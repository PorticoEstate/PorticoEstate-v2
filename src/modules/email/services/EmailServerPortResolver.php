<?php

namespace App\modules\email\services;

final class EmailServerPortResolver
{
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
