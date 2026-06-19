<?php

namespace App\modules\property\helpers;

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\controllers\Accounts\Accounts;
use App\modules\phpgwapi\services\AsyncService;
use App\modules\phpgwapi\security\Sessions;
use App\modules\phpgwapi\security\Acl;
use App\modules\phpgwapi\services\Cache;
use App\modules\phpgwapi\services\Config;

class BoCommon
{
	public $xsl_rootdir;
	public $socommon;
	public $phpgwapi_common;
	public $userSettings;
	public $account;
	public $serverSettings;
	public $flags;
	public $accounts;
	public $async;
	public $start;
	public $query;
	public $filter;
	public $sort;
	public $order;
	public $cat_id;
	public $district_id;
	public $join;
	public $left_join;
	public $like;
	public $acl_read;
	public $type_id;
	public $uicols;
	public $cols_return;
	public $cols_extra;
	public $cols_return_lookup;
	public $public_functions = array(
		'confirm_session' => true,
		'get_vendor_email' => true
	);

	public function __construct()
	{
		$this->userSettings = Settings::getInstance()->get('user');
		$this->serverSettings = Settings::getInstance()->get('server');
		$this->flags = Settings::getInstance()->get('flags');

		$this->socommon = \CreateObject('property.socommon');
		$this->account = isset($this->userSettings['account_id']) ? (int) $this->userSettings['account_id'] : -1;
		$this->accounts = new Accounts();
		$this->phpgwapi_common = new \phpgwapi_common();
		$this->async = AsyncService::getInstance();

		$this->join = $this->socommon->join;
		$this->left_join = $this->socommon->left_join;
		$this->like = $this->socommon->like;

		$template_set = isset($this->serverSettings['template_set']) ? $this->serverSettings['template_set'] : 'base';
		$this->xsl_rootdir = PHPGW_SERVER_ROOT . "/property/templates/{$template_set}";
	}

	public function checkPerms($rights, $required)
	{
		return ($rights & $required);
	}

	public function check_perms($rights, $required)
	{
		return $this->checkPerms($rights, $required);
	}

	public function checkPerms2($owner_id, $grants, $required)
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

	public function check_perms2($owner_id, $grants, $required)
	{
	
		return $this->checkPerms2($owner_id, $grants, $required);
	}

	public function confirm_session()
	{
		$sessions = Sessions::getInstance();

		if ($sessions->verify())
		{
			header('Content-Type: application/json');
			echo json_encode(array('sessionExpired' => false));
			$this->phpgwapi_common->phpgw_exit();
		}
	}

	public function confirmSession()
	{
		return $this->confirm_session();
	}

	public function dateToTimestamp($date = array())
	{
		return \phpgwapi_datetime::date_to_timestamp($date);
	}

	public function date_to_timestamp($date = array())
	{
		return $this->dateToTimestamp($date);
	}

	public function msgboxData($receipt)
	{
		$msgbox_data_error = array();
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

		return array_merge($msgbox_data_error, $msgbox_data_message);
	}

	public function msgbox_data($receipt)
	{
		return $this->msgboxData($receipt);
	}

	public function selectList($selected = '', $list = array())
	{
		if (is_array($list))
		{
			foreach ($list as &$entry)
			{
				if ((string)$entry['id'] === (string)$selected)
				{
					$entry['selected'] = 1;
					break;
				}
			}
			return $list;
		}
	}

	public function select_list($selected = '', $list = array())
	{
		return $this->selectList($selected, $list);
	}

	public function selectMultiList($selected = '', $input_list = array())
	{
		$j = 0;
		$output_list = array();
		if (isset($input_list) and is_array($input_list))
		{
			foreach ($input_list as $entry)
			{
				$output_list[$j]['id'] = $entry['id'];
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

	public function select_multi_list($selected = '', $input_list = array())
	{
		return $this->selectMultiList($selected, $input_list);
	}

	public function selectMultiList2($selected = '', $list = array(), $input_type = '')
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

	public function select_multi_list_2($selected = '', $list = array(), $input_type = '')
	{
		return $this->selectMultiList2($selected, $list, $input_type);
	}

	public function translateDatatype($datatype)
	{
		$datatype_text = array(
			'V' => 'Varchar',
			'I' => 'Integer',
			'C' => 'char',
			'N' => 'Float',
			'D' => 'Date',
			'T' => 'Memo',
			'R' => 'Muliple radio',
			'CH' => 'Muliple checkbox',
			'LB' => 'Listbox',
			'AB' => 'Contact',
			'VENDOR' => 'Vendor',
			'email' => 'Email',
			'link' => 'Link',
			'pwd' => 'Password',
			'user' => 'phpgw user'
		);

		$datatype = lang($datatype_text[$datatype]);

		return $datatype;
	}

	public function translate_datatype($datatype)
	{
		return $this->translateDatatype($datatype);
	}

	public function translateDatatypeInsert($datatype)
	{
		$datatype_text = array(
			'V' => 'varchar',
			'I' => 'int',
			'C' => 'char',
			'N' => 'decimal',
			'D' => 'timestamp',
			'T' => 'text',
			'R' => 'int',
			'CH' => 'text',
			'LB' => 'int',
			'AB' => 'int',
			'VENDOR' => 'int',
			'email' => 'varchar',
			'link' => 'varchar',
			'pwd' => 'varchar',
			'user' => 'int'
		);

		return $datatype_text[$datatype];
	}

	public function translate_datatype_insert($datatype)
	{
		return $this->translateDatatypeInsert($datatype);
	}

	public function translateDatatypePrecision($datatype)
	{
		$datatype_precision = array(
			'I' => 4,
			'R' => 4,
			'LB' => 4,
			'AB' => 4,
			'VENDOR' => 4,
			'email' => 64,
			'link' => 255,
			'pwd' => 32,
			'user' => 4
		);

		return (isset($datatype_precision[$datatype]) ? $datatype_precision[$datatype] : '');
	}

	public function translate_datatype_precision($datatype)
	{
		return $this->translateDatatypePrecision($datatype);
	}

	public function translateDatatypeFormat($datatype)
	{
		$datatype_text = array(
			'V' => 'varchar',
			'I' => 'integer',
			'C' => 'char',
			'N' => 'float',
			'D' => 'date',
			'T' => 'memo',
			'R' => 'radio',
			'CH' => 'checkbox',
			'LB' => 'listbox',
			'AB' => 'contact',
			'VENDOR' => 'vendor',
			'email' => 'email',
			'link' => 'link',
			'pwd' => 'password',
			'user' => 'phpgw_user'
		);

		if (isset($datatype_text[$datatype]))
		{
			return $datatype_text[$datatype];
		}
		return $datatype;
	}

	public function translate_datatype_format($datatype)
	{
		return $this->translateDatatypeFormat($datatype);
	}

	public function addLeadingZero($num, $id_type = '')
	{
		if ($id_type == "hex")
		{
			$num = hexdec($num);
			$num++;
		}

		if (strlen($num) == 4)
		{
			$return = $num;
		}
		if (strlen($num) == 3)
		{
			$return = "0$num";
		}
		if (strlen($num) == 2)
		{
			$return = "00$num";
		}
		if (strlen($num) == 1)
		{
			$return = "000$num";
		}
		if (strlen($num) == 0)
		{
			$return = "0001";
		}

		return strtoupper($return);
	}

	public function add_leading_zero($num, $id_type = '')
	{
		return $this->addLeadingZero($num, $id_type);
	}

	public function select2string($array_values, $id = 'id', $name = 'name', $name2 = '')
	{
		$str_array_values = "";
		for ($i = 0; $i < count($array_values); $i++)
		{
			foreach ($array_values[$i] as $key => $value)
			{
				if ($key == $id)
				{
					$str_array_values .= $value;
					$str_array_values .= "#";
				}
				if ($key == $name)
				{
					$str_array_values .= $value;
					$str_array_values .= "@";
				}
				if ($key == $name2)
				{
					$str_array_values = substr($str_array_values, 0, strrpos($str_array_values, '@'));
					$str_array_values .= " " . $value;
					$str_array_values .= "@";
				}
			}
		}

		return $str_array_values;
	}

	public function getOriginLink($type)
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
			$type = explode("_", $type);
			$entity_id = $type[1];
			$cat_id = $type[2];
			$link = array(
				'menuaction' => 'property.uientity.view',
				'entity_id' => $entity_id,
				'cat_id' => $cat_id
			);
		}

		return (isset($link) ? $link : '');
	}

	public function get_origin_link($type)
	{
		return $this->getOriginLink($type);
	}

	public function utf2ascii($text = '')
	{
		$charset = isset($this->serverSettings['charset']) ? $this->serverSettings['charset'] : null;
		if (!isset($charset) || $charset == 'utf-8')
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
		return $text;
	}

	public function utf2_ascii($text = '')
	{
		return $this->utf2ascii($text);
	}

	public function ascii2utf($text = '')
	{
		$charset = isset($this->serverSettings['charset']) ? $this->serverSettings['charset'] : null;
		if (!isset($charset) || $charset == 'utf-8')
		{
			return mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
		}
		else
		{
			return $text;
		}
	}

	public function ascii2_utf($text = '')
	{
		return $this->ascii2utf($text);
	}

	public function makeMenuDate($array, $id_buttons, $name_hidden)
	{
		$split_values = array();
		foreach ($array as $value)
		{
			array_push($split_values, array(
				'text' => "{$value['id']}",
				'value' => $value['id'],
				'onclick' => array('fn' => 'onDateClick', 'obj' => array(
					'id_button' => $id_buttons,
					'opt' => $value['id'],
					'hidden_name' => $name_hidden
				))
			));
		}
		return $split_values;
	}

	public function makeMenuUser($array, $id_buttons, $name_hidden)
	{
		$split_values = array();
		foreach ($array as $value)
		{
			array_push($split_values, array(
				'text' => $value['name'],
				'value' => $value['id'],
				'onclick' => array('fn' => 'onUserClick', 'obj' => array(
					'id_button' => $id_buttons,
					'id' => $value['id'],
					'name' => $value['name'],
					'hidden_name' => $name_hidden
				))
			));
		}
		return $split_values;
	}

	public function chooseSelect($array, $index_return)
	{
		foreach ($array as $value)
		{
			if ($value["selected"] == "selected")
			{
				return $value[$index_return];
			}
		}
		return $array[count($array) - 1][$index_return];
	}

	public function buildDatatypeList($datatypeText = array())
	{
		$datatypes = array();
		foreach ($datatypeText as $key => $name)
		{
			$datatypes[] = array(
				'id' => $key,
				'name' => $name,
			);
		}

		return $datatypes;
	}

	public function buildNullableList()
	{
		$nullable = array();
		$nullable[0]['id'] = 'True';
		$nullable[0]['name'] = lang('true');
		$nullable[1]['id'] = 'False';
		$nullable[1]['name'] = lang('false');

		return $nullable;
	}

	public function createPreferences($app = '', $user_id = '')
	{
		return $this->socommon->create_preferences($app, $user_id);
	}

	public function getLookupEntity($location = '')
	{
		return $this->socommon->get_lookup_entity($location);
	}

	public function getStartEntity($location = '')
	{
		return $this->socommon->get_start_entity($location);
	}

	public function readSingleTenant($tenant_id)
	{
		return $this->socommon->read_single_tenant($tenant_id);
	}

	public function checkLocation($location_code = '', $type_id = '')
	{
		return $this->socommon->check_location($location_code, $type_id);
	}

	public function fmCache($name = '', $value = '')
	{
		return $this->socommon->fm_cache($name, $value);
	}

	public function resetFmCache()
	{
		$this->socommon->reset_fm_cache();
	}

	public function resetFmCacheUserlist()
	{
		return $this->socommon->reset_fm_cache_userlist();
	}

	public function nextId($table, $key = '')
	{
		return $this->socommon->next_id($table, $key);
	}

	public function incrementId($name)
	{
		return $this->socommon->increment_id($name);
	}

	public function newDb($db = '')
	{
		return $this->socommon->new_db($db);
	}

	public function getMaxLocationLevel()
	{
		return $this->socommon->get_max_location_level();
	}

	public function getLocationList($required)
	{
		return $this->socommon->get_location_list($required);
	}

	public function setPendingAction($action_params)
	{
		return $this->socommon->set_pending_action($action_params);
	}

	public function readLocationData($location_code)
	{
		$soadmin_location = CreateObject('property.soadmin_location');

		$location_types = $soadmin_location->select_location_type();
		unset($soadmin_location);

		return $this->socommon->read_location_data($location_code, $location_types);
	}

	public function buildPartOfTownList($parts, $selected = '')
	{
		$part_of_town_list = array();

		if (is_array($parts) && (count($parts)))
		{
			foreach ($parts as $entry)
			{
				$part_of_town_list[] = array(
					'id' => $entry['id'],
					'name' => $entry['name'],
					'district_id' => $entry['district_id'],
					'selected' => $entry['id'] == $selected ? 1 : 0
				);
			}
		}

		return $part_of_town_list;
	}

	public function selectPartOfTown($district_id = '', $selected = '')
	{
		$parts = $this->socommon->select_part_of_town($district_id);
		return $this->buildPartOfTownList($parts, $selected);
	}

	public function selectDistrictList($selected = '')
	{
		$districts = $this->socommon->select_district_list();
		return $this->selectList($selected, $districts);
	}

	public function selectCategoryList($data = array())
	{
		$categories = execMethod('property.sogeneric.get_list', $data);
		$selected = isset($data['selected']) ? $data['selected'] : '';
		return $this->selectList($selected, $categories);
	}

	public function read_location_data($location_code)
	{
		return $this->readLocationData($location_code);
	}

	public function select_part_of_town($format = '', $selected = '', $district_id = '')
	{
		$this->addPartOfTownTemplate($format, $this->xsl_rootdir);

		return $this->selectPartOfTown($district_id, $selected);
	}

	public function select_district_list($format = '', $selected = '')
	{
		$this->addDistrictTemplate($format, $this->xsl_rootdir);

		return $this->selectDistrictList($selected);
	}

	public function select_category_list($data)
	{
		$this->addCategoryTemplate($data['format'], $this->xsl_rootdir);

		return $this->selectCategoryList($data);
	}

	public function create_preferences($app = '', $user_id = '')
	{
		return $this->createPreferences($app, $user_id);
	}

	public function get_lookup_entity($location = '')
	{
		return $this->getLookupEntity($location);
	}

	public function get_start_entity($location = '')
	{
		return $this->getStartEntity($location);
	}

	public function read_single_tenant($tenant_id)
	{
		return $this->readSingleTenant($tenant_id);
	}

	public function check_location($location_code = '', $type_id = '')
	{
		return $this->checkLocation($location_code, $type_id);
	}

	public function fm_cache($name = '', $value = '')
	{
		return $this->fmCache($name, $value);
	}

	public function reset_fm_cache()
	{
		return $this->resetFmCache();
	}

	public function reset_fm_cache_userlist()
	{
		return $this->resetFmCacheUserlist();
	}

	public function next_id($table, $key = '')
	{
		return $this->nextId($table, $key);
	}

	public function select_datatype($selected = '', $sub_module = '')
	{
		$custom = createObject('phpgwapi.custom_fields');
		$datatypes = $this->buildDatatypeList($custom->datatype_text);

		return $this->selectList($selected, $datatypes);
	}

	public function select_nullable($selected = '')
	{
		$nullable = $this->buildNullableList();

		return $this->selectList($selected, $nullable);
	}

	public function increment_id($name)
	{
		return $this->incrementId($name);
	}

	public function preserve_attribute_values($values, $values_attributes)
	{
		return $this->preserveAttributeValues($values, $values_attributes);
	}

	public function new_db($db = '')
	{
		return $this->newDb($db);
	}

	public function get_max_location_level()
	{
		return $this->getMaxLocationLevel();
	}

	public function get_sub_menu($children = array(), $selection = array(), $level = '')
	{
		return $this->getSubMenu($children, $selection, $level);
	}

	public function get_location_list($required)
	{
		return $this->getLocationList($required);
	}

	public function make_menu_date($array, $id_buttons, $name_hidden)
	{
		return $this->makeMenuDate($array, $id_buttons, $name_hidden);
	}

	public function make_menu_user($array, $id_buttons, $name_hidden)
	{
		return $this->makeMenuUser($array, $id_buttons, $name_hidden);
	}

	public function choose_select($array, $index_return)
	{
		return $this->chooseSelect($array, $index_return);
	}

	public function set_pending_action($action_params)
	{
		return $this->setPendingAction($action_params);
	}

	public function get_top_level_categories($data)
	{
		return $this->getTopLevelCategories($data);
	}

	public function get_top_level_category_names($data)
	{
		return $this->getTopLevelCategoryNames($data);
	}

	public function get_categories($data)
	{
		return $this->getCategories($data);
	}

	public function get_vendor_email($vendor_id = 0, $field_name = '')
	{
		if (!$vendor_id)
		{
			$vendor_id  = \Sanitizer::get_var('vendor_id', 'int', 'GET', 0);
			$field_name = \Sanitizer::get_var('field_name', 'string', 'GET');
		}
		$preselect     = \Sanitizer::get_var('preselect', 'bool');
		$preselect_one = \Sanitizer::get_var('preselect_one', 'bool');
		$as_json       = \Sanitizer::get_var('phpgw_return_as') == 'json';
		$draw          = \Sanitizer::get_var('draw', 'int');
		return $this->getVendorEmail($vendor_id, $field_name, $preselect, $preselect_one, $as_json, $draw);
	}

	public function get_vendor_contract($vendor_id = 0, $selected = '')
	{
		return $this->getVendorContract($vendor_id, $selected);
	}

	public function get_eco_service()
	{
		$query = \Sanitizer::get_var('query');
		return $this->getEcoService($query);
	}

	public function get_eco_service_name($id)
	{
		return $this->getEcoServiceName($id);
	}

	public function get_unspsc_code()
	{
		$query = \Sanitizer::get_var('query');
		return $this->getUnspscCode($query);
	}

	public function get_unspsc_code_name($id)
	{
		return $this->getUnspscCodeName($id);
	}

	public function get_b_account()
	{
		$query = \Sanitizer::get_var('query');
		$role = \Sanitizer::get_var('role');
		return $this->getBAccount($query, $role);
	}

	public function get_external_project()
	{
		$query = \Sanitizer::get_var('query');
		return $this->getExternalProject($query);
	}

	public function get_external_project_name($id)
	{
		return $this->getExternalProjectName($id);
	}

	public function get_ecodimb()
	{
		$query = \Sanitizer::get_var('query');
		return $this->getEcodimb($query);
	}

	public function get_documentation_url($id)
	{
		return $this->getDocumentationUrl($id);
	}

	public function get_users($query)
	{
		return $this->getUsers();
	}

	public function addUserListTemplate($format, $xsl_rootdir)
	{
		switch ($format)
		{
			case 'select':
				\phpgwapi_xslttemplates::getInstance()->add_file(array('user_id_select'), $xsl_rootdir);
				break;
			case 'filter':
				\phpgwapi_xslttemplates::getInstance()->add_file(array('user_id_filter'), $xsl_rootdir);
				break;
		}
	}

	public function addGroupListTemplate($format, $xsl_rootdir)
	{
		switch ($format)
		{
			case 'select':
				\phpgwapi_xslttemplates::getInstance()->add_file(array('group_select'), $xsl_rootdir);
				break;
			case 'filter':
				\phpgwapi_xslttemplates::getInstance()->add_file(array('group_filter'), $xsl_rootdir);
				break;
		}
	}

	public function getUserList($format = '', $selected = '', $extra = '', $default = '', $start = '', $sort = 'ASC', $order = 'account_lastname', $query = '', $offset = '', $enabled = false)
	{
		$order = $order ? $order : 'account_lastname';

		$this->addUserListTemplate($format, $this->xsl_rootdir);
		$selected = $this->resolveSelectedDefault($selected, $default);

		$all_users = array();

		if (is_array($extra))
		{
			foreach ($extra as $extra_user)
			{
				$all_users[] = array(
					'account_id' => $extra_user,
					'account_firstname' => lang($extra_user)
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
						'user_id' => $user->id,
						'name' => $user->__toString(),
					);
				}
			}
		}

		if (count($all_users) > 0)
		{
			foreach ($all_users as $user)
			{
				if ($user['user_id'] == $selected)
				{
					$user_list[] = array(
						'user_id' => $user['user_id'],
						'name' => $user['name'],
						'selected' => 'selected'
					);
				}
				else
				{
					$user_list[] = array(
						'user_id' => $user['user_id'],
						'name' => $user['name'],
					);
				}
			}
		}

		return isset($user_list) ? $user_list : array();
	}

	public function getGroupList($format = '', $selected = '', $start = '', $sort = '', $order = '', $query = '', $offset = '')
	{
		$this->addGroupListTemplate($format, $this->xsl_rootdir);

		$users = $this->accounts->get_list('groups', $start, $sort, $order, $query, $offset);
		$user_list = array();
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
					'id' => $user->id,
					'name' => $user->firstname,
					'selected' => $sel_user
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

		return $user_list;
	}

	public function getUserListRight($rights, $selected = '', $acl_location = '', $extra = '', $default = '')
	{
		$selected = $this->resolveSelectedDefault($selected, $default);

		if (!is_array($rights))
		{
			$rights = array($rights);
		}

		$users_extra = $this->buildUsersExtraListRight($extra);

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

			$accounts_index = array();
			$users = array();

			foreach ($users_gross as $entry => $user)
			{
				if (!isset($accounts_index[$user['account_id']]))
				{
					$users[] = $user;
				}
				$accounts_index[$user['account_id']] = true;
			}
			unset($users_gross);
			unset($accounts_index);

			$account_firstname = array();
			$account_lastname = array();
			foreach ($users as $key => $row)
			{
				$account_lastname[$key] = $row['account_lastname'];
				$account_firstname[$key] = $row['account_firstname'];
			}

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

		$user_list = array();
		$selected_found = false;

		foreach ($users as $user)
		{
			if ($user['account_lid'] == $selected)
			{
				$user_list[] = array(
					'lid' => $user['account_lid'],
					'firstname' => $user['account_firstname'],
					'lastname' => $user['account_lastname'],
					'selected' => 'selected'
				);
			}
			else
			{
				$user_list[] = array(
					'lid' => $user['account_lid'],
					'firstname' => $user['account_firstname'],
					'lastname' => $user['account_lastname'],
				);
			}

			if (!$selected_found)
			{
				$selected_found = $user['account_lid'] == $selected ? true : false;
			}
		}

		foreach ($user_list as &$user)
		{
			$user['id'] = $user['lid'];
			$user['name'] = ltrim("{$user['lastname']}, {$user['firstname']}", ', ');
		}
		unset($user);

		if ($selected && !$selected_found)
		{
			$user_id = $this->accounts->name2id($selected);
			$_user = $this->accounts->get($user_id);

			$user_list[] = array(
				'lid' => $_user->lid,
				'firstname' => $_user->firstname,
				'lastname' => $_user->lastname,
				'id' => $selected,
				'name' => $_user->__toString(),
				'selected' => 'selected'
			);
		}

		return $user_list;
	}

	public function getUserListRight2($format = '', $right = '', $selected = '', $acl_location = '', $extra = '', $default = '')
	{
		if (is_array($format))
		{
			$data = $format;
			$format = isset($data['format']) ? $data['format'] : '';
			$right = isset($data['right']) ? $data['right'] : '';
			$selected = isset($data['selected']) && is_array($data['selected']) ? $data['selected'][0] : (isset($data['selected']) ? $data['selected'] : '');
			$acl_location = isset($data['acl_location']) ? $data['acl_location'] : '';
			$extra = isset($data['extra']) ? $data['extra'] : '';
			$default = isset($data['default']) ? $data['default'] : '';
		}

		$this->addUserListTemplate($format, $this->xsl_rootdir);
		$selected = $this->resolveSelectedDefault($selected, $default);

		$users_extra = $this->buildUsersExtraList($extra);
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
			$name = (isset($user['account_lastname']) ? $user['account_lastname'] . ' ' : '') . $user['account_firstname'];
			$user_list[] = array(
				'id' => $user['account_id'],
				'name' => $name,
				'selected' => $user['account_id'] == $selected ? 1 : 0
			);

			if (!$selected_found)
			{
				$selected_found = $user['account_id'] == $selected ? true : false;
			}
		}

		if ($selected && !$selected_found)
		{
			$user_list[] = array(
				'id' => $selected,
				'name' => $this->accounts->get($selected)->__toString(),
				'selected' => 1
			);
		}

		return $user_list;
	}

	public function addContactTemplate($type, $xsl_rootdir)
	{
		switch ($type)
		{
			case 'view':
				\phpgwapi_xslttemplates::getInstance()->add_file(array('contact_view'), $xsl_rootdir);
				break;
			case 'form':
				\phpgwapi_xslttemplates::getInstance()->add_file(array('contact_form'), $xsl_rootdir);
				break;
		}
	}

	public function resolveSelectedDefault($selected, $default)
	{
		if (!$selected && $default)
		{
			$selected = $default;
		}

		return $selected;
	}

	public function buildUsersExtraListRight($extra)
	{
		$users_extra = array();

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

		return $users_extra;
	}

	public function buildUsersExtraList($extra)
	{
		$users_extra = array();

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

		return $users_extra;
	}

	public function addViewFormTemplate($entity_type, $type, $xsl_rootdir)
	{
		$template = ($type === 'view') ? "{$entity_type}_view" : "{$entity_type}_form";
		\phpgwapi_xslttemplates::getInstance()->add_file(array($template), $xsl_rootdir);
	}

	public function addPartOfTownTemplate($format, $xsl_rootdir)
	{
		switch ($format)
		{
			case 'select':
				\phpgwapi_xslttemplates::getInstance()->add_file(array('select_part_of_town'), $xsl_rootdir);
				break;
			case 'filter':
				\phpgwapi_xslttemplates::getInstance()->add_file(array('filter_part_of_town'), $xsl_rootdir);
				break;
		}
	}

	public function addDistrictTemplate($format, $xsl_rootdir)
	{
		switch ($format)
		{
			case 'select':
				\phpgwapi_xslttemplates::getInstance()->add_file(array('select_district'), $xsl_rootdir);
				break;
			case 'filter':
				\phpgwapi_xslttemplates::getInstance()->add_file(array('filter_district'), $xsl_rootdir);
				break;
		}
	}

	public function addCategoryTemplate($format, $xsl_rootdir)
	{
		switch ($format)
		{
			case 'select':
				\phpgwapi_xslttemplates::getInstance()->add_file(array('cat_select'), $xsl_rootdir);
				break;
			case 'filter':
				\phpgwapi_xslttemplates::getInstance()->add_file(array('cat_filter'), $xsl_rootdir);
				break;
		}
	}

	public function addConditionalViewFormTemplate($entity_type, $type, $xsl_rootdir)
	{
		if (isset($type) && $type == 'view')
		{
			\phpgwapi_xslttemplates::getInstance()->add_file(array("{$entity_type}_view"), $xsl_rootdir);
		}
		else
		{
			\phpgwapi_xslttemplates::getInstance()->add_file(array("{$entity_type}_form"), $xsl_rootdir);
		}
	}

	public function isViewWithoutLookupId($data, $id_key)
	{
		return isset($data['type'])
			&& $data['type'] == 'view'
			&& (!isset($data[$id_key]) || !$data[$id_key]);
	}

	public function resolveLookupType($data, $default = 'form')
	{
		return isset($data['type']) ? $data['type'] : $default;
	}

	public function hasLookupIdWithoutDisplayValue($data, $id_key, $display_key)
	{
		return isset($data[$id_key])
			&& $data[$id_key]
			&& (!isset($data[$display_key]) || !$data[$display_key]);
	}

	public function addFormFlags(&$output, $data)
	{
		if (isset($data['disabled']))
		{
			$output['disabled'] = $data['disabled'] ? true : false;
		}
		if (isset($data['required']))
		{
			$output['required'] = $data['required'] ? true : false;
		}
	}

	public function buildLookupUrl($entity_name, $params = array())
	{
		$base_params = array('menuaction' => 'property.uilookup.' . $entity_name);
		return \phpgw::link('/index.php', array_merge($base_params, $params));
	}

	public function addEntityLabels(&$output, $entity_type, $data = array())
	{
		switch ($entity_type)
		{
			case 'vendor':
				$output['lang_vendor'] = lang('Vendor');
				$output['lang_select_vendor_help'] = lang('click this link to select vendor');
				$output['lang_vendor_name'] = lang('Vendor Name');
				break;

			case 'tenant':
				if (isset($data['role']) && $data['role'] == 'customer')
				{
					$output['lang_select_tenant_help'] = lang('click this link to select customer');
					$output['lang_tenant'] = lang('Customer');
				}
				else
				{
					$output['lang_select_tenant_help'] = lang('click this link to select tenant');
					$output['lang_tenant'] = lang('Tenant');
				}
				break;

			case 'b_account':
				$output['lang_select_b_account_help'] = lang('click this link to select budget account');
				$output['lang_b_account'] = isset($data['role']) && $data['role'] == 'group' ? lang('budget account group') : lang('Budget account');
				break;

			case 'external_project':
				$output['lang_select_external_project_help'] = lang('click to select external project');
				$output['lang_external_project'] = lang('external project');
				break;

			case 'ecodimb':
				$output['lang_select_ecodimb_help'] = lang('click to select dimb');
				$output['lang_ecodimb'] = lang('dimb');
				break;
		}
	}

	public function readSingleFromSogeneric($location, $id)
	{
		$sogeneric = CreateObject('property.sogeneric');
		$sogeneric->get_location_info($location, false);

		return $sogeneric->read_single(array('id' => $id));
	}

	public function readSingleFromSogenericWithAttributes($location, $attribute_location, $id)
	{
		$sogeneric = CreateObject('property.sogeneric');
		$sogeneric->get_location_info($location, false);

		$custom = createObject('property.custom_fields');
		$entity_data = array();
		$entity_data['attributes'] = $custom->find('property', $attribute_location, 0, '', 'ASC', 'attrib_sort', true, true);

		return $sogeneric->read_single(array('id' => $id), $entity_data);
	}

	public function addContactLookupMetadata(&$output, $field)
	{
		$output['field'] = $field;
		$output['contact_link'] = \phpgw::link('/index.php', array(
			'menuaction' => 'property.uilookup.addressbook',
			'column' => $field,
			'clear_state' => 1
		));
		$output['lang_contact'] = lang('contact');
		$output['lang_select_contact_help'] = lang('click this link to select');
	}

	public function readContactEntry($contact_id)
	{
		$contacts = CreateObject('phpgwapi.contacts');
		$contact_data = $contacts->read_single_entry($contact_id, array(
			'fn',
			'tel_work',
			'email'
		));

		return array(
			'value_contact_name' => $contact_data[0]['fn'],
			'value_contact_email' => $contact_data[0]['email'],
			'value_contact_tel' => $contact_data[0]['tel_work']
		);
	}

	public function addContactFallbackFromPreferences(&$contact, $contact_id)
	{
		$user_id = createObject('property.soresponsible')->get_contact_user_id($contact_id);
		$prefs = $this->socommon->create_preferences('common', $user_id);
		$contact['value_contact_email'] = $prefs['email'];
		$contact['value_contact_tel'] = $prefs['cellphone'];
	}

	public function getAttributeValueByName($attributes, $attribute_name, $default = null)
	{
		if (!is_array($attributes))
		{
			return $default;
		}

		foreach ($attributes as $attribute)
		{
			if (isset($attribute['name']) && $attribute['name'] == $attribute_name)
			{
				return isset($attribute['value']) ? $attribute['value'] : $default;
			}
		}

		return $default;
	}

	public function initiateEventLookup($data)
	{
		$event = array();
		$event['name'] = $data['name'];
		$event['event_name'] = $data['event_name'];
		if (isset($data['type']) && $data['type'] == 'view')
		{
			$this->addViewFormTemplate('event', 'view', $this->xsl_rootdir);
			if (!isset($data['event']) || !$data['event'])
			{
				// Intentionally keeping legacy no-op branch for parity.
			}
		}
		else
		{
			$this->addViewFormTemplate('event', 'form', $this->xsl_rootdir);
		}

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
			$event['value'] = $data['event_id'];
			$event_info = execMethod('property.soevent.read_single', $data['event_id']);
			$event['descr'] = $event_info['descr'];
			$event['enabled'] = $event_info['enabled'] ? lang('yes') : lang('no');
			$event['lang_enabled'] = lang('enabled');

			$job_id = "property{$data['location']}::{$data['item_id']}::{$data['name']}";
			$job = execMethod('phpgwapi.asyncservice.read', $job_id);

			$event['next'] = $this->phpgwapi_common->show_date($job[$job_id]['next'], $this->userSettings['preferences']['common']['dateformat']);
			$event['lang_next_run'] = lang('next run');

			$criteria = array(
				'start_date' => $event_info['start_date'],
				'end_date' => $event_info['end_date'],
				'location_id' => $event_info['location_id'],
				'location_item_id' => $event_info['location_item_id']
			);

			$event['count'] = 0;
			$boevent = CreateObject('property.boevent');
			$boevent->find_scedules($criteria);
			$schedules = $boevent->cached_events;
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
				$c = CreateObject('phpgwapi.contacts');
				$qfields = array(
					'contact_id' => 'contact_id',
					'per_full_name' => 'per_full_name',
				);

				$criteria = array('contact_id' => $event['responsible_id']);
				$contacts = $c->get_persons($qfields, 15, 0, '', '', $criteria);
				$event['responsible'] = $contacts[0]['per_full_name'];
			}

			unset($event_info);
			unset($job_id);
			unset($job);
		}

		$event['event_link'] = \phpgw::link(
			'/index.php',
			array(
				'menuaction' => 'property.uievent.edit',
				'location' => $data['location'],
				'attrib_id' => $event['name'],
				'item_id' => isset($event['item_id']) ? $event['item_id'] : '',
				'id' => isset($event['value']) && $event['value'] ? $event['value'] : ''
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

	public function initiate_ui_vendorlookup($data)
	{
		$this->addViewFormTemplate('vendor', $this->resolveLookupType($data), $this->xsl_rootdir);

		$vendor['value_vendor_id'] = $data['vendor_id'];
		$vendor['value_vendor_name'] = $data['vendor_name'];

		if ($this->hasLookupIdWithoutDisplayValue($data, 'vendor_id', 'vendor_name'))
		{
			$vendor_data = $this->readSingleFromSogenericWithAttributes('vendor', '.vendor', $data['vendor_id']);
			if (is_array($vendor_data))
			{
				$org_name = $this->getAttributeValueByName(isset($vendor_data['attributes']) ? $vendor_data['attributes'] : null, 'org_name');
				if ($org_name !== null)
				{
					$vendor['value_vendor_name'] = $org_name;
				}
			}
		}

		$vendor['vendor_link'] = $this->buildLookupUrl('vendor');
		$this->addEntityLabels($vendor, 'vendor', $data);
		$this->addFormFlags($vendor, $data);

		return $vendor;
	}

	public function initiate_ui_contact_lookup($data)
	{
		$field = $data['field'];
		if (!empty($data['type']))
		{
			$this->addContactTemplate($data['type'], $this->xsl_rootdir);
		}

		$contact['value_contact_id'] = $data['contact_id'];

		if ($this->hasLookupIdWithoutDisplayValue($data, 'contact_id', 'contact_name'))
		{
			$contact_entry = $this->readContactEntry($data['contact_id']);
			$contact = array_merge($contact, $contact_entry);

			if (!$contact['value_contact_email'])
			{
				$this->addContactFallbackFromPreferences($contact, $data['contact_id']);
			}
		}

		$this->addContactLookupMetadata($contact, $field);

		return $contact;
	}

	public function initiate_ui_tenant_lookup($data)
	{
		$this->addViewFormTemplate('tenant', $this->resolveLookupType($data), $this->xsl_rootdir);

		$tenant['value_tenant_id'] = $data['tenant_id'];
		$tenant['value_first_name'] = $data['first_name'];
		$tenant['value_last_name'] = $data['last_name'];
		$tenant['tenant_link'] = $this->buildLookupUrl('tenant');
		$this->addEntityLabels($tenant, 'tenant', $data);

		if ($this->hasLookupIdWithoutDisplayValue($data, 'tenant_id', 'tenant_name'))
		{
			$tenant_data = $this->readSingleFromSogenericWithAttributes('tenant', '.tenant', $data['tenant_id']);
			if (is_array($tenant_data['attributes']))
			{
				$first_name = $this->getAttributeValueByName($tenant_data['attributes'], 'first_name');
				if ($first_name !== null)
				{
					$tenant['value_first_name'] = $first_name;
				}

				$last_name = $this->getAttributeValueByName($tenant_data['attributes'], 'last_name');
				if ($last_name !== null)
				{
					$tenant['value_last_name'] = $last_name;
				}
			}
		}

		return $tenant;
	}

	public function initiate_ui_budget_account_lookup($data)
	{
		$this->addViewFormTemplate('b_account', $this->resolveLookupType($data), $this->xsl_rootdir);

		$b_account['value_b_account_id'] = $data['b_account_id'];
		$b_account['value_b_account_name'] = $data['b_account_name'];
		$b_account['b_account_link'] = $this->buildLookupUrl('b_account', array(
			'role' => isset($data['role']) && $data['role'] ? $data['role'] : '',
			'parent' => isset($data['parent']) && $data['parent'] ? $data['parent'] : '',
		));
		$this->addEntityLabels($b_account, 'b_account', $data);
		if ($this->hasLookupIdWithoutDisplayValue($data, 'b_account_id', 'b_account_name'))
		{
			$location = (isset($data['role']) && $data['role'] == 'group') ? 'b_account' : 'budget_account';
			$b_account_data = $this->readSingleFromSogeneric($location, $data['b_account_id']);
			$b_account['value_b_account_name'] = $b_account_data['descr'];
		}

		$this->addFormFlags($b_account, $data);
		return $b_account;
	}

	public function initiate_external_project_lookup($data)
	{
		$external_project = array();

		if ($this->isViewWithoutLookupId($data, 'external_project_id'))
		{
			return $external_project;
		}

		$this->addConditionalViewFormTemplate('external_project', $this->resolveLookupType($data), $this->xsl_rootdir);

		$external_project['value_external_project_id'] = $data['external_project_id'];
		$external_project['value_external_project_name'] = $data['external_project_name'];
		$external_project['external_project_url'] = $this->buildLookupUrl('external_project');
		$this->addEntityLabels($external_project, 'external_project', $data);
		if ($this->hasLookupIdWithoutDisplayValue($data, 'external_project_id', 'external_project_name'))
		{
			$external_project_data = $this->readSingleFromSogeneric('external_project', $data['external_project_id']);
			$external_project['value_external_project_name'] = $external_project_data['name'];
			$external_project['value_external_project_budget'] = $external_project_data['budget'];
		}
		return $external_project;
	}

	public function initiate_ecodimb_lookup($data)
	{
		$ecodimb = array();

		if ($this->isViewWithoutLookupId($data, 'ecodimb'))
		{
			return $ecodimb;
		}

		$this->addConditionalViewFormTemplate('ecodimb', $this->resolveLookupType($data), $this->xsl_rootdir);

		$ecodimb['value_ecodimb'] = $data['ecodimb'];
		$ecodimb['value_ecodimb_descr'] = $data['ecodimb_descr'];
		$ecodimb['ecodimb_url'] = $this->buildLookupUrl('ecodimb');
		$this->addEntityLabels($ecodimb, 'ecodimb', $data);
		if ($this->hasLookupIdWithoutDisplayValue($data, 'ecodimb', 'ecodimb_descr'))
		{
			$ecodimb_data = $this->readSingleFromSogeneric('dimb', $data['ecodimb']);
			$ecodimb['value_ecodimb_descr'] = $ecodimb_data['descr'];
		}
		$this->addFormFlags($ecodimb, $data);

		return $ecodimb;
	}

	public function initiateUiAlarm($data)
	{
		$boalarm = CreateObject('property.boalarm');

		$this->addViewFormTemplate('alarm', $data['type'], $this->xsl_rootdir);

		$alarm['header'][] = array(
			'lang_time' => lang('Time'),
			'lang_text' => lang('Text'),
			'lang_user' => lang('User'),
			'lang_enabled' => lang('Enabled'),
			'lang_select' => lang('Select')
		);

		$alarm['values'] = $boalarm->read_alarms($data['alarm_type'], $data['id'], $data['text']);

		if ($data['type'] == 'form')
		{
			$alarm['alter_alarm'][] = array(
				'lang_enable' => lang('Enable'),
				'lang_disable' => lang('Disable'),
				'lang_delete' => lang('Delete')
			);

			for ($i = 1; $i <= 31; $i++)
			{
				$alarm['add_alarm']['day_list'][($i - 1)]['id'] = $i;
				if ($i == 14)
				{
					$alarm['add_alarm']['day_list'][($i - 1)]['selected'] = 'selected';
				}
			}

			$alarm['add_alarm']['lang_day'] = lang('Day');
			$alarm['add_alarm']['lang_day_statustext'] = lang('Day');

			for ($i = 1; $i <= 24; $i++)
			{
				$alarm['add_alarm']['hour_list'][($i - 1)]['id'] = $i;
			}
			$alarm['add_alarm']['lang_hour'] = lang('Hour');
			$alarm['add_alarm']['lang_hour_statustext'] = lang('Hour');

			for ($i = 1; $i <= 60; $i++)
			{
				$alarm['add_alarm']['minute_list'][($i - 1)]['id'] = $i;
			}
			$alarm['add_alarm']['lang_minute'] = lang('Minutes before the event');
			$alarm['add_alarm']['lang_minute_statustext'] = lang('Minutes before the event');

			$alarm['add_alarm']['user_list'] = $this->get_user_list_right2(
				'select',
				4,
				false,
				$data['acl_location'],
				false,
				$this->account
			);

			$alarm['add_alarm']['lang_user'] = lang('User');
			$alarm['add_alarm']['lang_user_statustext'] = lang('Select the user the alarm belongs to.');
			$alarm['add_alarm']['lang_no_user'] = lang('No user');
			$alarm['add_alarm']['lang_add'] = lang('Add');
			$alarm['add_alarm']['lang_add_alarm'] = lang('Add alarm');
			$alarm['add_alarm']['lang_add_statustext'] = lang('Add alarm for selected user');
		}

		return $alarm;
	}

	public function generateSql($data)
	{
		$cols = isset($data['cols']) ? $data['cols'] : '';
		$entity_table = isset($data['entity_table']) ? $data['entity_table'] : '';
		$location_table = isset($data['location_table']) ? $data['location_table'] : '';
		$cols_return = isset($data['cols_return']) && $data['cols_return'] ? $data['cols_return'] : array();
		$uicols = isset($data['uicols']) && $data['uicols'] ? $data['uicols'] : array();
		$joinmethod = isset($data['joinmethod']) ? $data['joinmethod'] : '';
		$paranthesis = isset($data['paranthesis']) ? $data['paranthesis'] : '';
		$lookup = isset($data['lookup']) ? $data['lookup'] : '';
		$location_level = isset($data['location_level']) && $data['location_level'] > 0 ? (int)$data['location_level'] : 0;
		$no_address = isset($data['no_address']) ? $data['no_address'] : '';
		$force_location = isset($data['force_location']) ? $data['force_location'] : '';
		$cols_extra = array();
		$cols_return_lookup = array();

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

		$soadmin_location = CreateObject('property.soadmin_location');
		$location_types = $soadmin_location->select_location_type();
		$config = $soadmin_location->read_config('');

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
			$cols .= ",fm_location1.loc1_name";
			$joinmethod .= " {$this->join}  fm_location1 ON ({$_location_table}.loc1 = fm_location1.loc1))";
			$paranthesis .= '(';
			$joinmethod .= " {$this->join}  fm_part_of_town ON (fm_location1.part_of_town_id = fm_part_of_town.id))";
			$paranthesis .= '(';
			$joinmethod .= " {$this->join}  fm_owner ON (fm_location1.owner_id = fm_owner.id))";
			$paranthesis .= '(';
		}
		else
		{
			$type_id = 0;
			$no_address = true;
		}

		$_level = 1;
		for ($i = 0; $i < $type_id; $i++)
		{
			if ($_level > 1)
			{
				$joinmethod .= " {$this->left_join} fm_location{$_level}";
				$paranthesis .= '(';
				$on = 'ON';
				for ($k = ($_level - 1); $k > 0; $k--)
				{
					$joinmethod .= " $on (fm_location{$_level}.loc{$k} = fm_location" . ($_level - 1) . ".loc{$k} AND  fm_location{$_level}.loc{$_level} = $entity_table.loc{$_level})";
					$on = 'AND';
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
				$i = $_level - 1;
				$uicols['input_type'][] = 'text';
				$uicols['name'][] = 'loc' . $location_types[$i]['id'];
				$uicols['descr'][] = $location_types[$i]['name'];
				$uicols['statustext'][] = $location_types[$i]['descr'];
				$uicols['exchange'][] = false;
				$uicols['align'][] = '';
				$uicols['datatype'][] = '';
				$uicols['formatter'][] = '';
				$uicols['classname'][] = '';
				$uicols['sortable'][] = $_level == 1;
			}
		}

		unset($soadmin_location);

		for ($i = 0; $i < $type_id; $i++)
		{
			$cols_return[] = 'loc' . $location_types[$i]['id'];
		}

		$lang_name = lang('name');
		$location_relation_data = array();
		$custom = createObject('property.custom_fields');
		for ($i = 1; $i < ($type_id + 1); $i++)
		{
			$cols .= ",loc{$i}_name";
			$cols_return[] = "loc{$i}_name";
			$cols_extra[] = "loc{$i}_name";
			$cols_return_lookup[] = "loc{$i}_name";
			$uicols['input_type'][] = in_array($i, $list_location_level) ? 'text' : 'hidden';
			$uicols['name'][] = "loc{$i}_name";
			$uicols['descr'][] = "{$location_types[($i - 1)]['name']} {$lang_name}";
			$uicols['statustext'][] = $location_types[$i - 1]['descr'];
			$uicols['exchange'][] = $lookup;
			$uicols['align'][] = '';
			$uicols['datatype'][] = '';
			$uicols['formatter'][] = '';
			$uicols['classname'][] = '';
			$uicols['sortable'][] = $i == 1;

			$fm_location_cols_temp = $custom->find('property', '.location.' . $i, 0, '', '', '', true);
			foreach ($fm_location_cols_temp as $entry)
			{
				if ($entry['lookup_form'])
				{
					$location_relation_data[] = array(
						'level' => $i,
						'name' => $entry['name'],
						'descr' => $entry['input_text'],
						'status_text' => $entry['status_text'],
						'datatype' => $entry['datatype'],
					);
				}
			}
		}

		Cache::system_set('property', 'location_relation_data', $location_relation_data);

		if (!$no_address)
		{
			$cols .= ",$entity_table.address";
			$cols_return[] = 'address';
			$uicols['input_type'][] = 'text';
			$uicols['name'][] = 'address';
			$uicols['descr'][] = lang('address');
			$uicols['statustext'][] = lang('address');
			$uicols['exchange'][] = false;
			$uicols['align'][] = '';
			$uicols['datatype'][] = '';
			$uicols['formatter'][] = '';
			$uicols['classname'][] = '';
			$uicols['sortable'][] = true;
		}

		$config_count = count($config);
		for ($i = 0; $i < $config_count; $i++)
		{
			if (($config[$i]['location_type'] <= $type_id) && ($config[$i]['query_value'] == 1))
			{
				if ($config[$i]['column_name'] == 'street_id')
				{
					$cols_return[] = 'street_name';
					$uicols['input_type'][] = 'hidden';
					$uicols['name'][] = 'street_name';
					$uicols['descr'][] = lang('street name');
					$uicols['statustext'][] = lang('street name');
					$uicols['exchange'][] = false;
					$uicols['align'][] = '';
					$uicols['datatype'][] = '';
					$uicols['formatter'][] = '';
					$uicols['classname'][] = '';
					$uicols['sortable'][] = true;

					$cols_return[] = 'street_number';
					$uicols['input_type'][] = 'hidden';
					$uicols['name'][] = 'street_number';
					$uicols['descr'][] = lang('street number');
					$uicols['statustext'][] = lang('street number');
					$uicols['exchange'][] = false;
					$uicols['align'][] = '';
					$uicols['datatype'][] = '';
					$uicols['formatter'][] = '';
					$uicols['classname'][] = '';
					$uicols['sortable'][] = '';

					$cols_return[] = $config[$i]['column_name'];
					$uicols['input_type'][] = 'hidden';
					$uicols['name'][] = $config[$i]['column_name'];
					$uicols['descr'][] = lang($config[$i]['input_text']);
					$uicols['statustext'][] = lang($config[$i]['input_text']);
					$uicols['exchange'][] = false;
					$uicols['align'][] = '';
					$uicols['datatype'][] = '';
					$uicols['formatter'][] = '';
					$uicols['classname'][] = '';
					$uicols['sortable'][] = '';

					if ($lookup)
					{
						$cols_extra[] = 'street_name';
						$cols_extra[] = 'street_number';
						$cols_extra[] = $config[$i]['column_name'];
					}
				}
				else
				{
					$cols_return[] = $config[$i]['column_name'];
					$uicols['input_type'][] = 'text';
					$uicols['name'][] = $config[$i]['column_name'];
					$uicols['descr'][] = $config[$i]['input_text'];
					$uicols['statustext'][] = $config[$i]['input_text'];
					$uicols['exchange'][] = false;
					$uicols['align'][] = '';
					$uicols['datatype'][] = '';
					$uicols['formatter'][] = '';
					$uicols['classname'][] = '';
					$uicols['sortable'][] = '';

					if ($lookup)
					{
						$cols_extra[] = $config[$i]['column_name'];
					}
				}
			}
		}

		$from = " FROM $paranthesis $entity_table ";
		$sql = "SELECT DISTINCT $cols $from $joinmethod";

		return array(
			'sql' => $sql,
			'type_id' => $type_id,
			'uicols' => $uicols,
			'cols_return' => $cols_return,
			'cols_extra' => $cols_extra,
			'cols_return_lookup' => $cols_return_lookup,
		);
	}

	public function performDownload($list, $name, $descr, $input_type = array(), $identificator = array(), $filename = '', $export_options = array())
	{
		set_time_limit(500);
		$this->flags['noheader'] = true;
		$this->flags['nofooter'] = true;
		$this->flags['xslt_app'] = false;
		Settings::getInstance()->set('flags', $this->flags);

		$export_format = isset($this->userSettings['preferences']['common']['export_format']) && $this->userSettings['preferences']['common']['export_format'] ? $this->userSettings['preferences']['common']['export_format'] : 'csv';
		$php_version = (float)PHP_VERSION;

		$html2text = null;
		if (isset($list) && is_array($list))
		{
			foreach ($list as &$entry)
			{
				foreach ($entry as $col => &$value)
				{
					if (is_string($value) && $value !== '' && $this->containsHtmlMarkup($value))
					{
						if ($html2text === null)
						{
							$html2text = createObject('phpgwapi.html2text');
						}
						$html2text->setHTML($value);
						$value = trim($html2text->getText());
					}
				}
			}
		}
		switch ($export_format)
		{
			case 'csv':
				$this->performCsvOut($list, $name, $descr, $input_type, $identificator, $filename);
				break;
			case 'excel':
				if (!empty($export_options['additional_sheets']))
				{
					$this->performPhpspreadsheetOut($list, $name, $descr, $input_type, $identificator, $filename, 'excel', $export_options);
				}
				else
				{
					$this->performXlsxOut($list, $name, $descr, $input_type, $identificator, $filename);
				}
				break;
			case 'ods':
				$this->performPhpspreadsheetOut($list, $name, $descr, $input_type, $identificator, $filename, 'ods', $export_options);
				break;
		}

		return $this->flags;
	}

	private function containsHtmlMarkup($value)
	{
		if (!is_string($value) || $value === '')
		{
			return false;
		}

		if (strpos($value, '<') === false || strpos($value, '>') === false)
		{
			return false;
		}

		return preg_match('/<\s*\/?\s*[a-z][^>]*>/i', $value) === 1;
	}

	public function performPhpspreadsheetOut($list, $name, $descr, $input_type = array(), $identificator = array(), $filename = '', $export_format = 'excel', $export_options = array())
	{
		if ($filename)
		{
			$filename_arr = explode('.', str_replace(' ', '_', basename($filename)));
			$filename = $filename_arr[0];
		}
		else
		{
			$filename = str_replace(' ', '_', $this->userSettings['account_lid']);
		}
		$date_time = str_replace(array(' ', '/'), '_', $this->phpgwapi_common->show_date(time()));

		switch ($export_format)
		{
			case 'excel':
				$suffix = 'xlsx';
				break;
			case 'ods':
				$suffix = 'ods';
				break;
			default:
				$suffix = 'xlsx';
				break;
		}
		$filename .= "_{$date_time}.{$suffix}";

		$browser = CreateObject('phpgwapi.browser');
		$content_type = $export_format === 'ods'
			? 'application/vnd.oasis.opendocument.spreadsheet'
			: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
		$browser->content_header($filename, $content_type);

		$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

		$spreadsheet->getProperties()->setCreator($this->userSettings['fullname'])
			->setLastModifiedBy($this->userSettings['fullname'])
			->setTitle("Download from {$this->serverSettings['system_name']}")
			->setSubject("Office 2007 XLSX Document")
			->setDescription("document for Office 2007 XLSX, generated using PHP classes.")
			->setKeywords("office 2007 openxml php")
			->setCategory("downloaded file");

		$spreadsheet->setActiveSheetIndex(0);

		if ($identificator)
		{
			$_first_row = 2;
			$i = 1;
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
		$m = 1;
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
			$content = array();
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
				$col = 'A';
				$line++;
				$rows = count($row);
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

		$this->appendAdditionalSheets($spreadsheet, $export_options);

		$spreadsheet->setActiveSheetIndex(0);

		if ($export_format === 'ods')
		{
			$objWriter = new \PhpOffice\PhpSpreadsheet\Writer\Ods($spreadsheet);
		}
		else
		{
			$objWriter = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
		}
		$objWriter->save('php://output');
	}

	private function appendAdditionalSheets(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet, array $export_options)
	{
		if (empty($export_options['additional_sheets']) || !is_array($export_options['additional_sheets']))
		{
			return;
		}

		$sheet_index = 0;
		foreach ($export_options['additional_sheets'] as $sheet_data)
		{
			$columns = isset($sheet_data['columns']) && is_array($sheet_data['columns']) ? $sheet_data['columns'] : array();
			if (!$columns)
			{
				continue;
			}

			$title = isset($sheet_data['title']) ? trim((string)$sheet_data['title']) : '';
			if ($title === '')
			{
				$title = "Sheet_" . ($sheet_index + 2);
			}
			$title = mb_substr($title, 0, 31);
			$base_title = $title;
			$suffix = 1;
			while ($spreadsheet->getSheetByName($title) !== null)
			{
				$suffix_text = '_' . $suffix;
				$trim_length = 31 - strlen($suffix_text);
				$title = mb_substr($base_title, 0, $trim_length) . $suffix_text;
				$suffix++;
			}

			$sheet = $spreadsheet->createSheet();
			$sheet->setTitle($title);

			$col_index = 1;
			foreach ($columns as $column)
			{
				$header = isset($column['header']) ? (string)$column['header'] : "Column {$col_index}";
				$sheet->setCellValue([$col_index, 1], $header);

				$values = isset($column['values']) && is_array($column['values']) ? array_values($column['values']) : array();
				$row_index = 2;
				foreach ($values as $value)
				{
					$sheet->setCellValue([$col_index, $row_index], (string)$value);
					$row_index++;
				}

				$col_index++;
			}

			$sheet_index++;
		}
	}

	public function performXlsxOut($list, $name, $descr, $input_type = array(), $identificator = array(), $filename = '')
	{
		if ($filename)
		{
			$filename_arr = explode('.', str_replace(' ', '_', basename($filename)));
			$filename = $filename_arr[0];
		}
		else
		{
			$filename = str_replace(' ', '_', $this->userSettings['account_lid']);
		}
		$date_time = str_replace(array(' ', '/'), '_', $this->phpgwapi_common->show_date(time()));
		$filename .= "_{$date_time}.xlsx";

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

		$m = 0;
		$formats = array();
		for ($k = 0; $k < $count_uicols_name; $k++)
		{
			if (!isset($input_type[$k]) || $input_type[$k] != 'hidden')
			{
				if (preg_match('/^loc/i', $name[$k]))
				{
					$header[$descr[$k]] = 'string';
					$formats[$m] = 'string';
				}
			}
			$m++;
		}

		$_header = array();
		if ($identificator)
		{
			$_identificator = array_values($identificator);
			$i = 0;
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

	public function performCsvOut($list, $name, $descr, $input_type = array(), $identificator = array(), $filename = '')
	{
		if ($filename)
		{
			$filename_arr = explode('.', str_replace(' ', '_', basename($filename)));
			$filename = $filename_arr[0];
		}
		else
		{
			$filename = str_replace(' ', '_', $this->userSettings['account_lid']);
		}
		$date_time = str_replace(array(' ', '/'), '_', $this->phpgwapi_common->show_date(time()));
		$filename .= "_{$date_time}.csv";

		$browser = CreateObject('phpgwapi.browser');
		$browser->content_header($filename, 'application/csv');

		if (!$fp = fopen('php://output', 'w'))
		{
			die('Unable to write to "php://output" - pleace notify the Administrator');
		}

		$BOM = "\xEF\xBB\xBF";
		fwrite($fp, $BOM);

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

	public function collect_locationdata($values = array(), $insert_record = array())
	{
		if ($insert_record)
		{
			if (isset($insert_record['location']) && is_array($insert_record['location']))
			{
				for ($i = 0; $i < count($insert_record['location']); $i++)
				{
					if (isset($_POST[$insert_record['location'][$i]]) && $_POST[$insert_record['location'][$i]])
					{
						$values['location'][$insert_record['location'][$i]] = \Sanitizer::get_var($insert_record['location'][$i], 'string', 'POST');
					}
				}
			}

			if (isset($insert_record['extra']) && is_array($insert_record['extra']))
			{
				foreach ($insert_record['extra'] as $key => $column)
				{
					if (isset($_POST[$key]) && $_POST[$key])
					{
						$values['extra'][$column] = \Sanitizer::get_var($key, 'string', 'POST');
					}
				}

				if (isset($values['extra']['p_entity_id']) && $values['extra']['p_entity_id'] && isset($values['extra']['p_cat_id']) && $values['extra']['p_cat_id'] && isset($values['extra']['p_num']) && $values['extra']['p_num'])
				{
					$values['extra']['p_num'] = execMethod(
						'property.soentity.convert_num_to_id',
						array(
							'type' => $values['extra']['type'],
							'entity_id' => $values['extra']['p_entity_id'],
							'cat_id' => $values['extra']['p_cat_id'],
							'num' => $values['extra']['p_num']
						)
					);

					$p_entity_id = $values['extra']['p_entity_id'];
					$p_cat_id = $values['extra']['p_cat_id'];
					$p_num = $values['extra']['p_num'];
					$values['p'][$p_entity_id]['p_entity_id'] = $p_entity_id;
					$values['p'][$p_entity_id]['p_cat_id'] = $p_cat_id;
					$values['p'][$p_entity_id]['p_num'] = $p_num;
					$values['p'][$p_entity_id]['p_cat_name'] = \Sanitizer::get_var("entity_cat_name_{$p_entity_id}");
				}
			}
			if (isset($insert_record['additional_info']) && is_array($insert_record['additional_info']))
			{
				foreach ($insert_record['additional_info'] as $additional_info)
				{
					if ($additional_info_value = \Sanitizer::get_var($additional_info['input_name'], 'string', 'POST'))
					{
						$values['additional_info'][$additional_info['input_text']] = $additional_info_value;
					}
				}
			}
		}

		$values['extra'] = isset($values['extra']) && $values['extra'] ? $values['extra'] : array();
		$values['street_name'] = \Sanitizer::get_var('street_name');
		$values['street_number'] = \Sanitizer::get_var('street_number');
		if (isset($values['location']) && is_array($values['location']))
		{
			$values['location_name'] = \Sanitizer::get_var('loc' . (count($values['location'])) . '_name', 'string', 'POST');
			$values['location_code'] = implode('-', $values['location']);
		}
		if ($values['location_code'])
		{
			$bolocation = CreateObject('property.bolocation');
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

		$origin = isset($values['origin']) && $values['origin'] ? $values['origin'] : false;
		$origin_id = isset($values['origin_id']) && $values['origin_id'] ? $values['origin_id'] : false;

		if ($origin == '.ticket' && $origin_id && !$values['descr'])
		{
			$boticket = CreateObject('property.botts');
			$ticket = $boticket->read_single($origin_id);
			$values['descr'] = strip_tags($ticket['details']);
			$values['name'] = $ticket['subject'] ? $ticket['subject'] : $ticket['category_name'];
			$ticket_notes = $boticket->read_additional_notes($origin_id);
			$i = count($ticket_notes) - 1;
			if (isset($ticket_notes[$i]['value_note']) && $ticket_notes[$i]['value_note'])
			{
				$values['descr'] .= ": " . $ticket_notes[$i]['value_note'];
			}
			$values['contact_id'] = $ticket['contact_id'];
		}

		if (isset($origin) && $origin)
		{
			$interlink = CreateObject('property.interlink');
			$values['origin_data'][] = array(
				'location' => $origin,
				'descr' => $interlink->get_location_name($origin),
				'data' => array(
					array(
						'id' => $origin_id,
						'link' => $interlink->get_relation_link(array('location' => $origin), $origin_id)
					)
				)
			);
		}
		return $values;
	}

	/**
	 * Resolve location type level from location array or location_code.
	 */
	public static function resolveLocationTypeIdFromValues(array $values): int
	{
		if (!empty($values['location']) && is_array($values['location']))
		{
			$count = 0;
			foreach ($values['location'] as $key => $part)
			{
				if (is_string($key) && preg_match('/^loc\d+$/', $key) && (string)$part !== '')
				{
					$count++;
				}
			}

			if ($count > 0)
			{
				return $count;
			}
		}

		$locationCode = trim((string)($values['location_code'] ?? ''));
		if ($locationCode !== '')
		{
			$parts = array_values(array_filter(explode('-', $locationCode), static function ($part)
			{
				return $part !== '';
			}));

			if ($parts)
			{
				return count($parts);
			}
		}

		return 0;
	}

	/**
	 * Build map of payload field => label used for additional_info.
	 *
	 * @return array<string, string>
	 */
	public static function buildAdditionalInfoFieldMap(int $typeId): array
	{
		if ($typeId <= 0)
		{
			return array();
		}

		try
		{
			$boLocation = \CreateObject('property.bolocation');
			$attributes = $boLocation->find_attribute(".location.{$typeId}");
		}
		catch (\Throwable $e)
		{
			return array();
		}

		$map = array();
		foreach ((array)$attributes as $attribute)
		{
			$lookupForm = !empty($attribute['lookup_form']);
			$columnName = isset($attribute['column_name']) ? (string)$attribute['column_name'] : '';
			$inputText = isset($attribute['input_text']) ? (string)$attribute['input_text'] : '';

			if (!$lookupForm || $columnName === '' || $inputText === '')
			{
				continue;
			}

			$map[$columnName] = $inputText;
		}

		return $map;
	}

	/**
	 * Collect additional_info from payload using location custom-field definitions.
	 *
	 * @return array<string, string>
	 */
	public static function collectAdditionalInfoFromPayload(array $values, array $input): array
	{
		$typeId = self::resolveLocationTypeIdFromValues($values);
		$fieldMap = self::buildAdditionalInfoFieldMap($typeId);
		if (!$fieldMap)
		{
			return array();
		}

		$collected = array();
		foreach ($fieldMap as $inputName => $inputText)
		{
			$rawValue = $values[$inputName] ?? $input[$inputName] ?? null;

			if (is_array($rawValue) || $rawValue === null)
			{
				continue;
			}

			$value = trim((string)$rawValue);
			if ($value === '')
			{
				continue;
			}

			$label = \Sanitizer::clean_value((string)$inputText, 'string');
			$collected[$label] = \Sanitizer::clean_value($value, 'string');
		}

		return $collected;
	}

	/**
	 * Merge payload-derived additional_info into values.
	 */
	public static function mergeAdditionalInfoFromPayload(array $values, array $input): array
	{
		$additionalInfo = self::collectAdditionalInfoFromPayload($values, $input);
		if (!$additionalInfo)
		{
			return $values;
		}

		if (!isset($values['additional_info']) || !is_array($values['additional_info']))
		{
			$values['additional_info'] = array();
		}

		foreach ($additionalInfo as $key => $value)
		{
			if (!array_key_exists($key, $values['additional_info']) || $values['additional_info'][$key] === '')
			{
				$values['additional_info'][$key] = $value;
			}
		}

		return $values;
	}

	public function getMenu($app = 'property')
	{
		$this->flags['nonavbar'] = false;
		Settings::getInstance()->set('flags', $this->flags);

		if (!isset($this->userSettings['preferences']['property']['horisontal_menus']) || $this->userSettings['preferences']['property']['horisontal_menus'] == 'no')
		{
			return array('flags' => $this->flags, 'menu' => null);
		}
		\phpgwapi_xslttemplates::getInstance()->add_file(array('menu'), $this->xsl_rootdir);

		$menu = Cache::session_get("menu_{$app}", $this->flags['menu_selection']);

		if (!$menu)
		{
			$menu_gross = execMethod("{$app}.menu.get_menu", 'horisontal');
			$selection = explode('::', $this->flags['menu_selection']);
			$level = 0;
			$menu['navigation'] = $this->getSubMenu($menu_gross['navigation'], $selection, $level);
			Cache::session_set("menu_{$app}", isset($this->flags['menu_selection']) && $this->flags['menu_selection'] ? $this->flags['menu_selection'] : 'property_missing_selection', $menu);
			unset($menu_gross);
		}

		return array('flags' => $this->flags, 'menu' => $menu);
	}

	public function noAccess()
	{
		$this->flags['xslt_app'] = true;
		\phpgwapi_xslttemplates::getInstance()->add_file(array('no_access', 'menu'), $this->xsl_rootdir);

		$receipt['error'][] = array('msg' => lang('NO ACCESS'));
		$msgbox_data = $this->msgboxData($receipt);

		$menu_result = $this->get_menu('property');
		$this->flags = $menu_result['flags'];

		$data = array(
			'msgbox_data' => $this->phpgwapi_common->msgbox($msgbox_data),
			'menu' => $menu_result['menu'],
		);

		$appname = lang('No access');
		$this->flags['app_header'] = lang('property') . ' - ' . $appname;
		Settings::getInstance()->set('flags', $this->flags);
		\phpgwapi_xslttemplates::getInstance()->set_var('phpgw', array('no_access' => $data));

		return $this->flags;
	}

	public function preserveAttributeValues($values, $values_attributes)
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

	public function getSubMenu($children = array(), $selection = array(), $level = '')
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
					$menu[$i]['children'] = $this->getSubMenu($menu[$i]['children'], $selection, $level);
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

	public function getTopLevelCategories($data)
	{
		$selected = array();
		if (!empty($data['selected']))
		{
			if (is_array($data['selected']))
			{
				$selected = $data['selected'];
			}
			else if (preg_match('/^\,|&,/', $data['selected']))
			{
				$selected = explode(',', trim($data['selected'], ','));
			}
			else
			{
				$selected[] = $data['selected'];
			}
		}

		$cats = CreateObject('phpgwapi.categories', -1, 'property', $data['acl_location']);
		$cats->supress_info = true;
		$_cats = $cats->return_sorted_array(0, false, '', '', '', false, false);
		$values = array();
		foreach ($_cats as $_cat)
		{
			if ($_cat['level'] == 0 && $_cat['active'] != 2)
			{
				$_cat['selected'] = in_array($_cat['id'], $selected) ? 1 : 0;
				$values[] = $_cat;
			}
		}

		return $values;
	}

	public function getTopLevelCategoryNames($data)
	{
		static $_cats = array();

		$selected = array();
		if (!empty($data['id']))
		{
			if (is_array($data['id']))
			{
				$selected = $data['id'];
			}
			else if (preg_match('/^\,|&,/', $data['id']))
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
			$cats = CreateObject('phpgwapi.categories', -1, 'property', $data['acl_location']);
			$cats->supress_info = true;
			$_cats[$data['acl_location']] = $cats->return_sorted_array(0, false, '', '', '', false, false);
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

	public function getCategories($data)
	{
		$cats = CreateObject('phpgwapi.categories', -1, 'property', $data['acl_location']);
		$cats->supress_info = true;
		$values = $cats->formatted_xslt_list(array(
			'selected' => $data['selected'],
			'globals' => true,
			'link_data' => array()
		));
		$ret = array();

		$level = !empty($data['level']) ? $data['level'] : 0;

		foreach ($values['cat_list'] as $category)
		{
			$ret[] = array(
				'id' => $category['cat_id'],
				'name' => $category['name'],
				'selected' => $category['selected'] ? 1 : 0
			);
		}
		return $ret;
	}

	public function getVendorContract($vendor_id = 0, $selected = '')
	{
		if (!$vendor_id)
		{
			$vendor_id = \Sanitizer::get_var('vendor_id', 'int');
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

	public function getVendorEmailContent($vendor_email, $field_name = '', $preselect = false, $preselect_one = false, $as_json = false, $draw = 0)
	{
		if (!$field_name)
		{
			$field_name = 'values[vendor_email][]';
		}
		else
		{
			$field_name .= '[]';
		}

		$content_email = array();
		$title = lang('The address to which this order will be sendt');

		$checked = $preselect ? 'checked="checked"' : '';

		$count_email = count($vendor_email);
		if ($count_email == 1 && $preselect_one)
		{
			$checked = 'checked="checked"';
		}

		foreach ($vendor_email as $_entry)
		{
			$content_email[] = array(
				'value_email' => $_entry['email'],
				'value_select' => "<input type='checkbox' name='{$field_name}' value='{$_entry['email']}' title='{$title}' {$checked}>"
			);
		}

		if ($as_json)
		{
			$total_records = count($content_email);

			return array(
				'data' => $content_email,
				'total_records' => $total_records,
				'draw' => $draw,
				'recordsTotal' => $total_records,
				'recordsFiltered' => $total_records
			);
		}

		return $content_email;
	}

	public function getVendorEmail($vendor_id, $field_name = '', $preselect = false, $preselect_one = false, $as_json = false, $draw = 0)
	{
		$vendor_email = execMethod('property.sowo_hour.get_email', $vendor_id);

		return $this->getVendorEmailContent(
			$vendor_email,
			$field_name,
			$preselect,
			$preselect_one,
			$as_json,
			$draw
		);
	}

	public function getEcoServiceName($id)
	{
		$ret = $id;
		if ($id = (int)$id)
		{
			$sogeneric = CreateObject('property.sogeneric', 'eco_service');
			$sogeneric_data = $sogeneric->read_single(array('id' => $id));
			$ret = $sogeneric_data['name'];
		}

		return $ret;
	}

	public function getEcoService($query)
	{
		$sogeneric = CreateObject('property.sogeneric', 'eco_service');

		$filter = array('active' => 1);
		$values = $sogeneric->read(array('filter' => $filter, 'query' => $query));

		return array('ResultSet' => array('Result' => $values));
	}

	public function getUnspscCode($query)
	{
		$sogeneric = CreateObject('property.sogeneric', 'unspsc_code');
		$values = $sogeneric->read(array('query' => $query, 'allrows' => true));
		foreach ($values as &$value)
		{
			$value['name'] = "{$value['id']} {$value['name']}";
		}

		return array('ResultSet' => array('Result' => $values));
	}

	public function getUnspscCodeName($id)
	{
		$ret = '';
		if ($id)
		{
			$sogeneric = CreateObject('property.sogeneric', 'unspsc_code');
			$sogeneric_data = $sogeneric->read_single(array('id' => $id));
			if ($sogeneric_data)
			{
				$ret = $sogeneric_data['name'];
			}
		}

		return $ret;
	}

	public function getBAccount($query, $role)
	{
		$type = 'budget_account';

		if ($role == 'group')
		{
			$type = 'b_account_category';
		}

		$sogeneric = CreateObject('property.sogeneric', $type);
		$filter = array('active' => 1);
		$values = $sogeneric->read(array('filter' => $filter, 'query' => $query));

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

	public function getExternalProject($query)
	{
		$sogeneric = CreateObject('property.sogeneric', 'external_project');
		$filter = array('active' => 1);
		$values = $sogeneric->read(array('filter' => $filter, 'query' => $query));

		return array('ResultSet' => array('Result' => $values));
	}

	public function getEcodimb($query)
	{
		$sogeneric = CreateObject('property.sogeneric', 'dimb');
		$filter = array('active' => 1);
		$values = $sogeneric->read(array('filter' => $filter, 'query' => $query));

		foreach ($values as &$value)
		{
			$value['name'] = "{$value['id']} {$value['descr']}";
		}

		return array('ResultSet' => array('Result' => $values));
	}

	public function getExternalProjectName($id)
	{
		$ret = $id;
		if ($id)
		{
			$sogeneric = CreateObject('property.sogeneric', 'external_project');
			$sogeneric_data = $sogeneric->read_single(array('id' => $id));
			if ($sogeneric_data)
			{
				$ret = $sogeneric_data['name'];
			}
		}

		return $ret;
	}

	public function getDocumentationUrl($id)
	{
		$order_info = $this->socommon->get_order_type($id);
		$secret = $order_info['secret'];

		$config_frontend = createobject('phpgwapi.config', 'mobilefrontend')->read();

		$documentation_url = !empty($config_frontend['external_site_address'])
			? rtrim($config_frontend['external_site_address'], '/')
			: rtrim($this->serverSettings['webserver_url'], '/');

		$documentation_url .= '/mobilefrontend/';

		$documentation_url .= '?' . http_build_query(array(
			'menuaction' => 'property.uiimport_documents.step_1_import',
			'id' => $id,
			'secret' => $secret,
			'domain' => $this->userSettings['domain']
		));

		return $documentation_url;
	}

	public function getUsers()
	{
		if (!$this->acl_read)
		{
			return;
		}

		$account_list = $this->accounts->get_list('accounts');

		$values = array();
		foreach ($account_list as $account)
		{
			if ($account->enabled)
			{
				$values[] = array(
					'id' => $account->id,
					'name' => $account->__toString(),
				);
			}
		}

		return array('ResultSet' => array('Result' => $values));
	}

	public function get_user_list($format = '', $selected = '', $extra = '', $default = '', $start = '', $sort = 'ASC', $order = 'account_lastname', $query = '', $offset = '', $enabled = false)
	{
		return $this->getUserList($format, $selected, $extra, $default, $start, $sort, $order, $query, $offset, $enabled);
	}

	public function get_group_list($format = '', $selected = '', $start = '', $sort = '', $order = '', $query = '', $offset = '')
	{
		return $this->getGroupList($format, $selected, $start, $sort, $order, $query, $offset);
	}

	public function get_user_list_right($rights, $selected = '', $acl_location = '', $extra = '', $default = '')
	{
		return $this->getUserListRight($rights, $selected, $acl_location, $extra, $default);
	}

	public function get_user_list_right2($format = '', $right = '', $selected = '', $acl_location = '', $extra = '', $default = '')
	{
		return $this->getUserListRight2($format, $right, $selected, $acl_location, $extra, $default);
	}

	public function initiate_event_lookup($data)
	{
		return $this->initiateEventLookup($data);
	}

	public function initiate_ui_alarm($data)
	{
		return $this->initiateUiAlarm($data);
	}

	public function generate_sql($data)
	{
		$result = $this->generateSql($data);
		$this->type_id = $result['type_id'];
		$this->uicols = $result['uicols'];
		$this->cols_return = $result['cols_return'];
		$this->cols_extra = $result['cols_extra'];
		$this->cols_return_lookup = $result['cols_return_lookup'];
		return $result['sql'];
	}

	public function get_menu($app = 'property')
	{
		$menu_result = $this->getMenu($app);
		$this->flags = $menu_result['flags'];
		if (is_null($menu_result['menu']))
		{
			return;
		}
		return $menu_result['menu'];
	}

	public function no_access()
	{
		$this->flags = $this->noAccess();
	}

	public function download($list, $name, $descr, $input_type = array(), $identificator = array(), $filename = '', $export_options = array())
	{
		$this->flags = $this->performDownload($list, $name, $descr, $input_type, $identificator, $filename, $export_options);
	}

	public function phpspreadsheet_out($list, $name, $descr, $input_type = array(), $identificator = array(), $filename = '', $export_format = 'excel', $export_options = array())
	{
		return $this->performPhpspreadsheetOut($list, $name, $descr, $input_type, $identificator, $filename, $export_format, $export_options);
	}

	public function xslx_out($list, $name, $descr, $input_type = array(), $identificator = array(), $filename = '')
	{
		return $this->performXlsxOut($list, $name, $descr, $input_type, $identificator, $filename);
	}

	public function csv_out($list, $name, $descr, $input_type = array(), $identificator = array(), $filename = '')
	{
		return $this->performCsvOut($list, $name, $descr, $input_type, $identificator, $filename);
	}
}
