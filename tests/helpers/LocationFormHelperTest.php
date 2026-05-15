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

	if (!function_exists('CreateObject'))
	{
		function CreateObject(string $name, ...$args)
		{
			if ($name === 'property.bolocation')
			{
				return $GLOBALS['__location_bo_test_double'] ?? null;
			}

			return null;
		}
	}

	if (!class_exists('FakeSoadminLocationForLocationHelperTest'))
	{
		class FakeSoadminLocationForLocationHelperTest
		{
			public function __construct(private array $config, private array $types)
			{
			}

			public function read_config($data = 0): array
			{
				return $this->config;
			}

			public function select_location_type(): array
			{
				return $this->types;
			}
		}
	}

	if (!class_exists('property_bolocation'))
	{
		class property_bolocation
		{
			public FakeSoadminLocationForLocationHelperTest $soadmin_location;
			public bool $checkLocationResult = false;
			public array $saveReceipt = [];
			public array $readSingleResult = [];
			public array $lastSaveValues = [];

			public function __construct(array $config = [], array $types = [])
			{
				$this->soadmin_location = new FakeSoadminLocationForLocationHelperTest($config, $types);
			}

			public function save($values, $valuesAttribute, $action = '', $typeId = '', $locationCodeParent = ''): array
			{
				$this->lastSaveValues = (array) $values;
				return $this->saveReceipt;
			}

			public function read_single($data = '', $extra = []): array
			{
				return $this->readSingleResult;
			}

			public function check_location($locationCode, $typeId = 0): bool
			{
				return $this->checkLocationResult;
			}
		}
	}
}

namespace Tests\Helpers
{

	use App\modules\property\helpers\LocationFormHelper;
	use PHPUnit\Framework\TestCase;

	class LocationFormHelperTest extends TestCase
	{
		private LocationFormHelper $helper;

		protected function setUp(): void
		{
			$this->helper = new LocationFormHelper();
		}

		public function testMapInputUsesDynamicConfiguredFields(): void
		{
			$GLOBALS['__location_bo_test_double'] = new \property_bolocation(
				[
					['column_name' => 'owner_id', 'location_type' => 2, 'lookup_form' => 1],
					['column_name' => 'ignored_field', 'location_type' => 2, 'lookup_form' => 0],
					['column_name' => 'district_id', 'location_type' => 3, 'lookup_form' => 1],
				],
				[
					['id' => 1],
					['id' => 2],
					['id' => 3],
				]
			);

			$result = $this->helper->mapInput([
				'type_id' => 2,
				'loc1' => 'A',
				'loc2' => 'B',
				'owner_id' => '7',
				'ignored_field' => 'X',
				'district_id' => '9',
				'cat_id' => '11',
			], null);

			$this->assertSame(2, $result['type_id']);
			$this->assertSame('A-B', $result['location_code']);
			$this->assertEquals(7, $result['values']['owner_id']);
			$this->assertArrayNotHasKey('ignored_field', $result['values']);
			$this->assertArrayNotHasKey('district_id', $result['values']);
		}

		public function testApplyLegacyRulesRequiresChangeTypeOnEdit(): void
		{
			$GLOBALS['__location_bo_test_double'] = new \property_bolocation();

			$state = $this->helper->applyLegacyRules([
				'values' => [
					'loc1' => 'A',
					'cat_id' => 1,
					'change_type' => 0,
				],
				'values_attribute' => [],
				'type_id' => 1,
				'location_code' => 'A',
				'errors' => [],
			], ['extra' => []], true);

			$this->assertTrue($state['values']['error_id']);
			$this->assertNotEmpty(array_filter(
				$state['errors'],
				static fn($msg) => str_contains((string) $msg, 'change type')
			));
		}

		public function testApplyLegacyRulesDetectsDuplicateOnAdd(): void
		{
			$bo = new \property_bolocation();
			$bo->checkLocationResult = true;
			$GLOBALS['__location_bo_test_double'] = $bo;

			$state = $this->helper->applyLegacyRules([
				'values' => [
					'loc1' => 'A',
					'cat_id' => 1,
				],
				'values_attribute' => [],
				'type_id' => 1,
				'location_code' => '',
				'errors' => [],
			], ['extra' => []], false);

			$this->assertTrue($state['values']['error_id']);
			$this->assertNotEmpty(array_filter(
				$state['errors'],
				static fn($msg) => str_contains((string) $msg, 'already registered')
			));
		}

		public function testPersistSaveReturnsSuccessWithLegacyReceipt(): void
		{
			$bo = new \property_bolocation();
			$bo->saveReceipt = [
				'location_code' => 'A',
				'error' => [],
				'message' => [],
			];
			$bo->readSingleResult = ['location_code' => 'A'];
			$GLOBALS['__location_bo_test_double'] = $bo;

			$state = $this->helper->persistSave([
				'values' => [
					'location_code' => 'A',
					'loc1' => 'A',
					'cat_id' => 1,
				],
				'values_attribute' => [],
				'errors' => [],
				'location_id' => 0,
				'location_code' => 'A',
				'type_id' => 1,
				'location_parent' => [],
				'is_edit' => false,
				'location_data' => null,
			]);

			$this->assertSame('success', $state['receipt']['status']);
			$this->assertSame('A', $state['receipt']['location_code']);
			$this->assertSame('A', $state['receipt']['receipt']['location_code']);
		}

		public function testPersistSaveStripsTransportKeysBeforeBoSave(): void
		{
			$bo = new \property_bolocation();
			$bo->saveReceipt = [
				'location_code' => '5804-01-01',
				'error' => [],
				'message' => [],
			];
			$bo->readSingleResult = ['location_code' => '5804-01-01'];
			$GLOBALS['__location_bo_test_double'] = $bo;

			$this->helper->persistSave([
				'values' => [
					'loc_code' => '5804-01-01',
					'location_code' => '5804-01-01',
					'loc1' => '5804',
					'loc2' => '01',
					'loc3' => '01',
					'type_id' => 3,
					'location_type' => 3,
					'cat_id' => 98,
					'change_type' => 2,
				],
				'values_attribute' => [],
				'errors' => [],
				'location_id' => 0,
				'location_code' => '5804-01-01',
				'type_id' => 3,
				'location_parent' => ['5804', '01'],
				'is_edit' => true,
				'location_data' => null,
			]);

			$this->assertArrayNotHasKey('type_id', $bo->lastSaveValues);
			$this->assertArrayNotHasKey('location_type', $bo->lastSaveValues);
			$this->assertArrayNotHasKey('loc_code', $bo->lastSaveValues);
			$this->assertSame('5804-01-01', $bo->lastSaveValues['location_code'] ?? null);
			$this->assertSame(98, $bo->lastSaveValues['cat_id'] ?? null);
			$this->assertSame(2, $bo->lastSaveValues['change_type'] ?? null);
		}
	}
}
