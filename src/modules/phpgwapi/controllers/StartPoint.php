<?php

namespace App\modules\phpgwapi\controllers;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use App\modules\phpgwapi\services\Log;
use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\Config;
use App\modules\phpgwapi\security\Acl;

use Sanitizer;

class StartPoint
{
	private $log;
	private $invalid_data = false;
	private $api_requested = false;
	private $app;
	private $class;
	private $method;


	public function __construct()
	{
		$this->init();
		$this->log = new Log();
	}

	public function init()
	{
		require_once SRC_ROOT_PATH . '/helpers/LegacyObjectHandler.php';
		/************************************************************************\
		 * Load the menuaction                                                    *
		\*********************************************************************** */
		if (Sanitizer::get_var('menuaction', 'bool'))
		{
			Settings::getInstance()->set('menuaction', Sanitizer::get_var('menuaction', 'string'));
		}
	}

	public function mobilefrontend(Request $request, Response $response)
	{
		$serverSettings = Settings::getInstance()->get('server');
		$userSettings = Settings::getInstance()->get('user');

		if (
			!empty($serverSettings['log_levels']['module']['mobilefrontend'])
			&& !empty($userSettings['apps']['admin'])
			&& !Sanitizer::get_var('phpgw_return_as', 'string', 'GET') == 'json'
		)
		{
			_debug_array(Settings::getInstance()->get('flags'));
			_debug_array($_SERVER);
		}

		$this->validate_object_method();

		$this->api_requested = false;
		if ($this->app == 'phpgwapi')
		{
			$this->app = 'home';
			$this->api_requested = true;
		}


		if ($this->app == 'home' && !$this->api_requested)
		{
			\phpgw::redirect_link('/home/');
		}

		if ($this->api_requested)
		{
			$this->app = 'phpgwapi';
		}

		$userSettings['preferences']['common']['template_set'] = 'mobilefrontend';
		Settings::getInstance()->set('user', $userSettings);

		$phpgwapi_common = new \phpgwapi_common();

		if ($this->app == 'phpgwapi')
		{
			$Object = CreateObject("{$this->app}.{$this->class}");
		}
		else if (is_file(PHPGW_SERVER_ROOT . "/mobilefrontend/inc/class.{$this->class}.inc.php"))
		{
			$Object = CreateObject("{$this->app}.{$this->class}");
		}
		else
		{
			include_class('mobilefrontend', $this->class, "{$this->app}/");
			$_class = "mobilefrontend_{$this->class}";
			$Object = new $_class;
		}

		if (
			!$this->invalid_data
			&& is_object($Object)
			&& isset($Object->public_functions)
			&& is_array($Object->public_functions)
			&& isset($Object->public_functions[$this->method])
			&& $Object->public_functions[$this->method]
		)
		{
			if (
				Sanitizer::get_var('X-Requested-With', 'string', 'SERVER') == 'XMLHttpRequest'
				// deprecated
				|| Sanitizer::get_var('phpgw_return_as', 'string', 'GET') == 'json'
			)
			{
				$return_data = $Object->{$this->method}();
				$response_str = json_encode($return_data);
				$response->getBody()->write($response_str);
				return $response->withHeader('Content-Type', 'application/json');
			}
			else
			{
				if (Sanitizer::get_var('phpgw_return_as', 'string', 'GET') == 'noframes')
				{
					Settings::getInstance()->update('flags', ['headonly' => true, 'noframework' => true]);
				}

				$Object->{$this->method}();

				if (!empty(Settings::getInstance()->get('flags')['xslt_app']))
				{
					$return_data =  \phpgwapi_xslttemplates::getInstance()->parse();
				}
				else
				{
					$return_data = '';
				}

				register_shutdown_function(array($phpgwapi_common, 'phpgw_final'));

				$response->getBody()->write($return_data);
				return $response->withHeader('Content-Type', 'text/html');
			}
			unset($this->app);
			unset($this->class);
			unset($this->method);
			unset($this->invalid_data);
			unset($this->api_requested);
		}
		else
		{
			//FIXME make this handle invalid data better
			if (!$this->app || !$this->class || !$this->method)
			{
				$this->log->message(array(
					'text' => 'W-BadmenuactionVariable, menuaction missing or corrupt: %1',
					'p1'   => Sanitizer::get_var('menuaction', 'string'),
					'line' => __LINE__,
					'file' => __FILE__
				));
			}

			if ((!isset($Object->public_functions)
					|| !is_array($Object->public_functions)
					|| !isset($Object->public_functions[$this->method])
					|| !$Object->public_functions[$this->method])
				&& $this->method
			)
			{
				$this->log->message(array(
					'text' => 'W-BadmenuactionVariable, attempted to access private method: %1 from %2',
					'p1'   => "{$this->class}::{$this->method}",
					'p2' => Sanitizer::get_ip_address(true),
					'line' => __LINE__,
					'file' => __FILE__
				));
			}
			$this->log->commit();

			// phpgw::redirect_link('/home/');
		}

		//   $phpgwapi_common->phpgw_footer();

		$response_str = json_encode(['message' => 'Welcome to Portico API']);
		$response->getBody()->write($response_str);
		return $response->withHeader('Content-Type', 'application/json');
	}

	public function run(Request $request, Response $response)
	{
		$this->validate_object_method();

		$this->handle_redirect();

		$phpgwapi_common = new \phpgwapi_common();

		$this->api_requested = false;
		if ($this->app == 'phpgwapi')
		{
			$this->app = 'home';
			$this->api_requested = true;
		}


		if ($this->app == 'home' && !$this->api_requested)
		{
			\phpgw::redirect_link('/home/');
		}

		if ($this->api_requested)
		{
			$this->app = 'phpgwapi';
		}

		$Object = CreateObject("{$this->app}.{$this->class}");

		if (
			!$this->invalid_data
			&& is_object($Object)
			&& isset($Object->public_functions)
			&& is_array($Object->public_functions)
			&& isset($Object->public_functions[$this->method])
			&& $Object->public_functions[$this->method]
		)
		{
			if (
				Sanitizer::get_var('X-Requested-With', 'string', 'SERVER') == 'XMLHttpRequest'
				// deprecated
				|| Sanitizer::get_var('phpgw_return_as', 'string', 'GET') == 'json'
			)
			{
				$return_data = $Object->{$this->method}();
				$response_str = json_encode($return_data);
				$response->getBody()->write($response_str);
				return $response->withHeader('Content-Type', 'application/json');
			}
			else
			{
				if (Sanitizer::get_var('phpgw_return_as', 'string', 'GET') == 'noframes')
				{
					$flags = Settings::getInstance()->get('flags');
					$flags['noframework'] = true;
					$flags['headonly'] = true;
					Settings::getInstance()->set('flags', $flags);
				}
				$Object->{$this->method}();

				if (!empty(Settings::getInstance()->get('flags')['xslt_app']))
				{
					$return_data =  \phpgwapi_xslttemplates::getInstance()->parse();
				}
				else
				{
					$return_data = '';
				}

				register_shutdown_function(array($phpgwapi_common, 'phpgw_final'));

				$response->getBody()->write($return_data);
				return $response->withHeader('Content-Type', 'text/html');
			}
			unset($this->app);
			unset($this->class);
			unset($this->method);
			unset($this->invalid_data);
			unset($this->api_requested);
		}
		else
		{
			//FIXME make this handle invalid data better
			if (!$this->app || !$this->class || !$this->method)
			{
				$this->log->message(array(
					'text' => 'W-BadmenuactionVariable, menuaction missing or corrupt: %1',
					'p1'   => Sanitizer::get_var('menuaction', 'string'),
					'line' => __LINE__,
					'file' => __FILE__
				));
			}

			if ((!isset($Object->public_functions)
					|| !is_array($Object->public_functions)
					|| !isset($Object->public_functions[$this->method])
					|| !$Object->public_functions[$this->method])
				&& $this->method
			)
			{
				$this->log->message(array(
					'text' => 'W-BadmenuactionVariable, attempted to access private method: %1 from %2',
					'p1'   => "{$this->class}::{$this->method}",
					'p2' => Sanitizer::get_ip_address(true),
					'line' => __LINE__,
					'file' => __FILE__
				));
			}
			$this->log->commit();

			// phpgw::redirect_link('/home/');
		}

		//   $phpgwapi_common->phpgw_footer();

		$response_str = json_encode(['message' => 'Welcome to Portico API']);
		$response->getBody()->write($response_str);
		return $response->withHeader('Content-Type', 'application/json');
	}

	private function validate_object_method()
	{
		$this->invalid_data = false;
		if (isset($_GET['menuaction']) || isset($_POST['menuaction']))
		{
			if (isset($_GET['menuaction']))
			{
				list($this->app, $this->class, $this->method) = explode('.', $_GET['menuaction']);
			}
			else
			{
				list($this->app, $this->class, $this->method) = explode('.', $_POST['menuaction']);
			}
			if (!$this->app || !$this->class || !$this->method)
			{
				$this->invalid_data = true;
			}
		}
		else
		{

			$this->app = 'home';
			$this->invalid_data = true;
		}
	}

	public function bookingfrontend(Request $request, Response $response)
	{
		return $this->execute($request, $response, 'bookingfrontend');
	}

	public function eventplannerfrontend(Request $request, Response $response)
	{
		return $this->execute($request, $response, 'eventplannerfrontend');
	}

	public function activitycalendarfrontend(Request $request, Response $response)
	{
		return $this->execute($request, $response, 'activitycalendarfrontend');
	}


	private function handle_redirect($app = null)
	{
		$sessions = \App\modules\phpgwapi\security\Sessions::getInstance();

		$redirect_input = Sanitizer::get_var('redirect', 'raw', 'COOKIE');
		$redirect = $redirect_input ? json_decode(Sanitizer::get_var('redirect', 'raw', 'COOKIE'), true) : null;

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
				$redirect_data['sessionid']	 = $sessid;
				$redirect_data['kp3']		 = Sanitizer::get_var('kp3', 'string', 'GET');
			}

			$target = $app ? "/{$app}/" : '/';
			$sessions->phpgw_setcookie('redirect', false, 0);
			\phpgw::redirect_link($target, $redirect_data);
			unset($redirect);
			unset($redirect_data);
			unset($sessid);
		}
	}

	public function execute(Request $request, Response $response, $app)
	{
		// Make sure we're always logged in
		$sessions = \App\modules\phpgwapi\security\Sessions::getInstance();

		$this->handle_redirect($app);

		$flags = Settings::getInstance()->get('flags');
		$flags['currentapp'] = $app;
		Settings::getInstance()->set('flags', $flags);

		$selected_lang = Sanitizer::get_var('selected_lang', 'string', 'COOKIE');

		if (Sanitizer::get_var('lang', 'bool', 'GET'))
		{
			$selected_lang = Sanitizer::get_var('lang', 'string', 'GET');
			$sessions->phpgw_setcookie('selected_lang', $selected_lang, (time() + (60 * 60 * 24 * 14)));
		}

		$userSettings = Settings::getInstance()->get('user');
		$userlang = $selected_lang ? $selected_lang : $userSettings['preferences']['common']['lang'];

		\App\modules\phpgwapi\services\Translation::getInstance()->set_userlang($userlang, true);

		if ($app == 'bookingfrontend')
		{
			$template_set = Sanitizer::get_var('template_set', 'string', 'COOKIE');

			switch ($template_set)
			{
				case 'bookingfrontend':
				case 'bookingfrontend_2':
					$userSettings['preferences']['common']['template_set'] = $template_set;
					break;
//				case 'bookingfrontend_3':
//					$userSettings['preferences']['common']['template_set'] = $template_set;
//					// TODO: menu action from request,
//					break;
				default: // respect the global setting
					break;
			}

			Settings::getInstance()->set('user', $userSettings);
		}
		else if (in_array($app, ['eventplannerfrontend', 'activitycalendarfrontend']))
		{
			$userSettings['preferences']['common']['template_set'] = 'bookingfrontend_2';
			Settings::getInstance()->set('user', $userSettings);
		}
		/*
		* This one is needed to set the correct template set, defined on first run
		*/
		$phpgwapi_common = new \phpgwapi_common();

		$this->validate_object_method();

		// Check for develop mode redirect - ONLY for GET requests and not for JSON returns
		if ($app == 'bookingfrontend' && $_SERVER['REQUEST_METHOD'] === 'GET' && Sanitizer::get_var('phpgw_return_as', 'string', 'GET') !== 'json') {
			$template_set = Sanitizer::get_var('template_set', 'string', 'COOKIE');

			if ($template_set == 'bookingfrontend_2') {
				$config_frontend = CreateObject('phpgwapi.config', 'bookingfrontend')->read();
				$develop_mode = isset($config_frontend['develope_mode']) && $config_frontend['develope_mode'] === 'True';

				// Handle homepage redirect with no menuaction
				if ($develop_mode && !isset($_GET['menuaction']) || $_GET['menuaction'] === 'bookingfrontend.uisearch.index') {
					\phpgw::redirect_link('/bookingfrontend/client/');
				}

				// Handle redirects with menuaction
				if ($develop_mode && isset($_GET['menuaction'])) {
					$menuaction = $_GET['menuaction'];

				// Skip search index since it's handled above
				if ($menuaction === 'bookingfrontend.uisearch.index') {
					return; // Already handled above
				}

					$redirectMap = [
					'bookingfrontend.uibuilding.show' => '/bookingfrontend/client/building/%id%',
					'bookingfrontend.uiresource.show' => '/bookingfrontend/client/resource/%id%',
					'bookingfrontend.uiuser.show' => '/bookingfrontend/client/user/details',
					'bookingfrontend.uiapplication.add_contact' => '/bookingfrontend/client/checkout',
					'bookingfrontend.uiorganization.show' => '/bookingfrontend/client/organization/%id%',
					'bookingfrontend.uiorganization.edit' => '/bookingfrontend/client/organization/%id%/edit',
					'bookingfrontend.uieventsearch.show' => '/bookingfrontend/client/search/event',
					'bookingfrontend.uiapplication.show' => '/bookingfrontend/client/user/applications/%id%',

						// Add more mappings as needed
					];

					foreach ($redirectMap as $action => $redirectUrl) {
						if (strpos($menuaction, $action) === 0) {
						// Don't redirect if payment_order_id is set
						if (isset($_GET['payment_order_id'])) {
							break; // Skip redirect for payment flows
						}
							// Replace placeholders with actual values if needed
						if (strpos($redirectUrl, '%id%') !== false) {
							if (isset($_GET['id'])) {
								$redirectUrl = str_replace('%id%', $_GET['id'], $redirectUrl);
								\phpgw::redirect_link($redirectUrl);
							}
						} else {
							// No placeholder, redirect directly
							\phpgw::redirect_link($redirectUrl);
						}
						}
					}
				}
			}
		}

		if (!$this->app || !$this->class || !$this->method)
		{
			$this->app = $app;
			$this->class = 'uisearch';
			$this->method = 'index';

			if ($app == 'bookingfrontend')
			{
				// Check for develop mode when no menuaction is provided and default to homepage
				if ($_SERVER['REQUEST_METHOD'] === 'GET' && Sanitizer::get_var('phpgw_return_as', 'string', 'GET') !== 'json') {
					// Check cookie first, if unset use the assigned template
					$template_set = Sanitizer::get_var('template_set', 'string', 'COOKIE');
					if (empty($template_set)) {
						$userSettings = Settings::getInstance()->get('user');
						$template_set = $userSettings['preferences']['common']['template_set'] ?? '';
					}

					if ($template_set == 'bookingfrontend_2') {
						$config_frontend = CreateObject('phpgwapi.config', 'bookingfrontend')->read();
						$develop_mode = isset($config_frontend['develope_mode']) && $config_frontend['develope_mode'] === 'True';

						if ($develop_mode) {
							\phpgw::redirect_link('/bookingfrontend/client/no/');
						}
					}
				}

				$this->class = 'uisearch';
			}
			else if ($app == 'activitycalendarfrontend')
			{
				$this->class = 'uiactivity';
				$this->method = 'add';
			}
			else //eventplannerfrontend
			{
				$this->class = 'uievents';
				\phpgw::redirect_link('/eventplannerfrontend/home/');
			}
			$this->invalid_data = false;
		}


		if ($this->app != $app)
		{
			$this->invalid_data = true;
			$phpgwapi_common->phpgw_header(true);
			$this->log->write(array(
				'text'	 => 'W-Permissions, Attempted to access %1 from %2',
				'p1'	 => $this->app,
				'p2'	 => Sanitizer::get_ip_address(true)
			));

			$lang_denied = lang('Access not permitted');
			echo <<<HTML
				<div class="error">$lang_denied</div>

HTML;
			$phpgwapi_common->phpgw_exit(True);
		}

		$message = 'Welcome to Portico';

		$Object = CreateObject("{$this->app}.{$this->class}");

		if (
			!$this->invalid_data
			&& is_object($Object)
			&& isset($Object->public_functions)
			&& is_array($Object->public_functions)
			&& isset($Object->public_functions[$this->method])
			&& $Object->public_functions[$this->method]
		)

		{
			if (
				Sanitizer::get_var('X-Requested-With', 'string', 'SERVER') == 'XMLHttpRequest'
				// deprecated
				|| Sanitizer::get_var('phpgw_return_as', 'string', 'GET') == 'json'
			)
			{
				$return_data = $Object->{$this->method}();
				$response_str = json_encode($return_data);
				$response->getBody()->write($response_str);
				return $response->withHeader('Content-Type', 'application/json');
			}
			else
			{
				if (Sanitizer::get_var('phpgw_return_as', 'string', 'GET') == 'noframes')
				{
					$flags['noframework'] = true;
					$flags['headonly'] = true;
					Settings::getInstance()->set('flags', $flags);
				}
				$Object->{$this->method}();

				if (!empty(Settings::getInstance()->get('flags')['xslt_app']))
				{
					$return_data =  \phpgwapi_xslttemplates::getInstance()->parse();
				}
				else
				{
					$return_data = '';
				}

				register_shutdown_function(array($phpgwapi_common, 'phpgw_final'));

				$response->getBody()->write($return_data);
				return $response->withHeader('Content-Type', 'text/html');
			}
			unset($this->app);
			unset($this->class);
			unset($this->method);
			unset($this->invalid_data);
			unset($this->api_requested);
		}
		else
		{
			$message = 'W-BadmenuactionVariable, menuaction missing or corrupt: %1';
			//FIXME make this handle invalid data better
			if (!$this->app || !$this->class || !$this->method)
			{
				$this->log->message(array(
					'text' => 'W-BadmenuactionVariable, menuaction missing or corrupt: %1',
					'p1'   => Sanitizer::get_var('menuaction', 'string'),
					'line' => __LINE__,
					'file' => __FILE__
				));
			}

			if ((!isset($Object->public_functions)
					|| !is_array($Object->public_functions)
					|| !isset($Object->public_functions[$this->method])
					|| !$Object->public_functions[$this->method])
				&& $this->method
			)
			{
				$message = 'W-BadmenuactionVariable, attempted to access private method: %1 from %2';
				$this->log->message(array(
					'text' => 'W-BadmenuactionVariable, attempted to access private method: %1 from %2',
					'p1'   => "{$this->class}::{$this->method}",
					'p2' => Sanitizer::get_ip_address(true),
					'line' => __LINE__,
					'file' => __FILE__
				));
			}
			$this->log->commit();
		}


		$response_str = json_encode(['message' => $message]);
		$response->getBody()->write($response_str);
		return $response->withHeader('Content-Type', 'application/json');
	}

	function registration(Request $request, Response $response)
	{
		$phpgwapi_common = new \phpgwapi_common();
		$log = new Log();

		/* * ***********************************************************************\
	 * Verify that the users session is still active otherwise kick them out   *
	  \************************************************************************ */
		$flags = Settings::getInstance()->get('flags');
		if ($flags['currentapp'] != 'home' && $flags['currentapp'] != 'about')
		{
			$acl = Acl::getInstance();

			if (!$acl->check('run', ACL_READ, $flags['currentapp']))
			{
				$phpgwapi_common->phpgw_header(true);
				$log->write(array(
					'text' => 'W-Permissions, Attempted to access %1 from %2',
					'p1' => $flags['currentapp'],
					'p2' => Sanitizer::get_ip_address(true)
				));

				$lang_denied = lang('Access not permitted');
				echo <<<HTML
					<div class="error">$lang_denied</div>

HTML;
				$phpgwapi_common->phpgw_exit(True);
			}
		}

		register_shutdown_function(array($phpgwapi_common, 'phpgw_final'));
		$config = (new Config('registration'))->read();

		if (isset($_GET['menuaction']))
		{
			list($app, $class, $method) = explode('.', $_GET['menuaction']);
		}
		else
		{
			$app = 'registration';
			if ($config['username_is'] != 'email')
			{
				$class = 'uireg';
				$method = 'step1';
			}
			else
			{
				$class = 'boreg';
				$method = 'step1';
			}
		}
		$Object = CreateObject("{$app}.{$class}");

		$invalid_data = false;

		$legal_anonymous_access = array(
			'registration' => array(
				'uireg' => array(
					'step1' => true,
					'tos' => true,
					'ready_to_activate' => true,
					'lostpw1' => true,
					'email_sent_lostpw' => true
				),
				'boreg' => array(
					'step1' => true,
					'step2' => true,
					'step4' => true,
					'lostpw1' => true,
					'lostpw2' => true,
					'lostpw3' => true,
					'get_locations' => true
				)
			)
		);

		if (!isset($legal_anonymous_access[$app][$class][$method]))
		{
			$invalid_data = true;

			$log->message(array(
				'text' => "W-BadmenuactionVariable, attempted to access private method as anonymous: {$app}.{$class}.{$method} from %1",
				'p1' => Sanitizer::get_ip_address(true),
				'line' => __LINE__,
				'file' => __FILE__
			));
			$log->commit();
			$message =  "This method is not alloved from this application as anonymous: {$app}.{$class}.{$method}";
			$phpgwapi_common->phpgw_footer();
			$response_str = json_encode(['message' => $message]);
			$response->getBody()->write($response_str);
			return $response->withHeader('Content-Type', 'application/json');
		}

		if (!$invalid_data && is_object($Object) && isset($Object->public_functions) && is_array($Object->public_functions) && isset($Object->public_functions[$method]) && $Object->public_functions[$method])
		{
			if (
				Sanitizer::get_var('X-Requested-With', 'string', 'SERVER') == 'XMLHttpRequest'
				// deprecated
				|| Sanitizer::get_var('phpgw_return_as', 'string', 'GET') == 'json'
			)
			{
				// comply with RFC 4627
				Settings::getInstance()->update('flags', ['nofooter' => true]);
				$return_data = $Object->$method();
				$response_str = json_encode($return_data);
				$response->getBody()->write($response_str);
				return $response->withHeader('Content-Type', 'application/json');
			}
			else
			{
				$Object->$method();

				if (!empty(Settings::getInstance()->get('flags')['xslt_app']))
				{
					$return_data =  \phpgwapi_xslttemplates::getInstance()->parse();
				}
				else
				{
					$return_data = '';
				}

				$response->getBody()->write($return_data);
				return $response->withHeader('Content-Type', 'text/html');
			}
		}
	}
}
