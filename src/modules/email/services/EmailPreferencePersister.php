<?php

namespace App\modules\email\services;

final class EmailPreferencePersister
{
	public function writeFields(
		array $fields,
		array $values,
		object $preferences,
		object $mail,
		?int $account = null,
		bool $legacyInput = false,
		bool $normalizeCheckboxes = true
	): bool
	{
		$scope = $account === null ? 'default' : 'extra_accounts';
		$wroteValue = false;

		foreach ($fields as $field)
		{
			$field = (array) $field;
			$id = (string) ($field['id'] ?? '');
			if ($id === '' || stripos((string) ($field['accts_usage'] ?? ''), $scope) === false)
			{
				continue;
			}

			$value = $values[$id] ?? null;
			if ($normalizeCheckboxes && ($field['widget'] ?? '') === 'checkbox')
			{
				$value = filter_var($value ?? false, FILTER_VALIDATE_BOOLEAN) ? 'True' : null;
			}
			$writeProps = (string) ($field['write_props'] ?? '');
			$empty = $value === null || !is_scalar($value) || trim((string) $value) === '';

			if ($empty)
			{
				if (stripos($writeProps, 'empty_no_delete') !== false)
				{
					continue;
				}

				$this->deleteValue($preferences, $id, $account);
				if (stripos($writeProps, 'empty_string_ok') !== false)
				{
					$this->addValue($preferences, $id, '', $account);
				}
				continue;
			}

			$stored = (string) $value;
			if (($field['type'] ?? '') === 'user_string' && stripos($writeProps, 'no_db_defang') === false)
			{
				if ($legacyInput)
				{
					$stored = $mail->stripslashes_gpc($stored);
				}
				if (stripos($writeProps, 'encrypted') !== false)
				{
					$stored = $mail->encrypt_email_passwd($stored);
				}
				if ($account === null || stripos($writeProps, 'encrypted') === false)
				{
					$stored = $mail->html_quotes_encode($stored);
				}
			}

			$this->deleteValue($preferences, $id, $account);
			$this->addValue($preferences, $id, $stored, $account);
			$wroteValue = true;
		}

		return $wroteValue;
	}

	public function deleteFields(array $fields, object $preferences, ?int $account = null): void
	{
		$scope = $account === null ? 'default' : 'extra_accounts';
		foreach ($fields as $field)
		{
			$field = (array) $field;
			$id = (string) ($field['id'] ?? '');
			if ($id === '' || stripos((string) ($field['accts_usage'] ?? ''), $scope) === false)
			{
				continue;
			}
			$this->deleteValue($preferences, $id, $account);
		}
	}

	private function deleteValue(object $preferences, string $id, ?int $account): void
	{
		if ($account === null)
		{
			$preferences->delete('email', $id);
			return;
		}

		$preferences->delete_struct('email', $this->accountPath($account, $id));
	}

	private function addValue(object $preferences, string $id, string $value, ?int $account): void
	{
		if ($account === null)
		{
			$preferences->add('email', $id, $value);
			return;
		}

		$preferences->add_struct('email', $this->accountPath($account, $id), $value);
	}

	private function accountPath(int $account, string $id): string
	{
		return '["ex_accounts"][' . $account . ']["' . $id . '"]';
	}
}
