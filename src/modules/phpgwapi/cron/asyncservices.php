#!/usr/bin/php -q
<?php

use Slim\Factory\AppFactory;
use DI\ContainerBuilder;
use App\providers\DatabaseServiceProvider;
use Psr\Container\ContainerInterface;

$_GET['domain'] = isset($_SERVER['argv'][1]) ? $_SERVER['argv'][1] : 'default';


$rootDir = dirname(__DIR__, 4);
require_once $rootDir . '/vendor/autoload.php';

define('SRC_ROOT_PATH', $rootDir . '/src');

$containerBuilder = new ContainerBuilder();

require_once SRC_ROOT_PATH . '/helpers/CommonFunctions.php';
require_once SRC_ROOT_PATH . '/helpers/Sanitizer.php';
require_once SRC_ROOT_PATH . '/helpers/phpgw.php';
require_once SRC_ROOT_PATH . '/helpers/DebugArray.php';

// Add your settings to the container
$database_settings = require_once SRC_ROOT_PATH . '/helpers/FilterDatabaseConfig.php';


$_domain_info = isset($database_settings['domain']) && $database_settings['domain'] == $_GET['domain'];
if (!$_domain_info)
{
	echo "not a valid domain\n";
	die();
}


$containerBuilder->addDefinitions(['settings' => ['db' => $database_settings]]);

// Build PHP-DI Container instance
$container = $containerBuilder->build();

// Instantiate the app
AppFactory::setContainer($container);
$app = AppFactory::create();

// Register service providers
$datbaseProvider = new DatabaseServiceProvider();

$datbaseProvider->register($container);

$displayErrorDetails = true; // Set to false in production
$logErrors = true;
$logErrorDetails = true;
$errorMiddleware = $app->addErrorMiddleware($displayErrorDetails, $logErrors, $logErrorDetails);
// Get default error handler and override it with your custom error handler
$customErrorHandler = new \App\helpers\ErrorHandler($app->getResponseFactory());
$errorMiddleware->setDefaultErrorHandler($customErrorHandler);

require_once SRC_ROOT_PATH . '/helpers/CronJobs.php';

// Register the service

/*
 * $argc — The number of arguments passed to script
 * https://www.php.net/manual/en/reserved.variables.argc.php
 * $argv — Array of arguments passed to script
 * https://www.php.net/manual/en/reserved.variables.argv.php
 */

$container->set('CronJobs', function (ContainerInterface $c)
{
	return new CronJobs();
});

// Now you can retrieve the CronJobs service from the container and use it
/** @var CronJobs $cronJobs */
$cronJobs = $container->get('CronJobs');
$cronJobs->CheckRun();
