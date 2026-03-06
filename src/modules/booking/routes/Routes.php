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
use App\modules\booking\controllers\AllocationController;
use App\controllers\GenericRegistryController;
use App\modules\booking\models\BookingGenericRegistry;
use App\modules\booking\controllers\WebhookController;
use App\modules\booking\controllers\BuildingController;
use App\modules\booking\controllers\DocumentController;
use App\modules\booking\controllers\ResourceDocumentController;
use App\modules\booking\controllers\OrganizationDocumentController;
use App\modules\booking\viewcontrollers\DocumentViewController;
use App\modules\booking\viewcontrollers\ConfigViewController;
use App\modules\booking\viewcontrollers\RegistryViewController;
use App\modules\booking\viewcontrollers\ApplicationViewController;
use App\modules\booking\controllers\ApplicationController;
use App\modules\phpgwapi\controllers\ConfigController;
use App\modules\booking\controllers\EmailCompareController;
use App\modules\booking\controllers\HospitalityController;
use App\modules\booking\controllers\HospitalityArticleController;
use App\modules\booking\controllers\HospitalityOrderController;
use App\modules\booking\controllers\ArticleMappingController;
use App\modules\booking\viewcontrollers\HospitalityViewController;

$app->group('/booking', function (RouteCollectorProxy $group) use ($container)
{
	$group->group('/buildings', function (RouteCollectorProxy $buildingGroup) use ($container)
	{
		$buildingGroup->get('', BuildingController::class . ':index');

		$buildingGroup->get('/documents/categories', DocumentController::class . ':categories');

		$buildingGroup->get('/documents', DocumentController::class . ':listAll');

		$buildingGroup->group('/{ownerId}/documents', function (RouteCollectorProxy $group) use ($container)
		{
			$group->get('', DocumentController::class . ':index');
			$group->get('/{id}', DocumentController::class . ':show');
			$group->patch('/{id}', DocumentController::class . ':update');
			$group->delete('/{id}', DocumentController::class . ':destroy');
		});
		$buildingGroup->get('/documents/{id}/download', DocumentController::class . ':downloadDocument');
	});

	$group->group('/organizations/{ownerId}/documents', function (RouteCollectorProxy $group)
	{
		$group->get('', OrganizationDocumentController::class . ':index');
		$group->get('/{id}', OrganizationDocumentController::class . ':show');
		$group->patch('/{id}', OrganizationDocumentController::class . ':update');
		$group->delete('/{id}', OrganizationDocumentController::class . ':destroy');
	});

	$group->get('/organizations/documents/{id}/download', OrganizationDocumentController::class . ':downloadDocument');
	$group->get('/resources/documents/{id}/download', ResourceDocumentController::class . ':downloadDocument');

	$group->group('/resources/{ownerId}/documents', function (RouteCollectorProxy $group)
	{
		$group->get('', ResourceDocumentController::class . ':index');
		$group->get('/{id}', ResourceDocumentController::class . ':show');
		$group->patch('/{id}', ResourceDocumentController::class . ':update');
		$group->delete('/{id}', ResourceDocumentController::class . ':destroy');
	});

	// TEMPORARY: Email template comparison endpoint — delete after verification
	$group->get('/email-compare/{id}', EmailCompareController::class . ':compare');

	// TODO: TEMPORARY VIEW GROUP, UNTIL SOMETHING BETTER COMES ALONG
	$group->group('/view', function (RouteCollectorProxy $viewGroup) use ($container)
	{
		$viewGroup->group('/buildings', function (RouteCollectorProxy $buildingGroup) use ($container)
		{
			$buildingGroup->get('/documents', DocumentViewController::class . ':list');
			$buildingGroup->get('/documents/{id}/edit', DocumentViewController::class . ':edit');
		});

		$viewGroup->get('/config/highlighted-buildings', ConfigViewController::class . ':highlightedBuildings');

		$viewGroup->group('/registry', function (RouteCollectorProxy $registryGroup)
		{
			$registryGroup->get('/{type}', RegistryViewController::class . ':index');
			$registryGroup->get('/{type}/add', RegistryViewController::class . ':edit');
			$registryGroup->get('/{type}/{id:[0-9]+}', RegistryViewController::class . ':edit');
		});

		$viewGroup->get('/hospitality', HospitalityViewController::class . ':index');
		$viewGroup->get('/hospitality/{id:[0-9]+}', HospitalityViewController::class . ':show');

		$viewGroup->get('/applications/{id:[0-9]+}', ApplicationViewController::class . ':show');
	});


})
	->addMiddleware(new AccessVerifier($container))
	->addMiddleware(new SessionsMiddleware($container));


$app->group('/booking/users', function (RouteCollectorProxy $group)
{
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

$app->group('/booking/allocations', function (RouteCollectorProxy $group)
{
	$group->post('', AllocationController::class . ':createAllocation');
	$group->get('/{id}', AllocationController::class . ':getAllocation');
	$group->put('/{id}', AllocationController::class . ':updateAllocation');
	$group->delete('/{id}', AllocationController::class . ':deleteAllocation');
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

		// Download all filtered data as CSV
		$typeGroup->get('/download', [$controller, 'download']);

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

$app->group('/booking/applications', function (RouteCollectorProxy $group)
{
	$group->get('/{id:[0-9]+}', ApplicationController::class . ':show');
	$group->post('/{id:[0-9]+}/assign', ApplicationController::class . ':assign');
	$group->post('/{id:[0-9]+}/unassign', ApplicationController::class . ':unassign');
	$group->post('/{id:[0-9]+}/toggle-dashboard', ApplicationController::class . ':toggleDashboard');
	$group->get('/{id:[0-9]+}/dates', ApplicationController::class . ':showDates');
	$group->get('/{id:[0-9]+}/resources', ApplicationController::class . ':showResources');
	$group->get('/{id:[0-9]+}/agegroups', ApplicationController::class . ':showAgegroups');
	$group->get('/{id:[0-9]+}/audience', ApplicationController::class . ':showAudience');
	$group->get('/{id:[0-9]+}/comments', ApplicationController::class . ':showComments');
	$group->get('/{id:[0-9]+}/internal-notes', ApplicationController::class . ':showInternalNotes');
	$group->get('/{id:[0-9]+}/documents', ApplicationController::class . ':showDocuments');
	$group->get('/{id:[0-9]+}/orders', ApplicationController::class . ':showOrders');
	$group->get('/{id:[0-9]+}/associations', ApplicationController::class . ':showAssociations');
	$group->delete('/{id:[0-9]+}/associations/{assocId:[0-9]+}', ApplicationController::class . ':deleteAssociation');
	$group->get('/{id:[0-9]+}/related', ApplicationController::class . ':showRelated');
	$group->get('/{id:[0-9]+}/user-list', ApplicationController::class . ':showUserList');
	$group->post('/{id:[0-9]+}/comment', ApplicationController::class . ':addComment');
	$group->post('/{id:[0-9]+}/internal-note', ApplicationController::class . ':addInternalNote');
	$group->post('/{id:[0-9]+}/message', ApplicationController::class . ':sendMessage');
	$group->post('/{id:[0-9]+}/accept', ApplicationController::class . ':accept');
	$group->post('/{id:[0-9]+}/reject', ApplicationController::class . ':reject');
	$group->post('/{id:[0-9]+}/reassign', ApplicationController::class . ':reassign');
	$group->get('/{id:[0-9]+}/recurring-preview', ApplicationController::class . ':recurringPreview');
	$group->post('/{id:[0-9]+}/create-recurring-allocations', ApplicationController::class . ':createRecurringAllocations');
})
	->addMiddleware(new AccessVerifier($container))
	->addMiddleware(new SessionsMiddleware($container));

$app->group('/booking/config', function (RouteCollectorProxy $group) {
	$group->get('/{appname}', ConfigController::class . ':getConfig');
	$group->put('/{appname}', ConfigController::class . ':updateConfig');
})
	->addMiddleware(new AccessVerifier($container))
	->addMiddleware(new SessionsMiddleware($container));

$app->group('/booking/hospitality', function (RouteCollectorProxy $group) {
	// Core hospitality CRUD
	$group->get('', HospitalityController::class . ':index');
	$group->post('', HospitalityController::class . ':store');
	$group->get('/{id:[0-9]+}', HospitalityController::class . ':show');
	$group->put('/{id:[0-9]+}', HospitalityController::class . ':update');
	$group->delete('/{id:[0-9]+}', HospitalityController::class . ':destroy');

	// Remote locations
	$group->get('/{id:[0-9]+}/remote-locations', HospitalityController::class . ':remoteLocations');
	$group->post('/{id:[0-9]+}/remote-locations', HospitalityController::class . ':addRemoteLocation');
	$group->delete('/{id:[0-9]+}/remote-locations/{resourceId:[0-9]+}', HospitalityController::class . ':removeRemoteLocation');
	$group->patch('/{id:[0-9]+}/remote-locations/{resourceId:[0-9]+}', HospitalityController::class . ':toggleRemoteLocation');

	// Delivery locations
	$group->get('/{id:[0-9]+}/delivery-locations', HospitalityController::class . ':deliveryLocations');

	// Article groups
	$group->get('/{id:[0-9]+}/article-groups', HospitalityArticleController::class . ':indexGroups');
	$group->post('/{id:[0-9]+}/article-groups', HospitalityArticleController::class . ':storeGroup');
	$group->put('/{id:[0-9]+}/article-groups/{groupId:[0-9]+}', HospitalityArticleController::class . ':updateGroup');
	$group->delete('/{id:[0-9]+}/article-groups/{groupId:[0-9]+}', HospitalityArticleController::class . ':destroyGroup');

	// Articles
	$group->get('/{id:[0-9]+}/articles', HospitalityArticleController::class . ':indexArticles');
	$group->post('/{id:[0-9]+}/articles', HospitalityArticleController::class . ':storeArticle');
	$group->put('/{id:[0-9]+}/articles/reorder', HospitalityArticleController::class . ':reorderArticles');
	$group->get('/{id:[0-9]+}/articles/{articleId:[0-9]+}', HospitalityArticleController::class . ':showArticle');
	$group->put('/{id:[0-9]+}/articles/{articleId:[0-9]+}', HospitalityArticleController::class . ':updateArticle');
	$group->delete('/{id:[0-9]+}/articles/{articleId:[0-9]+}', HospitalityArticleController::class . ':destroyArticle');
})
	->addMiddleware(new AccessVerifier($container))
	->addMiddleware(new SessionsMiddleware($container));

$app->group('/booking/hospitality-orders', function (RouteCollectorProxy $group) {
	$group->get('', HospitalityOrderController::class . ':index');
	$group->post('', HospitalityOrderController::class . ':store');
	$group->get('/{id:[0-9]+}', HospitalityOrderController::class . ':show');
	$group->put('/{id:[0-9]+}', HospitalityOrderController::class . ':update');
	$group->patch('/{id:[0-9]+}/status', HospitalityOrderController::class . ':updateStatus');
	$group->delete('/{id:[0-9]+}', HospitalityOrderController::class . ':destroy');

	// Order lines
	$group->post('/{id:[0-9]+}/lines', HospitalityOrderController::class . ':addLine');
	$group->put('/{id:[0-9]+}/lines/{lineId:[0-9]+}', HospitalityOrderController::class . ':updateLine');
	$group->delete('/{id:[0-9]+}/lines/{lineId:[0-9]+}', HospitalityOrderController::class . ':destroyLine');
})
	->addMiddleware(new AccessVerifier($container))
	->addMiddleware(new SessionsMiddleware($container));

$app->group('/booking/article-mappings', function (RouteCollectorProxy $group) {
	$group->post('', ArticleMappingController::class . ':store');
	$group->put('/{id:[0-9]+}', ArticleMappingController::class . ':update');
})
	->addMiddleware(new AccessVerifier($container))
	->addMiddleware(new SessionsMiddleware($container));

$app->get('/booking[/{params:.*}]', RedirectHelper::class . ':process')
	->addMiddleware(new AccessVerifier($container))
	->addMiddleware(new SessionsMiddleware($container));