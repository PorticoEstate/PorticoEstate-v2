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
			if (!isset($account['fullname'], $account['email_sig'], $account['layout'])) return $number;
			if ($number !== $position) return $position;
			$position++;
		}
		return $position;
	}

	public function saveDefault(array $standardSchema, array $customSchema, array $values, object $preferences, object $mail): void
	{
		$customEnabled = filter_var($values['use_custom_settings'] ?? false, FILTER_VALIDATE_BOOLEAN);
		foreach ($standardSchema as $field)
		{
			$field = (array) $field;
			$id = (string) ($field['id'] ?? '');
			if ($id === '' || stripos((string) ($field['accts_usage'] ?? ''), 'default') === false) continue;
			$this->writeField($field, $values, $preferences, $mail);
		}
		foreach ($customSchema as $field)
		{
			$field = (array) $field;
			$id = (string) ($field['id'] ?? '');
			if ($id === '' || stripos((string) ($field['accts_usage'] ?? ''), 'default') === false) continue;
			if ($customEnabled) $this->writeField($field, $values, $preferences, $mail);
			else $preferences->delete('email', $id);
		}
		$preferences->save_repository();
	}

	public function saveExtraAccount(int $account, array $standardSchema, array $customSchema, array $values, object $preferences, object $mail): void
	{
		$basePath = '["ex_accounts"][' . $account . ']';
		$preferences->delete_struct('email', $basePath . '["use_custom_settings"]');
		foreach (array_merge($standardSchema, $customSchema) as $field)
		{
			$field = (array) $field;
			$id = (string) ($field['id'] ?? '');
			if ($id === '' || $id === 'use_custom_settings' || stripos((string) ($field['accts_usage'] ?? ''), 'extra_accounts') === false) continue;
			$this->writeExtraField($account, $field, $values, $preferences, $mail);
		}
		$preferences->sort_struct('email', '["ex_accounts"]');
		$preferences->save_repository();
	}

	private function writeField(array $field, array $values, object $preferences, object $mail): void
	{
		$id = (string) $field['id'];
		$value = $values[$id] ?? null;
		if (($field['widget'] ?? '') === 'checkbox') $value = filter_var($value ?? false, FILTER_VALIDATE_BOOLEAN) ? 'True' : null;
		if ($value === null || !is_scalar($value) || trim((string) $value) === '')
		{
			$writeProps = (string) ($field['write_props'] ?? '');
			if (stripos($writeProps, 'empty_no_delete') !== false) return;
			$preferences->delete('email', $id);
			if (stripos($writeProps, 'empty_string_ok') !== false) $preferences->add('email', $id, '');
			return;
		}
		$stored = (string) $value;
		if (($field['type'] ?? '') === 'user_string')
		{
			$writeProps = (string) ($field['write_props'] ?? '');
			if (stripos($writeProps, 'no_db_defang') === false)
			{
				if (stripos($writeProps, 'encrypted') !== false) $stored = $mail->encrypt_email_passwd($stored);
				$stored = $mail->html_quotes_encode($stored);
			}
		}
		$preferences->delete('email', $id);
		$preferences->add('email', $id, $stored);
	}

	private function writeExtraField(int $account, array $field, array $values, object $preferences, object $mail): void
	{
		$id = (string) $field['id'];
		$path = '["ex_accounts"][' . $account . ']["' . $id . '"]';
		$value = $values[$id] ?? null;
		if (($field['widget'] ?? '') === 'checkbox') $value = filter_var($value ?? false, FILTER_VALIDATE_BOOLEAN) ? 'True' : null;
		if ($value === null || !is_scalar($value) || trim((string) $value) === '')
		{
			$writeProps = (string) ($field['write_props'] ?? '');
			if (stripos($writeProps, 'empty_no_delete') !== false) return;
			$preferences->delete_struct('email', $path);
			if (stripos($writeProps, 'empty_string_ok') !== false) $preferences->add_struct('email', $path, '');
			return;
		}
		$stored = (string) $value;
		if (($field['type'] ?? '') === 'user_string')
		{
			$writeProps = (string) ($field['write_props'] ?? '');
			if (stripos($writeProps, 'no_db_defang') === false)
			{
				if (stripos($writeProps, 'encrypted') !== false) $stored = $mail->encrypt_email_passwd($stored);
				else $stored = $mail->html_quotes_encode($stored);
			}
		}
		$preferences->delete_struct('email', $path);
		$preferences->add_struct('email', $path, $stored);
	}
}