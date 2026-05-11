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
use App\modules\property\helpers\CommonBusinessHelper;

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
	var $common_business_helper;
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
		$this->common_business_helper = new CommonBusinessHelper();



		$this->async =		AsyncService::getInstance();

		$this->join		 = $this->socommon->join;
		$this->left_join = $this->socommon->left_join;
		$this->like		 = $this->socommon->like;

		$template_set = isset($this->serverSettings['template_set']) ? $this->serverSettings['template_set'] : 'base';
		$this->xsl_rootdir = PHPGW_SERVER_ROOT . "/property/templates/{$template_set}";
	}

	function check_perms($rights, $required)
	{
		return $this->common_business_helper->checkPerms($rights, $required);
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
		$equalto = $this->accounts->membership($owner_id);
		return $this->common_business_helper->checkPerms2($owner_id, $grants, $required, $equalto);
	}

	function create_preferences($app = '', $user_id = '')
	{
		return $this->common_business_helper->createPreferences($this->socommon, $app, $user_id);
	}

	function get_lookup_entity($location = '')
	{
		return $this->common_business_helper->getLookupEntity($this->socommon, $location);
	}

	function get_start_entity($location = '')
	{
		return $this->common_business_helper->getStartEntity($this->socommon, $location);
	}

	function msgbox_data($receipt)
	{
		return $this->common_business_helper->msgboxData($receipt);
	}

	function confirm_session()
	{
		return $this->common_business_helper->confirm_session($this->phpgwapi_common);
	}

	function date_to_timestamp($date = array())
	{
		return $this->common_business_helper->dateToTimestamp($date);
	}

	function select_multi_list($selected = '', $input_list = array())
	{
		return $this->common_business_helper->selectMultiList($selected, $input_list);
	}

	function select_list($selected = '', $list = array())
	{
		return $this->common_business_helper->selectList($selected, $list);
	}

	function get_user_list($format = '', $selected = '', $extra = '', $default = '', $start = '', $sort = 'ASC', $order = 'account_lastname', $query = '', $offset = '', $enabled = false)
	{
		return $this->common_business_helper->get_user_list(
			$this->accounts,
			$this->xsl_rootdir,
			$format,
			$selected,
			$extra,
			$default,
			$start,
			$sort,
			$order,
			$query,
			$offset,
			$enabled
		);
	}

	function get_group_list($format = '', $selected = '', $start = '', $sort = '', $order = '', $query = '', $offset = '')
	{
		return $this->common_business_helper->get_group_list(
			$this->accounts,
			$this->xsl_rootdir,
			$format,
			$selected,
			$start,
			$sort,
			$order,
			$query,
			$offset
		);
	}

	function get_user_list_right($rights, $selected = '', $acl_location = '', $extra = '', $default = '')
	{
		return $this->common_business_helper->get_user_list_right(
			$this->socommon,
			$this->accounts,
			$rights,
			$selected,
			$acl_location,
			$extra,
			$default
		);
	}

	function get_user_list_right2($format = '', $right = '', $selected = '', $acl_location = '', $extra = '', $default = '')
	{
		return $this->common_business_helper->get_user_list_right2(
			$this->socommon,
			$this->accounts,
			$this->xsl_rootdir,
			$format,
			$right,
			$selected,
			$acl_location,
			$extra,
			$default
		);
	}

	function initiate_ui_vendorlookup($data)
	{
		//_debug_array($data);

		$this->common_business_helper->addViewFormTemplate('vendor', $this->common_business_helper->resolveLookupType($data), $this->xsl_rootdir);

		$vendor['value_vendor_id']	 = $data['vendor_id'];
		$vendor['value_vendor_name'] = $data['vendor_name'];

		if ($this->common_business_helper->hasLookupIdWithoutDisplayValue($data, 'vendor_id', 'vendor_name'))
		{
			$vendor_data = $this->common_business_helper->readSingleFromSogenericWithAttributes('vendor', '.vendor', $data['vendor_id']);
			if (is_array($vendor_data))
			{
				$org_name = $this->common_business_helper->getAttributeValueByName(isset($vendor_data['attributes']) ? $vendor_data['attributes'] : null, 'org_name');
				if ($org_name !== null)
				{
					$vendor['value_vendor_name'] = $org_name;
				}
			}
		}

		$vendor['vendor_link'] = $this->common_business_helper->buildLookupUrl('vendor');
		$this->common_business_helper->addEntityLabels($vendor, 'vendor', $data);
		$this->common_business_helper->addFormFlags($vendor, $data);
		//_debug_array($vendor);
		return $vendor;
	}

	function initiate_ui_contact_lookup($data)
	{
		//_debug_array($data);

		$field = $data['field'];
		if (!empty($data['type']))
		{
			$this->common_business_helper->addContactTemplate($data['type'], $this->xsl_rootdir);
		}

		$contact['value_contact_id'] = $data['contact_id'];
		//			$contact['value_contact_name']		= $data['contact_name'];

		if ($this->common_business_helper->hasLookupIdWithoutDisplayValue($data, 'contact_id', 'contact_name'))
		{
			$contact_entry = $this->common_business_helper->readContactEntry($data['contact_id']);
			$contact = array_merge($contact, $contact_entry);

			if (!$contact['value_contact_email'])
			{
				$this->common_business_helper->addContactFallbackFromPreferences($contact, $data['contact_id'], $this->socommon);
			}
		}

		$this->common_business_helper->addContactLookupMetadata($contact, $field);
		//_debug_array($contact);
		return $contact;
	}

	function initiate_ui_tenant_lookup($data)
	{
		$this->common_business_helper->addViewFormTemplate('tenant', $this->common_business_helper->resolveLookupType($data), $this->xsl_rootdir);

		$tenant['value_tenant_id']	 = $data['tenant_id'];
		$tenant['value_first_name']	 = $data['first_name'];
		$tenant['value_last_name']	 = $data['last_name'];
		$tenant['tenant_link'] = $this->common_business_helper->buildLookupUrl('tenant');
		$this->common_business_helper->addEntityLabels($tenant, 'tenant', $data);


		if ($this->common_business_helper->hasLookupIdWithoutDisplayValue($data, 'tenant_id', 'tenant_name'))
		{
			$tenant_data = $this->common_business_helper->readSingleFromSogenericWithAttributes('tenant', '.tenant', $data['tenant_id']);
			if (is_array($tenant_data['attributes']))
			{
				$first_name = $this->common_business_helper->getAttributeValueByName($tenant_data['attributes'], 'first_name');
				if ($first_name !== null)
				{
					$tenant['value_first_name'] = $first_name;
				}

				$last_name = $this->common_business_helper->getAttributeValueByName($tenant_data['attributes'], 'last_name');
				if ($last_name !== null)
				{
					$tenant['value_last_name'] = $last_name;
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
		$this->common_business_helper->addViewFormTemplate('b_account', $this->common_business_helper->resolveLookupType($data), $this->xsl_rootdir);

		$b_account['value_b_account_id']		 = $data['b_account_id'];
		$b_account['value_b_account_name']		 = $data['b_account_name'];
		$b_account['b_account_link'] = $this->common_business_helper->buildLookupUrl('b_account', array(
			'role'		 => isset($data['role']) && $data['role'] ? $data['role'] : '',
			'parent'	 => isset($data['parent']) && $data['parent'] ? $data['parent'] : '',
		));
		$this->common_business_helper->addEntityLabels($b_account, 'b_account', $data);
		if ($this->common_business_helper->hasLookupIdWithoutDisplayValue($data, 'b_account_id', 'b_account_name'))
		{
			$location = (isset($data['role']) && $data['role'] == 'group') ? 'b_account' : 'budget_account';
			$b_account_data = $this->common_business_helper->readSingleFromSogeneric($location, $data['b_account_id']);
			$b_account['value_b_account_name']	 = $b_account_data['descr'];
		}

		$this->common_business_helper->addFormFlags($b_account, $data);
		return $b_account;
	}

	function initiate_external_project_lookup($data)
	{
		$external_project = array();

		if ($this->common_business_helper->isViewWithoutLookupId($data, 'external_project_id'))
		{
			return $external_project;
		}

		$this->common_business_helper->addConditionalViewFormTemplate('external_project', $this->common_business_helper->resolveLookupType($data), $this->xsl_rootdir);

		$external_project['value_external_project_id']			 = $data['external_project_id'];
		$external_project['value_external_project_name']		 = $data['external_project_name'];
		$external_project['external_project_url'] = $this->common_business_helper->buildLookupUrl('external_project');
		$this->common_business_helper->addEntityLabels($external_project, 'external_project', $data);
		if ($this->common_business_helper->hasLookupIdWithoutDisplayValue($data, 'external_project_id', 'external_project_name'))
		{
			$external_project_data = $this->common_business_helper->readSingleFromSogeneric('external_project', $data['external_project_id']);
			$external_project['value_external_project_name']	 = $external_project_data['name'];
			$external_project['value_external_project_budget']	 = $external_project_data['budget'];
		}
		return $external_project;
	}

	function initiate_ecodimb_lookup($data)
	{
		$ecodimb = array();

		if ($this->common_business_helper->isViewWithoutLookupId($data, 'ecodimb'))
		{
			return $ecodimb;
		}

		$this->common_business_helper->addConditionalViewFormTemplate('ecodimb', $this->common_business_helper->resolveLookupType($data), $this->xsl_rootdir);

		$ecodimb['value_ecodimb']			 = $data['ecodimb'];
		$ecodimb['value_ecodimb_descr']		 = $data['ecodimb_descr'];
		$ecodimb['ecodimb_url'] = $this->common_business_helper->buildLookupUrl('ecodimb');
		$this->common_business_helper->addEntityLabels($ecodimb, 'ecodimb', $data);
		if ($this->common_business_helper->hasLookupIdWithoutDisplayValue($data, 'ecodimb', 'ecodimb_descr'))
		{
			$ecodimb_data = $this->common_business_helper->readSingleFromSogeneric('dimb', $data['ecodimb']);
			$ecodimb['value_ecodimb_descr']	 = $ecodimb_data['descr'];
		}
		$this->common_business_helper->addFormFlags($ecodimb, $data);

		return $ecodimb;
	}

	function initiate_event_lookup($data)
	{
		return $this->common_business_helper->initiate_event_lookup(
			$this->phpgwapi_common,
			$this->userSettings,
			$this->xsl_rootdir,
			$data
		);
	}

	function initiate_ui_alarm($data)
	{
		return $this->common_business_helper->initiate_ui_alarm($this->xsl_rootdir, $this->account, $data);
	}

	function select_multi_list_2($selected = '', $list = array(), $input_type = '')
	{
		return $this->common_business_helper->selectMultiList2($selected, $list, $input_type);
	}

	function translate_datatype($datatype)
	{
		return $this->common_business_helper->translateDatatype($datatype);
	}

	function translate_datatype_insert($datatype)
	{
		return $this->common_business_helper->translateDatatypeInsert($datatype);
	}

	function translate_datatype_precision($datatype)
	{
		return $this->common_business_helper->translateDatatypePrecision($datatype);
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
		return $this->common_business_helper->translateDatatypeFormat($datatype);
	}

	function add_leading_zero($num, $id_type = '')
	{
		return $this->common_business_helper->addLeadingZero($num, $id_type);
	}

	function read_location_data($location_code)
	{
		return $this->common_business_helper->readLocationData($this->socommon, $location_code);
	}

	function read_single_tenant($tenant_id)
	{
		return $this->common_business_helper->readSingleTenant($this->socommon, $tenant_id);
	}

	function check_location($location_code = '', $type_id = '')
	{
		return $this->common_business_helper->checkLocation($this->socommon, $location_code, $type_id);
	}

	function generate_sql($data)
	{
		$result = $this->common_business_helper->generate_sql($this->join, $this->left_join, $data);
		$this->type_id = $result['type_id'];
		$this->uicols = $result['uicols'];
		$this->cols_return = $result['cols_return'];
		$this->cols_extra = $result['cols_extra'];
		$this->cols_return_lookup = $result['cols_return_lookup'];

		return $result['sql'];
	}

	function select_part_of_town($format = '', $selected = '', $district_id = '')
	{
		$this->common_business_helper->addPartOfTownTemplate($format, $this->xsl_rootdir);

		return $this->common_business_helper->selectPartOfTown($this->socommon, $district_id, $selected);
	}

	function select_district_list($format = '', $selected = '')
	{
		$this->common_business_helper->addDistrictTemplate($format, $this->xsl_rootdir);

		return $this->common_business_helper->selectDistrictList($this->socommon, $selected);
	}

	function select_category_list($data)
	{
		$this->common_business_helper->addCategoryTemplate($data['format'], $this->xsl_rootdir);

		return $this->common_business_helper->selectCategoryList($data);
	}

	function fm_cache($name = '', $value = '')
	{
		return $this->common_business_helper->fmCache($this->socommon, $name, $value);
	}

	/**
	 * Clear all content from cache
	 *
	 */
	function reset_fm_cache()
	{
		$this->common_business_helper->resetFmCache($this->socommon);
	}

	/**
	 * Clear computed userlist for location and rights from cache
	 *
	 * @return integer number of values was found and cleared
	 */
	function reset_fm_cache_userlist()
	{
		return $this->common_business_helper->resetFmCacheUserlist($this->socommon);
	}

	function next_id($table, $key = '')
	{
		return $this->common_business_helper->nextId($this->socommon, $table, $key);
	}

	function select_datatype($selected = '', $sub_module = '')
	{

		$custom = createObject('phpgwapi.custom_fields');
		$datatypes = $this->common_business_helper->buildDatatypeList($custom->datatype_text);

		return $this->select_list($selected, $datatypes);
	}

	function select_nullable($selected = '')
	{
		$nullable = $this->common_business_helper->buildNullableList();

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
		$this->flags = $this->common_business_helper->download(
			$this->flags,
			$this->userSettings,
			$this->serverSettings,
			$this->phpgwapi_common,
			$list,
			$name,
			$descr,
			$input_type,
			$identificator,
			$filename
		);
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
		return $this->common_business_helper->phpspreadsheet_out(
			$this->userSettings,
			$this->serverSettings,
			$this->phpgwapi_common,
			$list,
			$name,
			$descr,
			$input_type,
			$identificator,
			$filename,
			$export_format
		);
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
		return $this->common_business_helper->xslx_out(
			$this->userSettings,
			$this->serverSettings,
			$this->phpgwapi_common,
			$list,
			$name,
			$descr,
			$input_type,
			$identificator,
			$filename
		);
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
		return $this->common_business_helper->csv_out(
			$this->userSettings,
			$this->phpgwapi_common,
			$list,
			$name,
			$descr,
			$input_type,
			$identificator,
			$filename
		);
	}

	function increment_id($name)
	{
		return $this->common_business_helper->incrementId($this->socommon, $name);
	}

	function get_origin_link($type)
	{
		return $this->common_business_helper->getOriginLink($type);
	}

	function new_db($db = '')
	{
		return $this->common_business_helper->newDb($this->socommon, $db);
	}

	function get_max_location_level()
	{
		return $this->common_business_helper->getMaxLocationLevel($this->socommon);
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
		return $this->common_business_helper->preserveAttributeValues($values, $values_attributes);
	}

	/**
	 * Converts utf-8 to ascii
	 *
	 * @param string $text string
	 * @return string ascii encoded
	 */
	function utf2ascii($text = '')
	{
		return $this->common_business_helper->utf2ascii($text, $this->serverSettings['charset'] ?? null);
	}

	/**
	 * Converts ascii to utf-8
	 *
	 * @param string $text string
	 * @return string utf-8 encoded
	 */
	function ascii2utf($text = '')
	{
		return $this->common_business_helper->ascii2utf($text, $this->serverSettings['charset'] ?? null);
	}

	/**
	 * Collects locationdata from location form and appends to values
	 *
	 * @param array $values array with data fom post
	 * @param array $insert_record array containing fields to collect from post
	 * @return array $values
	 */
	function collect_locationdata($values = array(), $insert_record = array())
	{
		return $this->common_business_helper->collect_locationdata($values, $insert_record);
	}

	function get_menu($app = 'property')
	{
		$menu_result = $this->common_business_helper->get_menu($this->flags, $this->userSettings, $this->xsl_rootdir, $app);
		$this->flags = $menu_result['flags'];

		if (is_null($menu_result['menu']))
		{
			return;
		}

		return $menu_result['menu'];
	}

	function get_sub_menu($children = array(), $selection = array(), $level = '')
	{
		return $this->common_business_helper->getSubMenu($children, $selection, $level);
	}

	function no_access()
	{
		$this->flags = $this->common_business_helper->no_access(
			$this->flags,
			$this->xsl_rootdir,
			$this->phpgwapi_common,
			$this->userSettings
		);
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
		return $this->common_business_helper->getLocationList($this->socommon, $required);
	}

	public function select2String($array_values, $id = 'id', $name = 'name', $name2 = '')
	{
		return $this->common_business_helper->select2String($array_values, $id, $name, $name2);
	}

	public function make_menu_date($array, $id_buttons, $name_hidden)
	{
		return $this->common_business_helper->makeMenuDate($array, $id_buttons, $name_hidden);
	}

	public function make_menu_user($array, $id_buttons, $name_hidden)
	{
		return $this->common_business_helper->makeMenuUser($array, $id_buttons, $name_hidden);
	}

	public function choose_select($array, $index_return)
	{
		return $this->common_business_helper->chooseSelect($array, $index_return);
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
		return $this->common_business_helper->setPendingAction($this->socommon, $action_params);
	}

	public function get_top_level_categories($data)
	{
		return $this->common_business_helper->getTopLevelCategories($data);
	}
	public function get_top_level_category_names($data)
	{
		return $this->common_business_helper->getTopLevelCategoryNames($data);
	}

	public function get_categories($data)
	{
		return $this->common_business_helper->getCategories($data);
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
		$as_json = Sanitizer::get_var('phpgw_return_as') == 'json';
		$draw = Sanitizer::get_var('draw', 'int');

		return $this->common_business_helper->getVendorEmail(
			$vendor_id,
			$field_name,
			$preselect,
			$preselect_one,
			$as_json,
			$draw
		);
	}

	public function get_vendor_contract($vendor_id = 0, $selected = '')
	{
		if (!$vendor_id)
		{
			$vendor_id = Sanitizer::get_var('vendor_id', 'int');
		}

		return $this->common_business_helper->getVendorContract($vendor_id, $selected);
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
		return $this->common_business_helper->getEcoService($query);
	}

	public function get_eco_service_name($id)
	{
		return $this->common_business_helper->getEcoServiceName($id);
	}

	public function get_unspsc_code()
	{
		$query = Sanitizer::get_var('query');
		return $this->common_business_helper->getUnspscCode($query);
	}

	public function get_unspsc_code_name($id)
	{
		return $this->common_business_helper->getUnspscCodeName($id);
	}

	public function get_b_account()
	{
		$query	 = Sanitizer::get_var('query');
		$role	 = Sanitizer::get_var('role');

		return $this->common_business_helper->getBAccount($query, $role);
	}

	public function get_external_project()
	{
		$query = Sanitizer::get_var('query');
		return $this->common_business_helper->getExternalProject($query);
	}

	public function get_external_project_name($id)
	{
		return $this->common_business_helper->getExternalProjectName($id);
	}

	public function get_ecodimb()
	{
		$query = Sanitizer::get_var('query');
		return $this->common_business_helper->getEcodimb($query);
	}

	public function get_documentation_url($id)
	{
		return $this->common_business_helper->getDocumentationUrl(
			$this->socommon,
			$id,
			$this->serverSettings,
			$this->userSettings
		);
	}

	public function get_users($query)
	{
		return $this->common_business_helper->getUsers($this->accounts, $this->acl_read);
	}
}
