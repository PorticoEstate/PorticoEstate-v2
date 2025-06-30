<?php

use App\modules\property\controllers\Bra5Controller;
use App\modules\phpgwapi\middleware\SessionsMiddleware;
use App\modules\phpgwapi\security\AccessVerifier;
use App\modules\property\helpers\RedirectHelper;
use App\modules\property\controllers\TenantController;
use App\modules\property\controllers\TicketController;
use App\controllers\GenericRegistryController;
use Slim\Routing\RouteCollectorProxy;


$app->get('/property/inc/soap_client/bra5/soap.php', Bra5Controller::class . ':process');

$app->get('/property/tenant/', TenantController::class . ':show')
	->addMiddleware(new AccessVerifier($container))
	->addMiddleware(new SessionsMiddleware($container));

$app->get('/property/tenantbylocation/{location_code}/', TenantController::class . ':ByLocation')
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


$app->post('/property/usercase/{caseId}/response/', TicketController::class . ':addUserCaseResponse')
	->addMiddleware(new AccessVerifier($container))
	->addMiddleware(new SessionsMiddleware($container));

// Property Registry Routes
$app->group('/property/registry', function (RouteCollectorProxy $group) use ($container) {
	
	// List all available registry types
	$group->get('/types', GenericRegistryController::class . ':types');
	
	// Registry type specific routes
	$group->get('/{type}', GenericRegistryController::class . ':index');
	
	$group->get('/{type}/schema', GenericRegistryController::class . ':schema');
	
	$group->get('/{type}/{id}', GenericRegistryController::class . ':show');
	
	$group->post('/{type}', GenericRegistryController::class . ':store');
	
	$group->put('/{type}/{id}', GenericRegistryController::class . ':update');
	
	$group->delete('/{type}/{id}', GenericRegistryController::class . ':delete');
})
->addMiddleware(new AccessVerifier($container))
->addMiddleware(new SessionsMiddleware($container));


$app->get('/property[/{params:.*}]', RedirectHelper::class . ':process')
	->addMiddleware(new AccessVerifier($container))
	->addMiddleware(new SessionsMiddleware($container));
