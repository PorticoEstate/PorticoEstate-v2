<?php

use App\modules\bookingfrontend\controllers\ApplicationController;
use App\modules\bookingfrontend\controllers\BuildingController;
use App\modules\bookingfrontend\controllers\DataStore;
use App\modules\bookingfrontend\controllers\BookingUserController;
use App\modules\bookingfrontend\controllers\ResourceController;
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
        $group->get('/documents/{id}/download', BuildingController::class . ':downloadDocument');
    });
    $group->group('/resources', function (RouteCollectorProxy $group)
    {
        $group->get('', ResourceController::class . ':index');
        $group->get('/{id}', ResourceController::class . ':getResource');
    });
});

// Session group
$app->group('/bookingfrontend', function (RouteCollectorProxy $group)
{
    $group->group('/applications', function (RouteCollectorProxy $group)
    {
        $group->get('/partials', ApplicationController::class . ':getPartials');
        $group->delete('/{id}', [ApplicationController::class, 'deletePartial']);
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
})->add(new SessionsMiddleware($app->getContainer()));


$app->get('/bookingfrontend/lang[/{lang}]', LangHelper::class . ':process');
$app->get('/bookingfrontend/login/', LoginHelper::class . ':organization')->add(new SessionsMiddleware($app->getContainer()));
$app->get('/bookingfrontend/logout[/{params:.*}]', LogoutHelper::class . ':process')->add(new SessionsMiddleware($app->getContainer()));
$app->get('/bookingfrontend/client[/{params:.*}]', function ($request, $response)
{
    $response = $response->withHeader('Location', '/bookingfrontend/client/');
    return $response;
});