<?php

/**
 * phpGroupWare - property: a Facilities Management System.
 *
 * @author Sigurd Nes <sigurdne@online.no>
 * @copyright Copyright (C) 2018 Free Software Foundation, Inc. http://www.fsf.org/
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
 * @subpackage helpdesk
 * @version $Id$
 */

use App\Database\Db;
use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\controllers\Accounts\Accounts;


/**
 * Description
 * @package property
 */
class controller_sosettings
{

	private $db, $like, $join, $left_join, $account, $accounts_obj;

	public function __construct()
	{
		$this->db		 = Db::getInstance();
		$this->like		 = &$this->db->like;
		$this->join		 = &$this->db->join;
		$this->left_join = &$this->db->left_join;
		$userSettings = Settings::getInstance()->get('user');
		$this->account	 = (int)$userSettings['account_id'];
		$this->accounts_obj = new Accounts();
	}

	public function save($data)
	{
		$this->db->transaction_begin();

		$this->db->query('UPDATE controller_control SET ticket_cat_id = NULL', __LINE__, __FILE__);

		$sql = "UPDATE controller_control SET ticket_cat_id =? WHERE id = ?";

		$valueset = array();

		foreach ($data as $control_id => $cat_id)
		{
			if ($cat_id)
			{
				$valueset[] = array(
					1	 => array(
						'value'	 => (int)$cat_id,
						'type'	 => PDO::PARAM_INT
					),
					2	 => array(
						'value'	 => $control_id,
						'type'	 => PDO::PARAM_INT
					)
				);
			}
		}

		if ($valueset)
		{
			$this->db->insert($sql, $valueset, __LINE__, __FILE__);
		}

		return $this->db->transaction_commit();
	}

	public function read()
	{
		$this->db->query('SELECT id, ticket_cat_id FROM controller_control', __LINE__, __FILE__);

		$values = array();
		while ($this->db->next_record())
		{
			$control_id = $this->db->f('id');

			$values[$control_id] = array(
				'cat_id' => $this->db->f('ticket_cat_id')
			);
		}
		return $values;
	}

	public function read_single($control_id)
	{
		$this->db->query('SELECT ticket_cat_id FROM controller_control WHERE id = ' . (int)$control_id, __LINE__, __FILE__);
		$this->db->next_record();
		return (int)$this->db->f('ticket_cat_id');
	}


	public function get_roles($control_id, $part_of_town_id)
	{
		if (!$control_id || !$part_of_town_id)
		{
			return array();
		}

		$sql = "SELECT * FROM controller_control_user_role WHERE control_id = :control_id AND part_of_town_id = :part_of_town_id";

		$statement = $this->db->prepare($sql);
		$statement->bindValue(':control_id', (int)$control_id, PDO::PARAM_INT);
		$statement->bindValue(':part_of_town_id', (int)$part_of_town_id, PDO::PARAM_INT);
		$statement->execute();

		$values = array();

		while ($row = $statement->fetch(PDO::FETCH_ASSOC))
		{
			$user_id = $row['user_id'];
			$values[$user_id] = $row['roles'];
		}
		return $values;
	}

	public function save_users($data)
	{
		//			_debug_array($data);

		$add = array();
		$update = array();
		$delete = array();
		$now = time();

		foreach ($data as $control_id => $part_of_town_info)
		{
			//		_debug_array($control_id);
			foreach ($part_of_town_info as $part_of_town_id => $user_info)
			{
				foreach ($user_info as $user_id => $role_info)
				{
					$new_roles = (int)array_sum((array)$role_info['new']);
					$old_roles = (int)$role_info['original'];

					if ($old_roles != $new_roles)
					{

						if ($new_roles && $old_roles)
						{
							$update[] = array(
								1	=> array(
									'value'	=> $new_roles,
									'type'	=> PDO::PARAM_INT
								),
								2	=> array(
									'value'	=> $now,
									'type'	=> PDO::PARAM_INT
								),
								3	=> array(
									'value'	=> $this->account,
									'type'	=> PDO::PARAM_INT
								),
								4	=> array(
									'value'	=> $control_id,
									'type'	=> PDO::PARAM_INT
								),
								5	=> array(
									'value'	=> $part_of_town_id,
									'type'	=> PDO::PARAM_INT
								),
								6	=> array(
									'value'	=> $user_id,
									'type'	=> PDO::PARAM_INT
								)
							);
						}
						else if ($new_roles && !$old_roles)
						{
							$add[] = array(
								1	=> array(
									'value'	=> $control_id,
									'type'	=> PDO::PARAM_INT
								),
								2	=> array(
									'value'	=> $part_of_town_id,
									'type'	=> PDO::PARAM_INT
								),
								3	=> array(
									'value'	=> $user_id,
									'type'	=> PDO::PARAM_INT
								),
								4	=> array(
									'value'	=> $new_roles,
									'type'	=> PDO::PARAM_INT
								),
								5	=> array(
									'value'	=> $now,
									'type'	=> PDO::PARAM_INT
								),
								6	=> array(
									'value'	=> $this->account,
									'type'	=> PDO::PARAM_INT
								)
							);
						}
						else if (!$new_roles)
						{
							$delete[] = array(
								1	=> array(
									'value'	=> $control_id,
									'type'	=> PDO::PARAM_INT
								),
								2	=> array(
									'value'	=> $part_of_town_id,
									'type'	=> PDO::PARAM_INT
								),
								3	=> array(
									'value'	=> $user_id,
									'type'	=> PDO::PARAM_INT
								)
							);
						}
					}
				}
			}
		}
		$this->db->transaction_begin();

		if ($add)
		{
			$add_sql = "INSERT INTO controller_control_user_role (control_id, part_of_town_id, user_id, roles, modified_on, modified_by) VALUES (?, ?, ?, ? ,? ,?)";
			$this->db->insert($add_sql, $add, __LINE__, __FILE__);
		}

		if ($update)
		{
			$update_sql = "UPDATE controller_control_user_role SET roles =?, modified_on = ?, modified_by = ? WHERE control_id =? AND part_of_town_id = ? AND user_id = ?";
			$this->db->insert($update_sql, $update, __LINE__, __FILE__);
		}

		if ($delete)
		{
			$delete_sql = "DELETE FROM controller_control_user_role WHERE control_id =? AND part_of_town_id = ? AND user_id = ?";
			$this->db->delete($delete_sql, $delete, __LINE__, __FILE__);
		}

		return $this->db->transaction_commit();
	}

	private function get_part_of_town($location_code)
	{
		$location_arr = explode('-', $location_code);

		$loc1 = $location_arr[0];

		$sql = "SELECT part_of_town_id FROM fm_location1 WHERE loc1 = ?";
		$statement = $this->db->prepare($sql);
		$statement->execute([$loc1]);

		$part_of_town_id = null;
		if ($row = $statement->fetch(PDO::FETCH_ASSOC))
		{
			$part_of_town_id = $row['part_of_town_id'];
		}
		return $part_of_town_id;
	}

	public function get_user_with_role($control_id, $location_code, $role)
	{
		if (!$location_code || !$control_id)
		{
			return false;
		}

		$part_of_town_id = $this->get_part_of_town($location_code);

		if (!$role || !$part_of_town_id)
		{
			return array();
		}
		/**
		 * Bitwise operator on 2 (inspector)
		 */
		$sql = "SELECT user_id, roles FROM controller_control_user_role WHERE control_id = ? AND part_of_town_id = ? AND (roles & ?) > 0;";
		$condition = array((int)$control_id, $part_of_town_id, (int)$role);

		$statement = $this->db->prepare($sql);
		$statement->execute($condition);

		$users = array();
		while ($row = $statement->fetch(PDO::FETCH_ASSOC))
		{
			$users[] = array(
				'user_id' => $row['user_id'],
				'roles' => $row['roles']
			);
		}

		$values = array();

		$sort_names = array();
		foreach ($users as $user)
		{
			$name = $this->accounts_obj->get($user['user_id'])->__toString();
			$sort_names[] = $name;
			$values[] = array(
				'id'	=> $user['user_id'],
				'roles'	=> $user['roles'],
				'name'	=> $name
			);
		}

		array_multisort($sort_names, SORT_ASC, $values);

		return $values;
	}

	public function get_inspectors($check_list_id)
	{
		$sql = "SELECT control_id, controller_check_list_inspector.user_id, location_code FROM controller_check_list "
			. " $this->left_join controller_check_list_inspector ON controller_check_list.id = controller_check_list_inspector.check_list_id"
			. " WHERE controller_check_list.id = :check_list_id";

		$statement = $this->db->prepare($sql);
		$statement->bindValue(':check_list_id', (int)$check_list_id, PDO::PARAM_INT);
		$statement->execute();

		$_inspectors = array();
		while ($row = $statement->fetch(PDO::FETCH_ASSOC))
		{
			$location_code = $row['location_code'];
			$control_id = $row['control_id'];
			$user_id = $row['user_id'];
			if ($user_id)
			{
				$_inspectors[] = $user_id;
			}
		}
		$part_of_town_id = $this->get_part_of_town($location_code);

		/**
		 * Bitwise operator on 2 (inspector)
		 */
		$sql = "SELECT user_id FROM controller_control_user_role WHERE control_id = ? AND part_of_town_id = ? AND (roles & 2) > 0;";
		$condition = array((int)$control_id, (int)$part_of_town_id);

		$statement = $this->db->prepare($sql);
		$statement->execute($condition);

		$inspectors = array();
		$__inspectors = array();

		while ($row = $statement->fetch(PDO::FETCH_ASSOC))
		{
			$__inspectors[] = $row['user_id'];
		}

		$___inspectors = array_unique(array_merge($_inspectors, $__inspectors));

		$sort_names = array();
		foreach ($___inspectors as $user_id)
		{
			$name = $this->accounts_obj->get($user_id)->__toString();
			$sort_names[] = $name;
			$inspectors[] = array(
				'id'	=> $user_id,
				'name'	=> $name,
				'selected' => in_array($user_id, $_inspectors)
			);
		}

		array_multisort($sort_names, SORT_ASC, $inspectors);

		return $inspectors;
	}

	public function get_all_inspectors($selected = array())
	{
		$sql = "SELECT DISTINCT controller_check_list_inspector.user_id"
			. " FROM controller_check_list_inspector";

		$this->db->query($sql, __LINE__, __FILE__);

		$inspectors = array();

		while ($this->db->next_record())
		{
			$inspectors[] = array('id' => (int)$this->db->f('user_id'));
		}

		$sort_names = array();
		foreach ($inspectors as &$user)
		{
			$name = $this->accounts_obj->get($user['id'])->__toString();
			$sort_names[] = $name;
			$user['name'] = $name;
			$user['selected'] = in_array($user['id'], $selected);
		}

		array_multisort($sort_names, SORT_ASC, $inspectors);

		return $inspectors;
	}
}
