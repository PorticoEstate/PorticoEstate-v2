<?php

namespace App\modules\phpgwapi\services;

use App\Database\Db;
use App\modules\phpgwapi\controllers\Locations;

require_once SRC_ROOT_PATH . '/modules/property/inc/class.soentity.inc.php';
require_once SRC_ROOT_PATH . '/modules/helpdesk/inc/class.botts.inc.php';
require_once SRC_ROOT_PATH . '/modules/property/inc/class.botts.inc.php';
require_once SRC_ROOT_PATH . '/modules/property/inc/class.solocation.inc.php';
require_once SRC_ROOT_PATH . '/modules/phpgwapi/inc/class.datetime.inc.php';


class InterLink
{

	var $db;
	var $locations_obj;
	private $_join = ' JOIN ';
	private $property_soentity, $helpdesk_botts, $property_botts, $property_solocation;

	public function __construct()
	{
		$this->db = Db::getInstance();
		$this->locations_obj = new Locations();
		$this->property_soentity = new \property_soentity();
		$this->helpdesk_botts = new \helpdesk_botts();
		$this->property_botts = new \property_botts();
		$this->property_solocation = new \property_solocation();

	}

	/**
	 * Get relation of the interlink
	 *
	 * @param string  $appname  the application name for the location
	 * @param string  $location the location name
	 * @param integer $id       id of the referenced item
	 * @param integer $role     role of the referenced item ('origin' or 'target')
	 *
	 * @return array interlink data
	 */
	public function get_relation($appname, $location, $id, $role = 'origin')
	{
		$location_id = (int) $this->locations_obj->get_id($appname, $location);
		$id = (int) $id;

		if ($role === 'target')
		{
			$sql = 'SELECT DISTINCT location2_id as linkend_location, location2_item_id as linkend_id, account_id, entry_date'
				. ' FROM phpgw_interlink'
				. ' WHERE location1_id = :location_id AND location1_item_id = :item_id'
				. ' ORDER BY location2_id DESC';
		}
		else
		{
			$sql = 'SELECT DISTINCT location1_id as linkend_location, location1_item_id as linkend_id, account_id, entry_date'
				. ' FROM phpgw_interlink'
				. ' WHERE location2_id = :location_id AND location2_item_id = :item_id'
				. ' ORDER BY location1_id DESC';
		}

		$stmt = $this->db->prepare($sql);
		$stmt->execute(array(
			':location_id' => $location_id,
			':item_id' => $id
		));

		$relation = array();
		$last_type = false;
		$i = -1;

		foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row)
		{
			if ($last_type != $row['linkend_location'])
			{
				$i++;
			}
			$relation[$i]['linkend_location'] = (int) $row['linkend_location'];
			$relation[$i]['data'][] = array(
				'id' => (int) $row['linkend_id'],
				'account_id' => (int) $row['account_id'],
				'entry_date' => $row['entry_date']
			);
			$last_type = $row['linkend_location'];
		}

		foreach ($relation as &$entry)
		{
			$linkend_location = $this->locations_obj->get_name($entry['linkend_location']);
			$entry['location'] = $linkend_location['location'];

			$entry['descr'] = $this->get_location_name($linkend_location['location']);

			foreach ($entry['data'] as &$data)
			{
				$data['link'] = $this->get_relation_link($linkend_location, $data['id']);
				$relation_info = $this->get_relation_info($linkend_location, $data['id']);
				$data['statustext'] = $relation_info['statustext'];
				$data['title'] = $relation_info['title'];
			}
		}
		return $relation;
	}

	/**
	 * Get additional info of the linked item
	 *
	 * @param array   $linkend_location the location
	 * @param integer $id   the id of the referenced item
	 *
	 * @return string info of the linked item
	 */
	public function get_relation_info($linkend_location, $id = 0)
	{
		$relation_info	 = array();
		$id				 = isset($linkend_location['id']) ? (int)$linkend_location['id'] : (int)$id;
		$type			 = $linkend_location['location'];
		if ($linkend_location['appname'] == 'helpdesk' && $type == '.ticket')
		{
			$this->db->query("SELECT status, subject AS title FROM phpgw_helpdesk_tickets WHERE id = {$id}", __LINE__, __FILE__);
			$this->db->next_record();
			$status_code			 = $this->db->f('status');
			$relation_info['title']	 = $this->db->f('title');

			static $status_text_helpdesk;
			if (!$status_text_helpdesk)
			{
				$status_text_helpdesk = $this->helpdesk_botts->get_status_text();
			}
			$relation_info['statustext'] = $status_text_helpdesk[$status_code];
			return $relation_info;
		}
		else if ($type == '.ticket')
		{
			$this->db->query("SELECT status, subject as title FROM fm_tts_tickets WHERE id = {$id}", __LINE__, __FILE__);
			$this->db->next_record();
			$status_code			 = $this->db->f('status');
			$relation_info['title']	 = $this->db->f('title');

			static $status_text;
			if (!$status_text)
			{
				$status_text = $this->property_botts->get_status_text();
			}
			$relation_info['statustext'] = $status_text[$status_code];
			return $relation_info;
		}
		else if ($type == '.project.workorder')
		{
			$this->db->query("SELECT fm_workorder_status.descr as status, fm_workorder.title FROM fm_workorder {$this->_join} fm_workorder_status ON fm_workorder.status = fm_workorder_status.id WHERE fm_workorder.id = {$id}", __LINE__, __FILE__);
			$this->db->next_record();
			$relation_info['statustext'] = $this->db->f('status');
			$relation_info['title']		 = $this->db->f('title');
			return $relation_info;
		}
		else if ($type == '.project.request')
		{
			$this->db->query("SELECT fm_request.title, fm_request_status.descr as status FROM fm_request {$this->_join} fm_request_status ON fm_request.status = fm_request_status.id WHERE fm_request.id = {$id}", __LINE__, __FILE__);
			$this->db->next_record();
			$relation_info['statustext'] = $this->db->f('status');
			$relation_info['title']		 = $this->db->f('title');
			return $relation_info;
		}
		else if ($type == '.project.condition_survey')
		{
			$this->db->query("SELECT fm_condition_survey.title, fm_condition_survey_status.descr as status FROM fm_condition_survey {$this->_join} fm_condition_survey_status ON fm_condition_survey.status_id = fm_condition_survey_status.id WHERE fm_condition_survey.id = {$id}", __LINE__, __FILE__);
			$this->db->next_record();
			$relation_info['statustext'] = $this->db->f('status');
			$relation_info['title']		 = $this->db->f('title');
			return $relation_info;
		}
		else if ($type == '.project')
		{
			$this->db->query("SELECT fm_project.name as title, fm_project_status.descr as status FROM fm_project {$this->_join} fm_project_status ON fm_project.status = fm_project_status.id WHERE fm_project.id = {$id}", __LINE__, __FILE__);
			$this->db->next_record();
			$relation_info['statustext'] = $this->db->f('status');
			$relation_info['title']		 = $this->db->f('title');
			return $relation_info;
		}
		else if (substr($type, 1, 6) == 'entity')
		{
			$type		 = explode('.', $type);
			$entity_id	 = (int)$type[2];
			$cat_id		 = (int)$type[3];
			$location_id = $this->locations_obj->get_id('property', ".entity.{$entity_id}.{$cat_id}");
			if ($location_id)
			{
				$metadata = $this->db->metadata("fm_entity_{$entity_id}_{$cat_id}");
				if (isset($metadata['status']))
				{
					$sql = "SELECT status FROM fm_entity_{$entity_id}_{$cat_id} WHERE id = {$id}";
				}
				else
				{
					$sql = "SELECT json_representation->>'status' as status FROM fm_bim_item"
						. " WHERE location_id = {$location_id}"
						. " AND id='{$id}'";
				}

				$this->db->query($sql, __LINE__, __FILE__);
				$this->db->next_record();
				if ($status_id = (int)$this->db->f('status'))
				{
					$sql = "SELECT phpgw_cust_choice.value as status FROM phpgw_cust_attribute"
						. " {$this->_join} phpgw_cust_choice ON phpgw_cust_attribute.location_id = phpgw_cust_choice.location_id "
						. " AND phpgw_cust_attribute.id = phpgw_cust_choice.attrib_id WHERE phpgw_cust_attribute.column_name = 'status'"
						. " AND phpgw_cust_choice.id = {$status_id} AND phpgw_cust_attribute.location_id = {$location_id}";
					$this->db->query($sql, __LINE__, __FILE__);
					$this->db->next_record();
					$relation_info['statustext'] = $this->db->f('status');
				}
			}

			$relation_info['title'] = 'N∕A';

			$short_desc = $this->property_soentity->get_short_description(array(
				'location_id'	 => $location_id,
				'id'			 => $id
			));
			if ($short_desc)
			{
				$relation_info['title'] = $short_desc;
			}

			return $relation_info;
		}
		else if (substr($type, 1, 5) == 'catch')
		{
			$type		 = explode('.', $type);
			$entity_id	 = (int)$type[2];
			$cat_id		 = (int)$type[3];
			// Not set
		}
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
	 * @param string  $location2 the location name of target
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
	 * @param integer $location_id the location
	 * @param integer $id			the id of the referenced item
	 *
	 * @return string the linkt to the the related item
	 */
	public function get_location_link($location_id, $id, $action = 'view')
	{
		$system_location = $this->locations_obj->get_name($location_id);

		$name = 'N∕A';
		if (preg_match('/.location./i', $system_location['location']))
		{
			$location_code = $this->property_solocation->get_location_code($id);
			$location		 = $this->property_solocation->read_single($location_code);
			$location_arr	 = explode('-', $location_code);
			$i				 = 1;
			$name_arr		 = array();
			foreach ($location_arr as $_dummy)
			{
				$name_arr[] = $location["loc{$i}_name"];
				$i++;
			}

			$name = implode('::', $name_arr);
		}
		else if (preg_match('/.entity./i', $system_location['location']))
		{
			$name = $this->property_soentity->get_short_description(array(
				'location_id'	 => $location_id,
				'id'			 => $id
			));
		}

		$link = $this->get_relation_link($system_location['location'], $id, $action);
		if ($link)
		{
			return array(
				'name'	 => $name,
				'link'	 => $link
			);
		}
		else
		{
			return array();
		}
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
	 * @param string  $location2 the location name of target
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


	/**
	 * Get entry date of the related item
	 *
	 * @param string  $appname  		  the application name for the location
	 * @param string  $origin_location the location name of the origin
	 * @param string  $target_location the location name of the target
	 * @param integer $id			  id of the referenced item (parent)
	 * @param integer $entity_id		  id of the entity type if the type is a entity
	 * @param integer $cat_id		  id of the entity_category type if the type is a entity
	 *
	 * @return array date_info and link to related items
	 */
	public function get_child_date($appname, $origin_location, $target_location, $id, $entity_id = '', $cat_id = '')
	{
		$userSettings = Settings::getInstance()->get('user');
		$dateformat = $userSettings['preferences']['common']['dateformat'];

		$location1_id = (int) $this->locations_obj->get_id($appname, $origin_location);
		$location2_id = (int) $this->locations_obj->get_id($appname, $target_location);
		$id = (int) $id;

		$sql = 'SELECT entry_date, location2_item_id AS item_id, :target_location AS location FROM phpgw_interlink'
			. ' WHERE location1_item_id = :item_id AND location1_id = :location1_id AND location2_id = :location2_id'
			. ' UNION'
			. ' SELECT entry_date, location1_item_id AS item_id, :target_location AS location FROM phpgw_interlink'
			. ' WHERE location2_item_id = :item_id AND location2_id = :location1_id AND location1_id = :location2_id';

		$stmt = $this->db->prepare($sql);
		$stmt->execute(array(
			':item_id' => $id,
			':location1_id' => $location1_id,
			':location2_id' => $location2_id,
			':target_location' => $target_location
		));

		$date_info = array();
		foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row)
		{
			$date_info[] = array(
				'entry_date' => \phpgwapi_datetime::show_date($row['entry_date'], $dateformat),
				'item_id' => (int) $row['item_id'],
				'location' => $row['location']
			);
		}

		foreach ($date_info as &$entry)
		{
			$entry['link'] = $this->get_relation_link(array('location' => $entry['location']), $entry['item_id']);
			if ($cat_id)
			{
				$entry['descr'] = $this->get_category_name($entity_id, $cat_id);
			}
			else
			{
				$entry['descr'] = lang($target_location);
			}
		}
		return $date_info;
	}

	/**
	 * Get location name
	 *
	 * @param array   $linkend_location the location
	 * @param integer $id			   the id of the referenced item
	 *
	 * @return string the linkt to the the related item
	 */
	public function get_location_name($location)
	{

		$location					 = ltrim($location, '.');
		$parts						 = explode('.', $location);
		$type	 = $parts[0];
		switch ($type)
		{
			case 'entity':
			case 'catch':
				$location_name = $this->get_category_name($parts[1], $parts[2], $type);
				break;
			default:
				$location_name	 = lang($location);
		}
		return $location_name;
	}

	private function get_category_name($entity_id, $cat_id, $type = 'entity')
	{
		if (!in_array($type, ['entity', 'catch']))
		{
			return '';
		}

		$stmt = $this->db->prepare("SELECT name FROM fm_{$type}_category WHERE entity_id = :entity_id AND id = :cat_id");
		$stmt->execute(array(
			':entity_id' => (int) $entity_id,
			':cat_id' => (int) $cat_id
		));

		return $stmt->fetchColumn();
	}
}
