<?php

/**
 * Generic Registry Routes
 * Routes for the generic registry controller that handles multiple simple entity types
 * Now using the global GenericRegistryController
 */

use App\controllers\GenericRegistryController;
use App\modules\booking\models\BookingGenericRegistry;
use App\modules\phpgwapi\security\AccessVerifier;
use App\modules\phpgwapi\middleware\SessionsMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app) use ($container)
{
	// Booking module registry routes
	$app->group('/api/booking/registry', function (RouteCollectorProxy $group) use ($container)
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
//	->addMiddleware(new AccessVerifier($container))
	->addMiddleware(new SessionsMiddleware($container));

	// Legacy routes for backward compatibility
	// These routes will auto-detect the module (booking) from the path
	$app->group('/api/registry', function (RouteCollectorProxy $group) use ($container)
	{
		// Create controller instance without specifying registry class (will auto-detect)
		$controller = new GenericRegistryController();

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
//	->addMiddleware(new AccessVerifier($container))
	->addMiddleware(new SessionsMiddleware($container));
};
