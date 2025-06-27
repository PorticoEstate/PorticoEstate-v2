<?php

/**
 * Generic Registry Routes
 * Routes for the generic registry controller that handles multiple simple entity types
 */

use App\modules\booking\controllers\GenericRegistryController;
use App\modules\phpgwapi\security\AccessVerifier;
use App\modules\phpgwapi\middleware\SessionsMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app) use ($container)
{
	$app->group('/api/registry', function (RouteCollectorProxy $group)
	{

		// Get available registry types
		$group->get('/types', [GenericRegistryController::class, 'types']);

		// Registry type routes
		$group->group('/{type}', function (RouteCollectorProxy $typeGroup)
		{

			// Get schema/field information for a registry type
			$typeGroup->get('/schema', [GenericRegistryController::class, 'schema']);

			// Get list for dropdowns/selects
			$typeGroup->get('/list', [GenericRegistryController::class, 'getList']);

			// CRUD operations
			$typeGroup->get('', [GenericRegistryController::class, 'index']); // List items
			$typeGroup->post('', [GenericRegistryController::class, 'store']); // Create new item
			$typeGroup->get('/{id:[0-9]+}', [GenericRegistryController::class, 'show']); // Get single item
			$typeGroup->put('/{id:[0-9]+}', [GenericRegistryController::class, 'update']); // Update item
			$typeGroup->delete('/{id:[0-9]+}', [GenericRegistryController::class, 'delete']); // Delete item
		});
	})
//	->addMiddleware(new AccessVerifier($container))
	->addMiddleware(new SessionsMiddleware($container));
};
