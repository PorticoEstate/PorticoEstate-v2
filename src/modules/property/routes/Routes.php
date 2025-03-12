<?php

use App\modules\property\controllers\Bra5Controller;
use App\modules\phpgwapi\middleware\SessionsMiddleware;
use App\modules\phpgwapi\security\AccessVerifier;
use App\modules\property\helpers\RedirectHelper;
use App\modules\property\controllers\TenantController;
use App\modules\property\controllers\TicketController;


$app->get('/property/inc/soap_client/bra5/soap.php', Bra5Controller::class . ':process');

$app->get('/property/tenant/', TenantController::class . ':show')
	->addMiddleware(new AccessVerifier($container))
	->addMiddleware(new SessionsMiddleware($container));

//get_user_cases
$app->get('/property/usercase/', TicketController::class . ':getUserCases')
	->addMiddleware(new AccessVerifier($container))
	->addMiddleware(new SessionsMiddleware($container));

//get_user_case
$app->get('/property/usercase/{id}/', TicketController::class . ':getUserCase')
	->addMiddleware(new AccessVerifier($container))
	->addMiddleware(new SessionsMiddleware($container));

$app->get('/property[/{params:.*}]', RedirectHelper::class . ':process')
->addMiddleware(new AccessVerifier($container))
->addMiddleware(new SessionsMiddleware($container));



