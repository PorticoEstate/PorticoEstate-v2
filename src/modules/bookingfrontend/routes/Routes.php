<?php

use App\modules\bookingfrontend\controllers\ApplicationController;
use App\modules\bookingfrontend\controllers\BuildingController;
use App\modules\bookingfrontend\controllers\CompletedReservationController;
use App\modules\bookingfrontend\controllers\DataStore;
use App\modules\bookingfrontend\controllers\BookingUserController;
use App\modules\bookingfrontend\controllers\EventController;
use App\modules\bookingfrontend\controllers\LoginController;
use App\modules\bookingfrontend\controllers\OrganizationController;
use App\modules\bookingfrontend\controllers\ResourceController;
use App\modules\bookingfrontend\helpers\LangHelper;
use App\modules\bookingfrontend\helpers\LoginHelper;
use App\modules\bookingfrontend\helpers\LogoutHelper;
use App\modules\phpgwapi\controllers\StartPoint;
use App\modules\phpgwapi\middleware\SessionsMiddleware;
use Slim\Routing\RouteCollectorProxy;
use App\modules\bookingfrontend\helpers\UserHelper;



$app->group('/bookingfrontend', function (RouteCollectorProxy $group)
{

	$group->get('/userhelper/callback[/{params:.*}]', UserHelper::class . ':process_callback');
	$group->get('/searchdataall[/{params:.*}]', DataStore::class . ':SearchDataAll');
	$group->group('/buildings', function (RouteCollectorProxy $group)
	{
		$group->get('', BuildingController::class . ':index');
		$group->get('/{id}', BuildingController::class . ':show');
		$group->get('/{id}/resources', ResourceController::class . ':getResourcesByBuilding');
		$group->get('/{id}/documents', BuildingController::class . ':getDocuments');
		$group->get('/document/{id}/download', BuildingController::class . ':downloadDocument');
		$group->get('/{id}/schedule', BuildingController::class . ':getSchedule');
		$group->get('/{id}/agegroups', BuildingController::class . ':getAgeGroups');
		$group->get('/{id}/audience', BuildingController::class . ':getAudience');
		$group->get('/{id}/seasons', BuildingController::class . ':getSeasons');
	});

	$group->group('/resources', function (RouteCollectorProxy $group)
	{
		$group->get('', ResourceController::class . ':index');
		$group->get('/{id}', ResourceController::class . ':getResource');
		$group->get('/{id}/documents', ResourceController::class . ':getDocuments');
		$group->get('/document/{id}/download', ResourceController::class . ':downloadDocument');

	});

	$group->group('/organizations', function (RouteCollectorProxy $group) {
		$group->get('/my', OrganizationController::class . ':getMyOrganizations');
		$group->post('', OrganizationController::class . ':create');
		$group->get('/lookup/{number}', OrganizationController::class . ':lookup');
		$group->post('/{id}/delegates', OrganizationController::class . ':addDelegate');
		$group->get('/list', OrganizationController::class . ':getList');
	});

	$group->group('/events', function (RouteCollectorProxy $group)
	{
		$group->get('/{id}', EventController::class . ':getEventById');
		$group->patch('/{id}', EventController::class . ':updateEvent');
		$group->post('/{id}/pre-registration', EventController::class . ':preRegister');
		$group->post('/{id}/in-registration', EventController::class . ':inRegistration');
		$group->patch('/{id}/out-registration', EventController::class . ':outRegistration');
	});
})->add(new SessionsMiddleware($app->getContainer()));

// Session group
$app->group('/bookingfrontend', function (RouteCollectorProxy $group)
{
	$group->group('/applications', function (RouteCollectorProxy $group)
	{
		$group->post('/simple', ApplicationController::class . ':createSimpleApplication');
		$group->get('/partials', ApplicationController::class . ':getPartials');
		$group->post('/partials', ApplicationController::class . ':createPartial');
		$group->post('/partials/checkout', ApplicationController::class . ':checkoutPartials');
		$group->put('/partials/{id}', ApplicationController::class . ':updatePartial');
		$group->get('', ApplicationController::class . ':getApplications');
		$group->delete('/{id}', [ApplicationController::class, 'deletePartial']);
		$group->patch('/partials/{id}', ApplicationController::class . ':patchApplication');
		$group->post('/{id}/documents', ApplicationController::class . ':uploadDocument');
		$group->delete('/document/{id}', ApplicationController::class . ':deleteDocument');
		$group->get('/document/{id}/download', ApplicationController::class . ':downloadDocument');
		$group->post('/validate-checkout', ApplicationController::class . ':validateCheckout');

	});
	$group->get('/invoices', CompletedReservationController::class . ':getReservations');
})->add(new SessionsMiddleware($app->getContainer()));


$app->get('/bookingfrontend/', StartPoint::class . ':bookingfrontend')->add(new SessionsMiddleware($app->getContainer()));
$app->post('/bookingfrontend/', StartPoint::class . ':bookingfrontend')->add(new SessionsMiddleware($app->getContainer()));
//legacy routes
$app->get('/bookingfrontend/index.php', StartPoint::class . ':bookingfrontend')->add(new SessionsMiddleware($app->getContainer()));
$app->post('/bookingfrontend/index.php', StartPoint::class . ':bookingfrontend')->add(new SessionsMiddleware($app->getContainer()));


$app->group('/bookingfrontend', function (RouteCollectorProxy $group)
{
	$group->get('/user', BookingUserController::class . ':index');
	$group->patch('/user', BookingUserController::class . ':update');
})->add(new SessionsMiddleware($app->getContainer()));

$app->group('/bookingfrontend/auth', function (RouteCollectorProxy $group) {
	$group->post('/login', LoginController::class . ':login');
	$group->post('/logout', LoginController::class . ':logout');
})->add(new SessionsMiddleware($app->getContainer()));


$app->get('/bookingfrontend/lang[/{lang}]', LangHelper::class . ':process');
$app->get('/bookingfrontend/login[/{params:.*}]', LoginHelper::class . ':organization')->add(new SessionsMiddleware($app->getContainer()));
$app->get('/bookingfrontend/logout[/{params:.*}]', LogoutHelper::class . ':process')->add(new SessionsMiddleware($app->getContainer()));
$app->get('/bookingfrontend/client[/{params:.*}]', function ($request, $response)
{
	$response = $response->withHeader('Location', '/bookingfrontend/client/');
	return $response;
});


$app->get('/swagger[/]', function ($request, $response) use ($container)
{
	// Check if user is authenticated
	$sessions = \App\modules\phpgwapi\security\Sessions::getInstance();
	if (!$sessions->verify())
	{
		// Redirect to login if not authenticated
		return $response->withHeader('Location', '/login')
			->withStatus(302);
	}

	// Check if user has admin role/permissions
	// $userInfo = $sessions->get_user();
	// if (empty($userInfo['apps']['admin']['enabled']))
	// {
	// 	$response = $response->withStatus(403)
	// 		->withHeader('Content-Type', 'text/html');
	// 	$response->getBody()->write('<h1>Access Denied</h1><p>You need administrator privileges to access API documentation.</p>');
	// 	return $response;
	// }

	// If authorized, show Swagger UI directly using the controller
	$swaggerController = new \App\modules\phpgwapi\controllers\SwaggerController();
	return $swaggerController->index($request, $response);
})->setName('api-docs');

// Add a route for the spec file
$app->get('/swagger/spec', function ($request, $response) use ($container)
{
	// Same authentication checks
	$sessions = \App\modules\phpgwapi\security\Sessions::getInstance();
	if (!$sessions->verify())
	{
		return $response->withStatus(401)->withJson(['error' => 'Authentication required']);
	}

	$userInfo = $sessions->get_user();
	if (empty($userInfo['apps']['admin']['enabled']))
	{
		return $response->withStatus(403)->withJson(['error' => 'Admin privileges required']);
	}

	// Serve the spec
	$swaggerController = new \App\modules\phpgwapi\controllers\SwaggerController();
	return $swaggerController->getSpec($request, $response);
});