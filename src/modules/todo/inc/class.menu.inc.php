<?php

/**
 * todo - Menus
 *
 * @author Dave Hall <skwashd@phpgroupware.org>
 * @copyright Copyright (C) 2007 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @package todo 
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
use App\modules\phpgwapi\services\Translation;

/**
 * Menus
 *
 * @package todo
 */
class todo_menu
{
	/**
	 * Get the menus for the todo
	 *
	 * @return array available menus for the current user
	 */
	function get_menu()
	{
		$userSettings = Settings::getInstance()->get('user');
		$flags = Settings::getInstance()->get('flags');
		$translation = Translation::getInstance();

		$incoming_app			 = $flags['currentapp'];
		Settings::getInstance()->update('flags', ['currentapp' => 'todo']);
		$menus = array();

		$menus['navbar'] = array(
			'todo' => array(
				'text'	=> $translation->translate('todo', array(), true),
				'url'	=> phpgw::link('/todo/view/todos'),
				'image'	=> array('todo', 'navbar'),
				'order'	=> 10,
				'group'	=> 'office'
			)
		);

		$menus['toolbar'] = array(
			array(
				'text'	=> $translation->translate('New', array(), true),
				'url'	=> phpgw::link('/todo/view/todos/add'),
			)
		);

		if (isset($userSettings['apps']['admin']))
		{
			$menus['admin'] = array(
				'categories' =>array(
					'text'	=> $translation->translate('Global Categories', array(), true),
					'url'	=> phpgw::link('/index.php', array('menuaction' => 'admin.uicategories.index', 'appname' => 'todo', 'global_cats' => 1))
				),
				'acl' => array(
					'text' => $translation->translate('Configure Access Permissions', array(), true),
					'url' => phpgw::link('/index.php', array(
						'menuaction' => 'preferences.uiadmin_acl.list_acl',
						'acl_app' => 'todo'
					))
				),
			);

		}

		if (isset($userSettings['apps']['preferences']))
		{
			$menus['preferences'] = array(
				array(
					'text'	=> $translation->translate('Preferences', array(), true),
					'url'	=> phpgw::link('/preferences/section', array('appname'	=> 'todo')),
				),
				array(
					'text'	=> $translation->translate('Grant Access', array(), true),
					'url'	=> phpgw::link('/index.php', array('menuaction' => 'preferences.uiadmin_acl.aclprefs', 'acl_app' => 'todo'))
				),
				array(
					'text'	=> $translation->translate('Edit categories', array(), true),
					'url'	=> phpgw::link('/index.php', array('menuaction' => 'preferences.uicategories.index', 'cats_app' => 'todo', 'cats_level' => 1, 'global_cats' => 1))
				)
			);

			$menus['toolbar'][] = array(
				'text'	=> $translation->translate('Preferences', array(), true),
				'url'	=> phpgw::link('/preferences/section', array('appname'	=> 'todo')),
				'image'	=> array('todo', 'preferences')
			);
		}

		$menus['navigation'] =  array(
			array(
				'text'	=> $translation->translate('New', array(), true),
				'url'	=> phpgw::link('/todo/view/todos/add'),
			)
		);

		$menus['folders'] = phpgwapi_menu::get_categories('todo');
		Settings::getInstance()->update('flags', ['currentapp' => $incoming_app]);

		return $menus;
	}
}
