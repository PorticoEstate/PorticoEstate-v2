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

		$selected_lang = Sanitizer::get_var('lang', 'string', 'GET');

		if ($selected_lang)
		{
			Sessions::getInstance()->phpgw_setcookie('selected_lang', $selected_lang);
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
			if (!empty($result['html']))
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
			// Add passkey login option
			$options .= <<<HTML
			<option value="passkey">Passkey (Passwordless)</option>
HTML;
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
				if (!empty($result['html']))
				{
					$html = $result['html'];
				}
				if (!empty($result['session_id']))
				{
					$this->redirect();
				}
				if (empty($result['html']))
				{
					$html = $LoginUi->phpgw_display_login($variables, $Login->get_cd());
				}
			}
		}

		$response = $response->withHeader('Content-Type', 'text/html');
		if ($html)
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

	public function displayLoginForm(Request $request, Response $response, array $args)
	{
		$phpgw_domain = $GLOBALS['phpgw_domain'] ?? [];

		$last_domain = \Sanitizer::get_var('last_domain', 'string', 'COOKIE', false);
		$domainOptions = '';
		foreach (array_keys($phpgw_domain) as $domain)
		{
			$selected = ($domain === $last_domain) ? 'selected' : '';
			$domainOptions .= "<option value=\"$domain\" $selected>$domain</option>";
		}

		$sectionOptions = "<option value=\"\">None</option>";
		$sections = ['activitycalendarfrontend', 'bookingfrontend', 'eventplannerfrontend', 'mobilefrontend'];
		foreach ($sections as $section)
		{
			$sectionOptions .= "<option value=\"$section\">$section</option>";
		}

		$html = '
            <!DOCTYPE html>
            <html>
            <head>
                <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
            </head>
            <body>
                <div class="container">
                    <form method="POST" action="./login">
                        <div class="mb-3">
                            <label for="login" class="form-label">Login:</label>
                            <input type="text" class="form-control" id="login" name="login">
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Password:</label>
                            <input type="password" class="form-control" id="password" name="passwd">
                        </div>
                        <div class="mb-3">
                            <label for="logindomain">Domain:</label>
                            <select class="form-select" id="logindomain" name="logindomain">
                                ' . $domainOptions . '
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="section">Section:</label>
                            <select class="form-select" id="section" name="section">
                                ' . $sectionOptions . '
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary">Submit</button>
                    </form>
                </div>
            </body>
            </html>
        ';
		$response = $response->withHeader('Content-Type', 'text/html');
		$response->getBody()->write($html);
		return $response;
	}

	/**
	 * Process login request
	 * 
	 * @OA\Post(
	 *     path="/login",
	 *     summary="Authenticate user and create session",
	 *     tags={"API"},
	 *     @OA\RequestBody(
	 *         required=true,
	 *         @OA\MediaType(
	 *             mediaType="application/x-www-form-urlencoded",
	 *             @OA\Schema(
	 *                 @OA\Property(property="login", type="string"),
	 *                 @OA\Property(property="passwd", type="string"),
	 *                 @OA\Property(property="logindomain", type="string"),
	 *                 @OA\Property(property="section", type="string")
	 *             )
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Login successful",
	 *         @OA\JsonContent(
	 *             @OA\Property(property="session_name", type="string"),
	 *             @OA\Property(property="session_id", type="string")
	 *         )
	 *     )
	 * )
	 */
	public function processLoginPost(Request $request, Response $response, array $args)
	{
		// Get the session ID
		$session_id = session_id();

		// Prepare the response
		$json = json_encode(['session_name' => session_name(), 'session_id' => $session_id]);
		$response = $response->withHeader('Content-Type', 'application/json');
		$response->getBody()->write($json);
		return $response;
	}

	/**
	 * Refresh session information
	 * 
	 * @OA\Get(
	 *     path="/refreshsession",
	 *     summary="Refresh session and verify login status",
	 *     tags={"API"},
	 *     @OA\Response(
	 *         response=200,
	 *         description="Session is valid",
	 *         @OA\JsonContent(
	 *             @OA\Property(property="session_id", type="string"),
	 *             @OA\Property(property="fullname", type="string")
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=401,
	 *         description="Not logged in",
	 *         @OA\JsonContent(
	 *             @OA\Property(property="message", type="string")
	 *         )
	 *     )
	 * )
	 */
	public function refreshSession(Request $request, Response $response, array $args)
	{
		$sessions = \App\modules\phpgwapi\security\Sessions::getInstance();
		if (!$sessions->verify())
		{
			$response_str = json_encode(['message' => 'Du er ikke logget inn']);
			$response->getBody()->write($response_str);
			return $response->withHeader('Content-Type', 'application/json')
				->withStatus(401);
		}
		else
		{
			$session_id = $sessions->get_session_id();
			$response_str = json_encode([
				'session_id' => $session_id,
				'fullname' => $sessions->get_user()['fullname']
			]);
			$response->getBody()->write($response_str);
			return $response->withHeader('Content-Type', 'application/json');
		}
	}

	/**
	 * Log out a user
	 * 
	 * @OA\Get(
	 *     path="/logout",
	 *     summary="Log out current user",
	 *     tags={"API"},
	 *     @OA\Response(
	 *         response=200,
	 *         description="Logout status",
	 *         @OA\JsonContent(
	 *             @OA\Property(property="message", type="string")
	 *         )
	 *     )
	 * )
	 */
	public function logout(Request $request, Response $response, array $args)
	{
		$sessions = \App\modules\phpgwapi\security\Sessions::getInstance();
		if (!$sessions->verify())
		{
			$response_str = json_encode(['message' => 'Du er ikke logget inn']);
			$response->getBody()->write($response_str);
			return $response->withHeader('Content-Type', 'application/json');
		}
		else
		{
			$session_id = $sessions->get_session_id();
			$sessions->destroy($session_id);
			$response_str = json_encode(['message' => 'Du er logget ut']);
			$response->getBody()->write($response_str);
			return $response->withHeader('Content-Type', 'application/json');
		}
	}
}
