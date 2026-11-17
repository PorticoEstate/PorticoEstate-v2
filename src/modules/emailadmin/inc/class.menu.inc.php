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


		$menus['toolbar'] = array();


		$menus['admin'] = array(
			'index'	 => array(
				'text'		 => lang('Configuration'),
				'url'		 => phpgw::link('/index.php', array(
					'menuaction' => 'admin.uiconfig.index',
					'appname'	 => 'emailadmin'
				))
			),
			'emailadmin' => array(
				'text'	=> $translation->translate('emailadmin', array(), true),
				'url' => phpgw::link('/emailadmin/view/profiles'),
			),
		);


		Settings::getInstance()->update('flags', ['currentapp' => $incomingApp]);

		return $menus;
	}
}
