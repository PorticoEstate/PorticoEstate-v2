<?php

use App\modules\messenger\helpers\RedirectHelper;
use App\modules\messenger\controllers\MessengerController;
use App\modules\messenger\viewcontrollers\MessengerViewController;
use App\modules\phpgwapi\security\AccessVerifier;
use App\modules\phpgwapi\middleware\SessionsMiddleware;
use Slim\Csrf\Guard;
use Slim\Routing\RouteCollectorProxy;
/** @var \Slim\App $app */
/** @var \DI\Container $container */


$messengerCsrfMiddleware = function ($request, $handler) use ($app)
{
	if (in_array(strtoupper((string) $request->getMethod()), ['POST', 'PUT', 'PATCH', 'DELETE'], true))
	{
		$body = (array) ($request->getParsedBody() ?: []);
		$query = $request->getQueryParams();
		if (
			strtoupper((string) $request->getMethod()) === 'POST'
			&& (isset($body['draw']) || isset($body['columns']) || isset($body['order']) || isset($query['draw']))
		)
		{
			return $handler->handle($request);
		}

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

$app->group('/messenger', function (RouteCollectorProxy $group) use ($messengerCsrfMiddleware)
{
	$group->group('/view', function (RouteCollectorProxy $viewGroup)
	{
		$viewGroup->get('/inbox', MessengerViewController::class . ':inbox');
		$viewGroup->get('/compose', MessengerViewController::class . ':compose');
		$viewGroup->get('/compose-groups', MessengerViewController::class . ':composeGroups');
		$viewGroup->get('/messages/{id:[0-9]+}', MessengerViewController::class . ':read');
		$viewGroup->get('/messages/{id:[0-9]+}/reply', MessengerViewController::class . ':reply');
		$viewGroup->get('/messages/{id:[0-9]+}/forward', MessengerViewController::class . ':forward');
		$viewGroup->get('/messages/{id:[0-9]+}/delete', MessengerViewController::class . ':delete');
	})->add($messengerCsrfMiddleware);

	$group->get('/messages', MessengerController::class . ':index');
	$group->get('/messages/users', MessengerController::class . ':users');
	$group->get('/messages/groups', MessengerController::class . ':groups');
	$group->post('/messages', MessengerController::class . ':store')->add($messengerCsrfMiddleware);
	$group->post('/messages/groups', MessengerController::class . ':storeGroups')->add($messengerCsrfMiddleware);
	$group->get('/messages/{id:[0-9]+}', MessengerController::class . ':show');
	$group->post('/messages/{id:[0-9]+}/reply', MessengerController::class . ':reply')->add($messengerCsrfMiddleware);
	$group->post('/messages/{id:[0-9]+}/forward', MessengerController::class . ':forward')->add($messengerCsrfMiddleware);
	$group->delete('/messages', MessengerController::class . ':destroy')->add($messengerCsrfMiddleware);
})
	->addMiddleware(new AccessVerifier($container))
	->addMiddleware(new SessionsMiddleware($container));

$app->get('/messenger[/{params:.*}]', RedirectHelper::class . ':process')
	->addMiddleware(new AccessVerifier($container))
	->addMiddleware(new SessionsMiddleware($container));
