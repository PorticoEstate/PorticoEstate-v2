<?php

namespace App\modules\hrm\helpers;

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

	public static function lengthMenu(?int $rowsPerPage = null): array
	{
		$rowsPerPage ??= self::rowsPerPage();
		return [$rowsPerPage, $rowsPerPage * 2, $rowsPerPage * 3];
	}
}