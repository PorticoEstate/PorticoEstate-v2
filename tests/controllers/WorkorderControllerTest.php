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
	}
}
