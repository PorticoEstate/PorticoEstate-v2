<?php

/**
 * phpGroupWare - property: a Facilities Management System.
 *
 * @author Sigurd Nes <sigurdne@online.no>
 * @copyright Copyright (C) 2003,2004,2005,2006,2007,2008,2009,2010,2011,2012 Free Software Foundation, Inc. http://www.fsf.org/
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
 * @internal Development of this application was funded by http://www.bergen.kommune.no/bbb_/ekstern/
 * @package property
 * @subpackage core
 * @version $Id$
 */

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\AsyncService;
use App\modules\phpgwapi\controllers\Accounts\Accounts;
use App\modules\phpgwapi\security\Acl;
use App\modules\phpgwapi\services\Config;
use App\modules\phpgwapi\services\Cache;

/**
 * Description
 * @package property
 */
phpgw::import_class('phpgwapi.datetime');

class property_bocommon
{

	var $socommon, $account, $async, $type_id, $uicols, $cols_return, $cols_extra, $cols_return_lookup, $acl_read;
	var $start;
	var $query;
	var $filter;
	var $sort;
	var $order;
	var $cat_id;
	var $district_id;
	var $xsl_rootdir;
	var $userSettings;
	var $serverSettings;
	var $flags;
	var $accounts;
	var $phpgwapi_common;
	protected $join;
	protected $left_join;
	protected $like;
	var $public_functions = array(
		'confirm_session'	 => true,
		'get_vendor_email'	 => true
	);

	function __construct()
	{
		//_debug_array($bt = debug_backtrace());
		$this->userSettings = Settings::getInstance()->get('user');
		$this->serverSettings = Settings::getInstance()->get('server');
		$this->flags =	Settings::getInstance()->get('flags');

		$this->socommon	 = CreateObject('property.socommon');
		$this->account	= isset($this->userSettings['account_id']) ? (int)$this->userSettings['account_id'] : -1;
		$this->accounts = new Accounts();
		$this->phpgwapi_common = new \phpgwapi_common();



		$this->async =		AsyncService::getInstance();

		$this->join		 = $this->socommon->join;
		$this->left_join = $this->socommon->left_join;
		$this->like		 = $this->socommon->like;

		$template_set = isset($this->serverSettings['template_set']) ? $this->serverSettings['template_set'] : 'base';
		$this->xsl_rootdir = PHPGW_SERVER_ROOT . "/property/templates/{$template_set}";
	}

	function check_perms($rights, $required)
	{
		return ($rights & $required);
	}

	/**
	 *
	 * @param integer $owner_id
	 * @param array $grants
	 * @param integer $required
	 * @return bool
	 */
	function check_perms2($owner_id, $grants, $required)
	{
		if (isset($grants['accounts'][$owner_id]) && ($grants['accounts'][$owner_id] & $required))
		{
			return true;
		}

		$equalto = $this->accounts->membership($owner_id);
		foreach ($grants['groups'] as $group => $_right)
		{
			if (isset($equalto[$group]) && ($_right & $required))
			{
				return true;
			}
		}

		return false;
	}

	function create_preferences($app = '', $user_id = '')
	{
		return $this->socommon->create_preferences($app, $user_id);
	}

	function get_lookup_entity($location = '')
	{
		return $this->socommon->get_lookup_entity($location);
	}

	function get_start_entity($location = '')
	{
		return $this->socommon->get_start_entity($location);
	}

	function msgbox_data($receipt)
	{
		$msgbox_data_error	 = array();
		$msgbox_data_message = array();
		if (isset($receipt['error']) and is_array($receipt['error']))
		{
			foreach ($receipt['error'] as $dummy => $error)
			{
				$msgbox_data_error[$error['msg']] = false;
			}
		}

		if (isset($receipt['message']) and is_array($receipt['message']))
		{
			foreach ($receipt['message'] as $dummy => $message)
			{
				$msgbox_data_message[$message['msg']] = true;
			}
		}

		$msgbox_data = array_merge($msgbox_data_error, $msgbox_data_message);

		return $msgbox_data;
	}

	function confirm_session()
	{
		$sessions = \App\modules\phpgwapi\security\Sessions::getInstance();

		if ($sessions->verify())
		{
			header('Content-Type: application/json');
			echo json_encode(array('sessionExpired' => false));
			$this->phpgwapi_common->phpgw_exit();
		}
	}

	function date_to_timestamp($date = array())
	{
		return phpgwapi_datetime::date_to_timestamp($date);
	}

	function select_multi_list($selected = '', $input_list = array())
	{
		$j = 0;
		if (isset($input_list) and is_array($input_list))
		{
			foreach ($input_list as $entry)
			{
				$output_list[$j]['id']	 = $entry['id'];
				$output_list[$j]['name'] = $entry['name'];

				if (isset($selected) && is_array($selected))
				{
					for ($i = 0; $i < count($selected); $i++)
					{
						if ($selected[$i] == $entry['id'])
						{
							$output_list[$j]['selected'] = 'selected';
						}
					}
				}
				$j++;
			}
		}
		return $output_list;
	}

	function select_list($selected = '', $list = array())
	{
		if (is_array($list))
		{
			foreach ($list as &$entry)
			{
				if ((string)$entry['id'] === (string)$selected) // in case the value is '0'
				{
					$entry['selected'] = 1;
					break;
				}
			}
			return $list;
		}
	}

	function get_user_list($format = '', $selected = '', $extra = '', $default = '', $start = '', $sort = 'ASC', $order = 'account_lastname', $query = '', $offset = '', $enabled = false)
	{
		$order = $order ? $order : 'account_lastname';

		switch ($format)
		{
			case 'select':
				phpgwapi_xslttemplates::getInstance()->add_file(array('user_id_select'), $this->xsl_rootdir);
				break;
			case 'filter':
				phpgwapi_xslttemplates::getInstance()->add_file(array('user_id_filter'), $this->xsl_rootdir);
				break;
		}

		if (!$selected && $default)
		{
			$selected = $default;
		}

		$all_users = array();

		if (is_array($extra))
		{
			foreach ($extra as $extra_user)
			{
				$all_users[] = array(
					'account_id'		 => $extra_user,
					'account_firstname'	 => lang($extra_user)
				);
			}
		}


		$users = $this->accounts->get_list('accounts', $start, $sort, $order, $query, $offset);

		if (is_array($users))
		{
			foreach ($users as $user)
			{
				if (($enabled && $user->enabled) || !$enabled)
				{
					$all_users[] = array(
						'user_id'	 => $user->id,
						'name'		 => $user->__toString(),
					);
				}
			}
		}

		if (count($all_users) > 0)
		{
			foreach ($all_users as $user)
			{
				$sel_user = '';
				if ($user['user_id'] == $selected)
				{
					$user_list[] = array(
						'user_id'	 => $user['user_id'],
						'name'		 => $user['name'],
						'selected'	 => 'selected'
					);
				}
				else
				{
					$user_list[] = array(
						'user_id'	 => $user['user_id'],
						'name'		 => $user['name'],
					);
				}
			}
		}
		//_debug_array($user_list);
		return $user_list;
	}

	function get_group_list($format = '', $selected = '', $start = '', $sort = '', $order = '', $query = '', $offset = '')
	{
		switch ($format)
		{
			case 'select':
				phpgwapi_xslttemplates::getInstance()->add_file(array('group_select'), $this->xsl_rootdir);
				break;
			case 'filter':
				phpgwapi_xslttemplates::getInstance()->add_file(array('group_filter'), $this->xsl_rootdir);
				break;
		}

		$users = $this->accounts->get_list('groups', $start, $sort, $order, $query, $offset);
		if (isset($users) and is_array($users))
		{
			foreach ($users as $user)
			{
				$sel_user = '';
				if ($user->id == $selected)
				{
					$sel_user = 'selected';
				}

				$user_list[] = array(
					'id'		 => $user->id,
					'name'		 => $user->firstname,
					'selected'	 => $sel_user
				);
			}
		}

		$user_count = count($user_list);
		for ($i = 0; $i < $user_count; $i++)
		{
			if ($user_list[$i]['selected'] != 'selected')
			{
				unset($user_list[$i]['selected']);
			}
		}

		//_debug_array($user_list);
		return $user_list;
	}

	function get_user_list_right($rights, $selected = '', $acl_location = '', $extra = '', $default = '')
	{
		if (!$selected && $default)
		{
			$selected = $default;
		}

		if (!is_array($rights))
		{
			$rights = array($rights);
		}

		if (is_array($extra))
		{
			foreach ($extra as $extra_user)
			{
				$users_extra[] = array(
					'account_lid'		 => $extra_user,
					'account_firstname'	 => lang($extra_user),
					'account_lastname'	 => ''
				);
			}
		}

		$right_index = 0;

		foreach ($rights as $right)
		{
			$right_index += $right;
		}

		$acl_userlist_name = "acl_userlist_{$right_index}_{$acl_location}";

		reset($rights);

		$acl = Acl::getInstance();

		if (!$users = $this->socommon->fm_cache($acl_userlist_name))
		{
			$users_gross = array();
			foreach ($rights as $right)
			{
				$users_gross = array_merge($users_gross, $acl->get_user_list_right($right, $acl_location));
			}

			$accounts	 = array();
			$users		 = array();

			foreach ($users_gross as $entry => $user)
			{

				if (!isset($accounts[$user['account_id']]))
				{
					$users[] = $user;
				}
				$accounts[$user['account_id']] = true;
			}
			unset($users_gross);
			unset($accounts);

			foreach ($users as $key => $row)
			{
				$account_lastname[$key]	 = $row['account_lastname'];
				$account_firstname[$key] = $row['account_firstname'];
			}

			// Sort the data with account_lastname ascending, account_firstname ascending
			// Add $data as the last parameter, to sort by the common key
			if ($users)
			{
				array_multisort($account_lastname, SORT_ASC, $account_firstname, SORT_ASC, $users);
			}

			$this->socommon->fm_cache('acl_userlist_' . $rights[0] . '_' . $acl_location, $users);
		}

		if (isset($users_extra) && is_array($users_extra) && is_array($users))
		{
			$users = array_merge($users_extra, $users);
		}

		$user_list		 = array();
		$selected_found	 = false;

		foreach ($users as $user)
		{
			if ($user['account_lid'] == $selected)
			{
				$user_list[] = array(
					'lid'		 => $user['account_lid'],
					'firstname'	 => $user['account_firstname'],
					'lastname'	 => $user['account_lastname'],
					'selected'	 => 'selected'
				);
			}
			else
			{
				$user_list[] = array(
					'lid'		 => $user['account_lid'],
					'firstname'	 => $user['account_firstname'],
					'lastname'	 => $user['account_lastname'],
				);
			}

			if (!$selected_found)
			{
				$selected_found = $user['account_lid'] == $selected ? true : false;
			}
		}

		foreach ($user_list as &$user)
		{
			$user['id']		 = $user['lid'];
			$user['name']	 = ltrim("{$user['lastname']}, {$user['firstname']}", ', ');
		}
		unset($user);

		if ($selected && !$selected_found)
		{
			$user_id = $this->accounts->name2id($selected);

			$_user = $this->accounts->get($user_id);

			$user_list[] = array(
				'lid'		 => $_user->lid,
				'firstname'	 => $_user->firstname,
				'lastname'	 => $_user->lastname,
				'id'		 => $selected,
				'name'		 => $_user->__toString(),
				'selected'	 => 'selected'
			);
		}

		return $user_list;
	}

	function get_user_list_right2($format = '', $right = '', $selected = '', $acl_location = '', $extra = '', $default = '')
	{
		if (is_array($format)) // i.e: called by ExecMethod()
		{
			$data			 = $format;
			$format			 = isset($data['format']) ? $data['format'] : '';
			$right			 = isset($data['right']) ? $data['right'] : '';
			$selected		 = isset($data['selected']) && is_array($data['selected']) ? $data['selected'][0] : (isset($data['selected']) ? $data['selected'] : '');
			$acl_location	 = isset($data['acl_location']) ? $data['acl_location'] : '';
			$extra			 = isset($data['extra']) ? $data['extra'] : '';
			$default		 = isset($data['default']) ? $data['default'] : '';
		}

		switch ($format)
		{
			case 'select':
				phpgwapi_xslttemplates::getInstance()->add_file(array('user_id_select'), $this->xsl_rootdir);
				break;
			case 'filter':
				phpgwapi_xslttemplates::getInstance()->add_file(array('user_id_filter'), $this->xsl_rootdir);
				break;
		}

		if (!$selected && $default)
		{
			$selected = $default;
		}

		if (isset($extra) and is_array($extra))
		{
			foreach ($extra as $extra_user)
			{
				$users_extra[] = array(
					'account_id'		 => $extra_user,
					'account_firstname'	 => lang($extra_user)
				);
			}
		}

		$acl = Acl::getInstance();

		if (!$users = $this->socommon->fm_cache('acl_userlist_' . $right . '_' . $acl_location))
		{
			$users = $acl->get_user_list_right($right, $acl_location);
			$this->socommon->fm_cache('acl_userlist_' . $right . '_' . $acl_location, $users);
		}

		if ((isset($users_extra) && is_array($users_extra)) && is_array($users))
		{
			foreach ($users as $users_entry)
			{
				array_push($users_extra, $users_entry);
			}
			$users = $users_extra;
		}

		$user_list = array();

		$selected_found = false;
		foreach ($users as $user)
		{
			$name		 = (isset($user['account_lastname']) ? $user['account_lastname'] . ' ' : '') . $user['account_firstname'];
			$user_list[] = array(
				'id'		 => $user['account_id'],
				'name'		 => $name,
				'selected'	 => $user['account_id'] == $selected ? 1 : 0
			);

			if (!$selected_found)
			{
				$selected_found = $user['account_id'] == $selected ? true : false;
			}
		}

		if ($selected && !$selected_found)
		{
			$user_list[] = array(
				'id'		 => $selected,
				'name'		 => $this->accounts->get($selected)->__toString(),
				'selected'	 => 1
			);
		}

		return $user_list;
	}

	function initiate_ui_vendorlookup($data)
	{
		//_debug_array($data);

		if (isset($data['type']) && $data['type'] == 'view')
		{
			phpgwapi_xslttemplates::getInstance()->add_file(array('vendor_view'), $this->xsl_rootdir);
		}
		else
		{
			phpgwapi_xslttemplates::getInstance()->add_file(array('vendor_form'), $this->xsl_rootdir);
		}

		$vendor['value_vendor_id']	 = $data['vendor_id'];
		$vendor['value_vendor_name'] = $data['vendor_name'];

		if (isset($data['vendor_id']) && $data['vendor_id'] && !$data['vendor_name'])
		{
			$contacts = CreateObject('property.sogeneric');
			$contacts->get_location_info('vendor', false);

			$custom						 = createObject('property.custom_fields');
			$vendor_data['attributes']	 = $custom->find('property', '.vendor', 0, '', 'ASC', 'attrib_sort', true, true);

			$vendor_data = $contacts->read_single(array('id' => $data['vendor_id']), $vendor_data);
			if (is_array($vendor_data))
			{
				foreach ($vendor_data['attributes'] as $attribute)
				{
					if ($attribute['name'] == 'org_name')
					{
						$vendor['value_vendor_name'] = $attribute['value'];
						break;
					}
				}
			}
			unset($contacts);
		}

		$vendor['vendor_link']				 = phpgw::link('/index.php', array('menuaction' => 'property.uilookup.vendor'));
		$vendor['lang_vendor']				 = lang('Vendor');
		$vendor['lang_select_vendor_help']	 = lang('click this link to select vendor');
		$vendor['lang_vendor_name']			 = lang('Vendor Name');
		$vendor['required']					 = isset($data['required']) && $data['required'] ? true : false;
		//_debug_array($vendor);
		return $vendor;
	}

	function initiate_ui_contact_lookup($data)
	{
		//_debug_array($data);

		$field = $data['field'];
		if (!empty($data['type']))
		{
			switch ($data['type'])
			{
				case 'view':
					phpgwapi_xslttemplates::getInstance()->add_file(array('contact_view'), $this->xsl_rootdir);
					break;
				case 'form':
					phpgwapi_xslttemplates::getInstance()->add_file(array('contact_form'), $this->xsl_rootdir);
					break;
				default:
					break;
			}
		}

		$contact['value_contact_id'] = $data['contact_id'];
		//			$contact['value_contact_name']		= $data['contact_name'];

		if (isset($data['contact_id']) && $data['contact_id'] && !$data['contact_name'])
		{
			$contacts						 = CreateObject('phpgwapi.contacts');
			$contact_data					 = $contacts->read_single_entry($data['contact_id'], array(
				'fn',
				'tel_work',
				'email'
			));
			$contact['value_contact_name']	 = $contact_data[0]['fn'];
			$contact['value_contact_email']	 = $contact_data[0]['email'];
			$contact['value_contact_tel']	 = $contact_data[0]['tel_work'];

			unset($contacts);

			if (!$contact['value_contact_email'])
			{
				$user_id						 = createObject('property.soresponsible')->get_contact_user_id($data['contact_id']);
				$prefs							 = $this->create_preferences('common', $user_id);
				$contact['value_contact_email']	 = $prefs['email'];
				$contact['value_contact_tel']	 = $prefs['cellphone'];
			}
		}

		$contact['field']					 = $field;
		$contact['contact_link']			 = phpgw::link('/index.php', array(
			'menuaction'	 => 'property.uilookup.addressbook',
			'column'		 => $field,
			'clear_state'	 => 1
		));
		$contact['lang_contact']			 = lang('contact');
		$contact['lang_select_contact_help'] = lang('click this link to select');
		//_debug_array($contact);
		return $contact;
	}

	function initiate_ui_tenant_lookup($data)
	{
		if ($data['type'] == 'view')
		{
			phpgwapi_xslttemplates::getInstance()->add_file(array('tenant_view'), $this->xsl_rootdir);
		}
		else
		{
			phpgwapi_xslttemplates::getInstance()->add_file(array('tenant_form'), $this->xsl_rootdir);
		}

		$tenant['value_tenant_id']	 = $data['tenant_id'];
		$tenant['value_first_name']	 = $data['first_name'];
		$tenant['value_last_name']	 = $data['last_name'];
		$tenant['tenant_link']		 = phpgw::link('/index.php', array('menuaction' => 'property.uilookup.tenant'));
		if ($data['role'] == 'customer')
		{
			$tenant['lang_select_tenant_help']	 = lang('click this link to select customer');
			$tenant['lang_tenant']				 = lang('Customer');
		}
		else
		{
			$tenant['lang_select_tenant_help']	 = lang('click this link to select tenant');
			$tenant['lang_tenant']				 = lang('Tenant');
		}


		if ($data['tenant_id'] && !$data['tenant_name'])
		{
			$tenant_object = CreateObject('property.sogeneric');
			$tenant_object->get_location_info('tenant', false);

			$custom						 = createObject('property.custom_fields');
			$tenant_data['attributes']	 = $custom->find('property', '.tenant', 0, '', 'ASC', 'attrib_sort', true, true);
			$tenant_data				 = $tenant_object->read_single(array('id' => $data['tenant_id']), $tenant_data);
			if (is_array($tenant_data['attributes']))
			{
				//_debug_array($tenant_data);
				foreach ($tenant_data['attributes'] as $entry)
				{

					if ($entry['name'] == 'first_name')
					{
						$tenant['value_first_name'] = $entry['value'];
					}
					if ($entry['name'] == 'last_name')
					{
						$tenant['value_last_name'] = $entry['value'];
					}
				}
			}
		}

		//_debug_array($tenant);
		return $tenant;
	}

	/**
	 * initiate design element for lookup to budget account/group
	 *
	 * @param array $data
	 *
	 * @return array with information to include in forms
	 */
	function initiate_ui_budget_account_lookup($data)
	{
		if (isset($data['type']) && $data['type'] == 'view')
		{
			phpgwapi_xslttemplates::getInstance()->add_file(array('b_account_view'), $this->xsl_rootdir);
		}
		else
		{
			phpgwapi_xslttemplates::getInstance()->add_file(array('b_account_form'), $this->xsl_rootdir);
		}

		$b_account['value_b_account_id']		 = $data['b_account_id'];
		$b_account['value_b_account_name']		 = $data['b_account_name'];
		$b_account['b_account_link']			 = phpgw::link('/index.php', array(
			'menuaction' => 'property.uilookup.b_account',
			'role'		 => isset($data['role']) && $data['role'] ? $data['role'] : '',
			'parent'	 => isset($data['parent']) && $data['parent'] ? $data['parent'] : '',
		));
		$b_account['lang_select_b_account_help'] = lang('click this link to select budget account');
		$b_account['lang_b_account']			 = isset($data['role']) && $data['role'] == 'group' ? lang('budget account group') : lang('Budget account');
		if ($data['b_account_id'] && !$data['b_account_name'])
		{
			$b_account_object = CreateObject('property.sogeneric');
			if (isset($data['role']) && $data['role'] == 'group')
			{
				$b_account_object->get_location_info('b_account', false);
			}
			else
			{
				$b_account_object->get_location_info('budget_account', false);
			}
			$b_account_data						 = $b_account_object->read_single(array('id' => $data['b_account_id']));
			$b_account['value_b_account_name']	 = $b_account_data['descr'];
		}

		$b_account['disabled']	 = isset($data['disabled']) && $data['disabled'] ? true : false;
		$b_account['required']	 = isset($data['required']) && $data['required'] ? true : false;
		return $b_account;
	}

	function initiate_external_project_lookup($data)
	{
		$external_project = array();

		if (isset($data['type']) && $data['type'] == 'view')
		{
			if (!isset($data['external_project_id']) || !$data['external_project_id'])
			{
				return $external_project;
			}

			phpgwapi_xslttemplates::getInstance()->add_file(array('external_project_view'), $this->xsl_rootdir);
		}
		else
		{
			phpgwapi_xslttemplates::getInstance()->add_file(array('external_project_form'), $this->xsl_rootdir);
		}

		$external_project['value_external_project_id']			 = $data['external_project_id'];
		$external_project['value_external_project_name']		 = $data['external_project_name'];
		$external_project['external_project_url']				 = phpgw::link('/index.php', array(
			'menuaction' => 'property.uilookup.external_project'
		));
		$external_project['lang_select_external_project_help']	 = lang('click to select external project');
		$external_project['lang_external_project']				 = lang('external project');
		if ($data['external_project_id'] && (!isset($data['external_project_name']) || !$data['external_project_name']))
		{
			$external_project_object							 = CreateObject('property.sogeneric');
			$external_project_object->get_location_info('external_project', false);
			$external_project_data								 = $external_project_object->read_single(array(
				'id' => $data['external_project_id']
			));
			$external_project['value_external_project_name']	 = $external_project_data['name'];
			$external_project['value_external_project_budget']	 = $external_project_data['budget'];
		}
		return $external_project;
	}

	function initiate_ecodimb_lookup($data)
	{
		$ecodimb = array();

		if (isset($data['type']) && $data['type'] == 'view')
		{
			if (!isset($data['ecodimb']) || !$data['ecodimb'])
			{
				return $ecodimb;
			}

			phpgwapi_xslttemplates::getInstance()->add_file(array('ecodimb_view'), $this->xsl_rootdir);
		}
		else
		{
			phpgwapi_xslttemplates::getInstance()->add_file(array('ecodimb_form'), $this->xsl_rootdir);
		}

		$ecodimb['value_ecodimb']			 = $data['ecodimb'];
		$ecodimb['value_ecodimb_descr']		 = $data['ecodimb_descr'];
		$ecodimb['ecodimb_url']				 = phpgw::link('/index.php', array('menuaction' => 'property.uilookup.ecodimb'));
		$ecodimb['lang_select_ecodimb_help'] = lang('click to select dimb');
		$ecodimb['lang_ecodimb']			 = lang('dimb');
		if ($data['ecodimb'] && (!isset($data['ecodimb_descr']) || !$data['ecodimb_descr']))
		{
			$ecodimb_object					 = CreateObject('property.sogeneric');
			$ecodimb_object->get_location_info('dimb', false);
			$ecodimb_data					 = $ecodimb_object->read_single(array('id' => $data['ecodimb']));
			$ecodimb['value_ecodimb_descr']	 = $ecodimb_data['descr'];
		}
		$ecodimb['disabled'] = isset($data['disabled']) && $data['disabled'] ? true : false;
		$ecodimb['required'] = isset($data['required']) && $data['required'] ? true : false;

		return $ecodimb;
	}

	function initiate_event_lookup($data)
	{
		$event				 = array();
		$event['name']		 = $data['name']; // attribute name
		$event['event_name'] = $data['event_name']; // Human readable description
		if (isset($data['type']) && $data['type'] == 'view')
		{
			phpgwapi_xslttemplates::getInstance()->add_file(array('event_view'), $this->xsl_rootdir);
			if (!isset($data['event']) || !$data['event'])
			{
				//		return $event;
			}
		}
		else
		{
			phpgwapi_xslttemplates::getInstance()->add_file(array('event_form'), $this->xsl_rootdir);
		}

		// If the record is not saved - issue a warning
		if (isset($data['item_id']) || $data['item_id'])
		{
			$event['item_id'] = $data['item_id'];
		}
		else if (isset($data['location_code']) || $data['location_code'])
		{
			$event['item_id'] = execMethod('property.solocation.get_item_id', $data['location_code']);
		}
		else
		{
			$event['warning'] = lang('Warning: the record has to be saved in order to plan an event');
		}

		if (isset($data['event_id']) && $data['event_id'])
		{
			$event['value']			 = $data['event_id'];
			$event_info				 = execMethod('property.soevent.read_single', $data['event_id']);
			$event['descr']			 = $event_info['descr'];
			$event['enabled']		 = $event_info['enabled'] ? lang('yes') : lang('no');
			$event['lang_enabled']	 = lang('enabled');

			$job_id	 = "property{$data['location']}::{$data['item_id']}::{$data['name']}";
			$job	 = execMethod('phpgwapi.asyncservice.read', $job_id);

			$event['next']			 = $this->phpgwapi_common->show_date($job[$job_id]['next'], $this->userSettings['preferences']['common']['dateformat']);
			$event['lang_next_run']	 = lang('next run');

			$criteria = array(
				'start_date'		 => $event_info['start_date'],
				'end_date'			 => $event_info['end_date'],
				'location_id'		 => $event_info['location_id'],
				'location_item_id'	 => $event_info['location_item_id']
			);

			$event['count']	 = 0;
			$boevent		 = CreateObject('property.boevent');
			$boevent->find_scedules($criteria);
			$schedules		 = $boevent->cached_events;
			//_debug_array($schedules);die();
			foreach ($schedules as $day => $set)
			{
				foreach ($set as $entry)
				{
					if ($entry['enabled'] && (!isset($entry['exception']) || !$entry['exception'] == true))
					{
						$event['count']++;
					}
				}
				$event['responsible_id'] = $entry['responsible_id'];
			}
			if ($event['responsible_id'])
			{
				$c		 = CreateObject('phpgwapi.contacts');
				$qfields = array(
					'contact_id'	 => 'contact_id',
					'per_full_name'	 => 'per_full_name',
				);

				$criteria				 = array('contact_id' => $event['responsible_id']);
				$contacts				 = $c->get_persons($qfields, 15, 0, '', '', $criteria);
				$event['responsible']	 = $contacts[0]['per_full_name'];
			}

			unset($event_info);
			unset($job_id);
			unset($job);
		}

		$event['event_link'] = phpgw::link(
			'/index.php',
			array(
				'menuaction' => 'property.uievent.edit',
				'location'	 => $data['location'],
				'attrib_id'	 => $event['name'],
				'item_id'	 => isset($event['item_id']) ? $event['item_id'] : '',
				'id'		 => isset($event['value']) && $event['value'] ? $event['value'] : ''
			)
		);

		$event['event_link'] = "{menuaction:'property.uievent.edit',lookup:1,"
			. "location:'{$data['location']}',"
			. "attrib_id:'{$event['name']}'";
		$event['event_link'] .= isset($event['item_id']) ? ",item_id:{$event['item_id']}" : '';
		$event['event_link'] .= isset($event['value']) ? ",id:{$event['value']}" : '';
		$event['event_link'] .= '}';

		$event['function_name'] = 'lookup_' . $event['name'] . '()';

		return $event;
	}

	function initiate_ui_alarm($data)
	{
		$boalarm = CreateObject('property.boalarm');

		if ($data['type'] == 'view')
		{
			phpgwapi_xslttemplates::getInstance()->add_file(array('alarm_view'), $this->xsl_rootdir);
		}
		else
		{
			phpgwapi_xslttemplates::getInstance()->add_file(array('alarm_form'), $this->xsl_rootdir);
		}

		$alarm['header'][] = array(
			'lang_time'		 => lang('Time'),
			'lang_text'		 => lang('Text'),
			'lang_user'		 => lang('User'),
			'lang_enabled'	 => lang('Enabled'),
			'lang_select'	 => lang('Select')
		);

		$alarm['values'] = $boalarm->read_alarms($data['alarm_type'], $data['id'], $data['text']);

		if ($data['type'] == 'form')
		{
			$alarm['alter_alarm'][] = array(
				'lang_enable'	 => lang('Enable'),
				'lang_disable'	 => lang('Disable'),
				'lang_delete'	 => lang('Delete')
			);

			for ($i = 1; $i <= 31; $i++)
			{
				$alarm['add_alarm']['day_list'][($i - 1)]['id'] = $i;
			}
			$alarm['add_alarm']['lang_day']				 = lang('Day');
			$alarm['add_alarm']['lang_day_statustext']	 = lang('Day');

			for ($i = 1; $i <= 24; $i++)
			{
				$alarm['add_alarm']['hour_list'][($i - 1)]['id'] = $i;
			}
			$alarm['add_alarm']['lang_hour']			 = lang('Hour');
			$alarm['add_alarm']['lang_hour_statustext']	 = lang('Hour');

			for ($i = 1; $i <= 60; $i++)
			{
				$alarm['add_alarm']['minute_list'][($i - 1)]['id'] = $i;
			}
			$alarm['add_alarm']['lang_minute']				 = lang('Minutes before the event');
			$alarm['add_alarm']['lang_minute_statustext']	 = lang('Minutes before the event');

			$alarm['add_alarm']['user_list'] = $this->get_user_list_right2('select', 4, false, $data['acl_location'], false, $default						 = $this->account);

			$alarm['add_alarm']['lang_user']			 = lang('User');
			$alarm['add_alarm']['lang_user_statustext']	 = lang('Select the user the alarm belongs to.');
			$alarm['add_alarm']['lang_no_user']			 = lang('No user');
			$alarm['add_alarm']['lang_add']				 = lang('Add');
			$alarm['add_alarm']['lang_add_alarm']		 = lang('Add alarm');
			$alarm['add_alarm']['lang_add_statustext']	 = lang('Add alarm for selected user');
		}

		//_debug_array($alarm['values']);
		return $alarm;
	}

	function select_multi_list_2($selected = '', $list = array(), $input_type = '')
	{
		if (isset($list) and is_array($list))
		{
			foreach ($list as &$choice)
			{
				$choice['input_type'] = $input_type;
				if (isset($selected) && is_array($selected))
				{
					foreach ($selected as &$sel)
					{
						if ($sel == $choice['id'])
						{
							$choice['checked'] = 'checked';
						}
					}
				}
			}
		}
		return $list;
	}

	function translate_datatype($datatype)
	{
		$datatype_text = array(
			'V'		 => 'Varchar',
			'I'		 => 'Integer',
			'C'		 => 'char',
			'N'		 => 'Float',
			'D'		 => 'Date',
			'T'		 => 'Memo',
			'R'		 => 'Muliple radio',
			'CH'	 => 'Muliple checkbox',
			'LB'	 => 'Listbox',
			'AB'	 => 'Contact',
			'VENDOR' => 'Vendor',
			'email'	 => 'Email',
			'link'	 => 'Link',
			'pwd'	 => 'Password',
			'user'	 => 'phpgw user'
		);

		$datatype = lang($datatype_text[$datatype]);

		return $datatype;
	}

	function translate_datatype_insert($datatype)
	{
		$datatype_text = array(
			'V'		 => 'varchar',
			'I'		 => 'int',
			'C'		 => 'char',
			'N'		 => 'decimal',
			'D'		 => 'timestamp',
			'T'		 => 'text',
			'R'		 => 'int',
			'CH'	 => 'text',
			'LB'	 => 'int',
			'AB'	 => 'int',
			'VENDOR' => 'int',
			'email'	 => 'varchar',
			'link'	 => 'varchar',
			'pwd'	 => 'varchar',
			'user'	 => 'int'
		);

		return $datatype_text[$datatype];
	}

	function translate_datatype_precision($datatype)
	{
		$datatype_precision = array(
			'I'		 => 4,
			'R'		 => 4,
			'LB'	 => 4,
			'AB'	 => 4,
			'VENDOR' => 4,
			'email'	 => 64,
			'link'	 => 255,
			'pwd'	 => 32,
			'user'	 => 4
		);

		return (isset($datatype_precision[$datatype]) ? $datatype_precision[$datatype] : '');
	}

	/**
	 * Convert a datatype to a format to output
	 *
	 * @param string $datatype the dataype to convert
	 *
	 * @return string the format - incoming of translation is not found
	 */
	function translate_datatype_format($datatype)
	{
		$datatype_text = array(
			'V'		 => 'varchar',
			'I'		 => 'integer',
			'C'		 => 'char',
			'N'		 => 'float',
			'D'		 => 'date',
			'T'		 => 'memo',
			'R'		 => 'radio',
			'CH'	 => 'checkbox',
			'LB'	 => 'listbox',
			'AB'	 => 'contact',
			'VENDOR' => 'vendor',
			'email'	 => 'email',
			'link'	 => 'link',
			'pwd'	 => 'password',
			'user'	 => 'phpgw_user'
		);


		if (isset($datatype_text[$datatype]))
		{
			return $datatype_text[$datatype];
		}
		return $datatype;
	}

	function add_leading_zero($num, $id_type = '')
	{
		if ($id_type == "hex")
		{
			$num = hexdec($num);
			$num++;
			$num = dechex($num);
		}
		else
		{
			$num++;
		}

		if (strlen($num) == 4)
			$return	 = $num;
		if (strlen($num) == 3)
			$return	 = "0$num";
		if (strlen($num) == 2)
			$return	 = "00$num";
		if (strlen($num) == 1)
			$return	 = "000$num";
		if (strlen($num) == 0)
			$return	 = "0001";

		return strtoupper($return);
	}

	function read_location_data($location_code)
	{
		$soadmin_location = CreateObject('property.soadmin_location');

		$location_types = $soadmin_location->select_location_type();
		unset($soadmin_location);

		return $this->socommon->read_location_data($location_code, $location_types);
	}

	function read_single_tenant($tenant_id)
	{
		return $this->socommon->read_single_tenant($tenant_id);
	}

	function check_location($location_code = '', $type_id = '')
	{
		return $this->socommon->check_location($location_code, $type_id);
	}

	function generate_sql($data)
	{
		//_debug_array($data);

		$cols				 = isset($data['cols']) ? $data['cols'] : '';
		$entity_table		 = isset($data['entity_table']) ? $data['entity_table'] : '';
		$location_table		 = isset($data['location_table']) ? $data['location_table'] : '';
		$cols_return		 = isset($data['cols_return']) && $data['cols_return'] ? $data['cols_return'] : array();
		$uicols				 = isset($data['uicols']) && $data['uicols'] ? $data['uicols'] : array();
		$joinmethod			 = isset($data['joinmethod']) ? $data['joinmethod'] : '';
		$paranthesis		 = isset($data['paranthesis']) ? $data['paranthesis'] : '';
		$lookup				 = isset($data['lookup']) ? $data['lookup'] : '';
		$location_level		 = isset($data['location_level']) && $data['location_level'] > 0 ? (int)$data['location_level'] : 0;
		$no_address			 = isset($data['no_address']) ? $data['no_address'] : '';
		$force_location		 = isset($data['force_location']) ? $data['force_location'] : '';
		$cols_extra			 = array();
		$cols_return_lookup	 = array();


		$config = new Config('property');
		$config->read();

		if ($location_level)
		{
			$list_location_level = isset($config->config_data['list_location_level']) && $config->config_data['list_location_level'] ? $config->config_data['list_location_level'] : array();
		}
		else
		{
			$list_location_level = array();
		}

		if (!$list_location_level)
		{
			for ($i = 0; $i < $location_level; $i++)
			{
				$list_location_level[] = $i + 1;
			}
		}

		$soadmin_location	 = CreateObject('property.soadmin_location');
		$location_types		 = $soadmin_location->select_location_type();
		$config				 = $soadmin_location->read_config('');

		if ($location_level || $force_location)
		{
			$_location_table = $location_table ? $location_table : $entity_table;
			if ($location_level)
			{
				$type_id = $location_level;
			}
			else
			{
				$type_id = count($location_types);
			}
			$cols		 .= ",fm_location1.loc1_name";
			$joinmethod	 .= " {$this->join}  fm_location1 ON ({$_location_table}.loc1 = fm_location1.loc1))";
			$paranthesis .= '(';
			$joinmethod	 .= " {$this->join}  fm_part_of_town ON (fm_location1.part_of_town_id = fm_part_of_town.id))";
			$paranthesis .= '(';
			$joinmethod	 .= " {$this->join}  fm_owner ON (fm_location1.owner_id = fm_owner.id))";
			$paranthesis .= '(';
		}
		else
		{
			$type_id	 = 0; //count($location_types);
			$no_address	 = true;
		}


		$this->type_id	 = $type_id;
		$_level			 = 1;
		for ($i = 0; $i < $type_id; $i++)
		{
			if ($_level > 1) // very expensive
			//		if($_level == 2 && in_array(2, $list_location_level))
			{
				$joinmethod	 .= " {$this->left_join} fm_location{$_level}";
				$paranthesis .= '(';
				$on			 = 'ON';
				for ($k = ($_level - 1); $k > 0; $k--)
				{
					$joinmethod	 .= " $on (fm_location{$_level}.loc{$k} = fm_location" . ($_level - 1) . ".loc{$k} AND  fm_location{$_level}.loc{$_level} = $entity_table.loc{$_level})";
					$on			 = 'AND';
					if ($k == 1)
					{
						$joinmethod .= ")";
					}
				}
			}
			$_level++;
		}

		unset($_level);

		foreach ($list_location_level as $_key => $_level)
		{
			if ($_level)
			{
				$i						 = $_level - 1;
				$uicols['input_type'][]	 = 'text';
				$uicols['name'][]		 = 'loc' . $location_types[$i]['id'];
				$uicols['descr'][]		 = $location_types[$i]['name'];
				$uicols['statustext'][]	 = $location_types[$i]['descr'];
				$uicols['exchange'][]	 = false;
				$uicols['align'][]		 = '';
				$uicols['datatype'][]	 = '';
				$uicols['formatter'][]	 = '';
				$uicols['classname'][]	 = '';
				$uicols['sortable'][]	 = $_level == 1;
			}
		}


		//_debug_array($uicols);die();
		//_debug_array($joinmethod);die();
		unset($soadmin_location);

		for ($i = 0; $i < $this->type_id; $i++)
		{
			$cols_return[] = 'loc' . $location_types[$i]['id'];
		}

		$lang_name				 = lang('name');
		$location_relation_data	 = array();
		$custom					 = createObject('property.custom_fields');
		for ($i = 1; $i < ($type_id + 1); $i++)
		{
			$cols					 .= ",loc{$i}_name";
			$cols_return[]			 = "loc{$i}_name";
			$cols_extra[]			 = "loc{$i}_name";
			$cols_return_lookup[]	 = "loc{$i}_name";
			$uicols['input_type'][]	 = in_array($i, $list_location_level) ? 'text' : 'hidden';
			$uicols['name'][]		 = "loc{$i}_name";
			$uicols['descr'][]		 = "{$location_types[($i - 1)]['name']} {$lang_name}";
			$uicols['statustext'][]	 = $location_types[$i - 1]['descr'];
			$uicols['exchange'][]	 = $lookup;
			$uicols['align'][]		 = '';
			$uicols['datatype'][]	 = '';
			$uicols['formatter'][]	 = '';
			$uicols['classname'][]	 = '';
			$uicols['sortable'][]	 = $i == 1;

			$fm_location_cols_temp = $custom->find('property', '.location.' . $i, 0, '', '', '', true);
			foreach ($fm_location_cols_temp as $entry)
			{
				if ($entry['lookup_form'])
				{
					$location_relation_data[] = array(
						'level'			 => $i,
						'name'			 => $entry['name'],
						'descr'			 => $entry['input_text'],
						'status_text'	 => $entry['status_text'],
						'datatype'		 => $entry['datatype'],
					);
				}
			}
		}

		Cache::system_set('property', 'location_relation_data', $location_relation_data);

		if (!$no_address)
		{
			$cols					 .= ",$entity_table.address";
			$cols_return[]			 = 'address';
			$uicols['input_type'][]	 = 'text';
			$uicols['name'][]		 = 'address';
			$uicols['descr'][]		 = lang('address');
			$uicols['statustext'][]	 = lang('address');
			$uicols['exchange'][]	 = false;
			$uicols['align'][]		 = '';
			$uicols['datatype'][]	 = '';
			$uicols['formatter'][]	 = '';
			$uicols['classname'][]	 = '';
			$uicols['sortable'][]	 = true;
		}

		$config_count = count($config);
		for ($i = 0; $i < $config_count; $i++)
		{

			if (($config[$i]['location_type'] <= $type_id) && ($config[$i]['query_value'] == 1))
			{

				if ($config[$i]['column_name'] == 'street_id')
				{

					$cols_return[]			 = 'street_name';
					$uicols['input_type'][]	 = 'hidden';
					$uicols['name'][]		 = 'street_name';
					$uicols['descr'][]		 = lang('street name');
					$uicols['statustext'][]	 = lang('street name');
					$uicols['exchange'][]	 = false;
					$uicols['align'][]		 = '';
					$uicols['datatype'][]	 = '';
					$uicols['formatter'][]	 = '';
					$uicols['classname'][]	 = '';
					$uicols['sortable'][]	 = true;

					$cols_return[]			 = 'street_number';
					$uicols['input_type'][]	 = 'hidden';
					$uicols['name'][]		 = 'street_number';
					$uicols['descr'][]		 = lang('street number');
					$uicols['statustext'][]	 = lang('street number');
					$uicols['exchange'][]	 = false;
					$uicols['align'][]		 = '';
					$uicols['datatype'][]	 = '';
					$uicols['formatter'][]	 = '';
					$uicols['classname'][]	 = '';
					$uicols['sortable'][]	 = '';

					$cols_return[]			 = $config[$i]['column_name'];
					$uicols['input_type'][]	 = 'hidden';
					$uicols['name'][]		 = $config[$i]['column_name'];
					$uicols['descr'][]		 = lang($config[$i]['input_text']);
					$uicols['statustext'][]	 = lang($config[$i]['input_text']);
					$uicols['exchange'][]	 = false;
					$uicols['align'][]		 = '';
					$uicols['datatype'][]	 = '';
					$uicols['formatter'][]	 = '';
					$uicols['classname'][]	 = '';
					$uicols['sortable'][]	 = '';

					if ($lookup)
					{
						$cols_extra[]	 = 'street_name';
						$cols_extra[]	 = 'street_number';
						$cols_extra[]	 = $config[$i]['column_name'];
					}
				}
				else
				{
					$cols_return[]			 = $config[$i]['column_name'];
					$uicols['input_type'][]	 = 'text';
					$uicols['name'][]		 = $config[$i]['column_name'];
					$uicols['descr'][]		 = $config[$i]['input_text'];
					$uicols['statustext'][]	 = $config[$i]['input_text'];
					$uicols['exchange'][]	 = false;
					$uicols['align'][]		 = '';
					$uicols['datatype'][]	 = '';
					$uicols['formatter'][]	 = '';
					$uicols['classname'][]	 = '';
					$uicols['sortable'][]	 = '';

					if ($lookup)
					{
						$cols_extra[] = $config[$i]['column_name'];
					}
				}
			}
		}

		$this->uicols				 = $uicols;
		$this->cols_return			 = $cols_return;
		$this->cols_extra			 = $cols_extra;
		$this->cols_return_lookup	 = $cols_return_lookup;

		$from = " FROM $paranthesis $entity_table ";

		$sql = "SELECT DISTINCT $cols $from $joinmethod";

		return $sql;
	}

	function select_part_of_town($format = '', $selected = '', $district_id = '')
	{
		switch ($format)
		{
			case 'select':
				phpgwapi_xslttemplates::getInstance()->add_file(array('select_part_of_town'), $this->xsl_rootdir);
				break;
			case 'filter':
				phpgwapi_xslttemplates::getInstance()->add_file(array('filter_part_of_town'), $this->xsl_rootdir);
				break;
		}

		$parts				 = $this->socommon->select_part_of_town($district_id);
		$part_of_town_list	 = array();

		if (is_array($parts) && (count($parts)))
		{
			foreach ($parts as $entry)
			{
				$part_of_town_list[] = array(
					'id'			 => $entry['id'],
					'name'			 => $entry['name'],
					'district_id'	 => $entry['district_id'],
					'selected'		 => $entry['id'] == $selected ? 1 : 0
				);
			}
		}

		return $part_of_town_list;
	}

	function select_district_list($format = '', $selected = '')
	{
		switch ($format)
		{
			case 'select':
				phpgwapi_xslttemplates::getInstance()->add_file(array('select_district'), $this->xsl_rootdir);
				break;
			case 'filter':
				phpgwapi_xslttemplates::getInstance()->add_file(array('filter_district'), $this->xsl_rootdir);
				break;
		}

		$districts = $this->socommon->select_district_list();

		return $this->select_list($selected, $districts);
	}

	function select_category_list($data)
	{
		switch ($data['format'])
		{
			case 'select':
				phpgwapi_xslttemplates::getInstance()->add_file(array('cat_select'), $this->xsl_rootdir);
				break;
			case 'filter':
				phpgwapi_xslttemplates::getInstance()->add_file(array('cat_filter'), $this->xsl_rootdir);
				break;
		}

		$categories = execMethod('property.sogeneric.get_list', $data);
		return $this->select_list($data['selected'], $categories);
	}

	function fm_cache($name = '', $value = '')
	{
		return $this->socommon->fm_cache($name, $value);
	}

	/**
	 * Clear all content from cache
	 *
	 */
	function reset_fm_cache()
	{
		$this->socommon->reset_fm_cache();
	}

	/**
	 * Clear computed userlist for location and rights from cache
	 *
	 * @return integer number of values was found and cleared
	 */
	function reset_fm_cache_userlist()
	{
		return $this->socommon->reset_fm_cache_userlist();
	}

	function next_id($table, $key = '')
	{
		return $this->socommon->next_id($table, $key);
	}

	function select_datatype($selected = '', $sub_module = '')
	{

		$custom = createObject('phpgwapi.custom_fields');

		foreach ($custom->datatype_text as $key => $name)
		{
			$datatypes[] = array(
				'id'	 => $key,
				'name'	 => $name,
			);
		}

		return $this->select_list($selected, $datatypes);
	}

	function select_nullable($selected = '')
	{
		$nullable[0]['id']	 = 'True';
		$nullable[0]['name'] = lang('true');
		$nullable[1]['id']	 = 'False';
		$nullable[1]['name'] = lang('false');

		return $this->select_list($selected, $nullable);
	}

	/**
	 * Choose which  download format to use - and call the appropriate function
	 *
	 * @param array $list array with data to export
	 * @param array $name array containing keys in $list
	 * @param array $descr array containing Names for the heading of the output for the coresponding keys in $list
	 * @param array $input_type array containing information whether fields are to be suppressed from the output
	 * @param array $identificator array containing 1.row for identification purposes in case of data import.
	 * @param string $filename
	 */
	function download($list, $name, $descr, $input_type = array(), $identificator = array(), $filename = '')
	{
		set_time_limit(500);
		$this->flags['noheader']	 = true;
		$this->flags['nofooter']	 = true;
		$this->flags['xslt_app']	 = false;
		Settings::getInstance()->set('flags', $this->flags);

		$export_format	 = isset($this->userSettings['preferences']['common']['export_format']) && $this->userSettings['preferences']['common']['export_format'] ? $this->userSettings['preferences']['common']['export_format'] : 'csv';
		$php_version	 = (float)PHP_VERSION;

		$html2text			 = createObject('phpgwapi.html2text');
		if (isset($list) && is_array($list))
		{
			foreach ($list as &$entry)
			{
				foreach ($entry as $col => &$value)
				{
					if ($value && !is_array($value))
					{
						$html2text->setHTML($value);
						$value = trim($html2text->getText());
					}
				}
			}
		}
		switch ($export_format)
		{
			case 'csv':
				$this->csv_out($list, $name, $descr, $input_type, $identificator, $filename);
				break;
			case 'excel':
				/* Experimental */
				$this->xslx_out($list, $name, $descr, $input_type, $identificator, $filename);
				//					$this->phpspreadsheet_out($list, $name, $descr, $input_type, $identificator, $filename, 'excel');
				break;
			case 'ods':
				$this->phpspreadsheet_out($list, $name, $descr, $input_type, $identificator, $filename, 'ods');
				break;
		}
	}

	/**
	 * downloads data as MsExcel to the browser
	 *
	 * @param array $list array with data to export
	 * @param array $name array containing keys in $list
	 * @param array $descr array containing Names for the heading of the output for the coresponding keys in $list
	 * @param array $input_type array containing information whether fields are to be suppressed from the output
	 * @param array $identificator array containing 1.row for identification purposes in case of data import.
	 * @param string $filename
	 * @param string $export_format
	 */
	function phpspreadsheet_out($list, $name, $descr, $input_type = array(), $identificator = array(), $filename = '', $export_format = 'excel')
	{

		if ($filename)
		{
			$filename_arr	 = explode('.', str_replace(' ', '_', basename($filename)));
			$filename		 = $filename_arr[0];
		}
		else
		{
			$filename = str_replace(' ', '_', $this->userSettings['account_lid']);
		}
		$date_time = str_replace(array(' ', '/'), '_', $this->phpgwapi_common->show_date(time()));

		switch ($export_format)
		{
			case 'excel':
				$suffix	 = 'xlsx';
				break;
			case 'ods':
				$suffix	 = 'ods';
				break;
			default:
				$suffix	 = 'xlsx';
				break;
		}
		$filename .= "_{$date_time}.{$suffix}";

		$browser = CreateObject('phpgwapi.browser');
		$browser->content_header($filename, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

		$spreadsheet = new PhpOffice\PhpSpreadsheet\Spreadsheet();

		$spreadsheet->getProperties()->setCreator($this->userSettings['fullname'])
			->setLastModifiedBy($this->userSettings['fullname'])
			->setTitle("Download from {$this->serverSettings['system_name']}")
			->setSubject("Office 2007 XLSX Document")
			->setDescription("document for Office 2007 XLSX, generated using PHP classes.")
			->setKeywords("office 2007 openxml php")
			->setCategory("downloaded file");

		// Set active sheet index to the first sheet, so Excel opens this as the first sheet
		$spreadsheet->setActiveSheetIndex(0);

		if ($identificator)
		{
			$_first_row	 = 2;
			$i			 = 1;
			foreach ($identificator as $key => $value)
			{
				$spreadsheet->getActiveSheet()->setCellValue([$i, 1], $value);
				$i++;
			}
		}
		else
		{
			$_first_row = 1;
		}
		$count_uicols_name = count($name);

		$text_format = array();
		$m			 = 1;
		for ($k = 0; $k < $count_uicols_name; $k++)
		{
			if (!isset($input_type[$k]) || $input_type[$k] != 'hidden')
			{
				if (preg_match('/^loc/i', $name[$k]))
				{
					$text_format[$m] = true;
				}
				$spreadsheet->getActiveSheet()->setCellValue([$m, $_first_row], $descr[$k]);
				$m++;
			}
		}

		$j = 0;
		if (isset($list) && is_array($list))
		{
			foreach ($list as $entry)
			{
				$m = 0;
				for ($k = 0; $k < $count_uicols_name; $k++)
				{
					if (!isset($input_type[$k]) || $input_type[$k] != 'hidden')
					{
						$content[$j][$m] = str_replace("\r\n", " ", $entry[$name[$k]]);
						$m++;
					}
				}
				$j++;
			}

			$line = $_first_row;

			foreach ($content as $row)
			{
				$col	 = 'A';
				$line++;
				$rows	 = count($row);
				for ($i = 0; $i < $rows; $i++)
				{
					$cell = "{$col}{$line}";
					if (isset($text_format[$i]))
					{
						$spreadsheet->getActiveSheet()->setCellValueExplicit($cell, $row[$i], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
					}
					else
					{
						$spreadsheet->getActiveSheet()->setCellValue($cell, $row[$i]);
					}
					$col++;
				}
			}
		}

		if ($export_format = 'ods')
		{
			$objWriter = new PhpOffice\PhpSpreadsheet\Writer\Ods($spreadsheet);
		}
		else // Save Excel 2007 file
		{
			$objWriter = new PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
		}
		$objWriter->save('php://output');
	}

	/**
	 * downloads data as xslx to the browser
	 *
	 * @param array $list array with data to export
	 * @param array $name array containing keys in $list
	 * @param array $descr array containing Names for the heading of the output for the coresponding keys in $list
	 * @param array $input_type array containing information whether fields are to be suppressed from the output
	 */
	function xslx_out($list, $name, $descr, $input_type = array(), $identificator = array(), $filename = '')
	{
		if ($filename)
		{
			$filename_arr	 = explode('.', str_replace(' ', '_', basename($filename)));
			$filename		 = $filename_arr[0];
		}
		else
		{
			$filename = str_replace(' ', '_', $this->userSettings['account_lid']);
		}
		$date_time	 = str_replace(array(' ', '/'), '_', $this->phpgwapi_common->show_date(time()));
		$filename	 .= "_{$date_time}.xlsx";

		$writer = CreateObject('phpgwapi.xlsxwriter');

		$browser = CreateObject('phpgwapi.browser');
		$browser->content_header($writer::sanitize_filename($filename), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

		$writer->setauthor($this->userSettings['fullname']);
		$writer->setTitle("Download from {$this->serverSettings['system_name']}");

		$count_uicols_name = count($name);

		$header = array();


		$loop = 0;
		if ($list)
		{
			foreach ($list as $entry)
			{
				for ($i = 0; $i < $count_uicols_name; ++$i)
				{
					if (!isset($input_type[$i]) || $input_type[$i] != 'hidden')
					{
						$test = $entry[$name[$i]];
						if (ctype_digit((string)$test))
						{
							if (empty($header[$descr[$i]]) || (!empty($header[$descr[$i]]) && $header[$descr[$i]] !== 'string'))
							{
								$header[$descr[$i]] = 'integer';
							}
						}
						//	else if(is_float($test))
						//	else if(preg_match('/([0-9]{1,})\.([0-9]{2,2})/', $test))
						else if (filter_var($test, FILTER_VALIDATE_FLOAT))
						{
							if (empty($header[$descr[$i]]) || (!empty($header[$descr[$i]]) && $header[$descr[$i]] !== 'string'))
							{
								$header[$descr[$i]] = '0.00';
							}
						}
						else
						{
							$header[$descr[$i]] = 'string';
						}
					}
				}

				$loop++;
				if ($loop > 4)
				{
					break;
				}
			}
		}
		else
		{
			for ($i = 0; $i < $count_uicols_name; $i++)
			{
				if (!isset($input_type[$i]) || $input_type[$i] != 'hidden')
				{
					$header[$descr[$i]] = 'string';
				}
			}
		}

		$m		 = 0;
		$formats = array();
		for ($k = 0; $k < $count_uicols_name; $k++)
		{
			if (!isset($input_type[$k]) || $input_type[$k] != 'hidden')
			{
				if (preg_match('/^loc/i', $name[$k]))
				{
					$header[$descr[$k]]	 = 'string';
					$formats[$m]		 = 'string';
				}
			}
			$m++;
		}

		$_header = array();
		if ($identificator)
		{
			$_identificator	 = array_values($identificator);
			$i				 = 0;
			foreach ($header as $key => $format)
			{
				if (!empty($_identificator[$i]))
				{
					$_header[$_identificator[$i]] = 'string';
				}
				else
				{
					$_header[$i] = 'string';
				}
				$i++;
			}
			$writer->writeSheetHeader('Sheet1', $_header);

			$writer->writeSheetRow('Sheet1', array_keys($header));
		}
		else
		{
			$writer->writeSheetHeader('Sheet1', $header);
		}

		unset($header);

		if (is_array($list))
		{
			foreach ($list as $entry)
			{
				$row = array();
				for ($i = 0; $i < $count_uicols_name; ++$i)
				{
					if (!isset($input_type[$i]) || $input_type[$i] != 'hidden')
					{
						$row[] = preg_replace("/\r\n/", ' ', (string)$entry[$name[$i]]);
					}
				}
				$writer->writeSheetRow('Sheet1', $row);
			}
		}
		$writer->writeToStdOut();
	}

	/**
	 * downloads data as CSV to the browser
	 *
	 * @param array $list array with data to export
	 * @param array $name array containing keys in $list
	 * @param array $descr array containing Names for the heading of the output for the coresponding keys in $list
	 * @param array $input_type array containing information whether fields are to be suppressed from the output
	 */
	function csv_out($list, $name, $descr, $input_type = array(), $identificator = array(), $filename = '')
	{
		if ($filename)
		{
			$filename_arr	 = explode('.', str_replace(' ', '_', basename($filename)));
			$filename		 = $filename_arr[0];
		}
		else
		{
			$filename = str_replace(' ', '_', $this->userSettings['account_lid']);
		}
		$date_time	 = str_replace(array(' ', '/'), '_', $this->phpgwapi_common->show_date(time()));
		$filename	 .= "_{$date_time}.csv";

		$browser = CreateObject('phpgwapi.browser');
		$browser->content_header($filename, 'application/csv');

		if (!$fp = fopen('php://output', 'w'))
		{
			die('Unable to write to "php://output" - pleace notify the Administrator');
		}

		$BOM = "\xEF\xBB\xBF"; // UTF-8 BOM
		fwrite($fp, $BOM); // NEW LINE

		if ($identificator)
		{
			$_identificator = array();
			foreach ($identificator as $key => $value)
			{
				$_identificator[] = $value;
			}
			fputcsv($fp, $_identificator, ';', '"', "\\");
		}

		$count_uicols_name = count($name);

		$header = array();
		for ($i = 0; $i < $count_uicols_name; ++$i)
		{
			if (!isset($input_type[$i]) || $input_type[$i] != 'hidden')
			{
				//	$header[] = $this->utf2ascii($descr[$i]);
				$header[] = $descr[$i];
			}
		}
		fputcsv($fp, $header, ';', '"', "\\");
		unset($header);

		if (is_array($list))
		{
			foreach ($list as $entry)
			{
				$row = array();
				for ($i = 0; $i < $count_uicols_name; ++$i)
				{
					if (!isset($input_type[$i]) || $input_type[$i] != 'hidden')
					{
						$row[] = preg_replace("/\r\n/", ' ', $entry[$name[$i]]);
					}
				}
				fputcsv($fp, $row, ';', '"', "\\");
			}
		}
		fclose($fp);
	}

	function increment_id($name)
	{
		return $this->socommon->increment_id($name);
	}

	function get_origin_link($type)
	{
		if ($type == 'tts')
		{
			$link = array('menuaction' => 'property.uitts.view');
		}
		else if ($type == 'request')
		{
			$link = array('menuaction' => 'property.uirequest.view');
		}
		else if ($type == 'project')
		{
			$link = array('menuaction' => 'property.uiproject.view');
		}
		else if (substr($type, 0, 6) == 'entity')
		{
			$type		 = explode("_", $type);
			$entity_id	 = $type[1];
			$cat_id		 = $type[2];
			$link		 = array(
				'menuaction' => 'property.uientity.view',
				'entity_id'	 => $entity_id,
				'cat_id'	 => $cat_id
			);
		}

		return (isset($link) ? $link : '');
	}

	function new_db($db = '')
	{
		return $this->socommon->new_db($db);
	}

	function get_max_location_level()
	{
		return $this->socommon->get_max_location_level();
	}

	/**
	 * Preserve attribute values from post in case of an error
	 *
	 * @param array $values value set with
	 * @param array $values_attributes attribute definitions and values from posting
	 *
	 * @return array attribute definitions and values
	 */
	public function preserve_attribute_values($values, $values_attributes)
	{

		if (!is_array($values_attributes))
		{
			return array();
		}

		foreach ($values_attributes as $attribute)
		{
			foreach ($values['attributes'] as &$val_attrib)
			{

				if ($val_attrib['id'] != $attribute['attrib_id'])
				{
					continue;
				}

				if (!isset($attribute['value']) && !isset($values['extra'][$val_attrib['name']]))
				{
					continue;
				}

				if (is_array($attribute['value']))
				{
					foreach ($val_attrib['choice'] as &$choice)
					{
						foreach ($attribute['value'] as $selected)
						{
							if ($selected == $choice['id'])
							{
								$choice['checked'] = 'checked';
							}
						}
					}
				}
				else if (isset($val_attrib['choice']) && is_array($val_attrib['choice']))
				{
					foreach ($val_attrib['choice'] as &$choice)
					{
						if ($choice['id'] == $attribute['value'])
						{
							$choice['checked'] = 'checked';
						}
					}
				}
				else if (isset($values['extra'][$val_attrib['name']]))
				{
					$val_attrib['value'] = $values['extra'][$val_attrib['name']];
				}
				else
				{
					$val_attrib['value'] = $attribute['value'];
				}
			}
		}
		return $values;
	}

	/**
	 * Converts utf-8 to ascii
	 *
	 * @param string $text string
	 * @return string ascii encoded
	 */
	function utf2ascii($text = '')
	{
		if (!isset($this->serverSettings['charset']) || $this->serverSettings['charset'] == 'utf-8')
		{
			if ($text == mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8'))
			{
				return $text;
			}
			else
			{
				return mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8');
			}
		}
		else
		{
			return $text;
		}
	}

	/**
	 * Converts ascii to utf-8
	 *
	 * @param string $text string
	 * @return string utf-8 encoded
	 */
	function ascii2utf($text = '')
	{
		if (!isset($this->serverSettings['charset']) || $this->serverSettings['charset'] == 'utf-8')
		{
			return mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
		}
		else
		{
			return $text;
		}
	}

	/**
	 * Collects locationdata from location form and appends to values
	 *
	 * @param array $values array with data fom post
	 * @param array $insert_record array containing fields to collect from post
	 * @return updated values
	 */
	function collect_locationdata($values = array(), $insert_record = array())
	{
		if ($insert_record)
		{
			if (isset($insert_record['location']) && is_array($insert_record['location']))
			{
				for ($i = 0; $i < count($insert_record['location']); $i++)
				{
					if (isset($_POST[$insert_record['location'][$i]]) && $_POST[$insert_record['location'][$i]])
					{
						$values['location'][$insert_record['location'][$i]] = Sanitizer::get_var($insert_record['location'][$i], 'string', 'POST');
					}
				}
			}

			if (isset($insert_record['extra']) && is_array($insert_record['extra']))
			{
				foreach ($insert_record['extra'] as $key => $column)
				{
					if (isset($_POST[$key]) && $_POST[$key])
					{
						$values['extra'][$column] = Sanitizer::get_var($key, 'string', 'POST');
					}
				}

				if (isset($values['extra']['p_entity_id']) && $values['extra']['p_entity_id'] && isset($values['extra']['p_cat_id']) && $values['extra']['p_cat_id'] && isset($values['extra']['p_num']) && $values['extra']['p_num'])
				{
					//strip prefix and leading zeros
					$values['extra']['p_num'] = execMethod(
						'property.soentity.convert_num_to_id',
						array(
							'type'		 => $values['extra']['type'],
							'entity_id'	 => $values['extra']['p_entity_id'],
							'cat_id'	 => $values['extra']['p_cat_id'],
							'num'		 => $values['extra']['p_num']
						)
					);

					$p_entity_id								 = $values['extra']['p_entity_id'];
					$p_cat_id									 = $values['extra']['p_cat_id'];
					$p_num										 = $values['extra']['p_num'];
					$values['p'][$p_entity_id]['p_entity_id']	 = $p_entity_id;
					$values['p'][$p_entity_id]['p_cat_id']		 = $p_cat_id;
					$values['p'][$p_entity_id]['p_num']			 = $p_num;
					$values['p'][$p_entity_id]['p_cat_name']	 = Sanitizer::get_var("entity_cat_name_{$p_entity_id}");
				}
			}
			if (isset($insert_record['additional_info']) && is_array($insert_record['additional_info']))
			{
				foreach ($insert_record['additional_info'] as $additional_info)
				{
					if ($additional_info_value = Sanitizer::get_var($additional_info['input_name'], 'string', 'POST'))
					{
						$values['additional_info'][$additional_info['input_text']] = $additional_info_value;
					}
				}
			}
		}

		$values['extra']		 = isset($values['extra']) && $values['extra'] ? $values['extra'] : array();
		$values['street_name']	 = Sanitizer::get_var('street_name');
		$values['street_number'] = Sanitizer::get_var('street_number');
		if (isset($values['location']) && is_array($values['location']))
		{
			$values['location_name'] = Sanitizer::get_var('loc' . (count($values['location'])) . '_name', 'string', 'POST'); // if not address - get the parent name as address
			$values['location_code'] = implode('-', $values['location']);
		}
		if ($values['location_code'])
		{
			$bolocation				 = CreateObject('property.bolocation');
			$values['location_data'] = $bolocation->read_single($values['location_code'], array_merge($values['extra'], array(
				'view' => true,
				'noattrib' => true
			)));
		}
		if (empty($values['location']) && !empty($values['location_code']) && !empty($values['location_data']))
		{
			$values['location'] = array();
			for ($i = 1; $i <= count(explode('-', $values['location_code'])); $i++)
			{
				$values['location']["loc{$i}"] = $values['location_data']["loc{$i}"];
			}
		}

		$origin		 = isset($values['origin']) && $values['origin'] ? $values['origin'] : false;
		$origin_id	 = isset($values['origin_id']) && $values['origin_id'] ? $values['origin_id'] : false;

		if ($origin == '.ticket' && $origin_id && !$values['descr'])
		{
			$boticket		 = CreateObject('property.botts');
			$ticket			 = $boticket->read_single($origin_id);
			$values['descr'] = strip_tags($ticket['details']);
			$values['name']	 = $ticket['subject'] ? $ticket['subject'] : $ticket['category_name'];
			$ticket_notes	 = $boticket->read_additional_notes($origin_id);
			$i				 = count($ticket_notes) - 1;
			if (isset($ticket_notes[$i]['value_note']) && $ticket_notes[$i]['value_note'])
			{
				$values['descr'] .= ": " . $ticket_notes[$i]['value_note'];
			}
			$values['contact_id'] = $ticket['contact_id'];
		}

		if (isset($origin) && $origin)
		{
			$interlink				 = CreateObject('property.interlink');
			$values['origin_data'][] = array(
				'location'	 => $origin,
				'descr'		 => $interlink->get_location_name($origin),
				'data'		 => array(
					array(
						'id'	 => $origin_id,
						'link'	 => $interlink->get_relation_link(array('location' => $origin), $origin_id)
					)
				)
			);
		}
		return $values;
	}

	function get_menu($app = 'property')
	{
		$this->flags['nonavbar'] = false;
		Settings::getInstance()->set('flags', $this->flags);

		if (!isset($this->userSettings['preferences']['property']['horisontal_menus']) || $this->userSettings['preferences']['property']['horisontal_menus'] == 'no')
		{
			return;
		}
		phpgwapi_xslttemplates::getInstance()->add_file(array('menu'), $this->xsl_rootdir);

		$menu = Cache::session_get("menu_{$app}", $this->flags['menu_selection']);

		if (!$menu)
		{
			$menu_gross			 = execMethod("{$app}.menu.get_menu", 'horisontal');
			$selection			 = explode('::', $this->flags['menu_selection']);
			$level				 = 0;
			$menu['navigation']	 = $this->get_sub_menu($menu_gross['navigation'], $selection, $level);
			Cache::session_set("menu_{$app}", isset($this->flags['menu_selection']) && $this->flags['menu_selection'] ? $this->flags['menu_selection'] : 'property_missing_selection', $menu,);
			unset($menu_gross);
		}
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

	function no_access()
	{
		$this->flags['xslt_app'] = true;
		phpgwapi_xslttemplates::getInstance()->add_file(array('no_access', 'menu'), $this->xsl_rootdir);

		$receipt['error'][] = array('msg' => lang('NO ACCESS'));

		$msgbox_data = $this->msgbox_data($receipt);

		$data = array(
			'msgbox_data'	 => $this->phpgwapi_common->msgbox($msgbox_data),
			'menu'			 => $this->get_menu(),
		);

		$appname = lang('No access');

		$this->flags['app_header'] = lang('property') . ' - ' . $appname;
		Settings::getInstance()->set('flags', $this->flags);
		phpgwapi_xslttemplates::getInstance()->set_var('phpgw', array('no_access' => $data));
	}

	/**
	 * Get list of accessible physical locations for current user
	 *
	 * @param integer $required Right the user has to be granted at location
	 *
	 * @return array $access_location list of accessible physical locations
	 */
	public function get_location_list($required)
	{
		return $this->socommon->get_location_list($required);
	}

	public function select2String($array_values, $id = 'id', $name = 'name', $name2 = '')
	{
		$str_array_values = "";
		for ($i = 0; $i < count($array_values); $i++)
		{
			foreach ($array_values[$i] as $key => $value)
			{
				if ($key == $id)
				{
					$str_array_values	 .= $value;
					$str_array_values	 .= "#";
				}
				if ($key == $name)
				{
					$str_array_values	 .= $value;
					$str_array_values	 .= "@";
				}
				if ($key == $name2)
				{
					// eliminate hte last @ in $str_array_values
					$str_array_values	 = substr($str_array_values, 0, strrpos($str_array_values, '@'));
					$str_array_values	 .= " " . $value;
					$str_array_values	 .= "@";
				}
			}
		}

		return $str_array_values;
	}

	public function make_menu_date($array, $id_buttons, $name_hidden)
	{
		$split_values = array();
		foreach ($array as $value)
		{
			array_push($split_values, array(
				'text'		 => "{$value['id']}",
				'value'		 => $value['id'],
				'onclick'	 => array('fn'	 => 'onDateClick', 'obj'	 => array(
					'id_button'		 => $id_buttons,
					'opt'			 => $value['id'],
					'hidden_name'	 => $name_hidden
				))
			));
		}
		return $split_values;
	}

	public function make_menu_user($array, $id_buttons, $name_hidden)
	{
		$split_values = array();
		foreach ($array as $value)
		{
			array_push($split_values, array(
				'text'		 => $value['name'],
				'value'		 => $value['id'],
				'onclick'	 => array('fn'	 => 'onUserClick', 'obj'	 => array(
					'id_button'		 => $id_buttons,
					'id'			 => $value['id'],
					'name'			 => $value['name'],
					'hidden_name'	 => $name_hidden
				))
			));
		}
		return $split_values;
	}

	public function choose_select($array, $index_return)
	{
		foreach ($array as $value)
		{
			if ($value["selected"] == "selected")
			{
				return $value[$index_return];
			}
		}
		//for avoid erros, return the last value
		return $array[count($array) - 1][$index_return];
	}

	/**
	 * pending action for items across the system.
	 *
	 * @param array   $data array containing string  'appname'			- the name of the module being looked up
	 * 										string  'location'			- the location within the module to look up
	 * 										integer 'id'				- id of the referenced item - could possibly be a bigint
	 * 										integer 'responsible'		- the user_id asked for approval
	 * 										string  'responsible_type'  - what type of responsible is asked for action (user,vendor or tenant)
	 * 										string  'action'			- what type of action is pending
	 * 										string  'remark'			- a general remark - if any
	 * 										integer 'deadline'			- unix timestamp if any deadline is given.
	 *
	 * @return integer $reminder  number of request for this action
	 */
	public function set_pending_action($action_params)
	{
		return $this->socommon->set_pending_action($action_params);
	}

	public function get_top_level_categories($data)
	{
		$selected = array();
		if (!empty($data['selected']))
		{
			if (is_array($data['selected']))
			{
				$selected = $data['selected'];
			}
			else if (preg_match('/^\,|&\,/', $data['selected']))
			{
				$selected = explode(',', trim($data['selected'], ','));
			}
			else
			{
				$selected[] = $data['selected'];
			}
		}

		$cats				 = CreateObject('phpgwapi.categories', -1, 'property', $data['acl_location']);
		$cats->supress_info	 = true;
		$_cats				 = $cats->return_sorted_array(0, false, '', '', '', false, false);
		$values = array();
		foreach ($_cats as $_cat)
		{
			if ($_cat['level'] == 0 && $_cat['active'] != 2)
			{
				$_cat['selected']	= in_array($_cat['id'], $selected) ? 1 : 0;

				$values[] = $_cat;
			}
		}

		return $values;
	}
	public function get_top_level_category_names($data)
	{
		static $_cats = array();

		$selected = array();
		if (!empty($data['id']))
		{
			if (is_array($data['id']))
			{
				$selected = $data['id'];
			}
			else if (preg_match('/^\,|&\,/', $data['id']))
			{
				$selected = explode(',', trim($data['id'], ','));
			}
			else
			{
				$selected[] = $data['id'];
			}
		}

		if (!isset($_cats[$data['acl_location']]))
		{
			$cats							 = CreateObject('phpgwapi.categories', -1, 'property', $data['acl_location']);
			$cats->supress_info				 = true;
			$_cats[$data['acl_location']]	 = $cats->return_sorted_array(0, false, '', '', '', false, false);
		}

		$names = array();

		if (is_array($_cats[$data['acl_location']]))
		{
			foreach ($_cats[$data['acl_location']] as $_cat)
			{
				if ($_cat['level'] == 0 && $_cat['active'] != 2 && in_array($_cat['id'], $selected))
				{
					$names[] = $_cat['name'];
				}
			}
		}

		return implode(', ', $names);
	}

	public function get_categories($data)
	{
		$cats				 = CreateObject('phpgwapi.categories', -1, 'property', $data['acl_location']);
		$cats->supress_info	 = true;
		$values				 = $cats->formatted_xslt_list(array(
			'selected'	 => $data['selected'],
			'globals'	 => true,
			'link_data'	 => array()
		));
		$ret				 = array();

		$level = !empty($data['level']) ? $data['level'] : 0;

		foreach ($values['cat_list'] as $category)
		{
			$ret[] = array(
				'id'		 => $category['cat_id'],
				'name'		 => $category['name'],
				'selected'	 => $category['selected'] ? 1 : 0
			);
		}
		return $ret;
	}

	public function get_vendor_email($vendor_id = 0, $field_name = '')
	{
		if (!$vendor_id)
		{
			$vendor_id	 = Sanitizer::get_var('vendor_id', 'int', 'GET', 0);
			$field_name	 = Sanitizer::get_var('field_name', 'string', 'GET');
		}

		$preselect = Sanitizer::get_var('preselect', 'bool');
		$preselect_one = Sanitizer::get_var('preselect_one', 'bool');

		$vendor_email = execMethod('property.sowo_hour.get_email', $vendor_id);

		if (!$field_name)
		{
			$field_name = 'values[vendor_email][]';
		}
		else
		{
			$field_name .= '[]';
		}

		$content_email	 = array();
		$title			 = lang('The address to which this order will be sendt');

		$checked = $preselect ? 'checked="checked"' : '';

		$count_email = count($vendor_email);
		if ($count_email == 1 && $preselect_one)
		{
			$checked = 'checked="checked"';
		}

		foreach ($vendor_email as $_entry)
		{
			$content_email[] = array(
				'value_email'	 => $_entry['email'],
				'value_select'	 => "<input type='checkbox' name='{$field_name}' value='{$_entry['email']}' title='{$title}' {$checked}>"
			);
		}

		if (Sanitizer::get_var('phpgw_return_as') == 'json')
		{
			$total_records = count($content_email);

			return array(
				'data'				 => $content_email,
				'total_records'		 => $total_records,
				'draw'				 => Sanitizer::get_var('draw', 'int'),
				'recordsTotal'		 => $total_records,
				'recordsFiltered'	 => $total_records
			);
		}
		return $content_email;
	}

	public function get_vendor_contract($vendor_id = 0, $selected = '')
	{
		if (!$vendor_id)
		{
			$vendor_id = Sanitizer::get_var('vendor_id', 'int');
		}

		$contract_list = createObject('property.soagreement')->get_vendor_contract($vendor_id, $selected);
		if ($selected)
		{
			foreach ($contract_list as &$contract)
			{
				$contract['selected'] = $selected == $contract['id'] ? 1 : 0;
			}
		}

		return $contract_list;
	}

	/**
	 * called as ajax from edit form
	 *
	 * @param string  $query
	 *
	 * @return array
	 */
	public function get_eco_service()
	{
		$query = Sanitizer::get_var('query');

		$sogeneric = CreateObject('property.sogeneric', 'eco_service');

		$filter	 = array('active' => 1);
		$values	 = $sogeneric->read(array('filter' => $filter, 'query' => $query));

		return array('ResultSet' => array('Result' => $values));
	}

	public function get_eco_service_name($id)
	{
		$ret = $id;
		if ($id	 = (int)$id)
		{
			$sogeneric		 = CreateObject('property.sogeneric', 'eco_service');
			$sogeneric_data	 = $sogeneric->read_single(array('id' => $id));
			$ret			 = $sogeneric_data['name'];
		}
		return $ret;
	}

	public function get_unspsc_code()
	{
		$query = Sanitizer::get_var('query');

		$sogeneric	 = CreateObject('property.sogeneric', 'unspsc_code');
		$values		 = $sogeneric->read(array('query' => $query, 'allrows' => true));
		foreach ($values as &$value)
		{
			$value['name'] = "{$value['id']} {$value['name']}";
		}

		return array('ResultSet' => array('Result' => $values));
	}

	public function get_unspsc_code_name($id)
	{
		$ret = '';
		if ($id)
		{
			$sogeneric		 = CreateObject('property.sogeneric', 'unspsc_code');
			$sogeneric_data	 = $sogeneric->read_single(array('id' => $id));
			if ($sogeneric_data)
			{
				$ret = $sogeneric_data['name'];
			}
		}
		return $ret;
	}

	public function get_b_account()
	{
		$query	 = Sanitizer::get_var('query');
		$role	 = Sanitizer::get_var('role');

		$type = 'budget_account';

		if ($role == 'group')
		{
			$type = 'b_account_category';
		}

		$sogeneric	 = CreateObject('property.sogeneric', $type);
		$filter		 = array('active' => 1);
		$values		 = $sogeneric->read(array('filter' => $filter, 'query' => $query));

		foreach ($values as &$value)
		{
			if (!preg_match("/^{$value['id']}/", $value['descr']))
			{
				$value['name'] = "{$value['id']} {$value['descr']}";
			}
			else
			{
				$value['name'] = $value['descr'];
			}
		}

		return array('ResultSet' => array('Result' => $values));
	}

	public function get_external_project()
	{
		$query = Sanitizer::get_var('query');

		$sogeneric	 = CreateObject('property.sogeneric', 'external_project');
		$filter		 = array('active' => 1);
		$values		 = $sogeneric->read(array('filter' => $filter, 'query' => $query));

		//			foreach ($values as &$value)
		//			{
		//				$value['name'] = "{$value['id']} {$value['name']}";
		//			}

		return array('ResultSet' => array('Result' => $values));
	}

	public function get_external_project_name($id)
	{
		$ret = $id;
		if ($id)
		{
			$sogeneric		 = CreateObject('property.sogeneric', 'external_project');
			$sogeneric_data	 = $sogeneric->read_single(array('id' => $id));
			if ($sogeneric_data)
			{
				$ret = $sogeneric_data['name'];
			}
		}
		return $ret;
	}

	public function get_ecodimb()
	{
		$query = Sanitizer::get_var('query');

		$sogeneric	 = CreateObject('property.sogeneric', 'dimb');
		$filter		 = array('active' => 1);
		$values		 = $sogeneric->read(array('filter' => $filter, 'query' => $query));

		foreach ($values as &$value)
		{
			$value['name'] = "{$value['id']} {$value['descr']}";
		}

		return array('ResultSet' => array('Result' => $values));
	}

	public function get_documentation_url($id)
	{

		$order_info = $this->socommon->get_order_type($id);
		$secret = $order_info['secret'];

		$config_frontend = createobject('phpgwapi.config', 'mobilefrontend')->read();

		$documentation_url = !empty($config_frontend['external_site_address'])  ? rtrim($config_frontend['external_site_address'], '/') : rtrim($this->serverSettings['webserver_url'], '/');

		$documentation_url .= '/mobilefrontend/';

		$documentation_url .= '?' . http_build_query(array(
			'menuaction' => 'property.uiimport_documents.step_1_import',
			'id'		 => $id,
			'secret'	 => $secret,
			'domain'	 => $this->userSettings['domain']
		));

		return $documentation_url;
	}

	public function get_users($query)
	{
		if (!$this->acl_read)
		{
			return;
		}

		$accounts = $this->accounts->get_list('accounts');

		$values = array();
		foreach ($accounts as $account)
		{
			if ($account->enabled)
			{
				$values[] = array(
					'id'	 => $account->id,
					'name'	 => $account->__toString(),
				);
			}
		}
		return array('ResultSet' => array('Result' => $values));
	}
}
