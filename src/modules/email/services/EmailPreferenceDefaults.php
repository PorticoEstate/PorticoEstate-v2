<?php

/**
 * EMail - Preference Defaults
 *
 * @author Sigurd Nes <sigurdne at gmail.com>
 * @copyright Copyright (C) 2026 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @package email
 */

/**
 * Resolves schema-defined defaults for main and extra accounts without persisting values or evaluating PHP.
 */

namespace App\modules\email\services;

/**
 * Resolves schema defaults without persisting them or evaluating schema text as PHP.
 * Blank literal options use their defaults; intentional empty text and explicit
 * booleans are preserved. Passwords and disabled features are never filled.
 */
final class EmailPreferenceDefaults
{
	private array $fields;

	/**
	 * Load the supplied or default preference schema used for value resolution.
	 *
	 * @param array|null $schema The optional standard/custom preference schema; null loads the shared schema.
	 */
	public function __construct(?array $schema = null)
	{
		$schema ??= (new EmailPreferenceSchema())->fields();
		$this->fields = array_merge($schema['standard'], $schema['custom']);
	}

	/**
	 * Fill missing scope-appropriate preference defaults from explicit schema tokens.
	 *
	 * @param array $values The existing values for the selected mail account.
	 * @param int $account The mail account number; null selects the main preference root where supported.
	 * @param array $user The user settings supplying identity and account default context.
	 * @param array $server The server settings supplying connection and login defaults.
	 * @return array The resolved preference values without persisting changes.
	 */
	public function resolve(array $values, int $account, array $user = [], array $server = []): array
	{
		$scope = $account === 0 ? 'default' : 'extra_accounts';
		foreach ($this->fields as $field)
		{
			if (stripos($field['accts_usage'], $scope) === false
				|| stripos($field['type'], 'INACTIVE') !== false)
			{
				continue;
			}
			$id = $field['id'];
			[$kind, $source] = array_pad(explode(',', $field['init_default'], 2), 2, '');
			$present = isset($values[$id]);
			if ($present && !($kind === 'string' && trim($source) !== ''
				&& is_string($values[$id]) && trim($values[$id]) === ''))
			{
				continue;
			}
			switch ($kind)
			{
				case 'string':
					$values[$id] = trim($source) === '' ? '' : $source;
					break;
				case 'set_or_not':
					if ($source !== 'not_set')
					{
						$values[$id] = 'True';
					}
					break;
				case 'init_no_fill':
					break;
				case 'varEVAL':
					$context = [
						'$this->userSettings["fullname"];' => $user['fullname'] ?? null,
						'$this->serverSettings["mail_server"];' => $server['mail_server'] ?? null,
						'$this->serverSettings["mail_server_type"];' => $server['mail_server_type'] ?? null,
						'$this->serverSettings["imap_server_type"];' => $server['imap_server_type'] ?? null,
						'$this->serverSettings["mail_folder"];' => $server['mail_folder'] ?? null,
					];
					if (!array_key_exists($source, $context))
					{
						throw new \LogicException('Unsupported email preference default: ' . $id);
					}
					if ($context[$source] !== null)
					{
						$values[$id] = $context[$source];
					}
					break;
				case 'function':
					$login = (string) ($user['account_lid'] ?? '');
					if (!in_array($source, ['sub_default_userid', 'sub_default_address'], true))
					{
						throw new \LogicException('Unsupported email preference default: ' . $id);
					}
					if ($login === '')
					{
						break;
					}
					$suffix = (string) ($server['mail_suffix'] ?? '');
					if ($source === 'sub_default_address')
					{
						$values[$id] = str_contains($login, '@') || $suffix === '' ? $login : $login . '@' . $suffix;
					}
					else
					{
						$values[$id] = match ($server['mail_login_type'] ?? '')
                        {
							'vmailmgr' => $login . '@' . $suffix,
							'ispman' => $login . '_' . str_replace('.', '_', $suffix),
							default => $login,
						};
					}
					break;
				default:
					throw new \LogicException('Unsupported email preference default: ' . $id);
			}
		}
		return $values;
	}
}
