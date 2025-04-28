<?php

use App\modules\phpgwapi\controllers\ServerSettingsController;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use App\modules\phpgwapi\controllers\StartPoint;
use App\modules\phpgwapi\middleware\SessionsMiddleware;
use App\modules\preferences\helpers\PreferenceHelper;
use App\modules\phpgwapi\helpers\HomeHelper;
use App\modules\phpgwapi\helpers\LoginHelper;
use App\modules\phpgwapi\helpers\RedirectHelper;
use Slim\Routing\RouteCollectorProxy;

// Handle requests for favicon.ico
$app->get('/favicon.ico', function (Request $request, Response $response)
{
	return $response->withStatus(204);
});

$app->get('/', StartPoint::class . ':run')->add(new SessionsMiddleware($app->getContainer()));
$app->post('/', StartPoint::class . ':run')->add(new SessionsMiddleware($app->getContainer()));
$app->get('/index.php', StartPoint::class . ':run')->add(new SessionsMiddleware($app->getContainer()));
$app->post('/index.php', StartPoint::class . ':run')->add(new SessionsMiddleware($app->getContainer()));


$app->get('/preferences/', PreferenceHelper::class . ':index')->add(new SessionsMiddleware($app->getContainer()));
$app->post('/preferences/', PreferenceHelper::class . ':index')->add(new SessionsMiddleware($app->getContainer()));

// Define a factory for the Preferences singleton in the container
$container->set(PreferenceHelper::class, function ($container)
{
	return PreferenceHelper::getInstance();
});

$app->get('/preferences/section', PreferenceHelper::class . ':section')->add(new SessionsMiddleware($app->getContainer()));
$app->post('/preferences/section', PreferenceHelper::class . ':section')->add(new SessionsMiddleware($app->getContainer()));
$app->get('/preferences/changepassword', PreferenceHelper::class . ':changepassword')->add(new SessionsMiddleware($app->getContainer()));
$app->post('/preferences/changepassword', PreferenceHelper::class . ':changepassword')->add(new SessionsMiddleware($app->getContainer()));

$app->get('/home/', HomeHelper::class . ':processHome')->add(new SessionsMiddleware($app->getContainer()));


$app->get('/redirect.php', RedirectHelper::class . ':processRedirect');

$app->get('/login.php', LoginHelper::class . ':processLogin');
$app->post('/login.php', LoginHelper::class . ':processLogin');
$app->get('/login_ui[/{params:.*}]', LoginHelper::class . ':processLogin');
$app->post('/login_ui[/{params:.*}]', LoginHelper::class . ':processLogin');

$app->get('/login_callback', LoginHelper::class . ':processLoginCallback');

$phpgw_domain = $phpgw_domain ?? [];
$app->get('/login[/{params:.*}]', LoginHelper::class . ':displayLoginForm');

$app->post('/login', LoginHelper::class . ':processLoginPost')
	->addMiddleware(new App\modules\phpgwapi\middleware\LoginMiddleware($container));

$app->get('/refreshsession[/{params:.*}]', LoginHelper::class . ':refreshSession');

$app->get('/logout[/{params:.*}]', LoginHelper::class . ':logout');


$app->get('/logout_ui[/{params:.*}]', function (Request $request, Response $response)
{
	$sessions = \App\modules\phpgwapi\security\Sessions::getInstance();
	$session_id = $sessions->get_session_id();
	if ($session_id)
	{
		$sessions->verify($session_id);
		$sessions->destroy($session_id);
	}
	phpgw::redirect_link('/login_ui', array('cd' => 1, 'logout' => 1));
});

$app->group('/api', function (RouteCollectorProxy $group)
{
	$group->get('/server-settings', ServerSettingsController::class . ':index');
});


$app->get('/swagger[/]', function ($request, $response) use ($container)
{
	// Check if user is authenticated
	$sessions = \App\modules\phpgwapi\security\Sessions::getInstance();
	if (!$sessions->verify())
	{
		// Redirect to login if not authenticated
		return $response->withHeader('Location', '/login')
			->withStatus(302);
	}

	// If authorized, show Swagger UI directly using the controller
	$swaggerController = new \App\modules\phpgwapi\controllers\SwaggerController();
	return $swaggerController->index($request, $response);
})->setName('api-docs');

// Add a route for the spec file
$app->get('/swagger/spec', function ($request, $response) use ($container)
{
	// Same authentication checks
	$sessions = \App\modules\phpgwapi\security\Sessions::getInstance();
	if (!$sessions->verify())
	{
		$response->getBody()->write(json_encode(['error' => 'Authentication required']));
		return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
	}

	$userInfo = $sessions->get_user();
	if (empty($userInfo['apps']['admin']['enabled']))
	{
		$response->getBody()->write(json_encode(['error' => 'Admin privileges required']));
		return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
	}

	// Serve the spec
	$swaggerController = new \App\modules\phpgwapi\controllers\SwaggerController();
	return $swaggerController->getSpec($request, $response);
});

$app->get('/api/tables', function ($request, $response) use ($container)
{
    // Same authentication checks
    $sessions = \App\modules\phpgwapi\security\Sessions::getInstance();
    if (!$sessions->verify())
    {
        $response->getBody()->write(json_encode(['error' => 'Authentication required']));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }

    $userInfo = $sessions->get_user();
    if (empty($userInfo['apps']['admin']['enabled']))
    {
        $response->getBody()->write(json_encode(['error' => 'Admin privileges required']));
        return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
    }

    // Serve the spec
    $databaseController = new \App\modules\phpgwapi\controllers\DatabaseController();
    return $databaseController->getTables($request, $response);
});


//get tabledata for specific table
$app->get('/api/tabledata/{table}', function ($request, $response, $args) use ($container)
{
	// Same authentication checks
	$sessions = \App\modules\phpgwapi\security\Sessions::getInstance();
	if (!$sessions->verify())
	{
		$response->getBody()->write(json_encode(['error' => 'Authentication required']));
		return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
	}

	$userInfo = $sessions->get_user();
	if (empty($userInfo['apps']['admin']['enabled']))
	{
		$response->getBody()->write(json_encode(['error' => 'Admin privileges required']));
		return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
	}

	// Serve the spec
	$databaseController = new \App\modules\phpgwapi\controllers\DatabaseController();
	return $databaseController->getTableData($request, $response, $args);
});

/**
 * Routes for passkey management
 */
$app->group('/passkey', function ($group)
{
	// Main passkey management page
	$group->get('', 'App\modules\phpgwapi\controllers\Passkey_Management_Controller:index');

	// API endpoint for registration options
	$group->get('/register/options', 'App\modules\phpgwapi\controllers\Passkey_Management_Controller:getRegistrationOptions');

	// API endpoint for registration verification
	$group->post('/register/verify', 'App\modules\phpgwapi\controllers\Passkey_Management_Controller:verifyRegistration');

	// API endpoint for deleting a passkey
	$group->post('/delete', 'App\modules\phpgwapi\controllers\Passkey_Management_Controller:deletePasskey');
})->add(new SessionsMiddleware($app->getContainer()));
