<?php

/**
 * phpGroupWare - SMS: A SMS Gateway.
 *
 * @author Sigurd Nes <sigurdne@online.no>
 * @copyright Copyright (C) 2003-2005 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @internal Development of this application was funded by http://www.bergen.kommune.no/bbb_/ekstern/
 * @package sms
 * @subpackage command
 * @version $Id$
 */


/**
 * Description
 * @package sms
 */
class sms_bocommand
{

	var $so, $bocommon, $total_records;

	var $public_functions = array(
		'read' => true,
		'read_single' => true,
		'save' => true,
		'delete' => true,
		'check_perms' => true
	);

	function __construct()
	{
		$this->so = CreateObject('sms.socommand');
		$this->bocommon = CreateObject('sms.bocommon');
	}


	function read($data)
	{
		$command_info = $this->so->read($data);
		$this->total_records = $this->so->total_records;
		return $command_info;
	}

	function read_log($data)
	{
		$command_info = $this->so->read_log($data);
		$phpgwapi_common = new \phpgwapi_common();

		foreach ($command_info as &$entry)
		{
			$entry['datetime'] = $phpgwapi_common->show_date(strtotime($entry['datetime']));
		}

		$this->total_records = $this->so->total_records;
		return $command_info;
	}

	function read_single_command($id)
	{
		$values = $this->so->read_single_command($id);
		return $values;
	}

	function save_command($values, $action = '')
	{

		if ($action == 'edit')
		{
			if ($values['command_id'] != '')
			{

				$receipt = $this->so->edit_command($values);
			}
			else
			{
				$receipt['error'][] = array('msg' => lang('Error'));
			}
		}
		else
		{
			$receipt = $this->so->add_command($values);
		}

		return $receipt;
	}

	function select_type_list($selected = '')
	{
		$input_command[0]['id'] = 'php';
		$input_command[0]['name'] = 'php code';
		$input_command[1]['id'] = 'shell';
		$input_command[1]['name'] = 'Command or shell script';

		return $this->bocommon->select_list($selected, $input_command);
	}

	function get_category_list($data)
	{
		switch ($data['format'])
		{
			case 'select':
				phpgwapi_xslttemplates::getInstance()->add_file(array('cat_select'));
				break;
			case 'filter':
				phpgwapi_xslttemplates::getInstance()->add_file(array('cat_filter'));
				break;
		}

		$categories = $this->so->get_category_list();
		$categories = $this->bocommon->select_list($data['selected'], $categories);
		return $categories;
	}
}
