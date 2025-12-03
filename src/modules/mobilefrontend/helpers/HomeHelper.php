<?php

namespace App\modules\mobilefrontend\helpers;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\Hooks;
use App\modules\phpgwapi\services\Cache;
use App\modules\phpgwapi\security\Sessions;

use App\modules\phpgwapi\services\Translation;
use App\modules\phpgwapi\services\Preferences;
use App\modules\phpgwapi\controllers\Applications;


class HomeHelper
{
	private $serverSettings;
	private $userSettings;
	private $hooks;
	private $phpgwapi_common;
	private $apps;


	public function __construct()
	{
		$flags = Settings::getInstance()->get('flags');
		$flags['noheader']             = true;
		$flags['nonavbar']             = false;
		$flags['currentapp']           = 'home';
		$flags['template_set']           = 'mobilefrontend';
		$flags['custom_frontend']           = 'mobilefrontend';
		$flags['menu_selection']             = 'home';

		Settings::getInstance()->set('flags', $flags);
		require_once SRC_ROOT_PATH . '/helpers/LegacyObjectHandler.php';

		$this->serverSettings = Settings::getInstance()->get('server');
		$this->userSettings = Settings::getInstance()->get('user');
		$this->apps = Settings::getInstance()->get('apps');
		$this->hooks = new Hooks();
		$this->phpgwapi_common = new \phpgwapi_common();
		\phpgw::import_class('phpgwapi.jquery');


	}

	public function processHome(Request $request, Response $response, array $args)
	{
		/**
		 * In case there is an extra session border to cross from outside a firewall
		 */
		if (\Sanitizer::get_var('keep_alive', 'bool', 'GET')	&& \Sanitizer::get_var('phpgw_return_as', 'string', 'GET') == 'json')
		{
			$now = time();
			$keep_alive_timestamp = Cache::session_get('mobilefrontend', 'keep_alive_timestamp');

			// first check
			if (!$keep_alive_timestamp)
			{
				$keep_alive_timestamp = $now;
				Cache::session_set('mobilefrontend', 'keep_alive_timestamp', $keep_alive_timestamp);
			}

			$sessions_timeout = 28800; // 480 minutes
			//		$sessions_timeout = $this->serverSettings['sessions_timeout'];
			if (($now - $keep_alive_timestamp) > $sessions_timeout)
			{
				$ret = array('status' => 440); //Login Time-out
				http_response_code(440);

				$sessions = Sessions::getInstance();
				$sessionid = $sessions->get_session_id();
				$this->hooks->process('logout');
				$sessions->destroy($sessionid);
			}
			else
			{
				Cache::session_set('mobilefrontend', 'keep_alive_timestamp', $now);
				$ret = array('status' => 200);
			}

			header('Content-Type: application/json');
			echo json_encode($ret);
			$this->phpgwapi_common->phpgw_exit();
		}

		if (isset($this->serverSettings['force_default_app']) && $this->serverSettings['force_default_app'] != 'user_choice')
		{
			$this->userSettings['preferences']['common']['default_app'] = $this->serverSettings['force_default_app'];
		}

		\phpgwapi_jquery::load_widget('core');
		$this->phpgwapi_common->phpgw_header();
		echo parse_navbar();

		Translation::getInstance()->add_app('mainscreen');
		if (lang('mainscreen_message') != '!mainscreen_message')
		{
			echo '<div class="msg">' . lang('mainscreen_message') . '</div>';
		}

		$preferences = createObject('phpgwapi.preferences');
		// This initializes the users portal_order preference if it does not exist.
		if ((!isset($this->userSettings['preferences']['portal_order']) || !is_array($this->userSettings['preferences']['portal_order'])) && $this->apps)
		{
			$preferences->delete('portal_order');
			$order = 0;
			foreach ($this->apps as $p)
			{
				if (isset($this->userSettings['apps'][$p['name']]) && $this->userSettings['apps'][$p['name']])
				{
					$preferences->add('portal_order', ++$order, $p['id']);
				}
			}
			$this->userSettings['preferences'] = $preferences->save_repository();
		}

		if (isset($this->userSettings['preferences']['portal_order']) && is_array($this->userSettings['preferences']['portal_order']))
		{
			$app_check = array();
			ksort($this->userSettings['preferences']['portal_order']);
			foreach ($this->userSettings['preferences']['portal_order'] as $app)
			{
				if (!isset($app_check[$app]) || !$app_check[$app])
				{
					$app_check[$app] = true;
					$applications = new Applications();
					$sorted_apps[] = $applications->id2name($app);
				}
			}
		}

		$this->hooks->process('home_mobilefrontend', $sorted_apps);

		if (isset($GLOBALS['portal_order']) && is_array($GLOBALS['portal_order']))
		{
			$preferences->delete('portal_order');
			foreach ($GLOBALS['portal_order'] as $app_order => $app_id)
			{
				$preferences->add('portal_order', $app_order, $app_id);
			}
			$preferences->save_repository();
		}
		if (Cache::system_get('phpgwapi', 'phpgw_home_screen_message'))
		{
			echo "<div class='container'><div class='jumbotron'><h1>";
			echo nl2br(Cache::system_get('phpgwapi', 'phpgw_home_screen_message_title'));
			echo "</h1>";
			echo nl2br(Cache::system_get('phpgwapi', 'phpgw_home_screen_message'));
			echo '</div></div>';
		}
		$this->phpgwapi_common->phpgw_footer();
		$response = $response->withHeader('Content-Type', 'text/plain');
		return $response;
	}
}
