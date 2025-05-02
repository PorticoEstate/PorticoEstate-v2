<?php

/**
 * phpGroupWare - Administration - ACL manager logic
 *
 * @author Dave Hall <skwashd@phpgroupware.org>
 * @author Others <unknown>
 * @copyright Copyright (C) 2007-2008 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.fsf.org/licenses/gpl.html GNU General Public License
 * @package phpgroupware
 * @subpackage admin
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

use App\helpers\Template;
use App\modules\phpgwapi\security\Acl;
use App\modules\phpgwapi\controllers\Accounts\phpgwapi_group;
use App\modules\phpgwapi\controllers\Accounts\phpgwapi_user;
use App\modules\phpgwapi\controllers\Accounts\Accounts;
use App\modules\phpgwapi\services\Cache;
use App\modules\phpgwapi\services\Hooks;
use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\controllers\Locations;
use App\modules\phpgwapi\services\Twig;


/*
 * phpGroupWare - Administration - ACL manager logic
 *
 * @package phpgroupware
 * @subpackage admin
 *
 * @internal FIXME this is shitty insecure code - someone needs to fix this
 */

class admin_uiaclmanager
{
	var $account_id;

	/**
	 *@var object $_boacl business logic
	 */
	protected $_boacl;

	/**
	 * @var array $public_functions publicly available methods class
	 */
	public $public_functions = array(
		'list_apps'				=> true,
		'access_form'			=> true,
		'account_list'			=> true,
		'list_addressmasters'	=> true,
		'edit_addressmasters'	=> true,
		'accounts_popup'		=> true,
	);

	private $userSettings;
	private $serverSettings;
	private $flags;
	private $acl;
	private $accounts;
	private $phpgwapi_common;
	private $hooks;
	private $locations;


	/**
	 * Constructor
	 *
	 * @return void
	 */
	public function __construct()
	{
		$this->userSettings = Settings::getInstance()->get('user');
		$this->serverSettings = Settings::getInstance()->get('server');
		$this->flags = Settings::getInstance()->get('flags');
		$this->acl = Acl::getInstance();
		$this->accounts = new Accounts();
		$this->locations = new Locations();

		$this->account_id = Sanitizer::get_var(
			'account_id',
			'int',
			'GET',
			$this->userSettings['account_id']
		);

		if (
			!$this->account_id
			|| $this->acl->check('account_access', 64, 'admin')
		)
		{
			phpgw::redirect_link('/index.php');
		}

		$this->_boacl = CreateObject('admin.boaclmanager');

		$this->flags['menu_selection'] = 'admin::admin::addressmasters';
		Settings::getInstance()->set('flags', $this->flags);
		$this->phpgwapi_common = new phpgwapi_common();
		$this->hooks = new Hooks();
	}

	/**
	 * Prepare the common template header
	 *
	 * @return void
	 */
	protected function _common_header()
	{
		$this->phpgwapi_common->phpgw_header();
	}

	/**
	 * This does something - ask ceb and jengo what - they wrote it
	 *
	 * @return void
	 */
	public function list_apps()
	{
		$this->_common_header();

		$this->hooks->process('acl_manager', array('preferences'));

		// Initialize variables for template
		$rows = '';

		if (is_array($GLOBALS['acl_manager']))
		{
			foreach ($GLOBALS['acl_manager'] as $app => $locations)
			{
				$icon = $this->phpgwapi_common->image($app, array('navbar.gif', $app . '.gif'));
				
				// Prepare app row data
				$appData = [
					'icon_backcolor' => $GLOBALS['phpgw_info']['theme']['row_off'],
					'link_backcolor' => $GLOBALS['phpgw_info']['theme']['row_off'],
					'app_name'       => lang($GLOBALS['phpgw_info']['navbar'][$app]['title']),
					'a_name'         => $app,
					'app_icon'       => $icon
				];

				// Render app row with Twig
				if ($icon) {
					$rows .= Twig::getInstance()->renderBlock('acl_applist.html.twig', 'app_row', $appData, 'admin');
				} else {
					$rows .= Twig::getInstance()->renderBlock('acl_applist.html.twig', 'app_row_noicon', $appData, 'admin');
				}

				if (is_array($locations))
				{
					foreach ($locations as $loc => $value)
					{
						$total_rights = 0;
						
						if (is_array($value['rights']))
						{
							foreach ($value['rights'] as $k => $v)
							{
								$total_rights += $v;
							}
						}
						reset($value['rights']);

						// If all of there rights are denied, then they shouldn't even see the option
						if ($total_rights != $this->acl->get_rights($loc, $app))
						{
							$link_values = array(
								'menuaction' => 'admin.uiaclmanager.access_form',
								'location'   => $loc,
								'acl_app'    => $app,
								'account_id' => $this->userSettings['account_id']
							);

							// Prepare link row data
							$linkData = [
								'link_location' => phpgw::link('/index.php', $link_values),
								'lang_location' => lang($value['name'])
							];
							
							// Render link row with Twig
							$rows .= Twig::getInstance()->renderBlock('acl_applist.html.twig', 'link_row', $linkData, 'admin');
						}
					}
				}

				// Add spacer row
				$rows .= Twig::getInstance()->renderBlock('acl_applist.html.twig', 'spacer_row', [], 'admin');
			}
		}

		// Prepare final template data
		$templateData = [
			'lang_header' => lang('ACL Manager'),
			'rows'        => $rows
		];

		// Render the template with Twig
		echo Twig::getInstance()->renderBlock('acl_applist.html.twig', 'list', $templateData, 'admin');
	}

	/**
	 * This does something - don't ask me what, but I suspect it involves rendering a form
	 *
	 * @return void
	 */
	public function access_form()
	{
		$acl_app	= Sanitizer::get_var('acl_app');
		$location	= Sanitizer::get_var('location');
		$account_id	= Sanitizer::get_var('account_id', 'int');
		$acl_man	= Sanitizer::get_var('acl_manager');

		$acl_manager = $acl_man[$acl_app][$location];

		$this->hooks->single('acl_manager', $acl_app);

		$this->_common_header();

		$acc = createobject('phpgwapi.accounts', $account_id);
		$afn = (string) $this->accounts->get($account_id);

		$link_values = array(
			'menuaction' => 'admin.boaclmanager.submit',
			'acl_app'    => $acl_app,
			'location'   => Sanitizer::get_var('location', 'string'),
			'account_id' => $account_id
		);

		$this->acl->set_account_id($account_id);
		$this->acl->read();
		$grants = $this->acl->get_rights($location, $acl_app);

		$select = <<<HTML
				<select name="acl_rights[]" multiple size="7">
HTML;

		foreach ($acl_manager['rights'] as $name => $value)
		{
			if (!$this->acl->check($location, $value, $acl_app))
			{
				$s = '';
				$name = lang($name);

				if ($grants & $value)
				{
					$s = ' selected';
				}

				$select .= <<<HTML
					<option value="{$value}{$s}">{$name}</option>
HTML;
			}
		}
		$select .= <<<HTML
				</select>
HTML;

		// Prepare template data
		$templateData = [
			'lang_message'   => lang('Check items to <b>%1</b> to %2 for %3', $acl_manager['name'], $acl_app, $afn),
			'form_action'    => phpgw::link('/index.php', $link_values),
			'lang_title'     => lang('ACL Manager'),
			'select_values'  => $select,
			'lang_submit'    => lang('submit'),
			'lang_cancel'    => lang('cancel')
		];

		// Render the template with Twig
		echo Twig::getInstance()->renderBlock('acl_manager_form.html.twig', 'form', $templateData, 'admin');
	}

	/**
	 * List current addressmasters
	 *
	 * @return void
	 */
	public function list_addressmasters()
	{
		$this->flags['xslt_app'] = true;

		$link_data = array(
			'menuaction'	=> 'admin.uiaclmanager.edit_addressmasters',
			'account_id'	=> $this->userSettings['account_id']
		);

		if (Sanitizer::get_var('edit', 'bool', 'POST'))
		{
			phpgw::redirect_link('/index.php', $link_data);
		}

		if (Sanitizer::get_var('done', 'bool', 'POST'))
		{
			phpgw::redirect_link('/index.php', array('menuaction' => 'admin.uimainscreen.mainscreen'));
		}

		$this->flags['app_header'] = lang('admin') . ': ' . lang('list addressmasters');
		Settings::getInstance()->set('flags', $this->flags);

		phpgwapi_xslttemplates::getInstance()->add_file('addressmaster');

		try
		{
			if ($this->locations->get_id('addressbook', 'addressmaster') == 0)
			{
				$this->locations->add('addressmaster', 'Address Master', 'addressbook');
			}
		}
		catch (Exception $e)
		{
			$this->locations->add('addressmaster', 'Address Master', 'addressbook');
		}

		$admins = $this->_boacl->list_addressmasters();

		//_debug_array($admins);
		//exit;

		//initialize the arrays
		$users = array();
		$groups = array();
		if (is_array($admins))
		{
			foreach ($admins as $admin)
			{
				if ($admin['lastname'] != 'Group')
				{
					$users[] = array(
						'lid'		=> $admin['lid'],
						'firstname' => $admin['firstname'],
						'lastname'	=> $admin['lastname']
					);
				}
				else if ($admin['lastname'] == 'Group')
				{
					$groups[] = array(
						'lid'		=> $admin['lid'],
						'firstname' => $admin['firstname'],
						'lastname'	=> $admin['lastname']
					);
				}
			}
		}

		//_debug_array($users);
		//exit;

		$link_data['menuaction'] = 'admin.uiaclmanager.list_addressmasters';

		$data = array(
			'sort_lid'				=> lang('loginid'),
			'sort_firstname'		=> lang('firstname'),
			'sort_lastname'			=> lang('lastname'),
			'sort_name'				=> lang('name'),
			'lang_users'			=> lang('users'),
			'lang_groups'			=> lang('groups'),
			'addressmaster_user'	=> $users,
			'addressmaster_group'	=> $groups,
			'lang_edit'				=> lang('edit'),
			'lang_done'				=> lang('done'),
			'action_url'			=> phpgw::link('/index.php', $link_data)
		);
		phpgwapi_xslttemplates::getInstance()->set_var('phpgw', array('addressmaster_list' => $data));
	}

	/**
	 * Render the accounts popup widget
	 *
	 * @return string html snippet
	 */
	public function accounts_popup()
	{
		echo 'Hent koden fra den gamle versjonen av phpGroupWare og legg den inn her.';
		exit;
		return $GLOBALS['phpgw']->accounts_popup->render('admin_acl');
	}

	/**
	 * Render for for editting addressmasters
	 *
	 * @return void
	 */
	public function edit_addressmasters()
	{
		$this->flags['xslt_app'] = true;

		$link_data = array(
			'menuaction' 	=> 'admin.uiaclmanager.list_addressmasters',
			'account_id'	=> $this->userSettings['account_id']
		);

		if (Sanitizer::get_var('save', 'bool', 'POST'))
		{
			$account_addressmaster = Sanitizer::get_var('account_addressmaster', 'string', 'POST', array());
			$group_addressmaster = Sanitizer::get_var('group_addressmaster', 'int', 'POST', array());

			$error = array(); //$this->_boacl->check_values($account_addressmaster, $group_addressmaster);
			if (count($error))
			{
				$error_message = $this->phpgwapi_common->error_list($error);
			}
			else
			{
				$this->_boacl->edit_addressmasters($account_addressmaster, $group_addressmaster);
				phpgw::redirect_link('/index.php', $link_data);
			}
		}

		if (Sanitizer::get_var('cancel', 'bool', 'POST'))
		{
			phpgw::redirect_link('/index.php', $link_data);
		}

		$this->flags['app_header'] = lang('admin') . ': ' . lang('edit addressmaster list');
		Settings::getInstance()->set('flags', $this->flags);

		phpgwapi_xslttemplates::getInstance()->add_file('addressmaster');

		$popwin_user = array();
		$select_user = array();
		if (
			isset($this->userSettings['preferences']['common']['account_selection'])
			&& $this->userSettings['preferences']['common']['account_selection'] == 'popup_xxxx'
		) // FIXME 'popup is broken'
		{
			$usel = $this->_boacl->list_addressmasters();
			foreach ($usel as $acc)
			{
				$user_list[] = array(
					'account_id'	=> $acc['account_id'],
					'select_value'	=> 'yes',
					'fullname'		=> (string) $this->accounts->get($acc['lid'])
				);
			}

			$popwin_user = array(
				'url'				=> phpgw::link(
					'/index.php',
					array('menuaction' => 'admin.uiaclmanager.accounts_popup'),
					true
				),
				'width'				=> '800',
				'height'			=> '600',
				'lang_open_popup'	=> lang('open popup window'),
				'user_list'			=> $user_list
			);
		}
		else
		{
			$app_user = (array) $this->acl->get_ids_for_location('run', 1, 'addressbook');

			$add_users = array(
				'users'		=> array(),
				'groups'	=> array()
			);

			if (is_array($app_user))
			{
				$add_users = $this->accounts->return_members($app_user);
			}
			$add_users['groups'] = $this->accounts->get_list('groups');

			$usel = $this->_boacl->get_addressmaster_ids();

			//_debug_array($usel);
			$user_list = array();
			foreach ($add_users['users'] as $user)
			{
				$select_value = '';
				if (is_array($usel) && in_array($user, $usel))
				{
					$select_value = 'yes';
				}

				$user_list[] = array(
					'account_id'	=> $user,
					'select_value'	=> $select_value,
					'fullname'		=> $this->phpgwapi_common->grab_owner_name($user)
				);
			}

			$group_list = array();
			if (is_array($add_users['groups']) && count($add_users['groups']))
			{
				foreach ($add_users['groups'] as $group)
				{
					$select_value = '';
					if (is_array($usel) && in_array($group, $usel))
					{
						$select_value = 'yes';
					}

					$group_list[] = array(
						'account_id'	=> $group->id,
						'select_value'	=> $select_value,
						'fullname'	=> lang('%1 group', $group->firstname)
					);
				}
			}

			$select_user = array(
				'lang_select_users'		=> lang('Select users'),
				'lang_select_groups'	=> lang('Select groups'),
				'group_list'			=> $group_list,
				'user_list'				=> $user_list
			);
		}

		$link_data['menuaction'] = 'admin.uiaclmanager.edit_addressmasters';

		$data = array(
			'lang_select_addressmasters'	=> lang('Select addressmasters'),
			'lang_save'						=> lang('save'),
			'lang_cancel'					=> lang('cancel'),
			'action_url'					=> phpgw::link('/index.php', $link_data),
			'popwin_user'					=> $popwin_user,
			'select_user'					=> $select_user
		);
		phpgwapi_xslttemplates::getInstance()->set_var('phpgw', array('addressmaster_edit' => $data));
	}
}
