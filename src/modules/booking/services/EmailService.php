<?php

namespace App\modules\booking\services;

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\Send;
use App\modules\phpgwapi\services\Cache;
use App\modules\phpgwapi\helpers\EmailTwigHelper;
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
    private ?EmailTwigHelper $emailTwigHelper = null;

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
     * Get or create the email Twig helper (lazy initialization)
     */
    private function getEmailTwigHelper(): EmailTwigHelper
    {
        if ($this->emailTwigHelper === null) {
            $this->emailTwigHelper = new EmailTwigHelper('booking');
        }
        return $this->emailTwigHelper;
    }

    /**
     * Format a datetime string with proper timezone handling
     *
     * @param string $datetimeString ISO 8601 datetime string (e.g., "2025-11-06T11:00:00+01:00")
     * @param string $format Output format (default: 'Y-m-d H:i')
     * @return string Formatted datetime in user's timezone
     */
    private function formatDateTime(string $datetimeString, ?string $format = null): string
    {
        if ($format === null) {
            $format = $this->datetimeformat;
        }

        try {
            if (strpos($datetimeString, 'T') !== false || strpos($datetimeString, '+') !== false || strpos($datetimeString, 'Z') !== false) {
                $datetime = new \DateTime($datetimeString);
            } else {
                $datetime = new \DateTime($datetimeString, new \DateTimeZone($this->userTimezone));
            }

            $userTz = new \DateTimeZone($this->userTimezone);
            $datetime->setTimezone($userTz);

            return $datetime->format($format);
        } catch (\Exception $e) {
            error_log("Failed to parse datetime '{$datetimeString}': " . $e->getMessage());
            return date($format, strtotime($datetimeString));
        }
    }

    /**
     * Format application dates into display strings
     */
    private function formatDates(array $dates): array
    {
        $formatted = [];
        foreach ($dates as $date) {
            $from = $this->formatDateTime($date['from_']);
            $to = $this->formatDateTime($date['to_']);
            $formatted[] = "\t{$from} - {$to}";
        }
        return $formatted;
    }

    /**
     * Compute not-approved dates by comparing requested/recurring dates against approved timestamps
     */
    private function computeNotApprovedDates(array $application, array $approvedTimestamps): array
    {
        $notApprovedDates = [];

        if (!empty($application['recurring_info']) && !empty($application['dates'])) {
            $recurringData = is_string($application['recurring_info'])
                ? json_decode($application['recurring_info'], true)
                : $application['recurring_info'];

            if ($recurringData) {
                $attemptedDates = $this->generateRecurringDates($application, $recurringData);

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
                    $requestedFrom = $this->formatDateTime($requestedDate['from_']);
                    $requestedTo = $this->formatDateTime($requestedDate['to_']);

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

        return $notApprovedDates;
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

			// Create backend link
			$enforce_ssl = $this->serverSettings['enforce_ssl'];
			$this->serverSettings['enforce_ssl'] = true;
			$link_backend = \phpgw::link('/index.php', array('menuaction' => 'booking.uiapplication.show', 'id' => $application['id']), false, true, true);
			$this->serverSettings['enforce_ssl'] = $enforce_ssl;

			$resourcename = $this->getResourceNames($application['resources']);

			$body = $this->getEmailTwigHelper()->render('@views/emails/case_officer_notification.twig', [
				'contact_email' => $application['contact_email'],
				'systemname' => $config['application_mail_systemname'],
				'resourcename' => $resourcename,
				'building_name' => $application['building_name'],
				'mail_created' => $config['application_mail_created'],
				'link_backend' => $link_backend,
				'signature' => $config['application_mail_signature'],
			]);

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
        // Try legacy CreateObject first (works in legacy context)
        if (function_exists('CreateObject')) {
            try {
                $config = CreateObject('phpgwapi.config', 'booking');
                $config->read();
                return $config->config_data ?? null;
            } catch (\Throwable $e) {
                // Fall through to direct DB query
            }
        }

        // Direct DB fallback for new Slim controller context
        try {
            $db = \App\Database\Db::getInstance();
            $stmt = $db->prepare(
                "SELECT config_name, config_value FROM phpgw_config WHERE config_app = 'booking'"
            );
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $config = [];
            foreach ($rows as $r) {
                $config[$r['config_name']] = $r['config_value'];
            }
            return $config ?: null;
        } catch (\Throwable $e) {
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
        $twig = $this->getEmailTwigHelper();
        $primaryApplication = reset($applications);

        // Common template data
        $baseData = [
            'systemname' => $config['application_mail_systemname'],
            'resourcename' => $resourcename,
            'link' => $link,
            'application_id' => $primaryApplication['id'],
            'organizer' => $primaryApplication['organizer'] ?? '',
            'comment' => $primaryApplication['comment'] ?? '',
            'signature' => $config['application_mail_signature'],
        ];

        if ($created) {
            $appDetails = $this->prepareApplicationDetails($applications);

            return $twig->render('@views/emails/application_group_created.twig', array_merge($baseData, [
                'mail_created' => $config['application_mail_created'],
                'applications' => $appDetails,
            ]));
        }

        // Status-based templates
        $status = $primaryApplication['status'];

        if ($status == 'ACCEPTED' || $status == 'REJECTED' || $status == 'PENDING') {
            // Count approved vs rejected
            $approvedCount = 0;
            $rejectedCount = 0;
            foreach ($applications as $app) {
                if ($app['status'] == 'ACCEPTED') {
                    $approvedCount++;
                } elseif ($app['status'] == 'REJECTED') {
                    $rejectedCount++;
                }
            }

            $hasMixedResults = ($approvedCount > 0 && $rejectedCount > 0);

            if ($status == 'PENDING') {
                $appDetails = $this->prepareApplicationDetails($applications);
            } else {
                $appDetails = $this->prepareAcceptedApplicationDetails($applications);
                $baseData['total_cost'] = $this->calculateTotalCost($applications);
            }

            return $twig->render('@views/emails/application_group_status.twig', array_merge($baseData, [
                'status' => $status,
                'status_text' => lang($status),
                'applications' => $appDetails,
                'approved_count' => $approvedCount,
                'rejected_count' => $rejectedCount,
                'has_mixed_results' => $hasMixedResults,
                'agreement_requirements' => $primaryApplication['agreement_requirements'] ?? '',
                'e_lock_instructions' => $e_lock_instructions,
                'mail_pending' => $config['application_mail_pending'] ?? '',
                'mail_accepted' => $config['application_mail_accepted'] ?? '',
                'mail_rejected' => $config['application_mail_rejected'] ?? '',
                'comment_added_mail' => $config['application_comment_added_mail'] ?? '',
            ]));
        }

        // Comment added or other status update
        return $twig->render('@views/emails/application_group_status.twig', array_merge($baseData, [
            'status' => 'COMMENT',
            'comment_added_mail' => $config['application_comment_added_mail'] ?? '',
        ]));
    }

    /**
     * Build email body based on application status (single application)
     */
    protected function buildEmailBody(array $application, array $config, bool $created, string $resourcename, string $link, array $e_lock_instructions): string
    {
        $twig = $this->getEmailTwigHelper();

        // Common template data
        $baseData = [
            'systemname' => $config['application_mail_systemname'],
            'resourcename' => $resourcename,
            'building_name' => $application['building_name'],
            'link' => $link,
            'application_id' => $application['id'],
            'application_name' => $application['name'] ?? '',
            'organizer' => $application['organizer'] ?? '',
            'comment' => $application['comment'] ?? '',
            'signature' => $config['application_mail_signature'],
        ];

        if ($created) {
            $dates = !empty($application['dates']) ? $this->formatDates($application['dates']) : [];

            return $twig->render('@views/emails/application_created.twig', array_merge($baseData, [
                'mail_created' => $config['application_mail_created'],
                'dates' => $dates,
            ]));
        }

        if ($application['status'] == 'PENDING') {
            $dates = !empty($application['dates']) ? $this->formatDates($application['dates']) : [];

            return $twig->render('@views/emails/application_pending.twig', array_merge($baseData, [
                'status_text' => lang($application['status']),
                'dates' => $dates,
                'mail_pending' => $config['application_mail_pending'],
            ]));
        }

        if ($application['status'] == 'ACCEPTED') {
            // Get associated dates and costs
            $associations = $this->getApplicationAssociations($application['id']);
            $approvedDates = [];
            $cost = 0;
            $approvedTimestamps = [];

            foreach ($associations as $assoc) {
                if ($assoc['active']) {
                    $from = $this->formatDateTime($assoc['from_']);
                    $to = $this->formatDateTime($assoc['to_']);
                    $approvedDates[] = "\t{$from} - {$to}";
                    $cost += (float)$assoc['cost'];

                    $approvedTimestamps[] = [
                        'from' => $from,
                        'to' => $to
                    ];
                }
            }

            $notApprovedDates = $this->computeNotApprovedDates($application, $approvedTimestamps);

            return $twig->render('@views/emails/application_accepted.twig', array_merge($baseData, [
                'status_text' => lang($application['status']),
                'approved_dates' => $approvedDates,
                'not_approved_dates' => $notApprovedDates,
                'cost' => $cost,
                'agreement_requirements' => $application['agreement_requirements'] ?? '',
                'mail_accepted' => $config['application_mail_accepted'],
                'e_lock_instructions' => $e_lock_instructions,
            ]));
        }

        if ($application['status'] == 'REJECTED') {
            $dates = !empty($application['dates']) ? $this->formatDates($application['dates']) : [];

            return $twig->render('@views/emails/application_rejected.twig', array_merge($baseData, [
                'status_text' => lang($application['status']),
                'dates' => $dates,
                'mail_rejected' => $config['application_mail_rejected'],
            ]));
        }

        // Comment added or other status update
        return $twig->render('@views/emails/application_comment.twig', array_merge($baseData, [
            'comment_added_mail' => $config['application_comment_added_mail'],
        ]));
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

            $equipment = (!empty($primaryApplication['equipment']) && $primaryApplication['equipment'] != 'dummy')
                ? $primaryApplication['equipment']
                : '';

            $body_notify = $this->getEmailTwigHelper()->render('@views/emails/building_notification_group.twig', [
                'contact_name' => $primaryApplication['contact_name'],
                'resourcename' => $resourcename,
                'building_name' => $primaryApplication['building_name'],
                'approved_dates' => $allAdates,
                'equipment' => $equipment,
                'equipment_label' => $config['application_equipment'] ?? '',
            ]);

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

            $equipment = (!empty($application['equipment']) && $application['equipment'] != 'dummy')
                ? $application['equipment']
                : '';

            $body_notify = $this->getEmailTwigHelper()->render('@views/emails/building_notification.twig', [
                'contact_name' => $application['contact_name'],
                'resourcename' => $resourcename,
                'building_name' => $application['building_name'],
                'approved_dates' => $adates,
                'equipment' => $equipment,
                'equipment_label' => $config['application_equipment'] ?? '',
            ]);

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
                        error_log("Failed to send building notification to {$email}: " . $e->getMessage());
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Failed to send building notification: " . $e->getMessage());
        }
    }

    /**
     * Prepare application details for group email templates (bordered sections)
     *
     * @return array Array of application data ready for template rendering
     */
    private function prepareApplicationDetails(array $applications): array
    {
        $result = [];

        foreach ($applications as $application) {
            $appData = [
                'name' => $application['name'] ?? '',
                'id' => $application['id'],
                'building_name' => $application['building_name'] ?? '',
                'resource_names' => '',
                'formatted_dates' => [],
            ];

            if (!empty($application['resources'])) {
                $appData['resource_names'] = $this->getResourceNames($application['resources']);
            }

            if (!empty($application['dates'])) {
                $appData['formatted_dates'] = $this->formatDates($application['dates']);
            }

            $result[] = $appData;
        }

        return $result;
    }

    /**
     * Prepare accepted application details with approved/rejected dates and costs
     *
     * @return array Array of application data ready for template rendering
     */
    private function prepareAcceptedApplicationDetails(array $applications): array
    {
        $result = [];

        foreach ($applications as $application) {
            $associations = $this->getApplicationAssociations($application['id']);
            $approvedDates = [];
            $applicationCost = 0;
            $approvedTimestamps = [];

            foreach ($associations as $assoc) {
                if ($assoc['active']) {
                    $from = $this->formatDateTime($assoc['from_']);
                    $to = $this->formatDateTime($assoc['to_']);
                    $cost = (float)$assoc['cost'];
                    $costText = $cost > 0 ? " (kr " . number_format($cost, 2, ",", '.') . ")" : "";
                    $approvedDates[] = "\t{$from} - {$to}{$costText}";
                    $applicationCost += $cost;

                    $approvedTimestamps[] = [
                        'from' => $from,
                        'to' => $to
                    ];
                }
            }

            $isApproved = !empty($approvedDates);
            $notApprovedDates = $this->computeNotApprovedDates($application, $approvedTimestamps);

            $appData = [
                'name' => $application['name'] ?? '',
                'id' => $application['id'],
                'building_name' => $application['building_name'] ?? '',
                'resource_names' => '',
                'is_approved' => $isApproved,
                'approved_dates' => $approvedDates,
                'not_approved_dates' => $notApprovedDates,
                'cost' => $applicationCost,
            ];

            if (!empty($application['resources'])) {
                $appData['resource_names'] = $this->getResourceNames($application['resources']);
            }

            $result[] = $appData;
        }

        return $result;
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
            $repeat_until->setTime(23, 59, 59);
        } else {
            $repeat_until = clone $from_time;
            $repeat_until->add(new \DateInterval('P3M'));
        }

        // Generate recurring dates by adding intervals (handles DST correctly)
        $current_from = clone $from_time;
        $current_to = clone $to_time;
        $interval_obj = new \DateInterval('P' . $interval . 'W');
        $i = 0;
        $max_iterations = 100;

        while ($current_to <= $repeat_until && $i < $max_iterations) {
            $dates[] = [
                'from' => $current_from->format($this->datetimeformat),
                'to' => $current_to->format($this->datetimeformat)
            ];

            $current_from->add($interval_obj);
            $current_to->add($interval_obj);
            $i++;
        }

        return $dates;
    }
}
