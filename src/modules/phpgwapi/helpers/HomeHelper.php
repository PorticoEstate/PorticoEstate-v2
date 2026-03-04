<?php

namespace App\modules\phpgwapi\helpers;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use App\modules\phpgwapi\security\Sessions;
use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\Hooks;
use App\modules\phpgwapi\services\Cache;
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

		Settings::getInstance()->set('flags', $flags);
		require_once SRC_ROOT_PATH . '/helpers/LegacyObjectHandler.php';

		$this->serverSettings = Settings::getInstance()->get('server');
		$this->userSettings = Settings::getInstance()->get('user');
		$this->apps = Settings::getInstance()->get('apps');
		$this->hooks = new Hooks();
		$this->phpgwapi_common = new \phpgwapi_common();
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

			$sessions_timeout = 7200; // 120 minutes
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


		// check if forward parameter is set
		if (isset($_GET['phpgw_forward']) && is_array($_GET['phpgw_forward']))
		{
			
			$extra_vars = [];
			foreach ($_GET as $name => $value)
			{
				// find phpgw_ in the $_GET parameters but skip phpgw_forward because of redirect call below
				if (preg_match('/phpgw_/', $name) && ($name != 'phpgw_forward'))
				{
					$name = substr($name, 6); // cut 'phpgw_'
					$extra_vars[$name] = \Sanitizer::clean_value($value);
				}
			}

			\phpgw::redirect_link($_GET['phpgw_forward'], $extra_vars);
			exit;
		}


		if (
			isset($_GET['cd']) && $_GET['cd'] == 'yes'
			&& isset($this->userSettings['preferences']['common']['default_app'])
			&& $this->userSettings['preferences']['common']['default_app']
			&& $this->userSettings['apps'][$this->userSettings['preferences']['common']['default_app']]
		)
		{
			\phpgw::redirect_link('/' . $this->userSettings['preferences']['common']['default_app'] . '/' . 'index.php');
			exit;
		}
		else
		{
			\phpgw::import_class('phpgwapi.jquery');
			\phpgw::import_class('phpgwapi.js');
			\phpgw::import_class('phpgwapi.css');

			\phpgwapi_jquery::load_widget('core');
			\phpgwapi_jquery::load_widget('autocomplete');
			\phpgwapi_js::getInstance()->validate_file('jquery', 'common', 'phpgwapi', false, array('combine' => true));
			\phpgwapi_js::getInstance()->validate_file('tinybox2', 'packed', 'phpgwapi', false, array('combine' => true));
			\phpgwapi_css::getInstance()->add_external_file('phpgwapi/js/tinybox2/style.css');
			$this->phpgwapi_common->phpgw_header();
			echo parse_navbar();
		}

		$bookmarks = Cache::user_get('phpgwapi', "bookmark_menu", $this->userSettings['id']);

		$bookmark_section = '';

		if ($this->userSettings['preferences']['common']['template_set'] == 'bootstrap')
		{
			$grid_envelope = 'row mt-4';
			$grid_element = 'col-4 mb-3';
			$card_class = 'card shadow h-100 mb-2';
			$card_body_class = 'card-block text-center';
			$card_footer_class = 'card-footer text-center';
			$link_class = 'text-secondary';
			$icon_class = 'text-secondary';
			$icon_wrap_class = 'p-3';
		}
		else
		{
			$grid_envelope = 'app-row mt-4';
			$grid_element = 'app-col-4 u-mb-4';
			$card_class = 'app-card app-card-bordered app-tile';
			$card_body_class = 'app-card-body app-text-center';
			$card_footer_class = 'app-tile-footer';
			$link_class = 'app-tile-link';
			$icon_class = 'app-tile-icon';
			$icon_wrap_class = 'app-tile-icon-wrap';
		}

		if ($bookmarks && is_array($bookmarks))
		{
			$bookmark_section = <<<HTML
	<div class="app-container">
		<div id="container_bookmark" class="{$grid_envelope}">
HTML;
			foreach ($bookmarks as $bm_key => $bookmark_data)
			{
				if (is_array($bookmark_data))
				{

					$icon = $bookmark_data['icon'] ? $bookmark_data['icon'] : 'fas fa-2x fa-file-alt';
					$bookmark_section .= <<<HTML
				<div class="{$grid_element}">
					<a href="{$bookmark_data['href']}" class="{$link_class}">
						<div class="{$card_class}">
							<div class="{$card_body_class}">
								<h1 class="{$icon_wrap_class}">
									<i class="{$icon} {$icon_class}"></i>
								</h1>
							</div>
							<div class="{$card_footer_class}">{$bookmark_data['text']}</div>
						</div>
					</a>
				</div>
HTML;
				}
			}
			$bookmark_section .= <<<HTML
		</div>
	</div>
HTML;
		}

		echo $bookmark_section;
		Translation::getInstance()->add_app('mainscreen');
		if (lang('mainscreen_message') != '!mainscreen_message')
		{
			echo '<div class="msg">' . lang('mainscreen_message') . '</div>';
		}

		if ((isset($this->userSettings['apps']['admin']) &&
				$this->userSettings['apps']['admin']) &&
			(isset($this->serverSettings['checkfornewversion']) &&
				$this->serverSettings['checkfornewversion'])
		)
		{
			// Create a stream
			$opts = array(
				'http' => array(
					'method' => "GET",
					//			    'proxy' => 'proxy.bergen.kommune.no:8080',
				)
			);

			if (isset($this->serverSettings['httpproxy_server']))
			{
				$opts['http']['proxy'] = "{$this->serverSettings['httpproxy_server']}:{$this->serverSettings['httpproxy_port']}";
			}

			$context = stream_context_create($opts);

			$contents = file_get_contents('https://raw.githubusercontent.com/PorticoEstate/PorticoEstate/master/setup/currentversion', false, $context);
			if (preg_match('/currentversion/', $contents))
			{
				$line_found = explode(':', rtrim($contents));
			}

			/**
			 * compares for major versions only
			 */
			if ($this->phpgwapi_common->cmp_version($this->serverSettings['versions']['phpgwapi'], $line_found[1], false))
			{
				echo '<p>There is a new version of PorticoEstate available from <a href="'
					. 'https://github.com/PorticoEstate/PorticoEstate">https://github.com/PorticoEstate/PorticoEstate</a>';
			}

			$_found = False;
			$db = \App\Database\Db::getInstance();
			$db->query("SELECT app_name,app_version FROM phpgw_applications", __LINE__, __FILE__);
			while ($db->next_record())
			{
				$_db_version  = $db->f('app_version');
				$_app_name    = $db->f('app_name');
				$_versionfile = $this->phpgwapi_common->get_app_dir($_app_name) . '/setup/setup.inc.php';
				if (file_exists($_versionfile))
				{
					require_once $_versionfile;
					$_file_version = $setup_info[$_app_name]['version'];
					$_app_title    = $this->apps[$_app_name]['title'];
					unset($setup_info);

					if ($this->phpgwapi_common->cmp_version_long($_db_version, $_file_version))
					{
						$_found = True;
						$_app_string .= '<br />' . $_app_title;
					}
					unset($_file_version);
					unset($_app_title);
				}
				unset($_db_version);
				unset($_versionfile);
			}
			if ($_found)
			{
				echo '<br>' . lang('The following applications require upgrades') . ':' . "\n";
				echo $_app_string . "\n";
				echo '<br>' . lang('Please run setup to become current') . '.' . "\n";
				unset($_app_string);
			}
		}

		// This initializes the users portal_order preference if it does not exist.
		if ((!isset($this->userSettings['preferences']['portal_order']) || !is_array($this->userSettings['preferences']['portal_order']))
			&& $this->apps
		)
		{
			Preferences::getInstance()->delete('portal_order');
			$order = 0;
			foreach ($this->apps as $p)
			{
				if (
					isset($this->userSettings['apps'][$p['name']])
					&& $this->userSettings['apps'][$p['name']]
				)
				{
					Preferences::getInstance()->add('portal_order', ++$order, $p['id']);
				}
			}
			$this->userSettings['preferences'] = Preferences::getInstance()->save_repository();
			Settings::getInstance()->set('user', $this->userSettings);
		}

		if (
			isset($this->userSettings['preferences']['portal_order'])
			&& is_array($this->userSettings['preferences']['portal_order'])
		)
		{
			$appplications = new Applications;
			$appplications->read_installed_apps();

			$app_check = array();
			ksort($this->userSettings['preferences']['portal_order']);
			foreach ($this->userSettings['preferences']['portal_order'] as $app)
			{
				if (!isset($app_check[$app]) || !$app_check[$app])
				{
					$app_check[$app] = true;
					$sorted_apps[] = $appplications->id2name($app);
				}
			}
		}
		else
		{
			$sorted_apps = array(
				'email',
				'calendar',
				'news_admin',
				'addressbook',
			);
		}

		$this->hooks->process('home', $sorted_apps);

		$portal_order = Cache::session_get('phpgwapi', 'portal_order');

		if (isset($portal_order) && is_array($portal_order))
		{
			Preferences::getInstance()->delete('portal_order');
			foreach ($portal_order  as $app_order => $app_id)
			{
				Preferences::getInstance()->add('portal_order', $app_order, $app_id);
			}
			Preferences::getInstance()->save_repository();
		}
		if (Cache::system_get('phpgwapi', 'phpgw_home_screen_message'))
		{
			echo "<div class='msg_important container'><h2>";
			echo nl2br(Cache::system_get('phpgwapi', 'phpgw_home_screen_message_title'));
			echo "</h2>";
			echo nl2br(Cache::system_get('phpgwapi', 'phpgw_home_screen_message'));
			echo '</div>';
		}

		$this->phpgwapi_common->phpgw_footer();
		$response = $response->withHeader('Content-Type', 'text/plain');
		return $response;
	}
}
