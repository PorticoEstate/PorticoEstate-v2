<?php

use App\modules\bookingfrontend\controllers\ApplicationController;
use App\modules\bookingfrontend\controllers\BuildingController;
use App\modules\bookingfrontend\controllers\CompletedReservationController;
use App\modules\bookingfrontend\controllers\DataStore;
use App\modules\bookingfrontend\controllers\BookingUserController;
use App\modules\bookingfrontend\controllers\DocumentController;
use App\modules\bookingfrontend\controllers\ResourceController;
use App\modules\bookingfrontend\controllers\EventController;
use App\modules\bookingfrontend\helpers\LangHelper;
use App\modules\bookingfrontend\helpers\LoginHelper;
use App\modules\bookingfrontend\helpers\LogoutHelper;
use App\modules\phpgwapi\controllers\StartPoint;
use App\modules\phpgwapi\middleware\SessionsMiddleware;
use Slim\Routing\RouteCollectorProxy;



$app->group('/bookingfrontend', function (RouteCollectorProxy $group)
{
    $group->get('/searchdataall[/{params:.*}]', DataStore::class . ':SearchDataAll');
    $group->group('/buildings', function (RouteCollectorProxy $group)
    {
        $group->get('', BuildingController::class . ':index');
        $group->get('/{id}', BuildingController::class . ':show');
        $group->get('/{id}/resources', ResourceController::class . ':getResourcesByBuilding');
        $group->get('/{id}/documents', BuildingController::class . ':getDocuments');
        $group->get('/document/{id}/download', BuildingController::class . ':downloadDocument');
        $group->get('/{id}/schedule', BuildingController::class . ':getSchedule');
    });
    $group->group('/resources', function (RouteCollectorProxy $group)
    {
        $group->get('', ResourceController::class . ':index');
        $group->get('/{id}', ResourceController::class . ':getResource');
        $group->get('/{id}/documents', ResourceController::class . ':getDocuments');
        $group->get('/document/{id}/download', ResourceController::class . ':downloadDocument');

    });
})->add(new SessionsMiddleware($app->getContainer()));

// Session group
$app->group('/bookingfrontend', function (RouteCollectorProxy $group)
{
    $group->group('/applications', function (RouteCollectorProxy $group)
    {
        $group->get('/partials', ApplicationController::class . ':getPartials');
        $group->post('/partials', ApplicationController::class . ':createPartial');
        $group->put('/partials/{id}', ApplicationController::class . ':updatePartial');
        $group->get('', ApplicationController::class . ':getApplications');
        $group->delete('/{id}', [ApplicationController::class, 'deletePartial']);
        $group->patch('/partials/{id}', ApplicationController::class . ':patchApplication');
    });
    $group->get('/invoices', CompletedReservationController::class . ':getReservations');
})->add(new SessionsMiddleware($app->getContainer()));

$app->group('/bookingfrontend', function (RouteCollectorProxy $group)
{
    $group->group('/events', function (RouteCollectorProxy $group)
    {
        $group->patch('/{id}', EventController::class . ':updateEvent');
    });
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


$app->get('/bookingfrontend/lang[/{lang}]', LangHelper::class . ':process');
$app->get('/bookingfrontend/login[/{params:.*}]', LoginHelper::class . ':organization')->add(new SessionsMiddleware($app->getContainer()));
$app->get('/bookingfrontend/logout[/{params:.*}]', LogoutHelper::class . ':process')->add(new SessionsMiddleware($app->getContainer()));
$app->get('/bookingfrontend/client[/{params:.*}]', function ($request, $response)
{
    $response = $response->withHeader('Location', '/bookingfrontend/client/');
    return $response;
});