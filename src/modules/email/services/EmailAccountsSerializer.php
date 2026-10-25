<?php

namespace App\modules\email\services;

final class EmailAccountsSerializer
{
	public static function accountName(array $account): string
	{
		$name = trim((string) ($account['account_name'] ?? ''));
		if ($name === '')
		{
			$name = trim((string) ($account['fullname'] ?? ''));
		}
		return $name === '' ? 'Unknown account' : $name;
	}

	public static function serialize(array $accounts): array
	{
		$result = [];
		foreach ($accounts as $key => $account)
		{
			if (!is_array($account)) continue;
			$status = (string) ($account['status'] ?? 'empty');
			if ($status === 'empty') continue;
			$id = (int) ($account['acctnum'] ?? $key);
			$name = self::accountName($account);
			$name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
			if ($status === 'disabled') $name = '(disabled) ' . $name;
			$result[] = [
				'id' => $id,
				'name' => '[' . $id . '] ' . $name,
				'status' => $status,
			];
		}
		return $result;
	}
}