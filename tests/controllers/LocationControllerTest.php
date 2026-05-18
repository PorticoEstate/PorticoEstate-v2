<?php

namespace
{
	require_once __DIR__ . '/../../vendor/autoload.php';
	require_once __DIR__ . '/../../src/helpers/Sanitizer.php';

	if (!function_exists('lang'))
	{
		function lang(string $text, ...$args): string
		{
			if (!$args)
			{
				return $text;
			}

			foreach ($args as $i => $arg)
			{
				$text = str_replace('%' . ($i + 1), (string) $arg, $text);
			}

			return $text;
		}
	}
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
			$controller = new class($this->container) extends LocationController
			{
				public function __construct(ContainerInterface $container)
				{
					parent::__construct($container);
				}

				protected function hasAcl(string $aclProperty): bool
				{
					return true;
				}
			};

			$ref = new \ReflectionClass(LocationController::class);
			$prop = $ref->getProperty('formHelper');
			$prop->setAccessible(true);
			$prop->setValue($controller, $helper);

			return $controller;
		}

		private function makeControllerWithAclMap(LocationFormHelper $helper, array $aclMap): LocationController
		{
			$controller = new class($this->container, $aclMap) extends LocationController
			{
				private array $aclMap;

				public function __construct(ContainerInterface $container, array $aclMap)
				{
					parent::__construct($container);
					$this->aclMap = $aclMap;
				}

				protected function hasAcl(string $aclProperty): bool
				{
					return $this->aclMap[$aclProperty] ?? true;
				}
			};

			$ref = new \ReflectionClass(LocationController::class);
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

		public function testSaveInvokesLegacyRulesBeforePersistence(): void
		{
			$helper = new class extends LocationFormHelper
			{
				public array $callOrder = [];

				public function mapInput(array $requestData, ?int $locationId = null): array
				{
					$this->callOrder[] = 'mapInput';
					return [
						'values' => ['loc1' => 'A', 'cat_id' => 1],
						'errors' => [],
						'location_id' => (int) $locationId,
						'type_id' => 1,
						'location_parent' => [],
					];
				}

				public function applyLegacyRules(array $state, array $insertRecord, bool $isEdit): array
				{
					$this->callOrder[] = 'applyLegacyRules:' . ($isEdit ? 'edit' : 'add');
					$state['values']['error_id'] = false;
					return $state;
				}

				public function validate(array $state): array
				{
					$this->callOrder[] = 'validate';
					return $state;
				}

				public function persistSave(array $state): array
				{
					$this->callOrder[] = 'persistSave';
					$state['receipt'] = ['status' => 'success', 'message' => 'saved'];
					return $state;
				}

				public function buildSaveResponse(array $state, string $userAction = 'save'): array
				{
					$this->callOrder[] = 'buildSaveResponse';
					return [
						'type' => 'json',
						'payload' => ['status' => 'success', 'message' => 'saved', 'location_code' => 'A'],
					];
				}
			};

			$this->request->method('getParsedBody')->willReturn(['loc1' => 'A', 'cat_id' => 1]);

			$controller = $this->makeControllerWithHelper($helper);
			$controller->save($this->request, $this->response, ['location_id' => '10']);

			$decoded = json_decode($this->responseBody, true);
			$this->assertSame('success', $decoded['status']);
			$this->assertSame([
				'mapInput',
				'applyLegacyRules:edit',
				'validate',
				'persistSave',
				'buildSaveResponse',
			], $helper->callOrder);
		}

		public function testAddReturns403WhenAclAddDenied(): void
		{
			$helper = new LocationFormHelper();
			$controller = $this->makeControllerWithAclMap($helper, ['acl_add' => false]);

			$this->request->method('getParsedBody')->willReturn(['loc1' => 'A']);
			$controller->add($this->request, $this->response);

			$decoded = json_decode($this->responseBody, true);
			$this->assertSame('error', $decoded['status']);
			$this->assertSame('No add access for location', $decoded['message']);
		}

		public function testSaveReturns403WhenAclEditDenied(): void
		{
			$helper = new LocationFormHelper();
			$controller = $this->makeControllerWithAclMap($helper, ['acl_edit' => false]);

			$this->request->method('getParsedBody')->willReturn(['loc1' => 'A']);
			$controller->save($this->request, $this->response, ['location_id' => '10']);

			$decoded = json_decode($this->responseBody, true);
			$this->assertSame('error', $decoded['status']);
			$this->assertSame('No edit access for location', $decoded['message']);
		}

		public function testDeleteByLocationCodeReturns403WhenAclDeleteDenied(): void
		{
			$helper = new LocationFormHelper();
			$controller = $this->makeControllerWithAclMap($helper, ['acl_delete' => false]);

			$this->request->method('getQueryParams')->willReturn(['location_code' => '10-01']);
			$this->request->method('getParsedBody')->willReturn([]);
			$controller->deleteByLocationCode($this->request, $this->response);

			$decoded = json_decode($this->responseBody, true);
			$this->assertSame('error', $decoded['status']);
			$this->assertSame('No delete access for location', $decoded['message']);
		}
	}
}
