<?php

namespace Tests\Controllers;

use DI\Container;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;

class PasskeyRoutesTest extends TestCase
{
	public function testMobilePasskeyRoutesMatchBackendRoutes(): void
	{
		$container = new Container();
		$container->set('settings', []);
		$app = AppFactory::createFromContainer($container);
		require __DIR__ . '/../../src/modules/phpgwapi/routes/Routes.php';

		$routes = [];
		foreach ($app->getRouteCollector()->getRoutes() as $route)
		{
			foreach ($route->getMethods() as $method)
			{
				$routes[$method . ' ' . $route->getPattern()] = $route->getCallable();
			}
		}

		foreach ([
			['GET', ''],
			['GET', '/list'],
			['GET', '/suggestion'],
			['POST', '/suggestion'],
			['GET', '/register/options'],
			['POST', '/register/verify'],
			['POST', '/delete'],
		] as [$method, $path])
		{
			$backend = $method . ' /passkey' . $path;
			$mobile = $method . ' /mobilefrontend/passkey' . $path;
			$this->assertArrayHasKey($backend, $routes);
			$this->assertArrayHasKey($mobile, $routes);
			$this->assertSame($routes[$backend], $routes[$mobile]);
		}
	}
}
