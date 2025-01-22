<?php

use App\modules\phpgwapi\middleware\SessionsMiddleware;
use App\modules\phpgwapi\security\AccessVerifier;
use App\modules\admin\controllers\OpcacheController;
use App\modules\admin\controllers\RedisController;


$app->get('/admin/admin/opcache/', OpcacheController::class . ':showOpcacheGui')
	->addMiddleware(new AccessVerifier($container))
	->addMiddleware(new SessionsMiddleware($container));


$app->get('/admin/admin/redis/', RedisController::class . ':showRedisCache')
	->addMiddleware(new AccessVerifier($container))
	->addMiddleware(new SessionsMiddleware($container));
