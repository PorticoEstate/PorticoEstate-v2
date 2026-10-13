<?php

use App\modules\email\controllers\EmailInboxController;
use App\modules\email\viewcontrollers\EmailInboxViewController;
use App\modules\phpgwapi\middleware\SessionsMiddleware;
use App\modules\phpgwapi\security\AccessVerifier;
use Slim\Routing\RouteCollectorProxy;

/** @var \Slim\App $app */
/** @var \DI\Container $container */

$app->group('/email', function (RouteCollectorProxy $group)
{
	$group->get('/api/inbox', EmailInboxController::class . ':index');
	$group->get('/view/inbox', EmailInboxViewController::class . ':index');
})
	->addMiddleware(new AccessVerifier($container))
	->addMiddleware(new SessionsMiddleware($container));
