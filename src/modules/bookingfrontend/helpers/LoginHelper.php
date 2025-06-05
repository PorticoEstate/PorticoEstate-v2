<?php

namespace App\modules\bookingfrontend\helpers;

use App\modules\phpgwapi\security\Sessions;
use App\modules\phpgwapi\services\Config;
use App\modules\phpgwapi\services\Cache;
use App\modules\bookingfrontend\helpers\UserHelper;
use Sanitizer;
use Slim\Psr7\Response;


class LoginHelper
{

    public static function organization()
    {
        
		Sessions::getInstance()->phpgw_setcookie('login_as_organization', '1');

		//If external login is enabled, callback will be handled by UserHelper::process_callback()
		
		if(self::login())
        {
            require_once SRC_ROOT_PATH . '/helpers/LegacyObjectHandler.php';
            /**
             * Pick up the external login-info
             */
            $bouser = new UserHelper();
            $bouser->log_in();

            $redirect = json_decode(Cache::session_get('bookingfrontend', 'redirect'), true);

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
                $redirect_data['rid'] = Sessions::getInstance()->generate_click_history();

                $sessid = Sanitizer::get_var('sessionid', 'string', 'GET');
                if ($sessid)
                {
                    $redirect_data['sessionid'] = $sessid;
                    $redirect_data['kp3'] = Sanitizer::get_var('kp3', 'string', 'GET');
                }

                Cache::session_clear('bookingfrontend', 'redirect');
                \phpgw::redirect_link('/bookingfrontend/', $redirect_data);
            }

            // Decode the 'after' parameter
            $after = urldecode(Sanitizer::get_var('after', 'raw', 'GET'));

            // If 'after' contains a '/', treat it as a URI (e.g., /this/page?with=params)
            if (strpos($after, '/') !== false || strpos($after, '?') !== false)
            {
                // Parse the URL to extract the path and query parameters
                $parsed_url = parse_url($after);
                $path = isset($parsed_url['path']) ? $parsed_url['path'] : '';
                $query = isset($parsed_url['query']) ? $parsed_url['query'] : '';

                // Convert the query string into an array
                $query_params = [];
                if (!empty($query))
                {
                    parse_str($query, $query_params);
                }
                $query_params['rid'] = Sessions::getInstance()->generate_click_history();

                // Sanitize and validate the path
                if (filter_var($path, FILTER_SANITIZE_URL))
                {
                    // Redirect to the extracted path with query parameters
                    \phpgw::redirect_link('/bookingfrontend' . $path, $query_params);
                    exit;
                }
            }
            else if (!empty($after))
            {
                // If 'after' doesn't look like a URI, treat it as query params
                $redirect_data = [];
                parse_str($after, $redirect_data);
                $redirect_data['rid'] = Sessions::getInstance()->generate_click_history();

                // Redirect to /bookingfrontend/ with the provided query params
                \phpgw::redirect_link('/bookingfrontend/', $redirect_data);
                exit;
            }

            // If 'after' is not provided or invalid, redirect to a default path
            \phpgw::redirect_link('/bookingfrontend/', ['rid' => Sessions::getInstance()->generate_click_history()]);
            exit;
        }
        \phpgw::redirect_link('/bookingfrontend/');
        exit;
    }

	private static function login()
	{

		$sessions = Sessions::getInstance();

		// Get bookingfrontendsession from GET if available
		$sessionid = Sanitizer::get_var('bookingfrontendsession', 'string', 'GET');

		if ((!Sanitizer::get_var(session_name(), 'string', 'COOKIE') && !$sessionid) || !$sessions->verify($sessionid))
		{
			$config = (new Config('bookingfrontend'))->read();

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
