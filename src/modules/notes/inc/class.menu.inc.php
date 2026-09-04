<?php

/**
 * notes - Menus
 *
 * @author Dave Hall <skwashd@phpgroupware.org>
 * @copyright Copyright (C) 2007 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @package notes 
 * @version $Id$
 */

/*
	   This program is free software: you can redistribute it and/or modify
	   it under the terms of the GNU General Public License as published by
	   the Free Software Foundation, either version 3 of the License, or
	   (at your option) any later version.

	   This program is distributed in the hope that it will be useful,
	   but WITHOUT ANY WARRANTY; without even the implied warranty of
	   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	   GNU General Public License for more details.

	   You should have received a copy of the GNU General Public License
	   along with this program.  If not, see <http://www.gnu.org/licenses/>.
	 */

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\Translation;

/**
 * Menus
 *
 * @package notes
 */
class notes_menu
{
	/**
	 * Get the menus for the notes
	 *
	 * @return array available menus for the current user
	 */
	function get_menu()
	{
		$userSettings = Settings::getInstance()->get('user');
		$flags = Settings::getInstance()->get('flags');
		$translation = Translation::getInstance();

		$incoming_app			 = $flags['currentapp'];
		Settings::getInstance()->update('flags', ['currentapp' => 'notes']);
		$menus = array();

		$menus['navbar'] = array(
			'notes' => array(
				'text'	=> $translation->translate('notes', array(), true),
				'url'	=> phpgw::link('/notes/view/notes'),
				'image'	=> array('notes', 'navbar'),
				'order'	=> 8,
				'group'	=> 'office'
			)
		);

		$menus['toolbar'] = array(
			array(
				'url'   => phpgw::link('/notes/view/notes/add'),
				'text'  => $translation->translate('New', array(), true),
				'image' => array('notes', 'new')
			)

		);

		if (isset($userSettings['apps']['admin']))
		{
			$menus['admin'] = array(
				array(
					'text'	=> 'Global Categories',
					'url'	=> phpgw::link('/index.php', array('menuaction' => 'admin.uicategories.index', 'appname' => 'notes', 'global_cats' => 'true'))
				)
			);
		}

		if (isset($userSettings['apps']['preferences']))
		{
			$menus['preferences'] = array(
				array(
					'text'	=> 'Grant Access',
					'url'	=> phpgw::link('/index.php', array('menuaction' => 'preferences.uiadmin_acl.aclprefs', 'acl_app' => 'notes'))
				),
				array(
					'text'	=> 'Edit categories',
					'url'	=> phpgw::link('/index.php', array('menuaction' => 'preferences.uicategories.index', 'cats_app' => 'notes', 'cats_level' => true, 'global_cats' => true))
				)
			);
		}

		$menus['navigation'] = array(
			'notes' => array(
				'url'   => phpgw::link('/notes/view/notes'),
				'text'  => $translation->translate('list notes', array(), true),
				'image' => array('notes', 'navbar')
			),
			'new' => array(
				'url'   => phpgw::link('/notes/view/notes/add'),
				'text'  => $translation->translate('New', array(), true),
				'image' => array('notes', 'new')
			)
		);
		$menus['folders'] = phpgwapi_menu::get_categories('notes');
		Settings::getInstance()->update('flags', ['currentapp' => $incoming_app]);

		return $menus;
	}
}
