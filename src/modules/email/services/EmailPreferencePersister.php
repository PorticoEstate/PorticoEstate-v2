<?php

/**
 * EMail - Preference Field Persistence
 *
 * @author Sigurd Nes <sigurdne at gmail.com>
 * @copyright Copyright (C) 2026 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @package email
 */

/**
 * Writes and removes schema-defined preference fields with account scoping, encoding, and secret-handling rules.
 */

namespace App\modules\email\services;

final class EmailPreferencePersister
{
	/**
	 * Apply schema rules when writing or deleting submitted preference fields.
	 *
	 * @param array $fields The schema field definitions to apply within the account scope.
	 * @param array $values The submitted preference field values.
	 * @param object $preferences The preference repository exposing main and structured account writes.
	 * @param object $mail The active mail runtime used for mailbox access and header decoding.
	 * @param int|null $account The mail account number; null selects the main preference root where supported.
	 * @param bool $legacyInput Whether legacy form decoding rules should be applied.
	 * @param bool $normalizeCheckboxes Whether absent or false checkbox values should be normalized as disabled.
	 * @return bool Whether at least one nonempty field value was written.
	 */
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

	/**
	 * Remove schema fields belonging to the selected preference scope.
	 *
	 * @param array $fields The schema field definitions to apply within the account scope.
	 * @param object $preferences The preference repository exposing main and structured account writes.
	 * @param int|null $account The mail account number; null selects the main preference root where supported.
	 * @return void No value is returned.
	 */
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

	/**
	 * Remove one main-account or nested extra-account preference.
	 *
	 * @param object $preferences The preference repository exposing main and structured account writes.
	 * @param string $id The stored entity identifier or new-entry marker accepted by the operation.
	 * @param int|null $account The mail account number; null selects the main preference root where supported.
	 * @return void No value is returned.
	 */
	private function deleteValue(object $preferences, string $id, ?int $account): void
	{
		if ($account === null)
		{
			$preferences->delete('email', $id);
			return;
		}

		$preferences->delete_struct('email', $this->accountPath($account, $id));
	}

	/**
	 * Write one main-account or nested extra-account preference.
	 *
	 * @param object $preferences The preference repository exposing main and structured account writes.
	 * @param string $id The stored entity identifier or new-entry marker accepted by the operation.
	 * @param string $value The encoded or encrypted value ready for preference storage.
	 * @param int|null $account The mail account number; null selects the main preference root where supported.
	 * @return void No value is returned.
	 */
	private function addValue(object $preferences, string $id, string $value, ?int $account): void
	{
		if ($account === null)
		{
			$preferences->add('email', $id, $value);
			return;
		}

		$preferences->add_struct('email', $this->accountPath($account, $id), $value);
	}

	/**
	 * Build the nested preference path for an extra-account field.
	 *
	 * @param int $account The mail account number; null selects the main preference root where supported.
	 * @param string $id The stored entity identifier or new-entry marker accepted by the operation.
	 * @return string The structured extra-account preference path.
	 */
	private function accountPath(int $account, string $id): string
	{
		return '["ex_accounts"][' . $account . ']["' . $id . '"]';
	}
}
