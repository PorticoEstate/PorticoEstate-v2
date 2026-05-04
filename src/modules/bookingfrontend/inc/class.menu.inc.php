<?php

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\security\Acl;
use App\modules\phpgwapi\controllers\Locations;
use App\modules\phpgwapi\services\Translation;
use App\modules\bookingfrontend\viewcontrollers\MenuController;

class bookingfrontend_menu
{

	function get_menu()
	{
		$userSettings = Settings::getInstance()->get('user');
		$flags = Settings::getInstance()->get('flags');
		$phpgw_locations = new Locations();
		$translation = Translation::getInstance();

		$incoming_app			 = $flags['currentapp'];
		$flags['currentapp']	 = 'property';
		Settings::getInstance()->set('flags', $flags);
		$acl					 =	Acl::getInstance();

		Settings::getInstance()->update('flags', ['currentapp' => 'bookingfrontend']);

		$menus = array();

		if ($acl->check('run', Acl::READ, 'admin') || $acl->check('admin', Acl::ADD, 'bookingfrontend'))
		{
			$menus['admin'] = array(
				'index'			=> array(
					'text' => lang('Configuration'),
					'url'  => phpgw::link('/index.php', array(
						'menuaction' => 'admin.uiconfig.index',
						'appname'	 => 'bookingfrontend'
					))
				),
				'metasettings'	=> array(
					'text' => lang('Metadata'),
					'url'  => phpgw::link('/index.php', array(
						'menuaction' => 'booking.uimetasettings.index',
						'appname'	 => 'booking'
					))
				),
				'multi_domain' => array(
					'text' => $translation->translate('multi domain', array(), false, 'booking'),
					'url'  => phpgw::link('/index.php', array(
						'menuaction' => 'booking.uigeneric.index',
						'type'		 => 'multi_domain'
					)),
				),
			);
		}

		Settings::getInstance()->update('flags', ['currentapp' => $incoming_app]);
		return MenuController::applyOverrides($menus);
	}
}
