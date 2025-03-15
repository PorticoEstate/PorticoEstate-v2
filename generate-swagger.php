<?php
require 'vendor/autoload.php';
// Add at the top of your file or in a bootstrap file
if (!defined('SRC_ROOT_PATH'))
{
	define('SRC_ROOT_PATH', __DIR__ . '/src');
}

// Mock classes to prevent actual loading
class_exists('PDO') ?: class_alias('\stdClass', '\PDO');
class_exists('App\Database\Db') ?: class_alias('\stdClass', '\App\Database\Db');


// Define required constants
define('PHPGW_SERVER_ROOT', SRC_ROOT_PATH . '/modules');
define('PHPGW_API_INC', SRC_ROOT_PATH . '/modules/phpgwapi/inc');
define('PHPGW_TEMPLATE_DIR', SRC_ROOT_PATH . '/modules/phpgwapi/templates/bootstrap');


// Scan only for annotations without trying to load the classes
$openapi = \OpenApi\Generator::scan([
	SRC_ROOT_PATH . '/modules/bookingfrontend/controllers',
	SRC_ROOT_PATH . '/modules/bookingfrontend/models',
	SRC_ROOT_PATH . '/modules/bookingfrontend/helpers',
	SRC_ROOT_PATH . '/modules/phpgwapi/helpers/LoginHelper.php',
], [
	'exclude' => [
//		SRC_ROOT_PATH . '/modules/bookingfrontend/controllers/LoginController.php'
	],
	'validate' => false
]);

// Output the OpenAPI specification
file_put_contents(__DIR__ . '/swagger/openapi.json', $openapi->toJson());
echo "OpenAPI documentation generated successfully.\n";
echo __DIR__ . '/swagger/openapi.json';