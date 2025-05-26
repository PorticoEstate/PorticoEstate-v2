<?php

use App\modules\bookingfrontend\controllers\ApplicationController;
use App\modules\bookingfrontend\controllers\BuildingController;
use App\modules\bookingfrontend\controllers\CheckoutController;
use App\modules\bookingfrontend\controllers\CompletedReservationController;
use App\modules\bookingfrontend\controllers\DataStore;
use App\modules\bookingfrontend\controllers\BookingUserController;
use App\modules\bookingfrontend\controllers\EventController;
use App\modules\bookingfrontend\controllers\LoginController;
use App\modules\bookingfrontend\controllers\OrganizationController;
use App\modules\bookingfrontend\controllers\ResourceController;
use App\modules\bookingfrontend\controllers\VersionController;
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
	$group->get('/searchdataalloptimised[/{params:.*}]', DataStore::class . ':SearchDataAllOptimised');
	$group->get('/availableresources[/{params:.*}]', DataStore::class . ':getAvailableResources');
	$group->get('/towns', BuildingController::class . ':getTowns');
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
		$group->get('/{id}/schedule', ResourceController::class . ':getResourceSchedule');
		$group->get('/document/{id}/download', ResourceController::class . ':downloadDocument');

	});

	$group->group('/organizations', function (RouteCollectorProxy $group) {
		$group->get('/my', OrganizationController::class . ':getMyOrganizations');
		$group->get('', DataStore::class . ':getOrganizations');
		$group->post('', OrganizationController::class . ':create');
		$group->get('/lookup/{number}', OrganizationController::class . ':lookup');
		$group->post('/{id}/delegates', OrganizationController::class . ':addDelegate');
		$group->get('/{id}/events', EventController::class . ':getOrganizationEvents');
		$group->get('/list', OrganizationController::class . ':getList');
		$group->get('/{id}', OrganizationController::class . ':getById');
	});

	$group->group('/events', function (RouteCollectorProxy $group)
	{
		$group->get('/upcoming', EventController::class . ':getUpcomingEvents');
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
		$group->get('', ApplicationController::class . ':getApplications');
		$group->post('/simple', ApplicationController::class . ':createSimpleApplication');
		$group->get('/partials', ApplicationController::class . ':getPartials');
		$group->post('/partials', ApplicationController::class . ':createPartial');
		$group->post('/partials/checkout', CheckoutController::class . ':checkout');
		$group->post('/partials/vipps-payment', CheckoutController::class . ':initiateVippsPayment');
		$group->put('/partials/{id}', ApplicationController::class . ':updatePartial');
		$group->patch('/partials/{id}', ApplicationController::class . ':patchApplication');
		$group->post('/{id}/documents', ApplicationController::class . ':uploadDocument');
		$group->delete('/document/{id}', ApplicationController::class . ':deleteDocument');
		$group->get('/document/{id}/download', ApplicationController::class . ':downloadDocument');
		$group->post('/validate-checkout', CheckoutController::class . ':validateCheckout');
		$group->get('/articles', ApplicationController::class . ':getArticlesByResources');
		$group->get('/{id}', ApplicationController::class . ':getApplicationById');
		$group->delete('/{id}', [ApplicationController::class, 'deletePartial']);

	});

	$group->group('/checkout', function (RouteCollectorProxy $group)
	{
		$group->get('/external-payment-eligibility', CheckoutController::class . ':checkExternalPaymentEligibility');
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
	$group->group('/user', function (RouteCollectorProxy $group) {
		$group->get('', BookingUserController::class . ':index');
		$group->patch('', BookingUserController::class . ':update');
		$group->get('/session', BookingUserController::class . ':getSessionId');
		$group->get('/messages', BookingUserController::class . ':getMessages');
		$group->delete('/messages/{id}', BookingUserController::class . ':deleteMessage');
		$group->get('/messages/test', BookingUserController::class . ':createTestMessage');
	});
})->add(new SessionsMiddleware($app->getContainer()));

$app->group('/bookingfrontend/auth', function (RouteCollectorProxy $group) {
	$group->post('/login', LoginController::class . ':login');
	$group->post('/logout', LoginController::class . ':logout');
})->add(new SessionsMiddleware($app->getContainer()));

$app->post('/bookingfrontend/version', VersionController::class . ':setVersion')->add(new SessionsMiddleware($app->getContainer()));
$app->get('/bookingfrontend/version', VersionController::class . ':getVersion')->add(new SessionsMiddleware($app->getContainer()));



$app->get('/bookingfrontend/lang[/{lang}]', LangHelper::class . ':process');
$app->get('/bookingfrontend/login[/{params:.*}]', LoginHelper::class . ':organization')->add(new SessionsMiddleware($app->getContainer()));
$app->get('/bookingfrontend/logout[/{params:.*}]', LogoutHelper::class . ':process')->add(new SessionsMiddleware($app->getContainer()));
$app->get('/bookingfrontend/client[/{params:.*}]', function ($request, $response)
{
	$response = $response->withHeader('Location', '/bookingfrontend/client/');
	return $response;
});