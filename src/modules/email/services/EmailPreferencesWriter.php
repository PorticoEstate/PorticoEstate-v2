<?php

/**
 * EMail - Account Preference Updates
 *
 * @author Sigurd Nes <sigurdne at gmail.com>
 * @copyright Copyright (C) 2026 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @package email
 */

/**
 * Coordinates main and extra-account saves, account-number validation and allocation, deletion, and cache invalidation.
 */

namespace App\modules\email\services;

final class EmailPreferencesWriter
{
	/**
	 * Validate that an input identifies a stored extra account.
	 *
	 * Validates an existing extra-account ID without coercing malformed input. /
	 *
	 * @param mixed $value The submitted extra-account identifier to validate.
	 * @param array $accounts The stored account map or normalized account summaries required by this operation.
	 * @return int The validated extra-account number.
	 */
	public static function existingAccountNumber(mixed $value, array $accounts): int
	{
		$account = is_int($value) || is_string($value)
			? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
			: false;
		if ($account === false)
		{
			throw new \InvalidArgumentException('Invalid email account');
		}
		if (!array_key_exists($account, $accounts))
		{
			throw new \OutOfBoundsException('Email account not found');
		}
		return $account;
	}

	/**
	 * Delete an existing extra-account preference tree and optionally save it.
	 *
	 * @param int $account The mail account number; null selects the main preference root where supported.
	 * @param array $accounts The stored account map or normalized account summaries required by this operation.
	 * @param object $preferences The preference repository exposing main and structured account writes.
	 * @param object $mail The active mail runtime used for mailbox access and header decoding.
	 * @param bool $saveRepository Whether to save repository changes and invalidate account caches.
	 * @return void No value is returned.
	 */
	public function deleteAccount(
		int $account, array $accounts, object $preferences, object $mail, bool $saveRepository = true
	): void
	{
		self::existingAccountNumber($account, $accounts);
		$preferences->delete_struct('email', '["ex_accounts"][' . $account . ']');
		if ($saveRepository)
		{
			$preferences->save_repository();
			$mail->invalidatePreferenceCache($account);
		}
	}

	/**
	 * Find the first unused positive extra-account number.
	 *
	 * Accepts stored preference maps or legacy account summaries with explicit status. /
	 *
	 * @param array $accounts The stored account map or normalized account summaries required by this operation.
	 * @return int The available extra-account identifier.
	 */
	public static function nextAccountNumber(array $accounts): int
	{
		$occupied = [];
		foreach ($accounts as $key => $account)
		{
			$number = (int) ($account['acctnum'] ?? $key);
			$complete = isset($account['status'])
				? $account['status'] !== 'empty'
				: isset($account['fullname'], $account['email_sig'], $account['layout']);
			if ($number > 0 && $complete)
			{
				$occupied[$number] = true;
			}
		}
		$position = 1;
		while (isset($occupied[$position]))
		{
			$position++;
		}
		return $position;
	}

	/**
	 * Persist main-account fields and apply the custom-settings master switch.
	 *
	 * Legacy adapters retain form decoding and can suppress repository saves in debug mode. /
	 *
	 * @param array $standardSchema The standard preference field definitions.
	 * @param array $customSchema The custom connection and authentication field definitions.
	 * @param array $values The submitted preference field values.
	 * @param object $preferences The preference repository exposing main and structured account writes.
	 * @param object $mail The active mail runtime used for mailbox access and header decoding.
	 * @param bool $legacyInput Whether legacy form decoding rules should be applied.
	 * @param bool $saveRepository Whether to save repository changes and invalidate account caches.
	 * @return void No value is returned.
	 */
	public function saveDefault(
		array $standardSchema, array $customSchema, array $values, object $preferences,
		object $mail, bool $legacyInput = false, bool $saveRepository = true
	): void
	{
		$persister = new EmailPreferencePersister();
		$customEnabled = $legacyInput ? isset($values['use_custom_settings'])
			: filter_var($values['use_custom_settings'] ?? false, FILTER_VALIDATE_BOOLEAN);
		$persister->writeFields($standardSchema, $values, $preferences, $mail, null, $legacyInput, !$legacyInput);
		if ($customEnabled)
		{
			$persister->writeFields($customSchema, $values, $preferences, $mail, null, $legacyInput, !$legacyInput);
		}
		else
		{
			$persister->deleteFields($customSchema, $preferences);
		}
		if ($saveRepository)
		{
			$preferences->save_repository();
			$mail->invalidatePreferenceCache(0);
		}
	}

	/**
	 * Persist schema-supported fields for the selected extra account.
	 *
	 * @param int $account The mail account number; null selects the main preference root where supported.
	 * @param array $standardSchema The standard preference field definitions.
	 * @param array $customSchema The custom connection and authentication field definitions.
	 * @param array $values The submitted preference field values.
	 * @param object $preferences The preference repository exposing main and structured account writes.
	 * @param object $mail The active mail runtime used for mailbox access and header decoding.
	 * @param bool $legacyInput Whether legacy form decoding rules should be applied.
	 * @param bool $saveRepository Whether to save repository changes and invalidate account caches.
	 * @return void No value is returned.
	 */
	public function saveExtraAccount(
		int $account, array $standardSchema, array $customSchema, array $values,
		object $preferences, object $mail, bool $legacyInput = false, bool $saveRepository = true
	): void
	{
		$persister = new EmailPreferencePersister();
		$basePath = '["ex_accounts"][' . $account . ']';
		$preferences->delete_struct('email', $basePath . '["use_custom_settings"]');
		$persister->writeFields(
			array_merge($standardSchema, $customSchema),
			$values,
			$preferences,
			$mail,
			$account,
			$legacyInput,
			!$legacyInput
		);
		$preferences->sort_struct('email', '["ex_accounts"]');
		if ($saveRepository)
		{
			$preferences->save_repository();
			$mail->invalidatePreferenceCache($account);
		}
	}
}