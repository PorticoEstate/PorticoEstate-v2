<?php

use App\modules\phpgwapi\middleware\SessionsMiddleware;
use App\modules\phpgwapi\security\AccessVerifier;
use App\modules\sms\helpers\RedirectHelper;
use App\modules\sms\controllers\pswinController;
use App\modules\sms\controllers\SmsController;
use App\modules\sms\viewcontrollers\SmsViewController;
use Slim\Csrf\Guard;
use Slim\Routing\RouteCollectorProxy;
/** @var \Slim\App $app */
/** @var \DI\Container $container */

$app->get('/sms/inc/plugin/gateway/pswin/soap.php', pswinController::class . ':process');
$app->post('/sms/inc/plugin/gateway/pswin/soap.php', pswinController::class . ':process');

$smsCsrfMiddleware = function ($request, $handler) use ($app)
{
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
		$viewGroup->get('/inbox/{id:[0-9]+}/delete', SmsViewController::class . ':deleteInbox');
		$viewGroup->get('/outbox/{id:[0-9]+}/delete', SmsViewController::class . ':deleteOutbox');
	})->add($smsCsrfMiddleware);

	$group->map(['GET', 'POST'], '/inbox', SmsController::class . ':inbox');
	$group->delete('/inbox/{id:[0-9]+}', SmsController::class . ':destroyInbox')->add($smsCsrfMiddleware);
	$group->map(['GET', 'POST'], '/outbox', SmsController::class . ':outbox');
	$group->delete('/outbox/{id:[0-9]+}', SmsController::class . ':destroyOutbox')->add($smsCsrfMiddleware);
	$group->post('/messages', SmsController::class . ':store')->add($smsCsrfMiddleware);
})
	->addMiddleware(new AccessVerifier($container))
	->addMiddleware(new SessionsMiddleware($container));

$app->get('/sms[/{params:.*}]', RedirectHelper::class . ':process')
->addMiddleware(new AccessVerifier($container))
->addMiddleware(new SessionsMiddleware($container));


