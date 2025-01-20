<?php

/**
 * phpGroupWare - sms: A SMS Gateway
 *
 * @author Sigurd Nes <sigurdne@online.no>
 * @copyright Copyright (C) 2003-2005 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @internal Development of this application was funded by http://www.bergen.kommune.no/bbb_/ekstern/
 * @package sms
 * @subpackage sms
 * @version $Id$
 */
/**
 * Description
 * @package sms
 */

use App\Database\Db;
use App\Database\Db2;
use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\controllers\Accounts\Accounts;
use App\modules\phpgwapi\controllers\Locations;


$apps_path['base'] = PHPGW_SERVER_ROOT . '/sms';
$apps_path['libs'] = $apps_path['base'] . '/lib';
$apps_path['plug'] = $apps_path['base'] . '/inc/plugin';
$apps_path['inc'] = $apps_path['base'] . '/inc';

$userSettings = Settings::getInstance()->get('user');

$sms_config = array();

// SMS command security parameter
$feat_command_path['bin'] = "{$apps_path['base']}/bin/{$userSettings['domain']}";

$sms_config['common']['apps_path'] = $apps_path;
$sms_config['common']['feat_command_path'] = $feat_command_path;

$location_obj = new Locations();
$location_id = $location_obj->get_id('sms', 'run');
$sms_config = CreateObject('admin.soconfig', $location_id)->read();
$sms_config['common']['apps_path'] = $apps_path;
$sms_config['common']['feat_command_path'] = $feat_command_path;

$reserved_codes = array(
	"PV",
	"BC",
	"GET",
	"PUT",
	"INFO",
	"SAVE",
	"DEL",
	"LIST",
	"RETR",
	"POP3",
	"SMTP",
	"BROWSE",
	"NEW",
	"SET",
	"POLL",
	"VOTE",
	"REGISTER",
	"REG",
	"DO",
	"USE",
	"EXECUTE",
	"EXEC",
	"RUN",
	"ACK"
);


$sms_config['reserved_codes'] = $reserved_codes;
Settings::getInstance()->set('sms_config', $sms_config);

if ($sms_config['common']['gateway_module_get'] && !$sms_config['common']['gateway_module_send'])
{
	$sms_config['common']['gateway_module_send'] = $sms_config['common']['gateway_module_get'];
}

if (file_exists("{$apps_path['inc']}/plugin/gateway/{$sms_config['common']['gateway_module_get']}/get.php") && file_exists("{$apps_path['inc']}/plugin/gateway/{$sms_config['common']['gateway_module_send']}/send.php"))
{
	require "{$apps_path['inc']}/plugin/gateway/{$sms_config['common']['gateway_module_get']}/get.php";
	require "{$apps_path['inc']}/plugin/gateway/{$sms_config['common']['gateway_module_send']}/send.php";
}
else
{
	if ($sms_config['common']['gateway_module_get'] && !file_exists("{$apps_path['inc']}/plugin/gateway/{$sms_config['common']['gateway_module_get']}/get.php"))
	{
		die("ERROR: Gateway get module '" . $sms_config['common']['gateway_module_get'] . "' does not exists - please contact system administrator");
	}
	else if ($sms_config['common']['gateway_module_send'] && !file_exists("{$apps_path['inc']}/plugin/gateway/{$sms_config['common']['gateway_module_send']}/send.php"))
	{
		throw new Exception("ERROR: Gateway send module '" . $sms_config['common']['gateway_module_send'] . "' does not exists - please contact system administrator");
		//			die("ERROR: Gateway send module '" . $sms_config['common']['gateway_module_send'] . "' does not exists - please contact system administrator");
	}
	else
	{
		die("ERROR: No selected gateway module available - please contact system administrator");
	}
}

class sms_sms__
{

	var $apps_path;
	var $generic_config;
	protected $global_lock	 = false;
	var $apps_config, $feat_command_path, $gateway_module_get, $gateway_module_send, $gateway_number, $web_title,
		$email_service, $email_footer, $reserved_codes, $db, $db2, $init, $like, $dateformat, $datetimeformat, $time_format, $account,
		$userSettings, $serverSettings, $phpgwapi_common;

	function __construct()
	{
		$sms_config = Settings::getInstance()->get('sms_config');
		$this->apps_config = $sms_config['common']['apps_config'];
		$this->apps_path = $sms_config['common']['apps_path'];
		$this->feat_command_path = $sms_config['common']['feat_command_path'];
		$this->gateway_module_get = $sms_config['common']['gateway_module_get'];
		$this->gateway_module_send = $sms_config['common']['gateway_module_send'];
		$this->gateway_number = $sms_config['common']['gateway_number'];
		$this->web_title = $sms_config['common']['web_title'];
		$this->email_service = $sms_config['common']['email_service'];
		$this->email_footer = $sms_config['common']['email_footer'];
		$this->reserved_codes = $sms_config['reserved_codes'];

		$this->userSettings = Settings::getInstance()->get('user');
		$this->serverSettings = Settings::getInstance()->get('server');
		$this->phpgwapi_common = new \phpgwapi_common();

		$this->db =	new Db2();
		$this->db2 = new Db2();
		$this->init = true;
		$this->like = $this->db->like;

		switch ($this->serverSettings['db_type'])
		{
			case 'mssqlnative':
			case 'mssql':
				$this->dateformat = "M d Y";
				$this->datetimeformat = "M d Y g:iA";
				$this->time_format = "g:iA";

				break;
			case 'mysql':
				$this->dateformat = "Y-m-d";
				$this->datetimeformat = "Y-m-d G:i:s";
				$this->time_format = "G:i:s";

				break;
			case 'pgsql':
				$this->dateformat = "Y-m-d";
				$this->datetimeformat = "Y-m-d G:i:s";
				$this->time_format = "G:i:s";
				break;
			case 'postgres':
				$this->dateformat = "Y-m-d";
				$this->datetimeformat = "Y-m-d G:i:s";
				$this->time_format = "G:i:s";
				break;
		}

		$this->account = $this->userSettings['account_id'];
		$this->generic_config = CreateObject('phpgwapi.config', 'sms')->read();
	}

	function datetime_now()
	{
		return date($this->datetimeformat, time());
	}

	function gpcode2gpid($uid, $gp_code)
	{
		if ($uid && $gp_code)
		{
			$db_query = "SELECT gpid FROM phpgw_sms_tblUserGroupPhonebook WHERE uid='{$uid}' AND gp_code='{$gp_code}'";
			$this->db->query($db_query);
			$this->db->next_record();
			$gpid = $this->db->f('gpid');
		}
		return $gpid;
	}

	function username2mobile($username)
	{

		$mobile = $this->userSettings['preferences']['common']['cellphone'];
		return $mobile;
	}

	function username2sender($username)
	{
		if ($this->userSettings['preferences']['sms']['signature'])
		{
			$sender = ' - ' . $this->userSettings['preferences']['sms']['signature'];
		}
		return $sender;
	}

	function username2name($username)
	{
		$name = $this->userSettings['fullname'];
		return $name;
	}

	function sendmail($mail_from, $mail_to, $subject = "", $mail_body = "")
	{
		$send = CreateObject('phpgwapi.send');
		$rcpt = $send->msg('email', $mail_to, $subject, stripslashes($mail_body), '', $cc, $bcc, $mail_from, $from_name, 'plain');

		return $rcpt;
	}

	function websend2pv($username, $sms_to, $message, $sms_type = "text", $unicode = "0")
	{
		$datetime_now = $this->datetime_now();
		$gateway_module_send = $this->gateway_module_send;
		$uid = (int)$this->account;

		$mobile_sender = $this->username2mobile($username);
		$max_length = 804;
		if ($sms_sender = $this->username2sender($username))
		{
			$max_length = $max_length - strlen($sms_sender) - 1;
		}
		if (strlen($message) > $max_length)
		{
			$message = substr($message, 0, $max_length - 1);
		}
		//			$sms_msg = str_replace(array("\r","\n", "\""), array("", "", " "), html_entity_decode($message));
		$sms_msg = str_replace(array("\r", "\""), array("", " "), html_entity_decode($message));
		$mobile_sender = str_replace(array("\'", "\""), array("", ""), $mobile_sender);
		$sms_sender = str_replace(array("\'", "\""), array("", ""), $sms_sender);
		if (is_array($sms_to))
		{
			$array_sms_to = $sms_to;
		}
		else
		{
			$array_sms_to[0] = $sms_to;
		}

		for ($i = 0; $i < count($array_sms_to); $i++)
		{
			$c_sms_to = str_replace(array("\'", "\"", " "), array("", "", ""), $array_sms_to[$i]);
			$message = $this->db->db_addslashes($message);

			$db_query = "INSERT INTO phpgw_sms_tblsmsoutgoing
					(uid,p_gateway,p_src,p_dst,p_footer,p_msg,p_datetime,p_sms_type,unicode)
					VALUES ('$uid','$gateway_module_send','$mobile_sender','$c_sms_to','$sms_sender','$message','$datetime_now','$sms_type','$unicode')";

			if ($this->db->get_transaction())
			{
				$this->global_lock = true;
			}
			else
			{
				$this->db->transaction_begin();
			}

			$this->db->query($db_query, __LINE__, __FILE__);
			$smslog_id = $this->db->get_last_insert_id('phpgw_sms_tblsmsoutgoing', 'smslog_id');

			$gp_code = "PV";
			$to[$i] = $c_sms_to;
			$ok[$i] = 0;
			if ($smslog_id)
			{
				try
				{
					if ($this->gw_send_sms($mobile_sender, $sms_sender, $c_sms_to, $sms_msg, $gp_code, $uid, $smslog_id, $sms_type, $unicode))
					{
						$ok[$i] = $smslog_id;
					}
				}
				catch (Exception $ex)
				{
					if (!$this->global_lock)
					{
						$this->db->transaction_abort();
					}
					throw $ex;
				}
			}

			if (!$this->global_lock)
			{
				$this->db->transaction_commit();
			}
		}

		return array($ok, $to);
	}

	function websend2group($username, $gp_code, $message, $sms_type = "text")
	{
		$datetime_now = $this->datetime_now();
		$gateway_module_send = $this->gateway_module_send;
		$accounts_obj = new Accounts();
		$uid = $accounts_obj->name2id($username);
		$mobile_sender = $this->username2mobile($username);
		$max_length = 804;
		if ($sms_sender = $this->username2sender($username))
		{
			$max_length = $max_length - strlen($sms_sender) - 1;
		}
		if (strlen($message) > $max_length)
		{
			$message = substr($message, 0, $max_length - 1);
		}
		if (is_array($gp_code))
		{
			$array_gp_code = $gp_code;
		}
		else
		{
			$array_gp_code[0] = $gp_code;
		}
		$j = 0;
		for ($i = 0; $i < count($array_gp_code); $i++)
		{
			$c_gp_code = strtoupper($array_gp_code[$i]);
			$gpid = gpcode2gpid($uid, $c_gp_code);
			$db_query = "SELECT * FROM phpgw_sms_tblUserPhonebook WHERE gpid='$gpid'";
			$db_result = $this->db->query($db_query);
			while ($this->db->next_record())
			{
				$p_num = $this->db->f('p_num');
				$sms_to = $p_num;
				$sms_msg = $message;
				$sms_msg = str_replace("\r", "", $sms_msg);
				$sms_msg = str_replace("\n", "", $sms_msg);
				$sms_msg = str_replace("\"", " ", $sms_msg);
				$mobile_sender = str_replace("\'", "", $mobile_sender);
				$mobile_sender = str_replace("\"", "", $mobile_sender);
				$sms_sender = str_replace("\'", "", $sms_sender);
				$sms_sender = str_replace("\"", "", $sms_sender);
				$sms_to = str_replace("\'", "", $sms_to);
				$sms_to = str_replace("\"", "", $sms_to);
				$the_msg = "$sms_to\n$sms_msg";
				$db_query1 = "
					INSERT INTO phpgw_sms_tblsmsoutgoing
					(uid,p_gateway,p_src,p_dst,p_footer,p_msg,p_datetime,p_gpid,p_sms_type)
					VALUES ('$uid','$gateway_module_send','$mobile_sender','$sms_to','$sms_sender','$message','$datetime_now','$gpid','$sms_type')
					";
				$this->db2->query($db_query1);
				$smslog_id = $this->db2->get_last_insert_id('phpgw_sms_tblsmsoutgoing');
				$to[$j] = $sms_to;
				$ok[$j] = 0;
				if ($smslog_id)
				{
					if ($this->gw_send_sms($mobile_sender, $sms_sender, $sms_to, $sms_msg, $c_gp_code, $uid, $smslog_id, $sms_type, $unicode))
					{
						$ok[$j] = $sms_to;
					}
				}
				$j++;
			}
		}
		return array($ok, $to);
	}

	function send2group($mobile_sender, $gp_code, $message)
	{
		$datetime_now = $this->datetime_now();
		$ok = false;
		if ($mobile_sender && $gp_code && $message)
		{
			$db_query = "SELECT uid,username,sender FROM phpgw_sms_tblUser WHERE mobile='$mobile_sender'";
			$this->db->query($db_query);
			$this->db->next_record();
			$uid = $this->db->f('uid');
			$username = $this->db->f('username');
			$sms_sender = $this->db->f('sender');
			if ($uid && $username)
			{
				$gp_code = strtoupper($gp_code);
				$db_query = "SELECT * FROM phpgw_sms_tblUserGroupPhonebook WHERE uid='$uid' AND gp_code='$gp_code'";
				$this->db->query($db_query);
				$this->db->next_record();
				$gpid = $this->db->f('gpid');
				if ($gpid && $message)
				{
					$db_query = "SELECT * FROM phpgw_sms_tblUserPhonebook WHERE gpid='$gpid' AND uid='$uid'";
					$this->db->query($db_query);
					while ($this->db->next_record())
					{
						$p_num = $this->db->f('p_num');
						$sms_to = $p_num;
						$max_length = 804 - strlen($sms_sender) - 3;
						if (strlen($message) > $max_length)
						{
							$message = substr($message, 0, $max_length - 1);
						}
						$sms_msg = $message;
						$sms_msg = str_replace("\r", "", $sms_msg);
						$sms_msg = str_replace("\n", "", $sms_msg);
						$sms_msg = str_replace("\"", " ", $sms_msg);
						$the_msg = "$sms_to\n$sms_msg";
						$mobile_sender = str_replace("\'", "", $mobile_sender);
						$mobile_sender = str_replace("\"", "", $mobile_sender);
						$sms_sender = str_replace("\'", "", $sms_sender);
						$sms_sender = str_replace("\"", "", $sms_sender);
						$sms_to = str_replace("\'", "", $sms_to);
						$sms_to = str_replace("\"", "", $sms_to);
						$send_code = md5(time() . $sms_to);
						$db_query1 = "
							INSERT INTO phpgw_sms_tblsmsoutgoing (uid,p_src,p_dst,p_footer,p_msg,p_datetime,p_gpid)
							VALUES ('$uid','$mobile_sender','$sms_to','$sms_sender','$message','$datetime_now','$gpid')";
						$this->db2->query($db_query1);
						$smslog_id = $this->db2->get_last_insert_id('phpgw_sms_tblsmsoutgoing');
						$sms_id = "$gp_code.$uid.$smslog_id";
						if ($smslog_id)
						{
							if ($this->gw_send_sms($mobile_sender, $sms_sender, $sms_to, $sms_msg, $gp_code, $uid, $smslog_id))
							{
								$ok = true;
							}
						}
					}
				}
			}
		}
		return $ok;
	}

	function insertsmstodb($sms_datetime, $sms_sender, $target_code, $message)
	{
		$email_footer = $this->email_footer;
		$email_service = $this->email_service;
		$web_title = $this->web_title;
		$gateway_module_get = $this->gateway_module_get;

		$ok = false;
		if ($sms_sender && $target_code && $message)
		{
			// masked sender sets here
			$masked_sender = substr_replace($sms_sender, 'xxxx', -4);
			$sql = "
					INSERT INTO phpgw_sms_tblsmsincoming
					(in_gateway,in_sender,in_masked,in_code,in_msg,in_datetime)
					VALUES ('$gateway_module_get','$sms_sender','$masked_sender','$target_code','$message','$sms_datetime')
					";
			$this->db->query($sql, __LINE__, __FILE__);

			if ($cek_ok = $this->db->get_last_insert_id('phpgw_sms_tblsmsincoming', 'in_id'))
			{
				$db_query1 = "SELECT board_forward_email FROM phpgw_sms_featboard WHERE board_code='$target_code'";
				$this->db->query($db_query1, __LINE__, __FILE__);
				$this->db->next_record();
				$email = $this->db->f('board_forward_email');
				if ($email)
				{
					$subject = "[SMSGW-$target_code] from $sms_sender";
					$body = "Forward WebSMS ($web_title)\n\n";
					$body .= "Date Time: $sms_datetime\n";
					$body .= "Sender: $sms_sender\n";
					$body .= "Code: $target_code\n\n";
					$body .= "Message:\n$message\n\n";
					$body .= $email_footer . "\n\n";
					$this->sendmail($email_service, $email, $subject, $body);
				}
				$ok = true;
			}
		}
		return $ok;
	}

	function insertsmstoinbox($sms_datetime, $sms_sender, $target_user, $message)
	{
		$email_footer = $this->email_footer;
		$email_service = $this->email_service;
		$web_title = $this->web_title;
		$ok = false;

		$accounts_obj = new Accounts();

		if ($sms_sender && $target_user && $message)
		{
			//	$db_query = "SELECT uid,email,mobile FROM phpgw_sms_tblUser WHERE username='$target_user'";
			$uid = $accounts_obj->name2id($target_user);
			if (!$uid)
			{
				//Try lowercase
				if ($target_user == 'Admins')
				{
					$uid = $accounts_obj->name2id('admins');
				}
			}
			$mobile = $this->userSettings['preferences']['common']['cellphone'];
			$email = $this->userSettings['preferences']['email']['address'];

			if ($uid)
			{
				$message = $this->db->db_addslashes(ucfirst(strtolower($message)));

				$db_query = "INSERT INTO phpgw_sms_tbluserinbox (in_sender,in_uid,in_msg,in_datetime) VALUES ('$sms_sender',$uid,'$message','$sms_datetime')";

				$this->db->query($db_query, __LINE__, __FILE__);

				if ($cek_ok = $this->db->get_last_insert_id('phpgw_sms_tbluserinbox', 'in_id'))
				{
					if ($email)
					{
						$subject = "[SMSGW-PV] from $sms_sender";
						$body = "Forward Private WebSMS ($web_title)\n\n";
						$body .= "Date Time: $sms_datetime\n";
						$body .= "Sender: $sms_sender\n";
						$body .= "Receiver: $mobile\n\n";
						$body .= "Message:\n$message\n\n";
						$body .= $email_footer . "\n\n";
						//				$this->sendmail($email_service,$email,$subject,$body);
					}
					$ok = true;
				}
			}
		}
		return $ok;
	}

	function getsmsinbox($debug = false)
	{
		$ReturnValue = $this->gw_set_incoming_action();
		if ($debug)
		{
			_debug_array($ReturnValue);
		}
	}

	function getsmsstatus()
	{
		$gateway_module_send = $this->gateway_module_send;
		$db_query = "SELECT * FROM phpgw_sms_tblsmsoutgoing WHERE p_status='0' AND p_gateway='$gateway_module_send'";
		$this->db->query($db_query, __LINE__, __FILE__);
		$accounts_obj = new Accounts();
		while ($this->db->next_record())
		{
			$gpid = "";
			$gp_code = "";
			$uid = $this->db->f('uid');
			$smslog_id = $this->db->f('smslog_id');
			$p_datetime = $this->db->f('p_datetime');
			$p_update = $this->db->f('p_update');
			$gpid = $this->db->f('p_gpid');
			//	$gp_code		= gpid2gpcode($gpid);
			$external_id = $this->db->f('external_id');
			if ($gpid)
			{
				$gp_code = $accounts_obj->name2id($gpid);
			}
			$this->gw_set_delivery_status($gp_code, $uid, $smslog_id, $p_datetime, $p_update, $external_id);
		}
	}
	/* 		function execgwcustomcmd()
		  {
		  if (function_exists("gw_customcmd"))
		  {
		  gw_customcmd();
		  }
		  }

		  function execcommoncustomcmd($DAEMON_COUNTER = '')
		  {
		  @include $this->apps_path[inc]."/admin/commoncustomcmd.php";
		  }

		 */

	function setsmsdeliverystatus($smslog_id, $uid, $p_status, $external_id = 0)
	{
		$external_id = (int)$external_id;
		$datetime_now = $this->datetime_now();
		$db_query = "UPDATE phpgw_sms_tblsmsoutgoing SET p_update='{$datetime_now}',p_status='{$p_status}', external_id = {$external_id} WHERE smslog_id='$smslog_id' AND uid='$uid'";
		$ok = $this->db->query($db_query, __LINE__, __FILE__);
		return $ok;
	}

	function checkavailablecode($code)
	{
		$reserved_codes = $this->reserved_codes;
		$ok = true;
		$reserved = false;
		for ($i = 0; $i < count($reserved_codes); $i++)
		{
			if ($code == $reserved_codes[$i])
			{
				$reserved = true;
			}
		}
		if ($reserved)
		{
			$ok = false;
		}
		else
		{
			// check for SMS autoreply
			$db_query = "SELECT autoreply_id FROM phpgw_sms_featautoreply WHERE autoreply_code='$code'";
			$this->db->query($db_query, __LINE__, __FILE__);
			if ($this->db->next_record())
			{
				$ok = false;
			}
			// check for SMS board
			$db_query = "SELECT board_id FROM phpgw_sms_featboard WHERE board_code='$code'";
			$this->db->query($db_query, __LINE__, __FILE__);
			if ($this->db->next_record())
			{
				$ok = false;
			}
			// check for SMS command
			$db_query = "SELECT command_id FROM phpgw_sms_featcommand WHERE command_code='$code'";
			$this->db->query($db_query, __LINE__, __FILE__);
			if ($this->db->next_record())
			{
				$ok = false;
			}
			// check for SMS custom
			$db_query = "SELECT custom_id FROM phpgw_sms_featcustom WHERE custom_code='$code'";
			$this->db->query($db_query, __LINE__, __FILE__);
			if ($this->db->next_record())
			{
				$ok = false;
			}
			// check for SMS poll
			$db_query = "SELECT poll_id FROM phpgw_sms_featpoll WHERE poll_code='$code'";
			$this->db->query($db_query, __LINE__, __FILE__);
			if ($this->db->next_record())
			{
				$ok = false;
			}
		}
		return $ok;
	}

	// part of SMS board
	function outputtorss($code, $line = "10")
	{
		$web_title = $this->web_title;
		include_once "$this->apps_path[inc]/feedcreator.class.php";
		$code = strtoupper($code);
		if (!$line)
		{
			$line = "10";
		};

		$format_output = "RSS0.91";
		$rss = new UniversalFeedCreator();
		$db_query1 = "SELECT * FROM phpgw_sms_tblsmsincoming WHERE in_code='$code' ORDER BY in_datetime DESC LIMIT 0,$line";
		$this->db->query($db_query1, __LINE__, __FILE__);
		while ($this->db->next_record())
		{
			$title = $this->db->f('in_masked');
			$description = $this->db->f('in_msg');
			$datetime = $this->db->f('in_datetime');
			$items = new FeedItem();
			$items->title = $title;
			$items->description = $description;
			$items->comments = $datetime;
			$items->date = strtotime($datetime);
			$rss->addItem($items);
		}
		$feeds = $rss->createFeed($format_output);
		return $feeds;
	}

	// part of SMS board
	function outputtohtml($code, $line = "10", $pref_bodybgcolor = "#E0D0C0", $pref_oddbgcolor = "#EEDDCC", $pref_evenbgcolor = "#FFEEDD")
	{
		$web_title = $this->web_title;
		$code = strtoupper($code);
		if (!$line)
		{
			$line = "10";
		};
		if (!$pref_bodybgcolor)
		{
			$pref_bodybgcolor = "#E0D0C0";
		}
		if (!$pref_oddbgcolor)
		{
			$pref_oddbgcolor = "#EEDDCC";
		}
		if (!$pref_evenbgcolor)
		{
			$pref_evenbgcolor = "#FFEEDD";
		}
		$sql = "SELECT board_pref_template FROM phpgw_sms_featboard WHERE board_code='$code'";
		$this->db->query($sql, __LINE__, __FILE__);
		if ($this->db->next_record())
		{
			$template = $this->db->f('board_pref_template');
			$sql = "SELECT * FROM phpgw_sms_tblsmsincoming WHERE in_code='$code' ORDER BY in_datetime DESC";
			$this->db->query($sql, __LINE__, __FILE__);

			//				$content = "<html>\n<head>\n<title>$web_title - Code: $code</title>\n<meta name=\"author\" content=\"http://playsms.sourceforge.net\">\n</head>\n<body bgcolor=\"$pref_bodybgcolor\" topmargin=\"0\" leftmargin=\"0\">\n<table width=100% cellpadding=2 cellspacing=2>\n";
			$content = "<table width=100% cellpadding=2 cellspacing=2>\n";
			$i = 0;
			while ($this->db->next_record())
			{
				$i++;
				$sender = $this->db->f('in_masked');
				$datetime = $this->db->f('in_datetime');
				$message = $this->db->f('in_msg');
				$tmp_template = $template;
				$tmp_template = str_replace("##SENDER##", $sender, $tmp_template);
				$tmp_template = str_replace("##DATETIME##", $datetime, $tmp_template);
				$tmp_template = str_replace("##MESSAGE##", $message, $tmp_template);
				if (($i % 2) == 0)
				{
					$pref_zigzagcolor = "$pref_evenbgcolor";
				}
				else
				{
					$pref_zigzagcolor = "$pref_oddbgcolor";
				}
				$content .= "\n<tr><td width=100% bgcolor=\"$pref_zigzagcolor\">\n$tmp_template</td></tr>\n\n";
			}
			//				$content .= "</table>\n</body>\n</html>\n";
			$content .= "</table>\n";
			return $content;
		}
	}

	// part of SMS command
	function execcommand($sms_datetime, $sms_sender, $command_code, $command_param)
	{
		$datetime_now = $this->datetime_now();
		$ok = false;
		$sql = "SELECT command_exec, command_type 
        FROM phpgw_sms_featcommand 
        WHERE LOWER(command_code) = LOWER('{$command_code}')";
		$this->db->query($sql, __LINE__, __FILE__);
		$this->db->next_record();
		$command_exec = $this->db->f('command_exec');
		$command_type = $this->db->f('command_type');

		if ($command_type == 'php')
		{
			include(PHPGW_SERVER_ROOT . "/sms/bin/{$this->userSettings['domain']}/" . basename($command_exec));
		}
		else
		{
			$command_exec = str_replace("##SMSDATETIME##", "$sms_datetime", $command_exec);
			$command_exec = str_replace("##SMSSENDER##", "$sms_sender", $command_exec);
			$command_exec = str_replace("##COMMANDCODE##", "$command_code", $command_exec);
			$command_exec = str_replace("##COMMANDPARAM##", "$command_param", $command_exec);
			$command_output = shell_exec(stripslashes($command_exec));
		}
		$sql = "
			INSERT INTO phpgw_sms_featcommand_log
			(sms_sender,command_log_datetime,command_log_code,command_log_exec,command_log_param,command_log_success)
			VALUES
			('$sms_sender','$datetime_now','$command_code','$command_exec','$command_param','" . (int)!!$command_output . "')
			";

		$this->db->transaction_begin();
		$this->db->query($sql, __LINE__, __FILE__);
		$new_id = $this->db->get_last_insert_id('phpgw_sms_featcommand_log', 'command_log_id');
		$this->db->transaction_commit();
		if ($new_id)
		{
			$ok = true;
		}
		return $ok;
	}

	// part of SMS custom
	function processcustom($sms_datetime, $sms_sender, $custom_code, $custom_param)
	{
		$datetime_now = $this->datetime_now();
		$ok = false;
		$sql = "SELECT custom_url FROM phpgw_sms_featcustom WHERE custom_code='$custom_code'";
		$this->db->query($sql, __LINE__, __FILE__);
		$this->db->next_record();
		$custom_url = $this->db->f('custom_url');
		$custom_url = str_replace("##SMSDATETIME##", urlencode($sms_datetime), $custom_url);
		$custom_url = str_replace("##SMSSENDER##", urlencode($sms_sender), $custom_url);
		$custom_url = str_replace("##CUSTOMCODE##", urlencode($custom_code), $custom_url);
		$custom_url = str_replace("##CUSTOMPARAM##", urlencode($custom_param), $custom_url);
		$url = parse_url($custom_url);
		if (!$url['port'])
		{
			$url['port'] = 80;
		}
		$connection = fsockopen($url['host'], $url['port'], $error_number, $error_description, 60);
		if ($connection)
		{
			socket_set_blocking($connection, false);
			fputs($connection, "GET $custom_url HTTP/1.0\r\n\r\n");
			$sql = "
				INSERT INTO phpgw_sms_featcustom_log
				(sms_sender,custom_log_datetime,custom_log_code,custom_log_url)
				VALUES
				('$sms_sender','$datetime_now','$custom_code','$custom_url')
				";
			$this->db->transaction_begin();
			$this->db->query($sql, __LINE__, __FILE__);
			$new_id = $this->db->get_last_insert_id('phpgw_sms_featcustom_log', 'custom_log_id');
			$this->db->transaction_commit();
			if ($new_id)
			{
				$ok = true;
			}
		}
		return $ok;
	}

	// part of SMS autoreply
	function processautoreply($sms_datetime, $sms_sender, $autoreply_code, $autoreply_param)
	{
		$datetime_now = $this->datetime_now();
		$ok = false;
		$autoreply_request = $autoreply_code . " " . $autoreply_param;
		$array_autoreply_request = explode(" ", $autoreply_request);
		$tmp_autoreply_request = '';
		for ($i = 0; $i < count($array_autoreply_request); $i++)
		{
			$autoreply_part[$i] = trim($array_autoreply_request[$i]);
			$tmp_autoreply_request .= $array_autoreply_request[$i] . " ";
		}
		$autoreply_request = trim($tmp_autoreply_request);
		$autoreply_scenario_param_list = '';
		for ($i = 1; $i < 8; $i++)
		{
			$autoreply_scenario_param_list .= "autoreply_scenario_param$i='" . $autoreply_part[$i] . "' AND ";
		}
		$sql = "
			SELECT autoreply_scenario_result FROM phpgw_sms_featautoreply_scenario
			WHERE $autoreply_scenario_param_list 1=1
			";
		$this->db->query($sql, __LINE__, __FILE__);
		$this->db->next_record();

		if ($autoreply_scenario_result = $this->db->f('autoreply_scenario_result'))
		{
			$sql = "
				INSERT INTO phpgw_sms_featautoreply_log
				(sms_sender,autoreply_log_datetime,autoreply_log_code,autoreply_log_request)
				VALUES
				('$sms_sender','$datetime_now','$autoreply_code','$autoreply_request')
				";
			$this->db->query($sql, __LINE__, __FILE__);
			$new_id = $this->db->get_last_insert_id('phpgw_sms_featautoreply_log', 'autoreply_log_id');
			$this->db->transaction_commit();
			if ($new_id)
			{
				$ok = true;
			}
		}

		if ($ok)
		{
			$ok = false;
			$sql = "SELECT uid FROM phpgw_sms_featautoreply WHERE autoreply_code='$autoreply_code'";
			$this->db->query($sql, __LINE__, __FILE__);
			$this->db->next_record();

			$c_uid = $this->db->f('uid');
			$accounts_obj = new Accounts();
			$c_username = $accounts_obj->id2name($c_uid);
			$smslog_id = $this->websend2pv($c_username, $sms_sender, $autoreply_scenario_result);
			if ($smslog_id)
			{
				$ok = true;
			}
		}
		return $ok;
	}

	// part of SMS poll
	function savepoll($sms_sender, $target_poll, $target_choice)
	{
		$ok = false;
		$target_poll = strtoupper($target_poll);
		$target_choice = strtoupper($target_choice);
		if ($sms_sender && $target_poll && $target_choice)
		{
			$sql = "SELECT poll_id,poll_enable FROM phpgw_sms_featpoll WHERE poll_code='$target_poll'";
			$this->db->query($sql, __LINE__, __FILE__);
			$this->db->next_record();

			$poll_id = $this->db->f('poll_id');
			$poll_enable = $this->db->f('poll_enable');
			$sql = "SELECT choice_id FROM phpgw_sms_featpoll_choice WHERE choice_code='$target_choice' AND poll_id='$poll_id'";
			$this->db->query($sql, __LINE__, __FILE__);
			$this->db->next_record();
			$choice_id = $this->db->f('choice_id');
			if ($poll_id && $choice_id)
			{
				$sql = "SELECT result_id FROM phpgw_sms_featpoll_result WHERE poll_sender='$sms_sender' AND poll_id='$poll_id'";
				$this->db->query($sql, __LINE__, __FILE__);

				if ($this->db->num_rows() > 0)
				{
					$already_vote = true;
				}
				if ((!$already_vote) && $poll_enable)
				{
					$sql = "
							INSERT INTO phpgw_sms_featpoll_result
							(poll_id,choice_id,poll_sender)
							VALUES ('$poll_id','$choice_id','$sms_sender')
							";
					$this->db->query($sql, __LINE__, __FILE__);
				}
				$ok = true;
			}
		}
		return $ok;
	}

	// check incoming SMS for available codes
	// and sets the action
	function setsmsincomingaction($sms_datetime, $sms_sender, $target_code, $message)
	{
		if (!strtotime($sms_datetime))
		{
			$sms_datetime = $this->datetime_now();
		}

		$target_code	 = mb_strtoupper(Sanitizer::clean_value($target_code));
		$message		 = Sanitizer::clean_value($message);

		$ok = false;
		switch ($target_code)
		{
			case "BC":
				$array_target_group = explode(" ", $message);
				$target_group = strtoupper(trim($array_target_group[0]));
				$message = $array_target_group[1];
				for ($i = 2; $i < count($array_target_group); $i++)
				{
					$message .= " " . $array_target_group[$i];
				}
				if ($this->send2group($sms_sender, $target_group, $message))
				{
					$ok = true;
				}
				break;
			case "PV":
				$array_target_user = explode(" ", $message);
				$target_user = strtoupper(trim($array_target_user[0]));
				$message = $array_target_user[1];
				for ($i = 2; $i < count($array_target_user); $i++)
				{
					$message .= " " . $array_target_user[$i];
				}
				if ($this->insertsmstoinbox($sms_datetime, $sms_sender, $target_user, $message))
				{
					$ok = true;
				}
				break;
			default:
				// maybe its for sms autoreply
				$db_query = "SELECT autoreply_id FROM phpgw_sms_featautoreply WHERE autoreply_code='$target_code'";
				$this->db->query($db_query, __LINE__, __FILE__);
				if ($this->db->next_record())
				{
					if ($this->processautoreply($sms_datetime, $sms_sender, $target_code, $message))
					{
						$ok = true;
					}
				}
				// maybe its for sms poll
				$db_query = "SELECT poll_id FROM phpgw_sms_featpoll WHERE poll_code='$target_code'";
				$this->db->query($db_query, __LINE__, __FILE__);
				if ($this->db->next_record())
				{
					if ($this->savepoll($sms_sender, $target_code, $message))
					{
						$ok = true;
					}
				}
				// or maybe its for sms command
				$db_query = "SELECT command_id FROM phpgw_sms_featcommand WHERE command_code='$target_code'";
				$this->db->query($db_query, __LINE__, __FILE__);
				if ($this->db->next_record())
				{
					if ($this->execcommand($sms_datetime, $sms_sender, $target_code, $message))
					{
						$ok = true;
					}
				}
				// or maybe its for sms custom
				$db_query = "SELECT custom_id FROM phpgw_sms_featcustom WHERE custom_code='$target_code'";
				$this->db->query($db_query, __LINE__, __FILE__);
				if ($this->db->next_record())
				{
					if ($this->processcustom($sms_datetime, $sms_sender, $target_code, $message))
					{
						$ok = true;
					}
				}
				// its for sms board
				$db_query = "SELECT board_id FROM phpgw_sms_featboard WHERE board_code='$target_code'";
				$this->db->query($db_query, __LINE__, __FILE__);
				if ($this->db->next_record())
				{
					if ($this->insertsmstodb($sms_datetime, $sms_sender, $target_code, $message))
					{
						$ok = true;
					}
				}
		}
		if (!$ok)
		{
			//				$receipt_message = "Hei\n\n";
			//				$receipt_message .= "Dette er en tjeneste som benyttes for utkvittering av status på f.eks bestillinger\n";
			//				$receipt_message .= "Din melding har feil kodeord - og blir ikke registert eller lest\n";
			//				$receipt_message .= "Korrekt kodeord er å finne på bestillingen\n";
			//				$receipt_message .= "Mvh\n";
			//				$receipt_message .= "EBF\n";


			if ($sms_sender && ctype_digit($sms_sender) && !empty($this->generic_config['receipt_on_code_miss']))
			{
				$this->websend2pv('Admin', $sms_sender, $this->generic_config['receipt_on_code_miss'], 'text');
			}

			$message = "{$target_code} {$message}";
			if ($this->insertsmstoinbox($sms_datetime, $sms_sender, "Admins", $message))
			{
				$ok = true;
			}
		}
		return $ok;
	}
}
