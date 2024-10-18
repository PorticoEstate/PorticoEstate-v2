<?php

/**
 * phpGroupWare - controller: a part of a Facilities Management System.
 *
 * @author Erik Holm-Larsen <erik.holm-larsen@bouvet.no>
 * @author Torstein Vadla <torstein.vadla@bouvet.no>
 * @copyright Copyright (C) 2011,2012 Free Software Foundation, Inc. http://www.fsf.org/
 * This file is part of phpGroupWare.
 *
 * phpGroupWare is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * phpGroupWare is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with phpGroupWare; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @internal Development of this application was funded by http://www.bergen.kommune.no/
 * @package property
 * @subpackage controller
 * @version $Id$
 */

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\security\Acl;
use App\modules\phpgwapi\controllers\Locations;
use App\modules\phpgwapi\services\Translation;

class controller_menu
{

	protected $mobilefrontend;

	function __construct()
	{
		$script_path = Sanitizer::get_var('REDIRECT_URL', 'string', 'SERVER');

		if ($script_path && preg_match('/mobilefrontend/', $script_path))
		{
			$this->mobilefrontend = true;
		}
	}


	function get_frontend_menu()
	{

		$userSettings = Settings::getInstance()->get('user');
		$flags = Settings::getInstance()->get('flags');
		$location_obj = new Locations();
		$translation = Translation::getInstance();

		$incoming_app			 = $flags['currentapp'];
		Settings::getInstance()->update('flags', ['currentapp' => 'controller']);
		$acl					 =	Acl::getInstance();

		$menus = array();

		$menus['navbar'] = array(
			'calendar_planner' => array(
				'text' => lang('calendar planner'),
				'url' => phpgw::link('/index.php', array('menuaction' => 'controller.uicalendar_planner.index')),
				'image' => array('property', 'location'),
				'order' => 10,
				'group' => 'office'
			)
		);

		$menus['navigation'] = array();

		$menus['navigation']					 = array();
		$menus['navigation']['calendar_planner'] = array(
			'text'	 => lang('calendar planner'),
			'url'	 => phpgw::link('/index.php', array('menuaction' => 'controller.uicalendar_planner.index')),
			'image'	 => array('property', 'location_1')
		);


		//			if ($acl->check('run', Acl::READ, 'admin') || $acl->check('.control', Acl::EDIT, 'controller'))
		//			{
		//				$menus['navigation']['settings']			 = array(
		//					'text'	 => lang('settings'),
		//					'url'	 => phpgw::link('/index.php', array('menuaction' => 'controller.uisettings.edit')),
		//					'image'	 => array('property', 'location_1')
		//				);
		//			}

		$menus['navigation']['start_inspection']	 = array(
			'text'	 => lang('start inspection'),
			'url'	 => phpgw::link('/index.php', array('menuaction' => 'controller.uicalendar_planner.start_inspection')),
			'image'	 => array('property', 'location_1')
		);

		$menus['navigation']['ad_hoc']	 = array(
			'text'	 => 'Ad Hoc',
			'url'	 => phpgw::link('/index.php', array('menuaction' => 'controller.uicalendar_planner.ad_hoc')),
			'image'	 => array('property', 'location_1')
		);

		$menus['navigation']['inspection_history']	 = array(
			'text'	 => lang('inspection history'),
			'url'	 => phpgw::link('/index.php', array('menuaction' => 'controller.uicalendar_planner.inspection_history')),
			'image'	 => array('property', 'location_1')
		);

		Settings::getInstance()->update('flags', ['currentapp' => $incoming_app]);
		return $menus;
	}

	function get_menu()
	{
		if ($this->mobilefrontend)
		{
			return $this->get_frontend_menu();
		}

		$userSettings = Settings::getInstance()->get('user');
		$flags = Settings::getInstance()->get('flags');
		$location_obj = new Locations();
		$translation = Translation::getInstance();

		$incoming_app			 = $flags['currentapp'];
		Settings::getInstance()->update('flags', ['currentapp' => 'controller']);
		$acl					 =	Acl::getInstance();


		$menus = array();
		$config = CreateObject('phpgwapi.config', 'controller');
		$config->read();
		if (isset($config->config_data['home_alternative']) && $config->config_data['home_alternative'])
		{
			$main = 'controller.uicomponent.index';
		}
		else
		{
			$main = 'controller.uicontrol.control_list';
		}


		$menus['navbar'] = array(
			'controller' => array(
				'text' => lang('Controller'),
				'url' => phpgw::link('/index.php', array('menuaction' => $main)),
				'image' => array('property', 'location'),
				'order' => 10,
				'group' => 'office'
			)
		);

		$menus['navigation'] = array();
		if ($acl->check('.usertype.superuser', ACL_ADD, 'controller'))
		{
			$menus['navigation']['control'] =  array(
				'text' => lang('Control types'),
				'url' => phpgw::link('/index.php', array('menuaction' => 'controller.uicontrol.control_list')),
				'image' => array('property', 'location_1')
			);


			if (!isset($config->config_data['home_alternative']) || !$config->config_data['home_alternative'])
			{

				$menus['navigation']['control']['children'] = array(
					'location_for_check_list' => array(
						'text' => lang('location_connections'),
						'url' => phpgw::link('/index.php', array('menuaction' => 'controller.uicontrol_register_to_location.index')),
						'image' => array('property', 'location_1')
					)
				);
			}

			$menus['navigation']['control_item'] = array(
				'text' => lang('Control_item'),
				'url' => phpgw::link('/index.php', array('menuaction' => 'controller.uicontrol_item.index')),
				'image' => array('property', 'location_1')
			);
			$menus['navigation']['control_group'] = array(
				'text' => lang('Control_group'),
				'url' => phpgw::link('/index.php', array('menuaction' => 'controller.uicontrol_group.index')),
				'image' => array('property', 'location_1')
			);
		}
		$menus['navigation']['procedure'] = array(
			'text' => lang('Procedure'),
			'url' => phpgw::link('/index.php', array('menuaction' => 'controller.uiprocedure.index')),
			'image' => array('property', 'location_1'),
		);
		$menus['navigation']['calendar_overview'] = array(
			'text' => lang('Calendar_overview'),
			'url' => phpgw::link('/index.php', array('menuaction' => 'controller.uicalendar.view_calendar_for_year')),
			'image' => array('property', 'location_1'),
		);
		$menus['navigation']['status_components'] = array(
			'text' => lang('status components'),
			'url' => phpgw::link('/index.php', array('menuaction' => 'controller.uicomponent.index')),
			'image' => array('property', 'location_1'),
		);
		$menus['navigation']['status_locations'] = array(
			'text' => lang('status locations'),
			'url' => phpgw::link('/index.php', array('menuaction' => 'controller.uicomponent.index', 'get_locations' => true)),
			'image' => array('property', 'location_1'),
		);
		$menus['navigation']['bulk_update_assigned'] = array(
			'text' => lang('bulk update assigned'),
			'url' => phpgw::link('/index.php', array('menuaction' => 'controller.uibulk_update.assign')),
			'image' => array('property', 'location_1'),
		);
		$menus['navigation']['calendar_planner'] = array(
			'text' => lang('calendar planner'),
			'url' => phpgw::link('/index.php', array('menuaction' => 'controller.uicalendar_planner.index')),
			'image' => array('property', 'location'),
			'order' => 10,
			'group' => 'office'
		);

		//			if ($acl->check('run', Acl::READ, 'admin') || $acl->check('.control', Acl::EDIT, 'controller'))
		//			{
		//				$menus['navigation']['settings']			 = array(
		//					'text'	 => lang('settings'),
		//					'url'	 => phpgw::link('/index.php', array('menuaction' => 'controller.uisettings.edit')),
		//					'image'	 => array('property', 'location_1')
		//				);
		//			}

		$menus['navigation']['start_inspection']	 = array(
			'text'	 => lang('start inspection'),
			'url'	 => phpgw::link('/index.php', array('menuaction' => 'controller.uicalendar_planner.start_inspection')),
			'image'	 => array('property', 'location_1')
		);

		$menus['navigation']['ad_hoc']	 = array(
			'text'	 => 'Ad Hoc',
			'url'	 => phpgw::link('/index.php', array('menuaction' => 'controller.uicalendar_planner.ad_hoc')),
			'image'	 => array('property', 'location_1')
		);

		$menus['navigation']['inspection_history'] =  array(
			'text' => lang('inspection history'),
			'url' => phpgw::link('/index.php', array('menuaction' => 'controller.uicalendar_planner.inspection_history')),
			'image' => array('property', 'location_1')
		);

		if ($acl->check('run', Acl::READ, 'admin') || $acl->check('admin', Acl::ADD, 'controller'))
		{
			$menus['admin'] = array(
				'index' => array(
					'text' => lang('Configuration'),
					'url' => phpgw::link('/index.php', array(
						'menuaction' => 'admin.uiconfig.index',
						'appname' => 'controller'
					))
				),
				'acl' => array(
					'text' => lang('Configure Access Permissions'),
					'url' => phpgw::link('/index.php', array(
						'menuaction' => 'preferences.uiadmin_acl.list_acl',
						'acl_app' => 'controller'
					))
				),
				'check_item_status' => array(
					'text' => lang('check item status'),
					'url' => phpgw::link('/index.php', array(
						'menuaction' => 'property.uigeneric.index',
						'type' => 'controller_check_item_status'
					))
				),
				'control_cats' => array(
					'text' => lang('Control area'),
					'url' => phpgw::link('/index.php', array(
						'menuaction' => 'admin.uicategories.index',
						'appname' => 'controller', 'location' => '.control', 'global_cats' => 'true',
						'menu_selection' => 'admin::controller::control_cats'
					))
				),
				'role_at_location' => array(
					'url' => phpgw::link('/index.php', array(
						'menuaction' => 'property.uilocation.responsiblility_role',
						'menu_selection' => 'admin::controller::role_at_location'
					)),
					'text' => lang('role at location'),
					'image' => array('property', 'responsibility_role')
				),
				'controller_document_types' => array(
					'url' => phpgw::link('/index.php', array(
						'menuaction' => 'controller.uidocument.document_types',
						'menu_selection' => 'admin::controller::controller_document_types'
					)),
					'text' => lang('Document types')
				),
				'settings' =>  array(
					'text' => lang('settings'),
					'url' => phpgw::link('/index.php', array('menuaction' => 'controller.uisettings.edit')),
					'image' => array('property', 'location_1')
				),
				'custom_functions' => array(
					'text' => lang('custom functions'),
					'url' => phpgw::link('/index.php', array(
						'menuaction' => 'admin.ui_custom.list_custom_function',
						'appname' => 'controller', 'location' => '.checklist', 'menu_selection' => 'admin::controller::custom_functions'
					))
				),
				'control_category' => array(
					'text' => lang('category'),
					'url' => phpgw::link('/index.php', array('menuaction' => 'controller.uigeneric.index',	'type' => 'control_category'))
				),
			);
		}

		if (isset($userSettings['apps']['preferences']))
		{
			$menus['preferences'] = array(
				// in case of userprefs - need a hook for 'settings'

				array(
					'text' => $translation->translate('Preferences', array(), true),
					'url'	 => phpgw::link('/preferences/section', array(
						'appname' => 'controller',
						'type' => 'user'
					))
				),
				array(
					'text' => $translation->translate('Grant Access', array(), true),
					'url' => phpgw::link('/index.php', array(
						'menuaction' => 'preferences.uiadmin_acl.aclprefs',
						'acl_app' => 'controller'
					))
				)
			);
		}


		Settings::getInstance()->update('flags', ['currentapp' => $incoming_app]);

		return $menus;
	}
}
