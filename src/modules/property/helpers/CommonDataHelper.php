<?php

namespace App\modules\property\helpers;

use App\Database\Db;
use App\Database\Db2;
use App\modules\phpgwapi\security\Acl;
use App\modules\phpgwapi\services\Settings;

class CommonDataHelper
{
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
			'first_name' => $this->db->f('first_name'),
			'last_name' => $this->db->f('last_name'),
			'contact_phone' => $this->db->f('contact_phone')
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
		$this->db->query("SELECT preference_json, preference_owner FROM phpgw_preferences where preference_app = '{$app}'"
			. " AND preference_owner IN (-1,-2," . (int)$user_id . ')', __LINE__, __FILE__);
		$forced = $default = $user = array();
		while ($this->db->next_record())
		{
			$value = json_decode($this->db->f('preference_json'), true);
			$this->unquote($value);
			if (!is_array($value))
			{
				continue;
			}
			switch ($this->db->f('preference_owner'))
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
		$part_of_town = array();
		if ($district_id)
		{
			$filter = 'WHERE district_id = ' . (int)$district_id;
		}
		$this->db->query("SELECT name, id, district_id FROM fm_part_of_town $filter ORDER BY name ", __LINE__, __FILE__);

		while ($this->db->next_record())
		{
			$part_of_town[] = array(
				'id' => $this->db->f('id'),
				'name' => $this->db->f('name', true),
				'district_id' => $this->db->f('district_id')
			);
		}

		return $part_of_town;
	}

	public function selectDistrictList()
	{
		$this->db->query("SELECT id, descr FROM fm_district where id >'0' ORDER BY id ");

		$district = array();
		$i = 0;
		while ($this->db->next_record())
		{
			$district[$i]['id'] = $this->db->f('id');
			$district[$i]['name'] = stripslashes($this->db->f('descr'));
			$i++;
		}

		return $district;
	}

	public function getLookupEntity($location)
	{
		$this->db->query("SELECT entity_id,name FROM fm_entity_lookup {$this->join} fm_entity on fm_entity_lookup.entity_id=fm_entity.id WHERE type='lookup' AND location='{$location}'  ");
		$entity = array();
		while ($this->db->next_record())
		{
			$entity[] = array(
				'id' => $this->db->f('entity_id'),
				'name' => $this->db->f('name', true)
			);
		}
		return $entity;
	}

	public function getStartEntity($location)
	{
		$this->db->query("SELECT entity_id,name FROM fm_entity_lookup {$this->join} fm_entity on fm_entity_lookup.entity_id=fm_entity.id WHERE type='start' AND location='{$location}'  ");

		$entity = array();
		while ($this->db->next_record())
		{
			$entity[] = array(
				'id' => $this->db->f('entity_id'),
				'name' => $this->db->f('name', true)
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
		$this->db->query("SELECT type, secret FROM fm_orders WHERE id={$id}", __LINE__, __FILE__);
		$this->db->next_record();
		return array(
			'type' => $this->db->f('type'),
			'secret' => $this->db->f('secret')
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

			$this->db->query("SELECT value FROM fm_cache WHERE name='{$name}'");

			if ($this->db->next_record())
			{
				$this->db->query("UPDATE fm_cache SET value = '{$value}' WHERE name='{$name}'", __LINE__, __FILE__);
			}
			else
			{
				$this->db->query("INSERT INTO fm_cache (name,value)VALUES ('$name','$value')", __LINE__, __FILE__);
			}
		}
		else
		{
			$this->db->query("SELECT value FROM fm_cache where name='$name'");
			if ($this->db->next_record())
			{
				$ret = $this->db->f('value');

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
		$this->db->query("SELECT count(*) as cnt FROM fm_location$type_id where location_code='$location_code'");
		$this->db->next_record();

		if ($this->db->f('cnt'))
		{
			return true;
		}
	}

	public function nextId($table = '', $key = '')
	{
		$where = '';
		if (is_array($key))
		{
			$condition = array();
			foreach ($key as $column => $value)
			{
				if ($value)
				{
					$condition[] = $column . "='" . $value;
				}
			}

			$where = 'WHERE ' . implode("' AND ", $condition) . "'";
		}

		$this->db->query("SELECT max(id) as maximum FROM $table $where", __LINE__, __FILE__);
		$this->db->next_record();
		$next_id = (int)$this->db->f('maximum') + 1;
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

		$this->db->query("SELECT name FROM fm_idgenerator WHERE name='{$name}'");
		$this->db->next_record();
		if (!$this->db->f('name'))
		{
			throw new \Exception("property_socommon::increment_id() - not a valid name: '{$name}'");
		}

		$now = time();
		$this->db->query("SELECT value, start_date FROM fm_idgenerator WHERE name='{$name}' AND start_date < {$now} ORDER BY start_date DESC");
		$this->db->next_record();
		$next_id = $this->db->f('value') + 1;
		$start_date = (int)$this->db->f('start_date');
		$this->db->query("UPDATE fm_idgenerator SET value = $next_id WHERE name = '{$name}' AND start_date = {$start_date}");
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
