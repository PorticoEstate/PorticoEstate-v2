<?php

namespace
{
	require_once __DIR__ . '/../../vendor/autoload.php';
	require_once __DIR__ . '/../../src/helpers/Sanitizer.php';
}

namespace Tests\Controllers
{
	use App\modules\property\controllers\LocationController;
	use App\modules\property\helpers\LocationFormHelper;
	use PHPUnit\Framework\MockObject\MockObject;
	use PHPUnit\Framework\TestCase;
	use Psr\Container\ContainerInterface;
	use Psr\Http\Message\ResponseInterface;
	use Psr\Http\Message\ServerRequestInterface;
	use Psr\Http\Message\StreamInterface;

	class LocationControllerTest extends TestCase
	{
		private ServerRequestInterface&MockObject $request;
		private ResponseInterface&MockObject $response;
		private ContainerInterface&MockObject $container;
		private string $responseBody;

		protected function setUp(): void
		{
			$this->responseBody = '';

			$stream = $this->createMock(StreamInterface::class);
			$stream->method('write')->willReturnCallback(function (string $data): int
			{
				$this->responseBody .= $data;
				return strlen($data);
			});

			$this->response = $this->createMock(ResponseInterface::class);
			$this->response->method('getBody')->willReturn($stream);
			$this->response->method('withHeader')->willReturn($this->response);
			$this->response->method('withStatus')->willReturn($this->response);

			$this->request = $this->createMock(ServerRequestInterface::class);
			$this->container = $this->createMock(ContainerInterface::class);
		}

		private function makeControllerWithHelper(LocationFormHelper $helper): LocationController
		{
			$controller = new LocationController($this->container);
			$ref = new \ReflectionClass($controller);
			$prop = $ref->getProperty('formHelper');
			$prop->setAccessible(true);
			$prop->setValue($controller, $helper);

			return $controller;
		}

		public function testAddReturnsHelperPayloadAsJson(): void
		{
			$helper = new class extends LocationFormHelper
			{
				public array $capturedMapInputArgs = [];

				public function mapInput(array $requestData, ?int $locationId = null): array
				{
					$this->capturedMapInputArgs = [$requestData, $locationId];
					return ['values' => ['loc1' => 'A'], 'errors' => [], 'location_id' => 0];
				}

				public function validate(array $state): array
				{
					$state['validated'] = true;
					return $state;
				}

				public function persistSave(array $state): array
				{
					$state['receipt'] = ['status' => 'success', 'message' => 'ok'];
					return $state;
				}

				public function buildSaveResponse(array $state, string $userAction = 'save'): array
				{
					return [
						'type' => 'json',
						'payload' => ['status' => 'success', 'message' => 'ok', 'location_code' => 'A'],
					];
				}
			};

			$this->request->method('getParsedBody')->willReturn(['loc1' => 'A']);

			$controller = $this->makeControllerWithHelper($helper);
			$controller->add($this->request, $this->response);

			$decoded = json_decode($this->responseBody, true);
			$this->assertSame('success', $decoded['status']);
			$this->assertSame('ok', $decoded['message']);
			$this->assertSame('A', $decoded['location_code']);
			$this->assertSame([['loc1' => 'A'], null], $helper->capturedMapInputArgs);
		}

		public function testSaveReturns400ForInvalidLocationId(): void
		{
			$this->request->method('getParsedBody')->willReturn(['loc1' => 'A']);

			$controller = $this->makeControllerWithHelper(new LocationFormHelper());
			$controller->save($this->request, $this->response, ['location_id' => '0']);

			$decoded = json_decode($this->responseBody, true);
			$this->assertSame('error', $decoded['status']);
			$this->assertSame('Invalid location ID', $decoded['message']);
		}

		public function testSaveReturns400WhenHelperReportsErrorStatus(): void
		{
			$helper = new class extends LocationFormHelper
			{
				public array $capturedMapInputArgs = [];

				public function mapInput(array $requestData, ?int $locationId = null): array
				{
					$this->capturedMapInputArgs = [$requestData, $locationId];
					return ['values' => ['loc1' => 'A'], 'errors' => ['x']];
				}

				public function validate(array $state): array
				{
					return $state;
				}

				public function persistSave(array $state): array
				{
					$state['receipt'] = ['status' => 'error', 'message' => 'failed'];
					return $state;
				}

				public function buildSaveResponse(array $state, string $userAction = 'save'): array
				{
					return [
						'type' => 'json',
						'payload' => ['status' => 'error', 'message' => 'failed'],
					];
				}
			};

			$this->request->method('getParsedBody')->willReturn(['loc1' => 'A']);

			$controller = $this->makeControllerWithHelper($helper);
			$controller->save($this->request, $this->response, ['location_id' => '9']);

			$decoded = json_decode($this->responseBody, true);
			$this->assertSame('error', $decoded['status']);
			$this->assertSame('failed', $decoded['message']);
			$this->assertSame([['loc1' => 'A'], 9], $helper->capturedMapInputArgs);
		}

		public function testSaveReturns200WhenHelperReportsSuccessStatus(): void
		{
			$helper = new class extends LocationFormHelper
			{
				public function mapInput(array $requestData, ?int $locationId = null): array
				{
					return ['values' => ['loc1' => 'A'], 'errors' => []];
				}

				public function validate(array $state): array
				{
					return $state;
				}

				public function persistSave(array $state): array
				{
					$state['receipt'] = ['status' => 'success', 'message' => 'saved'];
					return $state;
				}

				public function buildSaveResponse(array $state, string $userAction = 'save'): array
				{
					return [
						'type' => 'json',
						'payload' => ['status' => 'success', 'message' => 'saved'],
					];
				}
			};

			$this->request->method('getParsedBody')->willReturn(['loc1' => 'A']);

			$controller = $this->makeControllerWithHelper($helper);
			$controller->save($this->request, $this->response, ['location_id' => '10']);

			$decoded = json_decode($this->responseBody, true);
			$this->assertSame('success', $decoded['status']);
			$this->assertSame('saved', $decoded['message']);
		}
	}
}
