<?php

/**
 * preferences - Menus
 *
 * @author Dave Hall <skwashd@phpgroupware.org>
 * @copyright Copyright (C) 2007-2008 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @package preferences
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

use App\modules\phpgwapi\services\Translation;
use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\security\Acl;


/**
 * Menus
 *
 * @package preferences
 */
class preferences_menu
{
	/**
	 * Get the menus for the preferences
	 *
	 * @return array available menus for the current user
	 */
	function get_menu()
	{
		$translation = Translation::getInstance();
		$serverSettings = Settings::getInstance()->get('server');
		$acl = Acl::getInstance();

		$menus = array();

		$menus['navbar'] = array(
			'preferences' => array(
				'text'	=> $translation->translate('Preferences', array(), true),
				'url'	=> phpgw::link('/preferences/index.php'),
				'image'	=> array('preferences', 'navbar'),
				'order'	=> 0,
				'group'	=> 'office'
			)
		);

		$menus['toolbar'] = array();

		$menus['navigation'] = array();
		$menus['navigation'][] = array(
			'text'	=> $translation->translate('My Preferences', array(), true),
			'url'	=> phpgw::link('/preferences/section', array('appname'	=> 'preferences')),
			'image'	=> array('preferences', 'preferences')
		);

		if (!$acl->check('changepassword', Acl::READ, 'preferences'))
		{
			$menus['navigation'][] = array(
				'text'	=> $translation->translate('Change your Password', array(), true),
				'url'	=> phpgw::link('/preferences/changepassword')
			);
		}

		if (isset($navbar['admin']))
		{
			$menus['navigation'][] = array(
				'text'	=> $translation->translate('Default Preferences', array(), true),
				'url'	=> phpgw::link('/preferences/index.php', array('type' => 'default'))
			);
			$menus['navigation'][] = array(
				'text'	=> $translation->translate('Forced Preferences', array(), true),
				'url'	=> phpgw::link('/preferences/index.php', array('type' => 'forced'))
			);
		}

		$menus['preferences'] = array(
			array(
				'text'	=> $translation->translate('Preferences', array(), true),
				'url'	=> phpgw::link(
					'/preferences/section',
					array('appname'	=> 'preferences')
				),
				'image'	=> array('preferences', 'preferences')
			),
			array(
				'text'	=> $translation->translate('Change your Password', array(), true),
				'url'	=> phpgw::link('/preferences/changepassword')
			)
		);
		//		if (isset($serverSettings['auth_type_passkey']) && $serverSettings['auth_type_passkey'] == 'true')
		{
			$menus['preferences'][] = [
				'text' => $translation->translate('Passkey Management', array(), true),
				'url' => phpgw::link('/passkey'),
				'icon' => 'fas fa-fingerprint'
			];
		}
		if ((isset($serverSettings['auth_type'])
				&& in_array($serverSettings['auth_type'],  array('remoteuser', 'azure')))
			|| (isset($serverSettings['half_remote_user'])
				&& $serverSettings['half_remote_user'] == 'remoteuser')
		)
		{
			if (
				$serverSettings['mapping'] == 'table'
				|| $serverSettings['mapping'] == 'all'
			)
			{
				$menus['preferences'][] = array(
					'text'	=> $translation->translate('Mapping', array(), true),
					'url'	=> phpgw::link('/index.php', array(
						'menuaction' => 'preferences.uimapping.index',
						'appname' => 'preferences'
					))
				);
			}
		}

		return $menus;
	}
}
