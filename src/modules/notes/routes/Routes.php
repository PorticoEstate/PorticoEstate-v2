<?php

use App\modules\notes\helpers\RedirectHelper;
use App\modules\notes\controllers\NotesController;
use App\modules\notes\viewcontrollers\NotesViewController;
use App\modules\phpgwapi\security\AccessVerifier;
use App\modules\phpgwapi\middleware\SessionsMiddleware;
use Slim\Csrf\Guard;
use Slim\Routing\RouteCollectorProxy;
/** @var \Slim\App $app */
/** @var \DI\Container $container */


$notesCsrfMiddleware = function ($request, $handler) use ($app)
{
	$path = (string) $request->getUri()->getPath();
	$method = strtoupper((string) $request->getMethod());

	// Allow DataTables POST requests on /notes/notes without CSRF requirement
	if ($method === 'POST' && $path === '/notes/notes')
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
		$name = $request->getHeaderLine('csrf_name') ?: $request->getHeaderLine('X-CSRF-NAME') ?: (string) ($query['csrf_name'] ?? '');
		$value = $request->getHeaderLine('csrf_value') ?: $request->getHeaderLine('X-CSRF-VALUE') ?: (string) ($query['csrf_value'] ?? '');
		if ($name !== '')
		{
			$request = $request->withHeader('csrf_name', $name);
		}
		if ($value !== '')
		{
			$request = $request->withHeader('csrf_value', $value);
		}

		$parsed = $request->getParsedBody();
		if (!is_array($parsed) && str_contains(strtolower($request->getHeaderLine('Content-Type')), 'application/json'))
		{
			$decoded = json_decode((string) $request->getBody(), true);
			if (is_array($decoded))
			{
				$request = $request->withParsedBody($decoded);
			}
		}
	}

	$failureHandler = function ($request, $handler) use ($app)
	{
		$response = $app->getResponseFactory()->createResponse(400);
		$response->getBody()->write(json_encode(['error' => 'Failed CSRF check']));
		return $response->withHeader('Content-Type', 'application/json');
	};

	$csrfStorage = null;
	$guard = new Guard($app->getResponseFactory(), 'csrf', $csrfStorage, $failureHandler, 200, 16, true);
	return $guard->process($request, $handler);
};

$app->group('/notes', function (RouteCollectorProxy $group) use ($notesCsrfMiddleware)
{
	$group->group('/view', function (RouteCollectorProxy $viewGroup)
	{
		$viewGroup->get('/notes', NotesViewController::class . ':index');
		$viewGroup->get('/notes/add', NotesViewController::class . ':add');
		$viewGroup->get('/notes/{id:[0-9]+}', NotesViewController::class . ':view');
		$viewGroup->get('/notes/{id:[0-9]+}/edit', NotesViewController::class . ':edit');
		$viewGroup->get('/notes/{id:[0-9]+}/delete', NotesViewController::class . ':delete');
	})->add($notesCsrfMiddleware);

	$group->get('/notes', NotesController::class . ':index');
	$group->post('/notes', NotesController::class . ':store')->add($notesCsrfMiddleware);
	$group->get('/notes/{id:[0-9]+}', NotesController::class . ':show');
	$group->put('/notes/{id:[0-9]+}', NotesController::class . ':update')->add($notesCsrfMiddleware);
	$group->post('/notes/{id:[0-9]+}', NotesController::class . ':update')->add($notesCsrfMiddleware);
	$group->delete('/notes/{id:[0-9]+}', NotesController::class . ':destroy')->add($notesCsrfMiddleware);
	$group->get('/categories', NotesController::class . ':categories');
})
	->addMiddleware(new AccessVerifier($container))
	->addMiddleware(new SessionsMiddleware($container));

$app->get('/notes[/{params:.*}]', RedirectHelper::class . ':process')
	->addMiddleware(new AccessVerifier($container))
	->addMiddleware(new SessionsMiddleware($container));

