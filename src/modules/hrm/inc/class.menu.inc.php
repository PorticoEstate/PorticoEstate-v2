<?php

/**
 * phpGroupWare - HRM: a  human resource competence management system.
 *
 * @author Sigurd Nes <sigurdne@online.no>
 * @copyright Copyright (C) 2003-2005 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @internal Development of this application was funded by http://www.bergen.kommune.no/bbb_/ekstern/
 * @package hrm
 * @subpackage core
 * @version $Id$
 */

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\security\Acl;
use App\modules\phpgwapi\services\Translation;

/**
 * Description
 * @package hrm
 */

class hrm_menu
{
	var $sub;

	var $public_functions = array(
		'links'	=> true,
	);

	function __construct($sub = '')
	{
		$this->sub		= $sub;
	}

	/**
	 * Get the menus for the hrm
	 *
	 * @return array available menus for the current user
	 */
	public function get_menu()
	{

		$userSettings = Settings::getInstance()->get('user');
		$flags = Settings::getInstance()->get('flags');
		$translation = Translation::getInstance();

		$incoming_app			 = $flags['currentapp'];
		Settings::getInstance()->update('flags', ['currentapp' => 'hrm']);
		$acl					 =	Acl::getInstance();
		$menus = array();

		$start_page = 'user';
		if (
			isset($userSettings['preferences']['hrm']['default_start_page'])
			&& $userSettings['preferences']['hrm']['default_start_page']
		)
		{
			$start_page = $userSettings['preferences']['hrm']['default_start_page'];
		}

		$modernStartPages = array(
			'user' => phpgw::link('/hrm/view/users'),
			'place' => phpgw::link('/hrm/view/places'),
			'job' => phpgw::link('/hrm/view/jobs'),
		);
		$navbarUrl = $modernStartPages[$start_page] ?? phpgw::link('/index.php', array('menuaction' => "hrm.ui{$start_page}.index"));

		$menus['navbar'] = array(
			'hrm' => array(
				'text'	=> lang('hrm'),
				'url'	=> $navbarUrl,
				'image'	=> array('hrm', 'navbar'),
				'order'	=> 35,
				'group'	=> 'facilities management'
			),
		);

		$menus['toolbar'] = array();

		//			if ( isset($userSettings['apps']['admin']) )
		if (
			$acl->check('run', Acl::READ, 'admin')
			|| $acl->check('admin', Acl::ADD, 'hrm')
		)
		{
			$menus['admin'] = array(
				'categories'	=> array(
					'text'	=> $translation->translate('Global Categories', array(), true),
					'url'	=> phpgw::link('/index.php', array('menuaction' => 'admin.uicategories.index', 'appname' => 'hrm'))
				),
				'training'	=> array(
					'text'	=> lang('training category'),
					'url'	=> phpgw::link('/hrm/view/categories/training')
				),
				'skill_level'	=> array(
					'text'	=> lang('skill level'),
					'url'	=> phpgw::link('/hrm/view/categories/skill_level')
				),
				'experience'	=> array(
					'text'	=> lang('experience category'),
					'url'	=> phpgw::link('/hrm/view/categories/experience')
				),
				'qualification'	=> array(
					'text'	=> lang('qualification category'),
					'url'	=> phpgw::link('/hrm/view/categories/qualification')
				),
				'acl'	=> array(
					'text'	=> lang('Configure Access Permissions'),
					'url'	=> phpgw::link('/index.php', array('menuaction' => 'preferences.uiadmin_acl.list_acl', 'acl_app' => 'hrm'))
				)
			);
		}

		if (isset($userSettings['apps']['preferences']))
		{
			$menus['preferences'] = array(
				array(
					'text'	=> $translation->translate('Preferences', array(), true),
					'url'	=> phpgw::link('/preferences/section', array('appname' => 'hrm', 'type' => 'user'))
				),
				array(
					'text'	=> $translation->translate('Grant Access', array(), true),
					'url'	=> phpgw::link('/index.php', array('menuaction' => 'preferences.uiadmin_acl.aclprefs', 'acl_app' => 'hrm'))
				)
			);

			$menus['toolbar'][] = array(
				'text'	=> $translation->translate('Preferences', array(), true),
				'url'	=> phpgw::link('/preferences/preferences.php', array('appname'	=> 'hrm')),
				'image'	=> array('hrm', 'preferences')
			);
		}
		$job_children = array(
			'job_type'	=> array(
				'text'	=> lang('Job type'),
				'url'	=> phpgw::link('/hrm/view/jobs')
			),
			'organisation'	=> array(
				'text'	=> lang('Organisation'),
				'url'	=> phpgw::link('/hrm/view/jobs/hierarchy')
			)
		);

		$menus['navigation'] = array(
			'user'	=> array(
				'text'	=> lang('User'),
				'url'	=> phpgw::link('/hrm/view/users')
			),
			'job'	=> array(
				'text'	=> lang('Job type'),
				'url'	=> phpgw::link('/hrm/view/jobs'),
				'children' => $job_children
			),
			'place'	=> array(
				'text'	=> lang('PLace'),
				'url'	=> phpgw::link('/hrm/view/places')
			)
		);
		Settings::getInstance()->update('flags', ['currentapp' => $incoming_app]);
		return $menus;
	}

	function links()
	{
		$userSettings = Settings::getInstance()->get('user');
		$flags = Settings::getInstance()->get('flags');
		
		if (!isset($userSettings['preferences']['hrm']['horisontal_menus']) || $userSettings['preferences']['hrm']['horisontal_menus'] == 'no')
		{
			return;
		}
		phpgwapi_xslttemplates::getInstance()->add_file(array('menu'));
		$menu_brutto = execMethod('hrm.menu.get_menu');
		$selection = explode('::', $flags['menu_selection']);
		$level = 0;
		$menu['navigation'] = $this->get_sub_menu($menu_brutto['navigation'], $selection, $level);
		return $menu;
	}

	function get_sub_menu($children = array(), $selection = array(), $level = '')
	{
		$level++;
		$i = 0;
		$menu = array();
		foreach ($children as $key => $vals)
		{
			$menu[] = $vals;
			if ($key == $selection[$level])
			{
				$menu[$i]['this'] = true;
				if (isset($menu[$i]['children']))
				{
					$menu[$i]['children'] = $this->get_sub_menu($menu[$i]['children'], $selection, $level);
				}
			}
			else
			{
				if (isset($menu[$i]['children']))
				{
					unset($menu[$i]['children']);
				}
			}
			$i++;
		}
		return $menu;
	}
}
