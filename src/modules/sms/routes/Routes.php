<?php

use App\modules\phpgwapi\middleware\SessionsMiddleware;
use App\modules\phpgwapi\security\AccessVerifier;
use App\modules\sms\helpers\RedirectHelper;
use App\modules\sms\controllers\pswinController;
use App\modules\sms\controllers\SmsController;
use App\modules\sms\controllers\SmsCommandController;
use App\modules\sms\viewcontrollers\SmsViewController;
use App\modules\sms\viewcontrollers\SmsCommandViewController;
use Slim\Csrf\Guard;
use Slim\Routing\RouteCollectorProxy;
/** @var \Slim\App $app */
/** @var \DI\Container $container */

$app->get('/sms/inc/plugin/gateway/pswin/soap.php', pswinController::class . ':process');
$app->post('/sms/inc/plugin/gateway/pswin/soap.php', pswinController::class . ':process');

$smsCsrfMiddleware = function ($request, $handler) use ($app)
{
	$path = (string) $request->getUri()->getPath();
	$method = strtoupper((string) $request->getMethod());
	if ($method === 'POST' && $path === '/sms/commands')
	{
		$body = (array) ($request->getParsedBody() ?: []);
		$query = $request->getQueryParams();
		if (isset($body['draw']) || isset($body['columns']) || isset($body['order']) || isset($query['draw']))
		{
			return $handler->handle($request);
		}
	}

	if (in_array(strtoupper((string) $request->getMethod()), ['POST', 'PUT', 'PATCH', 'DELETE'], true))
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

		// slim/csrf reads the token from the parsed body first; decode JSON requests here
		// so csrf_name/csrf_value in a JSON payload can be validated (headers with
		// underscores are unreliable across some server/proxy configurations).
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

$app->group('/sms', function (RouteCollectorProxy $group) use ($smsCsrfMiddleware)
{
	$group->group('/view', function (RouteCollectorProxy $viewGroup)
	{
		$viewGroup->get('/inbox', SmsViewController::class . ':inbox');
		$viewGroup->get('/outbox', SmsViewController::class . ':outbox');
		$viewGroup->get('/send', SmsViewController::class . ':send');
		$viewGroup->get('/send-group', SmsViewController::class . ':sendGroup');
		$viewGroup->get('/refresh', SmsViewController::class . ':refresh');
		$viewGroup->get('/command', SmsCommandViewController::class . ':index');
		$viewGroup->get('/command/edit[/{id:[0-9]+}]', SmsCommandViewController::class . ':edit');
		$viewGroup->get('/command/log', SmsCommandViewController::class . ':log');
		$viewGroup->get('/command/redirect', SmsCommandViewController::class . ':redirect');
		$viewGroup->get('/command/{id:[0-9]+}/delete', SmsCommandViewController::class . ':delete');
		$viewGroup->get('/inbox/{id:[0-9]+}/delete', SmsViewController::class . ':deleteInbox');
		$viewGroup->get('/outbox/{id:[0-9]+}/delete', SmsViewController::class . ':deleteOutbox');
	})->add($smsCsrfMiddleware);

	$group->map(['GET', 'POST'], '/inbox', SmsController::class . ':inbox');
	$group->delete('/inbox/{id:[0-9]+}', SmsController::class . ':destroyInbox')->add($smsCsrfMiddleware);
	$group->map(['GET', 'POST'], '/outbox', SmsController::class . ':outbox');
	$group->delete('/outbox/{id:[0-9]+}', SmsController::class . ':destroyOutbox')->add($smsCsrfMiddleware);
	$group->post('/messages', SmsController::class . ':store')->add($smsCsrfMiddleware);
	$group->post('/group-messages', SmsController::class . ':storeGroup')->add($smsCsrfMiddleware);
	$group->post('/refresh', SmsController::class . ':refresh')->add($smsCsrfMiddleware);
	$group->get('/commands', SmsCommandController::class . ':index');
	$group->get('/commands/{id:[0-9]+}', SmsCommandController::class . ':show');
	$group->post('/commands', SmsCommandController::class . ':store')->add($smsCsrfMiddleware);
	$group->put('/commands/{id:[0-9]+}', SmsCommandController::class . ':update')->add($smsCsrfMiddleware);
	$group->delete('/commands/{id:[0-9]+}', SmsCommandController::class . ':destroy')->add($smsCsrfMiddleware);
	$group->map(['GET', 'POST'], '/commands/log', SmsCommandController::class . ':log');
})
	->addMiddleware(new AccessVerifier($container))
	->addMiddleware(new SessionsMiddleware($container));

$app->get('/sms[/{params:.*}]', RedirectHelper::class . ':process')
->addMiddleware(new AccessVerifier($container))
->addMiddleware(new SessionsMiddleware($container));


