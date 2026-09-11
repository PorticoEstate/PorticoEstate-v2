<?php

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\Log;

class sms_sms extends sms_sms_
{

	var $param;
	function __construct()
	{
		parent::__construct();
		$this->param = $this->sms_config['smsalert'];
	}

	function parse_html($s_str)
	{
		$i_left = 0;
		$i_right = 0;
		$vars = array();
		// Search for a tag in string
		while (is_int(($i_left = strpos($s_str, "<!--", $i_right))))
		{
			$i_left = $i_left + 4;
			$i_right = strpos($s_str, "-->", $i_left);
			$s_temp = substr($s_str, $i_left, ($i_right - $i_left));
			$a_tag = explode('=', $s_temp);
			$vars[strtolower($a_tag[0])] = $a_tag[1];
		}
		return $vars;
	}

	function gw_send_sms($mobile_sender, $sms_sender, $sms_to, $sms_msg, $gp_code = "", $uid = "", $smslog_id = "", $flash = false)
	{
		$debug = empty($this->param['debug']) ? false : true;

		$sms_to = ltrim($sms_to, '+');

		if (strlen($sms_to) < 9)
		{
			$sms_to = "47{$sms_to}";
		}

		$post_data = array(
			'sUser'	=> $this->param['login'],
			'sPass'	=> $this->param['password'],
			'sSM'	=> $sms_msg,
			'sOriginator'	=> (string)$this->sms_config['common']['gateway_number'],
			'sMSISDN'	=> $sms_to,
			'nForeignId' => 0 //$smslog_id
		);

		$url = 'smsalert.no/systorsmsvarious/systorsmsvarious.asmx/SendSM';

		$post_string = http_build_query($post_data);

		$ch = curl_init($url);

		if ($this->param['proxy_host'])
		{
			curl_setopt($ch, CURLOPT_PROXY, "{$this->param['proxy_host']}:{$this->param['proxy_port']}");
		}

		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1)");
		curl_setopt($ch, CURLOPT_POSTFIELDS, $post_string);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$result_xml = curl_exec($ch);

		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);


		try
		{
			$result = new SimpleXMLElement($result_xml);
		}
		catch (Exception $ex)
		{
			$error_message = $ex->getMessage();
			$result = 0;
		}

		if ($debug)
		{
			$log = new Log();

			$logData = $post_data;
			$logData['sPass'] = '[redacted]';
			$log_message = 'SMS gateway debug information:' . PHP_EOL
				. 'data: ' . json_encode($logData) . PHP_EOL
				. 'httpCode: ' . $httpCode . PHP_EOL
				. 'response: ' . $result_xml;
			if (isset($error_message))
			{
				$log_message .= PHP_EOL . 'error_message: ' . $error_message;
			}
			$log->fatal(array(
				'text'	=> $log_message,
				'line'	=> __LINE__,
				'file'	=> __FILE__
			));
		}

		if ($result > 0)
		{
			$this->setsmsdeliverystatus($smslog_id, $uid, 1);
			$ret = true;
		}
		else
		{
			$this->setsmsdeliverystatus($smslog_id, $uid, 2);
			$ret = false;
			throw new Exception('SMSgateway:General error');
		}



		return $ret;
	}

	function gw_set_delivery_status($gp_code = "", $uid = "", $smslog_id = "", $p_datetime = "", $p_update = "")
	{
		return;
		// OK = delivered
		// FAIL = failed


		if ($result['statuscode'] == 'OK')
		{
			$this->setsmsdeliverystatus($smslog_id, $uid, 1);
		}
		else if ($result['statuscode'] == 'FAIL')
		{
			$this->setsmsdeliverystatus($smslog_id, $uid, 2);
		}

		return;
	}
}
