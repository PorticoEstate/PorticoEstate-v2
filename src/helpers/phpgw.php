<?php

/**
 *
 * @author Dave Hall <skwashd@phpgroupware.org>
 * @author Dan Kuykendall <seek3r@phpgroupware.org>
 * @author Joseph Engo <jengo@phpgroupware.org>
 * @copyright Copyright (C) 2000-2008 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.fsf.org/licenses/lgpl.html GNU Lesser General Public License
 * @package phpgroupware
 * @subpackage phpgwapi
 * @version $Id$
 */

/*
	   This program is free software: you can redistribute it and/or modify
	   it under the terms of the GNU Lesser General Public License as published by
	   the Free Software Foundation, either version 2 of the License, or
	   (at your option) any later version.

	   This program is distributed in the hope that it will be useful,
	   but WITHOUT ANY WARRANTY; without even the implied warranty of
	   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	   GNU General Public License for more details.

	   You should have received a copy of the GNU Lesser General Public License
	   along with this program.  If not, see <http://www.gnu.org/licenses/>.
	 */

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\security\Sessions;

/**
 *
 *
 * @package phpgroupware
 * @subpackage phpgwapi
 */
class phpgw
{


	/**
	 * Strips out html chars
	 *
	 * Used as a shortcut for stripping out html special chars.
	 *
	 * @param $s string The string to have its html special chars stripped out.
	 * @return string The string with html special characters removed
	 */
	public static function strip_html($s)
	{
		$s = htmlspecialchars(strip_tags($s), ENT_QUOTES, 'UTF-8');
		return $s;
	}


	/**
	 * Generate a url which supports url or cookies based sessions
	 *
	 * @param string  $url       a url relative to the phpgroupware install root
	 * @param array   $extravars query string arguements
	 * @param boolean $redirect  is this for a redirect link ?
	 * @param boolean $external is the resultant link being used as external access (i.e url in emails..)
	 * @param boolean $force_backend if the resultant link is being used to reference resources in the api
	 *
	 * @return string generated url
	 */
	public static function link($url, $extravars = array(), $redirect = false, $external = false, $force_backend = false, $skipHistory = false)
	{

		$url = preg_replace("/\/index.php/", "/", $url);
		$flags = Settings::getInstance()->get('flags');
		$serverSettings = Settings::getInstance()->get('server');
		$userSettings = Settings::getInstance()->get('user');
		if (!$force_backend)
		{
			$custom_frontend = isset($flags['custom_frontend']) && $flags['custom_frontend'] ? $flags['custom_frontend'] : '';

			if ($custom_frontend && substr($url, 0, 4) != 'http' && strpos($url, "/{$custom_frontend}/") === false)
			{
				$url = '/' . $custom_frontend . '/' . ltrim($url, '/');
			}
		}

		//W3C Compliant in markup
		$term = '&amp;';
		if ($redirect)
		{
			// RFC Compliant for Header('Location: ...
			$term = '&';
		}

		/* first we process the $url to build the full scriptname */
		$full_scriptname = true;

		$url_firstchar = substr($url, 0, 1);
		if (
			$url_firstchar == '/'
			&& $serverSettings['webserver_url'] == '/'
		)
		{
			$full_scriptname = false;
		}

		if ($url_firstchar != '/')
		{
			$app = $flags['currentapp'];
			if ($app != 'home' && $app != 'login' && $app != 'logout')
			{
				$url = $app . '/' . $url;
			}
		}

		if ($full_scriptname)
		{
			$webserver_url_count = strlen($serverSettings['webserver_url']) - 1;

			if (
				substr($serverSettings['webserver_url'], $webserver_url_count, 1) != '/'
				&& $url_firstchar != '/'
			)
			{
				$url = "{$serverSettings['webserver_url']}/{$url}";
			}
			else
			{
				$url = "{$serverSettings['webserver_url']}{$url}";
			}
		}

		$request_scheme = empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] == 'off' ? 'http' : 'https';

		/*
			 * Behind reverse-proxy
			 */
		$request_scheme = !empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https' ? 'https' : $request_scheme;

		if ($external || $serverSettings['webserver_url'] == '/')
		{
			$server_port = !empty($serverSettings['enforce_ssl']) ? 443 : Sanitizer::get_var('SERVER_PORT', 'int', 'SERVER');

			if ($server_port == 443)
			{
				$request_scheme = 'https';
			}

			if (substr($url, 0, 4) != 'http' && $request_scheme != 'https')
			{
				if ($server_port == 80 || strpos($serverSettings['hostname'], ':') !== false)
				{
					$url = "{$request_scheme}://{$serverSettings['hostname']}{$url}";
				}
				else
				{
					$url = "{$request_scheme}://{$serverSettings['hostname']}:{$server_port}{$url}";
				}
			}
		}


		if ($request_scheme == 'https')
		{
			$serverSettings['enforce_ssl'] = true;
		}

		if (
			$external && isset($serverSettings['enforce_ssl'])
			&& $serverSettings['enforce_ssl']
		)
		{
			if (substr($url, 0, 4) != 'http')
			{
				$url = "https://{$serverSettings['hostname']}{$url}";
			}
			else
			{
				$url = preg_replace('/http:/', 'https:', $url);
			}
		}

		/*
				If an app sends the extrvars as a string we covert the extrvars into an array for proper processing
				This also helps prevent any duplicate values in the query string.
			*/
		if (!is_array($extravars) && $extravars != '')
		{
			trigger_error(
				"String used for extravar in sessions::link(url, extravar) call, use an array",
				E_USER_WARNING
			);
			$vars = explode('&', $extravars);
			foreach ($vars as $v)
			{
				$b = explode('=', $v);
				$new_extravars[$b[0]] = $b[1];
			}

			unset($extravars);

			$extravars = $new_extravars;
			unset($new_extravars);
		}


		if (!$external)
		{
			/* add session params if not using cookies */
			if (empty($serverSettings['usecookies']))
			{
				if (is_array($extravars))
				{
					$_session_vars = Sessions::getInstance()->_get_session_vars();
					/*
						 * Make sure session vars are inserted at the end of the array
						 * easier to read...
						 */
					foreach ($_session_vars as $_session_key => $_session_value)
					{
						if ($_session_key == 'domain' && !$_session_value)
						{
							continue;
						}
						unset($extravars[$_session_key]);
						$extravars[$_session_key] = $_session_value;
					}
				}
				else
				{
					$extravars = Sessions::getInstance()->_get_session_vars();
				}
			}

			//used for repost prevention
			if (!$skipHistory) {
				$extravars['click_history'] = Sessions::getInstance()->generate_click_history();
			}

			/* enable easy use of xdebug */
			if (isset($_REQUEST['XDEBUG_PROFILE']))
			{
				$extravars['XDEBUG_PROFILE'] = 1;
			}
		}

		if ($external && empty($extravars['domain']))
		{
			//cron..
			if ($userSettings['domain'] != 'default')
			{
				$extravars['domain'] = $userSettings['domain'];
			}
		}

		if (is_array($extravars)) //we have something to append
		{
			$url .= '?' . http_build_query($extravars, '', $term);
		}
		return $url;
	}

	/**
	 * Redirect to another URL
	 *
	 * @param string $string The url the link is for
	 * @param string $extravars	Extra params to be passed to the url
	 * @return null
	 */
	public static function redirect_link($url = '', $extravars = array(), $skipHistory = false)
	{
		self::redirect(self::link($url, $extravars, true, false, false, $skipHistory));
	}

	/**
	 * Safe redirect to external urls
	 *
	 * Stop session theft for "GET" based sessions
	 *
	 * @access public
	 * @param string $url the target url
	 * @returns string safe redirect url
	 * @author Dave Hall
	 */
	public static function safe_redirect($url)
	{
		$serverSettings = Settings::getInstance()->get('server');
		return $serverSettings['webserver_url']
			. '/redirect.php?go=' . urlencode($url);
	}


	/**
	 * Detects if the page has already been called before - good for forms
	 *
	 * @param boolean $display_error when implemented will use the generic error handler code
	 *
	 * @return boolean true if called previously, else false - call ok
	 */
	public static function is_repost($display_error = false)
	{
		$history		= \App\modules\phpgwapi\services\Cache::session_get('phpgwapi', 'history');
		$click_history	= \Sanitizer::get_var('click_history', 'string', 'GET');

		if ($click_history && isset($history[$click_history]))
		{
			if ($display_error)
			{
				//more on this later :)
				self::redirect_link('/error.php', array('type' => 'repost'));
			}
			else
			{
				//handled by the app
				return true;
			}
		}
		else
		{
			$history[$click_history] = true;
			\App\modules\phpgwapi\services\Cache::session_set('phpgwapi', 'history', $history);
			return false;
		}
	}


	/**
	 * Handles redirects under iis and apache
	 *
	 * This function handles redirects under iis and apache it assumes that $GLOBALS['phpgw']->link() has already been called
	 *
	 * @access public
	 * @param string The url ro redirect to
	 */
	public static function redirect($url = '')
	{
		if (!$url)
		{
			$url = \Sanitizer::get_var('PHP_SELF', 'string', 'SERVER');
		}

		if (headers_sent($filename, $linenum))
		{
			$log = new \App\modules\phpgwapi\services\Log();

			$log->error(array(
				'text'	=> 'Headers already sent in %1 on line %2.',
				'p1'	=> $filename,
				'p2'	=> $linenum,
				'line'	=> $linenum,
				'file'	=> $filename
			));

			echo "<html>\n<head>\n<title>Redirecting to $url</title>";
			echo "\n<meta http-equiv=\"refresh\ content=\"0; URL=$url\">";
			echo "\n</head>\n<body>";
			echo "\n<h3>Please continue to <a href=\"$url\">this page</a></h3>";
			echo "\n</body>\n</html>";
			exit;
		}
		else
		{
			header('Location: ' . $url);
			exit;
		}
	}


	/**
	 * Import a class, should be used in the top of each class, doesn't instantiate like createObject does
	 *
	 * @internal when calling static methods, phpgw::import_class() should be called to ensure it is available
	 * @param string $clasname the class to import module.class
	 */
	public static function import_class($classname)
	{
		$parts = explode('.', $classname);

		if (count($parts) != 2)
		{
			trigger_error(lang('Invalid class: %1', $classname), E_USER_ERROR);
		}

		if (!include_class($parts[0], $parts[1]))
		{
			$bt = debug_backtrace();

			trigger_error(lang('Unable to load class: %1', $classname) . " called from function: {$bt[0]['function']} file: {$bt[0]['file']} line: {$bt[0]['line']}", E_USER_ERROR);
		}
	}

	/**
	 * Display a message - and quit.
	 * @param string $appname
	 * @param string $message
	 */
	public static function no_access($appname = '', $message = '')
	{
		$phpgwapi_common = new phpgwapi_common();
		$flags = Settings::getInstance()->get('flags');
		$message = $message ? $message : lang('no access');
		if (\Sanitizer::get_var('phpgw_return_as') == 'json')
		{
			echo $message;
		}
		else
		{
			\App\modules\phpgwapi\services\Cache::message_set($message, 'error');

			$appname = $appname ? $appname : $flags['currentapp'];
			$flags['app_header'] = lang($appname) . '::' . lang('No access');
			$flags['xslt_app'] = false;
			Settings::getInstance()->set('flags', $flags);
			$phpgwapi_common->phpgw_header(true);
		}
		$phpgwapi_common->phpgw_exit();
	}
}
