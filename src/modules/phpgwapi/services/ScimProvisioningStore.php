<?php

namespace App\modules\phpgwapi\services;

interface ScimProvisioningStore
{
	public function findById(int $accountId, string $accountType): ?array;

	public function findByExternalId(string $externalId, string $accountType): ?array;

	public function list(string $accountType, int $offset, int $limit, ?array $filter = null): array;

	public function createUser(array $data): array;

	public function updateUser(int $accountId, array $data): ?array;

	public function deactivate(int $accountId, string $accountType): bool;

	public function createGroup(array $data): array;

	public function updateGroup(int $accountId, string $displayName): ?array;

	public function groupMembers(int $groupId): array;

	public function addGroupMember(int $groupId, int $accountId): bool;

	public function removeGroupMember(int $groupId, int $accountId): bool;

	public function patchGroup(int $groupId, string $displayName, array $addMembers, array $removeMembers): ?array;
}