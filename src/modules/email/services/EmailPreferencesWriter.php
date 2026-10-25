<?php

namespace App\modules\email\services;

final class EmailPreferencesWriter
{
	public static function nextAccountNumber(array $accounts): int
	{
		$position = 1;
		foreach ($accounts as $key => $account)
		{
			$number = (int) ($account['acctnum'] ?? $key);
			if (!isset($account['fullname'], $account['email_sig'], $account['layout']))
			{
				return $number;
			}
			if ($number !== $position)
			{
				return $position;
			}
			$position++;
		}
		return $position;
	}

	public function saveDefault(array $standardSchema, array $customSchema, array $values, object $preferences, object $mail): void
	{
		$persister = new EmailPreferencePersister();
		$customEnabled = filter_var($values['use_custom_settings'] ?? false, FILTER_VALIDATE_BOOLEAN);
		$persister->writeFields($standardSchema, $values, $preferences, $mail);
		if ($customEnabled)
		{
			$persister->writeFields($customSchema, $values, $preferences, $mail);
		}
		else
		{
			$persister->deleteFields($customSchema, $preferences);
		}
		$preferences->save_repository();
	}

	public function saveExtraAccount(int $account, array $standardSchema, array $customSchema, array $values, object $preferences, object $mail): void
	{
		$persister = new EmailPreferencePersister();
		$basePath = '["ex_accounts"][' . $account . ']';
		$preferences->delete_struct('email', $basePath . '["use_custom_settings"]');
		$persister->writeFields(
			array_merge($standardSchema, $customSchema),
			$values,
			$preferences,
			$mail,
			$account
		);
		$preferences->sort_struct('email', '["ex_accounts"]');
		$preferences->save_repository();
	}
}