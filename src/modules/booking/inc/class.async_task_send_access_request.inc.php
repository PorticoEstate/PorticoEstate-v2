<?php

use App\Database\Db;

phpgw::import_class('phpgwapi.datetime');
phpgw::import_class('booking.async_task');

class booking_async_task_send_access_request extends booking_async_task
{
	private $account, $config, $e_lock_integration;
	var	$cleanup_old_reservations = array();
	var	$e_lock_host_map = array();
	private $simulate = false;
	// Tracking array for sent emails
	private $email_sent = array();
	private $dateTimeFormat;

	public function __construct()
	{
		parent::__construct();
		$this->account	 = $this->userSettings['account_id'];
		$this->config	 = CreateObject('phpgwapi.config', 'booking')->read();

		$this->dateTimeFormat = $this->userSettings['preferences']['common']['dateformat'] . ' H:i';

		$bogeneric = createObject('booking.bogeneric');
		$lock_systems = $bogeneric->read(array('location_info' => array('type' => 'e_lock_system')));

		foreach ($lock_systems as $lock_system)
		{
			$this->e_lock_host_map[$lock_system['id']] = $lock_system['webservicehost'];
		}
	}

	public function get_default_times()
	{
		return array(
			'min'	 => '*',
			'hour'	 => '*',
			'dow'	 => '*',
			'day'	 => '*',
			'month'	 => '*',
			'year'	 => '*'
		);
	}

	public function run($options = array())
	{
		static $create_call_ids = array();
		static $status_call_ids = array();

		// Initialize email tracking array at the beginning of each run
		$this->email_sent = array();

		$request_method = !empty($this->config['e_lock_request_method']) ? $this->config['e_lock_request_method'] : 'Stavanger_e_lock.php';

		if (!$request_method)
		{
			throw new LogicException('request_method not chosen');
		}

		$file = PHPGW_SERVER_ROOT . "/booking/inc/custom/default/{$request_method}";

		if (!is_file($file))
		{
			throw new LogicException("request method \"{$request_method}\" not available");
		}

		require_once $file;

		$e_lock_integration = new booking_e_lock_integration();
		$this->e_lock_integration = $e_lock_integration;

		$db = Db::getInstance();

		$reservation_types = array(
			//				'booking',
			'event',
			//				'allocation'
		);

		$stages = array(
			0	 => 60 * 60 * 3, // 3 hours : send SMS and email as reminder
			1	 => 60 * 15, // 15 minutes : request access
			2	 => 60 * 10, // 10 minutes : get request status
		);
		$processed_reservations = array();
		$so_resource = CreateObject('booking.soresource');

		//SMS
		try
		{
			$sms_service = CreateObject('sms.sms');
		}
		catch (Exception $ex)
		{
			$this->log('sms_error', $ex->getMessage());
			$sms_service = null;
		}

		foreach ($stages as $stage => $time_ahead)
		{
			foreach ($reservation_types as $reservation_type)
			{
				$bo = CreateObject('booking.bo' . $reservation_type);

				/**
				 * Condition: < $_stage
				 * update to $_stage after check
				 */
				$_stage = $stage + 1;

				$request_access = $bo->find_request_access($_stage, $time_ahead);

				if (!is_array($request_access) || empty($request_access['results']))
				{
					continue;
				}

				if ($db->get_transaction())
				{
					$this->global_lock = true;
				}
				else
				{
					$db->transaction_begin();
				}

				if (count($request_access['results']) > 0)
				{
					foreach ($request_access['results'] as $reservation)
					{
						$resources = $so_resource->read(array(
							'filters'	 => array('where' => 'bb_resource.id IN(' . implode(', ', $reservation['resources']) . ')'),
							'results'	 => 100
						));

						foreach ($resources['results'] as $resource)
						{
							if (!$resource['e_locks'])
							{
								continue;
							}
							if ($stage == 0)
							{
								if (!empty($processed_reservations[$reservation['id']]))
								{
									continue;
								}

								/**
								 * send SMS
								 */

								$_from = date($this->dateTimeFormat, strtotime($reservation['from_']));
								$_to = date($this->dateTimeFormat, strtotime($reservation['to_']));
								$sms_text = "Hei {$reservation['contact_name']}\n "
									. "Du har fått tilgang til {$resource['name']} i tidsrommet {$_from} - {$_to}.\nKoden for adgang vil bli sendt 10 minutter før tidspunktet for tilgang.\n ";
								/**
								 * send email - only if not already sent for this reservation
								 */
								if ($this->simulate)
								{
									echo 'stage 0 - sms:';
									_debug_array($reservation['contact_phone']);
									echo 'stage 0 - email:';
									_debug_array($reservation['contact_email']);
								}
								else if (!isset($this->email_sent[$reservation['id']]))
								{
									if ($sms_service)
									{
										try
										{
											$sms_res = $sms_service->websend2pv($this->account, $reservation['contact_phone'], $sms_text);
										}
										catch (Exception $ex)
										{
											//implement me
											$this->log('sms_error', $ex->getMessage());
										}

										if (!empty($sms_res[0][0]))
										{
											$comment = 'Melding om tilgang er sendt til ' . $reservation['contact_phone'];
											$bo->add_single_comment($reservation['id'], $comment);
										}
									}
									$this->send_mailnotification($reservation['contact_email'], 'Melding om tilgang', nl2br($sms_text));
									// Mark email as sent for this reservation
									$this->email_sent[$reservation['id']] = true;
								}

								$this->log('sms_tekst', $sms_text);
							}
							else if ($stage == 1)
							{
								/**
								 * send request
								 */
								foreach ($resource['e_locks'] as $e_lock)
								{
									if (!$e_lock['e_lock_system_id'] || !$e_lock['e_lock_resource_id'])
									{
										continue;
									}

									$to = $this->round_to_next_hour($reservation['to_']);
									$custom_id	 = "{$reservation['id']}::{$resource['id']}::{$e_lock['e_lock_system_id']}::{$e_lock['e_lock_resource_id']}";

									if (isset($create_call_ids[$custom_id]))
									{
										continue;
									}

									$webservicehost = $e_lock['webservicehost'];

									$post_data = array(
										'id'		 => 0,
										'custom_id'	 => $custom_id,
										'desc'		 => $reservation['contact_name'],
										'email'		 => $reservation['contact_email'],
										'from'		 => date('Y-m-d\TH:i:s.v', phpgwapi_datetime::user_localtime()) . 'Z',
										'mobile'	 => $reservation['contact_phone'],
										'resid'		 => $e_lock['e_lock_resource_id'],
										'system'	 => (int)$e_lock['e_lock_system_id'],
										'to'		 => $to->format('Y-m-d\TH:i:s.v') . 'Z',
									);
									if ($this->simulate)
									{
										echo 'stage 1 - post_data:';
										_debug_array($post_data);
										$create_call_ids[$custom_id] = true;
									}
									else
									{
										$http_code = $e_lock_integration->resources_create($post_data, $webservicehost);

										if ($http_code == 200)
										{
											$create_call_ids[$custom_id] = true;
										}
									}

									$log_data = _debug_array($post_data, false);
									$this->log('post_data', $log_data);
									$this->log('http_code', $http_code);
								}
								unset($e_lock);
							}
							else if ($stage == 2)
							{
								$_from = date($this->dateTimeFormat, strtotime($reservation['from_']));
								$_to = date($this->dateTimeFormat, strtotime($reservation['to_']));
								$sms_text = "Hei {$reservation['contact_name']}\n "
									. "Du har fått tilgang til {$resource['name']} i tidsrommet {$_from} - {$_to}.\n ";							
							// Collect all access codes from all e_locks before sending
								$access_codes = array();
								$failed_locks = array();
															/**
								 * Get status
								 */
								foreach ($resource['e_locks'] as $e_lock)
								{
									$get_data = array(
										'resid'		 => $e_lock['e_lock_resource_id'],
										'reserved'	 => 1,
										'system'	 => (int)$e_lock['e_lock_system_id'],
									);

									$webservicehost = $e_lock['webservicehost'];
									if ($this->simulate)
									{

										echo 'stage 2 - get_data:';
										_debug_array($get_data);
										$status_arr = array(
											array(
												'custom_id'	 => '1::1::1::1',
												'id'		 => 1,
												'key'		 => '123456',
												'to'		 => date('Y-m-d\TH:i:s.v', phpgwapi_datetime::user_localtime()) . 'Z',
											)
										);
									}
									else
									{
										$status_arr = $e_lock_integration->get_status($get_data, $webservicehost);

										$this->cleanup_old_reservations["{$get_data['system']}_{$get_data['resid']}"] = $status_arr;

										$log_data	 = _debug_array($get_data, false);
										$this->log('get_data', $log_data);
										$log_data	 = _debug_array($status_arr, false);
										$this->log('status_arr', $log_data);
									}

									/**
									 * look for descr, and collect access codes for all matching e_locks
									 */
									$e_lock_name		 = $e_lock['e_lock_name'] ? $e_lock['e_lock_name'] : 'låsen';
									$found_reservation	 = false;
									foreach ($status_arr as $status)
									{
										$id_arr = explode('::', $status['custom_id']);
										if ((int)$reservation['id'] !== (int)$id_arr[0])
										{
											continue;
										}

										$custom_id = "{$reservation['id']}::{$resource['id']}::{$e_lock['e_lock_system_id']}::{$e_lock['e_lock_resource_id']}";

										if (isset($status_call_ids[$custom_id]))
										{
											continue;
										}

										if (empty($e_lock['access_code_format']))
										{
											continue;
										}

										if ($custom_id == $status['custom_id'])
										{
											$status_call_ids[$custom_id] = true;

											$found_reservation = true;

											if ($e_lock['access_code_format'] && preg_match('/__key__/i', $e_lock['access_code_format']))
											{
												$e_loc_key = str_replace('__key__', $status['key'], $e_lock['access_code_format']);
											}
											else
											{
												$e_loc_key = $status['key'];
											}

											// Store access code info for later
											$access_codes[] = array(
												'lock_name' => $e_lock_name,
												'code' => $e_loc_key,
												'instruction' => $e_lock['access_instruction'] ?? null
											);

											break;
										}
									}

									unset($status);

									if (!$found_reservation)
									{
										$failed_locks[] = $e_lock_name;
									}
								}
								unset($e_lock);
								
								// Now send all access codes in a single message if reservation is not already sent
								if (!isset($this->email_sent[$reservation['id']]))
								{
									// Build SMS text with all access codes
									foreach ($access_codes as $code_info)
									{
										$sms_text .= "Koden for {$code_info['lock_name']} er: {$code_info['code']}\n";
										if ($code_info['instruction'])
										{
											$sms_text .= "\n{$code_info['instruction']}\n";
										}
									}
									
									// Send combined message if we have at least one access code
									if (!empty($access_codes))
									{
										if ($this->simulate)
										{
											echo 'stage 2 - sms/contact_phone:';
											_debug_array($reservation['contact_phone']);
										}
										else
										{
											if ($sms_service)
											{
												try
												{
													$sms_res = $sms_service->websend2pv($this->account, $reservation['contact_phone'], $sms_text);
												}
												catch (Exception $ex)
												{
													$this->log('sms_error', $ex->getMessage());
												}

												if (!empty($sms_res[0][0]))
												{
													$comment = 'Melding om tilgang er sendt til ' . $reservation['contact_phone'];
													$bo->add_single_comment($reservation['id'], $comment);
												}
											}
											if ($this->send_mailnotification($reservation['contact_email'], 'Melding om tilgang', nl2br($sms_text)))
											{
												$comment = 'Melding om tilgang og koder for alle låser er sendt til ' . $reservation['contact_email'];
												$bo->add_single_comment($reservation['id'], $comment);
											}
											$this->log('sms_tekst', $sms_text);
										}
										// Mark email as sent for this reservation
										$this->email_sent[$reservation['id']] = true;
									}
									elseif (!empty($failed_locks) && $sms_service)
									{
										// If no codes found but we have failed locks, send error message
										if ($this->simulate)
										{
											echo "stage 2 - sms/melding (Fant ikke reservasjonen): reservasjon: {$reservation['id']}, låser: " . implode(', ', $failed_locks) . "<br/>";
											echo "Ressurs: {$resource['name']}, {$resource['id']}<br/>";
										}
										else
										{
											$lock_list = implode(', ', $failed_locks);
											$error_msg	 = "Fant ikke reservasjonen for {$lock_list} i adgangskontrollen.\n";
											$error_msg	 .= "Du må kontakte byggansvarlig for manuell innlåsing.\n";
											$error_msg	 .= "Denne meldingen kan ikke besvares";
											$sms_res	 = $sms_service->websend2pv($this->account, $reservation['contact_phone'], $error_msg);

											$this->send_mailnotification($reservation['contact_email'], 'Melding om tilgang', nl2br($error_msg));
											$bo->add_single_comment($reservation['id'], "Fant ikke reservasjonene for {$lock_list} i adgangskontrollen.");
											$this->email_sent[$reservation['id']] = true;
										}
									}
								}
							}
							$processed_reservations[$reservation['id']] = true;
						}
						unset($resource);
					}

					unset($reservation);

					$bo->complete_request_access($request_access['results'], $_stage);
				}

				if ($this->simulate)
				{
					$db->transaction_abort();
				}
				else
				{
					if (!$this->global_lock)
					{
						$db->transaction_commit();
					}
				}
			}
		}

		$this->cleanup_old_reservations();
	}

	private function cleanup_old_reservations()
	{
		foreach ($this->cleanup_old_reservations as $key => $status_arr)
		{

			$system_arr = explode('_', $key);
			$e_lock_system = $system_arr[0];

			$webservicehost = $this->e_lock_host_map[$e_lock_system];

			$now = time() - 7 * 24 * 3600; // a week old

			foreach ($status_arr as $entry)
			{
				$to = strtotime($entry['to']);

				if ($to > 0 && $to < $now)
				{
					$delete_data = array(
						'id'				 => (int)$entry['id'],
						'deleterequest'		 =>  1,
					);

					$http_code = $this->e_lock_integration->resources_delete($delete_data, $webservicehost);
				}
			}
		}
	}
	private function send_mailnotification($receiver, $subject, $body)
	{
		$rcpt	 = false;
		$send	 = CreateObject('phpgwapi.send');

		$from = isset($this->config['email_sender']) && $this->config['email_sender'] ? $this->config['email_sender'] : "noreply<noreply@{$this->serverSettings['hostname']}>";

		if (strlen(trim($body)) == 0)
		{
			return false;
		}

		if (strlen($receiver) > 0)
		{
			try
			{
				$rcpt = $send->msg('email', $receiver, $subject, $body, '', '', '', $from, 'AktivKommune', 'html');
			}
			catch (Exception $e)
			{
				// TODO: Inform user if something goes wrong
			}
		}
		return $rcpt;
	}

	private function log($what, $value = '')
	{
		$log_args = array(
			'severity'	 => 'I',
			'file'		 => __FILE__,
			'line'		 => __LINE__,
			'text'		 => "what: {$what}, <br/>value: {$value}"
		);

		$log = new \App\modules\phpgwapi\services\Log();
		$log->info($log_args);
	}

	function round_to_next_hour($dateString)
	{
		$date	 = new DateTime($dateString);
		$minutes = $date->format('i');
		if ($minutes > 0)
		{
			$date->modify("+1 hour");
			$date->modify('-' . $minutes . ' minutes');
		}
		return $date;
	}
}
