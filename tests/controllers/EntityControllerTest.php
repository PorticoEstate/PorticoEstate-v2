<?php

namespace
{
	require_once __DIR__ . '/../../vendor/autoload.php';
	require_once __DIR__ . '/../../src/helpers/Sanitizer.php';

		if (!defined('ACL_READ'))
		{
			define('ACL_READ', 1);
		}
		if (!defined('ACL_ADD'))
		{
			define('ACL_ADD', 2);
		}
		if (!defined('ACL_EDIT'))
		{
			define('ACL_EDIT', 4);
		}
		if (!defined('ACL_DELETE'))
		{
			define('ACL_DELETE', 8);
		}

		if (!function_exists('include_class'))
		{
			function include_class(string $appname, string $name): void
			{
				// Test shim: legacy loader not needed in unit tests.
			}
		}

		if (!function_exists('lang'))
		{
			function lang(string $text, ...$args): string
			{
				if (!$args)
				{
					return $text;
				}

				return vsprintf($text, $args);
			}
		}

	// ---------------------------------------------------------------------------
	// Minimal stub so createMock('\property_boentity') works without loading the
	// full legacy class (which has heavyweight constructor dependencies).
	// ---------------------------------------------------------------------------
	if (!class_exists('property_boentity'))
	{
		abstract class property_boentity
		{
			public $start;
			public $query;
			public $filter;
			public $sort;
			public $order;
			public $allrows;
			public $total_records;
			public $entity_id;
			public $cat_id;
			public $type_app = [];

			abstract public function read(): array;
			abstract public function read_single(array $params): array;
			abstract public function save(array $values, array $attribs, string $mode, int $entity_id, int $cat_id): array;
			abstract public function delete(int $id): void;
			abstract public function get_items_per_qr(string $qr_code): array;
			abstract public function read_entity_to_link(array $params): array;
			abstract public function get_inventory(array $params): array;
		}
	}


}

namespace Tests\Controllers
{

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use App\modules\property\controllers\EntityController;
use App\modules\property\inc\EntityFormHelper;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;

/**
 * Phase 4.5 – output parity / call-chain verification for EntityController.
 *
 * Strategy:
 *   - The legacy uientity::query() and EntityController::index() both ultimately
 *     call property_boentity::read().  We verify here that EntityController
 *     (a) sets the correct bo properties from request params before calling read(),
 *     (b) returns exactly what bo::read() returns (no hidden decorations), and
 *     (c) wraps the result as a JSON response.
 *
 *   - uientity::query() adds HTML decorations (link, img_url, thumbnail_flag)
 *     that are presentation-layer concerns and are intentionally absent from the
 *     REST response.  This is the only expected difference.
 *
 *   - Subsidiary helpers (getFiles, getRelated, getInventory, getCases, …) are
 *     verified to delegate to the correct bo / controller_helper method with the
 *     right arguments extracted from the route args and query params.
 */
class EntityControllerTest extends TestCase
{
	private ServerRequestInterface&MockObject $request;
	private ResponseInterface&MockObject      $response;
	private ContainerInterface&MockObject     $container;
	private string                            $responseBody;

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

	// ── Helpers ──────────────────────────────────────────────────────────────

	/**
	 * Create a controller whose private bo() factory returns the provided stub.
	 */
	private function makeController(
		\property_boentity $boStub,
		?EntityFormHelper $helperStub = null,
		?callable $onAbort = null
	): EntityController
	{
		$controller = new class($this->container, $boStub, $helperStub, $onAbort) extends EntityController
		{
			private \property_boentity $boStub;
			private ?EntityFormHelper $helperStub;
			private $onAbort;

			public function __construct(
				ContainerInterface $c,
				\property_boentity $stub,
				?EntityFormHelper $helperStub = null,
				?callable $onAbort = null
			)
			{
				parent::__construct($c);
				$this->boStub = $stub;
				$this->helperStub = $helperStub;
				$this->onAbort = $onAbort;
			}

			/** @phpstan-ignore-next-line */
			protected function bo(array $args): \property_boentity
			{
				return $this->boStub;
			}

			protected function enrichRows(array $rows, \property_boentity $bo): array
			{
				return $rows;
			}

			/** @phpstan-ignore-next-line */
			protected function assertEntityAcl(
				ServerRequestInterface $request,
				array $args,
				int $aclType,
				string $message
			): \property_boentity {
				return $this->boStub;
			}

			protected function formHelper(): EntityFormHelper
			{
				if ($this->helperStub)
				{
					return $this->helperStub;
				}

				return new class extends EntityFormHelper
				{
					public function persistSave(
						array $values,
						$attributes,
						string $action,
						int $entityId,
						int $catId,
						object $bo,
						$valuesChecklistStage = null
					): array {
						$receipt = $bo->save($values, (array)$attributes, $action, $entityId, $catId);
						$values['id'] = $receipt['id'] ?? ($values['id'] ?? 0);

						return [
							'receipt' => $receipt,
							'values' => $values,
						];
					}

					public function handleFiles(array $values, string $categoryDir, string $typeApp, array &$errors): void
					{
						// No-op for controller unit tests.
					}
				};
			}

			protected function abortTransaction(): void
			{
				if ($this->onAbort)
				{
					($this->onAbort)();
				}
			}
		};
		return $controller;
	}

	private function baseArgs(): array
	{
		return ['type' => 'entity', 'entity_id' => '5', 'cat_id' => '3'];
	}

	// ── index() ──────────────────────────────────────────────────────────────

	/**
	 * EntityController::index() must call bo::read() and return its result verbatim
	 * as JSON — no HTML decorations (links, img_url, thumbnail_flag) added.
	 *
	 * Parity note: uientity::query() adds 'link', 'img_url', 'file_name', and
	 * 'thumbnail_flag' to each row for XSLT rendering.  These are presentation
	 * concerns and are intentionally absent from the REST response.
	 */
	public function testIndexCallsBoReadAndReturnsVerbatimJson(): void
	{
		$rawRows = [
			['id' => 1, 'name' => 'Item A', 'loc1' => '101'],
			['id' => 2, 'name' => 'Item B', 'loc1' => '102'],
		];

		$bo = $this->createMock(\property_boentity::class);
		$bo->expects($this->once())->method('read')->willReturn($rawRows);

		$this->request->method('getQueryParams')->willReturn([
			'start'   => '10',
			'query'   => 'foo',
			'filter'  => 'active',
			'sort'    => 'name',
			'dir'     => 'ASC',
			'allrows' => 'false',
		]);

		$controller = $this->makeController($bo);
		$controller->index($this->request, $this->response, $this->baseArgs());

		$decoded = json_decode($this->responseBody, true);
		$this->assertSame($rawRows, $decoded, 'index() must return the raw bo::read() result without decorations');

		// Verify no presentation keys were injected
		foreach ($decoded as $row)
		{
			$this->assertArrayNotHasKey('link',           $row, 'REST response must not contain HTML link decoration');
			$this->assertArrayNotHasKey('img_url',        $row, 'REST response must not contain img_url decoration');
			$this->assertArrayNotHasKey('thumbnail_flag', $row, 'REST response must not contain thumbnail_flag decoration');
		}
	}

	/**
	 * Request params must be mapped correctly to bo properties before read().
	 *
	 * uientity::query() reads DataTables wire params (search[value], columns[n][data],
	 * order[0][column], order[0][dir]) via Sanitizer::get_var() from superglobals.
	 * EntityController::index() reads cleaner REST params from the PSR-7 query string.
	 * Both ultimately assign the same fields on bo before calling read().
	 */
	public function testIndexMapsRequestParamsToBoProperties(): void
	{
		$bo = $this->createMock(\property_boentity::class);
		$bo->expects($this->once())
			->method('read')
			->with([
				'start' => 20,
				'query' => 'searchterm',
				'filter' => 'pending',
				'order' => 'id',
				'sort' => 'DESC',
				'allrows' => false,
			])
			->willReturn([]);

		$this->request->method('getQueryParams')->willReturn([
			'start'   => '20',
			'query'   => 'searchterm',
			'filter'  => 'pending',
			'sort'    => 'id',
			'dir'     => 'DESC',
			'allrows' => 'false',
		]);

		$controller = $this->makeController($bo);
		$controller->index($this->request, $this->response, $this->baseArgs());
	}

	public function testIndexAllrowsParamSetsTrueWhenFlagPresent(): void
	{
		$bo = $this->createMock(\property_boentity::class);
		$bo->method('read')->willReturn([]);

		$this->request->method('getQueryParams')->willReturn(['allrows' => 'true']);

		$controller = $this->makeController($bo);
		$controller->index($this->request, $this->response, $this->baseArgs());

		$this->assertTrue($bo->allrows);
	}

	// ── show() ───────────────────────────────────────────────────────────────

	public function testShowReturnsItemFromBoReadSingle(): void
	{
		$item = ['id' => 7, 'name' => 'Detail', 'cat_id' => 3];

		$bo = $this->createMock(\property_boentity::class);
		$bo->method('read_single')->with(['id' => 7])->willReturn($item);

		$args = array_merge($this->baseArgs(), ['id' => '7']);
		$controller = $this->makeController($bo);
		$controller->show($this->request, $this->response, $args);

		$decoded = json_decode($this->responseBody, true);
		$this->assertSame($item, $decoded);
	}

	public function testShowThrowsBadRequestForZeroId(): void
	{
		$this->expectException(HttpBadRequestException::class);

		$bo = $this->createMock(\property_boentity::class);
		$args = array_merge($this->baseArgs(), ['id' => '0']);
		$this->makeController($bo)->show($this->request, $this->response, $args);
	}

	public function testShowThrowsNotFoundForEmptyResult(): void
	{
		$this->expectException(HttpNotFoundException::class);

		$bo = $this->createMock(\property_boentity::class);
		$bo->method('read_single')->willReturn([]);
		$args = array_merge($this->baseArgs(), ['id' => '99']);
		$this->makeController($bo)->show($this->request, $this->response, $args);
	}

	// ── store() ──────────────────────────────────────────────────────────────

	public function testStoreCallsBoSaveWithAddModeAndReturns201(): void
	{
		$receipt = ['id' => 10, 'message' => [], 'error' => []];

		$bo = $this->createMock(\property_boentity::class);
		$bo->expects($this->once())
			->method('save')
			->with(
				['title' => 'New'],
				['1' => 'val'],
				'add',
				5,   // entity_id
				3    // cat_id
			)
			->willReturn($receipt);

		$this->request->method('getParsedBody')->willReturn([
			'values'           => ['title' => 'New'],
			'values_attribute' => ['1' => 'val'],
		]);

		$controller = $this->makeController($bo);

		$this->response->expects($this->atLeastOnce())
			->method('withStatus')
			->with(201)
			->willReturn($this->response);

		$controller->store($this->request, $this->response, $this->baseArgs());

		$decoded = json_decode($this->responseBody, true);
		$this->assertSame($receipt, $decoded);
	}

	public function testStoreForwardsChecklistPayloadToSharedHelper(): void
	{
		$bo = $this->createMock(\property_boentity::class);
		$bo->category_dir = 'entity';
		$bo->type = 'entity';
		$bo->type_app = ['entity' => 'property'];

		$helper = $this->getMockBuilder(EntityFormHelper::class)
			->onlyMethods(['persistSave', 'handleFiles'])
			->getMock();

		$helper->expects($this->once())
			->method('persistSave')
			->with(
				['title' => 'New'],
				['1' => 'val'],
				'add',
				5,
				3,
				$bo,
				['stage' => 77]
			)
			->willReturn([
				'receipt' => ['id' => 10, 'message' => [], 'error' => []],
				'values' => ['id' => 10],
			]);

		$helper->expects($this->never())->method('handleFiles');

		$this->request->method('getParsedBody')->willReturn([
			'values' => ['title' => 'New'],
			'values_attribute' => ['1' => 'val'],
			'values_checklist_stage' => ['stage' => 77],
		]);

		$this->makeController($bo, $helper)->store($this->request, $this->response, $this->baseArgs());

		$decoded = json_decode($this->responseBody, true);
		$this->assertSame(10, $decoded['id']);
	}

	public function testStoreMergesFileHandlingErrorsIntoReceipt(): void
	{
		$bo = $this->createMock(\property_boentity::class);
		$bo->category_dir = 'entity';
		$bo->type = 'entity';
		$bo->type_app = ['entity' => 'property'];

		$helper = $this->getMockBuilder(EntityFormHelper::class)
			->onlyMethods(['persistSave', 'handleFiles'])
			->getMock();

		$helper->expects($this->once())
			->method('persistSave')
			->willReturn([
				'receipt' => ['id' => 10, 'message' => [], 'error' => []],
				'values' => ['id' => 10],
			]);

		$helper->expects($this->once())
			->method('handleFiles')
			->willReturnCallback(function (array $values, string $categoryDir, string $typeApp, array &$errors): void
			{
				$this->assertSame(['id' => 10], $values);
				$this->assertSame('entity', $categoryDir);
				$this->assertSame('property', $typeApp);
				$errors[] = ['msg' => 'Failed to upload file !'];
			});

		$this->request->method('getParsedBody')->willReturn([
			'values' => [
				'title' => 'New',
				'file_action' => [123],
			],
			'values_attribute' => [],
		]);

		$this->makeController($bo, $helper)->store($this->request, $this->response, $this->baseArgs());

		$decoded = json_decode($this->responseBody, true);
		$this->assertSame('Failed to upload file !', $decoded['error'][0]['msg']);
	}

	public function testStoreAbortsTransactionAndRethrowsWhenPersistSaveFails(): void
	{
		$bo = $this->createMock(\property_boentity::class);

		$helper = $this->getMockBuilder(EntityFormHelper::class)
			->onlyMethods(['persistSave', 'handleFiles'])
			->getMock();

		$helper->expects($this->once())
			->method('persistSave')
			->willThrowException(new \RuntimeException('boom-store'));

		$helper->expects($this->never())->method('handleFiles');

		$this->request->method('getParsedBody')->willReturn([
			'values' => ['title' => 'New'],
			'values_attribute' => [],
		]);

		$abortCalls = 0;
		$controller = $this->makeController(
			$bo,
			$helper,
			function () use (&$abortCalls): void
			{
				$abortCalls++;
			}
		);

		try
		{
			$controller->store($this->request, $this->response, $this->baseArgs());
			$this->fail('Expected RuntimeException was not thrown');
		}
		catch (\RuntimeException $e)
		{
			$this->assertSame('boom-store', $e->getMessage());
		}

		$this->assertSame(1, $abortCalls);
	}

	// ── update() ─────────────────────────────────────────────────────────────

	public function testUpdateCallsBoSaveWithEditModeAndInjectsId(): void
	{
		$receipt = ['id' => 7, 'message' => [], 'error' => []];

		$bo = $this->createMock(\property_boentity::class);
		$bo->expects($this->once())
			->method('save')
			->with(
				$this->callback(fn($v) => $v['id'] === 7),
				[],
				'edit',
				5,
				3
			)
			->willReturn($receipt);

		$this->request->method('getParsedBody')->willReturn(['values' => ['title' => 'Updated']]);

		$args = array_merge($this->baseArgs(), ['id' => '7']);
		$this->makeController($bo)->update($this->request, $this->response, $args);

		$decoded = json_decode($this->responseBody, true);
		$this->assertSame($receipt, $decoded);
	}

	public function testUpdateForwardsChecklistPayloadToSharedHelper(): void
	{
		$bo = $this->createMock(\property_boentity::class);
		$bo->category_dir = 'entity';
		$bo->type = 'entity';
		$bo->type_app = ['entity' => 'property'];

		$helper = $this->getMockBuilder(EntityFormHelper::class)
			->onlyMethods(['persistSave', 'handleFiles'])
			->getMock();

		$helper->expects($this->once())
			->method('persistSave')
			->with(
				['title' => 'Updated', 'id' => 7],
				['1' => 'val'],
				'edit',
				5,
				3,
				$bo,
				['stage' => 88]
			)
			->willReturn([
				'receipt' => ['id' => 7, 'message' => [], 'error' => []],
				'values' => ['id' => 7],
			]);

		$helper->expects($this->never())->method('handleFiles');

		$this->request->method('getParsedBody')->willReturn([
			'values' => ['title' => 'Updated'],
			'values_attribute' => ['1' => 'val'],
			'values_checklist_stage' => ['stage' => 88],
		]);

		$args = array_merge($this->baseArgs(), ['id' => '7']);
		$this->makeController($bo, $helper)->update($this->request, $this->response, $args);

		$decoded = json_decode($this->responseBody, true);
		$this->assertSame(7, $decoded['id']);
	}

	public function testUpdateMergesFileHandlingErrorsIntoReceipt(): void
	{
		$bo = $this->createMock(\property_boentity::class);
		$bo->category_dir = 'entity';
		$bo->type = 'entity';
		$bo->type_app = ['entity' => 'property'];

		$helper = $this->getMockBuilder(EntityFormHelper::class)
			->onlyMethods(['persistSave', 'handleFiles'])
			->getMock();

		$helper->expects($this->once())
			->method('persistSave')
			->willReturn([
				'receipt' => ['id' => 7, 'message' => [], 'error' => []],
				'values' => ['id' => 7],
			]);

		$helper->expects($this->once())
			->method('handleFiles')
			->willReturnCallback(function (array $values, string $categoryDir, string $typeApp, array &$errors): void
			{
				$this->assertSame(['id' => 7], $values);
				$this->assertSame('entity', $categoryDir);
				$this->assertSame('property', $typeApp);
				$errors[] = ['msg' => 'Failed to upload file !'];
			});

		$this->request->method('getParsedBody')->willReturn([
			'values' => [
				'title' => 'Updated',
				'file_action' => [456],
			],
			'values_attribute' => [],
		]);

		$args = array_merge($this->baseArgs(), ['id' => '7']);
		$this->makeController($bo, $helper)->update($this->request, $this->response, $args);

		$decoded = json_decode($this->responseBody, true);
		$this->assertSame('Failed to upload file !', $decoded['error'][0]['msg']);
	}

	public function testUpdateThrowsBadRequestForZeroId(): void
	{
		$this->expectException(HttpBadRequestException::class);

		$bo = $this->createMock(\property_boentity::class);
		$args = array_merge($this->baseArgs(), ['id' => '0']);
		$this->makeController($bo)->update($this->request, $this->response, $args);
	}

	public function testUpdateAbortsTransactionAndRethrowsWhenPersistSaveFails(): void
	{
		$bo = $this->createMock(\property_boentity::class);

		$helper = $this->getMockBuilder(EntityFormHelper::class)
			->onlyMethods(['persistSave', 'handleFiles'])
			->getMock();

		$helper->expects($this->once())
			->method('persistSave')
			->willThrowException(new \RuntimeException('boom-update'));

		$helper->expects($this->never())->method('handleFiles');

		$this->request->method('getParsedBody')->willReturn([
			'values' => ['title' => 'Updated'],
			'values_attribute' => [],
		]);

		$abortCalls = 0;
		$controller = $this->makeController(
			$bo,
			$helper,
			function () use (&$abortCalls): void
			{
				$abortCalls++;
			}
		);

		$args = array_merge($this->baseArgs(), ['id' => '7']);

		try
		{
			$controller->update($this->request, $this->response, $args);
			$this->fail('Expected RuntimeException was not thrown');
		}
		catch (\RuntimeException $e)
		{
			$this->assertSame('boom-update', $e->getMessage());
		}

		$this->assertSame(1, $abortCalls);
	}

	// ── destroy() ────────────────────────────────────────────────────────────

	public function testDestroyCallsBoDeleteAndReturns204(): void
	{
		$bo = $this->createMock(\property_boentity::class);
		$bo->expects($this->once())->method('delete')->with(4);

		$this->response->expects($this->atLeastOnce())
			->method('withStatus')
			->with(204)
			->willReturn($this->response);

		$args = array_merge($this->baseArgs(), ['id' => '4']);
		$this->makeController($bo)->destroy($this->request, $this->response, $args);
	}

	public function testDestroyThrowsBadRequestForZeroId(): void
	{
		$this->expectException(HttpBadRequestException::class);

		$bo = $this->createMock(\property_boentity::class);
		$args = array_merge($this->baseArgs(), ['id' => '0']);
		$this->makeController($bo)->destroy($this->request, $this->response, $args);
	}

	// ── getItemsPerQr() ──────────────────────────────────────────────────────

	public function testGetItemsPerQrCallsBoWithQrCode(): void
	{
		$items = [['id' => 1, 'qr_code' => 'ABC123']];

		$bo = $this->createMock(\property_boentity::class);
		$bo->expects($this->once())
			->method('get_items_per_qr')
			->with('ABC123')
			->willReturn($items);

		$this->request->method('getQueryParams')->willReturn(['qr_code' => 'ABC123']);

		$this->makeController($bo)->getItemsPerQr($this->request, $this->response, $this->baseArgs());

		$this->assertSame($items, json_decode($this->responseBody, true));
	}

	// ── getFiles() ───────────────────────────────────────────────────────────

	public function testGetFilesReturnsFilesSubarrayFromReadSingle(): void
	{
		$files = [['file_id' => 1, 'name' => 'photo.jpg', 'mime_type' => 'image/jpeg']];

		$bo = $this->createMock(\property_boentity::class);
		$bo->method('read_single')->willReturn(['id' => 3, 'files' => $files, 'location_data' => []]);

		$this->request->method('getQueryParams')->willReturn(['draw' => '1']);

		$args = array_merge($this->baseArgs(), ['id' => '3']);
		$this->makeController($bo)->getFiles($this->request, $this->response, $args);

		$decoded = json_decode($this->responseBody, true);
		$this->assertSame('photo.jpg',  $decoded['data'][0]['file_name']);
		$this->assertSame(1,            $decoded['data'][0]['img_id']);
		$this->assertArrayHasKey('file_link', $decoded['data'][0]);
		$this->assertArrayHasKey('delete_file', $decoded['data'][0]);
		$this->assertSame(1,            $decoded['recordsTotal']);
		$this->assertSame(1,            $decoded['recordsFiltered']);
		$this->assertSame(1,            $decoded['draw']);
	}

	// ── getRelated() ─────────────────────────────────────────────────────────

	public function testGetRelatedReturnsFlattenedLinks(): void
	{
		$raw = [
			'related_type_a' => [
				['name' => 'ItemX', 'entity_link' => '/index.php?menuaction=...'],
			],
		];

		$bo = $this->createMock(\property_boentity::class);
		$bo->method('read_entity_to_link')->willReturn($raw);

		$this->request->method('getQueryParams')->willReturn(['draw' => '2']);

		$args = array_merge($this->baseArgs(), ['id' => '1']);
		$this->makeController($bo)->getRelated($this->request, $this->response, $args);

		$decoded = json_decode($this->responseBody, true);
		$this->assertCount(1, $decoded['data']);
		$this->assertSame('ItemX', $decoded['data'][0]['name']);
		$this->assertSame(2, $decoded['draw']);
	}
}

} // end namespace Tests\Controllers

