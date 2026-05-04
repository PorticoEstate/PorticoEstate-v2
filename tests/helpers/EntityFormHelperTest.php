<?php

namespace
{
	require_once __DIR__ . '/../../vendor/autoload.php';

	if (!function_exists('lang'))
	{
		function lang(string $text, ...$args): string
		{
			if (!$args)
			{
				return $text;
			}

			// phpGroupWare uses %1, %2 positional placeholders
			foreach ($args as $i => $arg)
			{
				$text = str_replace('%' . ($i + 1), (string)$arg, $text);
			}
			return $text;
		}
	}

	if (!class_exists('phpgw'))
	{
		class phpgw
		{
			public static function is_repost(bool $display_error = false): bool
			{
				return false;
			}
		}
	}
}

namespace Tests\Helpers
{

use App\modules\property\helpers\EntityFormHelper;
use PHPUnit\Framework\TestCase;

class EntityFormHelperTest extends TestCase
{
	private EntityFormHelper $helper;

	protected function setUp(): void
	{
		$this->helper = new EntityFormHelper();
	}

	public function testBuildSaveResponseReturnsJsonErrorPayload(): void
	{
		$receipt = ['error' => [['msg' => 'Validation failed']], 'message' => []];

		$response = $this->helper->buildSaveResponse(true, $receipt, ['title' => 'Draft'], 'error');

		$this->assertSame('json', $response['type']);
		$this->assertSame('error', $response['payload']['status']);
		$this->assertSame($receipt, $response['payload']['receipt']);
		$this->assertSame([], $response['values']);
	}

	public function testBuildSaveResponseReturnsJsonSavedPayload(): void
	{
		$receipt = ['id' => 42, 'error' => [], 'message' => []];

		$response = $this->helper->buildSaveResponse(true, $receipt, ['title' => 'Saved'], 'success', 42, 5, 3, 'entity');

		$this->assertSame('json', $response['type']);
		$this->assertSame('saved', $response['payload']['status']);
		$this->assertSame(42, $response['payload']['id']);
		$this->assertSame($receipt, $response['payload']['receipt']);
	}

	public function testBuildSaveResponseReturnsEditForNonJsonError(): void
	{
		$values = ['title' => 'Draft'];
		$receipt = ['error' => [['msg' => 'Boom']], 'message' => []];

		$response = $this->helper->buildSaveResponse(false, $receipt, $values, 'error');

		$this->assertSame('edit', $response['type']);
		$this->assertSame([], $response['payload']);
		$this->assertSame($values, $response['values']);
	}

	public function testBuildSaveResponseReturnsRedirectEditForApplyOnPersistedRecord(): void
	{
		$receipt = ['id' => 77, 'error' => [], 'message' => []];
		$values = ['apply' => 1];

		$response = $this->helper->buildSaveResponse(false, $receipt, $values, 'success', 0, 5, 3, 'entity');

		$this->assertSame('redirect-edit', $response['type']);
		$this->assertSame([
			'id' => 77,
			'entity_id' => 5,
			'cat_id' => 3,
			'type' => 'entity',
		], $response['payload']);
	}

	public function testBuildSaveResponseReturnsEditForApplyWithoutResolvedId(): void
	{
		$receipt = ['id' => 0, 'error' => [], 'message' => []];
		$values = ['apply' => 1, 'title' => 'Unsaved'];

		$response = $this->helper->buildSaveResponse(false, $receipt, $values, 'success', 0, 5, 3, 'entity');

		$this->assertSame('edit', $response['type']);
		$this->assertSame($values, $response['values']);
	}

	public function testBuildSaveResponseReturnsRedirectIndexForNonApplySuccess(): void
	{
		$receipt = ['id' => 11, 'error' => [], 'message' => []];

		$response = $this->helper->buildSaveResponse(false, $receipt, ['title' => 'Saved'], 'success', 11, 5, 3, 'entity');

		$this->assertSame('redirect-index', $response['type']);
		$this->assertSame([
			'entity_id' => 5,
			'cat_id' => 3,
			'type' => 'entity',
		], $response['payload']);
		$this->assertSame([], $response['values']);
	}

	// ── validate() ───────────────────────────────────────────────────────────

	private function makeSoadmin(array $category = []): object
	{
		$defaults = ['org_unit' => 0, 'location_level' => 0];
		$cat = array_merge($defaults, $category);
		return new class($cat)
		{
			private array $cat;
			public function __construct(array $cat) { $this->cat = $cat; }
			public function read_single_category(int $entityId, int $catId): array { return $this->cat; }
		};
	}

	private function makeBoStub(): object
	{
		return new class
		{
			public function get_attribute_information(array &$attrs): void {}
		};
	}

	public function testValidateCatIdZeroReturnsImmediateError(): void
	{
		// Any soadmin/bo — should not be called when catId=0
		$soadmin = $this->makeSoadmin();
		$bo = $this->makeBoStub();

		$result = $this->helper->validate([], null, 0, 5, $soadmin, $bo);

		$this->assertCount(1, $result['errors']);
		$this->assertStringContainsString('entity type', $result['errors'][0]['msg']);
	}

	public function testValidateMissingLocationReturnsError(): void
	{
		$soadmin = $this->makeSoadmin(['location_level' => 1]);
		$bo = $this->makeBoStub();

		$values = ['location' => null, 'p' => null];
		$result = $this->helper->validate($values, null, 3, 5, $soadmin, $bo);

		$msgs = array_column($result['errors'], 'msg');
		$this->assertNotEmpty(array_filter($msgs, fn($m) => str_contains($m, 'location')));
	}

	public function testValidateNonNullableAttributeWithEmptyValueReturnsError(): void
	{
		$soadmin = $this->makeSoadmin();
		$bo = $this->makeBoStub();

		$attrs = [[
			'name'        => 'my_field',
			'input_text'  => 'My Field',
			'datatype'    => 'V',
			'nullable'    => 0,
			'value'       => '',
		]];

		$result = $this->helper->validate([], $attrs, 3, 5, $soadmin, $bo);

		$msgs = array_column($result['errors'], 'msg');
		$this->assertNotEmpty(array_filter($msgs, fn($m) => str_contains($m, 'My Field')));
	}

	public function testValidateNullableAttributeWithEmptyValuePassesValidation(): void
	{
		$soadmin = $this->makeSoadmin();
		$bo = $this->makeBoStub();

		$attrs = [[
			'name'       => 'opt_field',
			'input_text' => 'Optional',
			'datatype'   => 'V',
			'nullable'   => 1,
			'value'      => '',
		]];

		$result = $this->helper->validate([], $attrs, 3, 5, $soadmin, $bo);

		$this->assertEmpty($result['errors']);
	}

	public function testValidateIntegerAttributeWithNonNumericValueReturnsError(): void
	{
		$soadmin = $this->makeSoadmin();
		$bo = $this->makeBoStub();

		$attrs = [[
			'name'       => 'count_field',
			'input_text' => 'Count',
			'datatype'   => 'I',
			'nullable'   => 1,
			'value'      => 'abc',
		]];

		$result = $this->helper->validate([], $attrs, 3, 5, $soadmin, $bo);

		$msgs = array_column($result['errors'], 'msg');
		$this->assertNotEmpty(array_filter($msgs, fn($m) => str_contains($m, 'integer')));
	}

	public function testValidateIntegerAttributeWithNumericValuePassesValidation(): void
	{
		$soadmin = $this->makeSoadmin();
		$bo = $this->makeBoStub();

		$attrs = [[
			'name'       => 'count_field',
			'input_text' => 'Count',
			'datatype'   => 'I',
			'nullable'   => 1,
			'value'      => '42',
		]];

		$result = $this->helper->validate([], $attrs, 3, 5, $soadmin, $bo);

		$this->assertEmpty($result['errors']);
	}
}
}
