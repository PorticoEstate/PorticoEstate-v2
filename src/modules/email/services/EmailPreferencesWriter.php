<?php

namespace App\modules\email\services;

final class EmailPreferencesWriter
{
	/**
	 * Accepts stored preference maps or legacy account summaries with explicit status.
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
	 * Legacy adapters retain form decoding and can suppress repository saves in debug mode.
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