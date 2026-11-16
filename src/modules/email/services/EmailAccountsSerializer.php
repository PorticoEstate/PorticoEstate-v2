<?php

/**
 * EMail - Account Serialization
 *
 * @author Sigurd Nes <sigurdne at gmail.com>
 * @copyright Copyright (C) 2026 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @package email
 */

/**
 * Converts stored or normalized account settings into labeled account entries with enabled status.
 */

namespace App\modules\email\services;

final class EmailAccountsSerializer
{
	/**
	 * Resolve an account label from its explicit name or full name.
	 *
	 * @param array $account The account preference values used to construct its label.
	 * @return string The resolved label, or the unknown-account fallback.
	 */
	public static function accountName(array $account): string
	{
		$name = trim((string) ($account['account_name'] ?? ''));
		if ($name === '')
		{
			$name = trim((string) ($account['fullname'] ?? ''));
		}
		return $name === '' ? 'Unknown account' : $name;
	}

	/**
	 * Normalize saved or summarized accounts into API account entries.
	 *
	 * @param array $accounts The stored account map or normalized account summaries required by this operation.
	 * @return array Account entries containing id, display name, and status.
	 */
	public static function serialize(array $accounts): array
	{
		$result = [];
		foreach ($accounts as $key => $account)
		{
			if (!is_array($account))
            {
            	continue;
            }
			$status = array_key_exists('status', $account)
				? (string) $account['status']
				: self::storedAccountStatus($account);
			if ($status === 'empty')
            {
            	continue;
            }
			$id = (int) ($account['acctnum'] ?? $key);
			$name = self::accountName($account);
			$name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
			if ($status === 'disabled')
            {
            	$name = '(disabled) ' . $name;
            }
			$result[] = [
				'id' => $id,
				'name' => '[' . $id . '] ' . $name,
				'status' => $status,
			];
		}
		return $result;
	}

	/**
	 * Determine whether saved account fields describe an empty, disabled, or enabled account.
	 *
	 * @param array $account The saved preference fields for one extra account.
	 * @return string The stored account status.
	 */
	private static function storedAccountStatus(array $account): string
	{
		foreach (['fullname', 'email_sig', 'layout'] as $required)
		{
			if (!isset($account[$required]))
            {
            	return 'empty';
            }
		}
		$enabled = $account['ex_account_enabled'] ?? null;
		return $enabled !== null && (string) $enabled !== '' ? 'enabled' : 'disabled';
	}
}