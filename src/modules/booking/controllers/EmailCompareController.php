<?php

namespace App\modules\booking\controllers;

use App\modules\booking\services\EmailService;
use App\modules\phpgwapi\services\Settings;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
require_once SRC_ROOT_PATH . '/helpers/LegacyObjectHandler.php';

/**
 * TEMPORARY controller for comparing old (hardcoded) vs new (Twig) email rendering.
 * Delete this file after verification is complete.
 *
 * Usage:
 *   GET /booking/email-compare/{id}                    — single app, uses real status
 *   GET /booking/email-compare/{id}?created=1          — simulate "created" email
 *   GET /booking/email-compare/{id}?status=ACCEPTED    — override status
 *   GET /booking/email-compare/{id}?group=1            — test group email (loads siblings via parent_id)
 */
class EmailCompareController
{
    public function __construct(ContainerInterface $container) {}

    public function compare(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $params = $request->getQueryParams();
        $created = !empty($params['created']);
        $overrideStatus = $params['status'] ?? null;
        $groupMode = !empty($params['group']);

        // Load real application data
        $so = \CreateObject('booking.soapplication');
        $application = $so->read_single($id);

        if (!$application) {
            $response->getBody()->write("Application #{$id} not found");
            return $response->withStatus(404);
        }

        if ($overrideStatus) {
            $application['status'] = $overrideStatus;
        }

        // Load config
        $config = \CreateObject('phpgwapi.config', 'booking');
        $config->read();
        $configData = $config->config_data;

        $serverSettings = Settings::getInstance()->get('server');
        $external_site_address = !empty($configData['external_site_address'])
            ? $configData['external_site_address']
            : $serverSettings['webserver_url'];

        // Build common parameters
        $link = $external_site_address . '/bookingfrontend/?menuaction=bookingfrontend.uiapplication.show&id=' . $application['id'] . '&secret=' . $application['secret'];

        // Get resource names + e-lock instructions
        $emailService = new EmailService();
        $resourcename = $this->callProtected($emailService, 'getResourceNames', [$application['resources']]);
        $resourcesData = $this->callProtected($emailService, 'getResourcesData', [$application['resources']]);
        $e_lock_instructions = $this->callProtected($emailService, 'getELockInstructions', [$resourcesData]);

        if ($groupMode) {
            // Load group siblings
            $applications = $this->loadGroupApplications($so, $application, $overrideStatus);
            $allResources = [];
            foreach ($applications as $app) {
                if (!empty($app['resources'])) {
                    $allResources = array_merge($allResources, $app['resources']);
                }
            }
            $allResources = array_unique($allResources);
            $resourcename = $this->callProtected($emailService, 'getResourceNames', [$allResources]);

            $oldBody = $this->oldBuildEmailBodyForGroup($emailService, $applications, $configData, $created, $resourcename, $link, $e_lock_instructions);
            $newBody = $this->callProtected($emailService, 'buildEmailBodyForGroup', [$applications, $configData, $created, $resourcename, $link, $e_lock_instructions]);
        } else {
            $oldBody = $this->oldBuildEmailBody($emailService, $application, $configData, $created, $resourcename, $link, $e_lock_instructions);
            $newBody = $this->callProtected($emailService, 'buildEmailBody', [$application, $configData, $created, $resourcename, $link, $e_lock_instructions]);
        }

        // Normalize whitespace for diff
        $oldNorm = $this->normalizeHtml($oldBody);
        $newNorm = $this->normalizeHtml($newBody);
        $identical = ($oldNorm === $newNorm);

        // Build comparison page
        $statusLabel = $created ? 'CREATED' : $application['status'];
        $modeLabel = $groupMode ? 'GROUP' : 'SINGLE';

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Email Compare — App #{$id} ({$statusLabel})</title>
<style>
* { box-sizing: border-box; }
body { font-family: system-ui, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
h1 { font-size: 1.2rem; }
.meta { background: #fff; padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; font-size: 0.85rem; }
.meta b { display: inline-block; min-width: 120px; }
.verdict { padding: 8px 16px; border-radius: 6px; font-weight: bold; margin-bottom: 16px; }
.verdict.pass { background: #d4edda; color: #155724; }
.verdict.fail { background: #f8d7da; color: #721c24; }
.columns { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.col { background: #fff; border: 1px solid #ddd; border-radius: 6px; overflow: hidden; }
.col-header { padding: 8px 16px; font-weight: bold; border-bottom: 1px solid #ddd; }
.col-header.old { background: #fff3cd; }
.col-header.new { background: #d1ecf1; }
.col-body { padding: 16px; overflow-x: auto; }
.source { background: #f8f9fa; padding: 16px; border-radius: 6px; margin-top: 16px; }
.source pre { white-space: pre-wrap; word-break: break-word; font-size: 0.8rem; max-height: 400px; overflow-y: auto; }
.links { margin-top: 16px; font-size: 0.85rem; }
.links a { margin-right: 12px; }
</style>
</head>
<body>
<h1>Email Template Comparison — Application #{$id}</h1>
<div class="meta">
<b>Mode:</b> {$modeLabel}<br>
<b>Status:</b> {$statusLabel}<br>
<b>Application:</b> {$application['name']}<br>
<b>Building:</b> {$application['building_name']}<br>
<b>Resources:</b> {$resourcename}
</div>
HTML;

        $verdictClass = $identical ? 'pass' : 'fail';
        $verdictText = $identical
            ? 'PASS — Normalized HTML is identical'
            : 'DIFF — Output differs (see rendered and source views below)';
        $html .= "<div class=\"verdict {$verdictClass}\">{$verdictText}</div>";

        $oldBodyEsc = htmlspecialchars($oldBody, ENT_QUOTES, 'UTF-8');
        $newBodyEsc = htmlspecialchars($newBody, ENT_QUOTES, 'UTF-8');

        $html .= <<<HTML
<h2>Rendered output</h2>
<div class="columns">
<div class="col"><div class="col-header old">OLD (hardcoded PHP)</div><div class="col-body">{$oldBody}</div></div>
<div class="col"><div class="col-header new">NEW (Twig templates)</div><div class="col-body">{$newBody}</div></div>
</div>
<h2>HTML source</h2>
<div class="columns">
<div class="col"><div class="col-header old">OLD source</div><div class="col-body"><pre>{$oldBodyEsc}</pre></div></div>
<div class="col"><div class="col-header new">NEW source</div><div class="col-body"><pre>{$newBodyEsc}</pre></div></div>
</div>
HTML;

        // Quick-nav links for testing other statuses
        $html .= '<div class="links"><b>Test other modes:</b> ';
        $html .= "<a href=\"/booking/email-compare/{$id}?created=1\">Created</a>";
        $html .= "<a href=\"/booking/email-compare/{$id}?status=PENDING\">Pending</a>";
        $html .= "<a href=\"/booking/email-compare/{$id}?status=ACCEPTED\">Accepted</a>";
        $html .= "<a href=\"/booking/email-compare/{$id}?status=REJECTED\">Rejected</a>";
        if ($application['parent_id']) {
            $html .= "<a href=\"/booking/email-compare/{$id}?group=1\">Group</a>";
            $html .= "<a href=\"/booking/email-compare/{$id}?group=1&created=1\">Group Created</a>";
            $html .= "<a href=\"/booking/email-compare/{$id}?group=1&status=ACCEPTED\">Group Accepted</a>";
        }
        $html .= '</div>';

        $html .= '</body></html>';

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }

    /**
     * Load all applications in the same group (by parent_id)
     */
    private function loadGroupApplications($so, array $primaryApp, ?string $overrideStatus): array
    {
        $parentId = $primaryApp['parent_id'] ?? $primaryApp['id'];

        $result = $so->read([
            'filters' => ['parent_id' => $parentId],
            'results' => 'all'
        ]);

        $applications = $result['results'] ?? [$primaryApp];

        if ($overrideStatus) {
            foreach ($applications as &$app) {
                $app['status'] = $overrideStatus;
            }
        }

        return $applications;
    }

    /**
     * Normalize HTML for comparison: collapse whitespace, trim, remove blank lines
     */
    private function normalizeHtml(string $html): string
    {
        // Normalize line endings
        $html = str_replace("\r\n", "\n", $html);
        // Collapse whitespace between tags
        $html = preg_replace('/>\s+</', '><', $html);
        // Collapse multiple whitespace inside text
        $html = preg_replace('/\s+/', ' ', $html);
        return trim($html);
    }

    /**
     * Call a protected/private method on EmailService via reflection
     */
    private function callProtected(EmailService $service, string $method, array $args)
    {
        $ref = new \ReflectionMethod($service, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($service, $args);
    }

    // =========================================================================
    // OLD buildEmailBody — copied verbatim from commit b8a836e1
    // =========================================================================

    private function oldBuildEmailBody(EmailService $service, array $application, array $config, bool $created, string $resourcename, string $link, array $e_lock_instructions): string
    {
        $body = '';

        if ($created) {
            $body = "<p>" . $config['application_mail_created'] . "</p>";
            $body .= "<h3>Søknadsdetaljer:</h3>";
            if (!empty($application['name'])) {
                $body .= "<p><strong>Arrangement:</strong> " . $application['name'] . "</p>";
            }
            if (!empty($application['organizer'])) {
                $body .= "<p><strong>Arrangør:</strong> " . $application['organizer'] . "</p>";
            }
            $body .= "<p><strong>Ressurs:</strong> " . $resourcename . "</p>";
            $body .= "<p><strong>Lokasjon:</strong> " . $application['building_name'] . "</p>";
            if (!empty($application['dates'])) {
                $dates = [];
                foreach ($application['dates'] as $date) {
                    $from = $this->oldFormatDateTime($service, $date['from_']);
                    $to = $this->oldFormatDateTime($service, $date['to_']);
                    $dates[] = "\t{$from} - {$to}";
                }
                if (!empty($dates)) {
                    $body .= "<p><strong>Ønskede tider:</strong></p>";
                    $body .= "<pre>" . implode("\n", $dates) . "</pre>";
                }
            }
            $body .= '<p><a href="' . $link . '">Link til ' . $config['application_mail_systemname'] . ': søknad #' . $application['id'] . '</a></p>';
        }
        elseif ($application['status'] == 'PENDING') {
            $body = "<p>Din søknad i " . $config['application_mail_systemname'] . " om leie/lån av " . $resourcename . " på " . $application['building_name'] . " er " . lang($application['status']) . '</p>';
            $body .= "<h3>Søknadsdetaljer:</h3>";
            if (!empty($application['name'])) {
                $body .= "<p><strong>Arrangement:</strong> " . $application['name'] . "</p>";
            }
            if (!empty($application['organizer'])) {
                $body .= "<p><strong>Arrangør:</strong> " . $application['organizer'] . "</p>";
            }
            if (!empty($application['dates'])) {
                $dates = [];
                foreach ($application['dates'] as $date) {
                    $from = $this->oldFormatDateTime($service, $date['from_']);
                    $to = $this->oldFormatDateTime($service, $date['to_']);
                    $dates[] = "\t{$from} - {$to}";
                }
                if (!empty($dates)) {
                    $body .= "<p><strong>Ønskede tider:</strong></p>";
                    $body .= "<pre>" . implode("\n", $dates) . "</pre>";
                }
            }
            if (!empty($application['comment'])) {
                $body .= '<p><strong>Kommentar fra saksbehandler:</strong><br />' . $application['comment'] . '</p>';
            }
            $body .= "<p>" . $config['application_mail_pending'] . "</p>";
            $body .= '<p><a href="' . $link . '">Link til ' . $config['application_mail_systemname'] . ': søknad #' . $application['id'] . '</a></p>';
        }
        elseif ($application['status'] == 'ACCEPTED') {
            $body = "<p>Din søknad i " . $config['application_mail_systemname'] . " om leie/lån av " . $resourcename . " på " . $application['building_name'] . " er " . lang($application['status']) . '</p>';
            $body .= "<h3>Søknadsdetaljer:</h3>";
            if (!empty($application['name'])) {
                $body .= "<p><strong>Arrangement:</strong> " . $application['name'] . "</p>";
            }
            if (!empty($application['organizer'])) {
                $body .= "<p><strong>Arrangør:</strong> " . $application['organizer'] . "</p>";
            }

            $associations = $this->callProtected($service, 'getApplicationAssociations', [$application['id']]);
            $adates = [];
            $cost = 0;
            $approvedTimestamps = [];
            foreach ($associations as $assoc) {
                if ($assoc['active']) {
                    $from = $this->oldFormatDateTime($service, $assoc['from_']);
                    $to = $this->oldFormatDateTime($service, $assoc['to_']);
                    $adates[] = "\t{$from} - {$to}";
                    $cost += (float)$assoc['cost'];
                    $approvedTimestamps[] = ['from' => $from, 'to' => $to];
                }
            }

            $notApprovedDates = [];
            if (!empty($application['recurring_info']) && !empty($application['dates'])) {
                $recurringData = is_string($application['recurring_info'])
                    ? json_decode($application['recurring_info'], true)
                    : $application['recurring_info'];
                if ($recurringData) {
                    $attemptedDates = $this->callProtected($service, 'generateRecurringDates', [$application, $recurringData]);
                    foreach ($attemptedDates as $attemptedDate) {
                        $isApproved = false;
                        foreach ($approvedTimestamps as $approved) {
                            if ($attemptedDate['from'] === $approved['from'] && $attemptedDate['to'] === $approved['to']) {
                                $isApproved = true;
                                break;
                            }
                        }
                        if (!$isApproved) {
                            $notApprovedDates[] = "\t{$attemptedDate['from']} - {$attemptedDate['to']}";
                        }
                    }
                }
            } else {
                if (!empty($application['dates'])) {
                    foreach ($application['dates'] as $requestedDate) {
                        $requestedFrom = $this->oldFormatDateTime($service, $requestedDate['from_']);
                        $requestedTo = $this->oldFormatDateTime($service, $requestedDate['to_']);
                        $isApproved = false;
                        foreach ($approvedTimestamps as $approved) {
                            if ($requestedFrom === $approved['from'] && $requestedTo === $approved['to']) {
                                $isApproved = true;
                                break;
                            }
                        }
                        if (!$isApproved) {
                            $notApprovedDates[] = "\t{$requestedFrom} - {$requestedTo}";
                        }
                    }
                }
            }

            if (!empty($notApprovedDates)) {
                $body .= "<pre style='color: #dc3545;'>Tider du ikke fikk:\n" . implode("\n", $notApprovedDates) . "</pre><br />";
            }
            if (!empty($adates)) {
                $body .= "<pre>Godkjent tid:\n" . implode("\n", $adates) . "</pre><br />";
            }
            if ($cost > 0) {
                $body .= "<pre>Totalkostnad: kr " . number_format($cost, 2, ",", '.') . "</pre><br />";
            }
            if (!empty($application['agreement_requirements'])) {
                $lang_additional_requirements = lang('additional requirements');
                $body .= "{$lang_additional_requirements}:<br />" . $application['agreement_requirements'] . "<br />";
            }
            if (!empty($application['comment'])) {
                $body .= "<p>Kommentar fra saksbehandler:<br />" . $application['comment'] . "</p>";
            }
            $body .= "<p>{$config['application_mail_accepted']}</p>";
            $body .= "<br /><a href=\"{$link}\">Link til {$config['application_mail_systemname']}: søknad #{$application['id']}</a>";
            if (!empty($e_lock_instructions)) {
                $body .= "\n" . implode("\n", $e_lock_instructions);
            }
        }
        elseif ($application['status'] == 'REJECTED') {
            $body = "<p>Din søknad i " . $config['application_mail_systemname'] . " om leie/lån av " . $resourcename . " på " . $application['building_name'] . " er " . lang($application['status']) . '</p>';
            $body .= "<h3>Søknadsdetaljer:</h3>";
            if (!empty($application['name'])) {
                $body .= "<p><strong>Arrangement:</strong> " . $application['name'] . "</p>";
            }
            if (!empty($application['organizer'])) {
                $body .= "<p><strong>Arrangør:</strong> " . $application['organizer'] . "</p>";
            }
            if (!empty($application['dates'])) {
                $dates = [];
                foreach ($application['dates'] as $date) {
                    $from = $this->oldFormatDateTime($service, $date['from_']);
                    $to = $this->oldFormatDateTime($service, $date['to_']);
                    $dates[] = "\t{$from} - {$to}";
                }
                if (!empty($dates)) {
                    $body .= "<p><strong>Forespurte tider:</strong></p>";
                    $body .= "<pre>" . implode("\n", $dates) . "</pre>";
                }
            }
            if (!empty($application['comment'])) {
                $body .= '<p><strong>Kommentar fra saksbehandler:</strong><br />' . $application['comment'] . '</p>';
            }
            $body .= '<p>' . $config['application_mail_rejected'] . ' <a href="' . $link . '">Link til ' . $config['application_mail_systemname'] . ': søknad #' . $application['id'] . '</a></p>';
        }
        else {
            $body = "<p>" . $config['application_comment_added_mail'] . "</p>";
            $body .= '<p>Kommentar fra saksbehandler:<br />' . $application['comment'] . '</p>';
            $body .= '<p><a href="' . $link . '">Link til ' . $config['application_mail_systemname'] . ': søknad #' . $application['id'] . '</a></p>';
        }

        $body .= "<p>" . $config['application_mail_signature'] . "</p>";
        return $body;
    }

    // =========================================================================
    // OLD buildEmailBodyForGroup — copied verbatim from commit b8a836e1
    // =========================================================================

    private function oldBuildEmailBodyForGroup(EmailService $service, array $applications, array $config, bool $created, string $resourcename, string $link, array $e_lock_instructions): string
    {
        $primaryApplication = reset($applications);
        $body = '';

        if ($created) {
            $body = "<p>" . $config['application_mail_created'] . "</p>";
            $body .= "<h3>Kombinert søknad - " . count($applications) . " delapplikasjoner:</h3>";
            if (!empty($primaryApplication['organizer'])) {
                $body .= "<p><strong>Arrangør:</strong> " . $primaryApplication['organizer'] . "</p>";
            }
            $body .= $this->oldBuildApplicationDetailsSection($service, $applications);
            $body .= '<p><a href="' . $link . '">Link til ' . $config['application_mail_systemname'] . ': søknad #' . $primaryApplication['id'] . '</a></p>';
        }
        elseif ($primaryApplication['status'] == 'PENDING') {
            $body = "<p>Din kombinerte søknad i " . $config['application_mail_systemname'] . " om leie/lån av " . $resourcename . " er " . lang($primaryApplication['status']) . '</p>';
            $body .= "<h3>Kombinert søknad - " . count($applications) . " deler:</h3>";
            if (!empty($primaryApplication['organizer'])) {
                $body .= "<p><strong>Arrangør:</strong> " . $primaryApplication['organizer'] . "</p>";
            }
            $body .= $this->oldBuildApplicationDetailsSection($service, $applications);
            if (!empty($primaryApplication['comment'])) {
                $body .= '<p><strong>Kommentar fra saksbehandler:</strong><br />' . $primaryApplication['comment'] . '</p>';
            }
            $body .= "<p>" . $config['application_mail_pending'] . "</p>";
            $body .= '<p><a href="' . $link . '">Link til ' . $config['application_mail_systemname'] . ': søknad #' . $primaryApplication['id'] . '</a></p>';
        }
        elseif ($primaryApplication['status'] == 'ACCEPTED' || $primaryApplication['status'] == 'REJECTED') {
            $approvedCount = 0;
            $rejectedCount = 0;
            foreach ($applications as $app) {
                if ($app['status'] == 'ACCEPTED') $approvedCount++;
                elseif ($app['status'] == 'REJECTED') $rejectedCount++;
            }
            $hasMixedResults = ($approvedCount > 0 && $rejectedCount > 0);

            if ($hasMixedResults) {
                $body = "<p>Din kombinerte søknad i " . $config['application_mail_systemname'] . " om leie/lån er behandlet</p>";
            } else {
                $body = "<p>Din kombinerte søknad i " . $config['application_mail_systemname'] . " om leie/lån er " . lang($primaryApplication['status']) . '</p>';
            }
            if ($rejectedCount > 0 && $approvedCount > 0) {
                $body .= "<h3>Kombinert søknad - " . $approvedCount . " deler godkjent, " . $rejectedCount . " avslått:</h3>";
            } elseif ($approvedCount > 0) {
                $body .= "<h3>Kombinert søknad - " . count($applications) . " deler godkjent:</h3>";
            } else {
                $body .= "<h3>Kombinert søknad - " . count($applications) . " deler avslått:</h3>";
            }
            if (!empty($primaryApplication['organizer'])) {
                $body .= "<p><strong>Arrangør:</strong> " . $primaryApplication['organizer'] . "</p>";
            }
            $body .= $this->oldBuildAcceptedApplicationDetailsSection($service, $applications);

            $totalCost = 0;
            foreach ($applications as $app) {
                $associations = $this->callProtected($service, 'getApplicationAssociations', [$app['id']]);
                foreach ($associations as $assoc) {
                    if ($assoc['active']) $totalCost += (float)$assoc['cost'];
                }
            }
            if ($totalCost > 0) {
                $body .= "<p><strong>Totalkostnad for alle bookinger: kr " . number_format($totalCost, 2, ",", '.') . "</strong></p>";
            }
            if (!empty($primaryApplication['agreement_requirements'])) {
                $lang_additional_requirements = lang('additional requirements');
                $body .= "{$lang_additional_requirements}:<br />" . $primaryApplication['agreement_requirements'] . "<br />";
            }
            if (!empty($primaryApplication['comment'])) {
                $body .= "<p>Kommentar fra saksbehandler:<br />" . $primaryApplication['comment'] . "</p>";
            }
            if ($hasMixedResults) {
                $body .= "<p>Se detaljer for hver del av søknaden ovenfor.</p>";
            } elseif ($approvedCount > 0) {
                $body .= "<p>{$config['application_mail_accepted']}</p>";
            } else {
                $body .= "<p>{$config['application_mail_rejected']}</p>";
            }
            $body .= "<br /><a href=\"{$link}\">Link til {$config['application_mail_systemname']}: søknad #{$primaryApplication['id']}</a>";
            if (!empty($e_lock_instructions)) {
                $body .= "\n" . implode("\n", $e_lock_instructions);
            }
        }
        else {
            $body = "<p>" . $config['application_comment_added_mail'] . "</p>";
            $body .= '<p>Kommentar fra saksbehandler:<br />' . $primaryApplication['comment'] . '</p>';
            $body .= '<p><a href="' . $link . '">Link til ' . $config['application_mail_systemname'] . ': søknad #' . $primaryApplication['id'] . '</a></p>';
        }

        $body .= "<p>" . $config['application_mail_signature'] . "</p>";
        return $body;
    }

    private function oldBuildApplicationDetailsSection(EmailService $service, array $applications): string
    {
        $section = "";
        foreach ($applications as $application) {
            $section .= "<div style='border-left: 3px solid #007cba; padding-left: 10px; margin: 10px 0;'>";
            $applicationName = !empty($application['name']) ? $application['name'] : "Søknadsdel";
            $section .= "<h4>{$applicationName} (ID: {$application['id']}):</h4>";
            if (!empty($application['resources'])) {
                $resourceNames = $this->callProtected($service, 'getResourceNames', [$application['resources']]);
                $section .= "<p><strong>Ressurs:</strong> {$resourceNames}</p>";
            }
            if (!empty($application['building_name'])) {
                $section .= "<p><strong>Lokasjon:</strong> {$application['building_name']}</p>";
            }
            if (!empty($application['dates'])) {
                $dates = [];
                foreach ($application['dates'] as $date) {
                    $from = $this->oldFormatDateTime($service, $date['from_']);
                    $to = $this->oldFormatDateTime($service, $date['to_']);
                    $dates[] = "\t{$from} - {$to}";
                }
                if (!empty($dates)) {
                    $section .= "<p><strong>Ønskede tider:</strong></p>";
                    $section .= "<pre>" . implode("\n", $dates) . "</pre>";
                }
            }
            $section .= "</div>";
        }
        return $section;
    }

    private function oldBuildAcceptedApplicationDetailsSection(EmailService $service, array $applications): string
    {
        $section = "";
        foreach ($applications as $application) {
            $associations = $this->callProtected($service, 'getApplicationAssociations', [$application['id']]);
            $adates = [];
            $applicationCost = 0;
            $approvedTimestamps = [];

            foreach ($associations as $assoc) {
                if ($assoc['active']) {
                    $from = $this->oldFormatDateTime($service, $assoc['from_']);
                    $to = $this->oldFormatDateTime($service, $assoc['to_']);
                    $cost = (float)$assoc['cost'];
                    $costText = $cost > 0 ? " (kr " . number_format($cost, 2, ",", '.') . ")" : "";
                    $adates[] = "\t{$from} - {$to}{$costText}";
                    $applicationCost += $cost;
                    $approvedTimestamps[] = ['from' => $from, 'to' => $to];
                }
            }

            $isApproved = !empty($adates);

            if ($isApproved) {
                $section .= "<div style='border-left: 3px solid #28a745; padding-left: 10px; margin: 10px 0;'>";
                $applicationName = !empty($application['name']) ? $application['name'] : "Søknadsdel";
                $section .= "<h4>✅ {$applicationName} - Godkjent (ID: {$application['id']}):</h4>";
            } else {
                $section .= "<div style='border-left: 3px solid #dc3545; padding-left: 10px; margin: 10px 0;'>";
                $applicationName = !empty($application['name']) ? $application['name'] : "Søknadsdel";
                $section .= "<h4>❌ {$applicationName} - Avslått (ID: {$application['id']}):</h4>";
            }

            if (!empty($application['resources'])) {
                $resourceNames = $this->callProtected($service, 'getResourceNames', [$application['resources']]);
                $section .= "<p><strong>Ressurs:</strong> {$resourceNames}</p>";
            }
            if (!empty($application['building_name'])) {
                $section .= "<p><strong>Lokasjon:</strong> {$application['building_name']}</p>";
            }

            $notApprovedDates = [];
            if (!empty($application['recurring_info']) && !empty($application['dates'])) {
                $recurringData = is_string($application['recurring_info'])
                    ? json_decode($application['recurring_info'], true)
                    : $application['recurring_info'];
                if ($recurringData) {
                    $attemptedDates = $this->callProtected($service, 'generateRecurringDates', [$application, $recurringData]);
                    foreach ($attemptedDates as $attemptedDate) {
                        $match = false;
                        foreach ($approvedTimestamps as $approved) {
                            if ($attemptedDate['from'] === $approved['from'] && $attemptedDate['to'] === $approved['to']) {
                                $match = true;
                                break;
                            }
                        }
                        if (!$match) {
                            $notApprovedDates[] = "\t{$attemptedDate['from']} - {$attemptedDate['to']}";
                        }
                    }
                }
            } else {
                if (!empty($application['dates'])) {
                    foreach ($application['dates'] as $requestedDate) {
                        $requestedFrom = $this->oldFormatDateTime($service, $requestedDate['from_']);
                        $requestedTo = $this->oldFormatDateTime($service, $requestedDate['to_']);
                        $match = false;
                        foreach ($approvedTimestamps as $approved) {
                            if ($requestedFrom === $approved['from'] && $requestedTo === $approved['to']) {
                                $match = true;
                                break;
                            }
                        }
                        if (!$match) {
                            $notApprovedDates[] = "\t{$requestedFrom} - {$requestedTo}";
                        }
                    }
                }
            }

            if (!empty($notApprovedDates)) {
                $section .= "<pre style='color: #dc3545;'><strong>Tider du ikke fikk:</strong>\n" . implode("\n", $notApprovedDates) . "</pre>";
            }
            if (!empty($adates)) {
                $section .= "<p><strong>Godkjente tider:</strong></p>";
                $section .= "<pre>" . implode("\n", $adates) . "</pre>";
                if ($applicationCost > 0) {
                    $section .= "<p><strong>Kostnad for {$applicationName}: kr " . number_format($applicationCost, 2, ",", '.') . "</strong></p>";
                }
            }
            $section .= "</div>";
        }
        return $section;
    }

    private function oldFormatDateTime(EmailService $service, string $datetimeString): string
    {
        return $this->callProtected($service, 'formatDateTime', [$datetimeString]);
    }
}
