<?php

namespace App\modules\phpgwapi\helpers;

require_once SRC_ROOT_PATH . '/helpers/LegacyObjectHandler.php';

use App\modules\phpgwapi\helpers\LoginUi;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use App\modules\phpgwapi\security\Login;
use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\Translation;
use App\helpers\Template;
use App\modules\phpgwapi\security\Sessions;
use Sanitizer;
use phpgw;
use Slim\Routing\RouteContext;


class LoginHelper
{

	private $serverSettings;
	var $tmpl	 = null;
	var $msg_only = false;

	public function __construct($msg_only = false)
	{
		$this->serverSettings = Settings::getInstance()->get('server');
		if (!$this->serverSettings['isConnected'])
		{
			throw new \Exception('Not connected to the server');
		}

		Sessions::getInstance();
		$this->serverSettings['template_set'] = Settings::getInstance()->get('login_template_set');
		$this->serverSettings['template_dir'] = PHPGW_SERVER_ROOT
			. "/phpgwapi/templates/{$this->serverSettings['template_set']}";

		Settings::getInstance()->set('server', $this->serverSettings);

		$tmpl = new Template($this->serverSettings['template_dir']);

		// This is used for system downtime, to prevent new logins.
		if (
			isset($this->serverSettings['deny_all_logins']) && $this->serverSettings['deny_all_logins']
		)
		{
			$tmpl->set_file(
				array(
					'login_form' => 'login_denylogin.tpl'
				)
			);
			$tmpl->pfp('loginout', 'login_form');
			exit;
		}
		$this->tmpl		= $tmpl;
		$this->msg_only = $msg_only;
	}

	public function processLoginCallback(Request $request, Response $response, array $args)
	{
		$Login = new Login();
		$result = $Login->login();
		if (!empty($result['session_id']))
		{
			$this->redirect();
		}

		$response = $response->withHeader('Content-Type', 'text/html');

		if (!empty($result['html']))
		{
			$response->getBody()->write($result['html']);
		}
		return $response;
	}


	public function processLogin(Request $request, Response $response, array $args)
	{
		$routeContext = RouteContext::fromRequest($request);
		$route = $routeContext->getRoute();
		$routePath = $route->getPattern();
		$routePath_arr = explode('/', $routePath);
		$currentApp = trim($routePath_arr[1], '[');

		$selected_lang = Sanitizer::get_var('lang', 'string', 'GET');

		if($selected_lang)
		{
			Sessions::getInstance()->phpgw_setcookie('selected_lang', $selected_lang);
		}

		//backwards compatibility
		$login_type = Sanitizer::get_var('type', 'string', 'GET');
		if ($login_type !== 'sql' && empty($_POST) && ($routePath_arr[1] == 'login.php' || $routePath_arr[2] == 'login.php'))
		{
			$process_login = new Login();
			$result = $process_login->login();
			if (!empty($result['session_id'])) //SSO login
			{
				phpgw::redirect_link('/home/', array('cd' => 'yes'));
			}
			if(!empty($result['html']))
			{
				$response = $response->withHeader('Content-Type', 'text/html');
				$response->getBody()->write($result['html']);
				return $response;
			}
		}
		$location_obj = new \App\modules\phpgwapi\controllers\Locations();
		$location_id	= $location_obj->get_id('admin', 'openid_connect');

		if ($location_id)
		{
			$config_openid = (new \App\modules\phpgwapi\services\ConfigLocation($location_id))->read();
		}

		if ($login_type !== 'sql' && empty($_POST) && !empty($config_openid['common']['method_backend']) && empty($_REQUEST['skip_remote']))
		{
			$lang_sign_in = lang('Sign in');
			$lang_select_login_method = lang('Select login method');
			$options = <<<HTML
			<option value="">{$lang_select_login_method}</option>
HTML;
			foreach ($config_openid['common']['method_backend'] as $type)
			{
				$method_name = $config_openid[$type]['name'];
				$options .= <<<HTML
				<option value="{$type}">{$method_name}</option>
HTML;
			}
			$options .= <<<HTML
			<option value="sql">Brukernavn/Passord</option>
HTML;

			$html = <<<HTML
<!DOCTYPE html>
	<html>
	<head>
		<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
		<script>
			document.addEventListener('DOMContentLoaded', function() {
				const selectElement = document.getElementById('type');
				const form = document.getElementById('login-form');

				// Function to reset the select box to its default value
				function resetSelectBox() {
					selectElement.value = '';
				}

				// Function to check the selected option and submit the form
				function checkSelectedOption() {
					if (selectElement.value !== '') {
						form.submit();
					}
				}

				// Reset the select box on page load
				resetSelectBox();

				// Add event listener to the select element
				selectElement.addEventListener('change', checkSelectedOption);

				// Reset the select box when the user clicks the "back" button
				window.addEventListener('pageshow', resetSelectBox);
			});
		</script>
	</head>
	<body>
		<div class="container">
			<h1>{$lang_sign_in} {$this->serverSettings['site_title']}</h1>
			<form id="login-form" method="GET" action="./login.php">
				<div class="mb-3">
					<label for="type" class="form-label">Logg inn med:</label>
					<select id="type" name="type" class="form-select">
						{$options}
					</select>
				</div>
			</form>
		</div>
	</body>
</html>
HTML;

			$response = $response->withHeader('Content-Type', 'text/html');
			$response->getBody()->write($html);
			return $response;
		}


		$LoginUi   = new LoginUi($this->msg_only);
		$variables = array();

		if (!Sanitizer::get_var('hide_lightbox', 'bool'))
		{
			$partial_url	   = '/login_ui';
//			$phpgw_url_for_sso = '/phpgwapi/inc/sso/login_server.php';

			$variables['lang_login']  = lang('login');
			$variables['partial_url'] = $partial_url;
			// if (isset($this->serverSettings['half_remote_user']) && $this->serverSettings['half_remote_user'] == 'remoteuser')
			// {
			// 	$variables['lang_additional_url'] = lang('use sso login');
			// 	$variables['additional_url']	  = phpgw::link('/' . $phpgw_url_for_sso);
			// }
		}

		if ($this->serverSettings['auth_type'] == 'remoteuser')
		{
			$this->msg_only = true;
		}

		$html = '';
		if (empty($_POST))
		{
			$html = $LoginUi->phpgw_display_login($variables, Sanitizer::get_var('cd', 'int', 'GET', 0));
		}
		else
		{

			$Login = new Login();
			if (Sanitizer::get_var('create_account', 'bool'))
			{
				$html = $Login->create_account();
			}
			else if (Sanitizer::get_var('create_mapping', 'bool'))
			{
				$html = $Login->create_mapping();
			}
			else
			{

				$result = $Login->login();
				if (!empty($result['session_id']))
				{
					$this->redirect();
				}
				else
				{
					$html = $LoginUi->phpgw_display_login($variables, $Login->get_cd());
				}
			}
		}

		$response = $response->withHeader('Content-Type', 'text/html');
		if($html)
		{
			$response->getBody()->write($html);
		}
		return $response;
	}

	function redirect()
	{
		$sessions = Sessions::getInstance();

		$_redirect_data = Sanitizer::get_var('redirect', 'raw', 'COOKIE');
		$redirect = array();
		if ($_redirect_data)
		{
			$redirect = json_decode($_redirect_data, true);
		}
		else
		{
			phpgw::redirect_link('/home/', array('cd' => 'yes'));
		}

		if (is_array($redirect) && count($redirect) && empty($_SESSION['skip_redirect_on_login']))
		{
			foreach ($redirect as $key => $value)
			{
				$redirect_data[$key] = Sanitizer::clean_value($value);
			}

			$sessions->phpgw_setcookie('redirect', '', time() - 60); // expired

			/**
			 * Hack to deal with problem with update cookie for sso combined with certain reverse-proxy implementation
			 */
			$_SESSION['skip_redirect_on_login'] = true;

			phpgw::redirect_link('/', $redirect_data);
		}
	}
}
