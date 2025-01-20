<?php

use App\modules\phpgwapi\middleware\SessionsMiddleware;
use App\modules\phpgwapi\security\AccessVerifier;
use App\modules\admin\controllers\OpcacheController;


$app->get('/admin/admin/opcache/', OpcacheController::class . ':showOpcacheGui')
	->addMiddleware(new AccessVerifier($container))
	->addMiddleware(new SessionsMiddleware($container));
