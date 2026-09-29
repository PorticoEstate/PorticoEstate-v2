<?php

namespace App\modules\phpgwapi\services;

use App\Database\Db;
use App\modules\phpgwapi\controllers\Accounts\Accounts;
use App\modules\phpgwapi\controllers\Accounts\phpgwapi_user;
use App\modules\phpgwapi\controllers\Accounts\phpgwapi_group;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class ScimProvisioningRepository implements ScimProvisioningStore
{
	private object $db;
	private string $tenantId;
	private ?object $accounts;

	public function __construct(?object $db = null, ?string $tenantId = null, ?object $accounts = null)
	{
		$this->db = $db ?? Db::getInstance();
		$this->accounts = $accounts;
		$configuredTenant = $tenantId ?? getenv('SCIM_TENANT_ID');
		if (!is_string($configuredTenant) || trim($configuredTenant) === '')
		{
			throw new InvalidArgumentException('SCIM_TENANT_ID is not configured');
		}

		$this->tenantId = trim($configuredTenant);
	}

	public function findById(int $accountId, string $accountType): ?array
	{
		$this->assertAccountType($accountType);
		$sql = $this->baseSelect()
			. ' WHERE a.account_id = :account_id'
			. ' AND a.account_type = :account_type'
			. " AND m.auth_type = 'scim' AND m.location = :tenant_id AND m.status = 'A'";

		return $this->fetchOne($sql, [
			':account_id' => $accountId,
			':account_type' => $accountType,
			':tenant_id' => $this->tenantId,
		]);
	}

	public function findByExternalId(string $externalId, string $accountType): ?array
	{
		$this->assertAccountType($accountType);
		$sql = $this->baseSelect()
			. ' WHERE m.ext_user = :external_id'
			. ' AND a.account_type = :account_type'
			. " AND m.auth_type = 'scim' AND m.location = :tenant_id AND m.status = 'A'";

		return $this->fetchOne($sql, [
			':external_id' => $externalId,
			':account_type' => $accountType,
			':tenant_id' => $this->tenantId,
		]);
	}

	public function list(string $accountType, int $offset, int $limit, ?array $filter = null): array
	{
		$this->assertAccountType($accountType);
		$where = [
			'a.account_type = :account_type',
			"m.auth_type = 'scim'",
			'm.location = :tenant_id',
			"m.status = 'A'",
		];
		$params = [
			':account_type' => $accountType,
			':tenant_id' => $this->tenantId,
		];

		if ($filter !== null)
		{
			$columns = [
				'userName' => 'a.account_lid',
				'displayName' => 'a.account_lid',
				'externalId' => 'm.ext_user',
			];
			$attribute = $filter['attribute'] ?? '';
			if (!isset($columns[$attribute]))
			{
				throw new InvalidArgumentException('Unsupported repository filter');
			}
			$where[] = $columns[$attribute] . ' = :filter_value';
			$params[':filter_value'] = (string) ($filter['value'] ?? '');
		}

		$whereSql = ' WHERE ' . implode(' AND ', $where);
		$countStatement = $this->db->prepare(
			'SELECT COUNT(*) FROM phpgw_mapping m JOIN phpgw_accounts a ON a.account_id = m.account_id' . $whereSql
		);
		$countStatement->execute($params);
		$total = (int) $countStatement->fetchColumn();

		$listParams = $params;
		$listParams[':limit'] = max(0, $limit);
		$listParams[':offset'] = max(0, $offset);
		$statement = $this->db->prepare(
			$this->baseSelect() . $whereSql . ' ORDER BY a.account_id ASC LIMIT :limit OFFSET :offset'
		);
		foreach ($listParams as $name => $value)
		{
			$statement->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
		}
		$statement->execute();

		return [
			'total' => $total,
			'resources' => $statement->fetchAll(PDO::FETCH_ASSOC),
		];
	}

	public function createUser(array $data): array
	{
		$this->db->transaction_begin();
		try
		{
			$accounts = $this->accounts();
			$user = new phpgwapi_user();
			$user->init([
				'lid' => $data['userName'],
				'firstname' => $data['givenName'],
				'lastname' => $data['familyName'],
				'passwd_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
				'enabled' => $data['active'],
				'expires' => -1,
				'person_id' => 0,
				'quota' => -1,
			]);
			$accountId = (int) $accounts->create_user_account($user);

			$statement = $this->db->prepare(
				"INSERT INTO phpgw_mapping (ext_user, auth_type, status, location, account_lid, account_id)"
				. " VALUES (:external_id, 'scim', 'A', :tenant_id, :account_lid, :account_id)"
			);
			$statement->execute([
				':external_id' => $data['externalId'],
				':tenant_id' => $this->tenantId,
				':account_lid' => $data['userName'],
				':account_id' => $accountId,
			]);
			$this->storeEmail($accountId, $data['email']);
			$this->db->transaction_commit();

			return $this->findById($accountId, 'u')
				?? throw new RuntimeException('Created SCIM user could not be read');
		}
		catch (Throwable $exception)
		{
			$this->db->transaction_abort();
			throw $exception;
		}
	}

	public function updateUser(int $accountId, array $data): ?array
	{
		$current = $this->findById($accountId, 'u');
		if ($current === null)
		{
			return null;
		}

		$this->db->transaction_begin();
		try
		{
			$statement = $this->db->prepare(
				'UPDATE phpgw_accounts SET account_lid = :account_lid,'
				. ' account_firstname = :firstname, account_lastname = :lastname,'
				. ' account_status = :status WHERE account_id = :account_id AND account_type = :account_type'
			);
			$statement->execute([
				':account_lid' => $data['userName'],
				':firstname' => $data['givenName'],
				':lastname' => $data['familyName'],
				':status' => $data['active'] ? 'A' : 'I',
				':account_id' => $accountId,
				':account_type' => 'u',
			]);

			if ($current['account_lid'] !== $data['userName'])
			{
				$mappingStatement = $this->db->prepare(
					"UPDATE phpgw_mapping SET account_lid = :new_lid"
					. " WHERE account_id = :account_id AND location = :tenant_id AND auth_type = 'scim'"
				);
				$mappingStatement->execute([
					':new_lid' => $data['userName'],
					':account_id' => $accountId,
					':tenant_id' => $this->tenantId,
				]);
			}

			$this->storeEmail($accountId, $data['email']);
			$this->db->transaction_commit();

			return $this->findById($accountId, 'u');
		}
		catch (Throwable $exception)
		{
			$this->db->transaction_abort();
			throw $exception;
		}
	}

	public function deactivate(int $accountId, string $accountType): bool
	{
		$this->assertAccountType($accountType);
		$statement = $this->db->prepare(
			"UPDATE phpgw_accounts SET account_status = 'I'"
			. ' WHERE account_id = :account_id AND account_type = :account_type'
			. ' AND EXISTS (SELECT 1 FROM phpgw_mapping m WHERE m.account_id = phpgw_accounts.account_id'
			. " AND m.location = :tenant_id AND m.auth_type = 'scim')"
		);
		$statement->execute([
			':account_id' => $accountId,
			':account_type' => $accountType,
			':tenant_id' => $this->tenantId,
		]);

		return $statement->rowCount() > 0;
	}

	public function createGroup(array $data): array
	{
		$this->db->transaction_begin();
		try
		{
			$group = new phpgwapi_group();
			$group->init([
				'lid' => $data['displayName'],
				'firstname' => $data['displayName'],
				'passwd_hash' => '',
				'enabled' => true,
				'expires' => -1,
				'person_id' => 0,
				'quota' => -1,
			]);
			$accountId = (int) $this->accounts()->create_group_account($group);

			$statement = $this->db->prepare(
				"INSERT INTO phpgw_mapping (ext_user, auth_type, status, location, account_lid, account_id)"
				. " VALUES (:external_id, 'scim', 'A', :tenant_id, :account_lid, :account_id)"
			);
			$statement->execute([
				':external_id' => $data['externalId'],
				':tenant_id' => $this->tenantId,
				':account_lid' => $data['displayName'],
				':account_id' => $accountId,
			]);
			$this->db->transaction_commit();

			return $this->findById($accountId, 'g')
				?? throw new RuntimeException('Created SCIM group could not be read');
		}
		catch (Throwable $exception)
		{
			$this->db->transaction_abort();
			throw $exception;
		}
	}

	public function updateGroup(int $accountId, string $displayName): ?array
	{
		$current = $this->findById($accountId, 'g');
		if ($current === null)
		{
			return null;
		}

		$this->db->transaction_begin();
		try
		{
			$statement = $this->db->prepare(
				'UPDATE phpgw_accounts SET account_lid = :account_lid, account_firstname = :account_lid'
				. ' WHERE account_id = :account_id AND account_type = :account_type'
			);
			$statement->execute([
				':account_lid' => $displayName,
				':account_id' => $accountId,
				':account_type' => 'g',
			]);

			$mappingStatement = $this->db->prepare(
				"UPDATE phpgw_mapping SET account_lid = :new_lid"
				. " WHERE account_id = :account_id AND location = :tenant_id AND auth_type = 'scim'"
			);
			$mappingStatement->execute([
				':new_lid' => $displayName,
				':account_id' => $accountId,
				':tenant_id' => $this->tenantId,
			]);
			$this->db->transaction_commit();

			return $this->findById($accountId, 'g');
		}
		catch (Throwable $exception)
		{
			$this->db->transaction_abort();
			throw $exception;
		}
	}

	public function groupMembers(int $groupId): array
	{
		$statement = $this->db->prepare(
			'SELECT a.account_id, a.account_lid AS display'
			. ' FROM phpgw_group_map gm'
			. ' JOIN phpgw_accounts a ON a.account_id = gm.account_id'
			. ' JOIN phpgw_mapping m ON m.account_id = a.account_id'
			. ' WHERE gm.group_id = :group_id AND a.account_type = :account_type'
			. " AND m.location = :tenant_id AND m.auth_type = 'scim' AND m.status = 'A'"
			. ' ORDER BY a.account_id'
		);
		$statement->execute([
			':group_id' => $groupId,
			':account_type' => 'u',
			':tenant_id' => $this->tenantId,
		]);

		return array_map(
			fn(array $row): array => ['value' => (string) $row['account_id'], 'display' => $row['display']],
			$statement->fetchAll(PDO::FETCH_ASSOC)
		);
	}

	public function addGroupMember(int $groupId, int $accountId): bool
	{
		if ($this->findById($groupId, 'g') === null || $this->findById($accountId, 'u') === null)
		{
			return false;
		}

		$statement = $this->db->prepare(
			'INSERT INTO phpgw_group_map (group_id, account_id) VALUES (:group_id, :account_id)'
			. ' ON CONFLICT (group_id, account_id) DO NOTHING'
		);
		$statement->execute([':group_id' => $groupId, ':account_id' => $accountId]);

		return true;
	}

	public function removeGroupMember(int $groupId, int $accountId): bool
	{
		if ($this->findById($groupId, 'g') === null || $this->findById($accountId, 'u') === null)
		{
			return false;
		}

		$statement = $this->db->prepare(
			'DELETE FROM phpgw_group_map WHERE group_id = :group_id AND account_id = :account_id'
		);
		$statement->execute([':group_id' => $groupId, ':account_id' => $accountId]);

		return true;
	}

	public function patchGroup(int $groupId, string $displayName, array $addMembers, array $removeMembers): ?array
	{
		$current = $this->findById($groupId, 'g');
		if ($current === null)
		{
			return null;
		}
		foreach (array_unique(array_merge($addMembers, $removeMembers)) as $accountId)
		{
			if ($this->findById((int) $accountId, 'u') === null)
			{
				throw new InvalidArgumentException('Unknown SCIM group member');
			}
		}

		$this->db->transaction_begin();
		try
		{
			if ($displayName !== $current['account_lid'])
			{
				$accountStatement = $this->db->prepare(
					'UPDATE phpgw_accounts SET account_lid = :account_lid, account_firstname = :account_lid'
					. ' WHERE account_id = :account_id AND account_type = :account_type'
				);
				$accountStatement->execute([
					':account_lid' => $displayName,
					':account_id' => $groupId,
					':account_type' => 'g',
				]);
				$mappingStatement = $this->db->prepare(
					"UPDATE phpgw_mapping SET account_lid = :new_lid"
					. " WHERE account_id = :account_id AND location = :tenant_id AND auth_type = 'scim'"
				);
				$mappingStatement->execute([
					':new_lid' => $displayName,
					':account_id' => $groupId,
					':tenant_id' => $this->tenantId,
				]);
			}

			foreach ($addMembers as $accountId)
			{
				$statement = $this->db->prepare(
					'INSERT INTO phpgw_group_map (group_id, account_id) VALUES (:group_id, :account_id)'
					. ' ON CONFLICT (group_id, account_id) DO NOTHING'
				);
				$statement->execute([':group_id' => $groupId, ':account_id' => (int) $accountId]);
			}
			foreach ($removeMembers as $accountId)
			{
				$statement = $this->db->prepare(
					'DELETE FROM phpgw_group_map WHERE group_id = :group_id AND account_id = :account_id'
				);
				$statement->execute([':group_id' => $groupId, ':account_id' => (int) $accountId]);
			}
			$this->db->transaction_commit();

			return $this->findById($groupId, 'g');
		}
		catch (Throwable $exception)
		{
			$this->db->transaction_abort();
			throw $exception;
		}
	}

	private function baseSelect(): string
	{
		return "SELECT a.account_id, a.account_lid, a.account_firstname, a.account_lastname,"
			. " a.account_status, a.account_type, m.ext_user AS external_id,"
			. " d.account_data->>'scim_email' AS email"
			. ' FROM phpgw_mapping m JOIN phpgw_accounts a ON a.account_id = m.account_id'
			. ' LEFT JOIN phpgw_accounts_data d ON d.account_id = a.account_id';
	}

	private function storeEmail(int $accountId, string $email): void
	{
		$statement = $this->db->prepare(
			'INSERT INTO phpgw_accounts_data (account_id, account_data)'
			. " VALUES (:account_id, jsonb_build_object('scim_email', :email))"
			. ' ON CONFLICT (account_id) DO UPDATE SET account_data ='
			. " COALESCE(phpgw_accounts_data.account_data, '{}'::jsonb)"
			. " || jsonb_build_object('scim_email', EXCLUDED.account_data->>'scim_email')"
		);
		$statement->execute([':account_id' => $accountId, ':email' => $email]);
	}

	private function accounts(): object
	{
		if ($this->accounts === null)
		{
			$this->accounts = new Accounts();
		}

		return $this->accounts;
	}

	private function fetchOne(string $sql, array $params): ?array
	{
		$statement = $this->db->prepare($sql);
		$statement->execute($params);
		$row = $statement->fetch(PDO::FETCH_ASSOC);

		return $row === false ? null : $row;
	}

	private function assertAccountType(string $accountType): void
	{
		if (!in_array($accountType, ['u', 'g'], true))
		{
			throw new InvalidArgumentException('Unsupported account type');
		}
	}
}