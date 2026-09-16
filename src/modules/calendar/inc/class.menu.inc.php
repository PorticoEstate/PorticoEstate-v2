<?php

/**
 * Calendar - Menus
 *
 * @author Dave Hall <skwashd@phpgroupware.org>
 * @copyright Copyright (C) 2007 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @package calendar 
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
 * @package calendar
 */
class calendar_menu
{
	/**
	 * Get the menus for the calendar
	 *
	 * @return array available menus for the current user
	 */
	function get_menu()
	{
		$userSettings = Settings::getInstance()->get('user');
		$flags = Settings::getInstance()->get('flags');
		$translation = Translation::getInstance();

		$incoming_app			 = $flags['currentapp'];
		Settings::getInstance()->update('flags', ['currentapp' => 'calendar']);
		$menus = array();

		$menus['navbar'] = array(
			'calendar'	=> array(
				'text'	=> $translation->translate('Calendar', array(), true),
				'url'	=> phpgw::link('/calendar/view/month'),
				'image'	=> array('calendar', 'navbar'),
				'order'	=> 4,
				'group'	=> 'office'
			)
		);

		$menus['toolbar'] = array(
			array(
				'text'	=> $translation->translate('New', array(), true),
				'url'	=> phpgw::link('/calendar/view/event/new'),
				'image'	=> array('calendar', 'new')

			),
			array(
				'text'	=> $translation->translate('Today', array(), true),
				'url'	=> phpgw::link('/calendar/view/day'),
				'image'	=> array('calendar', 'today')
			),
			array(
				'text'	=> $translation->translate('Week', array(), true),
				'url'	=> phpgw::link('/calendar/view/week'),
				'image'	=> array('calendar', 'week')
			),
			array(
				'text'	=> $translation->translate('Week Detailed', array(), true),
				'url'	=> phpgw::link('/calendar/view/week-new'),
				'image'	=> array('calendar', 'week_detailed')
			),
			array(
				'text'	=> $translation->translate('Month', array(), true),
				'url'	=> phpgw::link('/calendar/view/month'),
				'image'	=> array('calendar', 'month')
			),
			array(
				'text'	=> $translation->translate('Year', array(), true),
				'url'	=> phpgw::link('/calendar/view/year'),
				'image'	=> array('calendar', 'year')
			),
			array(
				'text'	=> $translation->translate('Group Planner', array(), true),
				'url'	=> phpgw::link('/calendar/view/planner'),
				'image'	=> array('calendar', 'planner')
			),
			array(
				'text'	=> $translation->translate('Busy/Free', array(), true),
				'url'	=> phpgw::link('/calendar/view/matrix'),
				'image'	=> array('calendar', 'busy_free')
			),
			array(
				'text'	=> $translation->translate('Import', array(), true),
				'url'	=> phpgw::link('/calendar/view/import'),
				'image'	=> array('calendar', 'import')
			)
		);

		if (isset($userSettings['apps']['admin']))
		{
			$menus['admin'] = array(
				'index'	=> array(
					'text'	=> $translation->translate('Site Configuration', array(), true),
					'url'	=> phpgw::link('/index.php', array('menuaction'	=> 'admin.uiconfig.index', 'appname'	=> 'calendar'))
				),
				'custom'	=> array(
					'text'	=> $translation->translate('Custom fields and sorting', array(), true),
					'url'	=> phpgw::link('/calendar/view/custom-fields')
				),
				'holiday'	=> array(
					'text'	=> $translation->translate('Calendar Holiday Management', array(), true),
					'url'	=> phpgw::link('/calendar/view/holidays')
				),
				'categories'	=> array(
					'text'	=> $translation->translate('Global Categories', array(), true),
					'url'	=> phpgw::link('/index.php', array('menuaction'	=> 'admin.uicategories.index', 'appname'	=> 'calendar'))
				)
			);
		}

		if (isset($userSettings['apps']['preferences']))
		{
			$menus['preferences'] = array(
				'preferences'	=> array(
					'text'	=> $translation->translate('Preferences', array(), true),
					'url'	=> phpgw::link('/preferences/preferences.php', array('appname'	=> 'calendar'))
				),
				'acls'	=> array(
					'text'	=> $translation->translate('Grant Access', array(), true),
					'url'	=> phpgw::link('/index.php', array('menuaction'	=> 'preferences.uiaclprefs.index', 'acl_app'	=> 'calendar'))
				),
				'categories'	=> array(
					'text'	=> $translation->translate('Edit Categories', array(), true),
					'url'	=> phpgw::link('/index.php', array('menuaction'	=> 'preferences.uicategories.index', 'cats_app'	=> 'calendar', 'cats_level'	=> 1, 'global_cats'	=> true))
				)
			);
			$menus['toolbar'][] = array(
				'text'	=> $translation->translate('Preferences', array(), true),
				'url'	=> phpgw::link('/preferences/preferences.php', array('appname'	=> 'calendar')),
				'image'	=> array('calendar', 'preferences')
			);
		}

		$menus['navigation'] = array(
			array(
				'text'	=> $translation->translate('New', array(), true),
				'url'	=> phpgw::link('/calendar/view/event/new')
			),
			array(
				'text'		=> $translation->translate('view', array(), true),
				'url'		=> '#',
				'children'	=> array(
					array(
						'text'	=> $translation->translate('Today', array(), true),
						'url'	=> phpgw::link('/calendar/view/day')
					),
					array(
						'text'	=> $translation->translate('Week', array(), true),
						'url'	=> phpgw::link('/calendar/view/week')
					),
					array(
						'text'	=> $translation->translate('Week Detailed', array(), true),
						'url'	=> phpgw::link('/calendar/view/week-new')
					),
					array(
						'text'	=> $translation->translate('Month', array(), true),
						'url'	=> phpgw::link('/calendar/view/month')
					),
					array(
						'text'	=> $translation->translate('Year', array(), true),
						'url'	=> phpgw::link('/calendar/view/year')
					),
					array(
						'text'	=> $translation->translate('Group Planner', array(), true),
						'url'	=> phpgw::link('/calendar/view/planner')
					),
					array(
						'text'	=> $translation->translate('Daily Matrix View', array(), true),
						'url'	=> phpgw::link('/calendar/view/matrix')
					)
				)
			),
			array(
				'text'	=> $translation->translate('Import', array(), true),
				'url'	=> phpgw::link('/calendar/view/import')
			)

		);

		$menus['folders'] = phpgwapi_menu::get_categories('calendar');
		Settings::getInstance()->update('flags', ['currentapp' => $incoming_app]);

		return $menus;
	}
}
