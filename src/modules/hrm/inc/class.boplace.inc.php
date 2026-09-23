<?php

/**
 * phpGroupWare - HRM: a  human resource competence management system.
 *
 * @author Sigurd Nes <sigurdne@online.no>
 * @copyright Copyright (C) 2003-2005 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @internal Development of this application was funded by http://www.bergen.kommune.no/bbb_/ekstern/
 * @package hrm
 * @subpackage place
 * @version $Id$
 */

use App\modules\phpgwapi\services\Cache;
use App\modules\phpgwapi\services\Settings;

/**
 * Description
 * @package hrm
 */

class hrm_boplace
{
	var $start;
	var $query;
	var $filter;
	var $sort;
	var $order;
	var $cat_id;
	var $length;

	var $public_functions = array(
		'read'			=> true,
		'read_single'		=> true,
		'save'			=> true,
		'delete'		=> true,
		'check_perms'		=> true
	);

	var $soap_functions = array(
		'list' => array(
			'in'  => array('int', 'int', 'struct', 'string', 'int'),
			'out' => array('array')
		),
		'read' => array(
			'in'  => array('int', 'struct'),
			'out' => array('array')
		),
		'save' => array(
			'in'  => array('int', 'struct'),
			'out' => array()
		),
		'delete' => array(
			'in'  => array('int', 'struct'),
			'out' => array()
		)
	);

	var $so, $bocommon, $use_session, $allrows, $total_records, $userSettings, $phpgwapi_common;
	function __construct($session = false)
	{
		$this->so 		= CreateObject('hrm.soplace');
		$this->bocommon 	= CreateObject('hrm.bocommon');

		if ($session)
		{
			$this->read_sessiondata();
			$this->use_session = true;
		}

		$start	= Sanitizer::get_var('start', 'int', 'REQUEST', 0);
		$query	= Sanitizer::get_var('query');
		$sort	= Sanitizer::get_var('sort');
		$order	= Sanitizer::get_var('order');
		$filter	= Sanitizer::get_var('filter', 'int');
		$cat_id	= Sanitizer::get_var('cat_id', 'int');
		$allrows = Sanitizer::get_var('allrows', 'bool');
		$length = Sanitizer::get_var('length', 'int', 'REQUEST', 10);
		$this->userSettings = Settings::getInstance()->get('user');
		$this->phpgwapi_common = new \phpgwapi_common();


		if ($start)
		{
			$this->start = $start;
		}
		else
		{
			$this->start = 0;
		}

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
		$this->length = $length === -1 ? -1 : max(1, $length);
	}


	function save_sessiondata($data)
	{
		if ($this->use_session)
		{
			Cache::session_set('hr_place', 'session_data', $data);
		}
	}

	function read_sessiondata()
	{
		$data = Cache::session_get('hr_place', 'session_data');

		$this->start	= $data['start'];
		$this->query	= $data['query'];
		$this->filter	= $data['filter'];
		$this->sort	= $data['sort'];
		$this->order	= $data['order'];
		$this->cat_id	= $data['cat_id'];
	}


	function read()
	{
		$place_info = $this->so->read(array(
			'start' => $this->start,
			'query' => $this->query,
			'sort' => $this->sort,
			'order' => $this->order,
			'length' => $this->length,
			'allrows' => $this->allrows
		));
		$this->total_records = $this->so->total_records;
		return $place_info;
	}

	function read_single($id)
	{
		$values = $this->so->read_single($id);
		$dateformat = $this->userSettings['preferences']['common']['dateformat'];
		if ($values['entry_date'])
		{
			$values['entry_date']	= $this->phpgwapi_common->show_date($values['entry_date'], $dateformat);
		}

		return $values;
	}


	function save($values, $action = '')
	{

		if ($action == 'edit')
		{
			if ($values['place_id'] != '')
			{

				$receipt = $this->so->edit($values);
			}
			else
			{
				$receipt['error'][] = array('msg' => lang('Error'));
			}
		}
		else
		{
			$receipt = $this->so->add($values);
		}

		return $receipt;
	}

	function delete($id)
	{
		$this->so->delete($id);
	}

	function select_category_list($format = '', $selected = '')
	{

		switch ($format)
		{
			case 'select':
				phpgwapi_xslttemplates::getInstance()->add_file(array('cat_select'));
				break;
			case 'filter':
				phpgwapi_xslttemplates::getInstance()->add_file(array('cat_filter'));
				break;
		}

		$categories = $this->so->select_category_list();
		$category_list = array();

		//while (is_array($categories) && list(,$category) = each($categories))
		if (is_array($categories))
		{
			foreach ($categories as $key => $category)
			{
				$sel_category = '';
				if ($category['id'] == $selected)
				{
					$sel_category = 'selected';
				}

				$category_list[] = array(
					'cat_id'	=> $category['id'],
					'name'		=> $category['name'],
					'selected'	=> $sel_category
				);
			}
		}

		for ($i = 0; $i < count($category_list); $i++)
		{
			if ($category_list[$i]['selected'] != 'selected')
			{
				unset($category_list[$i]['selected']);
			}
		}

		return $category_list;
	}


	function select_place_list($selected = '')
	{
		$places = $this->so->select_place_list();
		$place_list = $this->bocommon->select_list($selected, $places);
		return $place_list;
	}
}
