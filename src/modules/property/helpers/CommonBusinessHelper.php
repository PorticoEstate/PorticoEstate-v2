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
}
