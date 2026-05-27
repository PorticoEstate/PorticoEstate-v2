<?php

namespace Tests\Controllers
{
	require_once __DIR__ . '/../../vendor/autoload.php';

	use App\modules\property\controllers\ProjectController;
	use App\modules\property\helpers\ProjectFormHelper;
	use PHPUnit\Framework\MockObject\MockObject;
	use PHPUnit\Framework\TestCase;
	use Psr\Container\ContainerInterface;
	use Psr\Http\Message\ResponseInterface;
	use Psr\Http\Message\ServerRequestInterface;
	use Psr\Http\Message\StreamInterface;

	class ProjectControllerTest extends TestCase
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

		private function makeController(object $bo): ProjectController
		{
			return new class($this->container, $bo) extends ProjectController
			{
				private object $boStub;

				public function __construct(ContainerInterface $container, object $boStub)
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
					return true;
				}
			};
		}

		private function makeControllerWithHelper(object $bo, ProjectFormHelper $helper): ProjectController
		{
			return new class($this->container, $bo, $helper) extends ProjectController
			{
				private object $boStub;
				private ProjectFormHelper $helper;

				public function __construct(ContainerInterface $container, object $boStub, ProjectFormHelper $helper)
				{
					parent::__construct($container);
					$this->boStub = $boStub;
					$this->helper = $helper;
				}

				protected function bo()
				{
					return $this->boStub;
				}

				protected function hasReadAccess(): bool
				{
					return true;
				}

				protected function hasAddAccess(): bool
				{
					return true;
				}

				protected function hasEditAccess(): bool
				{
					return true;
				}

				protected function hasDeleteAccess(): bool
				{
					return true;
				}

				protected function formHelper(): ProjectFormHelper
				{
					return $this->helper;
				}
			};
		}

		public function testIndexReturnsDataTablesPayload(): void
		{
			$bo = new class
			{
				public int $total_records = 2;

				public function read(array $params): array
				{
					return [
						['project_id' => 10, 'name' => 'P10'],
						['project_id' => 11, 'name' => 'P11'],
					];
				}
			};

			$this->request->method('getQueryParams')->willReturn([
				'draw' => 7,
				'start' => 0,
				'length' => 10,
				'search' => ['value' => 'P'],
				'order' => [['column' => 0, 'dir' => 'asc']],
				'columns' => [['data' => 'project_id']],
			]);
			$this->request->method('getParsedBody')->willReturn([]);

			$controller = $this->makeController($bo);
			$controller->index($this->request, $this->response);

			$decoded = json_decode($this->responseBody, true);
			$this->assertSame(7, $decoded['draw']);
			$this->assertSame(2, $decoded['recordsTotal']);
			$this->assertSame(2, $decoded['recordsFiltered']);
			$this->assertCount(2, $decoded['data']);
		}

		public function testPostCollectionRoutesDataTablesToIndexShape(): void
		{
			$bo = new class
			{
				public int $total_records = 1;

				public function read(array $params): array
				{
					return [
						['project_id' => 42, 'name' => 'P42'],
					];
				}
			};

			$this->request->method('getQueryParams')->willReturn([]);
			$this->request->method('getParsedBody')->willReturn([
				'draw' => 3,
				'start' => 0,
				'length' => 25,
				'order' => [['column' => 0, 'dir' => 'asc']],
				'columns' => [['data' => 'project_id']],
			]);

			$controller = $this->makeController($bo);
			$controller->postCollection($this->request, $this->response);

			$decoded = json_decode($this->responseBody, true);
			$this->assertArrayHasKey('recordsTotal', $decoded);
			$this->assertArrayHasKey('draw', $decoded);
			$this->assertSame(3, $decoded['draw']);
		}

		public function testShowReturnsCanonicalEnvelope(): void
		{
			$bo = new class
			{
				public int $total_records = 1;

				public function read_single(int $id): array
				{
					return [
						'id' => $id,
						'project_id' => $id,
						'name' => 'Project',
					];
				}
			};

			$this->request->method('getQueryParams')->willReturn([]);
			$this->request->method('getParsedBody')->willReturn([]);

			$controller = $this->makeController($bo);
			$controller->show($this->request, $this->response, ['id' => 99]);

			$decoded = json_decode($this->responseBody, true);
			$this->assertSame('success', $decoded['status']);
			$this->assertSame(99, $decoded['data']['id']);
		}

		public function testGetOrdersReturnsDataTablesPayload(): void
		{
			$bo = new class
			{
				public int $total_records = 0;
				public object $so;

				public function __construct()
				{
					$this->so = new class
					{
						public int $total_records = 2;
					};
				}

				public function get_orders(array $params): array
				{
					return [
						['workorder_id' => 1, 'project_id' => 77],
						['workorder_id' => 2, 'project_id' => 77],
					];
				}
			};

			$this->request->method('getQueryParams')->willReturn([
				'draw' => 5,
				'start' => 0,
				'length' => 10,
				'order' => [['column' => 0, 'dir' => 'asc']],
				'columns' => [['data' => 'workorder_id']],
			]);
			$this->request->method('getParsedBody')->willReturn([]);

			$controller = $this->makeController($bo);
			$controller->getOrders($this->request, $this->response, ['id' => 77]);

			$decoded = json_decode($this->responseBody, true);
			$this->assertSame(5, $decoded['draw']);
			$this->assertSame(2, $decoded['recordsTotal']);
			$this->assertCount(2, $decoded['data']);
		}

		public function testStoreReturnsCreatedPayload(): void
		{
			$bo = new class
			{
				public int $total_records = 0;
			};

			$helper = new class extends ProjectFormHelper
			{
				public function mapInput(array $requestData, bool $isEdit = false, int $id = 0): array
				{
					return array('values' => array('name' => 'New'), 'values_attribute' => array(), 'is_edit' => false, 'errors' => array());
				}

				public function validate(array $state): array
				{
					return $state;
				}

				public function persistSave(array $state, object $bo): array
				{
					$state['id'] = 321;
					$state['receipt'] = array('id' => 321);
					return $state;
				}
			};

			$this->request->method('getQueryParams')->willReturn(array());
			$this->request->method('getParsedBody')->willReturn(array('name' => 'New'));

			$controller = $this->makeControllerWithHelper($bo, $helper);
			$controller->store($this->request, $this->response);

			$decoded = json_decode($this->responseBody, true);
			$this->assertSame('success', $decoded['status']);
			$this->assertSame(321, $decoded['data']['id']);
		}

		public function testGetFilesWithoutIdReturnsEmptyDataTablesPayload(): void
		{
			$bo = new class
			{
				public int $total_records = 0;
			};

			$helper = new ProjectFormHelper();

			$this->request->method('getQueryParams')->willReturn(array('draw' => 9));
			$this->request->method('getParsedBody')->willReturn(array());

			$controller = $this->makeControllerWithHelper($bo, $helper);
			$controller->getFiles($this->request, $this->response, array('id' => 0));

			$decoded = json_decode($this->responseBody, true);
			$this->assertSame(9, $decoded['draw']);
			$this->assertSame(0, $decoded['recordsTotal']);
			$this->assertSame(array(), $decoded['data']);
		}

		public function testDestroyReturnsSuccessPayload(): void
		{
			$bo = new class
			{
				public int $total_records = 0;
				public int $deletedId = 0;

				public function delete(int $id): void
				{
					$this->deletedId = $id;
				}
			};

			$helper = new ProjectFormHelper();

			$this->request->method('getQueryParams')->willReturn(array());
			$this->request->method('getParsedBody')->willReturn(array());

			$controller = $this->makeControllerWithHelper($bo, $helper);
			$controller->destroy($this->request, $this->response, array('id' => 45));

			$decoded = json_decode($this->responseBody, true);
			$this->assertSame('success', $decoded['status']);
			$this->assertSame(45, $decoded['data']['id']);
			$this->assertSame(45, $bo->deletedId);
		}

		public function testGetVouchersWithoutIdReturnsEmptyDataTablesPayload(): void
		{
			$bo = new class
			{
				public int $total_records = 0;
				public array $config = array();
			};

			$helper = new ProjectFormHelper();

			$this->request->method('getQueryParams')->willReturn(array('draw' => 11));
			$this->request->method('getParsedBody')->willReturn(array());

			$controller = $this->makeControllerWithHelper($bo, $helper);
			$controller->getVouchers($this->request, $this->response, array('id' => 0));

			$decoded = json_decode($this->responseBody, true);
			$this->assertSame(11, $decoded['draw']);
			$this->assertSame(0, $decoded['recordsTotal']);
			$this->assertSame(array(), $decoded['data']);
		}

		public function testUpdateReturnsSuccessPayload(): void
		{
			$bo = new class
			{
				public int $total_records = 0;
			};

			$helper = new class extends ProjectFormHelper
			{
				public function mapInput(array $requestData, bool $isEdit = false, int $id = 0): array
				{
					return array('values' => array('id' => $id, 'name' => 'Updated'), 'values_attribute' => array(), 'is_edit' => true, 'errors' => array());
				}

				public function validate(array $state): array
				{
					return $state;
				}

				public function persistSave(array $state, object $bo): array
				{
					$state['id'] = 77;
					$state['receipt'] = array('id' => 77);
					return $state;
				}
			};

			$this->request->method('getQueryParams')->willReturn(array());
			$this->request->method('getParsedBody')->willReturn(array('name' => 'Updated'));

			$controller = $this->makeControllerWithHelper($bo, $helper);
			$controller->update($this->request, $this->response, array('id' => 77));

			$decoded = json_decode($this->responseBody, true);
			$this->assertSame('success', $decoded['status']);
			$this->assertSame(77, $decoded['data']['id']);
		}

		public function testStoreReturnsErrorPayloadWhenValidationFails(): void
		{
			$bo = new class
			{
				public int $total_records = 0;
			};

			$helper = new class extends ProjectFormHelper
			{
				public function mapInput(array $requestData, bool $isEdit = false, int $id = 0): array
				{
					return array('values' => array(), 'values_attribute' => array(), 'is_edit' => false, 'errors' => array('Project name is required'));
				}

				public function validate(array $state): array
				{
					return $state;
				}

				public function persistSave(array $state, object $bo): array
				{
					$state['receipt'] = array(
						'error' => array(
							array('msg' => 'Project name is required')
						)
					);
					return $state;
				}
			};

			$this->request->method('getQueryParams')->willReturn(array());
			$this->request->method('getParsedBody')->willReturn(array());

			$controller = $this->makeControllerWithHelper($bo, $helper);
			$controller->store($this->request, $this->response);

			$decoded = json_decode($this->responseBody, true);
			$this->assertSame('error', $decoded['status']);
			$this->assertArrayHasKey('receipt', $decoded);
			$this->assertArrayHasKey('error', $decoded['receipt']);
		}

		public function testUpdateReturnsErrorPayloadWhenValidationFails(): void
		{
			$bo = new class
			{
				public int $total_records = 0;
			};

			$helper = new class extends ProjectFormHelper
			{
				public function mapInput(array $requestData, bool $isEdit = false, int $id = 0): array
				{
					return array('values' => array('id' => $id), 'values_attribute' => array(), 'is_edit' => true, 'errors' => array('Status is required'));
				}

				public function validate(array $state): array
				{
					return $state;
				}

				public function persistSave(array $state, object $bo): array
				{
					$state['receipt'] = array(
						'error' => array(
							array('msg' => 'Status is required')
						)
					);
					return $state;
				}
			};

			$this->request->method('getQueryParams')->willReturn(array());
			$this->request->method('getParsedBody')->willReturn(array());

			$controller = $this->makeControllerWithHelper($bo, $helper);
			$controller->update($this->request, $this->response, array('id' => 88));

			$decoded = json_decode($this->responseBody, true);
			$this->assertSame('error', $decoded['status']);
			$this->assertArrayHasKey('receipt', $decoded);
			$this->assertArrayHasKey('error', $decoded['receipt']);
		}
	}
}
