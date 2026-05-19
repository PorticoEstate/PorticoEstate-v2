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

		public function testQueryRoleAcceptsBodyParamsAndOverridesQueryParams(): void
		{
			$bo = new class
			{
				public int $total_records = 0;
				public array $capturedParams = [];

				public function get_responsible(array $params): array
				{
					$this->capturedParams = $params;
					$this->total_records = 1;
					return [['id' => 1]];
				}
			};

			$controller = new class($this->container, $bo) extends LocationController
			{
				private $boStub;

				public function __construct(ContainerInterface $container, $boStub)
				{
					parent::__construct($container);
					$this->boStub = $boStub;
				}

				protected function bo()
				{
					return $this->boStub;
				}

				protected function currentAccountId(): int
				{
					return 55;
				}
			};

			$this->request->method('getQueryParams')->willReturn([
				'user_id' => 10,
				'role_id' => 2,
				'start' => 0,
				'length' => 10,
				'draw' => 1,
				'search' => ['value' => 'query-side'],
				'order' => [['column' => 0, 'dir' => 'asc']],
				'columns' => [['data' => 'loc1']],
			]);
			$this->request->method('getParsedBody')->willReturn([
				'user_id' => 77,
				'role_id' => 9,
				'start' => 5,
				'length' => -1,
				'draw' => 3,
				'search' => ['value' => 'body-side'],
				'order' => [['column' => 0, 'dir' => 'desc']],
				'columns' => [['data' => 'loc2']],
			]);

			$controller->queryRole($this->request, $this->response);

			$this->assertSame(77, $bo->capturedParams['user_id']);
			$this->assertSame(9, $bo->capturedParams['role_id']);
			$this->assertSame(5, $bo->capturedParams['start']);
			$this->assertSame(-1, $bo->capturedParams['results']);
			$this->assertSame('body-side', $bo->capturedParams['query']);
			$this->assertSame('loc2', $bo->capturedParams['order']);
			$this->assertSame('DESC', $bo->capturedParams['sort']);

			$decoded = json_decode($this->responseBody, true);
			$this->assertSame(4, $decoded['draw']);
			$this->assertSame(1, $decoded['recordsTotal']);
		}

		public function testGetDocumentsAcceptsBodyParamsAndOverridesQueryParams(): void
		{
			$sodocument = new class
			{
				public int $total_records = 3;
				public array $capturedParams = [];

				public function read_at_location(array $params): array
				{
					$this->capturedParams = $params;
					return [];
				}
			};

			$genericDocument = new class
			{
				public int $total_records = 2;
				public array $capturedParams = [];

				public function read(array $params): array
				{
					$this->capturedParams = $params;
					return [];
				}
			};

			$locations = new class
			{
				public function get_id(string $app, string $location): int
				{
					return 321;
				}
			};

			$bo = new class
			{
				public function get_item_id(string $locationCode): int
				{
					return 789;
				}
			};

			$controller = new class($this->container, $bo, $sodocument, $genericDocument, $locations) extends LocationController
			{
				private $boStub;
				private $sodocumentStub;
				private $genericDocumentStub;
				private $locationsStub;

				public function __construct(ContainerInterface $container, $boStub, $sodocumentStub, $genericDocumentStub, $locationsStub)
				{
					parent::__construct($container);
					$this->boStub = $boStub;
					$this->sodocumentStub = $sodocumentStub;
					$this->genericDocumentStub = $genericDocumentStub;
					$this->locationsStub = $locationsStub;
				}

				protected function bo()
				{
					return $this->boStub;
				}

				protected function createObject(string $name, ...$args)
				{
					if ($name === 'property.sodocument')
					{
						return $this->sodocumentStub;
					}
					if ($name === 'property.sogeneric_document')
					{
						return $this->genericDocumentStub;
					}

					return parent::createObject($name, ...$args);
				}

				protected function makeLocationsController()
				{
					return $this->locationsStub;
				}
			};

			$this->request->method('getQueryParams')->willReturn([
				'doc_type' => 1,
				'location_code' => '10-01',
				'start' => 0,
				'length' => 10,
				'draw' => 1,
				'search' => ['value' => 'query-side'],
				'order' => [['column' => 0, 'dir' => 'asc']],
				'columns' => [['data' => 'name']],
			]);
			$this->request->method('getParsedBody')->willReturn([
				'doc_type' => 9,
				'location_code' => '20-02',
				'start' => 4,
				'length' => -1,
				'draw' => 5,
				'export' => true,
				'search' => ['value' => 'body-side'],
				'order' => [['column' => 0, 'dir' => 'desc']],
				'columns' => [['data' => 'title']],
			]);

			$controller->getDocuments($this->request, $this->response);

			$this->assertSame(9, $sodocument->capturedParams['doc_type']);
			$this->assertSame('20-02', $sodocument->capturedParams['location_code']);
			$this->assertSame(4, $sodocument->capturedParams['start']);
			$this->assertSame(-1, $sodocument->capturedParams['results']);
			$this->assertSame('body-side', $sodocument->capturedParams['query']);
			$this->assertSame('title', $sodocument->capturedParams['order']);
			$this->assertSame('DESC', $sodocument->capturedParams['sort']);
			$this->assertTrue($sodocument->capturedParams['allrows']);

			$this->assertSame(321, $genericDocument->capturedParams['location_id']);
			$this->assertSame(789, $genericDocument->capturedParams['location_item_id']);
			$this->assertSame(9, $genericDocument->capturedParams['cat_id']);

			$decoded = json_decode($this->responseBody, true);
			$this->assertSame(6, $decoded['draw']);
			$this->assertSame(5, $decoded['recordsTotal']);
		}

		public function testDownloadDefaultUsesReadAndReturnsResponse(): void
		{
			$bo = new class
			{
				public string $acl_location = '.location.1';
				public array $uicols = [
					'name' => ['loc1'],
					'descr' => ['Loc1'],
					'input_type' => [''],
				];
				public array $capturedReadParams = [];

				public function read(array $params): array
				{
					$this->capturedReadParams = $params;
					return [['loc1' => 'A']];
				}
			};

			$boCommon = new class
			{
				public array $captured = [];

				public function download($list, $name, $descr, $inputType): void
				{
					$this->captured = [$list, $name, $descr, $inputType];
				}
			};

			$controller = new class($this->container, $bo, $boCommon) extends LocationController
			{
				private $boStub;
				private $boCommonStub;

				public function __construct(ContainerInterface $container, $boStub, $boCommonStub)
				{
					parent::__construct($container);
					$this->boStub = $boStub;
					$this->boCommonStub = $boCommonStub;
				}

				protected function bo()
				{
					return $this->boStub;
				}

				protected function hasReadAccess(): bool
				{
					return true;
				}

				protected function makeBoCommon()
				{
					return $this->boCommonStub;
				}
			};

			$this->request->method('getQueryParams')->willReturn([]);

			$returned = $controller->download($this->request, $this->response, []);

			$this->assertSame($this->response, $returned);
			$this->assertSame(['allrows' => true], $bo->capturedReadParams);
			$this->assertSame([['loc1' => 'A']], $boCommon->captured[0]);
		}

		public function testDownloadSummaryUsesReadSummary(): void
		{
			$bo = new class
			{
				public string $acl_location = '.location.1';
				public array $uicols = [
					'name' => ['loc1'],
					'descr' => ['Loc1'],
					'input_type' => [''],
				];
				public bool $summaryCalled = false;

				public function read_summary(): array
				{
					$this->summaryCalled = true;
					return [['loc1' => 'S']];
				}
			};

			$boCommon = new class
			{
				public array $captured = [];

				public function download($list, $name, $descr, $inputType): void
				{
					$this->captured = [$list, $name, $descr, $inputType];
				}
			};

			$controller = new class($this->container, $bo, $boCommon) extends LocationController
			{
				private $boStub;
				private $boCommonStub;

				public function __construct(ContainerInterface $container, $boStub, $boCommonStub)
				{
					parent::__construct($container);
					$this->boStub = $boStub;
					$this->boCommonStub = $boCommonStub;
				}

				protected function bo()
				{
					return $this->boStub;
				}

				protected function hasReadAccess(): bool
				{
					return true;
				}

				protected function makeBoCommon()
				{
					return $this->boCommonStub;
				}
			};

			$this->request->method('getQueryParams')->willReturn(['download_type' => 'summary']);
			$controller->download($this->request, $this->response, []);

			$this->assertTrue($bo->summaryCalled);
			$this->assertSame([['loc1' => 'S']], $boCommon->captured[0]);
		}

		public function testDownloadResponsibilityRoleUsesGetResponsibleAndAppendsColumns(): void
		{
			$bo = new class
			{
				public string $acl_location = '.location.1';
				public array $uicols = [
					'name' => ['loc1'],
					'descr' => ['Loc1'],
					'input_type' => [''],
				];
				public array $capturedParams = [];

				public function get_responsible(array $params): array
				{
					$this->capturedParams = $params;
					return [[
						'loc1' => 'A',
						'responsible_contact' => 'John',
						'contact_id' => 88,
					]];
				}
			};

			$boCommon = new class
			{
				public array $captured = [];

				public function download($list, $name, $descr, $inputType): void
				{
					$this->captured = [$list, $name, $descr, $inputType];
				}
			};

			$controller = new class($this->container, $bo, $boCommon) extends LocationController
			{
				private $boStub;
				private $boCommonStub;

				public function __construct(ContainerInterface $container, $boStub, $boCommonStub)
				{
					parent::__construct($container);
					$this->boStub = $boStub;
					$this->boCommonStub = $boCommonStub;
				}

				protected function bo()
				{
					return $this->boStub;
				}

				protected function hasReadAccess(): bool
				{
					return true;
				}

				protected function makeBoCommon()
				{
					return $this->boCommonStub;
				}
			};

			$this->request->method('getQueryParams')->willReturn([
				'download_type' => 'responsiblility_role',
				'user_id' => 5,
				'role_id' => 42,
				'type_id' => 1,
				'search' => ['value' => 'abc'],
			]);

			$controller->download($this->request, $this->response, []);

			$this->assertSame(5, $bo->capturedParams['user_id']);
			$this->assertSame(42, $bo->capturedParams['role_id']);
			$this->assertSame('abc', $bo->capturedParams['query']);
			$this->assertSame(42, $boCommon->captured[0][0]['role_id']);
			$this->assertContains('role_id', $boCommon->captured[1]);
			$this->assertContains('responsible_contact', $boCommon->captured[1]);
			$this->assertContains('contact_id', $boCommon->captured[1]);
		}

		public function testDownloadReturns403WhenReadAclDenied(): void
		{
			$bo = new class
			{
				public string $acl_location = '.location.1';
			};

			$controller = new class($this->container, $bo) extends LocationController
			{
				private $boStub;

				public function __construct(ContainerInterface $container, $boStub)
				{
					parent::__construct($container);
					$this->boStub = $boStub;
				}

				protected function bo()
				{
					return $this->boStub;
				}

				protected function hasReadAccess(): bool
				{
					return false;
				}
			};

			$this->request->method('getQueryParams')->willReturn([]);
			$controller->download($this->request, $this->response, []);

			$decoded = json_decode($this->responseBody, true);
			$this->assertSame('error', $decoded['status']);
			$this->assertSame('No read access for location', $decoded['message']);
		}
	}
}
