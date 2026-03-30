<?php

namespace App\modules\phpgwapi\services;

use App\Database\Db;
use App\modules\phpgwapi\controllers\Locations;


class InterLink
{

	var $db;
	var $locations_obj;

	public function __construct()
	{
		$this->db = Db::getInstance();
		$this->locations_obj = new Locations();
	}

	/**
	 * Add link to item
	 *
	 * @param array  $data	link data
	 * @param object $db		db-object - used to keep the operation within the callers transaction
	 *
	 * @return bool true on success, false otherwise
	 */
	public function add($data, $db = '')
	{
		if (!$db)
		{
			$db = $this->db;
		}

		$location1_id		 = (int) $data['location1_id'];
		$location1_item_id	 = (int) $data['location1_item_id'];
		$location2_id		 = (int) $data['location2_id'];
		$location2_item_id	 = (int) $data['location2_item_id'];
		$account_id			 = (int) $data['account_id'];
		$entry_date			 = time();
		$is_private			 = !empty($data['is_private']) ? (int) $data['is_private'] : -1;
		$start_date			 = !empty($data['start_date']) ? (int) $data['start_date'] : -1;
		$end_date			 = !empty($data['end_date']) ? (int) $data['end_date'] : -1;

		$stmt = $db->prepare('SELECT 1 FROM phpgw_interlink WHERE location1_id = :location1_id AND location1_item_id = :location1_item_id AND location2_id = :location2_id AND location2_item_id = :location2_item_id LIMIT 1');
		$stmt->execute(array(
			':location1_id' => $location1_id,
			':location1_item_id' => $location1_item_id,
			':location2_id' => $location2_id,
			':location2_item_id' => $location2_item_id
		));

		if ($stmt->fetchColumn())
		{
			return false;
		}

		$sql = 'INSERT INTO phpgw_interlink (location1_id, location1_item_id, location2_id, location2_item_id, account_id, entry_date, is_private, start_date, end_date)'
			. ' VALUES (:location1_id, :location1_item_id, :location2_id, :location2_item_id, :account_id, :entry_date, :is_private, :start_date, :end_date)';

		$valueset = array(
			array(
				':location1_id' => array(
					'value' => $location1_id,
					'type' => \PDO::PARAM_INT
				),
				':location1_item_id' => array(
					'value' => $location1_item_id,
					'type' => \PDO::PARAM_INT
				),
				':location2_id' => array(
					'value' => $location2_id,
					'type' => \PDO::PARAM_INT
				),
				':location2_item_id' => array(
					'value' => $location2_item_id,
					'type' => \PDO::PARAM_INT
				),
				':account_id' => array(
					'value' => $account_id,
					'type' => \PDO::PARAM_INT
				),
				':entry_date' => array(
					'value' => $entry_date,
					'type' => \PDO::PARAM_INT
				),
				':is_private' => array(
					'value' => $is_private,
					'type' => \PDO::PARAM_INT
				),
				':start_date' => array(
					'value' => $start_date,
					'type' => \PDO::PARAM_INT
				),
				':end_date' => array(
					'value' => $end_date,
					'type' => \PDO::PARAM_INT
				)
			)
		);

		return $db->insert($sql, $valueset, __LINE__, __FILE__);
	}

	private function deleteInterlink($sql, $values, $db)
	{
		$valueset = array(array());

		foreach ($values as $placeholder => $value)
		{
			$valueset[0][$placeholder] = array(
				'value' => (int) $value,
				'type' => \PDO::PARAM_INT
			);
		}

		return $db->delete($sql, $valueset, __LINE__, __FILE__);
	}


	/**
	 * Delete link at origin
	 *
	 * @param string  $appname   the application name for the location
	 * @param string  $location1 the location name of origin
	 * @param string  $location1 the location name of target
	 * @param integer $id        id of the referenced item
	 * @param object $db			db-object - used to keep the operation within the callers transaction
	 *
	 * @return array interlink data
	 */
	public function delete_at_origin($appname, $location1, $location2, $id, $db = '')
	{
		if (!$db)
		{
			$db = $this->db;
		}

		$sql = 'DELETE FROM phpgw_interlink WHERE location1_id = :location1_id AND location2_id = :location2_id AND location1_item_id = :item_id';

		return $this->deleteInterlink($sql, array(
			':location1_id' => $this->locations_obj->get_id($appname, $location1),
			':location2_id' => $this->locations_obj->get_id($appname, $location2),
			':item_id' => $id
		), $db);
	}

	/**
	 * Delete all relations based on a given start point (location1 and item1)
	 *
	 * @param string  $appname   the application name for the location
	 * @param string  $location  the location name of target
	 * @param integer $id        id of the referenced item
	 * @param object $db			db-object - used to keep the operation within the callers transaction
	 *
	 * @return array interlink data
	 */
	public function delete_at_target($appname, $location, $id, $db = '')
	{
		if (!$db)
		{
			$db = $this->db;
		}

		$sql = 'DELETE FROM phpgw_interlink WHERE location1_id = :location_id AND location1_item_id = :item_id';

		return $this->deleteInterlink($sql, array(
			':location_id' => $this->locations_obj->get_id($appname, $location),
			':item_id' => $id
		), $db);
	}

	/**
	 * Delete all relations based on a given end point (location2 and item2)
	 *
	 * @param string  $appname   the application name for the location
	 * @param string  $location  the location name of target
	 * @param integer $id        id of the referenced item
	 * @param object $db			db-object - used to keep the operation within the callers transaction
	 *
	 * @return array interlink data
	 */
	public function delete_from_target($appname, $location, $id, $db = '')
	{
		if (!$db)
		{
			$db = $this->db;
		}

		$sql = 'DELETE FROM phpgw_interlink WHERE location2_id = :location_id AND location2_item_id = :item_id';

		return $this->deleteInterlink($sql, array(
			':location_id' => $this->locations_obj->get_id($appname, $location),
			':item_id' => $id
		), $db);
	}


	/**
	 * Get relation of the interlink
	 *
	 * @param array   $linkend_location the location
	 * @param integer $id			   the id of the referenced item
	 *
	 * @return string the linkt to the the related item
	 */
	public function get_relation_link($linkend_location, $id, $function = 'edit', $external = false)
	{
		$link = array();

		if (is_array($linkend_location))
		{
			$type = $linkend_location['location'];
		}
		else
		{
			$type = $linkend_location;
		}
		$appname = !empty($linkend_location['appname']) ? $linkend_location['appname'] : 'property';
		if ($type == '.tenant_claim')
		{
			$link = array('menuaction' => "{$appname}.uitenant_claim.edit", 'claim_id' => $id);
		}
		if ($type == '.ticket')
		{
			$link = array('menuaction' => "{$appname}.uitts.view", 'id' => $id);
		}
		else if ($type == '.application')
		{
			$link = array('menuaction' => "{$appname}.uiapplication.view", 'id' => $id);
		}
		else if ($type == '.s_agreement')
		{
			$link = array('menuaction' => 'property.uis_agreement.edit', 'id' => $id);
		}
		else if ($type == '.agreement')
		{
			$link = array('menuaction' => 'property.uiagreement.edit', 'id' => $id);
		}
		else if ($type == '.document')
		{
			$link = array('menuaction' => 'property.uidocument.edit', 'id' => $id);
		}
		else if ($type == '.project.workorder')
		{
			$link = array('menuaction' => "property.uiworkorder.{$function}", 'id' => $id);
		}
		else if ($type == '.project.request')
		{
			$link = array('menuaction' => "property.uirequest.{$function}", 'id' => $id);
		}
		else if ($type == '.project.condition_survey')
		{
			$link = array('menuaction' => "property.uicondition_survey.{$function}", 'id' => $id);
		}
		else if ($type == '.project')
		{
			$link = array('menuaction' => "property.uiproject.{$function}", 'id' => $id);
		}
		else if (substr($type, 1, 6) == 'entity')
		{
			$type		 = explode('.', $type);
			$entity_id	 = $type[2];
			$cat_id		 = $type[3];
			$link		 = array(
				'menuaction' => "property.uientity.{$function}",
				'entity_id'	 => $entity_id,
				'cat_id'	 => $cat_id,
				'id'		 => $id
			);
		}
		else if (substr($type, 1, 5) == 'catch')
		{
			$type		 = explode('.', $type);
			$entity_id	 = $type[2];
			$cat_id		 = $type[3];
			$link		 = array(
				'menuaction' => "property.uientity.{$function}",
				'type'		 => 'catch',
				'entity_id'	 => $entity_id,
				'cat_id'	 => $cat_id,
				'id'		 => $id
			);
		}
		else if ($type == '.checklist')
		{
			$link = array('menuaction' => 'controller.uicheck_list.view_control_info', 'check_list_id' => $id);
		}
		else if ($type == '.activity')
		{
			$link = array(
				'menuaction'	 => 'logistic.uiactivity.view_resource_allocation',
				'activity_id'	 => $id
			);
		}
		else if (substr($type, 1, 8) == 'location')
		{
			$type	 = explode('.', $type);
			$link	 = array(
				'menuaction'	 => "property.uilocation.{$function}",
				'location_code'	 => $id,
			);
		}

		if ($external)
		{
			return \phpgw::link('/index.php', $link, false, true);
		}
		else
		{
			return \phpgw::link('/index.php', $link);
		}
	}


	/**
	 * Get specific target
	 *
	 * @param string  $appname  the application name for the location
	 * @param string  $location1 the location name of origin
	 * @param string  $location1 the location name of target
	 * @param integer $id       id of the referenced item
	 * @param integer $role     role of the referenced item ('origin' or 'target')
	 *
	 * @return array targets
	 */
	public function get_specific_relation($appname, $location1, $location2, $id, $role = 'origin')
	{
		$location1_id	 = (int) $this->locations_obj->get_id($appname, $location1);
		$location2_id	 = (int) $this->locations_obj->get_id($appname, $location2);
		$id				 = (int) $id;

		if ($role === 'target')
		{
			$sql = 'SELECT location2_item_id AS item_id FROM phpgw_interlink'
				. ' WHERE location1_id = :location1_id AND location2_id = :location2_id AND location1_item_id = :item_id';
		}
		else
		{
			$sql = 'SELECT location1_item_id AS item_id FROM phpgw_interlink'
				. ' WHERE location1_id = :location1_id AND location2_id = :location2_id AND location2_item_id = :item_id';
		}

		$stmt = $this->db->prepare($sql);
		$stmt->execute(array(
			':location1_id' => $location1_id,
			':location2_id' => $location2_id,
			':item_id'      => $id
		));

		return array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'item_id');
	}
}
