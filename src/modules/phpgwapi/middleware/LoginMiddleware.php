<?php

/**
 * PorticoEstate - Login
 * @author Sigurd Nes <sigurdne@online.no>
 * @copyright Copyright (C) 2000-2013 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License v2 or later
 * @package phpgwapi
 * @subpackage login
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
		GNU Lesser General Public License for more details.

		You should have received a copy of the GNU Lesser General Public License
		along with this program.  If not, see <http://www.gnu.org/licenses/>.
	 */

namespace App\modules\phpgwapi\middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response;
use Slim\Routing\RouteContext;
use Psr\Http\Server\MiddlewareInterface;
use App\modules\phpgwapi\security\Login;
use App\modules\phpgwapi\security\Sessions;

/**
 * Login - enables common handling of the login process from different part of the system
 *
 * @package phpgwapi
 * @subpackage login
 */
class LoginMiddleware implements MiddlewareInterface
{
	private $settings;
	public function __construct($container)
	{
		$this->settings = $container->get('settings');
	}

	function check_cdcode($code)
	{
		switch ($code)
		{
			case 1:
				return lang('You have been successfully logged out');
			case 2:
				return lang('Sorry, your login has expired');
			case 5:
				return lang('Bad login or password');
			case 20:
				return lang('Cannot find the mapping ! (please advice your adminstrator)');
			case 21:
				return lang('you had inactive mapping to %1 account', \Sanitizer::get_var('phpgw_account', 'string', 'GET', ''));
			case 22:
				return lang('you seemed to have an active session elsewhere for the domain "%1", now set to expired - please try again', \Sanitizer::get_var('domain', 'string', 'COOKIE'));
			case 99:
				return lang('Blocked, too many attempts');
			case 10:
				return lang('sorry, your session has expired');
			default:
				return '';
		}
	}

	public function process(Request $request, RequestHandler $handler): Response
	{
		$routeContext = RouteContext::fromRequest($request);
		$route = $routeContext->getRoute();

		// If there is no route, return 404
		if (empty($route))
		{
			return $this->sendErrorResponse(['msg' => 'route not found'], 404);
		}

		$Login = new Login($this->settings);
		$result = $Login->login();

		if (empty($result['session_id']))
		{
			$reason = $this->check_cdcode($Login->get_cd());
			return $this->sendErrorResponse(['msg' => $reason], 404);
		}

		// Continue with the next middleware
		return $handler->handle($request);
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
