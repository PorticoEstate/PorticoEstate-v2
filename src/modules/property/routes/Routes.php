<?php

use App\modules\property\controllers\Bra5Controller;
use App\modules\phpgwapi\middleware\SessionsMiddleware;
use App\modules\phpgwapi\security\AccessVerifier;
use App\modules\property\helpers\RedirectHelper;
use App\modules\property\controllers\TenantController;
use App\modules\property\controllers\TicketController;
use App\modules\property\controllers\LocationController;
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

$app->group('/property/location', function (RouteCollectorProxy $group) use ($container) {
	$controller = new LocationController($container);

	$group->get('', [$controller, 'index']);
	$group->post('', [$controller, 'index']);
	$group->get('/summary', [$controller, 'summary']);
	$group->post('/summary', [$controller, 'summary']);
	$group->get('/responsibility-role', [$controller, 'responsibilityRole']);
	$group->post('/responsibility-role', [$controller, 'responsibilityRole']);
	$group->get('/part-of-town', [$controller, 'getPartOfTown']);
	$group->get('/accounts', [$controller, 'getAccounts']);
	$group->get('/history', [$controller, 'getHistoryData']);
	$group->post('/history', [$controller, 'getHistoryData']);
	$group->get('/documents', [$controller, 'getDocuments']);
	$group->post('/documents', [$controller, 'getDocuments']);
	$group->get('/location-data', [$controller, 'getLocationData']);
	$group->get('/component/controls', [$controller, 'getControlsAtComponent']);
	$group->get('/component/cases', [$controller, 'getCases']);
	$group->get('/component/checklists', [$controller, 'getChecklists']);
	$group->get('/component/cases-for-checklist', [$controller, 'getCasesForChecklist']);
	$group->post('/edit-field', [$controller, 'editField']);
	$group->delete('/{location_code:[^/]+}', [$controller, 'delete']);
})
->addMiddleware(new AccessVerifier($container))
->addMiddleware(new SessionsMiddleware($container));


$app->get('/property[/{params:.*}]', RedirectHelper::class . ':process')
	->addMiddleware(new AccessVerifier($container))
	->addMiddleware(new SessionsMiddleware($container));
