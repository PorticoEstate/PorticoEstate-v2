<?php

namespace App\modules\booking\services;

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\Send;
use App\modules\phpgwapi\services\Cache;
use Exception;

/**
 * Modern email service for booking applications
 * Shared between booking frontend and backend systems
 * Ports functionality from legacy booking.boapplication->send_notification
 */
class EmailService
{
    private $settings;
    private $serverSettings;
    private $send;
    private $datetimeformat = 'Y-m-d H:i';

    public function __construct()
    {
        $this->settings = Settings::getInstance();
        $this->serverSettings = $this->settings->get('server');
        $this->send = new Send();
    }

    /**
     * Send application notification email for multiple applications grouped by parent_id
     * Modern replacement for booking.boapplication->send_notification
     *
     * @param array $applications Array of application data grouped by parent_id
     * @param bool $created Whether this is a new application (true) or status update (false)
     * @param bool $assocciated Whether this is an associated booking (unused in legacy code)
     */
    public function sendApplicationGroupNotification(array $applications, bool $created = false, bool $assocciated = false): bool
    {
        if (empty($applications)) {
            return false;
        }

        // Use the first application for common data (contact info, etc.)
        $primaryApplication = reset($applications);
        
        // Skip if SMTP is not configured
        if (!(isset($this->serverSettings['smtp_server']) && $this->serverSettings['smtp_server'])) {
            return false;
        }

        try {
            // Get booking module configuration
            $config = $this->getBookingConfig();
            if (!$config) {
                return false;
            }

            // Get email settings
            $from = isset($config['email_sender']) && $config['email_sender'] 
                ? $config['email_sender'] 
                : "noreply<noreply@{$this->serverSettings['hostname']}>";
            
            $reply_to = !empty($config['email_reply_to']) ? $config['email_reply_to'] : '';
            
            $external_site_address = !empty($config['external_site_address']) 
                ? $config['external_site_address'] 
                : $this->serverSettings['webserver_url'];

            // Collect all resources and e-lock instructions from all applications
            $allResources = [];
            $allELockInstructions = [];
            $allAttachments = [];
            
            foreach ($applications as $application) {
                // Collect resources
                if (!empty($application['resources'])) {
                    $allResources = array_merge($allResources, $application['resources']);
                }
                
                // Get resources data for e-lock instructions
                $resources = $this->getResourcesData($application['resources']);
                $e_lock_instructions = $this->getELockInstructions($resources);
                if (!empty($e_lock_instructions)) {
                    $allELockInstructions = array_merge($allELockInstructions, $e_lock_instructions);
                }
                
                // Get attachments for accepted applications
                if ($application['status'] == 'ACCEPTED') {
                    $attachments = $this->getRelatedFiles($application);
                    if (!empty($attachments)) {
                        $allAttachments = array_merge($allAttachments, $attachments);
                    }
                }
            }

            // Remove duplicate resources and get resource names
            $allResources = array_unique($allResources);
            $resourcename = $this->getResourceNames($allResources);
            
            // Remove duplicate e-lock instructions and attachments
            $allELockInstructions = array_unique($allELockInstructions);
            $allAttachments = array_unique($allAttachments, SORT_REGULAR);

            // Build subject and body based on application status
            $subject = $config['application_mail_subject'];
            $link = $external_site_address . '/bookingfrontend/?menuaction=bookingfrontend.uiapplication.show&id=' . $primaryApplication['id'] . '&secret=' . $primaryApplication['secret'];

            $body = $this->buildEmailBodyForGroup($applications, $config, $created, $resourcename, $link, $allELockInstructions);

            // Send the main email to the applicant
            $success = $this->send->msg(
                'email',
                $primaryApplication['contact_email'],
                $subject,
                $body,
                '',  // msgtype
                '',  // cc
                '',  // bcc
                $from,
                'AktivKommune',
                'html',
                '',  // boundary
                $allAttachments,
                false, // receive_notification
                $reply_to
            );

            // Send notification to building contacts for accepted applications
            if ($primaryApplication['status'] == 'ACCEPTED' && 
                isset($config['application_notify_on_accepted']) && 
                $config['application_notify_on_accepted'] == 1) {
                
                $this->sendBuildingNotificationForGroup($applications, $config, $resourcename, $from, $reply_to);
            }

            return $success;

        } catch (Exception $e) {
            Cache::message_set("Email failed for {$primaryApplication['contact_email']}", 'error');
            Cache::message_set($e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Send application notification email (single application - legacy method)
     * Modern replacement for booking.boapplication->send_notification
     *
     * @param array $application Application data
     * @param bool $created Whether this is a new application (true) or status update (false)
     * @param bool $assocciated Whether this is an associated booking (unused in legacy code)
     */
    public function sendApplicationNotification(array $application, bool $created = false, bool $assocciated = false): bool
    {
        // Skip if SMTP is not configured
        if (!(isset($this->serverSettings['smtp_server']) && $this->serverSettings['smtp_server'])) {
            // Could log this, but legacy code just returned silently
            return false;
        }

        try {
            // Get booking module configuration
            $config = $this->getBookingConfig();
            if (!$config) {
                return false;
            }

            // Get email settings
            $from = isset($config['email_sender']) && $config['email_sender'] 
                ? $config['email_sender'] 
                : "noreply<noreply@{$this->serverSettings['hostname']}>";
            
            $reply_to = !empty($config['email_reply_to']) ? $config['email_reply_to'] : '';
            
            $external_site_address = !empty($config['external_site_address']) 
                ? $config['external_site_address'] 
                : $this->serverSettings['webserver_url'];

            // Get resource names
            $resourcename = $this->getResourceNames($application['resources']);

            // Get resources data for e-lock instructions
            $resources = $this->getResourcesData($application['resources']);
            $e_lock_instructions = $this->getELockInstructions($resources);

            // Build subject and body based on application status
            $subject = $config['application_mail_subject'];
            $link = $external_site_address . '/bookingfrontend/?menuaction=bookingfrontend.uiapplication.show&id=' . $application['id'] . '&secret=' . $application['secret'];

            $body = $this->buildEmailBody($application, $config, $created, $resourcename, $link, $e_lock_instructions);
            
            // Get attachments for accepted applications
            $attachments = [];
            if ($application['status'] == 'ACCEPTED') {
                $attachments = $this->getRelatedFiles($application);
            }

            // Send the main email to the applicant
            $success = $this->send->msg(
                'email',
                $application['contact_email'],
                $subject,
                $body,
                '',  // msgtype
                '',  // cc
                '',  // bcc
                $from,
                'AktivKommune',
                'html',
                '',  // boundary
                $attachments,
                false, // receive_notification
                $reply_to
            );

            // Send notification to building contacts for accepted applications
            if ($application['status'] == 'ACCEPTED' && 
                isset($config['application_notify_on_accepted']) && 
                $config['application_notify_on_accepted'] == 1) {
                
                $this->sendBuildingNotification($application, $config, $resourcename, $from, $reply_to);
            }

            return $success;

        } catch (Exception $e) {
            Cache::message_set("Email failed for {$application['contact_email']}", 'error');
            Cache::message_set($e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Get booking module configuration
     */
    protected function getBookingConfig(): ?array
    {
        try {
            $config = CreateObject('phpgwapi.config', 'booking');
            $config->read();
            return $config->config_data ?? null;
        } catch (Exception $e) {
            error_log("Failed to get booking config: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get resource names from resource IDs
     */
    protected function getResourceNames(array $resource_ids): string
    {
        if (empty($resource_ids)) {
            return '';
        }

        try {
            $soresource = CreateObject('booking.soresource');
            $resources = $soresource->read([
                'filters' => ['id' => $resource_ids],
                'results' => 100
            ]);

            $names = [];
            if (isset($resources['results']) && is_array($resources['results'])) {
                foreach ($resources['results'] as $resource) {
                    $names[] = $resource['name'];
                }
            }

            return implode(", ", $names);
        } catch (Exception $e) {
            error_log("Failed to get resource names: " . $e->getMessage());
            return 'Unknown resource';
        }
    }

    /**
     * Get resources data for e-lock instructions
     */
    protected function getResourcesData(array $resource_ids): array
    {
        if (empty($resource_ids)) {
            return [];
        }

        try {
            $soresource = CreateObject('booking.soresource');
            $resources = $soresource->read([
                'filters' => ['id' => $resource_ids],
                'results' => 100
            ]);

            return $resources['results'] ?? [];
        } catch (Exception $e) {
            error_log("Failed to get resources data: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get e-lock instructions from resources
     */
    protected function getELockInstructions(array $resources): array
    {
        $instructions = [];
        
        try {
            $bogeneric = CreateObject('booking.bogeneric');
            
            foreach ($resources as $resource) {
                if (!isset($resource['e_locks']) || !$resource['e_locks']) {
                    continue;
                }

                foreach ($resource['e_locks'] as $e_lock) {
                    if (!$e_lock['e_lock_system_id'] || !$e_lock['e_lock_resource_id']) {
                        continue;
                    }

                    $lock_system = $bogeneric->read_single([
                        'id' => $e_lock['e_lock_system_id'],
                        'location_info' => [
                            'type' => 'e_lock_system'
                        ]
                    ]);

                    if (isset($lock_system['instruction'])) {
                        $instructions[] = $lock_system['instruction'];
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Failed to get e-lock instructions: " . $e->getMessage());
        }

        return $instructions;
    }

    /**
     * Build email body for multiple applications grouped by parent_id
     */
    protected function buildEmailBodyForGroup(array $applications, array $config, bool $created, string $resourcename, string $link, array $e_lock_instructions): string
    {
        $primaryApplication = reset($applications);
        $body = '';

        if ($created) {
            $body = "<p>" . $config['application_mail_created'] . "</p>";
            
            // Add application details
            $body .= "<h3>Søknadsdetaljer:</h3>";
            if (!empty($primaryApplication['name'])) {
                $body .= "<p><strong>Arrangement:</strong> " . $primaryApplication['name'] . "</p>";
            }
            if (!empty($primaryApplication['organizer'])) {
                $body .= "<p><strong>Arrangør:</strong> " . $primaryApplication['organizer'] . "</p>";
            }
            $body .= "<p><strong>Ressurs:</strong> " . $resourcename . "</p>";
            $body .= "<p><strong>Lokasjon:</strong> " . $primaryApplication['building_name'] . "</p>";
            
            // Add requested dates from all applications
            $allDates = [];
            foreach ($applications as $application) {
                if (!empty($application['dates'])) {
                    foreach ($application['dates'] as $date) {
                        $from = date($this->datetimeformat, strtotime($date['from_']));
                        $to = date($this->datetimeformat, strtotime($date['to_']));
                        $allDates[] = "\t{$from} - {$to}";
                    }
                }
            }
            
            if (!empty($allDates)) {
                $body .= "<p><strong>Ønskede tider:</strong></p>";
                $body .= "<pre>" . implode("\n", $allDates) . "</pre>";
            }
            
            $body .= '<p><a href="' . $link . '">Link til ' . $config['application_mail_systemname'] . ': søknad #' . $primaryApplication['id'] . '</a></p>';
        }
        elseif ($primaryApplication['status'] == 'PENDING') {
            $body = "<p>Din søknad i " . $config['application_mail_systemname'] . " om leie/lån av " . $resourcename . " på " . $primaryApplication['building_name'] . " er " . lang($primaryApplication['status']) . '</p>';
            
            // Add application details
            $body .= "<h3>Søknadsdetaljer:</h3>";
            if (!empty($primaryApplication['name'])) {
                $body .= "<p><strong>Arrangement:</strong> " . $primaryApplication['name'] . "</p>";
            }
            if (!empty($primaryApplication['organizer'])) {
                $body .= "<p><strong>Arrangør:</strong> " . $primaryApplication['organizer'] . "</p>";
            }
            
            // Add requested dates from all applications
            $allDates = [];
            foreach ($applications as $application) {
                if (!empty($application['dates'])) {
                    foreach ($application['dates'] as $date) {
                        $from = date($this->datetimeformat, strtotime($date['from_']));
                        $to = date($this->datetimeformat, strtotime($date['to_']));
                        $allDates[] = "\t{$from} - {$to}";
                    }
                }
            }
            
            if (!empty($allDates)) {
                $body .= "<p><strong>Ønskede tider:</strong></p>";
                $body .= "<pre>" . implode("\n", $allDates) . "</pre>";
            }
            
            if (!empty($primaryApplication['comment'])) {
                $body .= '<p><strong>Kommentar fra saksbehandler:</strong><br />' . $primaryApplication['comment'] . '</p>';
            }
            
            $body .= "<p>" . $config['application_mail_pending'] . "</p>";
            $body .= '<p><a href="' . $link . '">Link til ' . $config['application_mail_systemname'] . ': søknad #' . $primaryApplication['id'] . '</a></p>';
        }
        elseif ($primaryApplication['status'] == 'ACCEPTED') {
            $body = "<p>Din søknad i " . $config['application_mail_systemname'] . " om leie/lån av " . $resourcename . " på " . $primaryApplication['building_name'] . " er " . lang($primaryApplication['status']) . '</p>';

            // Add application details
            $body .= "<h3>Søknadsdetaljer:</h3>";
            if (!empty($primaryApplication['name'])) {
                $body .= "<p><strong>Arrangement:</strong> " . $primaryApplication['name'] . "</p>";
            }
            if (!empty($primaryApplication['organizer'])) {
                $body .= "<p><strong>Arrangør:</strong> " . $primaryApplication['organizer'] . "</p>";
            }

            // Get associated dates and costs from all applications
            $allAdates = [];
            $totalCost = 0;

            foreach ($applications as $application) {
                $associations = $this->getApplicationAssociations($application['id']);
                foreach ($associations as $assoc) {
                    if ($assoc['active']) {
                        $from = date($this->datetimeformat, strtotime($assoc['from_']));
                        $to = date($this->datetimeformat, strtotime($assoc['to_']));
                        $allAdates[] = "\t{$from} - {$to}";
                        $totalCost += (float)$assoc['cost'];
                    }
                }
            }

            if (!empty($allAdates)) {
                $body .= "<pre>Godkjent tid:\n" . implode("\n", $allAdates) . "</pre><br />";
            }

            if ($totalCost > 0) {
                $body .= "<pre>Totalkostnad: kr " . number_format($totalCost, 2, ",", '.') . "</pre><br />";
            }

            if (!empty($primaryApplication['agreement_requirements'])) {
                $lang_additional_requirements = lang('additional requirements');
                $body .= "{$lang_additional_requirements}:<br />" . $primaryApplication['agreement_requirements'] . "<br />";
            }

            if (!empty($primaryApplication['comment'])) {
                $body .= "<p>Kommentar fra saksbehandler:<br />" . $primaryApplication['comment'] . "</p>";
            }

            $body .= "<p>{$config['application_mail_accepted']}</p>";
            $body .= "<br /><a href=\"{$link}\">Link til {$config['application_mail_systemname']}: søknad #{$primaryApplication['id']}</a>";

            if (!empty($e_lock_instructions)) {
                $body .= "\n" . implode("\n", $e_lock_instructions);
            }
        }
        elseif ($primaryApplication['status'] == 'REJECTED') {
            $body = "<p>Din søknad i " . $config['application_mail_systemname'] . " om leie/lån av " . $resourcename . " på " . $primaryApplication['building_name'] . " er " . lang($primaryApplication['status']) . '</p>';
            
            // Add application details
            $body .= "<h3>Søknadsdetaljer:</h3>";
            if (!empty($primaryApplication['name'])) {
                $body .= "<p><strong>Arrangement:</strong> " . $primaryApplication['name'] . "</p>";
            }
            if (!empty($primaryApplication['organizer'])) {
                $body .= "<p><strong>Arrangør:</strong> " . $primaryApplication['organizer'] . "</p>";
            }
            
            // Add requested dates from all applications
            $allDates = [];
            foreach ($applications as $application) {
                if (!empty($application['dates'])) {
                    foreach ($application['dates'] as $date) {
                        $from = date($this->datetimeformat, strtotime($date['from_']));
                        $to = date($this->datetimeformat, strtotime($date['to_']));
                        $allDates[] = "\t{$from} - {$to}";
                    }
                }
            }
            
            if (!empty($allDates)) {
                $body .= "<p><strong>Forespurte tider:</strong></p>";
                $body .= "<pre>" . implode("\n", $allDates) . "</pre>";
            }
            
            if (!empty($primaryApplication['comment'])) {
                $body .= '<p><strong>Kommentar fra saksbehandler:</strong><br />' . $primaryApplication['comment'] . '</p>';
            }
            
            $body .= '<p>' . $config['application_mail_rejected'] . ' <a href="' . $link . '">Link til ' . $config['application_mail_systemname'] . ': søknad #' . $primaryApplication['id'] . '</a></p>';
        }
        else {
            // Comment added or other status update
            $subject = $config['application_comment_mail_subject'];
            $body = "<p>" . $config['application_comment_added_mail'] . "</p>";
            $body .= '<p>Kommentar fra saksbehandler:<br />' . $primaryApplication['comment'] . '</p>';
            $body .= '<p><a href="' . $link . '">Link til ' . $config['application_mail_systemname'] . ': søknad #' . $primaryApplication['id'] . '</a></p>';
        }

        $body .= "<p>" . $config['application_mail_signature'] . "</p>";

        return $body;
    }

    /**
     * Build email body based on application status (single application)
     */
    protected function buildEmailBody(array $application, array $config, bool $created, string $resourcename, string $link, array $e_lock_instructions): string
    {
        $body = '';

        if ($created) {
            $body = "<p>" . $config['application_mail_created'] . "</p>";
            
            // Add application details
            $body .= "<h3>Søknadsdetaljer:</h3>";
            if (!empty($application['name'])) {
                $body .= "<p><strong>Arrangement:</strong> " . $application['name'] . "</p>";
            }
            if (!empty($application['organizer'])) {
                $body .= "<p><strong>Arrangør:</strong> " . $application['organizer'] . "</p>";
            }
            $body .= "<p><strong>Ressurs:</strong> " . $resourcename . "</p>";
            $body .= "<p><strong>Lokasjon:</strong> " . $application['building_name'] . "</p>";
            
            // Add requested dates
            if (!empty($application['dates'])) {
                $dates = [];
                foreach ($application['dates'] as $date) {
                    $from = date($this->datetimeformat, strtotime($date['from_']));
                    $to = date($this->datetimeformat, strtotime($date['to_']));
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
            
            // Add application details
            $body .= "<h3>Søknadsdetaljer:</h3>";
            if (!empty($application['name'])) {
                $body .= "<p><strong>Arrangement:</strong> " . $application['name'] . "</p>";
            }
            if (!empty($application['organizer'])) {
                $body .= "<p><strong>Arrangør:</strong> " . $application['organizer'] . "</p>";
            }
            
            // Add requested dates
            if (!empty($application['dates'])) {
                $dates = [];
                foreach ($application['dates'] as $date) {
                    $from = date($this->datetimeformat, strtotime($date['from_']));
                    $to = date($this->datetimeformat, strtotime($date['to_']));
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

            // Add application details
            $body .= "<h3>Søknadsdetaljer:</h3>";
            if (!empty($application['name'])) {
                $body .= "<p><strong>Arrangement:</strong> " . $application['name'] . "</p>";
            }
            if (!empty($application['organizer'])) {
                $body .= "<p><strong>Arrangør:</strong> " . $application['organizer'] . "</p>";
            }

            // Get associated dates and costs
            $associations = $this->getApplicationAssociations($application['id']);
            $adates = [];
            $cost = 0;

            foreach ($associations as $assoc) {
                if ($assoc['active']) {
                    $from = date($this->datetimeformat, strtotime($assoc['from_']));
                    $to = date($this->datetimeformat, strtotime($assoc['to_']));
                    $adates[] = "\t{$from} - {$to}";
                    $cost += (float)$assoc['cost'];
                }
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
            
            // Add application details
            $body .= "<h3>Søknadsdetaljer:</h3>";
            if (!empty($application['name'])) {
                $body .= "<p><strong>Arrangement:</strong> " . $application['name'] . "</p>";
            }
            if (!empty($application['organizer'])) {
                $body .= "<p><strong>Arrangør:</strong> " . $application['organizer'] . "</p>";
            }
            
            // Add requested dates
            if (!empty($application['dates'])) {
                $dates = [];
                foreach ($application['dates'] as $date) {
                    $from = date($this->datetimeformat, strtotime($date['from_']));
                    $to = date($this->datetimeformat, strtotime($date['to_']));
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
            // Comment added or other status update
            $subject = $config['application_comment_mail_subject'];
            $body = "<p>" . $config['application_comment_added_mail'] . "</p>";
            $body .= '<p>Kommentar fra saksbehandler:<br />' . $application['comment'] . '</p>';
            $body .= '<p><a href="' . $link . '">Link til ' . $config['application_mail_systemname'] . ': søknad #' . $application['id'] . '</a></p>';
        }

        $body .= "<p>" . $config['application_mail_signature'] . "</p>";

        return $body;
    }

    /**
     * Get application associations (bookings/events)
     */
    private function getApplicationAssociations(int $application_id): array
    {
        try {
            $assoc_bo = CreateObject('booking.booking_boapplication_association');
            $associations = $assoc_bo->so->read([
                'filters' => ['application_id' => $application_id],
                'sort' => 'from_',
                'dir' => 'asc',
                'results' => 'all'
            ]);

            return $associations['results'] ?? [];
        } catch (Exception $e) {
            error_log("Failed to get application associations: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get related files for application
     */
    private function getRelatedFiles(array $application): array
    {
        try {
            $boapplication = CreateObject('booking.boapplication');
            return $boapplication->get_related_files($application);
        } catch (Exception $e) {
            error_log("Failed to get related files: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Send notification to building contacts for accepted applications (grouped)
     */
    private function sendBuildingNotificationForGroup(array $applications, array $config, string $resourcename, string $from, string $reply_to): void
    {
        if (empty($applications)) {
            return;
        }

        $primaryApplication = reset($applications);

        try {
            $soapplication = CreateObject('booking.soapplication');
            $buildingemail = $soapplication->get_tilsyn_email($primaryApplication['building_name']);

            if (empty($buildingemail['email1']) && empty($buildingemail['email2']) && empty($buildingemail['email3'])) {
                return;
            }

            $subject_notify = $config['application_mail_subject'] . ": En søknad om leie/lån av " . $resourcename . " på " . $primaryApplication['building_name'] . " er godkjent";
            $body_notify = "<p>" . $primaryApplication['contact_name'] . " sin søknad om leie/lån av " . $resourcename . " på " . $primaryApplication['building_name'] . "</p>";

            // Add approved dates from all applications
            $allAdates = [];
            foreach ($applications as $application) {
                $associations = $this->getApplicationAssociations($application['id']);
                foreach ($associations as $assoc) {
                    if ($assoc['active']) {
                        $from_time = date($this->datetimeformat, strtotime($assoc['from_']));
                        $to_time = date($this->datetimeformat, strtotime($assoc['to_']));
                        $allAdates[] = "\t{$from_time} - {$to_time}";
                    }
                }
            }

            if (!empty($allAdates)) {
                $body_notify .= "<pre>Godkjent:\n" . implode("\n", $allAdates) . "</pre>";
            }

            if (!empty($primaryApplication['equipment']) && $primaryApplication['equipment'] != 'dummy') {
                $body_notify .= "<p><b>{$config['application_equipment']}:</b><br />" . $primaryApplication['equipment'] . "</p>";
            }

            // Send to each building email
            $buildingemails = array_unique(array_filter([$buildingemail['email1'], $buildingemail['email2'], $buildingemail['email3']]));
            foreach ($buildingemails as $email) {
                if ($email) {
                    try {
                        $this->send->msg(
                            'email',
                            $email,
                            $subject_notify,
                            $body_notify,
                            '',
                            '',
                            '',
                            $from,
                            'AktivKommune',
                            'html',
                            '',
                            [],
                            false,
                            $reply_to
                        );
                    } catch (Exception $e) {
                        // Log but don't fail the main email
                        error_log("Failed to send building notification to {$email}: " . $e->getMessage());
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Failed to send building notification: " . $e->getMessage());
        }
    }

    /**
     * Send notification to building contacts for accepted applications (single)
     */
    private function sendBuildingNotification(array $application, array $config, string $resourcename, string $from, string $reply_to): void
    {
        try {
            $soapplication = CreateObject('booking.soapplication');
            $buildingemail = $soapplication->get_tilsyn_email($application['building_name']);

            if (empty($buildingemail['email1']) && empty($buildingemail['email2']) && empty($buildingemail['email3'])) {
                return;
            }

            $subject_notify = $config['application_mail_subject'] . ": En søknad om leie/lån av " . $resourcename . " på " . $application['building_name'] . " er godkjent";
            $body_notify = "<p>" . $application['contact_name'] . " sin søknad om leie/lån av " . $resourcename . " på " . $application['building_name'] . "</p>";

            // Add approved dates
            $associations = $this->getApplicationAssociations($application['id']);
            $adates = [];
            foreach ($associations as $assoc) {
                if ($assoc['active']) {
                    $from_time = date($this->datetimeformat, strtotime($assoc['from_']));
                    $to_time = date($this->datetimeformat, strtotime($assoc['to_']));
                    $adates[] = "\t{$from_time} - {$to_time}";
                }
            }

            if (!empty($adates)) {
                $body_notify .= "<pre>Godkjent:\n" . implode("\n", $adates) . "</pre>";
            }

            if (!empty($application['equipment']) && $application['equipment'] != 'dummy') {
                $body_notify .= "<p><b>{$config['application_equipment']}:</b><br />" . $application['equipment'] . "</p>";
            }

            // Send to each building email
            $buildingemails = array_unique(array_filter([$buildingemail['email1'], $buildingemail['email2'], $buildingemail['email3']]));
            foreach ($buildingemails as $email) {
                if ($email) {
                    try {
                        $this->send->msg(
                            'email',
                            $email,
                            $subject_notify,
                            $body_notify,
                            '',
                            '',
                            '',
                            $from,
                            'AktivKommune',
                            'html',
                            '',
                            [],
                            false,
                            $reply_to
                        );
                    } catch (Exception $e) {
                        // Log but don't fail the main email
                        error_log("Failed to send building notification to {$email}: " . $e->getMessage());
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Failed to send building notification: " . $e->getMessage());
        }
    }
}