<?php

use App\modules\hrm\helpers\RedirectHelper;
use App\modules\hrm\controllers\HrmCategoryController;
use App\modules\hrm\controllers\HrmPlaceController;
use App\modules\hrm\controllers\HrmUserController;
use App\modules\hrm\viewcontrollers\HrmCategoryViewController;
use App\modules\hrm\viewcontrollers\HrmPlaceViewController;
use App\modules\hrm\viewcontrollers\HrmUserViewController;
use App\modules\phpgwapi\security\AccessVerifier;
use App\modules\phpgwapi\middleware\SessionsMiddleware;
use Slim\Routing\RouteCollectorProxy;

/** @var \Slim\App $app */
/** @var \DI\Container $container */

$app->group('/hrm', function (RouteCollectorProxy $group)
{
	$group->get('/view/users', HrmUserViewController::class . ':index');
	$group->get('/view/users/{id:[0-9]+}/training', HrmUserViewController::class . ':training');
	$group->get('/view/users/{id:[0-9]+}/training/cv', HrmUserViewController::class . ':viewCv');
	$group->get('/view/users/{id:[0-9]+}/training/{trainingId:[0-9]+}', HrmUserViewController::class . ':view');
	$group->map(['GET', 'POST'], '/view/users/{id:[0-9]+}/training/new', HrmUserViewController::class . ':edit');
	$group->map(['GET', 'POST'], '/view/users/{id:[0-9]+}/training/{trainingId:[0-9]+}/edit', HrmUserViewController::class . ':edit');
	$group->map(['GET', 'POST'], '/view/users/{id:[0-9]+}/training/{trainingId:[0-9]+}/delete', HrmUserViewController::class . ':delete');
	$group->get('/view/places', HrmPlaceViewController::class . ':index');
	$group->map(['GET', 'POST'], '/view/places/new', HrmPlaceViewController::class . ':edit');
	$group->get('/view/places/{id:[0-9]+}', HrmPlaceViewController::class . ':view');
	$group->map(['GET', 'POST'], '/view/places/{id:[0-9]+}/edit', HrmPlaceViewController::class . ':edit');
	$group->map(['GET', 'POST'], '/view/places/{id:[0-9]+}/delete', HrmPlaceViewController::class . ':delete');
	$group->get('/view/categories/{type}', HrmCategoryViewController::class . ':index');
	$group->map(['GET', 'POST'], '/view/categories/{type}/new', HrmCategoryViewController::class . ':edit');
	$group->map(['GET', 'POST'], '/view/categories/{type}/{id:[0-9]+}/edit', HrmCategoryViewController::class . ':edit');
	$group->map(['GET', 'POST'], '/view/categories/{type}/{id:[0-9]+}/delete', HrmCategoryViewController::class . ':delete');
	$group->get('/users', HrmUserController::class . ':index');
	$group->get('/users/{id:[0-9]+}/training', HrmUserController::class . ':training');
	$group->get('/places', HrmPlaceController::class . ':index');
	$group->get('/categories/{type}', HrmCategoryController::class . ':index');
})
	->addMiddleware(new AccessVerifier($container))
	->addMiddleware(new SessionsMiddleware($container));

$app->get('/hrm[/{params:.*}]', RedirectHelper::class . ':process')
	->addMiddleware(new AccessVerifier($container))
	->addMiddleware(new SessionsMiddleware($container));
