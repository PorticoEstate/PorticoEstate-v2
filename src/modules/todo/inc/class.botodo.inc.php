<?php

/**
 * Todo business
 *
 * @author Joseph Engo <jengo@phpgroupware.org>
 * @author Bettina Gille [ceb@phpgroupware.org]
 * @copyright Copyright (C) 2000-2003,2005 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @package todo
 * @version $Id$
 */

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\Cache;
use App\modules\phpgwapi\controllers\Accounts\Accounts;

phpgw::import_class('phpgwapi.datetime');

/**
 * Todo business
 * 
 * @package todo
 */
class todo_botodo
{
	var $start;
	var $query;
	var $filter;
	var $order;
	var $sort;
	var $cat_id;

	/**
	 * @var bool $debug enable debugging
	 */
	var bool $debug = false;

	var todo_sotodo $sotodo;

	var int $total_records = 0;
	var bool $use_session = False;
	var phpgwapi_common $phpgwapi_common;
	var	Accounts $accounts_obj;


	function __construct($session = False)
	{
		$this->sotodo	= CreateObject('todo.sotodo');

		if ($session)
		{
			$this->read_sessiondata();
			$this->use_session = True;
		}

		$_start		= isset($_REQUEST['start'])	? $_REQUEST['start']	: 0;
		$_query		= isset($_REQUEST['query'])	? $_REQUEST['query']	: '';
		$_sort		= isset($_REQUEST['sort'])	? $_REQUEST['sort']		: 'ASC';
		$_order		= isset($_REQUEST['order'])	? $_REQUEST['order']	: 'todo_id';
		$_filter	= isset($_REQUEST['filter']) ? $_REQUEST['filter']	: '';
		$_cat_id	= isset($_REQUEST['cat_id']) ? $_REQUEST['cat_id']	: 0;

		if (!empty($_start) || ($_start == '0') || ($_start == 0))
		{
			if ($this->debug)
			{
				echo '<br>overriding $start: "' . $this->start . '" now "' . $_start . '"';
			}
			$this->start = $_start;
		}

		if ((empty($_query) && !empty($this->query)) || !empty($_query))
		{
			$this->query  = $_query;
		}

		if (isset($_cat_id) && !empty($_cat_id))
		{
			$this->cat_id = $_cat_id;
		}
		if ((isset($_POST['cat_id']) || isset($_GET['cat_id'])) &&
			($_cat_id == '0' || $_cat_id == 0 || $_cat_id == '')
		)
		{
			$this->cat_id = 0;
		}

		if (isset($_sort) && !empty($_sort))
		{
			if ($this->debug)
			{
				echo '<br>overriding $sort: "' . $this->sort . '" now "' . $_sort . '"';
			}
			$this->sort   = $_sort;
		}

		if (isset($_order) && !empty($_order))
		{
			if ($this->debug)
			{
				echo '<br>overriding $order: "' . $this->order . '" now "' . $_order . '"';
			}
			$this->order  = $_order;
		}

		if (isset($_filter) && !empty($_filter))
		{
			if ($this->debug)
			{
				echo '<br>overriding $filter: "' . $this->filter . '" now "' . $_filter . '"';
			}
			$this->filter = $_filter;
		}
		$this->phpgwapi_common = new \phpgwapi_common();
		$this->accounts_obj = new Accounts();
	}


	function get_grants()
	{
		return $this->sotodo->grants;
	}

	function save_sessiondata($data)
	{
		if ($this->use_session)
		{
			Cache::session_set('todo', 'session_data', $data);
		}
	}

	function read_sessiondata()
	{
		$data = Cache::session_get('todo', 'session_data');

		$this->start	= $data['start'];
		$this->query	= $data['query'];
		$this->filter	= $data['filter'];
		$this->order	= $data['order'];
		$this->sort		= $data['sort'];
		$this->cat_id	= $data['cat_id'];
	}

	/**
	 *
	 * @param integer $owner_id
	 * @param array $grants
	 * @param integer $required
	 * @return bool
	 */
	function check_perms($owner_id, $grants,  $required)
	{
		if (isset($grants['accounts'][$owner_id]) && ($grants['accounts'][$owner_id] & $required))
		{
			return true;
		}

		$equalto = $this->accounts_obj->membership($owner_id);
		foreach ($grants['groups'] as $group => $_right)
		{
			if (isset($equalto[$group]) && ($_right & $required))
			{
				return true;
			}
		}

		return false;
	}

	function cached_accounts($account_id)
	{
		return $this->accounts_obj->get($account_id);
	}

	function employee_list($type)
	{
		$employees = $this->accounts_obj->get_list($type);
		return $employees;
	}

	function format_assigned($a = '')
	{
		if (substr($a, 0, 1) == ',' && (substr($a, strlen($a - 1), 1)) == ',')
		{
			$a = substr($a, 1, strlen($a) - 2);
		}
		$a = explode(',', $a);
		return $a;
	}

	function list_assigned($assi = '')
	{
		$aout = '';
		if (is_array($assi))
		{
			foreach ($assi as $a)
			{
				$adata = $this->cached_accounts($a);
				$aout  .= $this->phpgwapi_common->display_fullname($adata->lid, $adata->firstname, $adata->lastname) . '<br>';

			}
		}
		return $aout;
	}

	function _list($start = 0, $limit = '', $query = '', $filter = '', $order = '', $sort = '', $cat_id = 0, $tree = '', $parent = '')
	{
		if (is_array($start))
		{
			$params = $start;

			$start  = $params['start'];
			$limit  = $params['limit'];
			$query  = $params['query'];
			$filter = $params['filter'];
			$order  = $params['order'];
			$sort   = $params['sort'];
			$cat_id = $params['cat_id'];
			$tree   = $params['tree'];
			$parent = $params['parent'];
		}

		$todos = $this->sotodo->read_todos($start, $limit, $query, $filter, $order, $sort, $cat_id, $tree, $parent);
		$this->total_records = $this->sotodo->total_records;
		$userSettings = Settings::getInstance()->get('user');


		$r = array();
		foreach ($todos as $todo)
		{
			$sdate = $todo['sdate'] - phpgwapi_datetime::user_timezone();
			$todo['sdate'] = $this->phpgwapi_common->show_date($sdate, $userSettings['preferences']['common']['dateformat']);

			if (isset($todo['edate']) && $todo['edate'] != 0)
			{
				$edate = $todo['edate'] - phpgwapi_datetime::user_timezone();
				$todo['edate']	= $this->phpgwapi_common->show_date($edate, $userSettings['preferences']['common']['dateformat']);
			}

			if ($todo['assigned'])
			{
				$todo['assigned'] = $this->format_assigned($todo['assigned']);
			}

			if ($todo['assigned_group'])
			{
				$todo['assigned_group'] = $this->format_assigned($todo['assigned_group']);
			}

			$v['owner'] = $this->accounts_obj->name2id($todo['owner']);
			$r[] = array(
				'id'				=> (int) $todo['id'],
				'main'				=> (int) $todo['main'],
				'parent'			=> (int) $todo['parent'],
				'level'				=> (int) $todo['level'],
				'owner'				=> $todo['owner'],
				'owner_id'			=> (int) $todo['owner_id'],
				'access'			=> $todo['access'],
				'cat'				=> (int) $todo['cat'],
				'title'				=> $todo['title'],
				'descr'				=> $todo['descr'],
				'pri'				=> (int) $todo['pri'],
				'status'			=> (int) $todo['status'],
				'sdate'				=> $todo['sdate'],
				'edate'				=> $todo['edate'],
				'grants'			=> (int) $todo['grants'],
				'sdate_epoch'		=> (int) $todo['sdate_epoch'],
				'edate_epoch'		=> (int) $todo['edate_epoch'],
				'assigned'			=> $todo['assigned'],
				'assigned_group'	=> $todo['assigned_group']
			);
		}
		return $r;
	}

	function read($todo_id)
	{
		return $this->sotodo->read_single_todo($todo_id);
	}

	function check_values($values)
	{
		$error = array();
		if (!$values['title'])
		{
			$error[] = lang('Please enter a title');
		}

		if (strlen($values['descr']) >= 8000)
		{
			$error[] = lang('Description can not exceed 8000 characters in length');
		}

		if ($values['daysfromstart'] && ! preg_match('/^[0-9]+$/', $values['daysfromstart']))
		{
			$error[] = lang('You can only enter numbers for days from now');
		}

		if ($values['smonth'] || $values['sday'] || $values['syear'])
		{
			if (! phpgwapi_datetime::date_valid($values['syear'], $values['smonth'], $values['sday']))
			{
				$error[] = lang('You have entered an invalid start date');
			}
		}

		if ($values['emonth'] || $values['eday'] || $values['eyear'])
		{
			if (! phpgwapi_datetime::date_valid($values['eyear'], $values['emonth'], $values['eday']))
			{
				$error[] = lang('You have entered an invalid end date');
			}
		}

		/*
			if ($values['edate'] < $values['sdate'] && $values['edate'] && $values['sdate'])
			{
				$error[] = lang('Ending date can not be before start date');
			}
			*/

		if (($values['smonth'] || $values['sday'] || $values['syear']) && ($values['emonth'] || $values['eday'] || $values['eyear']))
		{
			if (phpgwapi_datetime::date_compare($values['eyear'], $values['emonth'], $values['eday'], $values['syear'], $values['smonth'], $values['sday']) == -1)
			{
				$error[] = lang('Ending date can not be before start date');
			}
		}

		if (count($error))
		{
			return $error;
		}
	}

	function save($values)
	{
		if ($values['access'])
		{
			$values['access'] = 'private';
		}
		else
		{
			$values['access'] = 'public';
		}

		if (isset($values['seltoday']))
		{
			$values['sdate'] = time();
		}
		else
		{
			if ($values['smonth'] || $values['sday'] || $values['syear'])
			{
				$values['sdate'] = mktime(0, 0, 0, $values['smonth'], $values['sday'], $values['syear']);
			}
		}

		if (!$values['sdate'])
		{
			$values['sdate'] = time();
		}

		if ($values['emonth'] || $values['eday'] || $values['eyear'])
		{
			$values['edate'] = mktime(2, 0, 0, $values['emonth'], $values['eday'], $values['eyear']);
		}
		else if ($values['daysfromstart'] > 0)
		{
			$values['edate'] = mktime(0, 0, 0, date('m', $values['sdate']), date('d', $values['sdate']) + $values['daysfromstart'], date('Y', $values['sdate']));
		}

		if (isset($values['id']) && (int)$values['id'] > 0)
		{
			$this->sotodo->edit_todo($values);
			$todo_id = $values['id'];
		}
		else
		{
			$todo_id = $this->sotodo->add_todo($values);
		}
		return $todo_id;
	}

	function exists($todo_id)
	{
		$exists = $this->sotodo->exists($todo_id);

		if ($exists)
		{
			return True;
		}
		else
		{
			return False;
		}
	}

	function delete($todo_id, $subs = False)
	{
		if (is_array($todo_id))
		{
			$todo_id = $todo_id[0];
		}

		if ($subs)
		{
			return $this->sotodo->delete_todo($todo_id, True);
		}
		else
		{
			return $this->sotodo->delete_todo($todo_id);
		}
	}
}
