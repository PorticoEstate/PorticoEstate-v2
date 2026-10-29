<?php

namespace Tests\Services;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/modules/phpgwapi/controllers/Accounts/AccountTypes.php';

use App\modules\phpgwapi\services\ScimProvisioningRepository;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ScimProvisioningRepositoryTest extends TestCase
{
	public function testMatchingGroupNameCreatesMappingWithoutCreatingAccount(): void
	{
		$row = ['account_id' => 7, 'account_lid' => 'Existing Staff', 'account_type' => 'g', 'external_id' => 'entra-7'];
		$db = new ScimRepositoryFakeDatabase([], [
			[$row], [], [], [$row],
		]);
		$repository = new ScimProvisioningRepository($db, 'tenant-1', new \stdClass());

		$this->assertSame($row, $repository->createGroup(['displayName' => 'Existing Staff', 'externalId' => 'entra-7']));
		$this->assertStringContainsString('FOR UPDATE', $db->statements[0]->sql);
		$this->assertSame([':account_lid' => 'Existing Staff', ':account_type' => 'g'], $db->statements[0]->executeParams);
		$this->assertStringContainsString('INSERT INTO phpgw_mapping', $db->statements[2]->sql);
		$this->assertSame(7, $db->statements[2]->executeParams[':account_id']);
		$this->assertSame('entra-7', $db->statements[2]->executeParams[':external_id']);
		$this->assertSame('tenant-1', $db->statements[2]->executeParams[':tenant_id']);
		$this->assertSame(['begin', 'commit'], $db->transactions);
	}

	public function testMatchingGroupRejectsAnotherEntraIdentity(): void
	{
		$db = new ScimRepositoryFakeDatabase([], [
			[['account_id' => 7, 'account_lid' => 'Existing Staff']],
			[['ext_user' => 'other-entra-id', 'location' => 'tenant-1', 'status' => 'A']],
		]);
		$repository = new ScimProvisioningRepository($db, 'tenant-1', new \stdClass());

		$this->expectException(DomainException::class);
		try
		{
			$repository->createGroup(['displayName' => 'Existing Staff', 'externalId' => 'entra-7']);
		}
		finally
		{
			$this->assertCount(2, $db->statements);
			$this->assertSame(['begin', 'rollback'], $db->transactions);
		}
	}

	public function testMatchingGroupRetryKeepsExistingMapping(): void
	{
		$row = ['account_id' => 7, 'account_lid' => 'Existing Staff', 'account_type' => 'g', 'external_id' => 'entra-7'];
		$db = new ScimRepositoryFakeDatabase([], [
			[$row],
			[['ext_user' => 'entra-7', 'location' => 'tenant-1', 'status' => 'A']],
			[$row],
		]);
		$repository = new ScimProvisioningRepository($db, 'tenant-1', new \stdClass());

		$this->assertSame($row, $repository->createGroup(['displayName' => 'Existing Staff', 'externalId' => 'entra-7']));
		$this->assertCount(3, $db->statements);
		$this->assertSame(['begin', 'commit'], $db->transactions);
	}

	public function testMatchingGroupRejectsAnotherTenant(): void
	{
		$db = new ScimRepositoryFakeDatabase([], [
			[['account_id' => 7, 'account_lid' => 'Existing Staff']],
			[['ext_user' => 'entra-7', 'location' => 'tenant-2', 'status' => 'A']],
		]);
		$repository = new ScimProvisioningRepository($db, 'tenant-1', new \stdClass());

		$this->expectException(DomainException::class);
		$repository->createGroup(['displayName' => 'Existing Staff', 'externalId' => 'entra-7']);
	}

	public function testMatchingGroupRollsBackWhenMappedResourceCannotBeRead(): void
	{
		$db = new ScimRepositoryFakeDatabase([], [
			[['account_id' => 7, 'account_lid' => 'Existing Staff']], [], [], [],
		]);
		$repository = new ScimProvisioningRepository($db, 'tenant-1', new \stdClass());

		$this->expectException(\RuntimeException::class);
		try
		{
			$repository->createGroup(['displayName' => 'Existing Staff', 'externalId' => 'entra-7']);
		}
		finally
		{
			$this->assertSame(['begin', 'rollback'], $db->transactions);
		}
	}

	public function testNewGroupIsCreatedWhenNameDoesNotMatch(): void
	{
		$row = ['account_id' => 8, 'account_lid' => 'New Staff', 'account_type' => 'g', 'external_id' => 'entra-8'];
		$db = new ScimRepositoryFakeDatabase([], [[], [], [$row]]);
		$accounts = new class {
			public array $created = [];

			public function create_group_account(object $group): int
			{
				$this->created[] = $group->lid;
				return 8;
			}
		};
		$repository = new ScimProvisioningRepository($db, 'tenant-1', $accounts);

		$this->assertSame($row, $repository->createGroup(['displayName' => 'New Staff', 'externalId' => 'entra-8']));
		$this->assertSame(['New Staff'], $accounts->created);
		$this->assertSame(8, $db->statements[1]->executeParams[':account_id']);
		$this->assertSame(['begin', 'commit'], $db->transactions);
	}

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
	public array $transactions = [];
	private array $rows;
	private array $resultSets;

	public function __construct(array $rows, array $resultSets = [])
	{
		$this->rows = $rows;
		$this->resultSets = $resultSets;
	}

	public function prepare(string $sql): ScimRepositoryFakeStatement
	{
		$rows = $this->resultSets === [] ? $this->rows : array_shift($this->resultSets);
		$statement = new ScimRepositoryFakeStatement($sql, $rows);
		$this->statements[] = $statement;

		return $statement;
	}

	public function transaction_begin(): void
	{
		$this->transactions[] = 'begin';
	}

	public function transaction_commit(): void
	{
		$this->transactions[] = 'commit';
	}

	public function transaction_abort(): void
	{
		$this->transactions[] = 'rollback';
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