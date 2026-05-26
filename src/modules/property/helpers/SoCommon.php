<?php

namespace App\modules\property\helpers;

use App\Database\Db;
use App\Database\Db2;
use App\modules\phpgwapi\security\Acl;
use App\modules\phpgwapi\services\Settings;
use App\traits\DbRowTrait;

class SoCommon
{
	use DbRowTrait;

	/**
	 * @var Db
	 */
	public $db;

	/**
	 * @var string
	 */
	public $join = ' INNER JOIN ';

	/**
	 * @var string
	 */
	public $like = 'LIKE';

	/**
	 * @var string
	 */
	public $left_join = ' LEFT JOIN ';

	/**
	 * @var int
	 */
	public $account;

	/**
	 * @var array
	 */
	public $userSettings;

	public function __construct(?Db $db = null, $join = ' INNER JOIN ')
	{
		$this->db = $db ? $db : Db::getInstance();
		$this->join = $join;

		$this->userSettings = Settings::getInstance()->get('user');
		$this->account = isset($this->userSettings['account_id']) ? (int)$this->userSettings['account_id'] : -1;

		$serverSettings = Settings::getInstance()->get('server');
		if (isset($serverSettings['db_type']))
		{
			switch ($serverSettings['db_type'])
			{
				case 'pgsql':
				case 'postgres':
					$this->join = ' JOIN ';
					$this->like = 'ILIKE';
					break;
				default:
					break;
			}
		}
	}

	public function fm_cache($name = '', $value = '')
	{
		return $this->fmCache($name, $value);
	}

	public function reset_fm_cache()
	{
		return $this->resetFmCache();
	}

	public function reset_fm_cache_userlist()
	{
		return $this->resetFmCacheUserlist($this->like);
	}

	public function create_preferences($app = '', $user_id = '')
	{
		return $this->createPreferences($app, $user_id);
	}

	public function read_single_tenant($id)
	{
		return $this->readSingleTenant($id);
	}

	public function check_location($location_code = '', $type_id = '')
	{
		return $this->checkLocation($location_code, $type_id);
	}

	public function select_part_of_town($district_id = 0)
	{
		return $this->selectPartOfTown($district_id);
	}

	public function select_district_list()
	{
		return $this->selectDistrictList();
	}

	public function next_id($table = '', $key = '')
	{
		return $this->nextId($table, $key);
	}

	public function get_lookup_entity($location)
	{
		return $this->getLookupEntity($location);
	}

	public function get_start_entity($location)
	{
		return $this->getStartEntity($location);
	}

	public function increment_id($name)
	{
		return $this->incrementId($name);
	}

	public function new_db($db = null)
	{
		return $this->newDb($db);
	}

	public function get_max_location_level()
	{
		return $this->getMaxLocationLevel();
	}

	public function get_location_list($required)
	{
		return $this->getLocationList($required);
	}

	public function get_order_type($id)
	{
		return $this->getOrderType($id);
	}

	public function readSingleTenant($id)
	{
		$this->db->query("SELECT * FROM fm_tenant WHERE id = " . (int)$id, __LINE__, __FILE__);

		if (!$this->db->next_record())
		{
			return array();
		}

		return array(
			'first_name' => $this->db->f('first_name', true),
			'last_name' => $this->db->f('last_name', true),
			'contact_phone' => $this->db->f('contact_phone', true)
		);
	}

	public function unquote(&$arr)
	{
		if (!is_array($arr))
		{
			$arr = stripslashes($arr);
			return;
		}
		foreach ($arr as $key => $value)
		{
			if (is_array($value))
			{
				$this->unquote($arr[$key]);
			}
			else
			{
				$arr[$key] = stripslashes($value);
			}
		}
	}

	public function createPreferences($app = '', $user_id = '')
	{
		$sql = 'SELECT preference_json, preference_owner FROM phpgw_preferences'
			. ' WHERE preference_app = :preference_app'
			. ' AND preference_owner IN (-1,-2,:user_id)';
		$stmt = $this->db->prepare($sql);
		$stmt->execute([
			':preference_app' => $app,
			':user_id' => (int)$user_id,
		]);
		$forced = $default = $user = array();
		while ($row = $stmt->fetch(\PDO::FETCH_ASSOC))
		{
			$value = json_decode($row['preference_json'], true);
			$this->unquote($value);
			if (!is_array($value))
			{
				continue;
			}
			switch ((int)$row['preference_owner'])
			{
				case -1:
					$forced[$app] = $value;
					break;
				case -2:
					$default[$app] = $value;
					break;
				default:
					$user[$app] = $value;
					break;
			}
		}
		$data = $user;

		foreach ($default as $app => $values)
		{
			foreach ($values as $var => $value)
			{
				if (!isset($data[$app][$var]) || $data[$app][$var] === '')
				{
					$data[$app][$var] = $value;
				}
			}
		}

		foreach ($forced as $app => $values)
		{
			foreach ($values as $var => $value)
			{
				$data[$app][$var] = $value;
			}
		}

		return $data[$app];
	}

	public function selectPartOfTown($district_id = 0)
	{
		$filter = '';
		$params = [];
		$part_of_town = array();
		if ($district_id)
		{
			$filter = 'WHERE district_id = :district_id';
			$params[':district_id'] = (int)$district_id;
		}
		$stmt = $this->db->prepare("SELECT name, id, district_id FROM fm_part_of_town $filter ORDER BY name");
		$stmt->execute($params);

		while ($row = $stmt->fetch(\PDO::FETCH_ASSOC))
		{
			$part_of_town[] = array(
				'id' => $row['id'],
				'name' => $this->dbStrip($row['name']),
				'district_id' => $row['district_id']
			);
		}

		return $part_of_town;
	}

	public function selectDistrictList()
	{
		$stmt = $this->db->prepare("SELECT id, descr FROM fm_district WHERE id > :min_id ORDER BY id");
		$stmt->execute([':min_id' => 0]);

		$district = array();
		$i = 0;
		while ($row = $stmt->fetch(\PDO::FETCH_ASSOC))
		{
			$district[$i]['id'] = $row['id'];
			$district[$i]['name'] = $this->dbStrip($row['descr']);
			$i++;
		}

		return $district;
	}

	public function getLookupEntity($location)
	{
		$sql = "SELECT entity_id, name FROM fm_entity_lookup {$this->join} fm_entity on fm_entity_lookup.entity_id = fm_entity.id"
			. ' WHERE type = :type AND location = :location';
		$stmt = $this->db->prepare($sql);
		$stmt->execute([
			':type' => 'lookup',
			':location' => $location,
		]);
		$entity = array();
		while ($row = $stmt->fetch(\PDO::FETCH_ASSOC))
		{
			$entity[] = array(
				'id' => $row['entity_id'],
				'name' => $this->dbStrip($row['name'])
			);
		}
		return $entity;
	}

	public function getStartEntity($location)
	{
		$sql = "SELECT entity_id, name FROM fm_entity_lookup {$this->join} fm_entity on fm_entity_lookup.entity_id = fm_entity.id"
			. ' WHERE type = :type AND location = :location';
			$stmt = $this->db->prepare($sql);
		$stmt->execute([
			':type' => 'start',
			':location' => $location,
		]);

		$entity = array();
		while ($row = $stmt->fetch(\PDO::FETCH_ASSOC))
		{
			$entity[] = array(
				'id' => $row['entity_id'],
				'name' => $this->dbStrip($row['name'])
			);
		}
		return $entity;
	}

	public function getMaxLocationLevel()
	{
		$this->db->query("SELECT count(*) as level FROM fm_location_type ");
		$this->db->next_record();
		return $this->db->f('level');
	}

	public function getLocationList($required)
	{
		$acl = Acl::getInstance();
		$access_list = $acl->get_location_list('property', $required);

		$needle = ".location.1.";
		$needle_len = strlen($needle);
		$access_location = array();
		foreach ($access_list as $location)
		{
			if (strrpos($location, $needle) === 0)
			{
				$target_len = strlen($location) - $needle_len;
				$access_location[] = substr($location, -$target_len);
			}
		}
		return $access_location;
	}

	public function getOrderType($id)
	{
		$id = (int)$id;
		$stmt = $this->db->prepare('SELECT type, secret FROM fm_orders WHERE id = :id');
		$stmt->execute([':id' => $id]);
		$row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
		return array(
			'type' => $row['type'] ?? '',
			'secret' => $row['secret'] ?? ''
		);
	}

	public function fmCache($name = '', $value = '')
	{
		if ($name && $value)
		{
			$value = serialize($value);

			if (function_exists('gzcompress'))
			{
				$value = base64_encode(gzcompress($value, 9));
			}
			else
			{
				$value = $this->db->db_addslashes($value);
			}

			$stmt = $this->db->prepare('SELECT value FROM fm_cache WHERE name = :name');
			$stmt->execute([':name' => $name]);

			if ($stmt->fetch(\PDO::FETCH_ASSOC))
			{
				$update = $this->db->prepare('UPDATE fm_cache SET value = :value WHERE name = :name');
				$update->execute([':value' => $value, ':name' => $name]);
			}
			else
			{
				$insert = $this->db->prepare('INSERT INTO fm_cache (name, value) VALUES (:name, :value)');
				$insert->execute([':name' => $name, ':value' => $value]);
			}
		}
		else
		{
			$stmt = $this->db->prepare('SELECT value FROM fm_cache WHERE name = :name');
			$stmt->execute([':name' => $name]);
			if ($row = $stmt->fetch(\PDO::FETCH_ASSOC))
			{
				$ret = $row['value'];

				if (function_exists('gzcompress'))
				{
					$ret = gzuncompress(base64_decode($ret));
				}
				else
				{
					$ret = stripslashes($ret);
				}

				return unserialize($ret);
			}
		}
	}

	public function resetFmCache()
	{
		$this->db->query("DELETE FROM fm_cache ", __LINE__, __FILE__);
	}

	public function resetFmCacheUserlist($like)
	{
		$this->db->query("DELETE FROM fm_cache WHERE name {$like} 'acl_userlist_%'", __LINE__, __FILE__, true);
		return $this->db->affected_rows();
	}

	public function checkLocation($location_code = '', $type_id = '')
	{
		$stmt = $this->db->prepare("SELECT count(*) as cnt FROM fm_location{$type_id} WHERE location_code = :location_code");
		$stmt->execute([':location_code' => $location_code]);
		$row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

		if (!empty($row['cnt']))
		{
			return true;
		}
	}

	public function nextId($table = '', $key = '')
	{
		$where = '';
		$params = [];
		if (is_array($key))
		{
			$condition = array();
			foreach ($key as $column => $value)
			{
				if ($value)
				{
					$placeholder = ':' . $column;
					$condition[] = $column . ' = ' . $placeholder;
					$params[$placeholder] = $value;
				}
			}

			$where = 'WHERE ' . implode(' AND ', $condition);
		}

		$stmt = $this->db->prepare("SELECT max(id) as maximum FROM $table $where");
		$stmt->execute($params);
		$row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
		$next_id = (int)($row['maximum'] ?? 0) + 1;
		return $next_id;
	}

	public function incrementId($name)
	{
		if (!$name)
		{
			throw new \Exception("property_socommon::increment_id() - Missing name");
		}

		if ($name == 'order')
		{
			$name = 'workorder';
		}
		else if ($name == 'helpdesk')
		{
			$name = 'workorder';
		}

		$stmt = $this->db->prepare('SELECT name FROM fm_idgenerator WHERE name = :name');
		$stmt->execute([':name' => $name]);
		if (!$stmt->fetch(\PDO::FETCH_ASSOC))
		{
			throw new \Exception("property_socommon::increment_id() - not a valid name: '{$name}'");
		}

		$now = time();
		$stmt = $this->db->prepare('SELECT value, start_date FROM fm_idgenerator WHERE name = :name AND start_date < :now ORDER BY start_date DESC');
		$stmt->execute([':name' => $name, ':now' => $now]);
		$row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
		$next_id = ((int)($row['value'] ?? 0)) + 1;
		$start_date = (int)($row['start_date'] ?? 0);
		$update = $this->db->prepare('UPDATE fm_idgenerator SET value = :value WHERE name = :name AND start_date = :start_date');
		$update->execute([':value' => $next_id, ':name' => $name, ':start_date' => $start_date]);
		return $next_id;
	}

	public function newDb($db = null)
	{
		if (is_object($db))
		{
			return new Db2();
		}
		else
		{
			return Db::getInstance();
		}
	}
}
