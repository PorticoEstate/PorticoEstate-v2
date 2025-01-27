<?php

use Slim\Factory\AppFactory;
use DI\ContainerBuilder;
use App\providers\DatabaseServiceProvider;

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

// Register service providers
$datbaseProvider = new DatabaseServiceProvider();

$datbaseProvider->register($app);

//require all routes
require_once __DIR__ . '/src/routes/RegisterRoutes.php';

$displayErrorDetails = true; // Set to false in production
$logErrors = true;
$logErrorDetails = true;
$errorMiddleware = $app->addErrorMiddleware($displayErrorDetails, $logErrors, $logErrorDetails);
// Get default error handler and override it with your custom error handler
$customErrorHandler = new \App\helpers\ErrorHandler($app->getResponseFactory());
$errorMiddleware->setDefaultErrorHandler($customErrorHandler);

// Run the Slim app
$app->run();
