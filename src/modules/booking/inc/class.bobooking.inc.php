<?php
use App\modules\bookingfrontend\helpers\UserHelper;

phpgw::import_class('booking.bocommon_authorized');

	require_once "schedule.php";

	function array_minus( $a, $b )
	{
		$b	 = array_flip($b);
		$c	 = array();
		foreach ($a as $x)
		{
			if (!array_key_exists($x, $b))
			{
				$c[] = $x;
			}
		}
		return $c;
	}

	class booking_bobooking extends booking_bocommon_authorized
	{

		const ROLE_ADMIN = 'organization_admin';
		var
			$allocation_so,
			$resource_so,
			$event_so,
			$season_bo;

		function __construct()
		{
			parent::__construct();
			$this->so			 = CreateObject('booking.sobooking');
			$this->allocation_so = CreateObject('booking.soallocation');
			$this->resource_so	 = CreateObject('booking.soresource');
			$this->event_so		 = CreateObject('booking.soevent');
			$this->season_bo	 = CreateObject('booking.boseason');
		}

		/**
		 * @ Send message about cancelation to users of building.
		 */
		function send_notification( $booking, $allocation, $maildata, $mailadresses, $valid_dates = null )
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


			if (($maildata['outseason'] != 'on' && $maildata['recurring'] != 'on' && $maildata['delete_allocation'] != 'on') ||
				($maildata['outseason'] != 'on' && $maildata['recurring'] != 'on' && $maildata['delete_allocation'] == 'on' &&
				$maildata['allocation'] == 0))
			{
				$link	 = $external_site_address . '/bookingfrontend/?menuaction=bookingfrontend.uiapplication.add&building_id=';
				$link	 .= $booking['building_id'] . '&building_name=' . urlencode($booking['building_name']) . '&from_[]=';
				$link	 .= urlencode($booking['from_']) . '&to_[]=' . urlencode($booking['to_']) . '&resource=' . $booking['resources'][0];

				$subject = $config->config_data['booking_canceled_mail_subject'];

				$body	 = "<p>" . $config->config_data['booking_canceled_mail'];
				$body	 .= '</p><p>' . $booking['group_name'] . ' har avbestilt tid i ' . $booking['building_name'] . ':<br />';
				$body	 .= $this->so->get_resource($booking['resources'][0]) . ' den ' . pretty_timestamp($booking['from_']);
				$body	 .= ' til ' . pretty_timestamp($booking['to_']);
				$body	 .= ' - <a href="' . $link . '">' . lang('Apply for time') . '</a></p>';
			}
			elseif (($maildata['outseason'] == 'on' || $maildata['recurring'] == 'on') && $maildata['delete_allocation'] != 'on')
			{
				$res_names = '';
				foreach ($booking['resources'] as $res)
				{
					$res_names = $res_names . $this->so->get_resource($res) . " ";
				}
				$info_deleted = ':<p>';
				foreach ($valid_dates as $valid_date)
				{
					$info_deleted	 = $info_deleted . "" . $res_names . " - ";
					$info_deleted	 .= pretty_timestamp($valid_date['from_']) . " - ";
					$info_deleted	 .= pretty_timestamp($valid_date['to_']);
					$link			 = $external_site_address . '/bookingfrontend/?menuaction=bookingfrontend.uiapplication.add&building_id=';
					$link			 .= $booking['building_id'] . '&building_name=' . urlencode($booking['building_name']);
					$link			 .= '&from_[]=' . urlencode($valid_date['from_']) . '&to_[]=' . urlencode($valid_date['to_']) . '&resource=' . $booking['resources'][0];
					$info_deleted	 .= ' - <a href="' . $link . '">' . lang('Apply for time') . '</a><br />';
				}

				$subject = $config->config_data['booking_canceled_mail_subject'];

				$body	 = "<p>" . $config->config_data['booking_canceled_mail'];
				$body	 .= '<br />' . $booking['group_name'] . ' har avbestilt tid i ' . $booking['building_name'];
				$body	 .= $info_deleted . '</p>';
			}
			elseif (($maildata['outseason'] == 'on' || $maildata['recurring'] == 'on') && $maildata['delete_allocation'] == 'on')
			{
				$res_names = '';
				foreach ($booking['resources'] as $res)
				{
					$res_names = $res_names . $this->so->get_resource($res) . " ";
				}
				$info_deleted = ':<p>';
				foreach ($valid_dates as $valid_date)
				{
					if (!in_array($valid_date, $maildata['delete']))
					{
						$info_deleted	 = $info_deleted . "" . $res_names . " - ";
						$info_deleted	 .= pretty_timestamp($valid_date['from_']) . " - ";
						$info_deleted	 .= pretty_timestamp($valid_date['to_']);
						$link			 = $external_site_address . '/bookingfrontend/?menuaction=bookingfrontend.uiapplication.add&building_id=';
						$link			 .= $booking['building_id'] . '&building_name=' . urlencode($booking['building_name']) . '&from_[]=';
						$link			 .= urlencode($valid_date['from_']) . '&to_[]=' . urlencode($valid_date['to_']) . '&resource=' . $booking['resources'][0];
						$info_deleted	 .= ' - <a href="' . $link . '">' . lang('Apply for time') . '</a><br />';
					}
				}
				foreach ($maildata['delete'] as $valid_date)
				{
					$info_deleted	 = $info_deleted . "" . $res_names . " - ";
					$info_deleted	 .= pretty_timestamp($valid_date['from_']) . " - ";
					$info_deleted	 .= pretty_timestamp($valid_date['to_']);
					$link			 = $external_site_address . '/bookingfrontend/?menuaction=bookingfrontend.uiapplication.add&building_id=';
					$link			 .= $booking['building_id'] . '&building_name=' . urlencode($booking['building_name']) . '&from_[]=';
					$link			 .= urlencode($valid_date['from_']) . '&to_[]=' . urlencode($valid_date['to_']) . '&resource=' . $booking['resources'][0];
					$info_deleted	 .= ' - <a href="' . $link . '">' . lang('Apply for time') . '</a><br />';
				}


				$subject = $config->config_data['allocation_canceled_mail_subject'];
				$body	 = "<p>" . $config->config_data['allocation_canceled_mail'];
				$body	 .= '<br />' . $booking['group_name'] . ' har avbestilt tid i ' . $booking['building_name'];
				$body	 .= $info_deleted . '</p>';
			}
			else
			{
				$res_names = '';
				foreach ($booking['resources'] as $res)
				{
					$res_names = $res_names . $this->so->get_resource($res) . " ";
				}
				$info_deleted = ':<p>';
				foreach ($maildata['delete'] as $valid_date)
				{
					$info_deleted	 = $info_deleted . "" . $res_names . " - ";
					$info_deleted	 .= pretty_timestamp($allocation['from_']) . " - ";
					$info_deleted	 .= pretty_timestamp($allocation['to_']);
					$link			 = $external_site_address . '/bookingfrontend/?menuaction=bookingfrontend.uiapplication.add&building_id=';
					$link			 .= $booking['building_id'] . '&building_name=' . urlencode($booking['building_name']) . '&from_[]=';
					$link			 .= urlencode($valid_date['from_']) . '&to_[]=' . urlencode($valid_date['to_']) . '&resource=' . $booking['resources'][0];
					$info_deleted	 .= ' - <a href="' . $link . '">' . lang('Apply for time') . '</a><br />';
				}
				$subject = $config->config_data['allocation_canceled_mail_subject'];
				$body	 = "<p>" . $config->config_data['allocation_canceled_mail'];
				$body	 .= '<br />' . $booking['group_name'] . ' har avbestilt tid i ' . $booking['building_name'];
				$body	 .= $info_deleted . '</p>';
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

		function send_admin_notification( $booking, $maildata, $system_message, $allocation, $valid_dates = null )
		{
			if (!(isset($this->serverSettings['smtp_server']) && $this->serverSettings['smtp_server']))
				return;
			$send = CreateObject('phpgwapi.send');

			$config = CreateObject('phpgwapi.config', 'booking');
			$config->read();

			$from = isset($config->config_data['email_sender']) && $config->config_data['email_sender'] ? $config->config_data['email_sender'] : "noreply<noreply@{$this->serverSettings['hostname']}>";

			$external_site_address = isset($config->config_data['external_site_address']) && $config->config_data['external_site_address'] ? $config->config_data['external_site_address'] : $this->serverSettings['webserver_url'];

			$subject		 = $system_message['title'];
			$body			 = '<b>Beskjed fra ' . $system_message['name'] . '</b><br />' . $system_message['message'] . '<br /><br /><b>Epost som er sendt til brukere av Hallen:</b><br />';
			$mailadresses	 = $config->config_data['emails'];
			$mailadresses	 = explode("\n", $mailadresses);

			$extra_mail_addresses = CreateObject('booking.boapplication')->get_mail_addresses( $booking['building_id'] );

			if(!empty($mailadresses[0]))
			{
				$mailadresses = array_merge($mailadresses, array_values($extra_mail_addresses));
			}
			else
			{
				$mailadresses = array_values($extra_mail_addresses);
			}

			if (($maildata['outseason'] != 'on' && $maildata['recurring'] != 'on' && $maildata['delete_allocation'] != 'on') ||
				($maildata['outseason'] != 'on' && $maildata['recurring'] != 'on' && $maildata['delete_allocation'] == 'on' &&
				$maildata['allocation'] == 0))
			{
				$link	 = $external_site_address . '/bookingfrontend/?menuaction=bookingfrontend.uiapplication.add&building_id=';
				$link	 .= $booking['building_id'] . '&building_name=' . urlencode($booking['building_name']) . '&from_[]=';
				$link	 .= urlencode($booking['from_']) . '&to_[]=' . urlencode($booking['to_']) . '&resource=' . $booking['resources'][0];

				$body	 .= "<p>" . $config->config_data['booking_canceled_mail'];
				$body	 .= '</p><p>' . $booking['group_name'] . ' har avbestilt tid i ' . $booking['building_name'] . ':<br />';
				$body	 .= $this->so->get_resource($booking['resources'][0]) . ' den ' . pretty_timestamp($booking['from_']);
				$body	 .= ' til ' . pretty_timestamp($booking['to_']);
				$body	 .= ' - <a href="' . $link . '">' . lang('Apply for time') . '</a></p>';
			}
			elseif (($maildata['outseason'] == 'on' || $maildata['recurring'] == 'on') && $maildata['delete_allocation'] != 'on')
			{
				$res_names = '';
				foreach ($booking['resources'] as $res)
				{
					$res_names = $res_names . $this->so->get_resource($res) . " ";
				}
				$info_deleted = ':<p>';
				foreach ($valid_dates as $valid_date)
				{
					$info_deleted	 = $info_deleted . "" . $res_names . " - ";
					$info_deleted	 .= pretty_timestamp($valid_date['from_']) . " - ";
					$info_deleted	 .= pretty_timestamp($valid_date['to_']);
					$link			 = $external_site_address . '/bookingfrontend/?menuaction=bookingfrontend.uiapplication.add&building_id=';
					$link			 .= $booking['building_id'] . '&building_name=' . urlencode($booking['building_name']);
					$link			 .= '&from_[]=' . urlencode($valid_date['from_']) . '&to_[]=' . urlencode($valid_date['to_']) . '&resource=' . $booking['resources'][0];
					$info_deleted	 .= ' - <a href="' . $link . '">' . lang('Apply for time') . '</a><br />';
				}

				$body	 .= "<p>" . $config->config_data['booking_canceled_mail'];
				$body	 .= '<br />' . $booking['group_name'] . ' har avbestilt tid i ' . $booking['building_name'];
				$body	 .= $info_deleted . '</p>';
			}
			elseif (($maildata['outseason'] == 'on' || $maildata['recurring'] == 'on') && $maildata['delete_allocation'] == 'on')
			{
				$res_names = '';
				foreach ($booking['resources'] as $res)
				{
					$res_names = $res_names . $this->so->get_resource($res) . " ";
				}
				$info_deleted = ':<p>';
				foreach ($valid_dates as $valid_date)
				{
					if (!in_array($valid_date, $maildata['delete']))
					{
						$info_deleted	 = $info_deleted . "" . $res_names . " - ";
						$info_deleted	 .= pretty_timestamp($valid_date['from_']) . " - ";
						$info_deleted	 .= pretty_timestamp($valid_date['to_']);
						$link			 = $external_site_address . '/bookingfrontend/?menuaction=bookingfrontend.uiapplication.add&building_id=';
						$link			 .= $booking['building_id'] . '&building_name=' . urlencode($booking['building_name']) . '&from_[]=';
						$link			 .= urlencode($valid_date['from_']) . '&to_[]=' . urlencode($valid_date['to_']) . '&resource=' . $booking['resources'][0];
						$info_deleted	 .= ' - <a href="' . $link . '">' . lang('Apply for time') . '</a><br />';
					}
				}
				foreach ($maildata['delete'] as $valid_date)
				{
					$info_deleted	 = $info_deleted . "" . $res_names . " - ";
					$info_deleted	 .= pretty_timestamp($valid_date['from_']) . " - ";
					$info_deleted	 .= pretty_timestamp($valid_date['to_']);
					$link			 = $external_site_address . '/bookingfrontend/?menuaction=bookingfrontend.uiapplication.add&building_id=';
					$link			 .= $booking['building_id'] . '&building_name=' . urlencode($booking['building_name']) . '&from_[]=';
					$link			 .= urlencode($valid_date['from_']) . '&to_[]=' . urlencode($valid_date['to_']) . '&resource=' . $booking['resources'][0];
					$info_deleted	 .= ' - <a href="' . $link . '">' . lang('Apply for time') . '</a><br />';
				}


				$body	 .= "<p>" . $config->config_data['allocation_canceled_mail'];
				$body	 .= '<br />' . $booking['group_name'] . ' har avbestilt tid i ' . $booking['building_name'];
				$body	 .= $info_deleted . '</p>';
			}
			else
			{
				$res_names = '';
				foreach ($booking['resources'] as $res)
				{
					$res_names = $res_names . $this->so->get_resource($res) . " ";
				}
				$info_deleted = ':<p>';
				foreach ($maildata['delete'] as $valid_date)
				{
					$info_deleted	 = $info_deleted . "" . $res_names . " - ";
					$info_deleted	 .= pretty_timestamp($allocation['from_']) . " - ";
					$info_deleted	 .= pretty_timestamp($allocation['to_']);
					$link			 = $external_site_address . '/bookingfrontend/?menuaction=bookingfrontend.uiapplication.add&building_id=';
					$link			 .= $booking['building_id'] . '&building_name=' . urlencode($booking['building_name']);
					$link			 .= '&from_[]=' . urlencode($valid_date['from_']) . '&to_[]=' . urlencode($valid_date['to_']) . '&resource=' . $booking['resources'][0];
					$info_deleted	 .= ' - <a href="' . $link . '">' . lang('Apply for time') . '</a><br />';
				}
				$body	 .= "<p>" . $config->config_data['allocation_canceled_mail'];
				$body	 .= '<br />' . $booking['group_name'] . ' har avbestilt tid i ' . $booking['building_name'];
				$body	 .= $info_deleted . '</p>';
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

		/**
		 * @see bocommon_authorized
		 */
		protected function include_subject_parent_roles( array|null $for_object = null )
		{
			$parent_roles	 = null;
			$parent_season	 = null;

			if (is_array($for_object))
			{
				if (!isset($for_object['season_id']))
				{
					throw new InvalidArgumentException('Cannot initialize object parent roles unless season_id is provided');
				}
				$parent_season = $this->season_bo->read_single($for_object['season_id']);
			}

			//Note that a null value for $parent_season is acceptable. That only signifies
			//that any roles specified for any season are returned instead of roles for a specific season.
			$parent_roles['season'] = $this->season_bo->get_subject_roles($parent_season);
			return $parent_roles;
		}

		/**
		 * @see booking_bocommon_authorized
		 */
		protected function get_subject_roles( $for_object = null, $initial_roles = array() )
		{
			if ($this->current_app() == 'bookingfrontend')
			{
				$bouser = new UserHelper();

				$group_id = is_array($for_object) ? $for_object['group_id'] : (!is_null($for_object) ? $for_object : null);

				if ($bouser->is_group_admin($group_id))
				{
					$initial_roles[] = array('role' => self::ROLE_ADMIN);
				}
			}

			return parent::get_subject_roles($for_object, $initial_roles);
		}

		/**
		 * @see bocommon_authorized
		 */
		protected function get_object_role_permissions( $forObject, $defaultPermissions )
		{
			if ($this->current_app() == 'bookingfrontend')
			{
				$defaultPermissions[self::ROLE_ADMIN] = array
					(
					'create' => true,
					'write'	 => true,
				);
			}
			return array_merge(
				array
					(
					'parent_role_permissions'	 => array
						(
						'season' => array
							(
							booking_sopermission::ROLE_MANAGER		 => array(
								'write'	 => true,
								'create' => true,
							),
							booking_sopermission::ROLE_CASE_OFFICER	 => array(
								'write'	 => true,
								'create' => true,
							),
							'parent_role_permissions'				 => array(
								'building' => array(
									booking_sopermission::ROLE_MANAGER => array(
										'write'	 => true,
										'create' => true,
									),
								),
							)
						),
					),
					'global'					 => array
						(
						booking_sopermission::ROLE_MANAGER => array
							(
							'write'	 => true,
							'delete' => true,
							'create' => true
						),
					),
				), $defaultPermissions
			);
		}

		/**
		 * @see bocommon_authorized
		 */
		protected function get_collection_role_permissions( $defaultPermissions )
		{
			if ($this->current_app() == 'bookingfrontend')
			{
				$defaultPermissions[booking_sopermission::ROLE_DEFAULT]['create']	 = true;
				$defaultPermissions[booking_sopermission::ROLE_DEFAULT]['write']	 = true;
				return $defaultPermissions;
			}
			return array_merge(
				array
					(
					'parent_role_permissions'	 => array
						(
						'season' => array
							(
							booking_sopermission::ROLE_MANAGER		 => array(
								'create' => true,
							),
							booking_sopermission::ROLE_CASE_OFFICER	 => array(
								'create' => true,
							),
							'parent_role_permissions'				 => array(
								'building' => array(
									booking_sopermission::ROLE_MANAGER => array(
										'create' => true,
									),
								),
							)
						)
					),
					'global'					 => array
						(
						booking_sopermission::ROLE_MANAGER => array
							(
							'create' => true
						)
					),
				), $defaultPermissions
			);
		}

        /**
         * Return a building's schedule for a given week in PorticoEstate Format
         *
         * @param int   $building_id
         * @param DateTime  $date
         *
         * @return array containing allocations, bookings and events
         */

        function building_schedule_pe( $building_id, $date)
        {
            $results = array();
            $from = clone $date;
            $from->setTime(0, 0, 0);
            // Make sure $from is a monday
            if ($from->format('w') != 1)
            {
                $from->modify('last monday');
            }
            $to				 = clone $from;
            $to->modify('+7 days');

            // Find building name
            $building_names = (new booking_sobuilding)->get_building_names(array($building_id));
            $building_name = $building_names[$building_id]['name'];

            $resources		 = $this->resource_so->read(array('filters'	 => array('building_id'	 => $building_id,
                'active' => 1), 'results'	 => -1));
            $resource_ids = array();
            $resources_id = array();
            foreach ($resources['results'] as $resource)
            {
                $resource_ids[] = $resource['id'];
                $resources_id[$resource['id']] = array(
                    'active' => $resource['active'],
                    'id' => $resource['id'],
                    'activity_id' => $resource['activity_id'],
                    'activity_name' => $resource['activity_name'],
                    'name' => $resource['name'],
                    'simple_booking' => $resource['simple_booking']
                );
            }

            // Allocations (Tildeling)
            $allocation_ids	 = $this->so->allocation_ids_for_resource($resource_ids, $from, $to);

            $allocations	 = $this->allocation_so->read(array('filters' => array('id' => $allocation_ids),
                'results' => -1));
            $allocations	 = $allocations['results'];
            foreach ($allocations as &$allocation)
            {
                $allocation_resources = array();
                foreach ($allocation['resources'] as $resource_id) {
                    $allocation_resources[] = $resources_id[$resource_id];
                }
                $results[] = array(
                    'type' => 'allocation',
                    'id' => $allocation['id'],
                    'id_string' => $allocation['id_string'],
                    'active' => $allocation['active'],
					'skip_bas' => $allocation['skip_bas'],
                    'building_id' => $allocation['building_id'],
                    'application_id' => $allocation['application_id'],
                    'completed' => $allocation['completed'],
                    'name' => $allocation['organization_name'],
                    'shortname' => $allocation['organization_shortname'],
                    'organization_id' => $allocation['organization_id'],
                    'resources' => $allocation_resources,
                    'season_id' => $allocation['season_id'],
                    'season_name' => $allocation['season_name'],
                    '_from' => $allocation['from_'],
                    '_to' => $allocation['to_'],
                    'from' => explode(" ", $allocation['from_'])[1],
                    'to' => explode(" ", $allocation['to_'])[1],
                    'date' => explode(" ", $allocation['from_'])[0],
                    'building_name' => $building_name
                );
            }

            // Bookings (Interntildeling)
            $booking_ids = $this->so->booking_ids_for_resource($resource_ids, $from, $to);
            $_bookings	 = $this->so->read(array('filters' => array('id' => $booking_ids), 'results' => -1));

            foreach ($_bookings['results'] as $booking)
            {
                $booking_resources = array();
                foreach ($booking['resources'] as $resource_id) {
                    $booking_resources[] = $resources_id[$resource_id];
                }
                $results[] = array(
                    'type'				 => 'booking',
                    'id'				 => $booking['id'],
                    'name'				 => $booking['group_name'],
                    'shortname'			 => $booking['group_shortname'],
                    'active'			 => $booking['active'],
					'skip_bas'			 => $booking['skip_bas'],
                    'allocation_id'		 => $booking['allocation_id'],
                    'group_id'			 => $booking['group_id'],
                    'season_id'			 => $booking['season_id'],
                    'season_name'		 => $booking['season_name'],
                    'activity_id'		 => $booking['activity_id'],
                    'activity_name'		 => $booking['activity_name'],
                    'application_id'	 => $booking['application_id'],
                    'group_name'		 => $booking['group_name'],
                    'group_shortname'	 => $booking['group_shortname'],
                    'building_id'		 => $booking['building_id'],
                    'building_name'		 => $booking['building_name'],
                    '_from' => $booking['from_'],
                    '_to' => $booking['to_'],
                    'from' => explode(" ", $booking['from_'])[1],
                    'to' => explode(" ", $booking['to_'])[1],
                    'date' => explode(" ", $booking['from_'])[0],
                    'completed'			 => $booking['completed'],
                    'reminder'			 => $booking['reminder'],
                    'resources'			 => $booking_resources,
                    'dates'				 => $booking['dates']
                );
            }

            // Events
            $event_ids	 = $this->so->event_ids_for_resource($resource_ids, $from, $to);
            $_events	 = $this->event_so->read(array('filters' => array('id' => $event_ids),
                'results' => -1));

            foreach ($_events['results'] as $event)
            {
                $event_resources = array();
                foreach ($event['resources'] as $resource_id) {
                    $event_resources[] = $resources_id[$resource_id];
                }
                $results[] = array(
                    'type'				 => 'event',
                    'id'				 => $event['id'],
                    'id_string'			 => $event['id_string'],
                    'active'			 => $event['active'],
					'skip_bas'			 => $event['skip_bas'],
                    'activity_id'		 => $event['activity_id'],
                    'application_id'	 => $event['application_id'],
                    'name'				 => $event['is_public'] ? $event['name'] : '',
                    'homepage'			 => $event['homepage'],
                    'description'		 => $event['is_public'] ? $event['description'] : '',
                    'equipment'			 => $event['equipment'],
                    'building_id'		 => $event['building_id'],
                    'building_name'		 => $event['building_name'],
                    'from' => explode(" ", $event['from_'])[1],
                    'to' => explode(" ", $event['to_'])[1],
                    'date' => explode(" ", $event['from_'])[0],
                    'completed'			 => $event['completed'],
                    'access_requested'	 => $event['access_requested'],
                    'reminder'			 => $event['reminder'],
                    '_from' => $event['from_'],
                    '_to' => $event['to_'],
                    'is_public'			 => $event['is_public'],
                    'activity_name'		 => $event['activity_name'],
                    'resources'			 => $event_resources,
                    'dates'				 => $event['dates']
                );
            }

			$soseason = CreateObject('booking.soseason');
			$seasons = $soseason->get_building_seasons($building_id, $from->format('Y-m-d'), $to->format('Y-m-d'));
            return array('total_records' => count($results), 'results' => array("schedule" => $results, "resources" => $resources_id, "seasons" => $seasons));
        }

		/**
		 * Return a building's schedule for a given week in a YUI DataSource
		 * compatible format
		 *
		 * @param int	$building_id
		 * @param $date
		 *
		 * @return array containing values from $array for the keys in $keys.
		 */
//        todo: remove debug kode
		function building_schedule( $building_id, $date )
		{
//            echo "debug:\n";
			$from = clone $date;
			$from->setTime(0, 0, 0);
			// Make sure $from is a monday
			if ($from->format('w') != 1)
			{
				$from->modify('last monday');
			}
			$to				 = clone $from;
			$to->modify('+7 days');

			$resources		 = $this->resource_so->read(array('filters'	 => array('building_id'	 => $building_id,
					'active' => 1), 'results'	 => -1));
			$resource_ids = array(-1);
			foreach ($resources['results'] as $resource)
			{
				$resource_ids[] = $resource['id'];
			}

	//		$allocation_ids	 = $this->so->allocation_ids_for_building($building_id, $from, $to);
			$allocation_ids	 = $this->so->allocation_ids_for_resource($resource_ids, $from, $to);

			$allocations	 = $this->allocation_so->read(array('filters' => array('id' => $allocation_ids),
				'results' => -1));
			$allocations	 = $allocations['results'];
			foreach ($allocations as &$allocation)
			{
				$allocation['name']		 = $allocation['organization_name'];
				$allocation['shortname'] = $allocation['organization_shortname'];
				$allocation['type']		 = 'allocation';
				unset($allocation['costs']);
				unset($allocation['comments']);
				unset($allocation['secret']);
				unset($allocation['customer_ssn']);
				unset($allocation['organizer']);
				unset($allocation['contact_name']);
				unset($allocation['contact_email']);
				unset($allocation['contact_phone']);
				unset($allocation['cost']);
				unset($allocation['sms_total']);
				unset($allocation['customer_organization_name']);
				unset($allocation['customer_organization_id']);
				unset($allocation['customer_identifier_type']);
				unset($allocation['customer_organization_number']);
				unset($allocation['customer_internal']);
				unset($allocation['include_in_list']);
				unset($allocation['agegroups']);
				unset($allocation['audience']);
			}

//			$booking_ids = $this->so->booking_ids_for_building($building_id, $from, $to);
			$booking_ids = $this->so->booking_ids_for_resource($resource_ids, $from, $to);
			$_bookings	 = $this->so->read(array('filters' => array('id' => $booking_ids), 'results' => -1));
			$bookings = array();

			/**
			 * Whitelisting
			 */
			foreach ($_bookings['results'] as $booking)
			{
				$bookings[] = array(
					'type'				 => 'booking',
					'id'				 => $booking['id'],
					'name'				 => $booking['group_name'],
					'shortname'			 => $booking['group_shortname'],
					'active'			 => $booking['active'],
					'allocation_id'		 => $booking['allocation_id'],
					'group_id'			 => $booking['group_id'],
					'season_id'			 => $booking['season_id'],
					'season_name'		 => $booking['season_name'],
					'activity_id'		 => $booking['activity_id'],
					'activity_name'		 => $booking['activity_name'],
					'application_id'	 => $booking['application_id'],
					'group_name'		 => $booking['group_name'],
					'group_shortname'	 => $booking['group_shortname'],
					'building_id'		 => $booking['building_id'],
					'building_name'		 => $booking['building_name'],
					'from_'				 => $booking['from_'],
					'to_'				 => $booking['to_'],
					'completed'			 => $booking['completed'],
					'reminder'			 => $booking['reminder'],
					'activity_name'		 => $booking['activity_name'],
					'resources'			 => $booking['resources'],
					'dates'				 => $booking['dates']
				);
			}

			$allocations = $this->split_allocations2($allocations, $bookings);

//			$event_ids	 = $this->so->event_ids_for_building($building_id, $from, $to);
			$event_ids	 = $this->so->event_ids_for_resource($resource_ids, $from, $to);
			$_events	 = $this->event_so->read(array('filters' => array('id' => $event_ids),
				'results' => -1));

			$events = array();

			/**
			 * Whitelisting
			 */
			foreach ($_events['results'] as $event)
			{
				$events[] = array(
					'type'				 => 'event',
					'name'				 => $event['name'],
					'id'				 => $event['id'],
					'id_string'			 => $event['id_string'],
					'active'			 => $event['active'],
					'activity_id'		 => $event['activity_id'],
					'application_id'	 => $event['application_id'],
					'name'				 => $event['is_public'] ? $event['name'] : '',
					'homepage'			 => $event['homepage'],
					'description'		 => $event['is_public'] ? $event['description'] : '',
					'equipment'			 => $event['equipment'],
					'building_id'		 => $event['building_id'],
					'building_name'		 => $event['building_name'],
					'from_'				 => $event['from_'],
					'to_'				 => $event['to_'],
					'completed'			 => $event['completed'],
					'access_requested'	 => $event['access_requested'],
					'reminder'			 => $event['reminder'],
					'is_public'			 => $event['is_public'],
					'activity_name'		 => $event['activity_name'],
					'resources'			 => $event['resources'],
					'dates'				 => $event['dates']
				);
			}

			$bookings	 = array_merge($allocations, $bookings);
//            echo "before rem\n";
			if ($this->current_app() == 'bookingfrontend')
			{
				$bookings	 = $this->_remove_event_conflicts($bookings, $events);
			}
			else
			{
				$bookings	 = $this->_remove_event_conflicts($bookings, $events, $list_conflicts = true);
			}
//            echo "after rem\n";

			$bookings = array_merge($events, $bookings);

			$resources		 = $resources['results'];

			$sort = array();
			foreach ($resources as $key => $row)
			{
				$sort[$key] = $row['sort'];
			}

			// Sort the resources with sortkey ascending
			// Add $resources as the last parameter, to sort by the common key
			array_multisort($sort, SORT_ASC, $resources);
			$bookings	 = $this->_split_multi_day_bookings($bookings, $from, $to);
			$results	 = build_schedule_table($bookings, $resources);
//            exit;
			return array('total_records' => count($results), 'results' => $results);
		}

	function organization_schedule($date, $organization_id, $building_id, $group_ids)
	{
		$from = clone $date;
		$from->setTime(0, 0, 0);
		// Make sure $from is a monday
		if ($from->format('w') != 1)
		{
			$from->modify('last monday');
		}
		$to				 = clone $from;
		$to->modify('+7 days');

		$resources		 = $this->resource_so->read(array('filters'	 => array('building_id'	 => $building_id,
			'active' => 1), 'results'	 => -1));
		$resource_ids = array(-1);
		foreach ($resources['results'] as $resource)
		{
			$resource_ids[] = $resource['id'];
		}

		$allocation_ids	 = $this->so->allocation_ids_for_organization($organization_id, $resource_ids, $from, $to);

		$allocations	 = $this->allocation_so->read(array('filters' => array('id' => $allocation_ids),
			'results' => -1));
		$allocations	 = $allocations['results'];
		$alloc_array = array();

		foreach ($allocations as &$allocation)
		{
			$alloc_array[] = array(
				'name'      => $allocation['organization_name'],
				'shortname' => $allocation['organization_shortname'],
				'type'      => 'allocation',
				'building_name' => $allocation['building_name'],
				'building_id' => $allocation['building_id'],
				'id' => $allocation['id'],
				'id_string' => $allocation['id_string'],
				'active' => $allocation['active'],
				'application_id' => $allocation['application_id'],
				'organization_id' => $allocation['organization_id'],
				'season_id' => $allocation['season_id'],
				'from_' => $allocation['from_'],
				'to_' => $allocation['to_'],
				'cost' => $allocation['cost'],
				'completed' => $allocation['completed'],
				'organization_name' => $allocation['organization_name'],
				'organization_shortname' => $allocation['organization_shortname'],
				'season_name' => $allocation['season_name'],
				'resources' => $allocation['resources'],
				'costs' => $allocation['costs'],
			);
		}


		if (!empty($group_ids))
		{
			$booking_ids = $this->so->booking_ids_for_organization($group_ids, $resource_ids, $from, $to);

		} else
		{
			$booking_ids = array();
		}



		$_bookings	 = $this->so->read(array('filters' => array('id' => $booking_ids), 'results' => -1));
		$bookings = array();

		/**
		 * Whitelisting
		 */
		foreach ($_bookings['results'] as $booking)
		{
			$bookings[] = array(
				'type'				 => 'booking',
				'id'				 => $booking['id'],
				'name'				 => $booking['group_name'],
				'shortname'			 => $booking['group_shortname'],
				'active'			 => $booking['active'],
				'allocation_id'		 => $booking['allocation_id'],
				'group_id'			 => $booking['group_id'],
				'season_id'			 => $booking['season_id'],
				'season_name'		 => $booking['season_name'],
				'activity_id'		 => $booking['activity_id'],
				'activity_name'		 => $booking['activity_name'],
				'application_id'	 => $booking['application_id'],
				'group_name'		 => $booking['group_name'],
				'group_shortname'	 => $booking['group_shortname'],
				'building_id'		 => $booking['building_id'],
				'building_name'		 => $booking['building_name'],
				'from_'				 => $booking['from_'],
				'to_'				 => $booking['to_'],
				'completed'			 => $booking['completed'],
				'reminder'			 => $booking['reminder'],
				'resources'			 => $booking['resources'],
				'dates'				 => $booking['dates']
			);
		}

		$allocations = $this->split_allocations($alloc_array, $bookings);

		$event_ids	 = $this->so->event_ids_for_organization($organization_id, $resource_ids, $from, $to);

		$_events	 = $this->event_so->read(array('filters' => array('id' => $event_ids),
			'results' => -1));

		$events = array();

		/**
		 * Whitelisting
		 */
		foreach ($_events['results'] as $event)
		{
			$events[] = array(
				'type'				 			=> 'event',
				'id'				 			=> $event['id'],
				'id_string'			 			=> $event['id_string'],
				'active'			 			=> $event['active'],
				'activity_id'		 			=> $event['activity_id'],
				'application_id'	 			=> $event['application_id'],
				'name'				 			=> $event['is_public'] ? $event['name'] : '',
				'homepage'			 			=> $event['homepage'],
				'description'					=> $event['is_public'] ? $event['description'] : '',
				'equipment'			 			=> $event['equipment'],
				'building_id'		 			=> $event['building_id'],
				'building_name'		 			=> $event['building_name'],
				'from_'				 			=> $event['from_'],
				'to_'				 			=> $event['to_'],
				'completed'			 			=> $event['completed'],
				'access_requested'	 			=> $event['access_requested'],
				'reminder'			 			=> $event['reminder'],
				'is_public'			 			=> $event['is_public'],
				'activity_name'		 			=> $event['activity_name'],
				'resources'			 			=> $event['resources'],
				'dates'				 			=> $event['dates'],
				'customer_organization_name'	=> $event['customer_organization_name']
			);
		}

		$bookings	 = array_merge($allocations, $bookings);

		if ($this->current_app() == 'bookingfrontend')
		{
			$bookings	 = $this->_remove_event_conflicts($bookings, $events);
		}
		else
		{
			$bookings	 = $this->_remove_event_conflicts($bookings, $events, $list_conflicts = true);
		}

		$bookings = array_merge($events, $bookings);

		$resources		 = $resources['results'];

		if (empty($resources))
		{
			foreach ($bookings as $booking)
			{
				$booking_res = $this->resource_so->read(array('filters'	 => array('building_id'	 => $booking['building_id'],
					'active' => 1), 'results'	 => -1))['results'];
				foreach ($booking_res as $resource)
				{
					if (!in_array($resource, $resources))
					{
						$resources[] = $resource;
					}
				}
			}
		}

		$sort = array();
		foreach ($resources as $key => $row)
		{
			$sort[$key] = $row['sort'];
		}

		// Sort the resources with sortkey ascending
		// Add $resources as the last parameter, to sort by the common key
		if (!empty($resources))
		{
			array_multisort($sort, SORT_ASC, $resources);
		}
		$bookings	 = $this->_split_multi_day_bookings($bookings, $from, $to);
		$results	 = build_organization_schedule_table($bookings, $resources);
//            exit;
		return array('total_records' => count($results), 'results' => $results);
	}

		function building_infoscreen_schedule( $building_id, $date, $res = false, $resource_id = false )
		{
			$from = clone $date;
			$from->setTime(0, 0, 0);
			// Make sure $from is a monday
			if ($from->format('w') != 1)
			{
				$from->modify('last monday');
			}
			$to = clone $from;
			$to->modify('+7 days');
			$to->modify('-1 minute');

			$resources = '';
			if ($res != False)
			{
				$resources	 = $this->so->get_screen_resources($building_id, $res);
				if (count($resources) > 0)
				{
					$resources	 = "AND bb_resource.id IN (" . implode(",", $resources) . ")";
				}
			}

			if($resource_id)
			{
				if(is_array($resource_id))
				{
					$resource_ids = $resource_id;
				}
				else
				{
					$resource_ids = array($resource_id);
				}
				$resources	 .= "AND bb_resource.id IN (" . implode(",", $resource_ids) . ")";
			}

			$allocations = $this->so->get_screen_allocation($building_id, $from, $to, $resources);
			$bookings	 = $this->so->get_screen_booking($building_id, $from, $to, $resources);
			$events		 = $this->so->get_screen_event($building_id, $from, $to, $resources);

			$results = array();

			foreach ($allocations as &$allocation)
			{
				$allocation['name']		 = $allocation['organization_name'];
				$allocation['shortname'] = $allocation['organization_shortname'];
				$allocation['type']		 = 'allocation';

				$datef					 = strtotime($allocation['from_']);
				$allocation['weekday']	 = date('D', $datef);
			}

			foreach ($bookings as &$booking)
			{
				$booking['name']		 = $booking['group_name'];
				$booking['shortname']	 = $booking['group_shortname'];
				$booking['type']		 = 'booking';

				$datef				 = strtotime($booking['from_']);
				$booking['weekday']	 = date('D', $datef);
			}

			foreach ($events as &$event)
			{
				$_name = $event['name'] === 'dummy' ? $event['activity_name'] : $event['name'];
				$event['name']		 = substr($_name, 0, 34);
				$event['shortname']	 = substr($_name, 0, 12);
				$event['type']		 = 'event';
				$datef				 = strtotime($event['from_']);
				$event['weekday']	 = date('D', $datef);
			}

			$allocations = $this->split_allocations2($allocations, $bookings);
			$bookings	 = array_merge($allocations, $bookings);
			$bookings	 = $this->_remove_event_conflicts2($bookings, $events);
			$bookings	 = array_merge($bookings, $events);
			$bookings	 = $this->_split_multi_day_bookings2($bookings, $from, $to);

			foreach ($bookings as &$allocation)
			{
				$datef	 = strtotime($allocation['from_']);
				$datet	 = strtotime($allocation['to_']);
				$timef	 = date('H:i:s', $datef);
				$timet	 = date('H:i:s', $datet);
				$weekday = $allocation['weekday'];
				$resname = $allocation['resource_name'];
				$ft		 = $timef;
				$from	 = explode(':', $timef);
				$to		 = explode(':', $timet);
				$from	 = $from[0] * 60 + $from[1];
				$to		 = $to[0] * 60 + $to[1];
				if ($to == 0)
				{
					$to		 = 24 * 60;
				}
				$colspan = ($to - $from) / 30;

				$allocation['colspan']				 = $colspan;
				$results[$weekday][$resname][$ft]	 = $allocation;
			}

			foreach ($results as &$day)
			{
				foreach ($day as &$res)
				{
					ksort($res);
				}
			}

			return array('total_records' => count($results), 'results' => $results);
		}

		function split_allocations2( $allocations, $all_bookings )
		{

			function get_from2( $a )
			{
				return $a['from_'];
			}
			;

			function get_to2( $a )
			{
				return $a['to_'];
			}
			;
			$new_allocations = array();
			foreach ($allocations as $allocation)
			{
				// $ Find all associated bookings
				$bookings = array();

				foreach ($all_bookings as $b)
				{
					if ($b['allocation_id'] == $allocation['id'])
					{
						$bookings[] = $b;
					}
				}
				$times = array($allocation['from_'], $allocation['to_']);

				$times	 = array_merge(array_map("get_from2", $bookings), $times);
				$times	 = array_merge(array_map("get_to2", $bookings), $times);
				$times	 = array_unique($times);
				sort($times);
				while (count($times) >= 2)
				{
					$from_		 = $times[0];
					$to_		 = $times[1];
					$resources	 = array($allocation['resource_id']);
					foreach ($all_bookings as $b)
					{

						if (($b['from_'] >= $from_ && $b['from_'] < $to_) || ($b['to_'] > $from_ && $b['to_'] <= $to_) || ($b['from_'] <= $from_ && $b['to_'] >= $to_))
						{
							$resources = array_minus($resources, array($b['resource_id']));
						}
					}
					if ($resources)
					{
						$a					 = $allocation;
						$a['from_']			 = $times[0];
						$a['to_']			 = $times[1];
						$new_allocations[]	 = $a;
					}
					array_shift($times);
				}
			}
			return $new_allocations;
		}

		function _remove_event_conflicts2( $bookings, &$events )
		{
			$new_bookings = array();
			foreach ($bookings as $b)
			{
				$keep = true;
				foreach ($events as &$e)
				{
					if ((($b['from_'] >= $e['from_'] && $b['from_'] < $e['to_']) ||
						($b['to_'] > $e['from_'] && $b['to_'] <= $e['to_']) ||
						($b['from_'] <= $e['from_'] && $b['to_'] >= $e['to_'])) && ( $b['resource_id'] == $e['resource_id']))
					{
						$keep = false;
						break;
					}
				}
				if ($keep)
				{
					$new_bookings[] = $b;
				}
			}
			return $new_bookings;
		}

		function _split_multi_day_bookings2( $bookings, $t0, $t1 )
		{
			if ($t1->format('H:i') == '00:00')
				$t1->modify('-1 day');
			$new_bookings = array();
			foreach ($bookings as $booking)
			{
				$from	 = new DateTime($booking['from_']);
				$to		 = new DateTime($booking['to_']);
				// Basic one-day booking
				if ($from->format('Y-m-d') == $to->format('Y-m-d'))
				{
					$booking['date']	 = $from->format('Y-m-d');
					$booking['weekday']	 = date_format(date_create($booking['date']), 'D');
					$booking['from_']	 = $from->format('H:i');
					$booking['to_']		 = $to->format('H:i');
					// We need to use 23:59 instead of 00:00 to sort correctly
					$booking['to_']		 = $booking['to_'] == '00:00' ? '23:59' : $booking['to_'];
					$new_bookings[]		 = $booking;
				}
				// Multi-day booking
				else
				{
					$start	 = clone max($from, $t0);
					$end	 = clone min($to, $t1);
					$date	 = clone $start;
					do
					{
						$new_booking			 = $booking;
						$new_booking['date']	 = $date->format('Y-m-d');
						$new_booking['weekday']	 = date_format($date, 'D');
						$new_booking['from_']	 = '00:00';
						$new_booking['to_']		 = '00:00';
						if ($new_booking['date'] == $from->format('Y-m-d'))
						{
							$new_booking['from_'] = $from->format('H:i');
						}
						else if ($new_booking['date'] == $to->format('Y-m-d'))
						{
							$new_booking['to_'] = $to->format('H:i');
						}
						// We need to use 23:59 instead of 00:00 to sort correctly
						$new_booking['to_']	 = $new_booking['to_'] == '00:00' ? '23:59' : $new_booking['to_'];
						$new_bookings[]		 = $new_booking;

						if ($date->format('Y-m-d') == $end->format('Y-m-d'))
						{
							break;
						}

						//		if($date->getTimestamp() > $end->getTimestamp()) // > php 5.3.0
						if ($date->format("U") > $end->format("U"))
						{
							throw new InvalidArgumentException('start time( ' . $date->format('Y-m-d') . ' ) later than end time( ' . $end->format('Y-m-d') . " ) for {$booking['type']}#{$booking['id']}::{$booking['name']}");
						}

						$date->modify('+1 day');
					}
					while (true);
				}
			}
			return $new_bookings;
		}

		function building_extraschedule( $building_id, $date )
		{
			$config = CreateObject('phpgwapi.config', 'booking');
			$config->read();

			$from = clone $date;
			$from->setTime(0, 0, 0);
			// Make sure $from is a monday
			if ($from->format('w') != 1)
			{
				$from->modify('last monday');
			}
			$to				 = clone $from;
			$to->modify('+7 days');
			$allocation_ids	 = $this->so->allocation_ids_for_building($building_id, $from, $to);

			$orgids = explode(",", $config->config_data['extra_schedule_ids']);

			$allocations = $this->allocation_so->read(array('filters'	 => array('id'				 => $allocation_ids,
					'organization_id'	 => $orgids), 'sort'		 => 'from_', 'results'	 => -1));
			$allocations = $allocations['results'];
			foreach ($allocations as &$allocation)
			{
				$allocation['name']		 = $allocation['organization_name'];
				$allocation['shortname'] = $allocation['organization_shortname'];
				$allocation['type']		 = 'allocation';
				unset($allocation['costs']);
				unset($allocation['comments']);
				unset($allocation['secret']);
				unset($allocation['customer_ssn']);
				unset($allocation['organizer']);
				unset($allocation['contact_name']);
				unset($allocation['contact_email']);
				unset($allocation['contact_phone']);
				unset($allocation['cost']);
				unset($allocation['sms_total']);
				unset($allocation['customer_organization_name']);
				unset($allocation['customer_organization_id']);
				unset($allocation['customer_identifier_type']);
				unset($allocation['customer_organization_number']);
				unset($allocation['customer_internal']);
				unset($allocation['include_in_list']);
				unset($allocation['agegroups']);
				unset($allocation['audience']);
			}

			$booking_ids = $this->so->booking_ids_for_building($building_id, $from, $to);
			$bookings	 = $this->so->read(array('filters' => array('id' => $booking_ids), 'sort' => 'from_',
				'results' => -1));
			$bookings	 = $bookings['results'];
			foreach ($bookings as &$booking)
			{
				$booking['name']		 = $booking['group_name'];
				$booking['shortname']	 = $booking['group_shortname'];
				$booking['type']		 = 'booking';
				unset($booking['costs']);
				unset($booking['comments']);
				unset($booking['secret']);
				unset($booking['customer_ssn']);
				unset($booking['organizer']);
				unset($booking['contact_name']);
				unset($booking['contact_email']);
				unset($booking['contact_phone']);
				unset($booking['cost']);
				unset($booking['sms_total']);
				unset($booking['customer_organization_name']);
				unset($booking['customer_organization_id']);
				unset($booking['customer_identifier_type']);
				unset($booking['customer_organization_number']);
				unset($booking['customer_internal']);
				unset($booking['include_in_list']);
				unset($booking['agegroups']);
				unset($booking['audience']);
			}

			$allocations = $this->split_allocations($allocations, $bookings);

			$event_ids	 = $this->so->event_ids_for_building($building_id, $from, $to);
			$events		 = $this->event_so->read(array('filters'	 => array('id' => $event_ids),
				'sort'		 => 'from_', 'results'	 => -1));
			$events		 = $events['results'];
			foreach ($events as &$event)
			{

				$event['name']	 = $event['description'];
				$event['type']	 = 'event';
				unset($event['costs']);
				unset($event['comments']);
				unset($event['secret']);
				unset($event['customer_ssn']);
				unset($event['organizer']);
				unset($event['contact_name']);
				unset($event['contact_email']);
				unset($event['contact_phone']);
				unset($event['cost']);
				unset($event['sms_total']);
				unset($event['customer_organization_name']);
				unset($event['customer_organization_id']);
				unset($event['customer_identifier_type']);
				unset($event['customer_organization_number']);
				unset($event['customer_internal']);
				unset($event['include_in_list']);
				unset($event['agegroups']);
				unset($event['audience']);
			}

			$bookings	 = array_merge($allocations, $bookings);
			$bookings	 = $this->_remove_event_conflicts($bookings, $events);

			$resource_ids	 = $this->so->resource_ids_for_bookings($booking_ids);
			$resource_ids	 = array_merge($resource_ids, $this->so->resource_ids_for_allocations($allocation_ids));
			$resource_ids	 = array_merge($resource_ids, $this->so->resource_ids_for_events($event_ids));
			$resources		 = $this->resource_so->read(array('filters' => array('id'		 => $resource_ids,
					'active'	 => 1, 'results'	 => -1)));
			$resources		 = $resources['results'];

			$sort = array();
			foreach ($resources as $key => $row)
			{
				$sort[$key] = $row['sort'];
			}

			// Sort the resources with sortkey ascending
			// Add $resources as the last parameter, to sort by the common key
			array_multisort($sort, SORT_ASC, $resources);
			$bookings	 = $this->_split_multi_day_bookings($bookings, $from, $to);
			$results	 = build_schedule_table($bookings, $resources);
			return array('total_records' => count($results), 'results' => $results);
		}

		function get_free_events( $building_id, $resource_id, $start_date, $end_date, $weekdays, $stop_on_end_date=false, $all_simple_bookings=false, $detailed_overlap=false )
		{

			$timezone	 = !empty($this->userSettings['preferences']['common']['timezone']) ? $this->userSettings['preferences']['common']['timezone'] : 'UTC';

			try
			{
				$DateTimeZone	 = new DateTimeZone($timezone);
			}
			catch (Exception $ex)
			{
				throw $ex;
			}

			$_from	 = clone $start_date;
			$_from->setTime(0, 0, 0);
			$_to		 = clone $end_date;
			$_to->setTime(23, 59, 59);

			if ($all_simple_bookings)
			{
				$resource_filters = array(
					'active'			 => 1,
					'rescategory_active' => 1,
					'simple_booking'	 => 1
				);
			}
			else if ($resource_id)
			{
				$resource_filters = array(
					'active'			 => 1,
					'rescategory_active' => 1,
					'id'				 => $resource_id
				);
			}
			else
			{
				$resource_filters = array(
					'active'			 => 1,
					'rescategory_active' => 1,
					'building_id'		 => $building_id ? $building_id : -1
				);
			}

			$resources	= $this->resource_so->read(array('filters' => $resource_filters,
				'sort' => 'sort', 'results' => -1));

			$resource_ids = array();
			$event_ids = array();
			$allocation_ids = array();
			$booking_ids = array();

			foreach ($resources['results'] as &$resource)
			{
				$resource_ids[] = $resource['id'];
				$from = clone $_from;

				if($resource['simple_booking_start_date'])
				{
					$simple_booking_start_date = new DateTime(date('Y-m-d H:i', $resource['simple_booking_start_date']), $DateTimeZone);

					$now = new DateTime('now',$DateTimeZone);

					if($simple_booking_start_date > $now)
					{
						$resource['skip_timeslot'] = true;
					}

					if($simple_booking_start_date > $_from)
					{
						$from = clone $simple_booking_start_date;
					}
					else
					{
						$from->setTime($simple_booking_start_date->format('H'), $simple_booking_start_date->format('i'), 0);
					}
				}

				$to = clone $_to;

				if($resource['booking_day_horizon'])
				{
					if(!$resource['booking_month_horizon'])
					{
						$__to = clone $from;
					}
					else
					{
						$__to = clone $to;
					}
					$__to->modify("+{$resource['booking_day_horizon']} days");
					$to = clone $__to;
				}

				if($resource['booking_month_horizon'])
				{
//					$test = $from->format('Y-m-d');
					$__to = $this->month_shifter($from, $resource['booking_month_horizon'], $DateTimeZone);

//					$test = $__to->format('Y-m-d');
//					if($__to > $_to)
					{
						$to = clone $__to;
					}
//					$test = $to->format('Y-m-d');
					$to->setTime(23, 59, 59);
				}

				if($resource['simple_booking_end_date'])
				{
					$simple_booking_end_date = new DateTime(date('Y-m-d', $resource['simple_booking_end_date']));
					$simple_booking_end_date->setTimezone($DateTimeZone);

					if($simple_booking_end_date < $to)
					{
						$to = clone $simple_booking_end_date;
					}
					$to->setTime(23, 59, 59);
				}


				if ($resource['simple_booking'] && empty($resource['skip_timeslot']))
				{
					$event_ids = array_merge($event_ids, $this->so->event_ids_for_resource($resource['id'], $_from, $to));
					$allocation_ids	 = array_merge($allocation_ids, $this->so->allocation_ids_for_resource($resource_id, $from, $to));
					$booking_ids	 = array_merge($booking_ids, $this->so->booking_ids_for_resource($resource_id, $from, $to));
				}

				$resource['from'] = $from;
				if($resource['booking_time_default_end'] > -1)
				{
					$to->setTime($resource['booking_time_default_end'], 0, 0);
				}

				$resource['to'] = $to;
			}
			unset($resource);

			$events = array();
			if ($event_ids)
			{
				$events = $this->event_so->read(array('filters' => array('id' => $event_ids),
					'results' => -1));
			}
			if ($allocation_ids)
			{
				$allocations = $this->allocation_so->read(array('filters' => array('id' => $allocation_ids),
					'results' => -1));
			}
			if ($booking_ids)
			{
				$bookings = $this->so->read(array('filters' => array('id' => $booking_ids),
					'results' => -1));
			}

			/**
			 * Check for temporary reserved
			 */
			$this->get_partials( $events, $resource_ids);

			/**
			 * Combine variants of bookings
			 */
			$events['results'] = array_merge((array)$events['results'],(array)$allocations['results'],(array)$bookings['results']);

			$availlableTimeSlots		 = array();
			$defaultStartHour			 = 8;
			$defaultStartMinute			 = 0;
			$defaultStartHour_fallback	 = 8;
			$defaultEndHour				 = 23;
			$defaultEndHour_fallback	 = 23;

			$days = array(
				0	 => "Sunday",
				1	 => "Monday",
				2	 => "Tuesday",
				3	 => "Wednesday",
				4	 => "Thursday",
				5	 => "Friday",
				6	 => "Saturday",
				7	 => "Sunday",
			);


			$dateformat = $this->userSettings['preferences']['common']['dateformat'];
			$datetimeformat = "{$dateformat} H:i";

			$soseason = CreateObject('booking.soseason');

			foreach ($resources['results'] as $resource)
			{
				if(!empty($resource['skip_timeslot']))
				{
					continue;
				}

				$availlableTimeSlots[$resource['id']] = [];

				if ($resource['simple_booking'] && $resource['simple_booking_start_date'])
				{
					$dow_start		 = $resource['booking_dow_default_start'];
					$booking_lenght	 = $resource['booking_day_default_lenght'];
					$booking_start	 = $resource['booking_time_default_start'];
					$booking_end	 = $resource['booking_time_default_end'];
					$booking_time_minutes	 = $resource['booking_time_minutes'] > 0 ? $resource['booking_time_minutes'] : 60;


					/**
					 * Make sure start is before end
					 */
					if ($booking_lenght == -1 || $booking_lenght == 0)
					{
						if ($resource['booking_time_default_start'] > -1)
						{
							$booking_start = min(array($resource['booking_time_default_start'], $resource['booking_time_default_end']));
						}

						if ($resource['booking_time_default_end'] > -1)
						{
							$booking_end = max(array($resource['booking_time_default_start'], $resource['booking_time_default_end']));
						}
					}


					if ($booking_start > -1)
					{
						$defaultStartHour			 = $booking_start;
						$defaultStartHour_fallback	 = $booking_start;
					}
					if ($booking_end > -1)
					{
						$defaultEndHour			 = $booking_end;
						$defaultEndHour_fallback = $booking_end;
					}

					if ($booking_lenght == -1)
					{
						$defaultEndHour--;
					}

					$checkDate = clone $resource['from'];
					$checkDate->setTimezone($DateTimeZone);
					$checkDate->setTime($defaultStartHour, 0, 0);

//					$limitDate = clone ($to);
					$limitDate = clone ($resource['to']);
					$limitDate->setTimezone($DateTimeZone);

					$test	 = $limitDate->format('Y-m-d');
					$test	 = $checkDate->format('Y-m-d');
					if ($stop_on_end_date)
					{
						$limitDate = clone $_to;
					}

					$active_seasons = $soseason->get_resource_seasons($resource['id'], $checkDate->format('Y-m-d'), $limitDate->format('Y-m-d'));

					do
					{
						$StartTime = clone ($checkDate);
						if ($defaultStartHour > $defaultEndHour && ($booking_lenght > -1 || $resource['booking_time_default_end'] == -1))
						{
							$defaultStartHour = $defaultStartHour_fallback;
						}

						if ($StartTime->format('H') > $defaultEndHour)
						{
							$StartTime->modify("+1 days");
							$defaultStartHour = $defaultStartHour_fallback;
						}

						if ($dow_start > -1)
						{
							$current_dow = $StartTime->format('w');
							if ($dow_start != $current_dow || ($dow_start == 7 && $current_dow == 0 ))
							{
								$modyfier = "next " . $days[$dow_start];
								$StartTime->modify($modyfier);
							}
						}

//						$StartTime->setTime($defaultStartHour, 0, 0);
						$StartTime->setTime($defaultStartHour, $defaultStartMinute, 0);

						$endTime = clone ($StartTime);

						if ($booking_lenght > -1)
						{
							$endTime->modify("+{$booking_lenght} days");
						}

						if ($booking_end > -1 && $booking_lenght > -1)
						{
							$endTime->setTime($booking_end, 0, 0);
						}
						else if($booking_end > -1 && !$booking_lenght > -1)
						{
							$test = $endTime->format('i');
//							$endTime->setTime(min($booking_end, $StartTime->format('H')) + 1, 0, 0);
							$endTime->setTime(min($booking_end, $StartTime->format('H')), (int)$endTime->format('i') + $booking_time_minutes, 0);
						}
						else
						{
							$endTime->setTime($StartTime->format('H'), (int)$endTime->format('i') + $booking_time_minutes, 0);
						}

						$checkDate = clone ($endTime);

						$within_season = false;

						/**
						 * Expensive
						 */
						foreach ($active_seasons as $season_id)
						{
							$within_season = $soseason->timespan_within_season($season_id, $StartTime, $endTime);
							if($within_season)
							{
								break;
							}
						}

						$DateTimeZone_utc	 = new DateTimeZone('UTC');

						// Transported to the client to be handled by javascript
						$from_utc = clone $StartTime;
						$to_utc = clone $endTime;
						$from_utc->setTimezone($DateTimeZone_utc);
						$to_utc->setTimezone($DateTimeZone_utc);


						if(!empty($simple_booking_start_date))
						{
							$_simple_booking_start_date = new DateTime(date('Y-m-d H:i', $resource['simple_booking_start_date']));
							$now = new DateTime();
							$now->setTimezone($DateTimeZone);

							if($limitDate->format('Y-m-d') == $checkDate->format('Y-m-d')
								&& $now->format('H') < $_simple_booking_start_date->format('H')
							)
							{
								$within_season = false;
							}
						}
						if($within_season)
						{
							$overlap_result = $this->check_if_resurce_is_taken($resource, $StartTime, $endTime, $events);
							
							// Handle both old and new format return values
							$overlap_status = is_array($overlap_result) ? $overlap_result['status'] : $overlap_result;
							$overlap_reason = is_array($overlap_result) ? $overlap_result['reason'] : null;
							$overlap_type = is_array($overlap_result) ? $overlap_result['type'] : null;
							$overlap_event = is_array($overlap_result) ? $overlap_result['event'] : null;
							
							// Create the base timeslot structure
							$timeslot = [
								'when'				 => $StartTime->format($datetimeformat) . ' - ' . $endTime->format($datetimeformat),
								'start'				 => $StartTime->getTimestamp() . '000',
								'end'				 => $endTime->getTimestamp() . '000',
								'overlap'			 => $overlap_status,
                                'start_iso'          => $StartTime->format('c'),
                                'end_iso'            => $endTime->format('c')
							];
							
							// Add detailed overlap information or applicationLink based on detailed_overlap parameter
							if ($detailed_overlap) {
								// Add the resource_id when using detailed_overlap
								$timeslot['resource_id'] = $resource['id'];
								
								// Add the detailed overlap information if it's available
								if ($overlap_reason) {
									$timeslot['overlap_reason'] = $overlap_reason;
								}
								if ($overlap_type) {
									$timeslot['overlap_type'] = $overlap_type;
								}
								if ($overlap_event) {
									$timeslot['overlap_event'] = $overlap_event;
								}
							} else {
								// Only add applicationLink when detailed_overlap is false
								$timeslot['applicationLink'] = [
									'menuaction'	 => 'bookingfrontend.uiapplication.add',
									'resource_id'	 => $resource['id'],
									'building_id'	 => $building_id,
									'from_[]'		 => $from_utc->format('Y-m-d H:i:s'),
									'to_[]'			 => $to_utc->format('Y-m-d H:i:s'),
									'simple'		 => true
								];
							}
							
							$availlableTimeSlots[$resource['id']][] = $timeslot;
						}

						if ($booking_lenght == -1 || $resource['booking_time_default_end'] == -1)
						{
							$defaultStartHour = $endTime->format('H');
							$defaultStartMinute = (int)$endTime->format('i');

							if($defaultStartHour > $defaultEndHour_fallback)
							{
								$defaultStartHour = $defaultStartHour_fallback;
							}
						}
					}
					while ($checkDate < $limitDate);
				}
			}

			return $availlableTimeSlots;
		}

		private function get_partials(& $events, $resource_ids)
		{
			$sessions = \App\modules\phpgwapi\security\Sessions::getInstance();
			$session_id = $sessions->get_session_id();
			if (!empty($session_id))
			{
				$filters = array('status' => 'NEWPARTIAL1', 'session_id' => $session_id);
				$params = array('filters' => $filters, 'results' =>'all');
				$applications = CreateObject('booking.soapplication')->read($params);

				if($applications['results'])
				{
					foreach ($applications['results'] as & $application)
					{
						$application['from_'] = $application['dates'][0]['from_'];
						$application['to_'] = $application['dates'][0]['to_'];
					}

					$events['results'] = array_merge((array)$events['results'],$applications['results']);
				}
			}

			$filters = array('active' => 1, 'resource_id' => $resource_ids);
			$params = array('filters' => $filters, 'results' =>'all');
			$blocks = CreateObject('booking.soblock')->read($params);

			if($blocks['results'])
			{
				foreach ($blocks['results'] as & $block)
				{
					if($block['session_id'] === $session_id)
					{
						continue;
					}
					$block['resources'] = array( $block['resource_id']);
					$block['type'] = 'block';
					$events['results'][] = $block;
				}
			}
		}

		function check_if_resurce_is_taken( $resource, $StartTime, $endTime, $events)
		{
			$timezone		 = $this->userSettings['preferences']['common']['timezone'];
			$DateTimeZone	 = new DateTimeZone($timezone);
			$overlap = false;
			$overlap_reason = null;
			$overlap_event = null;
			$overlap_type = null;

			$resource_id = $resource['id'];
			$booking_buffer_deadline = $resource['booking_buffer_deadline'];

			$now = new DateTime("now", $DateTimeZone);

			if($booking_buffer_deadline)
			{
				$now->modify($booking_buffer_deadline. ' Minute');
			}

			if ($StartTime <= $now)
			{
				$overlap_reason = 'time_in_past';
				$overlap_type = 'disabled';
				return ['status' => 3, 'reason' => $overlap_reason, 'type' => $overlap_type]; // disabled
			}

			foreach ($events['results'] as $event)
			{
				if (in_array($resource_id, $event['resources']))
				{
					$event_start = new DateTime($event['from_'], $DateTimeZone);
					$event_end	 = new DateTime($event['to_'], $DateTimeZone);
					
					// Check for exact match or full coverage (event has identical time boundaries or completely covers the requested slot)
					if (($event_start <= $StartTime AND $event_end >= $endTime) ||
						($event_start->format('Y-m-d H:i:s') === $StartTime->format('Y-m-d H:i:s') AND 
						 $event_end->format('Y-m-d H:i:s') === $endTime->format('Y-m-d H:i:s')))
					{
						$overlap_reason = 'complete_overlap';
						$overlap_type = 'complete';
						$overlap_event = [
							'id' => isset($event['id']) ? $event['id'] : null,
							'type' => $event['type'],
							'status' => isset($event['status']) ? $event['status'] : null,
							'from' => $event_start->format('Y-m-d H:i:s'),
							'to' => $event_end->format('Y-m-d H:i:s')
						];
						$overlap = ($event['type'] == 'block' ||  $event['status'] == 'NEWPARTIAL1') ? 2 : 1;
						break;
					}
					// Check for complete containment (existing event is inside the requested time)
					else if ($event_start > $StartTime AND $event_end < $endTime) 
					{
						$overlap_reason = 'complete_containment';
						$overlap_type = 'complete';
						$overlap_event = [
							'id' => isset($event['id']) ? $event['id'] : null,
							'type' => $event['type'],
							'status' => isset($event['status']) ? $event['status'] : null,
							'from' => $event_start->format('Y-m-d H:i:s'),
							'to' => $event_end->format('Y-m-d H:i:s')
						];
						$overlap = ($event['type'] == 'block' ||  $event['status'] == 'NEWPARTIAL1') ? 2 : 1;
						break;
					}
					// Check for start overlap (existing event starts before/at and ends after start time but before end time)
					else if ($event_start <= $StartTime AND $event_end > $StartTime AND $event_end < $endTime) 
					{
						$overlap_reason = 'start_overlap';
						$overlap_type = 'partial';
						$overlap_event = [
							'id' => isset($event['id']) ? $event['id'] : null,
							'type' => $event['type'],
							'status' => isset($event['status']) ? $event['status'] : null,
							'from' => $event_start->format('Y-m-d H:i:s'),
							'to' => $event_end->format('Y-m-d H:i:s')
						];
						$overlap = ($event['type'] == 'block' ||  $event['status'] == 'NEWPARTIAL1') ? 2 : 1;
						break;
					}
					// Check for end overlap (existing event starts after start time but before end time and ends at/after end time)
					else if ($event_start > $StartTime AND $event_start < $endTime AND $event_end >= $endTime) 
					{
						$overlap_reason = 'end_overlap';
						$overlap_type = 'partial';
						$overlap_event = [
							'id' => isset($event['id']) ? $event['id'] : null,
							'type' => $event['type'],
							'status' => isset($event['status']) ? $event['status'] : null,
							'from' => $event_start->format('Y-m-d H:i:s'),
							'to' => $event_end->format('Y-m-d H:i:s')
						];
						$overlap = ($event['type'] == 'block' ||  $event['status'] == 'NEWPARTIAL1') ? 2 : 1;
						break;
					}
				}
			}
			
			if ($overlap) {
				return [
					'status' => $overlap, 
					'reason' => $overlap_reason, 
					'type' => $overlap_type,
					'event' => $overlap_event
				];
			}
			
			return $overlap;
		}

		function month_shifter( DateTime $aDate, $months, $DateTimeZone )
		{

			$now = new DateTime('now', $DateTimeZone);

			/**
			 * Fake transition
			 */
//			$now = new DateTime('2023-05-31 23:59', $DateTimeZone);

			/**
			 * wait for desired time within day
			 */
			$start_of_month = clone($aDate);
			$start_of_month->modify('first day of this month');

			if($start_of_month > $now && $months > 0)
			{
				$months -=1;
			}
			$check_limit = clone($aDate);
			$check_limit->setTime(23, 59, 59);
			$check_limit->modify('last day of this month');
			if($check_limit > $now && $months > 0)
			{
				$months -=1;
			}
			$dateA		 = clone($aDate);
			$dateB		 = clone($aDate);
			$plusMonths	 = clone($dateA->modify($months . ' Month'));
			//check whether reversing the month addition gives us the original day back
			if ($dateB != $dateA->modify($months * -1 . ' Month'))
			{
				$result = $plusMonths->modify('last day of last month');
			}
			else if ($aDate == $dateB->modify('last day of this month'))
			{
				$result = $plusMonths->modify('last day of this month');
			}
			else
			{
				$result = $plusMonths->modify('last day of this month');
			}
			$result->setTime(23, 59, 59);
			return $result;
		}

		/**
		 * Return a resource's schedule for a given week in a YUI DataSource
		 * compatible format
		 *
		 * @param int	$resource_id
		 * @param $date
		 *
		 * @return array containg values from $array for the keys in $keys.
		 */
		function resource_schedule( $resource_id, $date )
		{
			$from = clone $date;
			$from->setTime(0, 0, 0);
			// Make sure $from is a monday
			if ($from->format('w') != 1)
			{
				$from->modify('last monday');
			}
			$to			 = clone $from;
			$to->modify("+7 days");
			$resource	 = $this->resource_so->read_single($resource_id);

			$allocation_ids	 = $this->so->allocation_ids_for_resource($resource_id, $from, $to);
			$allocations	 = $this->allocation_so->read(array('filters' => array('id' => $allocation_ids),
				'results' => -1));
			$allocations	 = $allocations['results'];
			foreach ($allocations as &$allocation)
			{
				$allocation['name']		 = $allocation['organization_name'];
				$allocation['shortname'] = $allocation['organization_shortname'];
				$allocation['type']		 = 'allocation';
				unset($allocation['costs']);
				unset($allocation['comments']);
				unset($allocation['secret']);
				unset($allocation['customer_ssn']);
				unset($allocation['organizer']);
				unset($allocation['contact_name']);
				unset($allocation['contact_email']);
				unset($allocation['contact_phone']);
				unset($allocation['cost']);
				unset($allocation['sms_total']);
				unset($allocation['customer_organization_name']);
				unset($allocation['customer_organization_id']);
				unset($allocation['customer_identifier_type']);
				unset($allocation['customer_organization_number']);
				unset($allocation['customer_internal']);
				unset($allocation['include_in_list']);
				unset($allocation['agegroups']);
				unset($allocation['audience']);
			}
			$booking_ids = $this->so->booking_ids_for_resource($resource_id, $from, $to);
			$bookings	 = $this->so->read(array('filters' => array('id' => $booking_ids), 'results' => -1));
			$bookings	 = $bookings['results'];
			foreach ($bookings as &$booking)
			{
				$booking['name']		 = $booking['group_name'];
				$booking['shortname']	 = $booking['group_shortname'];
				$booking['type']		 = 'booking';
				unset($booking['costs']);
				unset($booking['comments']);
				unset($booking['secret']);
				unset($booking['customer_ssn']);
				unset($booking['organizer']);
				unset($booking['contact_name']);
				unset($booking['contact_email']);
				unset($booking['contact_phone']);
				unset($booking['cost']);
				unset($booking['sms_total']);
				unset($booking['customer_organization_name']);
				unset($booking['customer_organization_id']);
				unset($booking['customer_identifier_type']);
				unset($booking['customer_organization_number']);
				unset($booking['customer_internal']);
				unset($booking['include_in_list']);
				unset($booking['agegroups']);
				unset($booking['audience']);
			}

			$allocations = $this->split_allocations($allocations, $bookings);

			$event_ids	 = $this->so->event_ids_for_resource($resource_id, $from, $to);
			$_events	 = $this->event_so->read(array('filters' => array('id' => $event_ids),
				'results' => -1));
			$events		 = array();

			/**
			 * Whitelisting
			 */
			foreach ($_events['results'] as $event)
			{
				$events[] = array(
					'type'				 => 'event',
					'name'				 => $event['name'],
					'id'				 => $event['id'],
					'id_string'			 => $event['id_string'],
					'active'			 => $event['active'],
					'activity_id'		 => $event['activity_id'],
					'application_id'	 => $event['application_id'],
					'name'				 => $event['is_public'] ? $event['name'] : '',
					'homepage'			 => $event['homepage'],
					'description'		 => $event['is_public'] ? $event['description'] : '',
					'equipment'			 => $event['equipment'],
					'building_id'		 => $event['building_id'],
					'building_name'		 => $event['building_name'],
					'from_'				 => $event['from_'],
					'to_'				 => $event['to_'],
					'completed'			 => $event['completed'],
					'access_requested'	 => $event['access_requested'],
					'reminder'			 => $event['reminder'],
					'is_public'			 => $event['is_public'],
					'activity_name'		 => $event['activity_name'],
					'resources'			 => $event['resources'],
					'dates'				 => $event['dates']
				);
			}
//			_debug_array($events);
			$bookings	 = array_merge($allocations, $bookings);

			if ($this->current_app() == 'bookingfrontend')
			{
				$bookings	 = $this->_remove_event_conflicts($bookings, $events);
			}
			else
			{
				$bookings	 = $this->_remove_event_conflicts($bookings, $events);
			}

			$bookings	 = array_merge($events, $bookings);

			$bookings	 = $this->_split_multi_day_bookings($bookings, $from, $to);
			$results	 = build_schedule_table($bookings, array($resource));
			return array('total_records' => count($results), 'results' => $results);
		}

		/**
		 * Split allocations overlapped by bookings into multiple allocations
		 * to avoid overlaps
		 */
		function split_allocations( $allocations, $all_bookings )
		{

			if (!function_exists('get_from2'))
			{

				function get_from2( $a )
				{
					return $a['from_'];
				}
			}
			;

			if (!function_exists('get_to2'))
			{

				function get_to2( $a )
				{
					return $a['to_'];
				}
			}
			;
			$new_allocations = array();
			foreach ($allocations as $allocation)
			{
				// $ Find all associated bookings
				$bookings = array();
				foreach ($all_bookings as $b)
				{
					if ($b['allocation_id'] == $allocation['id'])
					{
						$bookings[] = $b;
					}
				}
				$times	 = array($allocation['from_'], $allocation['to_']);
				$times	 = array_merge(array_map("get_from2", $bookings), $times);
				$times	 = array_merge(array_map("get_to2", $bookings), $times);
				$times	 = array_unique($times);
				sort($times);
				while (count($times) >= 2)
				{
					$from_	 = $times[0];
					$to_	 = $times[1];
					foreach ($all_bookings as $b)
					{
						$found = false;

						//Sigurd: altered 20181104
						//if(($b['from_'] >= $from_ && $b['from_'] <= $to_)
						if (($b['from_'] > $from_ && $b['from_'] < $to_) || ($b['to_'] > $from_ && $b['to_'] < $to_) || ($b['from_'] <= $from_ && $b['to_'] >= $to_))
						{
							$found = true;
						}
						if (!$found)
						{
							$a					 = $allocation;
							$a['from_']			 = $from_;
							$a['to_']			 = $to_;
							$new_allocations[]	 = $a;
						}
					}
					if (!$all_bookings)
					{
						$a					 = $allocation;
						$a['from_']			 = $from_;
						$a['to_']			 = $to_;
						$new_allocations[]	 = $a;
					}
					array_shift($times);
				}
			}

			return $new_allocations;
		}

		/**
		 * Split Multi-day bookings into separate single-day bookings
		 * */
		function _split_multi_day_bookings( $bookings, $t0, $t1 )
		{
			if ($t1->format('H:i') == '00:00')
			{
				$t1->modify('-1 day');
			}
			$new_bookings = array();
			foreach ($bookings as $booking)
			{
				$from	 = new DateTime($booking['from_']);
				$to		 = new DateTime($booking['to_']);
				// Basic one-day booking
				if ($from->format('Y-m-d') == $to->format('Y-m-d'))
				{
					$booking['date']	 = $from->format('Y-m-d');
					$booking['wday']	 = date_format(date_create($booking['date']), 'D');
					$booking['week']	 = date_format(date_create($booking['date']), 'W');
					$booking['from_']	 = $from->format('H:i');
					$booking['to_']		 = $to->format('H:i');
					// We need to use 23:59 instead of 00:00 to sort correctly
					$booking['to_']		 = $booking['to_'] == '00:00' ? '23:59' : $booking['to_'];
					$new_bookings[]		 = $booking;
				}
				// Multi-day booking
				else
				{
					$start	 = clone max($from, $t0);
					$end	 = clone min($to, $t1);
					$date	 = clone $start;
					do
					{
						$new_booking			 = $booking;
						$new_booking['date']	 = $date->format('Y-m-d');
						$new_booking['wday']	 = date_format($date, 'D');
						$new_booking['week']	 = date_format($date, 'W');
						$new_booking['from_']	 = '00:00';
						$new_booking['to_']		 = '00:00';
						if ($new_booking['date'] == $from->format('Y-m-d'))
						{
							$new_booking['from_'] = $from->format('H:i');
						}
						else if ($new_booking['date'] == $to->format('Y-m-d'))
						{
							$new_booking['to_'] = $to->format('H:i');
						}
						// We need to use 23:59 instead of 00:00 to sort correctly
						$new_booking['to_']	 = $new_booking['to_'] == '00:00' ? '23:59' : $new_booking['to_'];
						$new_bookings[]		 = $new_booking;
						unset($new_booking);
						if ($date->format('Y-m-d') == $end->format('Y-m-d'))
						{
							break;
						}

						//		if($date->getTimestamp() > $end->getTimestamp()) // > php 5.3.0
						if ($date->format("U") > $end->format("U"))
						{
							throw new InvalidArgumentException('start time( ' . $date->format('Y-m-d') . ' ) later than end time( ' . $end->format('Y-m-d') . " ) for {$booking['type']}#{$booking['id']}::{$booking['name']}");
						}

						$date->modify('+1 day');
					}
					while (true);
				}
			}

			foreach ($new_bookings as &$new_booking)
			{
				$booking_from	= new DateTime($new_booking['date'] . ' ' . $new_booking['from_']);
				$booking_to	= new DateTime($new_booking['date'] . ' ' . $new_booking['to_']);
				$conflicts = array();
				foreach ($new_booking['conflicts'] as $conflict)
				{
					$conflict_from	 = new DateTime($conflict['from_']);
					$conflict_to	 = new DateTime($conflict['to_']);

					if(
					  ($booking_from <= $conflict_from AND $booking_to > $conflict_from)
                      || ($booking_from > $conflict_from AND $booking_to < $conflict_to)
                      || ($booking_from < $conflict_to AND $booking_to >= $conflict_to)
					)
					{
						$conflicts[] = $conflict;
					}
				}
				$new_booking['conflicts'] = $conflicts;
			}

			return $new_bookings;
		}

		function _remove_event_conflicts( $bookings, &$events, $list_conflicts = false )
		{
			$conflict_map = array();

			foreach ($events as &$e)
			{
				$e['conflicts'] = array();
			}
			$new_bookings	 = array();
			$last			 = array();
			foreach ($bookings as $b)
			{
				if ($last)
				{
					foreach ($last as $l)
					{
//                        echo $l['id']."-".$l['from_']."-".$l['to_']."\n";
						$new_bookings[] = $l;
					}
					$last = array();
				}
				$keep = true;
//                $i = 0;
				foreach ($events as &$e)
				{

//                    echo $b['id']."\tfrom: ".substr($b['from_'],11,19)." to: ".substr($b['to_'],11,19)."\n";
//                    echo $e['id']."\tfrom: ".substr($e['from_'],11,19)." to: ".substr($e['to_'],11,19)." ".$e['name']."\n";

					if (
						(
						($b['from_'] >= $e['from_'] && $b['from_'] < $e['to_']) ||
						($b['to_'] > $e['from_'] && $b['to_'] <= $e['to_']) ||
						($b['from_'] <= $e['from_'] && $b['to_'] >= $e['to_'])
						) &&
						(array_intersect($b['resources'], $e['resources']) != array()))
					{
						$test_intersect = array_intersect($e['resources'], $b['resources']);

						$resources_to_keep = array_diff($b['resources'], $test_intersect);
						if ($resources_to_keep)
						{
							$tmp				 = $b;
							$tmp['resources']	 = $resources_to_keep;
							$last[]				 = $tmp;
						}

//                        echo "##$i\n";
						$keep				 = false;
						if($list_conflicts && !isset($conflict_map["{$b['type']}_{$b['id']}"]))
						{
							$e['conflicts'][]	 = $b;
							$conflict_map["{$b['type']}_{$b['id']}"] = true;
						}

						$bf	 = $b['from_'];
						$bt	 = $b['to_'];
						$ef	 = $e['from_'];
						$et	 = $e['to_'];
						$tmp = $b;

						if ($last)
						{
							$ilast	 = $last;
							$last	 = array();
							foreach ($ilast as $l)
							{
								$lf	 = $l['from_'];
								$lt	 = $l['to_'];
								$tmp = $l;
								if ($ef <= $lf && $et >= $lt)
								{
//                                    echo "B0: break ef <= bf && et >= bt\n\n";
									$last[] = $l;
									break;
								}
								/**
								 * Sigurd 20181001: endret fra
								 * $tmp['to_'] = $ef;
								 * til
								 * $tmp['to_'] = $lt;
								 */
								else if (($ef >= $lf) && ($et > $lt))
								{
//                                    echo "B1: (ef >= lf) && (et > lt)\n";
									$tmp['from_']	 = $lf;
//									$tmp['to_'] = $ef;
									//Sigurd 20181001
									$tmp['to_']		 = $lt;
									$last[]			 = $tmp;
								}
								elseif (($ef <= $lf) && ($et < $lt))
								{
//                                    echo "B2: (ef <= lf) && (et < lt)\n";
									$tmp['from_']	 = $et;
									$tmp['to_']		 = $lt;
									$last[]			 = $tmp;
								}
								elseif (($ef > $lf) && ($et < $lt))
								{
//                                    echo "B3: (ef > lf) && (et < lt)\n";
									$tmp['from_']	 = $lf;
									$tmp['to_']		 = $ef;
									$last[]			 = $tmp;
									$tmp['from_']	 = $et;
									$tmp['to_']		 = $lt;
									$last[]			 = $tmp;
								}
								else
								{
//                                    echo "B4: else break\n\n";
									$last[] = $l;
									break;
								}
							}
						}
						else
						{
							if ($ef <= $bf && $et >= $bt)
							{
//                                echo "A0: break ef <= bf && et >= bt\n\n";
								break;
							}
							//elseif (($ef >= $bf) && ($et > $bt))
							/**
							 * Sigurd 20170425 - altered in an attempt to keep allocations from disappearing.
							 */
							elseif (($ef >= $bf) && ($et >= $bt))
							{
//                                echo "A1: (ef >= bf) && (et > bt)\n";
								$tmp['from_']	 = $bf;
								$tmp['to_']		 = $ef;
								$last[]			 = $tmp;
							}
							elseif (($ef <= $bf) && ($et < $bt))
							{
//                                echo "A2: (ef <= bf) && (et < bt)\n";
								$tmp['from_']	 = $et;
								$tmp['to_']		 = $bt;
								$last[]			 = $tmp;
							}
							elseif (($ef > $bf) && ($et < $bt))
							{
//                                echo "A3: (ef > bf) && (et < bt)\n";
								$tmp['from_']	 = $bf;
								$tmp['to_']		 = $ef;
								$last[]			 = $tmp;
								$tmp['from_']	 = $et;
								$tmp['to_']		 = $bt;
								$last[]			 = $tmp;
							}
							else
							{
//                                echo "A4: else break\n\n";
								break;
							}
						}
//                        print_r($last);
					}
//                    $i+=1;
				}

				if ($last)
				{
					foreach ($last as $l)
					{
//                        echo $l['id']."-".$l['from_']."-".$l['to_']."\n";
						$new_bookings[] = $l;
					}
					$last = array();
				}

				if ($keep)
				{
					$new_bookings[] = $b;
				}
			}
//            print_r($new_bookings);
			return $new_bookings;
//            exit;
		}

		function _remove_event_conflicts_org( $bookings, &$events )
		{
			foreach ($events as &$e)
			{
				$e['conflicts'] = array();
			}
			$new_bookings = array();
			foreach ($bookings as $b)
			{
				$keep = true;
				foreach ($events as &$e)
				{
					if ((($b['from_'] >= $e['from_'] && $b['from_'] < $e['to_']) ||
						($b['to_'] > $e['from_'] && $b['to_'] <= $e['to_']) ||
						($b['from_'] <= $e['from_'] && $b['to_'] >= $e['to_'])) && (array_intersect($b['resources'], $e['resources']) != array()))
					{
						$keep				 = false;
						$e['conflicts'][]	 = $b;
						break;
					}
				}
				if ($keep)
				{
					$new_bookings[] = $b;
				}
			}
			return $new_bookings;
		}

		public function complete_expired( &$bookings )
		{
			$this->so->complete_expired($bookings);
		}

		public function find_expired($update_reservation_time)
		{
			return $this->so->find_expired($update_reservation_time);
		}

		function validate( &$entry )
		{
			$entry['allocation_id'] = $this->so->calculate_allocation_id($entry);
			return parent::validate($entry);
		}
	}
