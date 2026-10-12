<?php

use App\modules\emailadmin\controllers\EmailadminController;
use App\modules\emailadmin\helpers\RedirectHelper;
use App\modules\emailadmin\viewcontrollers\EmailadminViewController;
use App\modules\phpgwapi\middleware\SessionsMiddleware;
use App\modules\phpgwapi\security\AccessVerifier;
use Slim\Csrf\Guard;
use Slim\Routing\RouteCollectorProxy;

/** @var \Slim\App $app */
/** @var \DI\Container $container */

$emailadminCsrf = function ($request, $handler) use ($app)
{
	$method = strtoupper((string) $request->getMethod());
	if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true))
	{
		$query = $request->getQueryParams();
		$name = $request->getHeaderLine('csrf_name') ?: $request->getHeaderLine('X-CSRF-NAME') ?: (string) ($query['csrf_name'] ?? '');
		$value = $request->getHeaderLine('csrf_value') ?: $request->getHeaderLine('X-CSRF-VALUE') ?: (string) ($query['csrf_value'] ?? '');
		if ($name !== '') $request = $request->withHeader('csrf_name', $name);
		if ($value !== '') $request = $request->withHeader('csrf_value', $value);

		$parsed = $request->getParsedBody();
		if (!is_array($parsed) && str_contains(strtolower($request->getHeaderLine('Content-Type')), 'application/json'))
		{
			$decoded = json_decode((string) $request->getBody(), true);
			if (is_array($decoded)) $request = $request->withParsedBody($decoded);
		}
	}

	$failure = function ($request, $handler) use ($app)
	{
		$response = $app->getResponseFactory()->createResponse(400);
		$response->getBody()->write('{"error":"Failed CSRF check"}');
		return $response->withHeader('Content-Type', 'application/json');
	};
	$csrfStorage = null;
	$guard = new Guard($app->getResponseFactory(), 'csrf', $csrfStorage, $failure, 200, 16, true);
	return $guard->process($request, $handler);
};

$app->group('/emailadmin', function (RouteCollectorProxy $group) use ($emailadminCsrf)
{
	$group->group('/view', function (RouteCollectorProxy $view)
	{
		$view->get('/profiles', EmailadminViewController::class . ':index');
		$view->get('/profiles/add', EmailadminViewController::class . ':add');
		$view->get('/profiles/{id:[0-9]+}/edit', EmailadminViewController::class . ':edit');
	})->add($emailadminCsrf);

	$group->get('/profiles', EmailadminController::class . ':index');
	$group->get('/profiles/{id:[0-9]+}', EmailadminController::class . ':show');
	$group->post('/profiles', EmailadminController::class . ':store')->add($emailadminCsrf);
	$group->put('/profiles/{id:[0-9]+}', EmailadminController::class . ':update')->add($emailadminCsrf);
	$group->delete('/profiles/{id:[0-9]+}', EmailadminController::class . ':destroy')->add($emailadminCsrf);
	$group->patch('/profiles/order', EmailadminController::class . ':reorder')->add($emailadminCsrf);
})
	->addMiddleware(new AccessVerifier($container))
	->addMiddleware(new SessionsMiddleware($container));

$app->get('/emailadmin[/{params:.*}]', RedirectHelper::class . ':process')
	->addMiddleware(new AccessVerifier($container))
	->addMiddleware(new SessionsMiddleware($container));