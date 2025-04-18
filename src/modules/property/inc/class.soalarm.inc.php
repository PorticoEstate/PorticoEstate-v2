<?php

/**
 * phpGroupWare - property: a Facilities Management System.
 *
 * @author Sigurd Nes <sigurdne@online.no>
 * @copyright Copyright (C) 2003,2004,2005,2006,2007 Free Software Foundation, Inc. http://www.fsf.org/
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
 * @subpackage admin
 * @version $Id$
 */

use App\modules\phpgwapi\services\Settings;
use App\Database\Db;

/**
 * Description
 * @package property
 */
class property_soalarm
{
	var $db, $join, $left_join, $like, $account, $total_records;

	function __construct()
	{
		$userSettings = Settings::getInstance()->get('user');
		$this->account	 = $userSettings['account_id'];
		$this->db		 = Db::getInstance();
		$this->join		 = &$this->db->join;
		$this->like		 = &$this->db->like;
	}

	function select_method_list()
	{
		$this->db->query("SELECT id,name,data FROM fm_async_method ORDER BY name ");

		$i = 0;
		while ($this->db->next_record())
		{
			if ($this->db->f('data'))
			{
				$method_data = array();
				$data_set	 = unserialize($this->db->f('data'));
				if (is_array($data_set))
				{
					foreach ($data_set as $key => $value)
					{
						$method_data[] = $key . '=' . $value;
					}
				}

				$method_data = @implode(',', $method_data);
			}

			$categories[$i]['id']	 = $this->db->f('id');
			$categories[$i]['name']	 = stripslashes($this->db->f('name')) . '(' . $method_data . ')';
			$i++;
		}
		return $categories;
	}

	function read_single_method($id)
	{
		$this->db->query("SELECT name FROM fm_async_method  where id='$id'");
		$this->db->next_record();
		return $this->db->f('name');
	}

	function read($data)
	{
		$id		 = isset($data['id']) && $data['id'] ? $data['id'] : 0;
		$start	 = isset($data['start']) && $data['start'] ? $data['start'] : 0;
		$filter	 = isset($data['filter']) ? $data['filter'] : '';
		$query	 = isset($data['query']) ? $data['query'] : '';
		$sort	 = isset($data['sort']) && $data['sort'] ? $data['sort'] : 'DESC';
		$order	 = isset($data['order']) ? $data['order'] : '';
		$allrows = isset($data['allrows']) ? $data['allrows'] : '';
		$results = isset($data['results']) ? (int)$data['results'] : 0;

		if ($order == 'undefined')
		{
			$order = '';
		}

		if ($order)
		{
			$ordermethod = " ORDER BY $order $sort";
		}
		else
		{
			$ordermethod = ' ORDER BY id DESC';
		}

		$where = 'WHERE';

		$filtermethod = '';
		if ($filter > 0)
		{
			$filtermethod	 .= " $where owner='{$filter}' ";
			$where			 = 'AND';
		}

		$id = $this->db->db_addslashes($id);
		if ($id)
		{
			$filtermethod .= " {$where} id='{$id}'";
		}
		else
		{
			$filtermethod .= " {$where} id!='##last-check-run##'";
		}

		$querymethod = '';
		if ($query)
		{
			$query		 = $this->db->db_addslashes($query);
			$querymethod = " AND (data {$this->like} '%{$query}%' OR method {$this->like} '%{$query}%' OR id {$this->like} '%{$query}%')";
		}

		$sql = "SELECT phpgw_async.id,phpgw_async.next,phpgw_async.times,phpgw_async.method,phpgw_async.data,account_lid
			 FROM phpgw_async $this->join phpgw_accounts on phpgw_async.account_id=phpgw_accounts.account_id $filtermethod $querymethod";

		$this->db->query($sql, __LINE__, __FILE__);
		$this->total_records = $this->db->num_rows();

		if (!$allrows)
		{
			$this->db->limit_query($sql . $ordermethod, $start, __LINE__, __FILE__, $results);
		}
		else
		{
			$this->db->query($sql . $ordermethod, __LINE__, __FILE__);
		}

		$jobs = array();
		while ($this->db->next_record())
		{
			$id		 = $this->db->f('id');
			$data	 = @unserialize($this->db->f('data', true));

			$jobs[$id] = array(
				'id'		 => $id,
				'next'		 => $this->db->f('next'),
				'times'		 => unserialize($this->db->f('times')),
				'method'	 => $this->db->f('method'),
				'data'		 => $data,
				'enabled'	 => isset($data['enabled']) ? (int)$data['enabled'] : 0,
				'user'		 => $this->db->f('account_lid')
			);
		}
		return $jobs;
	}


	function read_single($owner_id)
	{
		$this->db->query("select * from fm_owner where owner_id='$owner_id'", __LINE__, __FILE__);

		if ($this->db->next_record())
		{
			$owner['id']			 = (int)$this->db->f('owner_id');
			$owner['abid']			 = $this->db->f('abid');
			$owner['contact_name']	 = stripslashes($this->db->f('contact_name'));
			$owner['remark']		 = stripslashes($this->db->f('remark'));
			$owner['entry_date']	 = $this->db->f('entry_date');
			$owner['cat_id']		 = (int)$this->db->f('category');

			return $owner;
		}
	}

	function add($owner)
	{
		$owner['name'] = $this->db->db_addslashes($owner['name']);

		$this->db->query("INSERT INTO fm_owner (entry_date,remark,abid,contact_name,category) "
			. "VALUES ('" . time() . "','" . $owner['remark'] . "','" . $owner['abid'] . "','" . $owner['contact_name']
			. "','" . $owner['cat_id'] . "')", __LINE__, __FILE__);

		$receipt['owner_id']	 = $this->db->get_last_insert_id('fm_owner', 'owner_id');
		$receipt['message'][]	 = array('msg' => lang('owner %1 has been saved', $receipt['owner_id']));
		return $receipt;
	}

	function edit($owner)
	{
		$owner['name'] = $this->db->db_addslashes($owner['name']);

		$this->db->query("UPDATE fm_owner set remark='" . $owner['remark'] . "', entry_date='" . time() . "', abid='" . $owner['abid'] . "', contact_name='" . $owner['contact_name'] . "', category='"
			. $owner['cat_id'] . "' WHERE owner_id=" . intval($owner['owner_id']), __LINE__, __FILE__);

		$receipt['owner_id']	 = $owner['owner_id'];
		$receipt['message'][]	 = array('msg' => lang('owner %1 has been edited', $owner['owner_id']));
		return $receipt;
	}

	function delete($owner_id)
	{
		$this->db->query('DELETE FROM fm_owner WHERE owner_id=' . intval($owner_id), __LINE__, __FILE__);
	}
}
