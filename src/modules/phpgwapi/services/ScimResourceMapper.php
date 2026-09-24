<?php

namespace App\modules\phpgwapi\services;

use InvalidArgumentException;

final class ScimResourceMapper
{
	public const USER_SCHEMA = 'urn:ietf:params:scim:schemas:core:2.0:User';
	public const GROUP_SCHEMA = 'urn:ietf:params:scim:schemas:core:2.0:Group';

	public function user(array $row, string $baseUrl): array
	{
		$this->requireType($row, 'u');
		$id = (string) $row['account_id'];
		$resource = [
			'schemas' => [self::USER_SCHEMA],
			'id' => $id,
			'externalId' => (string) $row['external_id'],
			'userName' => (string) $row['account_lid'],
			'active' => $row['account_status'] === 'A',
			'name' => [
				'givenName' => (string) $row['account_firstname'],
				'familyName' => (string) $row['account_lastname'],
			],
			'displayName' => trim($row['account_firstname'] . ' ' . $row['account_lastname']),
			'meta' => [
				'resourceType' => 'User',
				'location' => rtrim($baseUrl, '/') . '/Users/' . rawurlencode($id),
			],
		];

		if (!empty($row['email']))
		{
			$resource['emails'] = [[
				'value' => (string) $row['email'],
				'type' => 'work',
				'primary' => true,
			]];
		}

		return $resource;
	}

	public function group(array $row, string $baseUrl, array $members = []): array
	{
		$this->requireType($row, 'g');
		$id = (string) $row['account_id'];

		return [
			'schemas' => [self::GROUP_SCHEMA],
			'id' => $id,
			'externalId' => (string) $row['external_id'],
			'displayName' => (string) $row['account_lid'],
			'members' => $members,
			'meta' => [
				'resourceType' => 'Group',
				'location' => rtrim($baseUrl, '/') . '/Groups/' . rawurlencode($id),
			],
		];
	}

	private function requireType(array $row, string $type): void
	{
		if (($row['account_type'] ?? null) !== $type)
		{
			throw new InvalidArgumentException('Unexpected account type');
		}
	}
}