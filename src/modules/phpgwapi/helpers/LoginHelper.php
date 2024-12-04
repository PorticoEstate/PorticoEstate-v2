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

	public function processLogin(Request $request, Response $response, array $args)
	{
		$routeContext = RouteContext::fromRequest($request);
		$route = $routeContext->getRoute();
		$routePath = $route->getPattern();
		$routePath_arr = explode('/', $routePath);
		$currentApp = trim($routePath_arr[1], '[');

		//backwards compatibility
	//	if (empty($_POST) && $currentApp == 'mobilefrontend' && $routePath_arr[2] == 'login.php')
		if (empty($_POST) && ($routePath_arr[1] == 'login.php' || $routePath_arr[2] == 'login.php'))
		{
			$process_login = new Login();
			if ($process_login->login()) //SSO login
			{
				phpgw::redirect_link('/home/', array('cd' => 'yes'));
			}
		}

		$LoginUi   = new LoginUi($this->msg_only);
		$variables = array();

		if (!Sanitizer::get_var('hide_lightbox', 'bool'))
		{
			$partial_url	   = '/login_ui';
			$phpgw_url_for_sso = '/phpgwapi/inc/sso/login_server.php';

			$variables['lang_login']  = lang('login');
			$variables['partial_url'] = $partial_url;
			//		$variables['lang_frontend']	= $frontend ? lang($frontend) : '';
			if (isset($this->serverSettings['half_remote_user']) && $this->serverSettings['half_remote_user'] == 'remoteuser')
			{
				$variables['lang_additional_url'] = lang('use sso login');
				$variables['additional_url']	  = phpgw::link('/' . $phpgw_url_for_sso);
			}
		}

		if ($this->serverSettings['auth_type'] == 'remoteuser')
		{
			$this->msg_only = true;
		}

		if (empty($_POST))
		{
			$LoginUi->phpgw_display_login($variables, Sanitizer::get_var('cd', 'int', 'GET', 0));
		}
		else
		{

			$Login = new Login();
			if (Sanitizer::get_var('create_account', 'bool'))
			{
				$Login->create_account();
			}
			else if (Sanitizer::get_var('create_mapping', 'bool'))
			{
				$Login->create_mapping();
			}
			else
			{

				$sessionid = $Login->login();
				if ($sessionid)
				{

					$this->redirect();
				}
				else
				{
					$LoginUi->phpgw_display_login($variables, $Login->get_cd());
				}
			}
		}

		$response = $response->withHeader('Content-Type', 'text/html');
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
