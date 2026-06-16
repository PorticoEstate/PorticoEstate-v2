<?php

use Slim\Routing\RouteCollectorProxy;
use App\modules\phpgwapi\controllers\StartPoint;
use App\modules\phpgwapi\middleware\SessionsMiddleware;
use App\modules\mobilefrontend\helpers\HomeHelper;
use App\modules\phpgwapi\helpers\LoginHelper;
use App\modules\mobilefrontend\middleware\LoginMiddleware;
use App\modules\phpgwapi\services\Settings;

$app->get('/mobilefrontend/', StartPoint::class . ':mobilefrontend')->add(new SessionsMiddleware($app->getContainer()));
$app->post('/mobilefrontend/', StartPoint::class . ':mobilefrontend')->add(new SessionsMiddleware($app->getContainer()));
$app->get('/mobilefrontend/index.php', StartPoint::class . ':mobilefrontend')->add(new SessionsMiddleware($app->getContainer()));
$app->post('/mobilefrontend/index.php', StartPoint::class . ':mobilefrontend')->add(new SessionsMiddleware($app->getContainer()));

$app->get('/mobilefrontend/home/', HomeHelper::class . ':processHome')->add(new SessionsMiddleware($app->getContainer()));

//Legacy support
$app->get('/mobilefrontend/login.php', LoginHelper::class . ':processLogin')->add(new LoginMiddleware($app->getContainer()));
$app->post('/mobilefrontend/login.php', LoginHelper::class . ':processLogin')->add(new LoginMiddleware($app->getContainer()));

$app->get('/mobilefrontend/login_ui[/{params:.*}]', LoginHelper::class . ':processLogin')->add(new LoginMiddleware($app->getContainer()));
$app->post('/mobilefrontend/login_ui[/{params:.*}]', LoginHelper::class . ':processLogin')->add(new LoginMiddleware($app->getContainer()));

$app->get('/mobilefrontend/logout', function () use ($app)
{
	$custom_frontend = 'mobilefrontend';
	$settings = $app->getContainer()->get('settings');
	Settings::getInstance()->update(
		'flags',
		[
			'custom_frontend' => $custom_frontend,
			'session_name' => $settings['session_name'][$custom_frontend]
		]
	);
	$sessions = \App\modules\phpgwapi\security\Sessions::getInstance();
	$session_id = $sessions->get_session_id();
	if ($session_id)
	{
		$sessions->destroy($session_id);
	}
	phpgw::redirect_link('/login_ui', array('cd' => 1, 'logout' => 1));
});
