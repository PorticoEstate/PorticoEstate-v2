<?php

/**
 * bim - Menus
 *
 * @author Sigurd Nes <sigurdne@online.no>
 * @copyright Copyright (C) 2007,2008 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @package bim
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
 * Menus
 *
 * @package bim
 */
class bim_menu
{

	/**
	 * Get the menus for the bim
	 *
	 * @return array available menus for the current user
	 */
	public function get_menu()
	{
		$flags = Settings::getInstance()->get('flags');
		$userSettings = Settings::getInstance()->get('user');
		$translation = Translation::getInstance();

		$incoming_app = $flags['currentapp'];
		Settings::getInstance()->update('flags', ['currentapp' => 'bim']);
		$acl = Acl::getInstance();
		$menus = array();

		$menus['navbar'] = array(
			'bim' => array(
				'text'	=> lang('bim'),
				'url' => phpgw::link('/index.php', array('menuaction' => "bim.uibim.showModels")),
				'image'	=> array('bim', 'navbar'),
				'order'	=> 35,
				'group'	=> 'facilities management'
			),
		);

		$menus['toolbar'] = array();

		if (
			$acl->check('run', Acl::READ, 'admin')
			|| $acl->check('admin', Acl::ADD, 'bim')
		)
		{


			$menus['admin'] = array(
				'index'	=> array(
					'text'	=> lang('Configuration'),
					'url' => phpgw::link('/index.php', array(
						'menuaction' => 'admin.uiconfig.index',
						'appname' => 'bim'
					))
				),
				'acl'	=> array(
					'text'	=> lang('Configure Access Permissions'),
					'url' => phpgw::link('/index.php', array(
						'menuaction' => 'preferences.uiadmin_acl.list_acl',
						'acl_app' => 'bim'
					))
				)
			);
		}

		if (isset($userSettings['apps']['preferences']))
		{
			$menus['preferences'] = array(
				array(
					'text'	=> $translation->translate('Preferences', array(), true),
					'url' => phpgw::link('/preferences/preferences.php', array(
						'appname' => 'bim',
						'type' => 'user'
					))
				),
				array(
					'text'	=> $translation->translate('Grant Access', array(), true),
					'url' => phpgw::link('/index.php', array(
						'menuaction' => 'bim.uiadmin.aclprefs',
						'acl_app' => 'bim'
					))
				)
			);

			$menus['toolbar'][] = array(
				'text'	=> $translation->translate('Preferences', array(), true),
				'url'	=> phpgw::link('/preferences/preferences.php', array('appname'	=> 'bim')),
				'image'	=> array('bim', 'preferences')
			);
		}

		$menus['navigation'] = array();


		if ($acl->check('.ifc', ACL_READ, 'bim'))
		{
			$menus['navigation']['ifc'] = array(
				'url' => phpgw::link('/index.php', array('menuaction' => 'bim.uiifc.import')),
				'text'		=> lang('IFC'),
				'image'		=> array('bim', 'ifc'),
				'children'	=> array(
					'import'	=> array(
						'url' => phpgw::link('/index.php', array('menuaction' => 'bim.uiifc.import')),
						'text'	=> lang('import'),
						'image'		=> array('bim', 'ifc_import'),
					)
				)
			);
		}

		$menus['navigation']['viewer'] = array(
			'url' => phpgw::link('/index.php', array('menuaction' => 'bim.uiviewer.index')),
			'text'		=> lang('viewer'),
			'image'		=> array('bim', 'ifc'),
		);

		$menus['navigation']['item'] = array(
			'url' => phpgw::link('/index.php', array('menuaction' => 'bim.uiitem.index')),
			'text'	=> lang('BIM Items'),
			'image'	=> array('bim', 'custom'),
			'children'	=> array_merge(array(
				'index'		=> array(
					'url' => phpgw::link('/index.php', array('menuaction' => 'bim.uiitem.index')),
					'text'	=> lang('Register')
				),
				'foo'       => array(
					'url' => phpgw::link('/index.php', array('menuaction' => 'bim.uiitem.foo')),
					'text'	=> lang('Foo')
				),
				'showModels'       => array(
					'url' => phpgw::link('/index.php', array('menuaction' => 'bim.uibim.showModels')),
					'text'	=> lang('Show Models')
				),
				'ifc'       => array(
					'url' => phpgw::link('/index.php', array('menuaction' => 'bim.uiifc.import')),
					'text'	=> lang('Ifc')
				),
				'upload'		=> array(
					'url' => phpgw::link('/index.php', array('menuaction' => 'bim.uibim.upload')),
					'text'	=> lang('Upload Model'),
					'image'	=> array('bim', 'project_tenant_claim')
				)
			))
		);

		Settings::getInstance()->update('flags', ['currentapp' => $incoming_app]);

		return $menus;
	}
}
