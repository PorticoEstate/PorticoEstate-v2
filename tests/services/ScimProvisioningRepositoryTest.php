<?php

namespace Tests\Services;

require_once __DIR__ . '/../../vendor/autoload.php';

use App\modules\phpgwapi\services\ScimProvisioningRepository;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ScimProvisioningRepositoryTest extends TestCase
{
	public function testFindByExternalIdIsScopedToTenantAndType(): void
	{
		$row = [
			'account_id' => 42,
			'account_lid' => 'ola.nordmann',
			'account_type' => 'u',
			'external_id' => 'external-42',
		];
		$db = new ScimRepositoryFakeDatabase([$row]);
		$repository = new ScimProvisioningRepository($db, 'tenant-1');

		$this->assertSame($row, $repository->findByExternalId('external-42', 'u'));
		$this->assertStringContainsString("m.auth_type = 'scim'", $db->statements[0]->sql);
		$this->assertStringContainsString('a.account_id = m.account_id', $db->statements[0]->sql);
		$this->assertStringNotContainsString('a.account_lid = m.account_lid', $db->statements[0]->sql);
		$this->assertSame([
			':external_id' => 'external-42',
			':account_type' => 'u',
			':tenant_id' => 'tenant-1',
		], $db->statements[0]->executeParams);
	}

	public function testMissingTenantConfigurationIsRejected(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new ScimProvisioningRepository(new ScimRepositoryFakeDatabase([]), '');
	}

	public function testUnsupportedAccountTypeIsRejectedBeforeQuery(): void
	{
		$db = new ScimRepositoryFakeDatabase([]);
		$repository = new ScimProvisioningRepository($db, 'tenant-1');

		$this->expectException(InvalidArgumentException::class);
		try
		{
			$repository->findById(42, 'x');
		}
		finally
		{
			$this->assertCount(0, $db->statements);
		}
	}
}

final class ScimRepositoryFakeDatabase
{
	public array $statements = [];
	private array $rows;

	public function __construct(array $rows)
	{
		$this->rows = $rows;
	}

	public function prepare(string $sql): ScimRepositoryFakeStatement
	{
		$statement = new ScimRepositoryFakeStatement($sql, $this->rows);
		$this->statements[] = $statement;

		return $statement;
	}
}

final class ScimRepositoryFakeStatement
{
	public string $sql;
	public array $executeParams = [];
	private array $rows;

	public function __construct(string $sql, array $rows)
	{
		$this->sql = $sql;
		$this->rows = $rows;
	}

	public function execute(array $params = []): bool
	{
		$this->executeParams = $params;

		return true;
	}

	public function fetch(int $mode): array|false
	{
		return array_shift($this->rows) ?? false;
	}
}