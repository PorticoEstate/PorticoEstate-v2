<?php

/**
 * phpGroupWare - SMS:
 *
 * @author Sigurd Nes <sigurdne@online.no>
 * @copyright Copyright (C) 2003-2005 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @internal Development of this application was funded by http://www.bergen.kommune.no/bbb_/ekstern/
 * @package sms
 * @subpackage core
 * @version $Id$
 */

use App\modules\phpgwapi\controllers\Locations;
use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\security\Acl;
use App\modules\phpgwapi\services\Translation;
/**
 * Description
 * @package sms
 */
class sms_menu
{

	var $sub;
	var $public_functions = array(
		'links' => true,
	);

	function __construct($sub = '')
	{
		if (!$sub)
		{
			$this->sub = $sub;
		}
	}

	/**
	 * Get the menus for the sms
	 *
	 * @return array available menus for the current user
	 */
	public function get_menu()
	{
		$location_obj = new Locations();
		$flags = Settings::getInstance()->get('flags');
		$userSettings = Settings::getInstance()->get('user');
		$translation = Translation::getInstance();

		$incoming_app = $flags['currentapp'];
		Settings::getInstance()->update('flags', ['currentapp' => 'sms']);

		$acl = Acl::getInstance();
		$menus = array();

		$start_page = 'sms.index';
		if (isset($userSettings['preferences']['sms']['default_start_page']) && $userSettings['preferences']['sms']['default_start_page'])
		{
			$start_page = $userSettings['preferences']['sms']['default_start_page'];
		}

		// Inbox and outbox now have modern Slim/Twig pages; everything else still uses menuaction.
		if ($start_page === 'sms.index')
		{
			$navbar_url = phpgw::link('/sms/view/inbox');
		}
		elseif ($start_page === 'uisms.outbox')
		{
			$navbar_url = phpgw::link('/sms/view/outbox');
		}
		else
		{
			$navbar_url = phpgw::link('/index.php', array('menuaction' => "sms.ui{$start_page}"));
		}

		$menus['navbar'] = array(
			'sms' => array(
				'text' => lang('sms'),
				'url' => $navbar_url,
				'image' => array('sms', 'navbar'),
				'order' => 35,
				'group' => 'facilities management'
			),
		);

		$menus['toolbar'] = array();
		if ($acl->check('run', Acl::READ, 'admin'))
		{
			$menus['admin'] = array(
				'index'	 => array(
					'text'		 => lang('Configuration'),
					'url'		 => phpgw::link('/index.php', array(
						'menuaction' => 'admin.uiconfig.index',
						'appname'	 => 'sms'
					)),
				),
				'customconfig' => array(
					'text' => lang('custom config'),
					'nav_location' => 'navbar#' . $location_obj->get_id('sms', 'run'),
					'url' => phpgw::link('/index.php', array(
						'menuaction' => 'admin.uiconfig2.index',
						'location_id' => $location_obj->get_id('sms', 'run')
					))
				),
				'refresh' => array(
					'text' => lang('Daemon manual refresh'),
					'url' => phpgw::link('/index.php', array('menuaction' => 'sms.uisms.daemon_manual'))
				),
				'acl' => array(
					'text' => $translation->translate('Configure Access Permissions', array(), true),
					'url' => phpgw::link('/index.php', array(
						'menuaction' => 'preferences.uiadmin_acl.list_acl',
						'acl_app' => 'sms'
					))
				)
			);
		}

		if (!empty($userSettings['apps']['preferences']))
		{
			$menus['preferences'] = array(
				array(
					'text' => $translation->translate('Preferences', array(), true),
					'url' => phpgw::link('/preferences/section', array(
						'appname' => 'sms',
						'type' => 'user'
					))
				),
				array(
					'text' => $translation->translate('Grant Access', array(), true),
					'url' => phpgw::link('/index.php', array(
						'menuaction' => 'preferences.uiadmin_acl.aclprefs',
						'acl_app' => 'sms'
					))
				)
			);

			$menus['toolbar'][] = array(
				'text' => $translation->translate('Preferences', array(), true),
				'url' => phpgw::link('/preferences/preferences.php', array('appname' => 'sms')),
				'image' => array('sms', 'preferences')
			);
		}

		$command_children = array(
			'log' => array(
				'text' => lang('log'),
				'url' => phpgw::link('/index.php', array('menuaction' => 'sms.uicommand.log'))
			)
		);

		$menus['navigation'] = array(
			'inbox' => array(
				'text' => lang('Inbox'),
				'url' => phpgw::link('/sms/view/inbox')
			),
			'outbox' => array(
				'text' => lang('Outbox'),
				'url' => phpgw::link('/sms/view/outbox')
			)
		);

		if ($acl->check('.autoreply', Acl::READ, 'sms'))
		{
			$menus['navigation']['autoreply'] = array(
				'text' => lang('Autoreply'),
				'url' => phpgw::link('/index.php', array('menuaction' => 'sms.uiautoreply.index'))
			);
		}
		if ($acl->check('.board', Acl::READ, 'sms'))
		{
			$menus['navigation']['board'] = array(
				'text' => lang('Boards'),
				'url' => phpgw::link('/index.php', array('menuaction' => 'sms.uiboard.index'))
			);
		}
		if ($acl->check('.command', Acl::READ, 'sms'))
		{
			$menus['navigation']['command'] = array(
				'text' => lang('commands'),
				'url' => phpgw::link('/index.php', array('menuaction' => 'sms.uicommand.index')),
				'children' => $command_children
			);
		}
		if ($acl->check('.custom', Acl::READ, 'sms'))
		{
			$menus['navigation']['custom'] = array(
				'text' => lang('Custom'),
				'url' => phpgw::link('/index.php', array('menuaction' => 'sms.uicustom.index'))
			);
		}
		if ($acl->check('.poll', Acl::READ, 'sms'))
		{
			$menus['navigation']['poll'] = array(
				'text' => lang('Polls'),
				'url' => phpgw::link('/index.php', array('menuaction' => 'sms.uipoll.index'))
			);
		}

		Settings::getInstance()->update('flags', ['currentapp' => $incoming_app]);
		return $menus;
	}

	function links()
	{
		$userSettings = Settings::getInstance()->get('user');
		$flags = Settings::getInstance()->get('flags');

		if (!isset($userSettings['preferences']['sms']['horisontal_menus']) || $userSettings['preferences']['sms']['horisontal_menus'] == 'no')
		{
			return;
		}
		phpgwapi_xslttemplates::getInstance()->add_file(array('menu'));
		$menu_brutto = execMethod('sms.menu.get_menu');
		$selection = explode('::', $flags['menu_selection']);
		$level = 0;
		$menu['navigation'] = $this->get_sub_menu($menu_brutto['navigation'], $selection, $level);
		return $menu;
	}

	function get_sub_menu($children = array(), $selection = array(), $level = '')
	{
		$level++;
		$i = 0;
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
