<?php

namespace Tests\Controllers
{
	require_once __DIR__ . '/../../vendor/autoload.php';

	use App\modules\property\controllers\WorkorderController;
	use App\modules\property\helpers\WorkorderFormHelper;
	use PHPUnit\Framework\MockObject\MockObject;
	use PHPUnit\Framework\TestCase;
	use Psr\Container\ContainerInterface;
	use Psr\Http\Message\ResponseInterface;
	use Psr\Http\Message\ServerRequestInterface;
	use Psr\Http\Message\StreamInterface;
	use Slim\Exception\HttpBadRequestException;
	use Slim\Exception\HttpForbiddenException;

	class WorkorderControllerTest extends TestCase
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

		private function makeController(object $bo, WorkorderFormHelper $helper): WorkorderController
		{
			return new class($this->container, $bo, $helper) extends WorkorderController
			{
				private object $boStub;
				private WorkorderFormHelper $helperStub;

				public function __construct(ContainerInterface $container, object $boStub, WorkorderFormHelper $helperStub)
				{
					parent::__construct($container);
					$this->boStub = $boStub;
					$this->helperStub = $helperStub;
				}

				protected function bo()
				{
					return $this->boStub;
				}

				protected function hasAddAccess(): bool
				{
					return true;
				}

				protected function hasEditAccess(): bool
				{
					return true;
				}

				protected function formHelper(): WorkorderFormHelper
				{
					return $this->helperStub;
				}
			};
		}

		private function makeControllerWithDeps(
			object $bo,
			WorkorderFormHelper $helper,
			?object $bocommon = null,
			bool $readAccess = true,
			bool $addAccess = true,
			bool $editAccess = true
		): WorkorderController
		{
			$bocommonStub = $bocommon ?? new class
			{
			};

			return new class($this->container, $bo, $helper, $bocommonStub, $readAccess, $addAccess, $editAccess) extends WorkorderController
			{
				private object $boStub;
				private WorkorderFormHelper $helperStub;
				private object $bocommonStub;
				private bool $readAccess;
				private bool $addAccess;
				private bool $editAccess;

				public function __construct(
					ContainerInterface $container,
					object $boStub,
					WorkorderFormHelper $helperStub,
					object $bocommonStub,
					bool $readAccess,
					bool $addAccess,
					bool $editAccess
				)
				{
					parent::__construct($container);
					$this->boStub = $boStub;
					$this->helperStub = $helperStub;
					$this->bocommonStub = $bocommonStub;
					$this->readAccess = $readAccess;
					$this->addAccess = $addAccess;
					$this->editAccess = $editAccess;
				}

				protected function bo()
				{
					return $this->boStub;
				}

				protected function bocommon()
				{
					return $this->bocommonStub;
				}

				protected function hasReadAccess(): bool
				{
					return $this->readAccess;
				}

				protected function hasAddAccess(): bool
				{
					return $this->addAccess;
				}

				protected function hasEditAccess(): bool
				{
					return $this->editAccess;
				}

				protected function formHelper(): WorkorderFormHelper
				{
					return $this->helperStub;
				}
			};
		}

		public function testStoreReturnsSuccessEnvelope(): void
		{
			$bo = new class
			{
			};

			$helper = $this->createMock(WorkorderFormHelper::class);
			$mappedState = array(
				'values' => array('title' => 'WO', 'project_id' => 10, 'status' => 'open', 'b_account_id' => 22),
				'values_attribute' => array(),
				'is_edit' => false,
				'errors' => array(),
			);
			$persistedState = $mappedState;
			$persistedState['id'] = 501;
			$persistedState['receipt'] = array('id' => 501, 'error' => array());

			$helper->expects($this->once())->method('mapInput')->willReturn($mappedState);
			$helper->expects($this->once())->method('validate')->willReturn($mappedState);
			$helper->expects($this->once())->method('persistSave')->willReturn($persistedState);

			$this->request->method('getQueryParams')->willReturn(array());
			$this->request->method('getParsedBody')->willReturn(array('values' => array('title' => 'WO')));

			$controller = $this->makeController($bo, $helper);
			$controller->store($this->request, $this->response);

			$payload = json_decode($this->responseBody, true);
			$this->assertSame('success', $payload['status']);
			$this->assertSame(501, $payload['data']['id']);
		}

		public function testStoreRejectsInvalidValuesPayloadType(): void
		{
			$bo = new class
			{
			};
			$helper = $this->createMock(WorkorderFormHelper::class);

			$this->request->method('getQueryParams')->willReturn(array());
			$this->request->method('getParsedBody')->willReturn(array('values' => 'invalid'));

			$controller = $this->makeController($bo, $helper);

			$this->expectException(HttpBadRequestException::class);
			$controller->store($this->request, $this->response);
		}

		public function testStoreRejectsInvalidRelationInfoPayloadType(): void
		{
			$bo = new class
			{
			};
			$helper = $this->createMock(WorkorderFormHelper::class);

			$this->request->method('getQueryParams')->willReturn(array());
			$this->request->method('getParsedBody')->willReturn(array(
				'values' => array('title' => 'WO'),
				'RelationInfo' => 'invalid',
			));

			$controller = $this->makeController($bo, $helper);

			$this->expectException(HttpBadRequestException::class);
			$controller->store($this->request, $this->response);
		}

		public function testUpdateReturnsErrorEnvelopeWhenValidationFails(): void
		{
			$bo = new class
			{
			};

			$helper = $this->createMock(WorkorderFormHelper::class);
			$mappedState = array(
				'values' => array('id' => 77),
				'values_attribute' => array(),
				'is_edit' => true,
				'errors' => array(),
			);
			$validatedState = $mappedState;
			$validatedState['errors'] = array('Please select a status !');
			$persistedState = $validatedState;
			$persistedState['receipt'] = array('error' => array(array('msg' => 'Please select a status !')));
			$persistedState['id'] = 77;

			$helper->expects($this->once())->method('mapInput')->willReturn($mappedState);
			$helper->expects($this->once())->method('validate')->willReturn($validatedState);
			$helper->expects($this->once())->method('persistSave')->willReturn($persistedState);

			$this->request->method('getQueryParams')->willReturn(array());
			$this->request->method('getParsedBody')->willReturn(array('values' => array('title' => 'WO')));

			$controller = $this->makeController($bo, $helper);
			$controller->update($this->request, $this->response, array('id' => 77));

			$payload = json_decode($this->responseBody, true);
			$this->assertSame('error', $payload['status']);
			$this->assertSame(77, $payload['data']['id']);
			$this->assertNotEmpty($payload['receipt']['error']);
		}

		public function testBuildMultiUploadFileReturnsRestBasedActionUrl(): void
		{
			if (!class_exists('\\phpgwapi_jquery'))
			{
				eval('class phpgwapi_jquery { public static function init_multi_upload_file() {} }');
			}
			if (!class_exists('\\phpgw'))
			{
				eval('class phpgw { public static function link($url, $extravars = array()) { if (is_array($extravars) && !empty($extravars)) { $query = http_build_query($extravars); return $url . (strpos($url, "?") === false ? "?" : "&") . $query; } return $url; } }');
			}

			$bo = new class
			{
			};
			$helper = $this->createMock(WorkorderFormHelper::class);

			$this->request->method('getQueryParams')->willReturn(array());
			$this->request->method('getParsedBody')->willReturn(array());

			$controller = $this->makeController($bo, $helper);
			$controller->buildMultiUploadFile($this->request, $this->response, array('id' => 88));

			$decoded = json_decode($this->responseBody, true);
			$this->assertArrayHasKey('multi_upload_action', $decoded);
			$this->assertStringContainsString('/property/workorder/88/multi-upload', $decoded['multi_upload_action']);
			$this->assertStringNotContainsString('menuaction=', $decoded['multi_upload_action']);
		}

		public function testStoreRejectsWhenAddAccessMissing(): void
		{
			$bo = new class
			{
			};
			$helper = $this->createMock(WorkorderFormHelper::class);

			$this->request->method('getQueryParams')->willReturn(array());
			$this->request->method('getParsedBody')->willReturn(array('values' => array('title' => 'WO')));

			$controller = $this->makeControllerWithDeps($bo, $helper, null, true, false, true);

			$this->expectException(HttpForbiddenException::class);
			$controller->store($this->request, $this->response);
		}

		public function testUpdateRejectsWhenEditAccessMissing(): void
		{
			$bo = new class
			{
			};
			$helper = $this->createMock(WorkorderFormHelper::class);

			$this->request->method('getQueryParams')->willReturn(array());
			$this->request->method('getParsedBody')->willReturn(array('values' => array('title' => 'WO')));

			$controller = $this->makeControllerWithDeps($bo, $helper, null, true, true, false);

			$this->expectException(HttpForbiddenException::class);
			$controller->update($this->request, $this->response, array('id' => 77));
		}

		public function testUpdateRejectsInvalidId(): void
		{
			$bo = new class
			{
			};
			$helper = $this->createMock(WorkorderFormHelper::class);

			$this->request->method('getQueryParams')->willReturn(array());
			$this->request->method('getParsedBody')->willReturn(array('values' => array('title' => 'WO')));

			$controller = $this->makeControllerWithDeps($bo, $helper);

			$this->expectException(HttpBadRequestException::class);
			$controller->update($this->request, $this->response, array('id' => 0));
		}

		public function testGetCategoryReturnsEmptyPayloadForMissingCategoryId(): void
		{
			$bo = new class
			{
			};
			$helper = $this->createMock(WorkorderFormHelper::class);

			$this->request->method('getQueryParams')->willReturn(array('cat_id' => 0));
			$this->request->method('getParsedBody')->willReturn(array());

			$controller = $this->makeControllerWithDeps($bo, $helper);
			$controller->getCategory($this->request, $this->response);

			$payload = json_decode($this->responseBody, true);
			$this->assertSame(array(), $payload);
		}

		public function testGetOtherOrdersReturnsDataTableEnvelope(): void
		{
			$bo = new class
			{
				public function get_other_orders(int $vendorId, string $locationCode): array
				{
					return array(
						array('workorder_id' => 1, 'vendor_id' => $vendorId, 'location_code' => $locationCode),
						array('workorder_id' => 2, 'vendor_id' => $vendorId, 'location_code' => $locationCode),
					);
				}
			};
			$helper = $this->createMock(WorkorderFormHelper::class);

			$this->request->method('getQueryParams')->willReturn(array(
				'vendor_id' => 55,
				'location_code' => '123-45',
				'draw' => 9,
			));
			$this->request->method('getParsedBody')->willReturn(array());

			$controller = $this->makeControllerWithDeps($bo, $helper);
			$controller->getOtherOrders($this->request, $this->response);

			$payload = json_decode($this->responseBody, true);
			$this->assertSame(9, $payload['draw']);
			$this->assertSame(2, $payload['recordsTotal']);
			$this->assertCount(2, $payload['data']);
			$this->assertSame(1, $payload['data'][0]['id']);
			$this->assertStringContainsString("menuaction=property.uiworkorder.view", $payload['data'][0]['url']);
			$this->assertStringContainsString("value='1'", $payload['data'][0]['select']);
		}

		public function testReceiveOrderDelegatesToBo(): void
		{
			$bo = new class
			{
				public function receive_order(int $id, float $receivedAmount): array
				{
					return array('status' => 'ok', 'id' => $id, 'received_amount' => $receivedAmount);
				}
			};
			$helper = $this->createMock(WorkorderFormHelper::class);

			$this->request->method('getQueryParams')->willReturn(array('received_amount' => 345.5));
			$this->request->method('getParsedBody')->willReturn(array());

			$controller = $this->makeControllerWithDeps($bo, $helper);
			$controller->receiveOrder($this->request, $this->response, array('id' => 77));

			$payload = json_decode($this->responseBody, true);
			$this->assertSame('ok', $payload['status']);
			$this->assertSame(77, $payload['id']);
			$this->assertSame(345.5, $payload['received_amount']);
		}

		public function testGetFilesReturnsEmptyDatatableForMissingId(): void
		{
			$bo = new class
			{
			};
			$helper = $this->createMock(WorkorderFormHelper::class);

			$this->request->method('getQueryParams')->willReturn(array('draw' => 3));
			$this->request->method('getParsedBody')->willReturn(array());

			$controller = $this->makeControllerWithDeps($bo, $helper);
			$controller->getFiles($this->request, $this->response, array('id' => 0));

			$payload = json_decode($this->responseBody, true);
			$this->assertSame(3, $payload['draw']);
			$this->assertSame(0, $payload['recordsTotal']);
			$this->assertSame(array(), $payload['data']);
		}

		public function testGetFilesUsesRestImageEndpointForImageRows(): void
		{
			if (!class_exists('\\phpgw'))
			{
				eval('class phpgw { public static function link($url, $extravars = array()) { if (is_array($extravars) && !empty($extravars)) { $query = http_build_query($extravars); return $url . (strpos($url, "?") === false ? "?" : "&") . $query; } return $url; } }');
			}
			if (!function_exists('lang'))
			{
				eval('function lang($text) { return $text; }');
			}

			$bo = new class
			{
				public function get_files(int $id): array
				{
					return array(
						array(
							'file_id' => 8,
							'name' => 'sample.pdf',
							'file_name' => 'sample.pdf',
							'directory' => '/workorder/77',
							'mime_type' => 'application/pdf',
							'tags' => '',
						),
						array(
							'file_id' => 9,
							'name' => 'sample.png',
							'file_name' => 'sample.png',
							'directory' => '/workorder/77',
							'mime_type' => 'image/png',
							'tags' => '',
						)
					);
				}
			};
			$helper = $this->createMock(WorkorderFormHelper::class);

			$this->request->method('getQueryParams')->willReturn(array('draw' => 2));
			$this->request->method('getParsedBody')->willReturn(array());

			$controller = $this->makeControllerWithDeps($bo, $helper);
			$controller->getFiles($this->request, $this->response, array('id' => 77));

			$payload = json_decode($this->responseBody, true);
			$this->assertCount(2, $payload['data']);
			$this->assertStringContainsString('/property/workorder/files/view', $payload['data'][0]['file_name']);
			$this->assertStringNotContainsString('menuaction=property.uiworkorder.view_file', $payload['data'][0]['file_name']);
			$this->assertStringContainsString('/property/workorder/77/files/image', $payload['data'][1]['img_url']);
			$this->assertStringNotContainsString('menuaction=property.uiworkorder.view_image', $payload['data'][1]['img_url']);
		}

		public function testUpdateFileDataReturnsSuccessEnvelopeForNoopAction(): void
		{
			if (!function_exists('CreateObject'))
			{
				eval('function CreateObject($name) { return new class { public function delete_file($path, $opts) {} public function set_tags($ids, $tags) {} public function remove_tags($ids, $tags) {} }; }');
			}

			$bo = new class
			{
			};
			$helper = $this->createMock(WorkorderFormHelper::class);

			$this->request->method('getQueryParams')->willReturn(array(
				'action' => 'noop',
				'ids' => array(10, 20),
			));
			$this->request->method('getParsedBody')->willReturn(array());

			$controller = $this->makeControllerWithDeps($bo, $helper);
			$controller->updateFileData($this->request, $this->response, array('id' => 77));

			$payload = json_decode($this->responseBody, true);
			$this->assertSame('success', $payload['status']);
			$this->assertSame('noop', $payload['action']);
			$this->assertSame(array(10, 20), $payload['ids']);
		}

		public function testGetVendorContractRejectsWhenReadAccessMissing(): void
		{
			$bo = new class
			{
			};
			$helper = $this->createMock(WorkorderFormHelper::class);

			$this->request->method('getQueryParams')->willReturn(array('vendor_id' => 1));
			$this->request->method('getParsedBody')->willReturn(array());

			$controller = $this->makeControllerWithDeps($bo, $helper, null, false, true, true);

			$this->expectException(HttpForbiddenException::class);
			$controller->getVendorContract($this->request, $this->response);
		}
	}
}
