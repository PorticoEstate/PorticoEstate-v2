<?php

use App\modules\bookingfrontend\controllers\applications\ApplicationController;
use App\modules\bookingfrontend\controllers\BuildingController;
use App\modules\bookingfrontend\controllers\applications\CheckoutController;
use App\modules\bookingfrontend\controllers\applications\CommentsController;
use App\modules\bookingfrontend\controllers\ScheduleEntityController;
use App\modules\bookingfrontend\controllers\CompletedReservationController;
use App\modules\bookingfrontend\controllers\DataStore;
use App\modules\bookingfrontend\controllers\BookingUserController;
use App\modules\bookingfrontend\controllers\DebugController;
use App\modules\bookingfrontend\controllers\EventController;
use App\modules\bookingfrontend\controllers\LoginController;
use App\modules\bookingfrontend\controllers\MultiDomainController;
use App\modules\bookingfrontend\controllers\OrganizationController;
use App\modules\bookingfrontend\controllers\ResourceController;
use App\modules\bookingfrontend\controllers\VersionController;
use App\modules\bookingfrontend\helpers\LangHelper;
use App\modules\bookingfrontend\helpers\LoginHelper;
use App\modules\bookingfrontend\helpers\LogoutHelper;
use App\modules\phpgwapi\controllers\StartPoint;
use App\modules\phpgwapi\middleware\SessionsMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
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
		$group->get('/{id}/schedule', ScheduleEntityController::class . ':getBuildingSchedule');
		$group->get('/{id}/agegroups', BuildingController::class . ':getAgeGroups');
		$group->get('/{id}/audience', BuildingController::class . ':getAudience');
		$group->get('/{id}/seasons', BuildingController::class . ':getSeasons');
	});

	$group->group('/resources', function (RouteCollectorProxy $group)
	{
		$group->get('', ResourceController::class . ':index');
		$group->get('/{id}', ResourceController::class . ':getResource');
		$group->get('/{id}/documents', ResourceController::class . ':getDocuments');
		$group->get('/{id}/schedule', ScheduleEntityController::class . ':getResourceSchedule');
		$group->get('/document/{id}/download', ResourceController::class . ':downloadDocument');

	});

	$group->group('/organizations', function (RouteCollectorProxy $group) {
		$group->get('/my', OrganizationController::class . ':getMyOrganizations');
		$group->get('', DataStore::class . ':getOrganizations');
		$group->post('', OrganizationController::class . ':create');
		$group->get('/lookup/{number}', OrganizationController::class . ':lookup');
		$group->post('/{id}/delegates', OrganizationController::class . ':addDelegate');
		$group->get('/{id}/delegates', OrganizationController::class . ':getDelegates');
		$group->put('/{id}/delegates/{delegate_id}', OrganizationController::class . ':updateDelegate');
		$group->delete('/{id}/delegates/{delegate_id}', OrganizationController::class . ':removeDelegate');
		$group->get('/{id}/groups', OrganizationController::class . ':getGroups');
		$group->post('/{id}/groups', OrganizationController::class . ':createGroup');
		$group->put('/{id}/groups/{group_id}', OrganizationController::class . ':updateGroup');
		$group->get('/{id}/buildings', OrganizationController::class . ':getBuildings');
		$group->get('/{id}/documents', OrganizationController::class . ':getDocuments');
		$group->get('/document/{id}/download', OrganizationController::class . ':downloadDocument');
		$group->get('/{id}/schedule', ScheduleEntityController::class . ':getOrganizationSchedule');
		$group->get('/{id}/events', EventController::class . ':getOrganizationEvents');
		$group->get('/list', OrganizationController::class . ':getList');
		$group->put('/{id}', OrganizationController::class . ':update');
		$group->get('/{id}', OrganizationController::class . ':getById');
	});

	$group->group('/multi-domains', function (RouteCollectorProxy $group) {
		$group->get('', MultiDomainController::class . ':getMultiDomains');
//		$group->post('', MultiDomainController::class . ':createMultiDomain');
		$group->get('/{id}', MultiDomainController::class . ':getMultiDomainById');
//		$group->put('/{id}', MultiDomainController::class . ':updateMultiDomain');
//		$group->delete('/{id}', MultiDomainController::class . ':deleteMultiDomain');
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
		$group->get('/{id}/documents', ApplicationController::class . ':getDocuments');
		$group->post('/{id}/documents', ApplicationController::class . ':uploadDocument');
		$group->delete('/document/{id}', ApplicationController::class . ':deleteDocument');
		$group->get('/document/{id}/download', ApplicationController::class . ':downloadDocument');
		$group->post('/validate-checkout', CheckoutController::class . ':validateCheckout');
		$group->get('/articles', ApplicationController::class . ':getArticlesByResources');
		$group->get('/{id}', ApplicationController::class . ':getApplicationById');
		$group->get('/{id}/schedule', ScheduleEntityController::class . ':getApplicationSchedule');
		$group->delete('/{id}', [ApplicationController::class, 'deletePartial']);

		// Comments endpoints
		$group->get('/{id}/comments', CommentsController::class . ':getApplicationComments');
		$group->post('/{id}/comments', CommentsController::class . ':addApplicationComment');
		$group->get('/{id}/comments/stats', CommentsController::class . ':getApplicationCommentStats');
		$group->put('/{id}/status', CommentsController::class . ':updateApplicationStatus');

	});

	$group->group('/checkout', function (RouteCollectorProxy $group)
	{
		$group->get('/external-payment-eligibility', CheckoutController::class . ':checkExternalPaymentEligibility');

		// Vipps payment endpoints
		$group->group('/vipps', function (RouteCollectorProxy $group)
		{
			$group->post('/check-payment-status', CheckoutController::class . ':checkVippsPaymentStatus');
			$group->get('/payment-details/{payment_order_id}', CheckoutController::class . ':getVippsPaymentDetails');
			$group->post('/cancel-payment', CheckoutController::class . ':cancelVippsPayment');
			$group->post('/refund-payment', CheckoutController::class . ':refundVippsPayment');
			$group->post('/post-to-accounting', CheckoutController::class . ':postVippsToAccounting');
		});
	});

	$group->get('/invoices', CompletedReservationController::class . ':getReservations');
})->add(new SessionsMiddleware($app->getContainer()));


// Redirect /bookingfrontend to /bookingfrontend/
$app->get('/bookingfrontend', function (Request $request, Response $response) {
    return $response->withStatus(301)->withHeader('Location', '/bookingfrontend/');
});

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

// Debug routes
$app->group('/bookingfrontend/debug', function (RouteCollectorProxy $group) {
	$group->get('/websocket', function ($request, $response) {
		$html = file_get_contents(__DIR__ . '/../templates/websocket-debug.html');

		// Get the base URL and construct WebSocket URL (always use wss)
		$uri = $request->getUri();
		$scheme = 'wss';
		$host = $uri->getHost();
		$port = $uri->getPort();
		$portSuffix = ($port && $port !== 443) ? ':' . $port : '';
		$wsUrl = $scheme . '://' . $host . $portSuffix . '/wss';

		// Replace the default WebSocket host in the HTML
		$html = str_replace('value="ws://localhost:8080"', 'value="' . $wsUrl . '"', $html);

		$response->getBody()->write($html);
		return $response->withHeader('Content-Type', 'text/html');
	});
	$group->post('/trigger-partial-update', DebugController::class . ':triggerPartialUpdate');
	$group->post('/test-redis', DebugController::class . ':testRedis');
	$group->get('/session-info', DebugController::class . ':getSessionInfo');
})->add(new SessionsMiddleware($app->getContainer()));



$app->get('/bookingfrontend/lang[/{lang}]', LangHelper::class . ':process');
$app->get('/bookingfrontend/login[/{params:.*}]', LoginHelper::class . ':organization')->add(new SessionsMiddleware($app->getContainer()));
$app->get('/bookingfrontend/logout[/{params:.*}]', LogoutHelper::class . ':process')->add(new SessionsMiddleware($app->getContainer()));
$app->get('/bookingfrontend/client[/{params:.*}]', function ($request, $response)
{
	$response = $response->withHeader('Location', '/bookingfrontend/client/');
	return $response;
});