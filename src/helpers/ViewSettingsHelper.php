<?php

namespace App\helpers;

use App\modules\phpgwapi\services\Settings;

class ViewSettingsHelper
{
	public static function rowsPerPage(): int
	{
		$user = Settings::getInstance()->get('user');
		return isset($user['preferences']['common']['maxmatchs']) && (int) $user['preferences']['common']['maxmatchs'] > 0
			? (int) $user['preferences']['common']['maxmatchs']
			: 10;
	}

	public static function lengthMenu(?int $rowsPerPage = null, bool $includeAll = false): array
	{
		$rowsPerPage ??= self::rowsPerPage();
		if ($includeAll)
		{
			return [
				[$rowsPerPage, $rowsPerPage * 2, $rowsPerPage * 3, -1],
				[$rowsPerPage, $rowsPerPage * 2, $rowsPerPage * 3, 'all'],
			];
		}

		return [$rowsPerPage, $rowsPerPage * 2, $rowsPerPage * 3];
	}
}
