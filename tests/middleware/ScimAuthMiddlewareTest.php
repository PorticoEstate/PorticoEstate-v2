<?php

namespace Tests\Middleware;

require_once __DIR__ . '/../../vendor/autoload.php';

use App\modules\phpgwapi\middleware\ScimAuthMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

class ScimAuthMiddlewareTest extends TestCase
{
	public function testMissingConfiguredTokenFailsClosed(): void
	{
		$response = $this->process(new ScimAuthMiddleware(''));

		$this->assertSame(503, $response->getStatusCode());
		$this->assertSame('application/scim+json', $response->getHeaderLine('Content-Type'));
	}

	public function testMissingAuthorizationHeaderIsRejected(): void
	{
		$response = $this->process(new ScimAuthMiddleware('expected-token'));

		$this->assertSame(401, $response->getStatusCode());
	}

	public function testInvalidBearerTokenIsRejected(): void
	{
		$request = $this->request()->withHeader('Authorization', 'Bearer wrong-token');
		$response = $this->process(new ScimAuthMiddleware('expected-token'), $request);

		$this->assertSame(401, $response->getStatusCode());
		$this->assertStringNotContainsString('expected-token', (string) $response->getBody());
	}

	public function testValidBearerTokenCallsHandler(): void
	{
		$request = $this->request()->withHeader('Authorization', 'Bearer expected-token');
		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->expects($this->once())
			->method('handle')
			->willReturn((new Response())->withStatus(204));

		$response = (new ScimAuthMiddleware('expected-token'))->process($request, $handler);

		$this->assertSame(204, $response->getStatusCode());
	}

	private function process(
		ScimAuthMiddleware $middleware,
		?ServerRequestInterface $request = null
	): ResponseInterface
	{
		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->expects($this->never())->method('handle');

		return $middleware->process($request ?? $this->request(), $handler);
	}

	private function request(): ServerRequestInterface
	{
		return (new ServerRequestFactory())->createServerRequest('GET', '/api/scim/v2/ServiceProviderConfig');
	}
}