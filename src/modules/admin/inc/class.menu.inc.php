<?php

/**
 * Admin - Menus
 *
 * @author Dave Hall <skwashd@phpgroupware.org>
 * @copyright Copyright (C) 2007 - 2008 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @package addressbook
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
use App\modules\phpgwapi\controllers\Locations;

/**
 * Menus
 *
 * @package admin
 */
class admin_menu
{
	/**
	 * Get the menus for admin
	 *
	 * @return array available menus for the current user
	 */
	function get_menu()
	{
		$translation = Translation::getInstance();
		$userSettings = Settings::getInstance()->get('user');
		$serverSettings = Settings::getInstance()->get('server');
		$flags = Settings::getInstance()->get('flags');
		$acl = Acl::getInstance();
		$locations = new Locations();

		$menus = array();

		$menus['navbar'] = array(
			'admin'	=> array(
				'text'	=> $translation->translate('Administration', array(), true),
				'url'	=> phpgw::link(
					'/index.php',
					array('menuaction' => 'admin.uimainscreen.mainscreen')
				),
				'image'	=> array('admin', 'navbar'),
				'order'	=> -5,
				'group'	=> 'systools'
			)
		);

		$local_admin = false;
		$is_admin = $acl->check('run', Acl::READ, 'admin');
		if (!$is_admin)
		{
			$available_apps = Settings::getInstance()->get('apps');
			foreach ($available_apps as $_app => $dummy)
			{
				if ($acl->check('admin', Acl::ADD, $_app))
				{
					$local_admin = true;
					break;
				}
			}
		}

		$menus['admin'] = array();
		if ($is_admin)
		{
			$menus['admin']['index'] = array(
				'text'	=> $translation->translate('global configuration', array(), true),
				'url'	=> phpgw::link(
					'/index.php',
					array('menuaction' => 'admin.uiconfig.index', 'appname' => 'admin')
				)
			);
			$menus['admin']['file_config'] = array(
				'text'	=> $translation->translate('file configuration', array(), true),
				'nav_location' => 'navbar#' . $locations->get_id('admin', 'vfs_filedata'),
				'url'	=> phpgw::link(
					'/index.php',
					array(
						'menuaction' => 'admin.uiconfig2.index',
						'location_id' => $locations->get_id('admin', 'vfs_filedata')
					)
				)
			);

			$menus['admin']['file_attribs']	= array(
				'text'	=> $translation->translate('file attributes', array(), true),
				'url'	=> phpgw::link(
					'/index.php',
					array(
						'menuaction'		=> 'admin.ui_custom.list_attribute',
						'appname'			=> 'admin',
						'location'			=> 'vfs_filedata',
						'menu_selection'	=> 'admin::admin::file_attribs'
					)
				)
			);
			$menus['admin']['file_cats']	= array(
				'text'	=> $translation->translate('file categories', array(), true),
				'url'	=> phpgw::link(
					'/index.php',
					array(
						'menuaction'		=> 'admin.uicategories.index',
						'appname'			=> 'admin',
						'location'			=> 'vfs_filedata',
						'menu_selection'	=> 'admin::admin::file_cats'
					)
				)
			);
		}

		if ($is_admin)
		{
			$menus['admin']['global_message'] = array(
				'text'	=> $translation->translate('global message', array(), true),
				'url'	=> phpgw::link(
					'/index.php',
					array('menuaction' => 'admin.uiaccounts.global_message')
				)
			);
		}

		if ($is_admin)
		{
			$menus['admin']['home_screen_message'] = array(
				'text'	=> $translation->translate('home screen message', array(), true),
				'url'	=> phpgw::link(
					'/index.php',
					array('menuaction' => 'admin.uiaccounts.home_screen_message')
				)
			);
		}

		if ($is_admin || $local_admin)
		{
			$menus['admin']['users'] = array(
				'text'	=> $translation->translate('manage users', array(), true),
				'url'	=> phpgw::link(
					'/index.php',
					array('menuaction' => 'admin.uiaccounts.list_users')
				)
			);
			$menus['admin']['groups'] = array(
				'text'	=> $translation->translate('manage groups', array(), true),
				'url'	=> phpgw::link(
					'/index.php',
					array('menuaction' => 'admin.uiaccounts.list_groups')
				)
			);
		}

		/*
			if ( $is_admin)
			{
				$menus['admin']['clear_user_cache'] = array
				(
					'text'	=> lang('Clear user cache'),
					'url'	=> phpgw::link('/index.php', array('menuaction' => 'admin.uiaccounts.clear_user_cache') )
				);
			}
*/
		if ($is_admin)
		{
			$menus['admin']['clear_cache'] = array(
				'text'	=> $translation->translate('clear cache', array(), true),
				'url'	=> phpgw::link('/index.php', array('menuaction' => 'admin.uiaccounts.clear_cache'))
			);
		}

		if ($is_admin)
		{
			$menus['admin']['sync_account'] = array(
				'text'	=> $translation->translate('Sync Account-Contact', array(), true),
				'url'	=> phpgw::link('/index.php', array('menuaction' => 'admin.uiaccounts.sync_accounts_contacts'))
			);
		}

		if ($is_admin)
		{
			$menus['admin']['apps'] = array(
				'text'	=> $translation->translate('Applications', array(), true),
				'url'	=> phpgw::link(
					'/index.php',
					array('menuaction' => 'admin.uiapplications.get_list')
				)
			);
		}

		if ($is_admin)
		{
			$menus['admin']['categories'] = array(
				'text'	=> $translation->translate('Global Categories', array(), true),
				'url'	=> phpgw::link(
					'/index.php',
					array('menuaction' => 'admin.uicategories.index')
				)
			);
		}

		if ($is_admin)
		{
			$menus['admin']['addressmasters'] = array(
				'text'	=> $translation->translate('addressmasters', array(), true),
				'url'	=> phpgw::link('/index.php', array(
					'menuaction' => 'admin.uiaclmanager.list_addressmasters',
					'account_id' => $userSettings['account_id']
				))
			);
		}

		if ($is_admin)
		{
			$menus['admin']['mainscreen'] = array(
				'text'	=> $translation->translate('Change Main Screen Message', array(), true),
				'url'	=> phpgw::link(
					'/index.php',
					array('menuaction' => 'admin.uimainscreen.index')
				)
			);
		}

		if ($is_admin)
		{
			$menus['admin']['sessions'] = array(
				'text'	=> $translation->translate('View Sessions', array(), true),
				'url'	=> phpgw::link(
					'/index.php',
					array('menuaction' => 'admin.uicurrentsessions.list_sessions')
				)
			);
		}

		if ($is_admin)
		{
			$menus['admin']['access_log'] = array(
				'text'	=> $translation->translate('View Access Log', array(), true),
				'url'	=> phpgw::link(
					'/index.php',
					array('menuaction' => 'admin.uiaccess_history.list_history')
				)
			);
		}

		if ($is_admin)
		{
			$menus['admin']['error_log'] = array(
				'text'	=> $translation->translate('View Error Log', array(), true),
				'url'	=> phpgw::link(
					'/index.php',
					array('menuaction' => 'admin.uilog.list_log')
				)
			);
		}

		if ($is_admin)
		{
			$menus['admin']['log_levels'] = array(
				'text'	=> $translation->translate('Edit Log Levels', array(), true),
				'url'	=> phpgw::link(
					'/index.php',
					array('menuaction' => 'admin.uiloglevels.edit_log_levels')
				)
			);
		}

		if ($is_admin)
		{
			$text = $translation->translate(
				'Find and Register all Application Hooks',
				array(),
				true
			);

			$menus['admin']['hooks'] = array(
				'text'	=> $text,
				'url'	=> phpgw::link(
					'/index.php',
					array('menuaction' => 'admin.uiapplications.register_all_hooks')
				)
			);
		}

		if ($is_admin)
		{
			$menus['admin']['async'] = array(
				'text'	=> $translation->translate('Asynchronous timed services', array(), true),
				'url'	=> phpgw::link(
					'/index.php',
					array('menuaction' => 'admin.uiasyncservice.index')
				)
			);
		}

		if (
			$is_admin
			&& function_exists('phpinfo')
		) // it is possible to disable commands in php.ini
		{
			$menus['admin']['phpinfo'] = array(
				'text'	=> $translation->translate('PHP Configuration', array(), true),
				'url'	=> phpgw::link(
					'/index.php',
					array('menuaction' => 'admin.uiconfig.phpinfo')
				),
			);
		}

		if ($is_admin)
		{
			$menus['admin']['opcache_monitor'] = array(
				'text'	=> $translation->translate('opcache monitor', array(), true),
				'url'	=> phpgw::link('/admin/admin/opcache/'),
			);
		}
		if ($is_admin)
		{
			$menus['admin']['redis_monitor'] = array(
				'text'	=> $translation->translate('redis monitor', array(), true),
				'url'	=> phpgw::link('/admin/admin/redis/'),
			);
		}


		if (!$is_admin && !$local_admin)
		{
			unset($menus['navbar']['admin']);
			unset($menus['admin']);
		}

		if (isset($userSettings['apps']['preferences']))
		{
			$menus['preferences'] = array();
		}

		$menus['toolbar'] = array();
		if (
			$acl->check('account_access', Acl::ADD, 'admin')
			|| $acl->check('account_access', Acl::PRIV, 'admin')
		)
		{
			$menus['toolbar'][] = array(
				'text'	=> $translation->translate('Add User', array(), true),
				'url'	=> phpgw::link(
					'/index.php',
					array('menuaction' => 'admin.uiaccounts.edit_account', 'account_id' => 0)
				),
				'image'	=> array('admin', 'user')
			);
		}

		if (
			$acl->check('group_access', Acl::ADD, 'admin')
			|| $acl->check('group_access', Acl::PRIV, 'admin')
		)
		{
			$menus['toolbar'][] = array(
				'text'	=> $translation->translate('Add Group', array(), true),
				'url'	=> phpgw::link(
					'/index.php',
					array('menuaction' => 'admin.uiaccounts.edit_group', 'account_id' => 0)
				),
				'image'	=> array('admin', 'group')
			);
		}

		if (
			!$acl->check('info_access', Acl::READ, 'admin')
			&& function_exists('phpinfo')
		)
		{
			$menus['toolbar'][] = array(
				'text'	=> $translation->translate('phpInfo', array(), true),
				'url'	=> phpgw::link('/admin/phpinfo.php')
					. '" onclick="window.open(\''
					. phpgw::link('/admin/phpinfo.php')
					. '\'); return false;"',
				'image'	=> array('admin', 'php')
			);
		}

		//$menus['navigation'] = $menus['admin'];

		return $menus;
	}
}
