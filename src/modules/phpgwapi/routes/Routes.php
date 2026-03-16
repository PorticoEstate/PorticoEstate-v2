<?php

use App\modules\phpgwapi\controllers\ServerSettingsController;
use App\modules\phpgwapi\controllers\LanguageController;
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

// Debug endpoint: show entrypoint logs and npm hash state
$app->get('/assets/debug/entrypoint', function (Request $request, Response $response)
{
	$baseDir = dirname(dirname(PHPGW_SERVER_ROOT));
	$debug = [];
	$debug['timestamp'] = date('c');

	// Entrypoint log
	$logFile = $baseDir . '/entrypoint.log';
	$debug['entrypoint_log_exists'] = file_exists($logFile);
	if (file_exists($logFile)) {
		$debug['entrypoint_log'] = file_get_contents($logFile);
	}

	// Hash comparison (same logic as entrypoint)
	$imageHash = '/tmp/.package-lock-hash';
	$volumeHash = $baseDir . '/node_modules/.package-lock-hash';
	$debug['image_hash_exists'] = file_exists($imageHash);
	$debug['volume_hash_exists'] = file_exists($volumeHash);
	if (file_exists($imageHash)) {
		$debug['image_hash'] = trim(file_get_contents($imageHash));
	}
	if (file_exists($volumeHash)) {
		$debug['volume_hash'] = trim(file_get_contents($volumeHash));
	}
	$debug['hashes_match'] = ($debug['image_hash'] ?? '') === ($debug['volume_hash'] ?? '');

	$response->getBody()->write(json_encode($debug, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
	return $response->withHeader('Content-Type', 'application/json');
});

// Serve Designsystemet CSS bundle
$app->get('/assets/designsystemet/index.css', function (Request $request, Response $response)
{
	$cssPath = dirname(dirname(PHPGW_SERVER_ROOT)) . '/node_modules/@digdir/designsystemet-css/dist/src/index.css';
	if (!is_readable($cssPath))
	{
		return $response->withStatus(404);
	}

	$response->getBody()->write(file_get_contents($cssPath));
	return $response
		->withHeader('Content-Type', 'text/css')
		->withHeader('Cache-Control', 'public, max-age=3600');
});

// Serve PorticoEstate design tokens CSS
$app->get('/assets/design-tokens/{file:.*\\.css}', function (Request $request, Response $response, array $args)
{
	$file = $args['file'] ?? '';
	$filePath = dirname(dirname(PHPGW_SERVER_ROOT)) . '/node_modules/@porticoestate/design-tokens/dist/' . $file;
	$realPath = realpath($filePath);
	$distRoot = realpath(dirname(dirname(PHPGW_SERVER_ROOT)) . '/node_modules/@porticoestate/design-tokens/dist');

	if (!$realPath || !$distRoot || !str_starts_with($realPath, $distRoot) || !is_readable($realPath))
	{
		return $response->withStatus(404);
	}

	$response->getBody()->write(file_get_contents($realPath));
	return $response
		->withHeader('Content-Type', 'text/css')
		->withHeader('Cache-Control', 'public, max-age=3600');
});

// Serve whitelisted node_modules files (JS, CSS, images)
$app->get('/assets/npm/{path:.*}', function (Request $request, Response $response, array $args)
{
	$params = $request->getQueryParams();
	$nodeModulesDir = dirname(dirname(PHPGW_SERVER_ROOT)) . '/node_modules';

	if (!empty($params['debug']))
	{
		$debug = [];
		$debug['node_modules_path'] = $nodeModulesDir;
		$debug['node_modules_exists'] = is_dir($nodeModulesDir);
		$debug['node_modules_readable'] = is_readable($nodeModulesDir);

		if (is_dir($nodeModulesDir))
		{
			$items = @scandir($nodeModulesDir);
			if ($items !== false)
			{
				$items = array_filter($items, fn($i) => $i !== '.' && $i !== '..');
				$debug['node_modules_count'] = count($items);
				$debug['node_modules_contents'] = array_values($items);
			}
			else
			{
				$debug['node_modules_contents'] = 'scandir failed';
			}
		}

		$debug['node'] = trim(@shell_exec('which node 2>&1') ?: 'not found');
		$debug['node_version'] = trim(@shell_exec('node --version 2>&1') ?: 'n/a');
		$debug['npm'] = trim(@shell_exec('which npm 2>&1') ?: 'not found');
		$debug['npm_version'] = trim(@shell_exec('npm --version 2>&1') ?: 'n/a');

		$path = $args['path'] ?? '';
		if ($path !== '')
		{
			$filePath = $nodeModulesDir . '/' . $path;
			$debug['requested_path'] = $path;
			$debug['resolved_file'] = $filePath;
			$debug['file_exists'] = file_exists($filePath);
			$debug['file_readable'] = is_readable($filePath);
			if (!file_exists($filePath))
			{
				$parts = explode('/', $path);
				$packageDir = $nodeModulesDir . '/' . (str_starts_with($path, '@') && count($parts) >= 2 ? $parts[0] . '/' . $parts[1] : $parts[0]);
				$debug['package_dir'] = $packageDir;
				$debug['package_exists'] = is_dir($packageDir);
			}
		}

		$response->getBody()->write(json_encode($debug, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		return $response->withHeader('Content-Type', 'application/json');
	}

	$allowedPrefixes = [
		'@digdir/designsystemet-web/',
		'@dnd-kit/',
		'@floating-ui/',
		'@preact/',
		'@u-elements/',
		'invokers-polyfill/',
		'jquery/',
		'jquery-migrate/',
		'jquery-ui/',
		'jstree/',
		'blueimp-file-upload/',
		'blueimp-tmpl/',
		'jqtree/',
		'responsive-tabs/',
	];

	$path = $args['path'] ?? '';
	$allowed = false;
	foreach ($allowedPrefixes as $prefix)
	{
		if (str_starts_with($path, $prefix))
		{
			$allowed = true;
			break;
		}
	}

	if (!$allowed)
	{
		return $response->withStatus(403);
	}

	$filePath = $nodeModulesDir . '/' . $path;
	$realPath = realpath($filePath);
	$nodeModulesRoot = realpath($nodeModulesDir);

	if (!$realPath || !str_starts_with($realPath, $nodeModulesRoot) || !is_readable($realPath))
	{
		return $response->withStatus(404);
	}

	$contentTypes = [
		'js'  => 'application/javascript',
		'mjs' => 'application/javascript',
		'css' => 'text/css',
		'png' => 'image/png',
		'gif' => 'image/gif',
		'svg' => 'image/svg+xml',
		'map' => 'application/json',
	];
	$ext = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
	$contentType = $contentTypes[$ext] ?? 'application/octet-stream';

	$response->getBody()->write(file_get_contents($realPath));
	return $response
		->withHeader('Content-Type', $contentType)
		->withHeader('Cache-Control', 'public, max-age=3600');
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

$app->get('/api/languages', LanguageController::class . ':getLanguages')
	->add(new SessionsMiddleware($app->getContainer()));

$app->get('/api/set-language/{lng}', LanguageController::class . ':setLanguage')
	->add(new SessionsMiddleware($app->getContainer()));


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
