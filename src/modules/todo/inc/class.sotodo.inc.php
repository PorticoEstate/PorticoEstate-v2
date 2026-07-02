<?php

/**
 * Todo storage
 *
 * @author Joseph Engo <jengo@phpgroupware.org>
 * @author Bettina Gille [ceb@phpgroupware.org]
 * @copyright Copyright (C) 2000-2003,2005 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @package todo
 * @version $Id$
 */

use App\Database\Db;
use App\traits\DbRowTrait;
use App\modules\phpgwapi\security\Acl;
use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\controllers\Accounts\Accounts;
use App\modules\phpgwapi\services\Log;

/**
 * Todo storage
 *  
 * @package todo
 */
class todo_sotodo
{
	use DbRowTrait;

	var $db;
	var $grants;
	var $historylog;
	var $owner;
	var $account;
	var $user_groups;
	var $join;
	var $total_records;

	function __construct()
	{
		$userSettings = Settings::getInstance()->get('user');
		$accounts_obj = new Accounts();


		$this->db          = Db::getInstance();
		$this->join        = $this->db->join;

		$this->grants      = Acl::getInstance()->get_grants2('todo', '.');
		$this->account     = $userSettings['account_id'];
		$this->user_groups = $accounts_obj->membership($this->account);
		$this->historylog  = CreateObject('phpgwapi.historylog', 'todo', '.');

		// This is so our transactions follow across classes
		$this->historylog->db = &$this->db;

		$this->owner = $userSettings['account_id'];
	}

	function type($tree)
	{
		switch ($tree)
		{
			case 'mains':
				return ' AND todo_id_parent = 0';
				break;
			case 'subs':
				return ' AND todo_id_parent != 0';
				break;
			default:
		}
		return '';
	}

	function read_todos($start = 0, $limit = True, $query = '', $filter = '', $order = '', $sort = '', $cat_id = '', $tree = '', $parent = '')
	{
		$type = $this->type($tree);

		if ($order)
		{
			$order = $this->db->db_addslashes($order);
			$sort = $this->db->db_addslashes($sort);
			$ordermethod = "ORDER BY $order $sort";
		}
		else
		{
			$ordermethod = 'ORDER BY todo_id_main, todo_id_parent, todo_level, todo_datecreated ASC';
		}

		$filter = strtolower($filter);

		if (!$filter)
		{
			$filter = 'none';
		}

		$filtermethod = "(( todo_owner = {$this->account} OR todo_assigned = '{$this->account}'";

		/**
		 * Begin Orlando Fix
		 *
		 * I had to change the way $group variables were read to
		 * object -> attributes
		 */
		if (is_array($this->user_groups) && count($this->user_groups))
		{
			$filtermethod .= " OR assigned_group IN('0'";
			foreach ($this->user_groups as $group)
			{
				$filtermethod .= ",'" . $group->id . "' ";
			}
			$filtermethod .= ')';
		}
		/**
		 * End Orlando Fix
		 */

		$filtermethod .= ')';

		if ($filter == 'none')
		{

			$public_user_list = array();
			if (is_array($this->grants['accounts']) && $this->grants['accounts'])
			{
				foreach ($this->grants['accounts'] as $user => $_right)
				{
					$public_user_list[] = $user;
				}
				reset($public_user_list);
				$filtermethod .= " OR (todo_access='public' AND todo_owner IN(" . implode(',', $public_user_list) . "))";
			}

			$public_group_list = array();
			if (is_array($this->grants['groups']) && $this->grants['groups'])
			{
				foreach ($this->grants['groups'] as $user => $_right)
				{
					$public_group_list[] = $user;
				}
				unset($user);
				reset($public_group_list);
				$filtermethod .= " OR todo_access='public' AND phpgw_group_map.group_id IN(" . implode(',', $public_group_list) . "))";
				$where = 'AND';
			}
			if ($public_user_list && !$public_group_list)
			{
				//		$filtermethod .= ')';
			}
		}

		$filtermethod .= ')';

		if ($filter == 'private')
		{
			$filtermethod .=  " AND todo_access = 'private'";
		}

		if ($cat_id)
		{
			$filtermethod .= ' AND todo_cat = ' . (int) $cat_id;
		}


		$querymethod = '';
		if ($query)
		{
			$query = $this->db->db_addslashes($query);
			$querymethod = " AND (todo_des LIKE '%$query%' OR todo_title LIKE '%$query%')";
		}


		$parentmethod = '';
		if ($parent)
		{
			$parentmethod = ' AND todo_id_parent=' . (int) $parent;
		}
		$sql = "SELECT DISTINCT phpgw_todo.* FROM phpgw_todo"
			. " {$this->join} phpgw_accounts ON ( phpgw_todo.todo_owner = phpgw_accounts.account_id)"
			. " {$this->join} phpgw_group_map ON (phpgw_accounts.account_id = phpgw_group_map.account_id)"
			. " WHERE $filtermethod $querymethod $type $parentmethod ";

		$sql2 = "SELECT count(*) as cnt FROM ({$sql}) as t";

		$this->db->query($sql2, __LINE__, __FILE__);

		$this->db->next_record();
		$this->total_records = $this->db->f('cnt');

		if ($limit)
		{
			$this->db->limit_query($sql . $ordermethod, $start, __LINE__, __FILE__);
		}
		else
		{
			$this->db->query($sql . $ordermethod, __LINE__, __FILE__);
		}

		$todos = array();
		while ($this->db->next_record())
		{
			$todos[] = array(
				'id'				=> (int)$this->db->f('todo_id'),
				'main'				=> (int)$this->db->f('todo_id_main'),
				'parent'			=> (int)$this->db->f('todo_id_parent'),
				'level'				=> (int)$this->db->f('todo_level'),
				'owner'				=> $this->db->f('todo_owner'),
				'owner_id'			=> $this->db->f('todo_owner'),
				'access'			=> $this->db->f('todo_access'),
				'cat'				=> (int)$this->db->f('todo_cat'),
				'title'				=> $this->dbStrip($this->db->f('todo_title')),
				'descr'				=> $this->dbStrip($this->db->f('todo_des')),
				'pri'				=> (int)$this->db->f('todo_pri'),
				'status'			=> (int)$this->db->f('todo_status'),
				'sdate'				=> $this->db->f('todo_startdate'),
				'edate'				=> $this->db->f('todo_enddate'),
				'sdate_epoch'		=> (int)$this->db->f('todo_startdate'),
				'edate_epoch'		=> (int)$this->db->f('todo_enddate'),
				'assigned'			=> $this->db->f('todo_assigned'),
				'assigned_group'	=> $this->db->f('assigned_group')
			);
		}
		return $todos;
	}

	function read_single_todo($todo_id)
	{
		$stmt = $this->db->prepare('SELECT * FROM phpgw_todo WHERE todo_id = :todo_id');
		$stmt->execute([':todo_id' => (int) $todo_id]);

		$row = $stmt->fetch(\PDO::FETCH_ASSOC);
		if (!$row)
		{
			return array();
		}

		return array(
			'id'				=> $row['todo_id'],
			'main'			=> $row['todo_id_main'],
			'parent'			=> $row['todo_id_parent'],
			'level'			=> $row['todo_level'],
			'owner'			=> $row['todo_owner'],
			'access'			=> $row['todo_access'],
			'cat'				=> $row['todo_cat'],
			'title'			=> $this->dbStrip($row['todo_title']),
			'descr'			=> $this->dbStrip($row['todo_des']),
			'pri'				=> $row['todo_pri'],
			'status'			=> $row['todo_status'],
			'sdate'			=> $row['todo_startdate'],
			'edate'			=> $row['todo_enddate'],
			'assigned'		=> $row['todo_assigned'],
			'assigned_group'	=> $row['assigned_group'],
		);
	}

	function add_todo($values)
	{
		$log = new Log();
		$log->message(array(
			'text'		=> 'debug, so add_todo values: %1',
			'p1'		=> print_r($values, true),
			'severity'	=> 'D',
			'line'		=> __LINE__,
			'file'		=> __FILE__
		));
		$log->commit();

		$values['parent'] = (int)$values['parent'];
		if ($values['parent'] > 0)
		{
			$values['main']		= $this->return_value($values['parent']);
			$values['level']	= $this->return_value($values['parent'], 'level') + 1;
		}

		$title = (string) ($values['title'] ?? '');
		$descr = (string) ($values['descr'] ?? '');
		$assigned = (string) ($values['assigned'] ?? '');
		$assigned_group = (string) ($values['assigned_group'] ?? '');

		/**
		 * Begin Orlando Fix
		 *
		 * I had to include another field in the INSERT query: entry_date
		 * because it didn't accept null values, and it now stores the actual time()
		 */
		$this->db->transaction_begin();
		$sql = 'INSERT INTO phpgw_todo (todo_id_main, todo_id_parent, todo_level, todo_owner, todo_access, todo_cat, todo_des, todo_title, todo_pri, todo_status, todo_datecreated, todo_startdate, todo_enddate, todo_assigned, assigned_group, entry_date)'
			. ' VALUES (:main, :parent, :level, :owner, :access, :cat, :descr, :title, :pri, :status, :datecreated, :sdate, :edate, :assigned, :assigned_group, :entry_date)';
		$stmt = $this->db->prepare($sql);
		$now = time();
		$stmt->execute([
			':main' => (int) $values['main'],
			':parent' => (int) $values['parent'],
			':level' => (int) $values['level'],
			':owner' => (int) $this->account,
			':access' => (int) !!$values['access'],
			':cat' => (int) $values['cat'],
			':descr' => $descr,
			':title' => $title,
			':pri' => (int) $values['pri'],
			':status' => (int) $values['status'],
			':datecreated' => $now,
			':sdate' => (int) $values['sdate'],
			':edate' => (int) $values['edate'],
			':assigned' => $assigned,
			':assigned_group' => $assigned_group,
			':entry_date' => $now,
		]);
		$todo_id = $this->db->get_last_insert_id('phpgw_todo', 'todo_id');
		/**
		 * End Orlando Fix
		 */

		if (!$values['parent'] || $values['parent'] == 0)
		{
			$stmt = $this->db->prepare('UPDATE phpgw_todo SET todo_id_main = :todo_id_main WHERE todo_id = :todo_id');
			$stmt->execute([':todo_id_main' => (int) $todo_id, ':todo_id' => (int) $todo_id]);
		}
		$this->historylog->add('A', $todo_id, '', '');
		$this->db->transaction_commit();
		return $todo_id;
	}

	function find_subs($list_parents = '', $list = '')
	{
		if ($list_parents == '')
		{
			return $list;
		}
		$parents = array_filter(array_map('intval', explode(',', (string) $list_parents)));
		if (!$parents)
		{
			return $list;
		}

		$parent_placeholders = array();
		$params = array();
		foreach (array_values($parents) as $index => $parent_id)
		{
			$key = ':parent_' . $index;
			$parent_placeholders[] = $key;
			$params[$key] = $parent_id;
		}

		$query = 'SELECT todo_id FROM phpgw_todo WHERE todo_id_parent IN (' . implode(', ', $parent_placeholders) . ')';

		if ($list <> '')
		{
			$exclude_ids = array_filter(array_map('intval', explode(',', (string) $list)));
			if ($exclude_ids)
			{
				$exclude_placeholders = array();
				$offset = count($params);
				foreach (array_values($exclude_ids) as $index => $exclude_id)
				{
					$key = ':exclude_' . ($offset + $index);
					$exclude_placeholders[] = $key;
					$params[$key] = $exclude_id;
				}
				$query .= ' AND todo_id NOT IN (' . implode(', ', $exclude_placeholders) . ')';
			}
		}

		$stmt = $this->db->prepare($query);
		$stmt->execute($params);
		$subs = array();
		while ($row = $stmt->fetch(\PDO::FETCH_ASSOC))
		{
			$subs[] = (int) $row['todo_id'];
		}
		if (count($subs))
		{
			$list_subs = implode(',', $subs);
			if ($list <> '')
			{
				$list .= ',';
			}
			$list = $this->find_subs($list_subs, $list . $list_subs);
		}
		return $list;
	}

	function delete_todo($todo_id, $sub = False)
	{
		$todo_id = (int) $todo_id;
		$this->db->transaction_begin();
		$sub_todos = $this->find_subs($todo_id);
		$delete_ids = array($todo_id);
		$parent = 0;
		if ($sub_todos)
		{
			if ($sub)
			{
				$delete_ids = array_merge($delete_ids, array_filter(array_map('intval', explode(',', (string) $sub_todos))));
			}
			else
			{
				$parent = $this->return_value($todo_id, 'parent');
			}
		}

		$placeholders = array();
		$params = array(
			':owner' => (int) $this->owner,
		);
		foreach (array_values(array_unique($delete_ids)) as $index => $delete_id)
		{
			$key = ':todo_id_' . $index;
			$placeholders[] = $key;
			$params[$key] = (int) $delete_id;
		}

		$delete_sql = 'DELETE FROM phpgw_todo WHERE todo_id IN (' . implode(', ', $placeholders) . ")"
			. " AND ((todo_access='public' AND todo_owner != :owner) OR (todo_owner = :owner))";
		$stmt = $this->db->prepare($delete_sql);
		$stmt->execute($params);

		if (!$sub && $sub_todos)
		{
			$stmt = $this->db->prepare('UPDATE phpgw_todo SET todo_id_parent = :parent WHERE todo_id_parent = :todo_id');
			$stmt->execute([':parent' => (int) $parent, ':todo_id' => $todo_id]);

			$sub_ids = array_filter(array_map('intval', explode(',', (string) $sub_todos)));
			if ($sub_ids)
			{
				$sub_placeholders = array();
				$sub_params = array();
				foreach (array_values($sub_ids) as $index => $sub_id)
				{
					$key = ':sub_id_' . $index;
					$sub_placeholders[] = $key;
					$sub_params[$key] = $sub_id;
				}

				$level_sql = 'UPDATE phpgw_todo SET todo_level = todo_level - 1 WHERE todo_id IN (' . implode(', ', $sub_placeholders) . ')';
				$stmt = $this->db->prepare($level_sql);
				$stmt->execute($sub_params);
			}
		}
		$this->historylog->delete($todo_id);
		$this->db->transaction_commit();
	}

	function edit_todo($values)
	{
		$values['parent']	= intval($values['parent']);
		$values['id']		= intval($values['id']);

		if ($values['parent'] > 0)
		{
			$values['main']		= $this->return_value($values['parent']);
			$values['level']	= $this->return_value($values['parent'], 'level') + 1;
		}
		else
		{
			$values['main']		= $values['id'];
			$values['level']	= 0;
		}

		$old_values = $this->read_single_todo($values['id']);

		$this->db->transaction_begin();
		if ($old_values['descr'] != $values['descr'])
		{
			$this->historylog->add('D', $values['id'], $values['descr'], $old_values['descr']);
		}

		if (($old_values['parent'] || $values['parent']) && ($old_values['parent'] != $values['parent']))
		{
			$this->historylog->add('P', $values['id'], $values['parent'], $old_values['parent']);
		}

		if ($old_values['pri'] != $values['pri'])
		{
			$this->historylog->add('U', $values['id'], $values['pri'], $old_values['pri']);
		}

		if ($old_values['status'] != $values['status'])
		{
			$this->historylog->add('s', $values['id'], $values['status'], $old_values['status']);
		}

		if ($old_values['access'] != $values['access'])
		{
			$this->historylog->add('a', $values['id'], $values['access'], $old_values['access']);
		}

		if (($old_values['sdate'] || $values['sdate']) && ($old_values['sdate'] != $values['sdate']))
		{
			$this->historylog->add('S', $values['id'], $values['sdate'], $old_values['sdate']);
		}

		if (($old_values['edate'] || $values['edate']) && ($old_values['edate'] != $values['edate']))
		{
			$this->historylog->add('E', $values['id'], $values['edate'], $old_values['edate']);
		}

		if ($old_values['title'] != $values['title'])
		{
			$this->historylog->add('T', $values['id'], $values['title'], $old_values['title']);
		}

		if ($old_values['cat'] != $values['cat'])
		{
			$this->historylog->add('C', $values['id'], $values['cat'], $old_values['cat']);
		}

		$update_sql = 'UPDATE phpgw_todo SET'
			. ' todo_des = :descr,'
			. ' todo_id_parent = :parent,'
			. ' todo_pri = :pri,'
			. ' todo_status = :status,'
			. ' todo_id_main = :main,'
			. ' todo_access = :access,'
			. ' todo_level = :level,'
			. ' todo_startdate = :sdate,'
			. ' todo_enddate = :edate,'
			. ' todo_title = :title,'
			. ' todo_cat = :cat,'
			. ' todo_assigned = :assigned,'
			. ' assigned_group = :assigned_group'
			. ' WHERE todo_id = :id';
		$stmt = $this->db->prepare($update_sql);
		$stmt->execute([
			':descr' => (string) ($values['descr'] ?? ''),
			':parent' => (int) $values['parent'],
			':pri' => (int) $values['pri'],
			':status' => (int) $values['status'],
			':main' => (int) $values['main'],
			':access' => (string) ($values['access'] ?? ''),
			':level' => (int) $values['level'],
			':sdate' => (int) $values['sdate'],
			':edate' => (int) $values['edate'],
			':title' => (string) ($values['title'] ?? ''),
			':cat' => (int) $values['cat'],
			':assigned' => (string) ($values['assigned'] ?? ''),
			':assigned_group' => (string) ($values['assigned_group'] ?? ''),
			':id' => (int) $values['id'],
		]);
		$this->db->transaction_commit();
	}

	function return_value($todo_id, $action = 'main')
	{
		$item = 'todo_id_main';
		switch ($action)
		{
			case 'main':
				$item = 'todo_id_main';
				break;
			case 'level':
				$item = 'todo_level';
				break;
		}

		$stmt = $this->db->prepare("SELECT {$item} AS value FROM phpgw_todo WHERE todo_id = :todo_id");
		$stmt->execute([':todo_id' => (int) $todo_id]);
		if ($row = $stmt->fetch(\PDO::FETCH_ASSOC))
		{
			return $row['value'];
		}
	}

	function exists($todo_id)
	{
		$stmt = $this->db->prepare('SELECT count(*) as cnt FROM phpgw_todo WHERE todo_id_parent = :todo_id_parent');
		$stmt->execute([':todo_id_parent' => (int) $todo_id]);
		$row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: array();

		if (!empty($row['cnt']))
		{
			return True;
		}
		else
		{
			return False;
		}
	}
}
