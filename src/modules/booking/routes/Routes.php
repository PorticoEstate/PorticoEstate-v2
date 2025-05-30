<?php

use Slim\Routing\RouteCollectorProxy;
use App\modules\booking\controllers\UserController;
use App\modules\booking\helpers\RedirectHelper;
use App\modules\phpgwapi\security\AccessVerifier;
use App\modules\phpgwapi\security\ApiKeyVerifier;
use App\modules\phpgwapi\middleware\SessionsMiddleware;
use App\modules\booking\controllers\VippsController;

$app->group('/booking/users', function (RouteCollectorProxy $group) {
	$group->get('', UserController::class . ':index');
	$group->post('', UserController::class . ':store');
	$group->get('/{id}', UserController::class . ':show');
	$group->put('/{id}', UserController::class . ':update');
	$group->delete('/{id}', UserController::class . ':destroy');
})
->addMiddleware(new AccessVerifier($container))
->addMiddleware(new SessionsMiddleware($container))
//->addMiddleware(new ApiKeyVerifier($container))
;


$app->get('/booking/getpendingtransactions/vipps', VippsController::class . ':getPendingTransactions')
	->addMiddleware(new AccessVerifier($container))
	->addMiddleware(new SessionsMiddleware($container));

$app->get('/booking[/{params:.*}]', RedirectHelper::class . ':process')
	->addMiddleware(new AccessVerifier($container))
	->addMiddleware(new SessionsMiddleware($container));

