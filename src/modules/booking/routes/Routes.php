<?php

use Slim\Routing\RouteCollectorProxy;
use App\modules\booking\controllers\UserController;
use App\modules\booking\helpers\RedirectHelper;
use App\modules\phpgwapi\security\AccessVerifier;
use App\modules\phpgwapi\security\ApiKeyVerifier;
use App\modules\phpgwapi\middleware\SessionsMiddleware;
use App\modules\booking\controllers\VippsController;
use App\modules\booking\controllers\ResourceController;
use App\modules\booking\controllers\EventController;
use App\controllers\GenericRegistryController;
use App\modules\booking\models\BookingGenericRegistry;
use App\modules\booking\controllers\WebhookController;


$app->group('/booking/users', function (RouteCollectorProxy $group) {
	$group->get('', UserController::class . ':index');
	$group->post('', UserController::class . ':store');
	$group->get('/{id}', UserController::class . ':show');
	$group->put('/{id}', UserController::class . ':update');
	$group->delete('/{id}', UserController::class . ':destroy');
})
->addMiddleware(new AccessVerifier($container))
->addMiddleware(new SessionsMiddleware($container));


$app->group('/booking/resources', function (RouteCollectorProxy $group)
{
	$group->get('', ResourceController::class . ':index');
	$group->get('/{id}/schedule', ResourceController::class . ':getResourceSchedule');
	$group->post('/{resource_id}/events', EventController::class . ':createForResource');// Create an event for a specific resource
})
->addMiddleware(new AccessVerifier($container))
->addMiddleware(new SessionsMiddleware($container));

$app->group('/booking/events', function (RouteCollectorProxy $group)
{
	//create an event for array of resources
	$group->post('', EventController::class . ':createEvent');
	$group->put('/{event_id}', EventController::class . ':updateEvent');
	$group->get('/{event_id}', EventController::class . ':getEvent');
	$group->patch('/{event_id}/toggle-active', EventController::class . ':toggleActiveStatus');
})
->addMiddleware(new AccessVerifier($container))
->addMiddleware(new SessionsMiddleware($container));

$app->get('/booking/getpendingtransactions/vipps', VippsController::class . ':getPendingTransactions')
	->addMiddleware(new AccessVerifier($container))
	->addMiddleware(new SessionsMiddleware($container));

// Webhook subscription management API
$app->group('/booking/webhooks', function (RouteCollectorProxy $group)
{
	// Validation endpoint (no auth required)
	$group->get('/validate', WebhookController::class . ':validate');
	
	// Subscription management
	$group->post('/subscriptions', WebhookController::class . ':create');
	$group->get('/subscriptions', WebhookController::class . ':list');
	$group->get('/subscriptions/{id}', WebhookController::class . ':read');
	$group->patch('/subscriptions/{id}', WebhookController::class . ':renew');
	$group->delete('/subscriptions/{id}', WebhookController::class . ':delete');
	$group->get('/subscriptions/{id}/log', WebhookController::class . ':deliveryLog');
})
->addMiddleware(new SessionsMiddleware($container));



$app->group('/booking/registry', function (RouteCollectorProxy $group) use ($container)
{
	// Create controller instance with BookingGenericRegistry
	$controller = new GenericRegistryController(BookingGenericRegistry::class);

	// Get available registry types
	$group->get('/types', [$controller, 'types']);

	// Registry type routes
	$group->group('/{type}', function (RouteCollectorProxy $typeGroup) use ($controller)
	{
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

$app->get('/booking[/{params:.*}]', RedirectHelper::class . ':process')
	->addMiddleware(new AccessVerifier($container))
	->addMiddleware(new SessionsMiddleware($container));