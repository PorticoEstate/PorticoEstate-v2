<?php

namespace App\modules\email\services;

final class EmailAccountDirectory
{
	public static function hasEnabledAccount(array $accounts, int $accountNumber): bool
	{
		foreach ($accounts as $account)
		{
			if (($account['status'] ?? '') === 'enabled'
				&& array_key_exists('acctnum', $account)
				&& (int) $account['acctnum'] === $accountNumber)
			{
				return true;
			}
		}
		return false;
	}

	public static function serialize(array $accounts, array $stored): array
	{
		$result = [];
		foreach ($accounts as $account)
		{
			$id = (int) ($account['acctnum'] ?? 0);
			$status = (string) ($account['status'] ?? '');
			if ($id < 1 || $status === '' || $status === 'empty')
            {
            	continue;
            }
			$preferences = (array) ($stored[$id] ?? []);
			$name = $status === 'disabled'
				? trim((string) ($preferences['account_name'] ?? $preferences['fullname'] ?? ''))
				: trim((string) ($preferences['account_name'] ?? $preferences['fullname'] ?? ''));
			if ($name === '')
            {
            	$name = 'Account ' . $id;
            }
			if ($status === 'disabled')
            {
            	$name = '(disabled) ' . $name;
            }
			$result[] = ['id' => $id, 'name' => '[' . $id . '] ' . $name, 'status' => $status];
		}
		return $result;
	}

	public static function nextAccountNumber(array $accounts): int
	{
		foreach ($accounts as $index => $account)
		{
			$id = (int) ($account['acctnum'] ?? 0);
			if (($account['status'] ?? '') === 'empty')
            {
            	return $id > 0 ? $id : $index + 1;
            }
			if ($id !== $index + 1)
            {
            	return $index + 1;
            }
		}
		return count($accounts) + 1;
	}
}