<?php

namespace App\modules\phpgwapi\middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response;
use Slim\Routing\RouteContext;
use App\modules\phpgwapi\security\Sessions;
use App\modules\phpgwapi\security\Login;
use App\modules\phpgwapi\services\Settings;
use Psr\Http\Server\MiddlewareInterface;
use Sanitizer;

class SessionsMiddleware implements MiddlewareInterface
{
	protected $container;
	private $routePath;
	private $settings;

	public function __construct($container)
	{
		$this->settings = $container->get('settings');
	}

	public function process(Request $request, RequestHandler $handler): Response
	{
		$second_pass = Sanitizer::get_var('login_second_pass', 'bool', 'COOKIE');

		$routeContext = RouteContext::fromRequest($request);
		$route = $routeContext->getRoute();


		// If there is no route, return 404
		if (empty($route))
		{
			return $this->sendErrorResponse(['msg' => 'route not found'], 404);
		}

		//get the route path
		$currentApp = '';

		if (isset($_GET['menuaction']) || isset($_POST['menuaction']))
		{
			if (isset($_GET['menuaction']))
			{
				list($currentApp, $class, $method) = explode('.', $_GET['menuaction']);
			}
			else
			{
				list($currentApp, $class, $method) = explode('.', $_POST['menuaction']);
			}
		}


		$this->routePath = $route->getPattern();
		$routePath_arr = explode('/', $this->routePath);
		$_currentApp = trim($routePath_arr[1], '[');
		$currentApp = $currentApp ? $currentApp : $_currentApp;

		$this->read_initial_settings($currentApp, $_currentApp);
		$sessions = Sessions::getInstance();
		$flags = Settings::getInstance()->get('flags');
		$verified = $sessions->verify();
		if ($currentApp == 'login' && isset($_POST['login']) && isset($_POST['passwd']))
		{
			$login = $request->getParsedBody()['login'];
			$passwd = $request->getParsedBody()['passwd'];
			$sessionid = $sessions->create($login, $passwd);
			if (empty($sessionid))
			{
				return $this->sendErrorResponse(['msg' => 'A valid session could not be created'], 401);
			}

			$response = new Response();
			$response->getBody()->write(json_encode(['session_id' => $sessionid]));
			return $response->withHeader('Content-Type', 'application/json');
		}
		else if ($verified)
		{
			return $handler->handle($request);
		}
		else if (!$verified)
		{
			if ($currentApp == 'bookingfrontend')
			{
				\App\modules\bookingfrontend\helpers\LoginHelper::process();
				return $handler->handle($request);
			}
			if ($currentApp == 'eventplannerfrontend')
			{
				\App\modules\eventplannerfrontend\helpers\LoginHelper::process();
				return $handler->handle($request);
			}

			if ($currentApp == 'activitycalendarfrontend')
			{
				\App\modules\activitycalendarfrontend\helpers\LoginHelper::process();
				return $handler->handle($request);
			}

			if ($currentApp == 'registration')
			{
				\App\modules\registration\helpers\LoginHelper::process();
				return $handler->handle($request);
			}

			if ($currentApp == 'mobilefrontend')
			{
				$process_login = new Login();
				if ($process_login->login())
				{
					Settings::getInstance()->set('flags', $flags);
					return $handler->handle($request);
				}
				else
				{
					$sessions->phpgw_setcookie('login_second_pass', true, 0);
					if (Sanitizer::get_var('menuaction', 'string', 'GET')  && Sanitizer::get_var('phpgw_return_as', 'string') != 'json')
					{
						unset($_GET['click_history']);
						unset($_GET['sessionid']);
						unset($_GET[session_name()]);
						unset($_GET['kp3']);
						$cookietime = time() + 60;
						$sessions->phpgw_setcookie('redirect', json_encode($_GET), $cookietime);
					}
					//					\phpgw::redirect_link('/login_ui');
					\phpgw::redirect_link('/login.php');
				}
				$response = new Response();
				return $response->withHeader('Content-Type', 'text/html');
			}
			else if ($second_pass)
			{
				$sessions->phpgw_setcookie('login_second_pass', false);
				return $this->sendErrorResponse(['msg' => 'A valid session could not be found'], 401);
			}
			else
			{
				$process_login = new Login();
				if ($process_login->login())
				{
					if (!$currentApp)
					{
						\phpgw::redirect_link('/home/', array('cd' => 'yes'));
					}
					Settings::getInstance()->set('flags', $flags);
					return $handler->handle($request);
				}
				else
				{
					$sessions->phpgw_setcookie('login_second_pass', true, 0);
					if (Sanitizer::get_var('menuaction', 'string', 'GET')  && Sanitizer::get_var('phpgw_return_as', 'string') != 'json')
					{
						unset($_GET['click_history']);
						unset($_GET['sessionid']);
						unset($_GET[session_name()]);
						unset($_GET['kp3']);
						$cookietime = time() + 60;
						$sessions->phpgw_setcookie('redirect', json_encode($_GET), $cookietime);
					}
					\phpgw::redirect_link('/login_ui');
				}
				$response = new Response();
				return $response->withHeader('Content-Type', 'text/html');
			}
		}
		// Continue with the next middleware
		return $handler->handle($request);
	}


	/**
	 * Read the initial settings
	 *
	 * @param string $currentApp
	 * @param string $_currentApp custom frontend
	 *
	 * @return void
	 */

	private function read_initial_settings($currentApp, $custom_frontend = '')
	{
		$flags = Settings::getInstance()->get('flags');
		$flags['currentapp'] = $currentApp;

		if (isset($this->settings['session_name'][$custom_frontend]))
		{
			$flags['session_name'] = $this->settings['session_name'][$custom_frontend];
			$flags['custom_frontend'] = $custom_frontend;
		}

		Settings::getInstance()->set('flags', $flags);
	}

	/**
	 * Get the ip address of current users
	 *
	 * @return string ip address
	 */
	protected function _get_user_ip()
	{
		return \Sanitizer::get_var(
			'HTTP_X_FORWARDED_FOR',
			'ip',
			'SERVER',
			\Sanitizer::get_var('REMOTE_ADDR', 'ip', 'SERVER')
		);
	}


	/**
	 * Send an error response
	 *
	 * @param array $error
	 * @param int   $statusCode
	 *
	 * @return Response
	 */
	private function sendErrorResponse($error, $statusCode = 401): Response
	{
		$response = new Response();
		$response->getBody()->write(json_encode($error));
		return $response->withHeader('Content-Type', 'application/json')->withStatus($statusCode);
	}
}
