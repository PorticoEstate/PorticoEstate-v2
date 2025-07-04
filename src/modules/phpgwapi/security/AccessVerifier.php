<?php

namespace App\modules\phpgwapi\security;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Container\ContainerInterface;
use Slim\Routing\RouteContext;
use Slim\Exception\HttpNotFoundException;
use Slim\Exception\HttpForbiddenException;
use Slim\Psr7\Response;
use App\modules\phpgwapi\services\Settings;
use App\Database\Db;

use App\modules\phpgwapi\security\Acl;


class AccessVerifier  implements MiddlewareInterface
{
	protected $acl;
	protected $db;

	public function __construct(ContainerInterface $container)
	{
		$this->db =	Db::getInstance();
	}

	//public function process

	public function process(Request $request, RequestHandler $handler): Response
	{

		$routeContext = RouteContext::fromRequest($request);
		$route = $routeContext->getRoute();

		// If there is no route, return 404
		if (empty($route))
		{
			return $this->sendErrorResponse(['msg' => 'route not found'], 404);
		}


		$flags = Settings::getInstance()->get('flags');
		$account_id = Settings::getInstance()->get('account_id');
		$currentApp = $flags['currentapp'];

		$acl = Acl::getInstance();
		$acl->set_account_id($account_id);

		$run = $acl->check('run', ACL_READ, $currentApp);


		// Check if the user has permission to access the route
		if (!$run)
		{
			//        throw new HttpForbiddenException($request, "You do not have permission to access this route.");

			return $this->sendErrorResponse(['msg' => 'You do not have permission to access this route.']);
		}

		return $handler->handle($request);
	}


	private function sendErrorResponse($error, $statusCode = 401)
	{
		$response = new Response();
		$response->getBody()->write(json_encode($error));
		return $response->withHeader('Content-Type', 'application/json')->withStatus($statusCode);
	}
}
