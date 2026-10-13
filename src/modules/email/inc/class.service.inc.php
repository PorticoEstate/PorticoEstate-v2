<?php
/**
 * EMail - Service
 *
 * @author Philipp Kamps <pkamps@probusiness.de>
 * @author Dave Hall <skwashd@phpgroupware.org>
 * @copyright Copyright (C) 2003-2007 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @package email
 * @version $Id$
 */


/**
 * Service
 *
 * @package email
 */	
use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\Translation;

class email_service
{
	/**
	 * Get the menus for the email module
	 * 
	 * @return array available menus for the current user
	 */
	public function get_menu()
	{
		$menu = [];
		$userSettings = (array) Settings::getInstance()->get('user');
		$translation = Translation::getInstance();

		$menu['navbar'] = array
		(
			'email'	=> array
			(
				'text'	=> $translation->translate('Email', [], false, 'email'),
				'url'	=> phpgw::link('/index.php', array('menuaction'=> 'email.uiindex.index') ),
				'image'	=> array('email', 'navbar'),
				'order'	=> 6,
				'group'	=> 'office'
			)
		);


		if (!empty($userSettings['apps']['admin']))
		{
			$menu['admin'] = array();
			$menu['admin'][] = array
			(
				'text'	=> $translation->translate('Site Configuration', [], false, 'email'),
				'url'	=> phpgw::link('/index.php', array('menuaction' => 'admin.uiconfig.index', 'appname' => 'email') )
			);
		}

		$menu['toolbar'] = array
		(
			array
			(
				// this is a bit of a hack, but it means that we can degrade gracefully
				'url'	=> phpgw::link('/index.php', array('menuaction' => 'email.uicompose.compose')) . '" onclick="window.open(\'' . phpgw::link('/index.php', array('menuaction' => 'email.uicompose.compose')) . '\'); return false;"',
				'text'	=> $translation->translate('New', [], false, 'email'),
				'image'	=> array('email', 'new')
			),
			array
			(
				'url'	=> phpgw::link('/index.php', array('menuaction' => 'email.uisearch.form')),
				'text'	=> $translation->translate('Search', [], false, 'email'),
				'image'	=> array('email', 'search')
			),
			array
			(
				'url'	=> phpgw::link('/index.php', array('menuaction' => 'email.uifilters.filters_list') ),
				'text'	=> $translation->translate('Filters', [], false, 'email'),
				'image'	=> array('email', 'filters')
			)
		);

		if (!empty($userSettings['apps']['preferences']))
		{
			$menu['preferences'] = array
			(
				array
				(
					'text'	=> $translation->translate('EMail Preferences', [], false, 'email'),
					'url'	=> phpgw::link('/index.php',array('menuaction'=> 'email.uipreferences.preferences'))
				),
				array
				(
					'text'	=> $translation->translate('Extra EMail Accounts', [], false, 'email'),
					'url'	=> phpgw::link('/index.php',array('menuaction'=> 'email.uipreferences.ex_accounts_list'))
				),
				array
				(
					'text'	=> $translation->translate('EMail Filters', [], false, 'email'),
					'url'	=> phpgw::link('/index.php',array('menuaction'=> 'email.uifilters.filters_list'))
				)
			);

			$menu['toolbar'][] = array
			(
				'text'	=> $translation->translate('Preferences', [], false, 'email'),
				'url'	=> phpgw::link('/index.php',array('menuaction'=> 'email.uipreferences.preferences'))
			);

			$menu['toolbar'][] = array
			(
				'text'	=> $translation->translate('Accounts', [], false, 'email'),
				'url'	=> phpgw::link('/index.php',array('menuaction'=> 'email.uipreferences.ex_accounts_list'))
			);
		}

		$menu['navigation'] = $menu['toolbar'];

		$menu['folders'] = [];
		return $menu;
	}


	/**
	 * The method provides the list of email folders
	 * 
	 * @return array
	 * @access public
	 */
	function getFolderContent()
	{
		return array('content', 'this is being phased out');
	}

}
