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

use App\modules\phpgwapi\services\Cache;

/**
 * Description
 * @package sms
 */
class sms_bocommand
{

	var $start;
	var $query;
	var $filter;
	var $sort;
	var $order;
	var $cat_id, $so, $bocommon, $use_session, $allrows, $total_records;

	var $public_functions = array(
		'read' => true,
		'read_single' => true,
		'save' => true,
		'delete' => true,
		'check_perms' => true
	);

	function __construct($session = false)
	{
		$this->so = CreateObject('sms.socommand');
		$this->bocommon = CreateObject('sms.bocommon');

		if ($session)
		{
			$this->read_sessiondata();
			$this->use_session = true;
		}

		$start = Sanitizer::get_var('start', 'int', 'REQUEST', 0);
		$query = Sanitizer::get_var('query');
		$sort = Sanitizer::get_var('sort');
		$order = Sanitizer::get_var('order');
		$filter = Sanitizer::get_var('filter', 'int');
		$cat_id = Sanitizer::get_var('cat_id', 'string');
		$allrows = Sanitizer::get_var('allrows', 'bool');

		$this->start = $start ? $start : 0;

		if (array_key_exists('query', $_POST) || array_key_exists('query', $_GET))
		{
			$this->query = $query;
		}
		if (array_key_exists('filter', $_POST) || array_key_exists('filter', $_GET))
		{
			$this->filter = $filter;
		}
		if (array_key_exists('sort', $_POST) || array_key_exists('sort', $_GET))
		{
			$this->sort = $sort;
		}
		if (array_key_exists('order', $_POST) || array_key_exists('order', $_GET))
		{
			$this->order = $order;
		}
		if (array_key_exists('cat_id', $_POST) || array_key_exists('cat_id', $_GET))
		{
			$this->cat_id = $cat_id;
		}
		if ($allrows)
		{
			$this->allrows = $allrows;
		}
	}

	function save_sessiondata($data)
	{
		if ($this->use_session)
		{
			Cache::session_set('sms_command', 'session_data', $data);
		}
	}

	function read_sessiondata()
	{
		$data = Cache::session_get('sms_command', 'session_data');

		$this->start = $data['start'];
		$this->query = $data['query'];
		$this->filter = $data['filter'];
		$this->sort = $data['sort'];
		$this->order = $data['order'];
		$this->cat_id = $data['cat_id'];
	}

	function read()
	{
		$command_info = $this->so->read(array(
			'start' => $this->start, 'query' => $this->query,
			'sort' => $this->sort, 'order' => $this->order,
			'allrows' => $this->allrows
		));
		$this->total_records = $this->so->total_records;
		return $command_info;
	}

	function read_log()
	{
		$command_info = $this->so->read_log(array(
			'start' => $this->start, 'query' => $this->query,
			'sort' => $this->sort, 'order' => $this->order,
			'allrows' => $this->allrows, 'cat_id' => $this->cat_id
		));
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
