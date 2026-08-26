<?php

/**
 * phpGroupWare - messenger
 *
 * @author Sigurd Nes <sigurdne@online.no>
 * @copyright Copyright (C) 2009 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @package messenger
 * @subpackage ???
 * @version $Id$
 */
/*
	  This program is free software: you can redistribute it and/or modify
	  it under the terms of the GNU General Public License as published by
	  the Free Software Foundation, either version 2 of the License, or
	  (at your option) any later version.

	  This program is distributed in the hope that it will be useful,
	  but WITHOUT ANY WARRANTY; without even the implied warranty of
	  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	  GNU General Public License for more details.

	  You should have received a copy of the GNU General Public License
	  along with this program.  If not, see <http://www.gnu.org/licenses/>.
	 */

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\security\Acl;
use App\modules\phpgwapi\services\Translation;

/**
 * Description
 * @package messenger
 */
class messenger_menu
{

	/**
	 * Get the menus for the messenger
	 *
	 * @return array available menus for the current user
	 */
	public function get_menu()
	{
		$userSettings = Settings::getInstance()->get('user');
		$flags = Settings::getInstance()->get('flags');
		$translation = Translation::getInstance();

		$incoming_app			 = $flags['currentapp'];
		Settings::getInstance()->update('flags', ['currentapp' => 'messenger']);

		$acl = Acl::getInstance();

		$menus = array();

		$menus['navbar'] = array(
			'messenger' => array(
				'text' => lang('messenger'),
				'url' => phpgw::link('/messenger/view/inbox'),
				'image' => array('messenger', 'navbar'),
				'order' => 35,
				'group' => 'office'
			),
		);

		$menus['toolbar'] = array();
		if (isset($userSettings['apps']['admin']))
		{
			$menus['admin'] = array(
				'index' => array(
					'text' => lang('Configuration'),
					'url' => phpgw::link('/index.php', array(
						'menuaction' => 'admin.uiconfig.index',
						'appname' => 'messenger'
					))
				),
				'acl' => array(
					'text' => lang('Configure Access Permissions'),
					'url' => phpgw::link('/index.php', array(
						'menuaction' => 'preferences.uiadmin_acl.list_acl',
						'acl_app' => 'messenger'
					))
				)
			);
		}

		if (isset($userSettings['apps']['preferences']))
		{
			$menus['preferences'] = array(
				array(
					'text' => $translation->translate('Preferences', array(), true),
					'url' => phpgw::link('/preferences/preferences.php', array(
						'appname' => 'messenger',
						'type' => 'user'
					))
				),
				array(
					'text' => $translation->translate('Grant Access', array(), true),
					'url' => phpgw::link('/index.php', array(
						'menuaction' => 'preferences.uiadmin_acl.aclprefs',
						'acl_app' => 'messenger'
					))
				)
			);

			$menus['toolbar'][] = array(
				'text' => $translation->translate('Preferences', array(), true),
				'url' => phpgw::link('/preferences/preferences.php', array('appname' => 'messenger')),
				'image' => array('messenger', 'preferences')
			);
		}

		$menus['navigation'] = array(
			'inbox' => array(
				'url' => phpgw::link('/messenger/view/inbox'),
				'text' => $translation->translate('inbox', array(), false, 'messenger'),
				'image' => array('messenger', 'navbar')
			)
		);
		if ($acl->check('.compose', ACL_ADD, 'messenger'))
		{
			$menus['navigation']['compose'] = array(
				'url' => phpgw::link('/messenger/view/compose'),
				'text' => $translation->translate('compose', array(), false, 'messenger'),
			);
		}
		if ($acl->check('.compose_groups', ACL_ADD, 'messenger'))
		{
			$menus['navigation']['compose_groups'] = array(
				'url' => phpgw::link('/messenger/view/compose-groups'),
				'text' => $translation->translate('compose groups', array(), false, 'messenger'),
			);
		}
		if ($acl->check('.compose_global', ACL_ADD, 'messenger'))
		{
			$menus['navigation']['compose_global'] = array(
				'url' => phpgw::link('/index.php', array('menuaction' => 'messenger.uimessenger.compose_global')),
				'text' => $translation->translate('compose global', array(), false, 'messenger'),
			);
		}
		Settings::getInstance()->update('flags', ['currentapp' => $incoming_app]);
		return $menus;
	}
}
