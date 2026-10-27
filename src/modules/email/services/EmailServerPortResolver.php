<?php

/**
 * EMail - Mail Server Ports
 *
 * @author Sigurd Nes <sigurdne at gmail.com>
 * @copyright Copyright (C) 2026 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @package email
 */

/**
 * Resolves incoming ports from account overrides, applicable profile settings, and protocol defaults.
 */

namespace App\modules\email\services;

final class EmailServerPortResolver
{
	/**
	 * Choose a valid account port, applicable IMAP profile port, or protocol default.
	 *
	 * @param string $serverType The incoming protocol identifier, such as imap, imaps, pop3, or pop3s.
	 * @param mixed $accountPort The account-specific port candidate, which may be unset or invalid.
	 * @param mixed $profileImapPort The optional IMAP profile port candidate.
	 * @return int The effective incoming server port.
	 */
	public static function resolve(string $serverType, $accountPort, $profileImapPort): int
	{
		$accountPort = self::validPort($accountPort);
		if ($accountPort !== null)
		{
			return $accountPort;
		}

		if (in_array($serverType, ['imap', 'imaps'], true))
		{
			$profileImapPort = self::validPort($profileImapPort);
			if ($profileImapPort !== null)
			{
				return $profileImapPort;
			}
		}

		$defaults = [
			'imap' => 143,
			'imaps' => 993,
			'pop3' => 110,
			'pop3s' => 995,
			'nntp' => 119,
		];
		return $defaults[$serverType] ?? 143;
	}

	/**
	 * Validate an arbitrary port candidate against the allowed numeric range.
	 *
	 * @param mixed $port The network port or port candidate to validate.
	 * @return int|null The valid port number, or null when the candidate is invalid.
	 */
	private static function validPort($port): ?int
	{
		if (!is_int($port) && !is_string($port))
		{
			return null;
		}

		$validated = filter_var(
			$port,
			FILTER_VALIDATE_INT,
			['options' => ['min_range' => 1, 'max_range' => 65535]]
		);
		return $validated === false ? null : $validated;
	}
}
