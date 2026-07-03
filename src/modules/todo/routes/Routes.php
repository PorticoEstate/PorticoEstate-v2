<?php

use App\modules\todo\helpers\RedirectHelper;
use App\modules\todo\controllers\TodoController;
use App\modules\todo\viewcontrollers\TodoViewController;
use App\modules\phpgwapi\security\AccessVerifier;
use App\modules\phpgwapi\middleware\SessionsMiddleware;
use Slim\Csrf\Guard;
use Slim\Routing\RouteCollectorProxy;
/** @var \Slim\App $app */
/** @var \DI\Container $container */

$todoCsrfMiddleware = function ($request, $handler) use ($app)
{
	$path = (string) $request->getUri()->getPath();
	$method = strtoupper((string) $request->getMethod());
	$csrfNameKey = 'csrf_name';
	$csrfValueKey = 'csrf_value';

	// Keep DataTables server-side list POST working without CSRF requirement.
	if ($method === 'POST' && $path === '/todo/todos')
	{
		$body = (array) ($request->getParsedBody() ?: []);
		$query = $request->getQueryParams();
		if (
			isset($body['draw'])
			|| isset($body['columns'])
			|| isset($body['order'])
			|| isset($query['draw'])
			|| isset($query['columns'])
			|| isset($query['order'])
		)
		{
			return $handler->handle($request);
		}
	}

	if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true))
	{
		$query = $request->getQueryParams();
		$headerName = $request->getHeaderLine($csrfNameKey);
		$headerValue = $request->getHeaderLine($csrfValueKey);

		// Accept alternative header names and query params as fallback, then map
		// to the header keys expected by slim/csrf.
		if ($headerName === '' && $request->getHeaderLine('X-CSRF-NAME') !== '')
		{
			$headerName = $request->getHeaderLine('X-CSRF-NAME');
		}
		if ($headerValue === '' && $request->getHeaderLine('X-CSRF-VALUE') !== '')
		{
			$headerValue = $request->getHeaderLine('X-CSRF-VALUE');
		}
		if ($headerName === '' && !empty($query[$csrfNameKey]))
		{
			$headerName = (string) $query[$csrfNameKey];
		}
		if ($headerValue === '' && !empty($query[$csrfValueKey]))
		{
			$headerValue = (string) $query[$csrfValueKey];
		}
		if ($headerName !== '')
		{
			$request = $request->withHeader($csrfNameKey, $headerName);
		}
		if ($headerValue !== '')
		{
			$request = $request->withHeader($csrfValueKey, $headerValue);
		}

		// slim/csrf reads parsed body arrays; decode JSON requests here so
		// csrf_name/csrf_value in JSON payload can be validated.
		$parsed = $request->getParsedBody();
		$contentType = strtolower($request->getHeaderLine('Content-Type'));
		if (!is_array($parsed) && str_contains($contentType, 'application/json'))
		{
			$raw = (string) $request->getBody();
			if ($raw !== '')
			{
				$decoded = json_decode($raw, true);
				if (is_array($decoded))
				{
					$request = $request->withParsedBody($decoded);
				}
			}
		}
	}

	$failureHandler = function ($request, $handler) use ($app)
	{
		$response = $app->getResponseFactory()->createResponse(400);
		$path = (string) $request->getUri()->getPath();
		$isApi = str_starts_with($path, '/todo/todos');

		if ($isApi)
		{
			$payload = json_encode(['error' => 'Failed CSRF check']);
			$response->getBody()->write($payload !== false ? $payload : '{"error":"Failed CSRF check"}');
			return $response->withHeader('Content-Type', 'application/json');
		}

		$response->getBody()->write('Failed CSRF check');
		return $response->withHeader('Content-Type', 'text/plain');
	};

	$csrfStorage = null;
	$guard = new Guard($app->getResponseFactory(), 'csrf', $csrfStorage, $failureHandler, 200, 16, true);
	return $guard->process($request, $handler);
};


$app->group('/todo', function (RouteCollectorProxy $group) use ($todoCsrfMiddleware)
{
	$group->group('/view', function (RouteCollectorProxy $viewGroup)
	{
		$viewGroup->get('/todos', TodoViewController::class . ':index');
		$viewGroup->map(['GET', 'POST'], '/todos/matrix', TodoViewController::class . ':matrix');
		$viewGroup->get('/todos/add', TodoViewController::class . ':add');
		$viewGroup->get('/todos/{id:[0-9]+}', TodoViewController::class . ':view');
		$viewGroup->get('/todos/{id:[0-9]+}/delete', TodoViewController::class . ':delete');
		$viewGroup->get('/todos/{id:[0-9]+}/edit', TodoViewController::class . ':edit');
	})->add($todoCsrfMiddleware);

	$group->group('/todos', function (RouteCollectorProxy $todoGroup) use ($todoCsrfMiddleware)
	{
		$todoGroup->get('', TodoController::class . ':index');
		$todoGroup->get('/export/csv', TodoController::class . ':exportCsv');
		$todoGroup->post('', TodoController::class . ':store')->add($todoCsrfMiddleware);
		$todoGroup->get('/{id:[0-9]+}', TodoController::class . ':show');
		$todoGroup->put('/{id:[0-9]+}', TodoController::class . ':update')->add($todoCsrfMiddleware);
		$todoGroup->patch('/{id:[0-9]+}/status', TodoController::class . ':updateStatus')->add($todoCsrfMiddleware);
		$todoGroup->delete('/{id:[0-9]+}', TodoController::class . ':destroy')->add($todoCsrfMiddleware);
	});

	$group->get('/categories', TodoController::class . ':categories');
})
	->addMiddleware(new AccessVerifier($container))
	->addMiddleware(new SessionsMiddleware($container));


$app->get('/todo[/{params:.*}]', RedirectHelper::class . ':process')
	->addMiddleware(new AccessVerifier($container))
	->addMiddleware(new SessionsMiddleware($container));
