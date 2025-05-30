<?php

namespace App\modules\eventplannerfrontend\helpers;

use App\modules\phpgwapi\security\Sessions;
use App\modules\phpgwapi\services\Config;
use App\modules\phpgwapi\services\Cache;
use Sanitizer;

class LoginHelper
{
	public static function post_login()
	{

		if (self::login())
		{
			require_once SRC_ROOT_PATH . '/helpers/LegacyObjectHandler.php';
			/**
			 * Pick up the external login-info
			 */
			$bouser = CreateObject('eventplannerfrontend.bouser');
		//	$bouser->log_in();

			$redirect =	json_decode(Cache::session_get('eventplannerfrontend', 'redirect'), true);

			if (!empty($config['debug_local_login']))
			{
				echo "<p>redirect:</p>";

				_debug_array($redirect);
				die();
			}

			if (is_array($redirect) && count($redirect))
			{
				$redirect_data = array();
				foreach ($redirect as $key => $value)
				{
					$redirect_data[$key] = Sanitizer::clean_value($value);
				}

				$redirect_data['second_redirect'] = true;

				$sessid = Sanitizer::get_var('sessionid', 'string', 'GET');
				if ($sessid)
				{
					$redirect_data['sessionid'] = $sessid;
					$redirect_data['kp3'] = Sanitizer::get_var('kp3', 'string', 'GET');
				}

				Cache::session_clear('eventplannerfrontend', 'redirect');
				\phpgw::redirect_link('/eventplannerfrontend/', $redirect_data);
			}

			$after = str_replace('&amp;', '&', urldecode(Sanitizer::get_var('after', 'raw')));
			if (!$after)
			{
				$after = array();
			}
			\phpgw::redirect_link('/eventplannerfrontend/', $after);
			exit;
		}
	}

	private static function login()
	{

		$sessions = Sessions::getInstance();

		if (!Sanitizer::get_var(session_name(), 'string', 'COOKIE') || !$sessions->verify())
		{
			$config = (new Config('eventplannerfrontend'))->read();

			$login		 = $config['anonymous_user'];
			$logindomain = Sanitizer::get_var('domain', 'string', 'GET');
			if ($logindomain && strstr($login, '#') === false)
			{
				$login .= "#{$logindomain}";
			}

			$passwd				 = $config['anonymous_passwd'];
			$_POST['submitit']	 = "";

			$sessionid = $sessions->create($login, $passwd);
			if (!$sessionid)
			{
				$lang_denied = lang('Anonymous access not correctly configured');
				if ($sessions->reason)
				{
					$lang_denied = $sessions->reason;
				}
				echo <<<HTML
<!DOCTYPE html>
<html>
<head>
	<title>Nede for vedlikehold</title>
	<style>
		body {
			background-color: #f2f2f2;
			font-family: Arial, sans-serif;
		}
		h1 {
			font-size: 48px;
			color: #333333;
			text-align: center;
			margin-top: 100px;
		}
		p {
			font-size: 24px;
			color: #666666;
			text-align: center;
			margin-top: 50px;
		}
		.footer {
			font-size: 14px;
			color: #666666;
			text-align: center;
			position: fixed;
			bottom: 0;
			width: 100%;
			margin-bottom: 10px;
		}
	</style>
</head>
<body>
	<h1>Nede for vedlikehold</h1>
	<p>Vi beklager ulempen, men denne nettsiden er for tiden under vedlikehold. Kom tilbake senere.</p>
	<div class="footer">$lang_denied</div>
</body>
</html>

HTML;
				/**
				 * Used for footer on exit
				 */
				require_once SRC_ROOT_PATH . '/helpers/LegacyObjectHandler.php';
				$phpgwapi_common = new \phpgwapi_common();
				$phpgwapi_common->phpgw_exit(True);
			}
		}
		else
		{
			return true;
		}
	}	
	public static function process()
	{
		self::login();
	}
}
