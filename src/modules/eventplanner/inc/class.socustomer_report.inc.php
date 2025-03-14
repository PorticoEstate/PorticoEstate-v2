<?php

/**
 * phpGroupWare - property: a part of a Facilities Management System.
 *
 * @author Sigurd Nes <sigurdne@online.no>
 * @copyright Copyright (C) 2016 Free Software Foundation, Inc. http://www.fsf.org/
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
 * @internal Development of this application was funded by http://www.bergen.kommune.no/
 * @package eventplanner
 * @subpackage customer_report
 * @version $Id: $
 */

use App\modules\phpgwapi\controllers\Accounts\Accounts;

phpgw::import_class('phpgwapi.socommon');

class eventplanner_socustomer_report extends phpgwapi_socommon
{

	protected static $so;

	public function __construct()
	{
		parent::__construct('eventplanner_booking_customer_report', eventplanner_customer_report::get_fields());
		$this->acl_location = eventplanner_customer_report::acl_location;
		$this->cats = CreateObject('phpgwapi.categories', -1, 'eventplanner', $this->acl_location);
		$this->cats->supress_info = true;
		$this->use_acl = true;
	}

	/**
	 * Implementing classes must return an instance of itself.
	 *
	 * @return the class instance.
	 */
	public static function get_instance()
	{
		if (self::$so == null)
		{
			self::$so = CreateObject('eventplanner.socustomer_report');
		}
		return self::$so;
	}

	function get_acl_condition()
	{
		$acl_condition = parent::get_acl_condition();

		if ($this->relaxe_acl)
		{
			return $acl_condition;
		}

		$sql = "SELECT object_id, permission FROM eventplanner_permission WHERE subject_id = {$this->account}";
		$this->db->query($sql, __LINE__, __FILE__);
		$object_ids = array(-1);
		while ($this->db->next_record())
		{
			$permission = $this->db->f('permission');
			if ($permission & ACL_READ)
			{
				$object_ids[] = $this->db->f('object_id');
			}
		}

		if ($acl_condition)
		{
			return '(' . $acl_condition . ' OR eventplanner_booking_customer_report.id IN (' . implode(',', $object_ids) . '))';
		}
		else
		{
			return 'eventplanner_booking_customer_report.id IN (' . implode(',', $object_ids) . ')';
		}
	}

	protected function populate(array $data)
	{
		$object = new eventplanner_customer_report();
		foreach ($this->fields as $field => $field_info)
		{
			$object->set_field($field, $data[$field]);
		}

		return $object;
	}

	function get_category_name($cat_id)
	{
		static $category_name = array();

		if (!isset($category_name[$cat_id]))
		{
			$category = $this->cats->return_single($cat_id);
			$category_name[$cat_id] = $category[0]['name'];
		}
		return $category_name[$cat_id];
	}

	protected function update($object)
	{
		$this->db->transaction_begin();
		$dateformat = $this->userSettings['preferences']['common']['dateformat'];
		$accounts_obj = new Accounts();
		$original = $this->read_single($object->get_id()); //returned as array()
		foreach ($this->fields as $field => $params)
		{
			$new_value = $object->$field;
			$old_value = $original[$field];
			if (!empty($params['history']) && ($new_value != $old_value))
			{
				$label = !empty($params['label']) ? lang($params['label']) : $field;
				switch ($field)
				{
					case 'status':
						$old_value = $status_text[$old_value];
						$new_value = $status_text[$new_value];
						break;
					case 'date_start':
					case 'date_end':
						$old_value = $this->phpgwapi_common->show_date($old_value, $dateformat);
						$new_value = $this->phpgwapi_common->show_date($new_value, $dateformat);

						break;
					case 'case_officer_id':
						$old_value = $old_value ? $accounts_obj->get($old_value)->__toString() : '';
						$new_value = $new_value ? $accounts_obj->get($new_value)->__toString() : '';
						break;
					case 'category_id':
						$old_value = $old_value ? $this->get_category_name($old_value) : '';
						$new_value = $new_value ? $this->get_category_name($new_value) : '';
						break;
					default:
						break;
				}
				$value_set = array(
					'customer_report_id'	=> $object->get_id(),
					'time'		=> time(),
					'author'	=> $this->userSettings['fullname'],
					'comment'	=> $label . ':: ' . lang('old value') . ': ' . $this->db->db_addslashes($old_value) . ', ' . lang('new value') . ': ' . $this->db->db_addslashes($new_value),
					'type'	=> 'history',
				);

				$this->db->query('INSERT INTO eventplanner_customer_report_comment (' .  implode(',', array_keys($value_set))   . ') VALUES ('
					. $this->db->validate_insert(array_values($value_set)) . ')', __LINE__, __FILE__);
			}
		}

		parent::update($object);

		return	$this->db->transaction_commit();
	}
}
