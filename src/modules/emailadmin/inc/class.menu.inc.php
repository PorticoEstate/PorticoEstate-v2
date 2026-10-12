<?php

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\Translation;

class emailadmin_menu
{
	public function get_menu(): array
	{
		$userSettings = Settings::getInstance()->get('user');
		if (empty($userSettings['apps']['admin']))
		{
			return [];
		}

		$flags = Settings::getInstance()->get('flags');
		$incomingApp = $flags['currentapp'] ?? '';
		Settings::getInstance()->update('flags', ['currentapp' => 'emailadmin']);
		$translation = Translation::getInstance();
		$menus = [
			'admin' => [
				'configuration' => [
					'text' => $translation->translate('Site Configuration', [], true),
					'url' => phpgw::link('/emailadmin/view/profiles'),
				],
			],
		];
		Settings::getInstance()->update('flags', ['currentapp' => $incomingApp]);

		return $menus;
	}
}