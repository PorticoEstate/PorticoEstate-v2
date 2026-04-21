<?php

use App\modules\property\controllers\Bra5Controller;
use App\modules\phpgwapi\middleware\SessionsMiddleware;
use App\modules\phpgwapi\security\AccessVerifier;
use App\modules\property\helpers\RedirectHelper;
use App\modules\property\controllers\TenantController;
use App\modules\property\controllers\TicketController;
use App\modules\property\controllers\EntityController;
use App\controllers\GenericRegistryController;
use Slim\Routing\RouteCollectorProxy;
use App\modules\property\models\PropertyGenericRegistry;


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
	
	// Create controller instance with PropertyGenericRegistry
	$controller = new GenericRegistryController(PropertyGenericRegistry::class);

	// Get available registry types
	$group->get('/types', [$controller, 'types']);

	// Registry type routes
	$group->group('/{type}', function (RouteCollectorProxy $typeGroup) use ($controller) {
		
		// Get schema/field information for a registry type
		$typeGroup->get('/schema', [$controller, 'schema']);

		// Get list for dropdowns/selects
		$typeGroup->get('/list', [$controller, 'getList']);

		// CRUD operations
		$typeGroup->get('', [$controller, 'index']); // List items
		$typeGroup->post('', [$controller, 'store']); // Create new item
		$typeGroup->get('/{id:[0-9]+}', [$controller, 'show']); // Get single item
		$typeGroup->put('/{id:[0-9]+}', [$controller, 'update']); // Update item
		$typeGroup->delete('/{id:[0-9]+}', [$controller, 'delete']); // Delete item
	});
})
->addMiddleware(new AccessVerifier($container))
->addMiddleware(new SessionsMiddleware($container));


// Entity (EAV custom attribute records) Routes
$app->group('/property/entity', function (RouteCollectorProxy $group) use ($container)
{
	$controller = new EntityController($container);

	$group->group('/{type}/{entity_id:[0-9]+}/{cat_id:[0-9]+}', function (RouteCollectorProxy $g) use ($controller)
	{
		// Core CRUD
		$g->get('',                [$controller, 'index']);
		$g->post('',               [$controller, 'index']); // DataTables server-side POST
		$g->post('/create',        [$controller, 'store']);
		$g->get('/{id:[0-9]+}',    [$controller, 'show']);
		$g->put('/{id:[0-9]+}',    [$controller, 'update']);
		$g->delete('/{id:[0-9]+}', [$controller, 'destroy']);

		// Item sub-resources (id in path)
		$g->get('/{id:[0-9]+}/files',     [$controller, 'getFiles']);
		$g->get('/{id:[0-9]+}/related',   [$controller, 'getRelated']);
		$g->get('/{id:[0-9]+}/inventory', [$controller, 'getInventory']);

		// Category-level data queries (id/location_id as query params)
		$g->get('/items-per-qr',        [$controller, 'getItemsPerQr']);
		$g->get('/cases',               [$controller, 'getCases']);
		$g->get('/checklists',          [$controller, 'getChecklists']);
		$g->get('/controls',            [$controller, 'getControlsAtComponent']);
		$g->get('/cases-for-checklist', [$controller, 'getCasesForChecklist']);
	});
})
->addMiddleware(new AccessVerifier($container))
->addMiddleware(new SessionsMiddleware($container));


$app->get('/property[/{params:.*}]', RedirectHelper::class . ':process')
	->addMiddleware(new AccessVerifier($container))
	->addMiddleware(new SessionsMiddleware($container));