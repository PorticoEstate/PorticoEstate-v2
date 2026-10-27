<?php

/**
 * EMail - Account Directory
 *
 * @author Sigurd Nes <sigurdne at gmail.com>
 * @copyright Copyright (C) 2026 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @package email
 */

/**
 * Checks enabled-account membership, builds account summaries, and finds available extra-account numbers.
 */

namespace App\modules\email\services;

final class EmailAccountDirectory
{
	/**
	 * Check whether the requested account occurs in the enabled account summaries.
	 *
	 * @param array $accounts The stored account map or normalized account summaries required by this operation.
	 * @param int $accountNumber The mail account number to find among enabled accounts.
	 * @return bool Whether a matching enabled account exists.
	 */
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

	/**
	 * Combine account status summaries with saved names for account-list output.
	 *
	 * @param array $accounts The stored account map or normalized account summaries required by this operation.
	 * @param array $stored Saved extra-account preferences keyed by account number.
	 * @return array Labeled extra-account entries with identifiers and status.
	 */
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

	/**
	 * Find the next usable account number from ordered legacy account summaries.
	 *
	 * @param array $accounts The stored account map or normalized account summaries required by this operation.
	 * @return int The available extra-account number.
	 */
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