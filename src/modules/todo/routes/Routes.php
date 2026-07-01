<?php

use App\modules\todo\helpers\RedirectHelper;
use App\modules\todo\controllers\TodoController;
use App\modules\todo\viewcontrollers\TodoViewController;
use App\modules\phpgwapi\security\AccessVerifier;
use App\modules\phpgwapi\middleware\SessionsMiddleware;
use Slim\Routing\RouteCollectorProxy;


$app->group('/todo', function (RouteCollectorProxy $group)
{
	$group->group('/view', function (RouteCollectorProxy $viewGroup)
	{
		$viewGroup->get('/todos', TodoViewController::class . ':index');
	});

	$group->group('/todos', function (RouteCollectorProxy $todoGroup)
	{
		$todoGroup->get('', TodoController::class . ':index');
		$todoGroup->get('/export/csv', TodoController::class . ':exportCsv');
		$todoGroup->post('', TodoController::class . ':store');
		$todoGroup->get('/{id:[0-9]+}', TodoController::class . ':show');
		$todoGroup->put('/{id:[0-9]+}', TodoController::class . ':update');
		$todoGroup->delete('/{id:[0-9]+}', TodoController::class . ':destroy');
	});

	$group->get('/categories', TodoController::class . ':categories');
})
	->addMiddleware(new AccessVerifier($container))
	->addMiddleware(new SessionsMiddleware($container));


$app->get('/todo[/{params:.*}]', RedirectHelper::class . ':process')
	->addMiddleware(new AccessVerifier($container))
	->addMiddleware(new SessionsMiddleware($container));
