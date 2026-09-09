<?php

use App\modules\hrm\helpers\RedirectHelper;
use App\modules\hrm\controllers\HrmUserController;
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
	$group->get('/view/users/{id:[0-9]+}/training/{trainingId:[0-9]+}', HrmUserViewController::class . ':view');
	$group->map(['GET', 'POST'], '/view/users/{id:[0-9]+}/training/new', HrmUserViewController::class . ':edit');
	$group->map(['GET', 'POST'], '/view/users/{id:[0-9]+}/training/{trainingId:[0-9]+}/edit', HrmUserViewController::class . ':edit');
	$group->map(['GET', 'POST'], '/view/users/{id:[0-9]+}/training/{trainingId:[0-9]+}/delete', HrmUserViewController::class . ':delete');
	$group->get('/users', HrmUserController::class . ':index');
	$group->get('/users/{id:[0-9]+}/training', HrmUserController::class . ':training');
})
	->addMiddleware(new AccessVerifier($container))
	->addMiddleware(new SessionsMiddleware($container));

$app->get('/hrm[/{params:.*}]', RedirectHelper::class . ':process')
	->addMiddleware(new AccessVerifier($container))
	->addMiddleware(new SessionsMiddleware($container));
