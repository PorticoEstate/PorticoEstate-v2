<?php

use App\modules\phpgwapi\services\Settings;
use App\Database\Db;


class sms_sms_ extends sms_sms__
{
	var $pswin_param, $sms_config, $db;
	function __construct()
	{
		parent::__construct();
		$this->sms_config = Settings::getInstance()->get('sms_config');
		$this->pswin_param = $this->sms_config['pswin'];
		$this->db = Db::getInstance();
	}

	function gw_customcmd()
	{
		// nothing
	}

	function gw_set_delivery_status($gp_code = "", $uid = "", $smslog_id = "", $p_datetime = "", $p_update = "")
	{
		// nothing
	}

	function gw_set_incoming_action()
	{
		$strip_code = isset($this->pswin_param['strip_code']) && $this->pswin_param['strip_code'] ? $this->pswin_param['strip_code'] : '';

		$test_receive = false;

		if ($test_receive)
		{
			$this->test_receive();
		}

		$sql = 'SELECT * FROM phpgw_sms_received_data WHERE status = 0 AND type = \'sms\'';
		$this->db->query($sql, __LINE__, __FILE__);

		$messages = array();
		while ($this->db->next_record())
		{
			$data = unserialize($this->db->f('data', true));
			if (!isset($data->m->Text))
			{
				continue;
			}

			$messages[] = array(
				'id' => $this->db->f('id'),
				'type' => $this->db->f('type'),
				'entry_date' => $this->db->f('entry_date'),
				'data' => $data
			);
		}

		//			_debug_array($messages);

		foreach ($messages as $entry)
		{
			$message = trim($entry['data']->m->Text);
			if ($strip_code && stripos($message, "{$strip_code} ") === 0)
			{
				$strip_code = strtolower($strip_code);
				$strip_code_len = strlen($strip_code);
				$message_len = strlen($message);

				$message = trim(substr($message, $strip_code_len));
			}

			//			_debug_array($message);
			$array_target_code = explode(' ', $message);

			$target_code = strtoupper(trim($array_target_code[0]));

			$message = $array_target_code[1];

			for ($i = 2; $i < count($array_target_code); $i++)
			{
				$message .= " {$array_target_code[$i]}";
			}

			$sms_datetime = date($this->db->datetime_format(), $entry['entry_date']);
			$sms_sender = $entry['data']->m->SenderNumber;

			if (parent::setsmsincomingaction($sms_datetime, $sms_sender, $target_code, $message))
			{
				$sql = 'UPDATE phpgw_sms_received_data SET status = 1 WHERE id =' . (int)$entry['id'];
				//_debug_array($sql);
				$this->db->query($sql, __LINE__, __FILE__);
			}
		}
		//			die();
	}

	function test_receive()
	{
		require_once 'SMSReceive.php';

		$options = array();
		$options['soap_version'] = SOAP_1_2;
		$options['location'] = $this->pswin_param['receive_url'];
		$options['uri'] = "http://localhost/~sn5607/savannah_trunk/sms/inc/plugin/gateway/pswin/soap.php";
		$options['trace'] = 1;
		$options['proxy_host'] = $this->pswin_param['proxy_host'];
		$options['proxy_port'] = $this->pswin_param['proxy_port'];
		$options['encoding'] = 'iso-8859-1'; //'UTF-8';

		$wdsl = PHPGW_SERVER_ROOT . '/sms/inc/plugin/gateway/pswin/Receive.wdsl';

		$receive = new SMSReceive($wdsl, $options);

		$Position = new GSMPosition();
		$Position->City = 'Bergen';
		$IncomingSMSMessage = new IncomingSMSMessage();
		$IncomingSMSMessage->ReceiverNumber = '26112';
		$IncomingSMSMessage->SenderNumber = '90665164';
		$IncomingSMSMessage->Text = 'Dette er en testmelding';
		$IncomingSMSMessage->Network = '';
		$IncomingSMSMessage->Address = 'Firstname;middlename;lastname;address;ZipCode;City;RegionNumber;CountyNumber';
		$IncomingSMSMessage->Position = $Position;


		$ReceiveSMSMessage = new ReceiveSMSMessage();

		$ReceiveSMSMessage->m = $IncomingSMSMessage;

		$ReturnValue = $receive->ReceiveSMSMessage($ReceiveSMSMessage);

		$result = $ReturnValue->ReceiveSMSMessageResult;

		_debug_array($result);
		die();
	}
}
