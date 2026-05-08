<?php

namespace App\modules\property\helpers;

class CommonBusinessHelper
{
	public function checkPerms($rights, $required)
	{
		return ($rights & $required);
	}

	public function checkPerms2($owner_id, $grants, $required, $equalto = array())
	{
		if (isset($grants['accounts'][$owner_id]) && ($grants['accounts'][$owner_id] & $required))
		{
			return true;
		}

		foreach ($grants['groups'] as $group => $_right)
		{
			if (isset($equalto[$group]) && ($_right & $required))
			{
				return true;
			}
		}

		return false;
	}

	public function dateToTimestamp($date = array())
	{
		return \phpgwapi_datetime::date_to_timestamp($date);
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

	public function selectMultiList($selected = '', $input_list = array())
	{
		$j = 0;
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

	public function addLeadingZero($num, $id_type = '')
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

	public function select2String($array_values, $id = 'id', $name = 'name', $name2 = '')
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

	public function utf2ascii($text = '', $charset = null)
	{
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
		else
		{
			return $text;
		}
	}

	public function ascii2utf($text = '', $charset = null)
	{
		if (!isset($charset) || $charset == 'utf-8')
		{
			return mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
		}
		else
		{
			return $text;
		}
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

	public function createPreferences($socommon, $app = '', $user_id = '')
	{
		return $socommon->create_preferences($app, $user_id);
	}

	public function getLookupEntity($socommon, $location = '')
	{
		return $socommon->get_lookup_entity($location);
	}

	public function getStartEntity($socommon, $location = '')
	{
		return $socommon->get_start_entity($location);
	}

	public function readSingleTenant($socommon, $tenant_id)
	{
		return $socommon->read_single_tenant($tenant_id);
	}

	public function checkLocation($socommon, $location_code = '', $type_id = '')
	{
		return $socommon->check_location($location_code, $type_id);
	}

	public function fmCache($socommon, $name = '', $value = '')
	{
		return $socommon->fm_cache($name, $value);
	}

	public function resetFmCache($socommon)
	{
		$socommon->reset_fm_cache();
	}

	public function resetFmCacheUserlist($socommon)
	{
		return $socommon->reset_fm_cache_userlist();
	}

	public function nextId($socommon, $table, $key = '')
	{
		return $socommon->next_id($table, $key);
	}

	public function incrementId($socommon, $name)
	{
		return $socommon->increment_id($name);
	}

	public function newDb($socommon, $db = '')
	{
		return $socommon->new_db($db);
	}

	public function getMaxLocationLevel($socommon)
	{
		return $socommon->get_max_location_level();
	}

	public function getLocationList($socommon, $required)
	{
		return $socommon->get_location_list($required);
	}

	public function setPendingAction($socommon, $action_params)
	{
		return $socommon->set_pending_action($action_params);
	}

	public function readLocationData($socommon, $location_code)
	{
		$soadmin_location = CreateObject('property.soadmin_location');

		$location_types = $soadmin_location->select_location_type();
		unset($soadmin_location);

		return $socommon->read_location_data($location_code, $location_types);
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

	public function selectPartOfTown($socommon, $district_id = '', $selected = '')
	{
		$parts = $socommon->select_part_of_town($district_id);
		return $this->buildPartOfTownList($parts, $selected);
	}

	public function selectDistrictList($socommon, $selected = '')
	{
		$districts = $socommon->select_district_list();
		return $this->selectList($selected, $districts);
	}

	public function selectCategoryList($data = array())
	{
		$categories = execMethod('property.sogeneric.get_list', $data);
		$selected = isset($data['selected']) ? $data['selected'] : '';
		return $this->selectList($selected, $categories);
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

	public function getDocumentationUrl($socommon, $id, $serverSettings = array(), $userSettings = array())
	{
		$order_info = $socommon->get_order_type($id);
		$secret = $order_info['secret'];

		$config_frontend = createobject('phpgwapi.config', 'mobilefrontend')->read();

		$documentation_url = !empty($config_frontend['external_site_address'])
			? rtrim($config_frontend['external_site_address'], '/')
			: rtrim($serverSettings['webserver_url'], '/');

		$documentation_url .= '/mobilefrontend/';

		$documentation_url .= '?' . http_build_query(array(
			'menuaction' => 'property.uiimport_documents.step_1_import',
			'id' => $id,
			'secret' => $secret,
			'domain' => $userSettings['domain']
		));

		return $documentation_url;
	}

	public function getUsers($accounts, $acl_read)
	{
		if (!$acl_read)
		{
			return;
		}

		$account_list = $accounts->get_list('accounts');

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
}
