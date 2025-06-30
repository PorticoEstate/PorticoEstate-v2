<?php

use Slim\Factory\AppFactory;
use DI\ContainerBuilder;
use App\providers\DatabaseServiceProvider;
use App\providers\WebSocketServiceProvider;
use App\WebSocket\Routes;

require_once __DIR__ . '/vendor/autoload.php';

define('SRC_ROOT_PATH', __DIR__ . '/src');

define('ACL_READ', 1);
define('ACL_ADD', 2);
define('ACL_EDIT', 4);
define('ACL_DELETE', 8);
define('ACL_PRIVATE', 16);
define('ACL_GROUP_MANAGERS', 32);
define('ACL_CUSTOM_1', 64);
define('ACL_CUSTOM_2', 128);
define('ACL_CUSTOM_3', 256);

$containerBuilder = new ContainerBuilder();

require_once SRC_ROOT_PATH . '/helpers/CommonFunctions.php';
require_once SRC_ROOT_PATH . '/helpers/Sanitizer.php';
require_once SRC_ROOT_PATH . '/helpers/phpgw.php';
require_once SRC_ROOT_PATH . '/helpers/DebugArray.php';

// Add your settings to the container
$database_settings = require_once SRC_ROOT_PATH . '/helpers/FilterDatabaseConfig.php';

$session_name = [
	'activitycalendarfrontend' => 'activitycalendarfrontendsession',
	'bookingfrontend' => 'bookingfrontendsession',
	'eventplannerfrontend' => 'eventplannerfrontendsession',
	'mobilefrontend' => 'mobilefrontendsession',
	'registration' => 'registrationsession',
];


$containerBuilder->addDefinitions([
	'settings' => [
		'db' => $database_settings,
		'session_name' => $session_name
	]
]);

// Build PHP-DI Container instance
$container = $containerBuilder->build();

// Instantiate the app
AppFactory::setContainer($container);
$app = AppFactory::create();

$app->add(function ($request, $handler)
{
	$response = $handler->handle($request);
	return $response
		->withHeader('Access-Control-Allow-Origin', '*')
		->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
		->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
});

// Register service providers
$datbaseProvider = new DatabaseServiceProvider();
$webSocketProvider = new WebSocketServiceProvider();

$datbaseProvider->register($container);
$webSocketProvider->register($container);

// Register WebSocket routes class
$container->set(Routes::class, function ($c)
{
	// Get the WebSocketServer instance from the container
	return new Routes($c->get(\App\WebSocket\WebSocketServer::class));
});

//require all routes
require_once __DIR__ . '/src/routes/RegisterRoutes.php';

// Generic registry routes are now included in individual module route files
// (e.g., booking module routes include their registry routes)

// Test route registration (remove in production)
// if (isset($_GET['test_routes']))
// {
// 	$routes = $app->getRouteCollector()->getRoutes();
// 	echo "Registered routes:\n<br/>";
// 	foreach ($routes as $route)
// 	{
// 		echo $route->getPattern() . " (" . implode(', ', $route->getMethods()) . ")\n<br/>";
// 	}
// 	exit;
// }

$displayErrorDetails = true; // Set to false in production
$logErrors = true;
$logErrorDetails = true;
$errorMiddleware = $app->addErrorMiddleware($displayErrorDetails, $logErrors, $logErrorDetails);
// Get default error handler and override it with your custom error handler
$customErrorHandler = new \App\helpers\ErrorHandler($app->getResponseFactory());
$errorMiddleware->setDefaultErrorHandler($customErrorHandler);

// Run the Slim app
$app->run();
