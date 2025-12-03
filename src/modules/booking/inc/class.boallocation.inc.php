<?php
	phpgw::import_class('booking.bocommon_authorized');

	class booking_boallocation extends booking_bocommon_authorized
	{

		var $season_bo;
		function __construct()
		{
			parent::__construct();
			$this->so = CreateObject('booking.soallocation');
		}

		/**
		 * @ Send message about cancelation to users of building. 
		 */
		function send_notification( $allocation, $maildata, $mailadresses )
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

			if ($maildata['outseason'] != 'on' && $maildata['recurring'] != 'on')
			{
				$res_names = '';
				foreach ($allocation['resources'] as $res)
				{
					$res_names = $res_names . $this->so->get_resource($res) . " ";
				}
				$info_deleted = ':<p>';
				$info_deleted = $info_deleted . "" . $res_names . " - ";
				$info_deleted .= pretty_timestamp($allocation['from_']) . " - ";
				$info_deleted .= pretty_timestamp($allocation['to_']);
				$link = $external_site_address . '/bookingfrontend/?menuaction=bookingfrontend.uiapplication.add&building_id=';
				$link .= $allocation['building_id'] . '&building_name=' . urlencode($allocation['building_name']) . '&from_[]=';
				$link .= urlencode($allocation['from_']) . '&to_[]=' . urlencode($allocation['to_']) . '&resource=' . $allocation['resources'][0];
				$info_deleted .= ' - <a href="' . $link . '">' . lang('Apply for time') . '</a><br />';

				$subject = $config->config_data['allocation_canceled_mail_subject'];
				$body = "<p>" . $config->config_data['allocation_canceled_mail'];
				$body .= '<br />' . $allocation['organization_name'] . ' har avbestilt tid i ' . $allocation['building_name'];
				$body .= $info_deleted . '</p>';
			}
			else
			{
				$res_names = '';
				foreach ($allocation['resources'] as $res)
				{
					$res_names = $res_names . $this->so->get_resource($res) . " ";
				}
				$info_deleted = ':<p>';
				foreach ($maildata['delete'] as $valid_date)
				{
					$info_deleted = $info_deleted . "" . $res_names . " - ";
					$info_deleted .= pretty_timestamp($valid_date['from_']) . " - ";
					$info_deleted .= pretty_timestamp($valid_date['to_']);
					$link = $external_site_address . '/bookingfrontend/?menuaction=bookingfrontend.uiapplication.add&building_id=';
					$link .= $allocation['building_id'] . '&building_name=' . urlencode($allocation['building_name']) . '&from_[]=';
					$link .= urlencode($valid_date['from_']) . '&to_[]=' . urlencode($valid_date['to_']) . '&resource=' . $allocation['resources'][0];
					$info_deleted .= ' - <a href="' . $link . '">' . lang('Apply for time') . '</a><br />';
				}

				$subject = $config->config_data['allocation_canceled_mail_subject'];
				$body = "<p>" . $config->config_data['allocation_canceled_mail'];
				$body .= '<br />' . $allocation['organization_name'] . ' har avbestilt tid i ' . $allocation['building_name'];
				$body .= $info_deleted . '</p>';
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

		function send_admin_notification( $allocation, $maildata, $system_message )
		{
			if (!(isset($this->serverSettings['smtp_server']) && $this->serverSettings['smtp_server']))
			{
				return;
			}
			$send = CreateObject('phpgwapi.send');

			$config = CreateObject('phpgwapi.config', 'booking');
			$config->read();

			$mailadresses = $config->config_data['emails'];
			$mailadresses = explode("\n", $mailadresses);

			$extra_mail_addresses = CreateObject('booking.boapplication')->get_mail_addresses( $allocation['building_id'] );

			if(!empty($mailadresses[0]))
			{
				$mailadresses = array_merge($mailadresses, array_values($extra_mail_addresses));
			}
			else
			{
				$mailadresses = array_values($extra_mail_addresses);
			}

			$from = isset($config->config_data['email_sender']) && $config->config_data['email_sender'] ? $config->config_data['email_sender'] : "noreply<noreply@{$this->serverSettings['hostname']}>";

			$external_site_address = isset($config->config_data['external_site_address']) && $config->config_data['external_site_address'] ? $config->config_data['external_site_address'] : $this->serverSettings['webserver_url'];

			$subject = $system_message['title'];
			$body = '<b>Beskjed fra ' . $system_message['name'] . '</b><br />' . $system_message['message'] . '<br /><br /><b>Epost som er sendt til brukere av Hallen:</b><br />';


			if ($config->config_data['user_can_delete_allocations'] == 'yes')
			{
				if ($maildata['outseason'] != 'on' && $maildata['recurring'] != 'on')
				{
					$res_names = '';
					foreach ($allocation['resources'] as $res)
					{
						$res_names = $res_names . $this->so->get_resource($res) . " ";
					}
					$info_deleted = ':<p>';
					$info_deleted = $info_deleted . "" . $res_names . " - ";
					$info_deleted .= pretty_timestamp($allocation['from_']) . " - ";
					$info_deleted .= pretty_timestamp($allocation['to_']);
					$link = $external_site_address . '/?menuaction=booking.uiapplication.add&building_id=';
					$link .= $allocation['building_id'] . '&building_name=' . urlencode($allocation['building_name']) . '&from_[]=';
					$link .= urlencode($allocation['from_']) . '&to_[]=' . urlencode($allocation['to_']) . '&resource=' . $allocation['resources'][0];
					$info_deleted .= ' - <a href="' . $link . '">' . lang('Apply for time') . '</a><br />';

					$body .= "<p>" . $config->config_data['allocation_canceled_mail'];
					$body .= '<br />' . $allocation['organization_name'] . ' har avbestilt tid i ' . $allocation['building_name'];
					$body .= $info_deleted . '</p>';
				}
				else
				{
					$res_names = '';
					foreach ($allocation['resources'] as $res)
					{
						$res_names = $res_names . $this->so->get_resource($res) . " ";
					}
					$info_deleted = ':<p>';
					foreach ($maildata['delete'] as $valid_date)
					{
						$info_deleted = $info_deleted . "" . $res_names . " - ";
						$info_deleted .= pretty_timestamp($valid_date['from_']) . " - ";
						$info_deleted .= pretty_timestamp($valid_date['to_']);
						$link = $external_site_address . '/?menuaction=booking.uiapplication.add&building_id=';
						$link .= $allocation['building_id'] . '&building_name=' . urlencode($allocation['building_name']) . '&from_[]=';
						$link .= urlencode($valid_date['from_']) . '&to_[]=' . urlencode($valid_date['to_']) . '&resource=' . $allocation['resources'][0];
						$info_deleted .= ' - <a href="' . $link . '">' . lang('Apply for time') . '</a><br />';
					}

					$body .= "<p>" . $config->config_data['allocation_canceled_mail'];
					$body .= '<br />' . $allocation['organization_name'] . ' har avbestilt tid i ' . $allocation['building_name'];
					$body .= $info_deleted . '</p>';
				}
			}
			else
			{
				$body .= "<p>Det er ikke sendt noen beskjed til brukere.</p>";
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
		protected function include_subject_parent_roles(array|null $for_object = null )
		{
			$this->season_bo = CreateObject('booking.boseason');
			$parent_roles = null;
			$parent_season = null;

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
		 * @see bocommon_authorized
		 */
		protected function get_object_role_permissions( $forObject, $defaultPermissions )
		{
			return array_merge(
				array
				(
				'parent_role_permissions' => array
					(
					'season' => array
						(
						booking_sopermission::ROLE_MANAGER => array(
							'write' => true,
							'create' => true,
						),
						booking_sopermission::ROLE_CASE_OFFICER => array(
							'write' => true,
							'create' => true,
						),
						'parent_role_permissions' => array(
							'building' => array(
								booking_sopermission::ROLE_MANAGER => array(
									'write' => true,
									'create' => true,
								),
							),
						)
					),
				),
				'global' => array
					(
					booking_sopermission::ROLE_MANAGER => array
						(
						'write' => true,
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
			return array_merge(
				array
				(
				'parent_role_permissions' => array
					(
					'season' => array
						(
						booking_sopermission::ROLE_MANAGER => array(
							'create' => true,
						),
						booking_sopermission::ROLE_CASE_OFFICER => array(
							'create' => true,
						),
						'parent_role_permissions' => array(
							'building' => array(
								booking_sopermission::ROLE_MANAGER => array(
									'create' => true,
								),
							),
						)
					)
				),
				'global' => array
					(
					booking_sopermission::ROLE_MANAGER => array
						(
						'create' => true
					)
				),
				), $defaultPermissions
			);
		}

		public function complete_expired( &$allocations )
		{
			$this->so->complete_expired($allocations);
		}

		public function find_expired($update_reservation_time)
		{
			return $this->so->find_expired($update_reservation_time);
		}

		/**
		 * Override add method to send webhook notifications
		 */
		function add($entity)
		{
			// Call parent add method
			$result = parent::add($entity);

			// Get the new allocation ID
			$allocation_id = $result['id'];

			// Get resource IDs if present
			$resource_ids = array();
			if (isset($entity['resources']) && is_array($entity['resources']))
			{
				$resource_ids = $entity['resources'];
			}

			try
			{
				$webhookNotifier = new \App\modules\booking\services\WebhookNotifier();
				$webhookNotifier->notifyChange('allocation', 'created', $allocation_id, $resource_ids);
			}
			catch (Exception $e)
			{
			// Log error but don't fail the main operation
			$log = new App\modules\phpgwapi\services\Log();

			$log->error(array(
				'text' => 'Webhook notification failed after event creation for event {p1} (reservation {p2})',
				'p1' => $allocation_id ? $allocation_id : "unknown",
				'p2' => $resource_ids ? implode(',', $resource_ids) : "none",
				'line' => __LINE__,
				'file' => __FILE__
			));

			}

			return $result;
		}

		/**
		 * Override update method to send webhook notifications
		 */
		function update($entity)
		{
			// Call parent update method
			$result = parent::update($entity);

			// Get allocation ID
			$allocation_id = $entity['id'];

			// Get resource IDs if present
			$resource_ids = array();
			if (isset($entity['resources']) && is_array($entity['resources']))
			{
				$resource_ids = $entity['resources'];
			}

			// Send webhook notification (async, after response)
			try
			{

				$change_type = $entity['active'] == 1 ? 'updated' : 'deleted';
				$webhookNotifier = new \App\modules\booking\services\WebhookNotifier();
				$webhookNotifier->notifyChange('allocation', $change_type, $allocation_id, $resource_ids);
			}
			catch (Exception $e)
			{
				// Log error but don't fail the main operation
				$log = new App\modules\phpgwapi\services\Log();
				$log->error(array(
					'text' => 'Webhook notification failed after allocation update',
					'p1' => $allocation_id ? $allocation_id : "unknown",
					'p2' => $resource_ids ? implode(',', $resource_ids) : "none",
					'line' => __LINE__,
					'file' => __FILE__
				));
			}

			return $result;
		}

		/**
		 * Override delete method to send webhook notifications
		 */
		function delete($id)
		{
			// Get allocation data before deletion (to get resource IDs)
			$allocation = $this->read_single($id);
			$resource_ids = array();
			if ($allocation && isset($allocation['resources']) && is_array($allocation['resources']))
			{
				$resource_ids = $allocation['resources'];
			}

			try
			{
				$webhookNotifier = new \App\modules\booking\services\WebhookNotifier();
				$webhookNotifier->notifyChange('allocation', 'deleted', $id, $resource_ids);
			}
			catch (Exception $e)
			{
				// Log error but don't fail the main operation
				$log = new App\modules\phpgwapi\services\Log();
				$log->error(array(
					'text' => 'Webhook notification failed after event creation for allocation {p1} (reservation {p2})',
					'p1' => $id ? $id : "unknown",
					'p2' => $resource_ids ? implode(',', $resource_ids) : "none",
					'line' => __LINE__,
					'file' => __FILE__
				));
			}

			// Call parent delete method
			$result = parent::delete_allocation($id);

			return $result;
		}
	}