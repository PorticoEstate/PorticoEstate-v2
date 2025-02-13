<?php

/**
 *
 * @author Sigurd Nes <sigurdne@online.no>
 * @copyright Copyright (C) 2003-2005 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @internal Development of this application was funded by http://www.bergen.kommune.no/bbb_/ekstern/
 * @package admin
 * @subpackage config
 * @version $Id: class.soconfig.inc.php 3613 2009-09-18 16:19:49Z sigurd $
 */

namespace App\modules\phpgwapi\services;

use App\helpers\DateHelper;
use App\Database\Db;
use App\modules\phpgwapi\services\Settings;
use PDO;

/**
 * Description
 * @package admin
 */

class ConfigLocation
{
	public $config_data = array();
	protected $db;
	protected $location_id = 0;
	protected $global_lock = false;
	var $join, $left_join, $like, $total_records;
	var $maxMatches;
	var $userSettings;


	public function __construct($location_id = 0)
	{
		$this->db			= Db::getInstance();
		$this->join			= 'JOIN';
		$this->left_join	= 'LEFT JOIN';
		$this->like			= 'ILIKE';

		$this->userSettings = Settings::getInstance()->get('user');

		$this->maxMatches = isset($this->userSettings['preferences']['common']['maxmatchs']) ? (int)$this->userSettings['preferences']['common']['maxmatchs'] : 15;

		if ($location_id)
		{
			$this->set_location($location_id);
			$this->read_repository();
		}
	}

	public function set_location(int $location_id)
	{
		$this->location_id = (int)$location_id;
	}

	public function read()
	{
		if (!$this->location_id)
		{
			throw new \Exception("location_id is not set");
		}

		if (!$this->config_data)
		{
			$this->read_repository();
		}
		return $this->config_data;
	}

	public function read_repository()
	{
		$sql = "SELECT phpgw_config2_section.name as section, value as config_value, phpgw_config2_attrib.name as config_name "
			. " FROM phpgw_config2_value"
			. " JOIN phpgw_config2_attrib ON phpgw_config2_value.attrib_id = phpgw_config2_attrib.id AND phpgw_config2_value.section_id = phpgw_config2_attrib.section_id"
			. " JOIN phpgw_config2_section ON phpgw_config2_value.section_id = phpgw_config2_section.id"
			. " WHERE location_id = :location_id";

		$stmt = $this->db->prepare($sql);
		$stmt->execute([':location_id' => $this->location_id]);

		while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
		{
			$test = unserialize($this->db->unmarshal($row['config_value'], 'string'));
			if ($test)
			{
				$this->config_data[$row['section']][$row['config_name']] = $test;
			}
			else
			{
				$this->config_data[$row['section']][$row['config_name']] = $this->db->unmarshal($row['config_value'], 'string');
			}
		}
	}


	function read_section(array $data)
	{
		$start		= isset($data['start']) && $data['start'] ? $data['start'] : 0;
		$query		= isset($data['query']) ? $data['query'] : '';
		$sort		= isset($data['sort']) && $data['sort'] ? $data['sort'] : 'DESC';
		$order		= isset($data['order']) ? $data['order'] : '';
		$allrows	= isset($data['allrows']) ? $data['allrows'] : '';

		if ($order)
		{
			$ordermethod = " ORDER BY $order $sort";
		}
		else
		{
			$ordermethod = ' ORDER BY name ASC';
		}

		$table = 'phpgw_config2_section';

		$querymethod = '';
		if ($query)
		{
			$querymethod = "AND name LIKE :query";
			$query = "%$query%";
		}

		$sql = "SELECT * FROM $table WHERE location_id = :location_id {$querymethod}";

		$stmt = $this->db->prepare($sql);
		$params = [':location_id' => $this->location_id];
		if ($query)
		{
			$params[':query'] = $query;
		}
		$stmt->execute($params);

		$this->total_records = $stmt->rowCount();

		$limit = $this->maxMatches;
		if (!$allrows)
		{
			$stmt = $this->db->prepare($sql . $ordermethod . " LIMIT :limit OFFSET :start");
			$params[':start'] = $start;
			$params[':limit'] = $limit;
		}
		else
		{
			$stmt = $this->db->prepare($sql . $ordermethod);
		}
		$stmt->execute($params);

		$config_info = array();
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
		{
			$config_info[] = array(
				'id'    => $row['id'],
				'name'  => stripslashes($row['name']),
				'descr' => stripslashes($row['descr'])
			);
		}
		return $config_info;
	}


	function read_single_section(int $id)
	{
		$id = (int)$id;
		$sql = "SELECT * FROM phpgw_config2_section WHERE location_id = :location_id AND id = :id";

		$stmt = $this->db->prepare($sql);
		$stmt->execute([':location_id' => $this->location_id, ':id' => $id]);

		$values = array();
		if ($row = $stmt->fetch(PDO::FETCH_ASSOC))
		{
			$values['id'] = $id;
			$values['name'] = $this->db->unmarshal($row['name'], 'string');
			$values['descr'] = $this->db->unmarshal($row['descr'], 'string');
		}
		return $values;
	}


	function add_section(array $values)
	{
		if ($this->db->get_transaction())
		{
			$this->global_lock = true;
		}
		else
		{
			$this->db->transaction_begin();
		}


		$sql = "SELECT id FROM phpgw_config2_section WHERE location_id = :location_id AND name = :name";

		$stmt = $this->db->prepare($sql);
		$stmt->execute([':location_id' => $this->location_id, ':name' => $values['name']]);

		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		if ($row)
		{
			$receipt['section_id'] =  $row['id'];
			$receipt['message'][] = array('msg' => lang('config section has not been saved'));
			return $receipt;
		}
		$values['section_id'] = $this->db->next_id('phpgw_config2_section');

		$insert_values = array(
			':id' => $values['section_id'],
			':location_id' => $this->location_id,
			':name' => $values['name'],
			':descr' => $values['descr'],
		);

		$sql = "INSERT INTO phpgw_config2_section (id, location_id, name, descr) VALUES (:id, :location_id, :name, :descr)";
		$stmt = $this->db->prepare($sql);
		$stmt->execute($insert_values);
		$receipt['message'][] = array('msg' => lang('config section has been saved'));
		$receipt['section_id'] = $values['section_id'];

		if (!$this->global_lock)
		{
			$this->db->transaction_commit();
		}

		return $receipt;
	}

	function edit_section(array $values)
	{
		if ($this->db->get_transaction())
		{
			$this->global_lock = true;
		}
		else
		{
			$this->db->transaction_begin();
		}


		$value_set = array(
			'name' => $values['name'],
			'descr' => $values['descr']
		);

		$sql = "UPDATE phpgw_config2_section SET name = :name, descr = :descr WHERE id = :id";
		$stmt = $this->db->prepare($sql);
		$stmt->execute([
			':name' => $value_set['name'],
			':descr' => $value_set['descr'],
			':id' => $values['section_id']
		]);

		if (!$this->global_lock)
		{
			$this->db->transaction_commit();
		}

		$receipt['message'][] = array('msg' => lang('config section has been edited'));

		$receipt['section_id'] = $values['section_id'];
		return $receipt;
	}

	function delete_section(int $id)
	{
		$id = (int)$id;

		if ($this->db->get_transaction())
		{
			$this->global_lock = true;
		}
		else
		{
			$this->db->transaction_begin();
		}

		$stmt = $this->db->prepare("DELETE FROM phpgw_config2_value WHERE section_id = :id");
		$stmt->execute([':id' => $id]);

		$stmt = $this->db->prepare("DELETE FROM phpgw_config2_choice WHERE section_id = :id");
		$stmt->execute([':id' => $id]);

		$stmt = $this->db->prepare("DELETE FROM phpgw_config2_attrib WHERE section_id = :id");
		$stmt->execute([':id' => $id]);

		$stmt = $this->db->prepare("DELETE FROM phpgw_config2_section WHERE id = :id");
		$stmt->execute([':id' => $id]);

		if (!$this->global_lock)
		{
			$this->db->transaction_commit();
		}
	}

	function read_attrib(array $data)
	{
		$start = isset($data['start']) && $data['start'] ? $data['start'] : 0;
		$query = isset($data['query']) ? $data['query'] : '';
		$sort = isset($data['sort']) && $data['sort'] ? $data['sort'] : 'DESC';
		$order = isset($data['order']) ? $data['order'] : '';
		$allrows = isset($data['allrows']) ? $data['allrows'] : '';
		$section_id = isset($data['section_id']) && $data['section_id'] ? (int)$data['section_id'] : 0;

		$ordermethod = $order ? " ORDER BY $order $sort" : ' ORDER BY name asc';

		$section_table = 'phpgw_config2_section';
		$attrib_table = 'phpgw_config2_attrib';
		$value_table = 'phpgw_config2_value';

		$querymethod = $query ? " AND {$attrib_table}.name LIKE :query" : '';

		$sql = "SELECT $attrib_table.id, $attrib_table.section_id, $value_table.id as value_id, $attrib_table.name, $attrib_table.descr, $attrib_table.input_type, $value_table.value"
			. " FROM ($section_table JOIN $attrib_table ON  ($section_table.id = $attrib_table.section_id))"
			. " LEFT JOIN $value_table ON ($attrib_table.section_id = $value_table.section_id AND $attrib_table.id = $value_table.attrib_id)"
			. " WHERE location_id = :location_id AND $attrib_table.section_id = :section_id $querymethod";

		$stmt = $this->db->prepare($sql);
		$params = [
			':location_id' => $this->location_id,
			':section_id' => $section_id
		];
		if ($query)
		{
			$params[':query'] = "%$query%";
		}
		$stmt->execute($params);


		$this->total_records = $stmt->rowCount();

		$limit = $this->maxMatches;

		if (!$allrows)
		{
			$stmt = $this->db->prepare($sql . $ordermethod . " LIMIT :limit OFFSET :start");
			$params[':start'] = $start;
			$params[':limit'] = $limit;
			$stmt->execute($params);
		}
		else
		{
			$stmt = $this->db->prepare($sql . $ordermethod);
			$stmt->execute($params);
		}

		$dateformat = $this->userSettings['preferences']['dateformat'];

		$config_info = array();
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
		{
			$input_type = $row['input_type'];
			switch ($input_type)
			{
				case 'password':
					$value = '****';
					break;
				case 'checkbox':
					$value = $row['value'];
					$test = unserialize($value);
					if ($test)
					{
						$value = $test;
					}

					break;
				case 'date':
					$value = DateHelper::showDate($row['value'], $dateformat);
					break;
				default:
					$value = $row['value'];
			}
			$config_info[] = array(
				'id' => $row['id'],
				'section_id' => $row['section_id'],
				'value_id' => $row['value_id'],
				'name' => $row['name'],
				'value' => $value,
				'descr' => $row['descr'],
			);
		}
		return $config_info;
	}


	function read_single_attrib(int $section_id, int $id)
	{
		$section_id = (int) $section_id;
		$id = (int) $id;

		$sql = "SELECT * FROM phpgw_config2_attrib WHERE section_id = :section_id AND id = :id";
		$stmt = $this->db->prepare($sql);
		$stmt->execute([':section_id' => $section_id, ':id' => $id]);

		$values = array();
		if ($row = $stmt->fetch(PDO::FETCH_ASSOC))
		{
			$values['id'] = $id;
			$values['input_type'] = $row['input_type'];
			$values['name'] = $this->db->unmarshal($row['name'], 'string');
			$values['descr'] = $this->db->unmarshal($row['descr'], 'string');
			if (in_array($row['input_type'], ['listbox', 'radio', 'checkbox']))
			{
				$values['choice'] = $this->read_attrib_choice($section_id, $id);
			}
		}

		return $values;
	}


	function read_attrib_choice(int $section_id, int $attrib_id)
	{
		$section_id	= (int) $section_id;
		$attrib_id	= (int) $attrib_id;

		$choice_table = 'phpgw_config2_choice';

		$sql = "SELECT * FROM {$choice_table} WHERE section_id = :section_id AND attrib_id = :attrib_id";
		$stmt = $this->db->prepare($sql);
		$stmt->execute([':section_id' => $section_id, ':attrib_id' => $attrib_id]);

		$choice = array();
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
		{
			$choice[] = array(
				'id'    => $row['id'],
				'value' => $this->db->unmarshal($row['value'], 'string')
			);
		}
		return $choice;
	}


	function add_attrib(array $values)
	{
		if ($this->db->get_transaction())
		{
			$this->global_lock = true;
		}
		else
		{
			$this->db->transaction_begin();
		}

		$sql = "SELECT id FROM phpgw_config2_attrib WHERE section_id = :section_id AND name = :name";
		$stmt = $this->db->prepare($sql);
		$stmt->execute([':section_id' => $values['section_id'], ':name' => $values['name']]);

		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		if ($row)
		{
			$receipt['attrib_id'] = $row['id'];
			$receipt['error'][] = array('msg' => lang('config attrib has been saved'));
			return $receipt;
		}

		$values['attrib_id'] = $this->db->lastInsertId();

		$insert_values = array(
			':section_id' => $values['section_id'],
			':attrib_id' => $values['attrib_id'],
			':input_type' => $values['input_type'],
			':name' => $values['name'],
			':descr' => $values['descr'],
		);

		$sql = "INSERT INTO phpgw_config2_attrib (section_id,id,input_type,name,descr) "
			. "VALUES (:section_id, :attrib_id, :input_type, :name, :descr)";
		$stmt = $this->db->prepare($sql);
		$stmt->execute($insert_values);

		$choice_map = array();
		if (isset($values['choice']) && $values['choice'])
		{
			foreach ($values['choice'] as $choice)
			{
				$values['new_choice'] = $choice;
				$this->edit_attrib($values);
			}
		}

		if (isset($values['value']) && $values['value'])
		{
			$this->add_value($values);
		}

		$receipt['message'][] = array('msg' => lang('config attrib has been saved'));
		$receipt['attrib_id'] = $values['attrib_id'];

		if (!$this->global_lock)
		{
			$this->db->transaction_commit();
		}

		return $receipt;
	}

	function edit_attrib(array $values)
	{
		if ($this->db->get_transaction())
		{
			$this->global_lock = true;
		}
		else
		{
			$this->db->transaction_begin();
		}


		$value_set = array(
			':name' => $values['name'],
			':descr' => $values['descr'],
			':input_type' => $values['input_type'],
		);

		$sql = "UPDATE phpgw_config2_attrib SET name = :name, descr = :descr, input_type = :input_type WHERE section_id = :section_id AND id = :attrib_id";
		$stmt = $this->db->prepare($sql);
		$stmt->execute(array_merge($value_set, [
			':section_id' => $values['section_id'],
			':attrib_id' => $values['attrib_id']
		]));

		if ($values['new_choice'])
		{
			$choice_id = $this->db->lastInsertId();

			$values_insert = array(
				':section_id' => $values['section_id'],
				':attrib_id' => $values['attrib_id'],
				':choice_id' => $choice_id,
				':new_choice' => $values['new_choice']
			);

			$sql = "INSERT INTO phpgw_config2_choice (section_id, attrib_id, id, value) VALUES (:section_id, :attrib_id, :choice_id, :new_choice)";
			$stmt = $this->db->prepare($sql);
			$stmt->execute($values_insert);
		}

		if (isset($values['delete_choice']) && is_array($values['delete_choice']))
		{
			foreach ($values['delete_choice'] as $choice_id)
			{
				$sql = "DELETE FROM phpgw_config2_choice WHERE section_id = :section_id AND attrib_id = :attrib_id AND id = :choice_id";
				$stmt = $this->db->prepare($sql);
				$stmt->execute([
					':section_id' => $values['section_id'],
					':attrib_id' => $values['attrib_id'],
					':choice_id' => $choice_id
				]);
			}
		}

		if (!$this->global_lock)
		{
			$this->db->transaction_commit();
		}

		$receipt['message'][] = array('msg' => lang('config attrib has been edited'));

		$receipt['attrib_id'] = $values['attrib_id'];
		$receipt['choice_id'] = $choice_id;
		return $receipt;
	}

	function delete_attrib(int $section_id, int $id)
	{
		$section_id	= (int) $section_id;
		$id			= (int) $id;

		if ($this->db->get_transaction())
		{
			$this->global_lock = true;
		}
		else
		{
			$this->db->transaction_begin();
		}

		$stmt = $this->db->prepare("DELETE FROM phpgw_config2_value WHERE section_id = :section_id AND attrib_id = :id");
		$stmt->execute([':section_id' => $section_id, ':id' => $id]);

		$stmt = $this->db->prepare("DELETE FROM phpgw_config2_choice WHERE section_id = :section_id AND attrib_id = :id");
		$stmt->execute([':section_id' => $section_id, ':id' => $id]);

		$stmt = $this->db->prepare("DELETE FROM phpgw_config2_attrib WHERE section_id = :section_id AND id = :id");
		$stmt->execute([':section_id' => $section_id, ':id' => $id]);

		if (!$this->global_lock)
		{
			$this->db->transaction_commit();
		}
	}

	function read_value(array $data)
	{
		$start		= isset($data['start']) && (int)$data['start'] ? $data['start'] : 0;
		$query		= isset($data['query']) ? $data['query'] : '';
		$sort		= isset($data['sort']) && $data['sort'] ? $data['sort'] : 'DESC';
		$order		= isset($data['order']) ? $data['order'] : '';
		$allrows	= isset($data['allrows']) ? $data['allrows'] : '';
		$section_id	= isset($data['section_id']) && $data['section_id'] ? (int)$data['section_id'] : 0;
		$attrib_id	= isset($data['attrib_id']) && $data['attrib_id'] ? (int)$data['attrib_id'] : 0;

		$ordermethod = $order ? " ORDER BY $order $sort" : ' ORDER BY value ASC';

		$table = 'phpgw_config2_value';

		$querymethod = '';
		if ($query)
		{
			$querymethod = " AND name LIKE :query";
			$query = "%$query%";
		}

		$sql = "SELECT * FROM $table WHERE section_id = :section_id AND attrib_id = :attrib_id $querymethod";

		$stmt = $this->db->prepare($sql);

		$params = [
			':section_id' => $section_id,
			':attrib_id' => $attrib_id
		];
		if ($query)
		{
			$params[':query'] = $query;
		}

		$stmt->execute($params);

		$this->total_records = $stmt->rowCount();

		$limit = $this->maxMatches;

		if (!$allrows)
		{
			$stmt = $this->db->prepare($sql . $ordermethod . " LIMIT :limit OFFSET :start");
			$params[':start'] = $start;
			$params[':limit'] = $limit;
			$stmt->execute($params);
		}
		else
		{
			$stmt = $this->db->prepare($sql . $ordermethod);
			$stmt->execute();
		}

		$config_info = array();
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
		{
			$value = $row['value'];
			$test = unserialize($value);
			if ($test)
			{
				$value = $test;
			}
			$config_info[] = array(
				'id'        => $row['id'],
				'section_id'    => $section_id,
				'attrib_id'    => $attrib_id,
				'value'        => $value,
			);
		}
		return $config_info;
	}

	function read_single_value(int $section_id, int $attrib_id, int $id)
	{
		$section_id	= (int) $section_id;
		$attrib_id	= (int) $attrib_id;
		$id			= (int) $id;

		$sql = "SELECT * FROM phpgw_config2_value WHERE section_id = :section_id AND attrib_id = :attrib_id AND id = :id";
		$stmt = $this->db->prepare($sql);
		$stmt->execute([':section_id' => $section_id, ':attrib_id' => $attrib_id, ':id' => $id]);

		$values = array();
		if ($row = $stmt->fetch(PDO::FETCH_ASSOC))
		{
			$values['id']		= $id;
			$value	=	stripslashes($row['value']);
			$test = unserialize($value);
			if ($test)
			{
				$values['value'] = $test;
			}
			else
			{
				$values['value'] = $value;
			}
		}

		return $values;
	}

	function add_value($values)
	{
		if (isset($values['input_type']) && $values['input_type'] == 'date')
		{
			$values['value'] = DateHelper::date_to_timestamp($values['value']);
		}

		if ($this->db->get_transaction())
		{
			$this->global_lock = true;
		}
		else
		{
			$this->db->transaction_begin();
		}

		if (is_array($values['value']))
		{
			$values['value'] = serialize($values['value']);
		}

		$id = $this->db->next_id('phpgw_config2_value', array('section_id' => $values['section_id'], 'attrib_id' => $values['attrib_id']));

		$insert_values = array(
			':section_id' => $values['section_id'],
			':attrib_id' => $values['attrib_id'],
			':id' => $id,
			':value' => $values['value']
		);

		$sql = "INSERT INTO phpgw_config2_value (section_id, attrib_id, id, value) VALUES (:section_id, :attrib_id, :id, :value)";
		$stmt = $this->db->prepare($sql);
		$stmt->execute($insert_values);

		$receipt['message'][] = array('msg' => lang('config value has been saved'));
		$receipt['id'] = $id;

		if (!$this->global_lock)
		{
			$this->db->transaction_commit();
		}

		return $receipt;
	}

	function edit_value($values)
	{
		if (isset($values['input_type']) && $values['input_type'] == 'date')
		{
			$values['value'] = DateHelper::date_to_timestamp($values['value']);
		}

		if (!$values['value'])
		{
			$this->delete_value($values['section_id'], $values['attrib_id'], $values['id']);
		}
		else
		{
			if ($this->db->get_transaction())
			{
				$this->global_lock = true;
			}
			else
			{
				$this->db->transaction_begin();
			}

			if (is_array($values['value']))
			{
				$values['value'] = serialize($values['value']);
			}

			$value_set = array(
				':value' => $values['value'],
				':section_id' => (int)$values['section_id'],
				':attrib_id' => (int)$values['attrib_id'],
				':id' => (int)$values['id']
			);

			$sql = "UPDATE phpgw_config2_value SET value = :value WHERE section_id = :section_id AND attrib_id = :attrib_id AND id = :id";
			$stmt = $this->db->prepare($sql);
			$stmt->execute($value_set);

			if (!$this->global_lock)
			{
				$this->db->transaction_commit();
			}
		}

		$receipt['message'][] = array('msg' => lang('config value has been edited'));

		$receipt['id'] = $values['id'];
		return $receipt;
	}

	function delete_value($section_id, $attrib_id, $id)
	{
		$section_id	= (int) $section_id;
		$attrib_id	= (int) $attrib_id;
		$id			= (int) $id;

		if ($this->db->get_transaction())
		{
			$this->global_lock = true;
		}
		else
		{
			$this->db->transaction_begin();
		}

		$stmt = $this->db->prepare("DELETE FROM phpgw_config2_value WHERE section_id = :section_id AND attrib_id = :attrib_id AND id = :id");
		$stmt->execute([':section_id' => $section_id, ':attrib_id' => $attrib_id, ':id' => $id]);

		if (!$this->global_lock)
		{
			$this->db->transaction_commit();
		}
	}

	function select_choice_list($section_id, $attrib_id)
	{
		$section_id = (int) $section_id;
		$attrib_id = (int) $attrib_id;

		$stmt = $this->db->prepare("SELECT * FROM phpgw_config2_choice WHERE section_id = :section_id AND attrib_id = :attrib_id ORDER BY value");
		$stmt->execute([':section_id' => $section_id, ':attrib_id' => $attrib_id]);

		$choice = array();

		while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
		{
			$value = $this->db->unmarshal($row['value'], 'string');
			$choice[] = array(
				'id'    => $value, //used for the value of the option in ui-forms
				'name'  => $value
			);
		}
		return $choice;
	}

	function select_conf_list()
	{
		$stmt = $this->db->prepare("SELECT * FROM phpgw_config2_section WHERE location_id = :location_id ORDER BY name");
		$stmt->execute([':location_id' => $this->location_id]);

		$section = array();
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
		{
			$section[] = array(
				'id'    => $row['id'],
				'name'  => $this->db->unmarshal($row['name'], 'string')
			);
		}
		return $section;
	}
}
