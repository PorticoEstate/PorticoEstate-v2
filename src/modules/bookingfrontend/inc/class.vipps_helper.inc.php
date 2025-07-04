<?php

/**
 * https://vippsas.github.io/vipps-ecom-api/shins/index.html?php#vipps-ecommerce-api
 * https://github.com/vippsas/vipps-ecom-api/blob/master/vipps-ecom-api.md
 */

use App\modules\phpgwapi\services\Cache;
use App\modules\phpgwapi\controllers\Locations;
use App\modules\phpgwapi\security\Sessions;
use App\Database\Db;
use GuzzleHttp;


class bookingfrontend_vipps_helper
{

	public $public_functions = array(
		'initiate'				 => true,
		'get_payment_details'	 => true,
		'check_payment_status'	 => true,
	);
	private $client_id,
		$client_secret,
		$subscription_key,
		$headers		 = array(),
		$proxy,
		$accesstoken,
		$debug,
		$client,
		$base_url,
		$msn;

	public function __construct()
	{
		Cache::session_set('bookingfrontend', 'payment_method', 'vipps');

		$location_obj = new Locations();
		$location_id		 = $location_obj->get_id('booking', 'run');
		$custom_config		 = CreateObject('admin.soconfig', $location_id);
		$custom_config_data	 = $custom_config->config_data['Vipps'];

		$config				 = CreateObject('phpgwapi.config', 'booking')->read();

		if (!empty($custom_config_data['debug']))
		{
			$this->debug = true;
		}

		$this->base_url			 = !empty($custom_config_data['base_url']) ? $custom_config_data['base_url'] : 'https://apitest.vipps.no';
		$this->client_id		 = !empty($custom_config_data['client_id']) ? $custom_config_data['client_id'] : '';
		$this->client_secret	 = !empty($custom_config_data['client_secret']) ? $custom_config_data['client_secret'] : '';
		$this->subscription_key	 = !empty($custom_config_data['subscription_key']) ? $custom_config_data['subscription_key'] : '';
		$this->msn				 = !empty($custom_config_data['msn']) ? $custom_config_data['msn'] : '';
		$this->proxy			 = !empty($config['proxy']) ? $config['proxy'] : '';

		$this->client = new GuzzleHttp\Client();

		$this->accesstoken = $this->get_accesstoken();
	}

	public function initiate()
	{
		$application_ids = Sanitizer::get_var('application_id');
		return $this->initiate_payment($application_ids);
	}

	/**
	 * POST /accesstoken/get
	 */
	private function get_accesstoken()
	{
		$path	 = '/accesstoken/get';
		$url	 = "{$this->base_url}{$path}";

		$request = array();

		$request['headers'] = array(
			'Accept'					 => 'application/json;charset=UTF-8',
			'client_id'					 => $this->client_id,
			'client_secret'				 => $this->client_secret,
			'Ocp-Apim-Subscription-Key'	 => $this->subscription_key,
		);

		if ($this->proxy)
		{
			$request['proxy'] = array(
				'http'	 => $this->proxy,
				'https'	 => $this->proxy
			);
		}

		$request_body	 = array();
		$request['json'] = $request_body;

		try
		{
			$response	 = $this->client->request('POST', $url, $request);
			$status_code = $response->getStatusCode(); // 200
			$ret		 = json_decode($response->getBody()->getContents(), true);
		}
		catch (\GuzzleHttp\Exception\BadResponseException $e)
		{
			// handle exception or api errors.
			if ($this->debug)
			{
				print_r($e->getMessage());
			}
		}

		return !empty($ret['access_token']) ? $ret['access_token'] : null;
	}

	function get_item_name($line)
	{
		return $line['name'];
	}

	function get_date_range($dates)
	{
		return "{$dates['from_']} - {$dates['to_']}";
	}

	private function initiate_payment($application_ids)
	{

		$remote_order_id = null;
		$soapplication	 = CreateObject('booking.soapplication');
		$filters		 = array('id' => $application_ids);
		$params			 = array('filters' => $filters, 'results' => 'all');
		$applications	 = $soapplication->read($params);

		$soapplication->get_purchase_order($applications);

		$total_amount = 0;
		$unpaid_order_ids = array();
		$building_names = array();
		$contact_phone = null;

		foreach ($applications['results'] as $application)
		{
			$contact_phone = $application['contact_phone'];
			
			if (!empty($application['building_name']) && !in_array($application['building_name'], $building_names))
			{
				$building_names[] = $application['building_name'];
			}

			foreach ($application['orders'] as $order)
			{
				if (empty($order['paid']))
				{
					$total_amount += (float)$order['sum'];
					$unpaid_order_ids[] = $order['order_id'];
				}
			}
		}

		if ($total_amount > 0)
		{
			$building_text = !empty($building_names) ? implode(', ', $building_names) : '';
			$transaction_text = !empty($building_text) ? 'Aktiv kommune, ' . $building_text : 'Aktiv kommune';
			
			$remote_order_id = $soapplication->add_payment($unpaid_order_ids, $this->msn);
			$transaction	 = [
				"amount"					 => $total_amount * 100,
				"orderId"					 => $remote_order_id,
				"transactionText"			 => $transaction_text,
				"skipLandingPage"			 => false,
				"scope"						 => "name address email",
				"useExplicitCheckoutFlow"	 => true
			];
		}
		else
		{
			return array('error' => 'No unpaid orders found for payment initiation');
		}

		$path	 = '/ecomm/v2/payments';
		$url	 = "{$this->base_url}{$path}";

		$request = array();
		$this->get_header($request);

		$session_id = Sessions::getInstance()->get_session_id();

		$fall_back_url = phpgw::link(
			'/bookingfrontend/',
			array('menuaction' => 'bookingfrontend.uiapplication.add_contact', 'payment_order_id' => $remote_order_id, session_name() => $session_id),
			false,
			true
		);

		$request_body = [
			"customerInfo"	 => [
				"mobileNumber" => $contact_phone
			],
			"merchantInfo"	 => [
				"authToken"				 => $session_id,
				"callbackPrefix"		 => "https://example.com/vipps/callbacks-for-payment-updates",
				"consentRemovalPrefix"	 => "https://example.com/vipps/consent-removal",
				"fallBack"				 => str_replace('&amp;', '&', $fall_back_url),
				"isApp"					 => false,
				"merchantSerialNumber"	 => $this->msn,
				"paymentType"			 => "eComm Regular Payment"
			],
			"transaction"	 => $transaction
		];

		$request['json'] = $request_body;

		try
		{
			$response	 = $this->client->request('POST', $url, $request);
			$status_code = $response->getStatusCode(); // 200
			$ret		 = json_decode($response->getBody()->getContents(), true);
		}
		catch (\GuzzleHttp\Exception\BadResponseException $e)
		{
			/**
			 * Init failed - clean up
			 */
			$soapplication->delete_payment($remote_order_id);

			$ret = $e->getMessage();
		}

		return $ret;
	}

	private function capture_payment($remote_order_id, $amount)
	{
		$path	 = "/ecomm/v2/payments/{$remote_order_id}/capture";
		$url	 = "{$this->base_url}{$path}";

		$request = array();
		$this->get_header($request);

		$transaction = [
			"amount"			 => $amount,
			"transactionText"	 => 'Booking i Aktiv kommune',
		];

		$request_body = [
			"merchantInfo"	 => [
				"merchantSerialNumber" => $this->msn,
			],
			"transaction"	 => $transaction
		];

		$request['json'] = $request_body;

		try
		{
			$response	 = $this->client->request('POST', $url, $request);
			$status_code = $response->getStatusCode(); // 200

			$ret = json_decode($response->getBody()->getContents(), true);
		}
		catch (\GuzzleHttp\Exception\BadResponseException $e)
		{
			// handle exception or api errors.
			if ($this->debug)
			{
				print_r($e->getMessage());
			}
		}
		return $ret;
	}

	private function cancel_order($remote_order_id, $remote_state)
	{
		$sopurchase_order = createObject('booking.sopurchase_order');
		$soapplication	 = CreateObject('booking.soapplication');
		$application_ids = $soapplication->get_application_from_payment_order($remote_order_id);
		$status			 = array('deleted' => false);
		$session_id		 = Sessions::getInstance()->get_session_id();
		
		if (!empty($session_id) && !empty($application_ids))
		{
			$partials = CreateObject('booking.uiapplication')->get_partials($session_id);

			Db::getInstance()->transaction_begin();

			$bo_block = createObject('booking.boblock');

			foreach ($application_ids as $application_id)
			{
				$exists = false;
				foreach ($partials['list'] as $partial)
				{
					if ($partial['id'] == $application_id)
					{
						$bo_block->cancel_block($session_id, $partial['dates'], $partial['resources']);
						$exists = true;
						break;
					}
				}
				if ($exists)
				{
					$sopurchase_order->delete_purchase_order($application_id);
					$soapplication->delete_application($application_id);
					$status['deleted'] = true;
				}
			}
			
			// Update payment status once for all applications
			$soapplication->update_payment_status($remote_order_id, 'voided', $remote_state);

			Db::getInstance()->transaction_commit();
		}

		Cache::message_set('cancelled');
	}

	public function cancel_payment($remote_order_id)
	{
		$soapplication = CreateObject('booking.soapplication');

		$cancel_array = array('CANCEL', 'VOID', 'FAILED', 'REJECTED');

		$approved_array = array('RESERVE', 'RESERVED');

		$data = $this->get_payment_details($remote_order_id);

		/**
		 * Sync with external data
		 */
		if (isset($data['transactionLogHistory'][0]['operation']) && in_array($data['transactionLogHistory'][0]['operation'], $cancel_array))
		{
			$this->cancel_order($remote_order_id, $data['transactionLogHistory'][0]['operation']);
			$soapplication->update_payment_status($remote_order_id, 'voided', $data['transactionLogHistory'][0]['operation']);
			return;
		}

		if (isset($data['transactionLogHistory'][0]['operation']) && $data['transactionLogHistory'][0]['operation'] == 'CAPTURE')
		{
			$soapplication->update_payment_status($remote_order_id, 'completed', 'CAPTURE');
			return;
		}

		$path	 = "/ecomm/v2/payments/{$remote_order_id}/cancel";
		$url	 = "{$this->base_url}{$path}";

		$request = array();
		$this->get_header($request);

		$transaction = [
			"transactionText" => 'Booking i Aktiv kommune',
		];

		$request_body = [
			"merchantInfo"	 => [
				"merchantSerialNumber" => $this->msn,
			],
			"transaction"	 => $transaction
		];

		$request['json'] = $request_body;
		try
		{
			$response	 = $this->client->request('PUT', $url, $request);
			$status_code = $response->getStatusCode(); // 200
			$ret		 = json_decode($response->getBody()->getContents(), true);
		}
		catch (\GuzzleHttp\Exception\BadResponseException $e)
		{
			// handle exception or api errors.
			Cache::message_set($e->getMessage(), 'error');

			if ($this->debug)
			{
				_debug_array($e->getMessage());
			}
		}
		if ($status_code == 200)
		{
			$soapplication->update_payment_status($remote_order_id, 'voided', 'CANCEL');
		}
		return $response;
	}

	private function get_header(&$request)
	{
		$request['headers'] = array(
			'Accept'					 => 'application/json;charset=UTF-8',
			'Authorization'				 => $this->accesstoken,
			'Ocp-Apim-Subscription-Key'	 => $this->subscription_key,
		);

		if ($this->proxy)
		{
			$request['proxy'] = array(
				'http'	 => $this->proxy,
				'https'	 => $this->proxy
			);
		}
	}

	private function authorize_payment($param)
	{
	}

	public function refund_payment($remote_order_id, $amount)
	{
		$soapplication = CreateObject('booking.soapplication');

		$cancel_array = array('CANCEL', 'VOID', 'FAILED', 'REJECTED');

		$data = $this->get_payment_details($remote_order_id);

		/**
		 * Sync with external data
		 */
		if (isset($data['transactionLogHistory'][0]['operation']) && in_array($data['transactionLogHistory'][0]['operation'], $cancel_array))
		{
			$this->cancel_order($remote_order_id, $data['transactionLogHistory'][0]['operation']);
			$soapplication->update_payment_status($remote_order_id, 'voided', $data['transactionLogHistory'][0]['operation']);
			return;
		}

		if (isset($data['transactionLogHistory'][0]['operation']) && $data['transactionLogHistory'][0]['operation'] == 'CAPTURE')
		{
			$soapplication->update_payment_status($remote_order_id, 'completed', 'CAPTURE');
		}

		$path	 = "/ecomm/v2/payments/{$remote_order_id}/refund";
		$url	 = "{$this->base_url}{$path}";

		$request = array();
		$this->get_header($request);

		$transaction = [
			"transactionText" => 'Booking i Aktiv kommune',
		];

		$request_body = [
			"merchantInfo"	 => [
				"amount"				 => $amount,
				"merchantSerialNumber"	 => $this->msn,
			],
			"transaction"	 => $transaction
		];

		$request['json'] = $request_body;
		try
		{
			$response	 = $this->client->request('POST', $url, $request);
			$status_code = $response->getStatusCode(); // 200
			$ret		 = json_decode($response->getBody()->getContents(), true);
		}
		catch (\GuzzleHttp\Exception\BadResponseException $e)
		{
			// handle exception or api errors.
			Cache::message_set($e->getMessage(), 'error');

			if ($this->debug)
			{
				_debug_array($e->getMessage());
			}
		}
		if ($status_code == 200)
		{
			$soapplication->update_payment_status($remote_order_id, 'refunded', 'REFUND', $amount / 100);
		}
		return $ret;
	}

	private function force_approve_payment($param)
	{
	}

	public function check_payment_status($remote_order_id = '')
	{
		$soapplication = CreateObject('booking.soapplication');
		if (!$remote_order_id)
		{
			$remote_order_id = Sanitizer::get_var('payment_order_id');
		}

		static $attempts = 0;

		//		    Start after 3 seconds
		//		    Check every 2 seconds
		$cancel_array = array('CANCEL', 'VOID', 'FAILED', 'REJECTED');

		$approved_array = array('RESERVE', 'RESERVED');

		while ($attempts < 6)
		{
			if (!$attempts)
			{
				sleep(3);
			}
			else
			{
				sleep(2);
			}

			$data = $this->get_payment_details($remote_order_id);

			if (isset($data['transactionLogHistory'][0]['operation']))
			{
				if ($data['transactionLogHistory'][0]['operationSuccess'] && in_array($data['transactionLogHistory'][0]['operation'], $cancel_array))
				{
					$this->cancel_order($remote_order_id, $data['transactionLogHistory'][0]['operation']);
				}
				if ($data['transactionLogHistory'][0]['operationSuccess'] && in_array($data['transactionLogHistory'][0]['operation'], $approved_array))
				{
					$soapplication->update_payment_status($remote_order_id, 'pending', $data['transactionLogHistory'][0]['operation']);

					$capture = $this->capture_payment($remote_order_id, (int)$data['transactionLogHistory'][0]['amount']);
					if ($capture['transactionInfo']['status'] == 'Captured')
					{
						Db::getInstance()->transaction_begin();
						$soapplication->update_payment_status($remote_order_id, 'completed', 'CAPTURE');
						$this->approve_application($remote_order_id, (int)$data['transactionLogHistory'][0]['amount']);
						Db::getInstance()->transaction_commit();
					}
				}

				return $data;
			}

			$attempts++;
		}

		return array(
			'status'	 => 'error',
			'message'	 => 'not found'
		);
	}

	protected function add_comment(&$event, $comment, $type = 'comment')
	{
		$event['comments'][] = array(
			'time' => 'now',
			'author' => 'Vipps',
			'comment' => $comment,
			'type' => $type
		);
	}
	protected function add_cost_history(&$event, $comment = '', $cost = '0.00')
	{
		if (!$comment)
		{
			$comment = lang('cost is set');
		}

		$event['costs'][] = array(
			'time' => 'now',
			'author' => 'Vipps',
			'comment' => $comment,
			'cost' => $cost
		);
	}
	/**
	 *
	 * @param string $remote_order_id
	 * @return boolean
	 */
	private function approve_application($remote_order_id, $amount)
	{
		$_amount = ($amount / 100);
		$boapplication = CreateObject('booking.boapplication');

		$application_ids = $boapplication->so->get_application_from_payment_order($remote_order_id);
		$ret = false;
		
		foreach ($application_ids as $application_id)
		{
			$application = $boapplication->so->read_single($application_id);
			$application['status'] = 'ACCEPTED';
			$receipt = $boapplication->update($application);
			
			$event = $application;
			unset($event['id']);
			unset($event['id_string']);
			$event['application_id'] = $application['id'];
			$event['is_public'] = 0;
			$event['include_in_list'] = 0;
			$event['reminder'] = 0;
			$event['customer_internal'] = 0;
			$event['cost'] = $_amount;
			$event['completed'] = 1; //paid !

			$building_info = $boapplication->so->get_building_info($application['id']);
			$event['building_id'] = $building_info['id'];
			$this->add_comment($event, lang('Event was created'));
			$this->add_cost_history($event, lang('cost is set'), $_amount);

			$booking_boevent = createObject('booking.boevent');
			$errors = array();

			/**
			 * Validate timeslots
			 */
			foreach ($application['dates'] as $checkdate)
			{
				$event['from_'] = $checkdate['from_'];
				$event['to_'] = $checkdate['to_'];
				$errors = array_merge($errors, $booking_boevent->validate($event));
			}
			unset($checkdate);

			if (!$errors)
			{
				$session_id = Sessions::getInstance()->get_session_id();

				CreateObject('booking.souser')->collect_users($application['customer_ssn']);
				$bo_block = createObject('booking.boblock');
				$bo_block->cancel_block($session_id, $application['dates'], $application['resources']);

				/**
				 * Add event for each timeslot
				 */
				foreach ($application['dates'] as $checkdate)
				{
					$event['from_'] = $checkdate['from_'];
					$event['to_'] = $checkdate['to_'];
					$receipt = $booking_boevent->so->add($event);
				}

				$booking_boevent->so->update_id_string();
				createObject('booking.sopurchase_order')->identify_purchase_order($application['id'], $receipt['id'], 'event');

				$boapplication->send_notification($application);
				$ret = true;
			}
		}

		return $ret;
	}

	/**
	 *
	 * @param string $remote_order_id
	 * @return type
	 */
	public function get_payment_details($remote_order_id = '')
	{

		if (!$remote_order_id)
		{
			$remote_order_id = Sanitizer::get_var('payment_order_id');
		}

		$path	 = "/ecomm/v2/payments/{$remote_order_id}/details";
		$url	 = "{$this->base_url}{$path}";
		$request = array();
		$this->get_header($request);

		$request_body = array();

		$request['json'] = $request_body;

		try
		{
			$response	 = $this->client->request('GET', $url, $request);
			$status_code = $response->getStatusCode(); // 200
			$ret		 = json_decode($response->getBody()->getContents(), true);
		}
		catch (\GuzzleHttp\Exception\BadResponseException $e)
		{
			// handle exception or api errors.
			if ($this->debug)
			{
				print_r($e->getMessage());
			}
		}

		return $ret;
	}


	public function postToAccountingSystem()
	{
		$soapplication = CreateObject('booking.soapplication');

		// Hent config for å avgjøre hvilket regnskapssystem som skal brukes
		$location_obj = new Locations();
		$location_id = $location_obj->get_id('booking', 'run');
		$custom_config = CreateObject('admin.soconfig', $location_id);
		$accounting_config = $custom_config->config_data['Accounting'] ?? [];
		$accounting_system = $accounting_config['system'] ?? 'visma_enterprise';

		// Opprett regnskapssystem basert på konfigurasjon
		try
		{
			$accounting = \App\modules\booking\helpers\accounting\AccountingSystemFactory::create(
				$accounting_system,
				$accounting_config,
				$this->debug
			);
		}
		catch (Exception $e)
		{
			if ($this->debug)
			{
				print_r("Failed to initialize accounting system: " . $e->getMessage());
			}
			return;
		}

		// Sjekk om regnskapssystemet er riktig konfigurert
		if (!$accounting->isConfigured())
		{
			if ($this->debug)
			{
				print_r("Accounting system is not properly configured: " . $accounting->getLastError());
			}
			return;
		}

		// Hent alle transaksjoner som ikke er bokført
		$result = $soapplication->get_unposted_transactions();
		$unposted_transactions = $result['results'] ?? [];

		foreach ($unposted_transactions as $transaction)
		{
			$remote_order_id = $transaction['remote_order_id'];
			$amount = $transaction['amount'];
			$description = $transaction['description'];
			$date = $transaction['date'];

			// Hent detaljer om transaksjonen fra Vipps
			$payment_details = $this->get_payment_details($remote_order_id);

			if ($payment_details['transactionInfo']['status'] === 'CAPTURE')
			{
				// Send transaksjonen til regnskapssystemet
				$result = $accounting->postTransaction(
					$amount / 100,
					$description,
					$date,
					$remote_order_id
				);

				if ($result)
				{
					// Oppdater status i databasen for å markere som bokført
					$soapplication->mark_as_posted($remote_order_id);

					if ($this->debug)
					{
						print_r("Successfully posted transaction {$remote_order_id} to accounting system.");
					}
				}
				else
				{
					if ($this->debug)
					{
						print_r("Failed to post transaction {$remote_order_id} to accounting system: " . $accounting->getLastError());
					}
				}
			}
			else
			{
				if ($this->debug)
				{
					print_r("Transaction {$remote_order_id} is not captured. Skipping.");
				}
			}
		}
		// Hent transaksjoner som er markert som refundert men ikke bokført
		$result = $soapplication->get_unposted_refund_transactions();
		$unposted_refunds = $result['results'] ?? [];

		foreach ($unposted_refunds as $refund)
		{
			$remote_order_id = $refund['remote_order_id'];
			$amount = $refund['amount'];
			$description = $refund['description'];
			$date = $refund['date'];
			$original_transaction_id = $refund['original_transaction_id'] ?? null;

			// Send refunderingen til regnskapssystemet
			$result = $accounting->postRefundTransaction(
				$amount / 100,
				$description,
				$date,
				$remote_order_id,
				$original_transaction_id
			);

			if ($result)
			{
				// Oppdater status i databasen for å markere refunderingen som bokført
				$soapplication->mark_refund_as_posted($remote_order_id);

				if ($this->debug)
				{
					print_r("Successfully posted refund for transaction {$remote_order_id} to accounting system.");
				}
			}
			else
			{
				if ($this->debug)
				{
					print_r("Failed to post refund for transaction {$remote_order_id} to accounting system: " . $accounting->getLastError());
				}
			}
		}
		// Etter du har behandlet unposted_refund_transactions
		// Finn betalinger som er bokført, men senere refundert og refunderingen er ikke bokført
		$refunds_needing_posting = $soapplication->get_refunded_posted_payments();

		// Bokfør disse refunderingene
		foreach ($refunds_needing_posting as $refund)
		{
			// Send refunderingen til regnskapssystemet
			$result = $accounting->postRefundTransaction(
				$refund['amount'] / 100,
				$refund['description'],
				$refund['date'],
				$refund['remote_order_id'],
				$refund['original_transaction_id']
			);

			if ($result)
			{
				// Oppdater status i databasen for å markere refunderingen som bokført
				$soapplication->mark_refund_as_posted($refund['remote_order_id']);

				if ($this->debug)
				{
					print_r("Successfully posted refund for transaction {$refund['remote_order_id']} to accounting system.");
				}
			}
			else
			{
				if ($this->debug)
				{
					print_r("Failed to post refund for transaction {$refund['remote_order_id']} to accounting system: " . $accounting->getLastError());
				}
			}
		}
	}
}
