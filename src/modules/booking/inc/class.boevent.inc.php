<?php

use App\modules\bookingfrontend\helpers\UserHelper;

phpgw::import_class('booking.bocommon_authorized');

class booking_boevent extends booking_bocommon_authorized
{

	const ROLE_ADMIN = 'organization_admin';

	function __construct()
	{
		parent::__construct();
		$this->so = CreateObject('booking.soevent');
	}

	/**
	 * @see booking_bocommon_authorized
	 */
	protected function get_subject_roles($for_object = null, $initial_roles = array())
	{
		if ($this->current_app() == 'bookingfrontend')
		{
			$bouser = new UserHelper();

			if (is_array($for_object) && $for_object['customer_organization_number'])
			{
				$org = $this->so->get_org($for_object['customer_organization_number']);
				$for_object['customer_organization_id'] = $org['id'];
				$for_object['customer_organization_name'] = $org['name'];
			}

			$org_id = is_array($for_object) ? $for_object['customer_organization_id'] : (!is_null($for_object) ? $for_object : null);

			$found_credentials = false;
			if ($bouser->is_organization_admin($org_id))
			{
				$initial_roles[] = array('role' => self::ROLE_ADMIN);
				$found_credentials = true;
			}

			$external_login_info = $bouser->get_cached_user_data();

			if (!$found_credentials && $for_object['customer_ssn'] == $external_login_info['ssn'])
			{
				$initial_roles[] = array('role' => self::ROLE_ADMIN);
			}

			//final check on parent object (application)
			if (!$found_credentials && $for_object['application_id'])
			{
				$check_for_owner = CreateObject('booking.boapplication')->read_single($for_object['application_id']);
				$application_owner_person = !empty($external_login_info['ssn']) && $check_for_owner['customer_ssn'] == $external_login_info['ssn'] ? true : false;
				$application_owner_organization = $bouser->is_organization_admin($check_for_owner['customer_organization_id']);

				if ($application_owner_person || $application_owner_organization)
				{
					$initial_roles[] = array('role' => self::ROLE_ADMIN);
				}
			}
		}
		return parent::get_subject_roles($for_object, $initial_roles);
	}

	/**
	 * @see bocommon_authorized
	 */
	protected function get_object_role_permissions($forObject, $defaultPermissions)
	{
		if ($this->current_app() == 'booking')
		{
			$defaultPermissions[booking_sopermission::ROLE_DEFAULT] = array(
				'read' => true,
				'delete' => true,
				'write' => true,
				'create' => true,
			);
		}

		if ($this->current_app() == 'bookingfrontend')
		{
			$defaultPermissions[self::ROLE_ADMIN] = array(
				'write' => array_fill_keys(array(
					'active', 'description', 'from_', 'to_', 'contact_name',
					'contact_email',
					'contact_phone', 'activity_name', 'audience', 'agegroups', 'is_public', 'include_in_list',
					'equipment'
				), true),
			);
		}

		return $defaultPermissions;
	}

	/**
	 * @see bocommon_authorized
	 */
	protected function get_collection_role_permissions($defaultPermissions)
	{
		if ($this->current_app() == 'booking')
		{
			$defaultPermissions[booking_sopermission::ROLE_DEFAULT]['create'] = true;
			$defaultPermissions[booking_sopermission::ROLE_DEFAULT]['write'] = true;
		}

		return $defaultPermissions;
	}

	public function get_permissions(array $entity)
	{
		return parent::get_permissions($entity);
	}

	public function complete_expired(&$events)
	{
		$this->so->complete_expired($events);
	}

	public function find_expired($update_reservation_time)
	{
		return $this->so->find_expired($update_reservation_time);
	}

	public function find_request_access($stage, $time_ahead)
	{
		return $this->so->find_request_access($stage, $time_ahead);
	}

	public function complete_request_access(&$events, $stage)
	{
		$this->so->complete_request_access($events, $stage);
	}

	/**
	 * @ Send message about cancelation/modification on event to users of building.
	 */
	function send_notification($type, $event, $mailadresses, $orgdate = null)
	{
		if (!(isset($this->serverSettings['smtp_server']) && $this->serverSettings['smtp_server']))
		{
			return;
		}

		$send = CreateObject('phpgwapi.send');

		$config = CreateObject('phpgwapi.config', 'booking');
		$config->read();

		$from = isset($config->config_data['email_sender']) && $config->config_data['email_sender'] ? $config->config_data['email_sender'] : "noreply<noreply@{$this->serverSettings['hostname']}>";

		$external_site_address = isset($config->config_data['external_site_address']) && $config->config_data['external_site_address'] ? $config->config_data['external_site_address'] : $this->serverSettings['webserver_url'];

		//        $link = $external_site_address.'/bookingfrontend/?menuaction=bookingfrontend.uiapplication.add&building_id=';
		//        $link .= $event['building_id'].'&building_name='.urlencode($event['building_name']).'&from_[]=';
		//        $link .= urlencode($event['from_']).'&to_[]='.urlencode($event['to_']).'&resource='.implode(",",$event['resources']);

		$link = $external_site_address . '/bookingfrontend/?menuaction=bookingfrontend.uibuilding.schedule&id=';
		$link .= $event['building_id'] . '&date=' . substr($event['from_'], 0, 10);
		$body = "";
		$subject = "";
		if (!$type)
		{
			$subject .= $config->config_data['event_canceled_mail_subject'];
			$body .= "<p>" . $config->config_data['event_canceled_mail'];
		}
		else
		{
			$subject .= $config->config_data['event_edited_mail_subject'];
			$body .= "<p>" . $config->config_data['event_edited_mail'];
		}

		if ($_POST['org_from'] < $event['from_'] && $_POST['org_to'] == $event['to_'])
		{
			$event['from_'] = $_POST['org_from'];
			$event['to_'] = $event['from_'];
			$freetime = pretty_timestamp($event['from_']) . ' til ' . pretty_timestamp($event['to_']);
		}
		elseif ($_POST['org_from'] == $event['from_'] && $_POST['org_to'] > $event['to_'])
		{
			$event['from_'] = $event['to_'];
			$event['to_'] = $_POST['org_to'];
			$freetime = pretty_timestamp($event['from_']) . ' til ' . pretty_timestamp($event['to_']);
		}
		elseif ($_POST['org_from'] < $event['from_'] && $_POST['org_to'] > $event['to_'])
		{
			$freetime = pretty_timestamp($_POST['org_from']) . ' til ' . pretty_timestamp($event['from_']) . " og \n";
			$freetime .= pretty_timestamp($event['to_']) . ' til ' . pretty_timestamp($_POST['org_to']);
		}
		else
		{
			$freetime = pretty_timestamp($event['from_']) . ' til ' . pretty_timestamp($event['to_']) . "\n";
		}

		$body .= '</p><p>' . $event['customer_organization_name'] ? $event['customer_organization_name'] : $event['contact_name'] . ' har avbestilt tid i ' . $event['building_name'] . ':<br />';
		$body .= implode(", ", $this->so->get_resources(implode(",", $event['resources']))) . ' den ' . $freetime;
		$body .= ' - <a href="' . $link . '">' . lang('Check calendar') . '</a></p>';
		$body .= "<p>" . $config->config_data['application_mail_signature'] . "</p>";

		$_mailadresses = array_unique($mailadresses);
		foreach ($_mailadresses as $adr)
		{
			try
			{
				$send->msg('email', $adr, $subject, $body, '', '', '', $from, 'AktivKommune', 'html');
			}
			catch (Exception $e)
			{
				// TODO: Inform user if something goes wrong
			}
		}
	}

	/**
	 * @ Send message about cancelation/modification on event to admins of building.
	 */
	function send_admin_notification($type, $event, $message = null, $orgdate = null)
	{
		if (empty($this->serverSettings['smtp_server']))
		{
			return;
		}
		$send = CreateObject('phpgwapi.send');

		$config = CreateObject('phpgwapi.config', 'booking');
		$config->read();

		$from = isset($config->config_data['email_sender']) && $config->config_data['email_sender'] ? $config->config_data['email_sender'] : "noreply<noreply@{$this->serverSettings['hostname']}>";

		$external_site_address = isset($config->config_data['external_site_address']) && $config->config_data['external_site_address'] ? $config->config_data['external_site_address'] : $this->serverSettings['webserver_url'];

		if (!$type)
		{
			$subject = $config->config_data['event_canceled_mail_subject'];
		}
		else
		{
			$subject = $config->config_data['event_edited_mail_subject'];
		}

		$body = '<b>Beksjed fra ' . $event['customer_organization_name'] ? $event['customer_organization_name'] : $event['contact_name'] . '</b><br />' . $message . '<br /><br/>';
		$body .= '<b>Kontaktperson:</b> ' . $event['contact_name'] . '<br />';
		$body .= '<b>Epost:</b> ' . $event['contact_email'] . '<br />';
		$body .= '<b>Telefon:</b> ' . $event['contact_phone'] . '<br /><br />';
		$body .= '<br /><b>Epost som er sendt til brukere av Hallen:</b><br />';

		$mailadresses = $config->config_data['emails'];
		$mailadresses = explode("\n", $mailadresses);

		$extra_mail_addresses = CreateObject('booking.boapplication')->get_mail_addresses($event['building_id']);

		if (!empty($mailadresses[0]))
		{
			$mailadresses = array_merge($mailadresses, array_values($extra_mail_addresses));
		}
		else
		{
			$mailadresses = array_values($extra_mail_addresses);
		}

		$link = $external_site_address . '/bookingfrontend/?menuaction=bookingfrontend.uibuilding.schedule&id=';
		$link .= $event['building_id'] . '&date=' . substr($event['from_'], 0, 10);

		if (!$type)
		{
			$body .= $config->config_data['event_canceled_mail_subject'];
			$body .= "<p>" . $config->config_data['event_canceled_mail'];
		}
		else
		{
			$body .= $config->config_data['event_edited_mail_subject'];
			$body .= "<p>" . $config->config_data['event_edited_mail'];
		}

		if ($_POST['org_from'] < $event['from_'] && $_POST['org_to'] == $event['to_'])
		{
			$event['from_'] = $_POST['org_from'];
			$event['to_'] = $event['from_'];
			$freetime = pretty_timestamp($event['from_']) . ' til ' . pretty_timestamp($event['to_']);
		}
		elseif ($_POST['org_from'] == $event['from_'] && $_POST['org_to'] > $event['to_'])
		{
			$event['from_'] = $event['to_'];
			$event['to_'] = $_POST['org_to'];
			$freetime = pretty_timestamp($event['from_']) . ' til ' . pretty_timestamp($event['to_']);
		}
		elseif ($_POST['org_from'] < $event['from_'] && $_POST['org_to'] > $event['to_'])
		{
			$freetime = pretty_timestamp($_POST['org_from']) . ' til ' . pretty_timestamp($event['from_']) . " og \n";
			$freetime .= pretty_timestamp($event['to_']) . ' til ' . pretty_timestamp($_POST['org_to']);
		}
		else
		{
			$freetime = pretty_timestamp($event['from_']) . ' til ' . pretty_timestamp($event['to_']) . "\n";
		}

		$body .= '</p><p>' . $event['customer_organization_name'] ? $event['customer_organization_name'] : $event['contact_name'] . ' har endret tid i ' . $event['building_name'] . ':<br />';
		$body .= implode(", ", $this->so->get_resources(implode(",", $event['resources']))) . ' den ' . $freetime;
		$body .= ' - <a href="' . $link . '">' . lang('Check calendar') . '</a></p>';

		if ($event['application_id'])
		{
			$link_application = $external_site_address . '/?menuaction=booking.uiapplication.show&id=';
			$link_application .= $event['application_id'];

			$body .= '<p> - <a href="' . $link_application . '">' . lang('Application') . '</a></p>';
		}

		$body .= "<p>" . $config->config_data['application_mail_signature'] . "</p>";

		$_mailadresses = array_unique($mailadresses);
		foreach ($_mailadresses as $adr)
		{
			try
			{
				$send->msg('email', $adr, $subject, $body, '', '', '', $from, 'AktivKommune', 'html');
			}
			catch (Exception $e)
			{
				// TODO: Inform user if something goes wrong
			}
		}
	}
	public function add_single_comment($event_id, $comment, $type = 'comment')
	{
		return $this->so->add_single_comment($event_id, $comment, $type);
	}
}
