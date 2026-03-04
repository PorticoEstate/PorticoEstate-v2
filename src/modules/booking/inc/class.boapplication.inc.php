<?php

use App\modules\phpgwapi\services\Cache;
use App\modules\phpgwapi\services\Log;
use App\modules\booking\services\EmailService;
use App\modules\phpgwapi\helpers\EmailTwigHelper;

phpgw::import_class('booking.bocommon');

class booking_boapplication extends booking_bocommon
{
	var $activity_bo,
		$organization_bo;

	function __construct()
	{
		parent::__construct();
		$this->activity_bo = CreateObject('booking.boactivity');
		$this->organization_bo = CreateObject('booking.boorganization');
		$this->so = CreateObject('booking.soapplication');
	}

	/*
		 * Used for external archive
		 */
	function get_export_text1($application, $config)
	{
		$dateformat = $this->userSettings['preferences']['common']['dateformat'];
		$resourcename = implode(", ", $this->get_resource_name($application['resources']));

		$_adates = array();
		foreach ($application['dates'] as $date)
		{
			$_adates[] = "\t" . date("$dateformat H:i:s", strtotime($date['from_'])) . " - " . date("$dateformat H:i:s", strtotime($date['to_']));
		}

		$customer_name = !empty($application['customer_organization_name']) ? $application['customer_organization_name'] : $application['contact_name'];

		$start_date = reset($application['dates']);
		$start_date_formatted = date($dateformat, strtotime($start_date['from_']));
		$end_date = end($application['dates']);
		$end_date_formatted = date($dateformat, strtotime($end_date['to_']));

		$timespan = ($start_date_formatted == $end_date_formatted)
			? $start_date_formatted
			: "{$start_date_formatted} - {$end_date_formatted}";

		$title = "Forespørsel om leie av {$application['building_name']}/{$resourcename} - {$timespan} - $customer_name";

		$attachments = $this->get_related_files($application);
		$file_names = array();
		foreach ($attachments as $attachment)
		{
			$file_names[] = $attachment['name'];
		}

		$twig = new EmailTwigHelper('booking');
		$body = $twig->render('@views/emails/export_text_request.twig', [
			'contact_name' => $application['contact_name'],
			'systemname' => $config['application_mail_systemname'],
			'resourcename' => $resourcename,
			'building_name' => $application['building_name'],
			'agreement_requirements' => $application['agreement_requirements'] ?? '',
			'dates' => $_adates,
			'comment' => $application['comment'] ?? '',
			'file_names' => $file_names,
		]);

		return array(
			'title' => $title,
			'body' => $body
		);
	}
	/*
		 * Used for external archive
		 */
	function get_export_text2($application, $config)
	{
		$resourcename = implode(", ", $this->get_resource_name($application['resources']));

		$customer_name = !empty($application['customer_organization_name']) ? "{$application['customer_organization_name']}/{$application['contact_name']}" : $application['contact_name'];

		$dateformat = $this->userSettings['preferences']['common']['dateformat'];
		$start_date = reset($application['dates']);
		$start_date_formatted = date($dateformat, strtotime($start_date['from_']));
		$end_date = end($application['dates']);
		$end_date_formatted = date($dateformat, strtotime($end_date['to_']));

		$timespan = ($start_date_formatted == $end_date_formatted)
			? $start_date_formatted
			: "{$start_date_formatted} - {$end_date_formatted}";

		$title = "Svar på forespørsel om leie av {$application['building_name']}/{$resourcename} - {$timespan}  - $customer_name";

		// Prepare approved dates for ACCEPTED status
		$approved_dates = [];
		$rejected_dates = [];
		if ($application['status'] == 'ACCEPTED')
		{
			$assoc_bo = new booking_boapplication_association();
			$associations = $assoc_bo->so->read(array(
				'filters' => array('application_id' => $application['id']),
				'sort' => 'from_', 'dir' => 'asc', 'results' => 'all'
			));

			foreach ($associations['results'] as $assoc)
			{
				if ($assoc['active'])
				{
					$approved_dates[] = "\t" . date("$dateformat H:i:s", strtotime($assoc['from_'])) . " - " . date("$dateformat H:i:s", strtotime($assoc['to_']));
				}
			}

			// FIXME Sigurd 2. sept 2015: Something wrong with get_rejected
			$rejected = array();
			foreach ($rejected as $key => $date)
			{
				$prefix = ($key === 0) ? '' : "\t";
				$rejected_dates[] = $prefix . implode(" - ", $date);
			}
		}

		$twig = new EmailTwigHelper('booking');
		$body = $twig->render('@views/emails/export_text_response.twig', [
			'status' => $application['status'],
			'status_text' => strtolower(lang($application['status'])),
			'contact_name' => $application['contact_name'],
			'systemname' => $config['application_mail_systemname'],
			'resourcename' => $resourcename,
			'building_name' => $application['building_name'],
			'comment' => $application['comment'] ?? '',
			'agreement_requirements' => $application['agreement_requirements'] ?? '',
			'approved_dates' => $approved_dates,
			'rejected_dates' => $rejected_dates,
			'mail_pending' => $config['application_mail_pending'] ?? '',
			'mail_accepted' => $config['application_mail_accepted'] ?? '',
			'mail_rejected' => $config['application_mail_rejected'] ?? '',
			'signature' => $config['application_mail_signature'],
		]);

		return array(
			'title' => $title,
			'body' => $body
		);
	}


	function send_notification($application, $created = false, $assocciated = false)
	{
		// Skip email notifications for PENDING status
		if ($application['status'] == 'PENDING' && !$created) {
			return true;
		}
		
		// Use modern EmailService for email notifications
		$emailService = new EmailService();
		$success = $emailService->sendApplicationNotification($application, $created, $assocciated);
		
		// Handle additional notifications to case officers (BCC functionality)
		if ($created) {
			$this->sendCaseOfficerNotifications($application);
		}
		
		// Handle SMS notifications (legacy functionality preserved)
		if ($created) {
			$this->sendSmsNotifications($application);
		}
		
		// Return recipient email for compatibility with existing code
		return $success ? $application['contact_email'] : '';
	}

	/**
	 * Send notifications to case officers (preserves legacy BCC functionality)
	 */
	private function sendCaseOfficerNotifications($application)
	{
		if (!(isset($this->serverSettings['smtp_server']) && $this->serverSettings['smtp_server'])) {
			return;
		}

		$building_info = $this->so->get_building_info($application['id']);
		$extra_mail_addresses = $this->get_mail_addresses($building_info['id'], $application['case_officer_id']);

		$mail_addresses = array();
		foreach ($extra_mail_addresses as $user_id => $extra_mail_address) {
			$prefs = CreateObject('phpgwapi.preferences', $user_id)->read();
			if (isset($prefs['booking']['notify_on_new']) && ($prefs['booking']['notify_on_new'] & 1)) {
				$mail_addresses[] = $prefs['common']['email'];
			}
		}

		if (empty($mail_addresses)) {
			return;
		}

		try {
			$config = CreateObject('phpgwapi.config', 'booking');
			$config->read();

			$from = isset($config->config_data['email_sender']) && $config->config_data['email_sender']
				? $config->config_data['email_sender']
				: "noreply<noreply@{$this->serverSettings['hostname']}>";

			$subject = "KOPI::" . $config->config_data['application_mail_subject'];

			// Create backend link
			$enforce_ssl = $this->serverSettings['enforce_ssl'];
			$this->serverSettings['enforce_ssl'] = true;
			$link_backend = phpgw::link('/index.php', array('menuaction' => 'booking.uiapplication.show', 'id' => $application['id']), false, true, true);
			$this->serverSettings['enforce_ssl'] = $enforce_ssl;

			$resourcename = implode(", ", $this->get_resource_name($application['resources']));

			$twig = new EmailTwigHelper('booking');
			$body = $twig->render('@views/emails/case_officer_notification.twig', [
				'contact_email' => $application['contact_email'],
				'systemname' => $config->config_data['application_mail_systemname'],
				'resourcename' => $resourcename,
				'building_name' => $application['building_name'],
				'mail_created' => $config->config_data['application_mail_created'],
				'link_backend' => $link_backend,
				'signature' => $config->config_data['application_mail_signature'],
			]);

			$send = CreateObject('phpgwapi.send');
			$bcc = implode(';', $mail_addresses);
			$send->msg('email', $bcc, $subject, $body, '', '', '', $from, 'AktivKommune', 'html', '', array(), false);

		} catch (Exception $e) {
			Cache::message_set("Case officer notification failed: " . $e->getMessage(), 'error');
		}
	}

	/**
	 * Send SMS notifications (preserves legacy functionality)
	 */
	private function sendSmsNotifications($application)
	{
		$building_info = $this->so->get_building_info($application['id']);
		$extra_mail_addresses = $this->get_mail_addresses($building_info['id'], $application['case_officer_id']);

		$cellphones = array();
		foreach ($extra_mail_addresses as $user_id => $extra_mail_address) {
			$prefs = CreateObject('phpgwapi.preferences', $user_id)->read();
			if (isset($prefs['booking']['notify_on_new']) && ($prefs['booking']['notify_on_new'] & 2)) {
				$cellphones[] = $prefs['common']['cellphone'];
			}
		}

		if (empty($cellphones)) {
			return;
		}

		try {
			$sms = CreateObject('sms.sms');
			$sms_message = "Ny søknad på {$application['building_name']}";
			foreach ($cellphones as $cellphone) {
				if ($cellphone) {
					$sms->websend2pv($this->userSettings['account_id'], $cellphone, $sms_message);
				}
			}
		} catch (Exception $e) {
			error_log("SMS notification failed: " . $e->getMessage());
		}
	}


	function get_related_files($application)
	{
		phpgw::import_class('booking.sodocument');

		$where_filter = array();

		if (empty($application['building_id']))
		{
			$building_info = $this->so->get_building_info($application['id']);
			$application['building_id'] = $building_info['id'];
		}

		if ($application['building_id'])
		{
			$where_filter[] = "(%%table%%.type='building' AND %%table%%.owner_id = {$application['building_id']})";
		}

		foreach ($application['resources'] as $resource_id)
		{
			$where_filter[] = "(%%table%%.type='resource' AND %%table%%.owner_id = {$resource_id})";
		}

		$regulations_params = array(
			'start' => 0,
			'sort' => 'name',
			'filters' => array(
				'active' => 1,
				'category' => array(
					booking_sodocument::CATEGORY_REGULATION,
					booking_sodocument::CATEGORY_HMS_DOCUMENT,
					booking_sodocument::CATEGORY_PRICE_LIST
				),
				'where' => array('(' . join(' OR ', $where_filter) . ')')
			)
		);


		$sodocument_view = createObject('booking.sodocument_view');
		$files = $sodocument_view->read($regulations_params);
		$mime_magic	 = createObject('phpgwapi.mime_magic');
		$attachments = array();
		foreach ($files['results'] as $file)
		{
			$document = $sodocument_view->read_single($file['id']);
			$attachments[] = array(
				'file'	 => $document['filename'],
				'name'	 => basename($document['filename']),
				'type'	 => $mime_magic->filename2mime(basename($document['filename']))
			);
		}

		return $attachments;
	}
	/**
	 *
	 * @param int $building_id
	 * @param int $user_id - the case officer, if any
	 * @return array
	 */
	function get_mail_addresses($building_id, $user_id = 0)
	{
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
	}


	/**
	 * Send message about comment on application to case officer.
	 */
	function send_admin_notification($application, $message = null)
	{
		if (!(isset($this->serverSettings['smtp_server']) && $this->serverSettings['smtp_server']))
		{
			//				return;
		}
		$send = CreateObject('phpgwapi.send');

		$config = CreateObject('phpgwapi.config', 'booking');
		$config->read();

		$from = isset($config->config_data['email_sender']) && $config->config_data['email_sender'] ? $config->config_data['email_sender'] : "noreply<noreply@{$this->serverSettings['hostname']}>";

		$subject = $config->config_data['application_comment_mail_subject_caseofficer'];

		$mailadresses = $config->config_data['emails'];
		$mailadresses = explode("\n", $mailadresses);

		$building_info = $this->so->get_building_info($application['id']);
		$extra_mail_addresses = $this->get_mail_addresses($building_info['id'], $application['case_officer_id']);

		if (!empty($mailadresses[0]))
		{
			$mailadresses = array_merge($mailadresses, array_values($extra_mail_addresses));
		}
		else
		{
			$mailadresses = array_values($extra_mail_addresses);
		}

		// Generate backend link with SSL enforcement
		$enforce_ssl = $this->serverSettings['enforce_ssl'];
		$this->serverSettings['enforce_ssl'] = true;
		$link = phpgw::link('/index.php', array('menuaction' => 'booking.uiapplication.show', 'id' => $application['id']), false, true, true);
		$link = str_replace('&amp;', '&', $link);
		$this->serverSettings['enforce_ssl'] = $enforce_ssl;

		$activity = $this->activity_bo->read_single($application['activity_id']);

		// Determine organization name if applicable
		$organization_name = '';
		if (strlen($application['customer_organization_number']) == 9)
		{
			$orgid = $this->organization_bo->so->get_orgid($application['customer_organization_number']);
			$organization = $this->organization_bo->read_single($orgid);
			$organization_name = $organization['name'];
		}

		$twig = new EmailTwigHelper('booking');
		$plain_text = $twig->render('@views/emails/admin_comment_notification.twig', [
			'organization_name' => $organization_name,
			'contact_name' => $application['contact_name'],
			'message' => $message,
			'building_name' => $application['building_name'],
			'activity_name' => $activity['name'],
			'contact_email' => $application['contact_email'],
			'contact_phone' => $application['contact_phone'],
			'link' => $link,
		]);

		// Strip HTML for plain text version
		$plain_text_stripped = strip_tags(str_replace(['<br />', '<br/>', '<br>'], "\n", $plain_text));

		$_mailadresses = array_unique($mailadresses);
		foreach ($_mailadresses as $adr)
		{
			try
			{
				$send->msg('email', $adr, $subject, $plain_text_stripped, '', '', '', $from, 'AktivKommune', 'text');

				if ($this->flags['currentapp'] == 'booking')
				{
					Cache::message_set("Epost er sendt til {$adr}");
				}
			}
			catch (Exception $e)
			{
				Cache::message_set("Epost feilet til {$adr}", 'error');

				$log = new Log();
				$log->error(array(
					'text'	=> 'booking_boapplication::send_admin_notification() : error when trying to send email. Error: %1',
					'p1'	=> $e->getMessage(),
					'line'	=> __LINE__,
					'file'	=> __FILE__
				));
			}
		}
	}

	/**
	 * Returns a sql-statement of application ids from applications assocciated with buildings
	 * which the given user has access to
	 *
	 * @param int $user_id
	 * @param int $building_id
	 * @return string $sql
	 */
	public function accessable_applications($user_id, $building_id)
	{
		$filtermethod = array();

		$filtermethod[] = '1=1';

		$sql = "SELECT DISTINCT ap.id"
			. " FROM bb_application ap"
			. " INNER JOIN bb_application_resource ar ON ar.application_id = ap.id"
			. " INNER JOIN bb_building_resource br ON br.resource_id = ar.resource_id"
			. " INNER JOIN bb_building bu ON bu.id = br.building_id";

		if ($user_id)
		{
			if (is_array($user_id))
			{
				$users = $user_id;
			}
			else
			{
				$users = array($user_id);
			}
			$filtermethod[] = "((pe.subject_id IN ( " . implode(',', $users) . ")"
				. " AND ap.case_officer_id IS NULL) OR  ap.case_officer_id IN ( " . implode(',', $users) . "))";
			$sql .= " INNER JOIN bb_permission pe ON pe.object_id = bu.id and pe.object_type = 'building'";
		}

		if ($building_id)
		{
			$filtermethod[] = "bu.id = {$building_id}";
		}

		$sql .=  " WHERE " . implode(' AND ', $filtermethod);
		return $sql;
	}

	public function read_dashboard_data($for_case_officer_id = array(null, null))
	{
		$params = $this->build_default_read_params();

		if (!isset($params['filters']))
		{
			$params['filters'] = array();
		}
		$where_clauses = !isset($params['filters']['where']) ? array() : (array)$params['filters']['where'];

		if (!is_null($for_case_officer_id[0]))
		{
			$where_clauses[] = "(%%table%%.display_in_dashboard = 1 AND %%table%%.case_officer_id = " . intval($for_case_officer_id[1]) . ')';
		}
		else
		{
			$where_clauses[] = "(%%table%%.display_in_dashboard = 1)";
			//				$where_clauses[] = "(%%table%%.case_officer_id = " . intval($for_case_officer_id[1]) . ')';
		}

		if ($building_id = Sanitizer::get_var('filter_building_id', 'int', 'REQUEST', 0))
		{
			$where_clauses[] = "(%%table%%.id IN ("
				. " SELECT DISTINCT a.id"
				. " FROM bb_application a, bb_application_resource ar, bb_resource r, bb_building_resource br "
				. " WHERE ar.application_id = a.id AND ar.resource_id = r.id AND br.resource_id =r.id  AND br.building_id = " . intval($building_id) . "))";
		}

		if ($status = Sanitizer::get_var('status') != '')
		{
			$params['filters']['status'] = Sanitizer::get_var('status');
		}

		$params['filters']['where'] = $where_clauses;

		return $this->so->read($params);
	}


	function get_partials_list($session_id = '')
	{
		$list = array();
		if (!empty($session_id))
		{
			$filters = array('status' => 'NEWPARTIAL1', 'session_id' => $session_id);
			$params = array('filters' => $filters, 'results' => 'all');
			$applications = $this->so->read($params);
			$this->so->get_purchase_order($applications);
			$list = $applications;
		}
		return $list;
	}


	function delete_application($id)
	{
		$this->so->delete_application($id);
	}
}

class booking_boapplication_association extends booking_bocommon
{

	var $so;
	function __construct()
	{
		parent::__construct();
		$this->so = new booking_soapplication_association();
	}
}
