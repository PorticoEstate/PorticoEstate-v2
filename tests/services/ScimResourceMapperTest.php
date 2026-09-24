<?php

namespace Tests\Services;

require_once __DIR__ . '/../../vendor/autoload.php';

use App\modules\phpgwapi\services\ScimResourceMapper;
use PHPUnit\Framework\TestCase;

class ScimResourceMapperTest extends TestCase
{
	public function testMapsUserWithoutExposingPasswordData(): void
	{
		$resource = (new ScimResourceMapper())->user([
			'account_id' => 42,
			'account_lid' => 'ola.nordmann',
			'account_firstname' => 'Ola',
			'account_lastname' => 'Nordmann',
			'account_status' => 'A',
			'account_type' => 'u',
			'external_id' => '11111111-1111-1111-1111-111111111111',
			'account_pwd' => 'must-not-leak',
		], 'https://example.test/api/scim/v2');

		$this->assertSame('42', $resource['id']);
		$this->assertSame('ola.nordmann', $resource['userName']);
		$this->assertTrue($resource['active']);
		$this->assertArrayNotHasKey('account_pwd', $resource);
		$this->assertSame('https://example.test/api/scim/v2/Users/42', $resource['meta']['location']);
	}

	public function testMapsGroupMembers(): void
	{
		$resource = (new ScimResourceMapper())->group([
			'account_id' => 7,
			'account_lid' => 'SCIM Staff',
			'account_type' => 'g',
			'external_id' => '22222222-2222-2222-2222-222222222222',
		], 'https://example.test/api/scim/v2', [['value' => '42']]);

		$this->assertSame('7', $resource['id']);
		$this->assertSame([['value' => '42']], $resource['members']);
	}
}