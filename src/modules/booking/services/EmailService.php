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
    // Set to true to enable email content logging to /tmp for debugging
    private static $DEBUG_EMAIL_LOGGING = false;

    private $settings;
    private $serverSettings;
    private $userSettings;
    private $send;
    private $datetimeformat = 'Y-m-d H:i';
    private $userTimezone;

    public function __construct()
    {
        $this->settings = Settings::getInstance();
        $this->serverSettings = $this->settings->get('server');
        $this->userSettings = $this->settings->get('user');
        $this->send = new Send();

        // Get user's timezone from preferences, default to Europe/Oslo
        $this->userTimezone = !empty($this->userSettings['preferences']['common']['timezone'])
            ? $this->userSettings['preferences']['common']['timezone']
            : 'Europe/Oslo';
    }

    /**
     * Format a datetime string with proper timezone handling
     *
     * @param string $datetimeString ISO 8601 datetime string (e.g., "2025-11-06T11:00:00+01:00")
     * @param string $format Output format (default: 'Y-m-d H:i')
     * @return string Formatted datetime in user's timezone
     */
    private function formatDateTime(string $datetimeString, string $format = null): string
    {
        if ($format === null) {
            $format = $this->datetimeformat;
        }

        try {
            // Parse the datetime string
            // If the string has timezone info (e.g., "2025-11-07T11:00:00+01:00"), DateTime respects it
            // If the string has no timezone info (e.g., "2025-11-07 12:00:00" from database),
            // we must assume it's already in the user's timezone, not UTC
            if (strpos($datetimeString, 'T') !== false || strpos($datetimeString, '+') !== false || strpos($datetimeString, 'Z') !== false) {
                // ISO format with timezone - parse as-is
                $datetime = new \DateTime($datetimeString);
            } else {
                // Plain datetime from database - assume it's in user's timezone
                $datetime = new \DateTime($datetimeString, new \DateTimeZone($this->userTimezone));
            }

            // Ensure we're displaying in user's timezone
            $userTz = new \DateTimeZone($this->userTimezone);
            $datetime->setTimezone($userTz);

            // Format and return
            return $datetime->format($format);
        } catch (\Exception $e) {
            // Fallback to original behavior if parsing fails
            error_log("Failed to parse datetime '{$datetimeString}': " . $e->getMessage());
            return date($format, strtotime($datetimeString));
        }
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

        // Use an ACCEPTED application as primary for template selection (prefer true parent if accepted)
        $primaryApplication = null;
        $trueParent = null;

        // First, try to find the true parent (self-referencing parent_id)
        foreach ($applications as $app) {
            if (isset($app['parent_id']) && $app['parent_id'] == $app['id']) {
                $trueParent = $app;
                break;
            }
        }

        // If true parent is ACCEPTED, use it as primary
        if ($trueParent && $trueParent['status'] == 'ACCEPTED') {
            $primaryApplication = $trueParent;
        } else {
            // Otherwise, find first ACCEPTED application for template selection
            foreach ($applications as $app) {
                if ($app['status'] == 'ACCEPTED') {
                    $primaryApplication = $app;
                    break;
                }
            }
        }

        // Fallback to first application if none are accepted (e.g., all rejected)
        if (!$primaryApplication) {
            $primaryApplication = reset($applications);
        }

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

            // Debug logging if enabled
            if (self::$DEBUG_EMAIL_LOGGING) {
                $debug_file = sys_get_temp_dir() . '/email_debug_' . date('Y-m-d_His') . '_group_app_' . $primaryApplication['id'] . '.html';
                file_put_contents($debug_file, "Subject: {$subject}\n\nTo: {$primaryApplication['contact_email']}\n\nBody:\n{$body}");
                error_log("DEBUG: Group email content saved to {$debug_file}");
            }

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

            // Debug logging if enabled
            if (self::$DEBUG_EMAIL_LOGGING) {
                $debug_file = sys_get_temp_dir() . '/email_debug_' . date('Y-m-d_His') . '_app_' . $application['id'] . '.html';
                file_put_contents($debug_file, "Subject: {$subject}\n\nTo: {$application['contact_email']}\n\nBody:\n{$body}");
                error_log("DEBUG: Email content saved to {$debug_file}");
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
     * Send notifications to case officers (preserves legacy BCC functionality)
     * 
     * @param array $application Application data
     */
    public function sendCaseOfficerNotifications(array $application): void
    {
        if (!(isset($this->serverSettings['smtp_server']) && $this->serverSettings['smtp_server'])) {
            return;
        }

        try {
            $building_info = $this->getBuildingInfo($application['id']);
            if (!$building_info) {
                return;
            }
            
            $extra_mail_addresses = $this->getMailAddresses((int)$building_info['id'], (int)$application['case_officer_id']);

            $mail_addresses = array();
            foreach ($extra_mail_addresses as $user_id => $extra_mail_address) {
                $prefs = CreateObject('phpgwapi.preferences', $user_id)->read();
                if (isset($prefs['booking']['notify_on_new']) && ($prefs['booking']['notify_on_new'] & 1)) {
                    if ($extra_mail_address) {
                        $mail_addresses[] = $extra_mail_address;
                    }
                }
            }

            if (empty($mail_addresses)) {
                return;
            }

            $config = $this->getBookingConfig();
            if (!$config) {
                return;
            }

			$from = isset($config['email_sender']) && $config['email_sender']
				? $config['email_sender']
				: "noreply<noreply@{$this->serverSettings['hostname']}>";

			$subject = "KOPI::" . $config['application_mail_subject'];
			$external_site_address = !empty($config['external_site_address'])
				? $config['external_site_address']
				: $this->serverSettings['webserver_url'];

			// Create backend link
			$enforce_ssl = $this->serverSettings['enforce_ssl'];
			$this->serverSettings['enforce_ssl'] = true;
			$link_backend = \phpgw::link('/index.php', array('menuaction' => 'booking.uiapplication.show', 'id' => $application['id']), false, true, true);
			$this->serverSettings['enforce_ssl'] = $enforce_ssl;

			$resourcename = $this->getResourceNames($application['resources']);

			$body = "<h1>NB!! KOPI av epost til {$application['contact_email']}</h1>"
				. "<p>Ny søknad i " . $config['application_mail_systemname'] . " om leie/lån av " . $resourcename . " på " . $application['building_name'] . "</p>"
				. "<p>" . $config['application_mail_created'] . "</p>"
				. "<p>Forresten...:<br/>"
				. "<a href=\"{$link_backend}\">Link til søknad i backend</a></p>"
				. "<p>" . $config['application_mail_signature'] . "</p>";

			$send = CreateObject('phpgwapi.send');
			$bcc = implode(';', $mail_addresses);
			$send->msg('email', $bcc, $subject, $body, '', '', '', $from, 'AktivKommune', 'html', '', array(), false);
		} catch (Exception $e) {
            error_log("Failed to send case officer notifications: " . $e->getMessage());
        }
    }

    /**
     * Send SMS notifications (preserves legacy functionality)
     * 
     * @param array $application Application data
     */
    public function sendSmsNotifications(array $application): void
    {
        try {

			$config = $this->getBookingConfig();
			$sms_text = $config['application_sms_created'] ?? '';

			$building_info = $this->getBuildingInfo($application['id']);
            if (!$building_info) {
                return;
            }

            $extra_mail_addresses = $this->getMailAddresses((int)$building_info['id'], (int)$application['case_officer_id']);

            $cellphones = array();
			foreach ($extra_mail_addresses as $user_id => $extra_mail_address)
			{
				$prefs = CreateObject('phpgwapi.preferences', $user_id)->read();
				if (isset($prefs['booking']['notify_on_new']) && ($prefs['booking']['notify_on_new'] & 2))
				{
					$cellphones[] = $prefs['common']['cellphone'];
				}
			}


			if (empty($cellphones)) {
                return;
            }

 
            if ($sms_text) {
                $sms = CreateObject('phpgwapi.sms');
                foreach ($cellphones as $phone) {
                    $sms->send($phone, $sms_text);
                }
            }

        } catch (Exception $e) {
            error_log("Failed to send SMS notifications: " . $e->getMessage());
        }
    }

    /**
     * Get building info
     */
    protected function getBuildingInfo(int $application_id): ?array
    {
        try {
            $so = CreateObject('booking.soapplication');
            return $so->get_building_info($application_id);
        } catch (Exception $e) {
            error_log("Failed to get building info: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get mail addresses for building roles and case officer
     */
    protected function getMailAddresses(int $building_id, int $user_id = 0): array
    {
        try {
			$roles_at_building = CreateObject('booking.sopermission_building')->get_roles_at_building($building_id);

			$users = array();

			foreach ($roles_at_building as $role)
			{
				$users[] = $role['user_id'];
			}

			if ($user_id && !in_array($user_id, $users))
			{
				$users[] = $user_id;
			}

			$mail_addresses = array();
			foreach ($users as $user)
			{

				$prefs = CreateObject('phpgwapi.preferences', $user)->read();

				if (!empty($prefs['common']['email']))
				{
					$mail_addresses[$user] =  $prefs['common']['email'];
				}
			}

			return $mail_addresses;

		} catch (Exception $e) {
            error_log("Failed to get mail addresses: " . $e->getMessage());
            return [];
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

            // Add combined application header
            $body .= "<h3>Kombinert søknad - " . count($applications) . " delapplikasjoner:</h3>";
            if (!empty($primaryApplication['name'])) {
                $body .= "<p><strong>Arrangement:</strong> " . $primaryApplication['name'] . "</p>";
            }
            if (!empty($primaryApplication['organizer'])) {
                $body .= "<p><strong>Arrangør:</strong> " . $primaryApplication['organizer'] . "</p>";
            }

            // Show details for each application separately if they have different resources
            $body .= $this->buildApplicationDetailsSection($applications);

            $body .= '<p><a href="' . $link . '">Link til ' . $config['application_mail_systemname'] . ': søknad #' . $primaryApplication['id'] . '</a></p>';
        }
        elseif ($primaryApplication['status'] == 'PENDING') {
            $body = "<p>Din kombinerte søknad i " . $config['application_mail_systemname'] . " om leie/lån av " . $resourcename . " er " . lang($primaryApplication['status']) . '</p>';

            // Add combined application details
            $body .= "<h3>Kombinert søknad - " . count($applications) . " delapplikasjoner:</h3>";
            if (!empty($primaryApplication['name'])) {
                $body .= "<p><strong>Arrangement:</strong> " . $primaryApplication['name'] . "</p>";
            }
            if (!empty($primaryApplication['organizer'])) {
                $body .= "<p><strong>Arrangør:</strong> " . $primaryApplication['organizer'] . "</p>";
            }

            // Show details for each application separately
            $body .= $this->buildApplicationDetailsSection($applications);

            if (!empty($primaryApplication['comment'])) {
                $body .= '<p><strong>Kommentar fra saksbehandler:</strong><br />' . $primaryApplication['comment'] . '</p>';
            }

            $body .= "<p>" . $config['application_mail_pending'] . "</p>";
            $body .= '<p><a href="' . $link . '">Link til ' . $config['application_mail_systemname'] . ': søknad #' . $primaryApplication['id'] . '</a></p>';
        }
        elseif ($primaryApplication['status'] == 'ACCEPTED') {
            $body = "<p>Din kombinerte søknad i " . $config['application_mail_systemname'] . " om leie/lån av " . $resourcename . " er " . lang($primaryApplication['status']) . '</p>';

            // Count approved vs rejected applications
            $approvedCount = 0;
            $rejectedCount = 0;
            foreach ($applications as $app) {
                if ($app['status'] == 'ACCEPTED') {
                    $approvedCount++;
                } elseif ($app['status'] == 'REJECTED') {
                    $rejectedCount++;
                }
            }

            // Add combined application details with accurate count
            if ($rejectedCount > 0) {
                $body .= "<h3>Kombinert søknad - " . $approvedCount . " delapplikasjoner godkjent, " . $rejectedCount . " avslått:</h3>";
            } else {
                $body .= "<h3>Kombinert søknad - " . count($applications) . " delapplikasjoner godkjent:</h3>";
            }

            if (!empty($primaryApplication['name'])) {
                $body .= "<p><strong>Arrangement:</strong> " . $primaryApplication['name'] . "</p>";
            }
            if (!empty($primaryApplication['organizer'])) {
                $body .= "<p><strong>Arrangør:</strong> " . $primaryApplication['organizer'] . "</p>";
            }

            // Show detailed breakdown per application with approved times and costs
            $body .= $this->buildAcceptedApplicationDetailsSection($applications);

            // Calculate and show total cost
            $totalCost = $this->calculateTotalCost($applications);
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

            $body .= "<p>{$config['application_mail_accepted']}</p>";
            $body .= "<br /><a href=\"{$link}\">Link til {$config['application_mail_systemname']}: søknad #{$primaryApplication['id']}</a>";

            if (!empty($e_lock_instructions)) {
                $body .= "\n" . implode("\n", $e_lock_instructions);
            }
        }
        elseif ($primaryApplication['status'] == 'REJECTED') {
            $body = "<p>Din kombinerte søknad i " . $config['application_mail_systemname'] . " om leie/lån av " . $resourcename . " er " . lang($primaryApplication['status']) . '</p>';

            // Add combined application details
            $body .= "<h3>Kombinert søknad - " . count($applications) . " delapplikasjoner avslått:</h3>";
            if (!empty($primaryApplication['name'])) {
                $body .= "<p><strong>Arrangement:</strong> " . $primaryApplication['name'] . "</p>";
            }
            if (!empty($primaryApplication['organizer'])) {
                $body .= "<p><strong>Arrangør:</strong> " . $primaryApplication['organizer'] . "</p>";
            }

            // Show details for each application separately
            $body .= $this->buildApplicationDetailsSection($applications);

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
                    $from = $this->formatDateTime($date['from_']);
                    $to = $this->formatDateTime($date['to_']);
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
                    $from = $this->formatDateTime($date['from_']);
                    $to = $this->formatDateTime($date['to_']);
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
            $approvedTimestamps = [];

            foreach ($associations as $assoc) {
                if ($assoc['active']) {
                    $from = $this->formatDateTime($assoc['from_']);
                    $to = $this->formatDateTime($assoc['to_']);
                    $adates[] = "\t{$from} - {$to}";
                    $cost += (float)$assoc['cost'];

                    // Store approved datetime strings (normalized to Oslo time) for comparison
                    // Using formatted strings avoids timezone conversion issues
                    $approvedTimestamps[] = [
                        'from' => $from,
                        'to' => $to
                    ];
                }
            }

            // Calculate rejected/not approved dates for recurring applications
            $notApprovedDates = [];

            // For recurring applications, we need to generate all attempted dates
            if (!empty($application['recurring_info']) && !empty($application['dates'])) {
                // Parse recurring info
                $recurringData = is_string($application['recurring_info'])
                    ? json_decode($application['recurring_info'], true)
                    : $application['recurring_info'];

                if ($recurringData) {
                    // Generate all recurring dates that were attempted
                    $attemptedDates = $this->generateRecurringDates($application, $recurringData);

                    // Compare attempted dates with approved dates
                    foreach ($attemptedDates as $attemptedDate) {
                        // attemptedDate now contains formatted strings directly
                        $attemptedFrom = $attemptedDate['from'];
                        $attemptedTo = $attemptedDate['to'];

                        // Check if this attempted time matches any approved time
                        $isApproved = false;
                        foreach ($approvedTimestamps as $approved) {
                            if ($attemptedFrom === $approved['from'] && $attemptedTo === $approved['to']) {
                                $isApproved = true;
                                break;
                            }
                        }

                        if (!$isApproved) {
                            $notApprovedDates[] = "\t{$attemptedFrom} - {$attemptedTo}";
                        }
                    }
                }
            } else {
                // For non-recurring applications, compare requested dates with approved dates
                if (!empty($application['dates'])) {
                    foreach ($application['dates'] as $requestedDate) {
                        // Format requested dates to Oslo time strings (same format as approved dates)
                        $requestedFrom = $this->formatDateTime($requestedDate['from_']);
                        $requestedTo = $this->formatDateTime($requestedDate['to_']);

                        // Check if this requested time matches any approved time
                        // Compare normalized Oslo time strings directly
                        $isApproved = false;
                        foreach ($approvedTimestamps as $approved) {
                            if ($requestedFrom === $approved['from'] && $requestedTo === $approved['to']) {
                                $isApproved = true;
                                break;
                            }
                        }

                        // If not approved, add to the not approved list
                        if (!$isApproved) {
                            $notApprovedDates[] = "\t{$requestedFrom} - {$requestedTo}";
                        }
                    }
                }
            }

            // Display not approved times first (if any)
            if (!empty($notApprovedDates)) {
                $body .= "<pre style='color: #dc3545;'>Tider du ikke fikk:\n" . implode("\n", $notApprovedDates) . "</pre><br />";
            }

            // Display approved times
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
                    $from = $this->formatDateTime($date['from_']);
                    $to = $this->formatDateTime($date['to_']);
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
            // Load the boapplication file which contains the association class
            \CreateObject('booking.boapplication');
            // Now we can instantiate the association class
            $assoc_bo = new \booking_boapplication_association();
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
                        $from_time = $this->formatDateTime($assoc['from_']);
                        $to_time = $this->formatDateTime($assoc['to_']);
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
                    $from_time = $this->formatDateTime($assoc['from_']);
                    $to_time = $this->formatDateTime($assoc['to_']);
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

    /**
     * Build application details section showing individual applications with their resources and dates
     */
    private function buildApplicationDetailsSection(array $applications): string
    {
        $section = "";

        foreach ($applications as $application) {
            $section .= "<div style='border-left: 3px solid #007cba; padding-left: 10px; margin: 10px 0;'>";

            // Use the application name (part name) as the header
            $applicationName = !empty($application['name']) ? $application['name'] : "Søknadsdel";
            $section .= "<h4>{$applicationName} (ID: {$application['id']}):</h4>";

            // Resource information for this application
            if (!empty($application['resources'])) {
                $resourceNames = $this->getResourceNames($application['resources']);
                $section .= "<p><strong>Ressurs:</strong> {$resourceNames}</p>";
            }

            // Building information
            if (!empty($application['building_name'])) {
                $section .= "<p><strong>Lokasjon:</strong> {$application['building_name']}</p>";
            }

            // Dates for this application
            if (!empty($application['dates'])) {
                $dates = [];
                foreach ($application['dates'] as $date) {
                    $from = $this->formatDateTime($date['from_']);
                    $to = $this->formatDateTime($date['to_']);
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

    /**
     * Build accepted application details section with approved times and costs per application
     */
    private function buildAcceptedApplicationDetailsSection(array $applications): string
    {
        $section = "";

        foreach ($applications as $application) {
            // First, get associations to check if there are any approved times
            $associations = $this->getApplicationAssociations($application['id']);
            $adates = [];
            $applicationCost = 0;
            $approvedTimestamps = [];

            foreach ($associations as $assoc) {
                if ($assoc['active']) {
                    $from = $this->formatDateTime($assoc['from_']);
                    $to = $this->formatDateTime($assoc['to_']);
                    $cost = (float)$assoc['cost'];
                    $costText = $cost > 0 ? " (kr " . number_format($cost, 2, ",", '.') . ")" : "";
                    $adates[] = "\t{$from} - {$to}{$costText}";
                    $applicationCost += $cost;

                    // Store approved datetime strings for comparison
                    $approvedTimestamps[] = [
                        'from' => $from,
                        'to' => $to
                    ];
                }
            }

            // Determine if this application was approved or rejected based on approved times
            $isApproved = !empty($adates);

            // Use different styling and labels for approved vs rejected applications
            if ($isApproved) {
                $section .= "<div style='border-left: 3px solid #28a745; padding-left: 10px; margin: 10px 0;'>";
                $applicationName = !empty($application['name']) ? $application['name'] : "Søknadsdel";
                $section .= "<h4>✅ {$applicationName} - Godkjent (ID: {$application['id']}):</h4>";
            } else {
                $section .= "<div style='border-left: 3px solid #dc3545; padding-left: 10px; margin: 10px 0;'>";
                $applicationName = !empty($application['name']) ? $application['name'] : "Søknadsdel";
                $section .= "<h4>❌ {$applicationName} - Avslått (ID: {$application['id']}):</h4>";
            }

            // Resource information
            if (!empty($application['resources'])) {
                $resourceNames = $this->getResourceNames($application['resources']);
                $section .= "<p><strong>Ressurs:</strong> {$resourceNames}</p>";
            }

            // Building information
            if (!empty($application['building_name'])) {
                $section .= "<p><strong>Lokasjon:</strong> {$application['building_name']}</p>";
            }

            // Calculate rejected/not approved dates
            $notApprovedDates = [];

            // For recurring applications, we need to generate all attempted dates
            if (!empty($application['recurring_info']) && !empty($application['dates'])) {
                // Parse recurring info
                $recurringData = is_string($application['recurring_info'])
                    ? json_decode($application['recurring_info'], true)
                    : $application['recurring_info'];

                if ($recurringData) {
                    // Generate all recurring dates that were attempted
                    $attemptedDates = $this->generateRecurringDates($application, $recurringData);

                    // Compare attempted dates with approved dates
                    foreach ($attemptedDates as $attemptedDate) {
                        // attemptedDate now contains formatted strings directly
                        $attemptedFrom = $attemptedDate['from'];
                        $attemptedTo = $attemptedDate['to'];

                        // Check if this attempted time matches any approved time
                        $isApproved = false;
                        foreach ($approvedTimestamps as $approved) {
                            if ($attemptedFrom === $approved['from'] && $attemptedTo === $approved['to']) {
                                $isApproved = true;
                                break;
                            }
                        }

                        if (!$isApproved) {
                            $notApprovedDates[] = "\t{$attemptedFrom} - {$attemptedTo}";
                        }
                    }
                }
            } else {
                // For non-recurring applications, compare requested dates with approved dates
                if (!empty($application['dates'])) {
                    foreach ($application['dates'] as $requestedDate) {
                        // Format requested dates to Oslo time strings (same format as approved dates)
                        $requestedFrom = $this->formatDateTime($requestedDate['from_']);
                        $requestedTo = $this->formatDateTime($requestedDate['to_']);

                        // Check if this requested time matches any approved time
                        $isApproved = false;
                        foreach ($approvedTimestamps as $approved) {
                            if ($requestedFrom === $approved['from'] && $requestedTo === $approved['to']) {
                                $isApproved = true;
                                break;
                            }
                        }

                        // If not approved, add to the not approved list
                        if (!$isApproved) {
                            $notApprovedDates[] = "\t{$requestedFrom} - {$requestedTo}";
                        }
                    }
                }
            }

            // Display not approved times first (if any)
            if (!empty($notApprovedDates)) {
                $section .= "<pre style='color: #dc3545;'><strong>Tider du ikke fikk:</strong>\n" . implode("\n", $notApprovedDates) . "</pre>";
            }

            // Display approved times
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

    /**
     * Calculate total cost from all applications
     */
    private function calculateTotalCost(array $applications): float
    {
        $totalCost = 0;

        foreach ($applications as $application) {
            $associations = $this->getApplicationAssociations($application['id']);
            foreach ($associations as $assoc) {
                if ($assoc['active']) {
                    $totalCost += (float)$assoc['cost'];
                }
            }
        }

        return $totalCost;
    }

    /**
     * Generate all recurring dates that were attempted for an application
     * Uses the exact same logic as generate_recurring_preview in uiapplication
     */
    private function generateRecurringDates(array $application, array $recurringData): array
    {
        $dates = [];

        if (empty($application['dates']) || empty($recurringData)) {
            return $dates;
        }

        // Get first date from application
        $first_date = $application['dates'][0];

        // Use formatDateTime to parse the date correctly (handles timezone properly)
        $from_str = $first_date['from_'];
        $to_str = $first_date['to_'];

        // Create DateTime objects in user timezone
        if (strpos($from_str, 'T') !== false || strpos($from_str, '+') !== false || strpos($from_str, 'Z') !== false) {
            $from_time = new \DateTime($from_str);
            $from_time->setTimezone(new \DateTimeZone($this->userTimezone));
            $to_time = new \DateTime($to_str);
            $to_time->setTimezone(new \DateTimeZone($this->userTimezone));
        } else {
            $from_time = new \DateTime($from_str, new \DateTimeZone($this->userTimezone));
            $to_time = new \DateTime($to_str, new \DateTimeZone($this->userTimezone));
        }

        // Parse recurring settings
        $interval = isset($recurringData['field_interval']) ? (int)$recurringData['field_interval'] : 1;
        $repeat_until = null;

        if (!empty($recurringData['repeat_until'])) {
            $repeat_until = new \DateTime($recurringData['repeat_until'], new \DateTimeZone($this->userTimezone));
            $repeat_until->setTime(23, 59, 59); // End of day
        } else {
            // Fallback to 3 months from first date
            $repeat_until = clone $from_time;
            $repeat_until->add(new \DateInterval('P3M'));
        }

        // Generate recurring dates by adding intervals (handles DST correctly)
        $current_from = clone $from_time;
        $current_to = clone $to_time;
        $interval_obj = new \DateInterval('P' . $interval . 'W'); // interval in weeks
        $i = 0;
        $max_iterations = 100; // Safety limit

        while ($current_to <= $repeat_until && $i < $max_iterations) {
            // Store formatted strings directly for easier comparison
            $dates[] = [
                'from' => $current_from->format($this->datetimeformat),
                'to' => $current_to->format($this->datetimeformat)
            ];

            // Add interval for next occurrence
            $current_from->add($interval_obj);
            $current_to->add($interval_obj);
            $i++;
        }

        return $dates;
    }
}