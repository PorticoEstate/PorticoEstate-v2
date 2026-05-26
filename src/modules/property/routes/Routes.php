<?php

use App\modules\property\controllers\Bra5Controller;
use App\modules\phpgwapi\middleware\SessionsMiddleware;
use App\modules\phpgwapi\security\AccessVerifier;
use App\modules\property\helpers\RedirectHelper;
use App\modules\property\controllers\TenantController;
use App\modules\property\controllers\TicketController;
use App\modules\property\controllers\LocationController;
use App\modules\property\controllers\EntityController;
use App\modules\property\controllers\ProjectController;
use App\controllers\GenericRegistryController;
use Slim\Routing\RouteCollectorProxy;
use App\modules\property\models\PropertyGenericRegistry;

/** @var \Slim\App $app */
/** @var \DI\Container $container */


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

$app->group('/property/location', function (RouteCollectorProxy $group) use ($container) {
	$controller = new LocationController($container);

	// Hybrid approach routes (explicit form helper orchestration)
	$group->post('', [$controller, 'postCollection']);
	$group->post('/add', [$controller, 'add']);
	$group->put('/{location_code:[^/]+}', [$controller, 'save']);
	$group->get('/list', [$controller, 'listLocations']);
	$group->post('/list', [$controller, 'listLocations']);
	$group->post('/datatable', [$controller, 'index']);

	// Thin adapter routes (legacy UI delegation)
	$group->get('', [$controller, 'index']);
	$group->get('/summary', [$controller, 'summary']);
	$group->post('/summary', [$controller, 'summary']);
	$group->get('/summary/query', [$controller, 'querySummary']);
	$group->post('/summary/query', [$controller, 'querySummary']);
	$group->get('/responsibility-role', [$controller, 'responsibilityRole']);
	$group->post('/responsibility-role', [$controller, 'responsibilityRole']);
	$group->get('/responsibility-role/query', [$controller, 'queryRole']);
	$group->post('/responsibility-role/query', [$controller, 'queryRole']);
	$group->post('/responsibility-role/save', [$controller, 'responsibilityRoleSave']);
	$group->get('/part-of-town', [$controller, 'getPartOfTown']);
	$group->get('/accounts', [$controller, 'getAccounts']);
	$group->get('/history', [$controller, 'getHistoryData']);
	$group->post('/history', [$controller, 'getHistoryData']);
	$group->get('/history/list', [$controller, 'listHistory']);
	$group->post('/history/list', [$controller, 'listHistory']);
	$group->get('/documents', [$controller, 'getDocuments']);
	$group->post('/documents', [$controller, 'getDocuments']);
	$group->get('/documents/list', [$controller, 'listDocuments']);
	$group->post('/documents/list', [$controller, 'listDocuments']);
	$group->get('/location-data', [$controller, 'getLocationData']);
	$group->get('/delivery-address', [$controller, 'getDeliveryAddress']);
	$group->post('/delivery-address', [$controller, 'getDeliveryAddress']);
	$group->get('/location-exception', [$controller, 'getLocationException']);
	$group->post('/location-exception', [$controller, 'getLocationException']);
	$group->get('/component/controls', [$controller, 'getControlsAtComponent']);
	$group->get('/component/cases', [$controller, 'getCases']);
	$group->get('/component/checklists', [$controller, 'getChecklists']);
	$group->get('/component/cases-for-checklist', [$controller, 'getCasesForChecklist']);
	$group->post('/component/add-control', [$controller, 'addControl']);
	$group->post('/component/update-control-serie', [$controller, 'updateControlSerie']);
	$group->post('/edit-field', [$controller, 'editField']);
	$group->get('/download', [$controller, 'download']);
	$group->map(['GET', 'POST'], '/delete', [$controller, 'deleteByLocationCode']);
	$group->delete('/{location_code:[^/]+}', [$controller, 'delete']);
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
		$g->post('',               [$controller, 'postCollection']);
		$g->post('/datatable',     [$controller, 'index']); // DataTables server-side POST
		$g->get('/list',           [$controller, 'listItems']);
		$g->post('/list',          [$controller, 'listItems']);
		$g->post('/create',        [$controller, 'store']);
		$g->get('/download',       [$controller, 'download']);
		$g->get('/{id:[0-9]+}',    [$controller, 'show']);
		$g->put('/{id:[0-9]+}',    [$controller, 'update']);
		$g->delete('/{id:[0-9]+}', [$controller, 'destroy']);

		// Item sub-resources (id in path)
		$g->post('/{id:[0-9]+}/files',     [$controller, 'getFiles']);
		$g->post('/{id:[0-9]+}/related',   [$controller, 'getRelated']);
		$g->post('/{id:[0-9]+}/target',    [$controller, 'getTarget']);
		$g->post('/{id:[0-9]+}/documents', [$controller, 'getDocuments']);
		$g->post('/{id:[0-9]+}/inventory', [$controller, 'getInventory']);
		$g->get('/{id:[0-9]+}/multi-upload', [$controller, 'buildMultiUploadFile']);
		$g->map(['POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], '/{id:[0-9]+}/multi-upload', [$controller, 'handleMultiUploadFile']);
		$g->map(['GET', 'POST'], '/{id:[0-9]+}/inventory/add', [$controller, 'addInventoryPopup']);
		$g->map(['GET', 'POST'], '/{id:[0-9]+}/inventory/{inventory_id:[0-9]+}/edit', [$controller, 'editInventoryPopup']);
		$g->get('/{id:[0-9]+}/inventory/{inventory_id:[0-9]+}/calendar', [$controller, 'inventoryCalendarPopup']);

		// Category-level data queries (id/location_id as query params)
		$g->get('/items-per-qr',        [$controller, 'getItemsPerQr']);
		$g->get('/cases',               [$controller, 'getCases']);
		$g->get('/checklists',          [$controller, 'getChecklists']);
		$g->get('/controls',            [$controller, 'getControlsAtComponent']);
		$g->get('/cases-for-checklist', [$controller, 'getCasesForChecklist']);
		$g->get('/assigned-history',    [$controller, 'assignedHistoryPopup']);
	});
})
->addMiddleware(new AccessVerifier($container))
->addMiddleware(new SessionsMiddleware($container));


$app->group('/property/project', function (RouteCollectorProxy $group) use ($container)
{
	$controller = new ProjectController($container);

	$group->get('', [$controller, 'index']);
	$group->post('', [$controller, 'postCollection']);
	$group->post('/datatable', [$controller, 'index']);
	$group->get('/list', [$controller, 'listProjects']);
	$group->post('/list', [$controller, 'listProjects']);
	$group->post('/create', [$controller, 'store']);
	$group->get('/{id:[0-9]+}/orders', [$controller, 'getOrders']);
	$group->post('/{id:[0-9]+}/orders', [$controller, 'getOrders']);
	$group->get('/{id:[0-9]+}', [$controller, 'show']);
	$group->put('/{id:[0-9]+}', [$controller, 'update']);
	$group->delete('/{id:[0-9]+}', [$controller, 'destroy']);
})
->addMiddleware(new AccessVerifier($container))
->addMiddleware(new SessionsMiddleware($container));


$app->get('/property[/{params:.*}]', RedirectHelper::class . ':process')
	->addMiddleware(new AccessVerifier($container))
	->addMiddleware(new SessionsMiddleware($container));