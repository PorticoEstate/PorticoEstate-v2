<?php

use App\Database\Db;
use App\Database\Db2;
use App\modules\phpgwapi\services\Settings;

abstract class rental_socommon
{

	protected $db;
	protected $like;
	protected $join;
	protected $left_join;
	protected $sort_field;
	protected $skip_limit_query;
	protected $serverSettings;
	protected $userSettings;
	protected $flags;
	protected $phpgwapi_common;


	public function __construct()
	{
		$this->db = clone(Db::getInstance());
		$this->like = $this->db->like;
		$this->join = $this->db->join;
		$this->left_join = $this->db->left_join;
		$this->sort_field = null;
		$this->skip_limit_query = null;
		$this->serverSettings = Settings::getInstance()->get('server');
		$this->userSettings = Settings::getInstance()->get('user');
		$this->flags = Settings::getInstance()->get('flags');
		$this->phpgwapi_common = new \phpgwapi_common();

	}



	/**
	 * Copied from property_socommon, needed to get inside the transaction due to cloned db-object
	 * @param string $name name of id to increment
	 * @return integer next id
	 * @throws Exception
	 */
	public function increment_id($name)
	{
		if (!$name)
		{
			throw new Exception("rental_socommon::increment_id() - Missing name");
		}

		if ($name == 'order') // FIXME: temporary hack
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
			throw new Exception("rental_socommon::increment_id() - not a valid name: '{$name}'");
		}

		$now = time();
		$this->db->query("SELECT value, start_date FROM fm_idgenerator WHERE name='{$name}' AND start_date < {$now} ORDER BY start_date DESC");
		$this->db->next_record();
		$next_id = $this->db->f('value') + 1;
		$start_date = (int)$this->db->f('start_date');
		$this->db->query("UPDATE fm_idgenerator SET value = $next_id WHERE name = '{$name}' AND start_date = {$start_date}");
		return $next_id;
	}

	/**
	 * Begin transaction
	 *
	 * @return integer|bool current transaction id
	 */
	public function transaction_begin()
	{
		return $this->db->transaction_begin();
	}

	/**
	 * Complete the transaction
	 *
	 * @return bool True if sucessful, False if fails
	 */
	public function transaction_commit()
	{
		return $this->db->transaction_commit();
	}

	/**
	 * Rollback the current transaction
	 *
	 * @return bool True if sucessful, False if fails
	 */
	public function transaction_abort()
	{
		return $this->db->transaction_abort();
	}

	/**
	 * Marshal values according to type
	 * @param $value the value
	 * @param $type the type of value
	 * @return mixed database value
	 */
	protected function marshal($value, $type)
	{
		if ($value === null)
		{
			return 'NULL';
		}
		else if ($type == 'int')
		{
			if ($value == '')
			{
				return 'NULL';
			}
			return intval($value);
		}
		else if ($type == 'float')
		{
			return str_replace(',', '.', $value);
		}
		else if ($type == 'field')
		{
			return $this->db->db_addslashes($value);
		}
		return "'" . $this->db->db_addslashes($value) . "'";
	}

	/**
	 * Unmarchal database values according to type
	 * @param $value the field value
	 * @param $type	a string dictating value type
	 * @return mixed $value
	 */
	protected function unmarshal($value, $type)
	{
		if ($type == 'bool')
		{
			return (bool)$value;
		}
		elseif ($type == 'int')
		{
			return (int)$value;
		}
		elseif ($value === null || $value == 'NULL')
		{
			return null;
		}
		elseif ($type == 'float')
		{
			return floatval($value);
		}
		elseif ($type == 'string')
		{
			return $this->db->stripslashes($value);
		}
		return $value;
	}

	/**
	 * Get the count of the specified query. Query must return a signel column
	 * called count.
	 *
	 * @param $sql the sql query
	 * @return the count value
	 */
	protected function get_query_count($sql)
	{
		$result = $this->db->query($sql);
		if ($result && $this->db->next_record())
		{
			return $this->unmarshal($this->db->f('count', true), 'int');
		}
	}

	/**
	 * Implementing classes must return an instance of itself.
	 *  
	 * @return the class instance.
	 */
	public abstract static function get_instance();

	/**
	 * Convenience method for getting one single object. Calls get() with the
	 * specified id as a filter.
	 *
	 * @param $id int with id of object to return.
	 * @return object with the specified id, null if not found.
	 */
	public function get_single(int $id)
	{
		$objects = $this->get(0, 0, '', false, '', '', array($this->get_id_field_name() => $id));
		if (count($objects) > 0)
		{
			$keys = array_keys($objects);
			return $objects[$keys[0]];
		}
		return null;
	}

	/**
	 * Method for retrieving the db-object (security "forgotten")
	 */
	public function get_db()
	{
		return $this->db;
	}

	/**
	 * Method for retreiving objects.
	 *
	 * @param $start_index int with index of first object.
	 * @param $num_of_objects int with max number of objects to return.
	 * @param $sort_field string representing the object field to sort on.
	 * @param $ascending bool true for ascending sort on sort field, false
	 * for descending.
	 * @param $search_for string with free text search query.
	 * @param $search_type string with the query type.
	 * @param $filters array with key => value of filters.
	 * @return array of objects. May return an empty
	 * array, never null. The array keys are the respective index numbers.
	 */
	public function get(int $start_index, int $num_of_objects, string $sort_field, bool $ascending, string $search_for, string $search_type, array $filters)
	{
		$results = array();   // Array to store result objects
		$map = array(); // Array to hold number of records per target object
		$check_map = array();  // Array to hold the actual number of record read per target object
		$object_ids = array();   // All of the object ids encountered
		$added_object_ids = array(); // All of the added objects ids
		// Retrieve information about the table name and the name and alias of id column
		// $break_on_limit - 	flag indicating whether to break the loop when the number of records
		// 						for all the result objects are traversed
		$id_field_name_info = $this->get_id_field_name(true);
		if (is_array($id_field_name_info))
		{
			$break_on_limit = true;
			$id_field_name = $id_field_name_info['translated'];
		}
		else
		{
			$break_on_limit = false;
			$id_field_name = $id_field_name_info;
		}

		// Special case: Sort on id field. Always changed to the id field name.
		// $break_when_num_of_objects_reached - flag indicating to break the loop when the number of
		//		results are reached and we are sure that the records are ordered by the id
		if ($sort_field == null || $sort_field == 'id' || $sort_field == '')
		{
			$sort_field = $id_field_name;
			$break_when_num_of_objects_reached = true;
		}
		else
		{
			$break_when_num_of_objects_reached = false;
		}

		// Only allow positive start index
		if ($start_index < 0)
		{
			$start_index = 0;
		}


		// test-input for break on ordered queries
		$db2 = new Db2();

		$sql = $this->get_query($sort_field, $ascending, $search_for, $search_type, $filters, false);
		$sql_parts = explode('1=1', $sql); // Split the query to insert extra condition on test for break

		/**
		 * Sigurd: try to limit the candidates to a minimum
		 */
		$bypass_offset_check = false;
		if (!$this->skip_limit_query && $num_of_objects && is_array($id_field_name_info) && $id_field_name_info['translated'])
		{
			$bypass_offset_check = true;
			$sql_parts_filter = explode('FROM', $sql, 2);

			$sql_filter = "SELECT DISTINCT {$id_field_name_info['table']}.{$id_field_name_info['field']} AS {$id_field_name_info['translated']}";

			if ($this->sort_field)
			{
				if (is_array($this->sort_field))
				{
					$_sort_field = implode(',', $this->sort_field);
				}
				else
				{
					$_sort_field = $this->sort_field;
				}
			}
			else
			{
				$_sort_field = $sort_field;
			}

			if ($_sort_field && $_sort_field != $id_field_name_info['translated'])
			{
				$sql_filter .= ",{$_sort_field}";
			}

			$sql_filter .= " FROM {$sql_parts_filter[1]}";

			$this->db->limit_query($sql_filter, $start_index, __LINE__, __FILE__, (int)$num_of_objects);
			$ids = array();
			while ($this->db->next_record())
			{
				$ids[] = $this->db->f($id_field_name_info['translated']);
			}

			if ($ids)
			{
				$id_filter = "{$id_field_name_info['table']}.{$id_field_name_info['field']} IN(" . implode(',', $ids) . ') ';
				$sql = str_replace('1=1', $id_filter, $sql);
			}
		}

		$this->db->query($sql, __LINE__, __FILE__, false, true);

		while ($this->db->next_record()) // Runs through all of the results
		{
			$should_populate_object = false; // Default value - we won't populate object
			$result_id = $this->unmarshal($this->db->f($id_field_name), 'int'); // The id of object
			if (in_array($result_id, $added_object_ids)) // Object with this id already added
			{
				$should_populate_object = true; // We should populate this object as we already have it in our result array
			}
			else // Object isn't already added to array
			{
				if (!in_array($result_id, $object_ids)) // Haven't already added this id
				{
					$object_ids[] = $result_id; // We have to add the new id
				}
				// We have to check if we should populate this object
				if ($bypass_offset_check || (count($object_ids) > $start_index)) // We're at index above start index
				{
					if ($num_of_objects == null || count($results) < $num_of_objects) // We haven't found all the objects we're looking for
					{
						$should_populate_object = true; // We should populate this object
						$added_object_ids[] = $result_id; // We keep the id
					}
				}
			}
			if ($should_populate_object)
			{
				$result = &$results[$result_id];
				$results[$result_id] = $this->populate($result_id, $result);
				$last_result_id = $result_id;
				$map[$result_id] = (int)$map[$result_id] + 1;
			}

			//Stop looping when array not sorted on other then id and wanted number of results is reached
			if (count($results) == $num_of_objects && $last_result_id != $result_id && $break_when_num_of_objects_reached)
			{
				break;
			}
			// else stop looping when wanted number of results is reached all records for result objects are read
			else if ($break_on_limit && (count($results) == $num_of_objects) && $last_result_id != $result_id)
			{
				$id_ok = 0;
				foreach ($map as $_result_id => $_count)
				{
					if (!isset($check_map[$_result_id]))
					{
						// Query the number of records for the specific object in question
						$sql2 = "{$sql_parts[0]} 1=1 AND {$id_field_name_info['table']}.{$id_field_name_info['field']} = {$_result_id} {$sql_parts[1]}";
						$db2->query($sql2, __LINE__, __FILE__);
						$db2->next_record();
						$check_map[$_result_id] = $db2->num_rows();
					}
					if ($check_map[$_result_id] == $_count)
					{
						$id_ok++;
					}
				}
				if ($id_ok == $num_of_objects)
				{
					break;
				}
			}
		}

		$this->db->set_fetch_single(false);

		return $results;
	}

	/**
	 * Returns count of matching objects.
	 *
	 * @param $search_for string with free text search query.
	 * @param $search_type string with the query type.
	 * @param $filters array with key => value of filters.
	 * @return int with object count.
	 */
	public function get_count(string $search_for, string $search_type, array $filters)
	{
		return $this->get_query_count($this->get_query('', false, $search_for, $search_type, $filters, true));
	}

	/**
	 * Implementing classes must return the name of the field used in the query
	 * returned from get_query().
	 * 
	 * @return string with name of id field.
	 */
	protected abstract function get_id_field_name();

	/**
	 * Returns SQL for retrieving matching objects or object count.
	 *
	 * @param $start_index int with index of first object.
	 * @param $num_of_objects int with max number of objects to return.
	 * @param $sort_field string representing the object field to sort on.
	 * @param $ascending bool true for ascending sort on sort field, false
	 * for descending.
	 * @param $search_for string with free text search query.
	 * @param $search_type string with the query type.
	 * @param $filters array with key => value of filters.
	 * @param $return_count bool telling to return only the count of the
	 * matching objects, or the objects themself.
	 * @return string with SQL.
	 */
	protected abstract function get_query(string $sort_field, bool $ascending, string $search_for, string $search_type, array $filters, bool $return_count);

	protected abstract function populate(int $object_id, &$object);

	protected abstract function add(&$object);

	protected abstract function update($object);

	/**
	 * Store the object in the database.  If the object has no ID it is assumed to be new and
	 * inserted for the first time.  The object is then updated with the new insert id.
	 */
	public function store(&$object)
	{
		if ($object->validates())
		{
			if ($object->get_id() > 0)
			{
				// We can assume this composite came from the database since it has an ID. Update the existing row
				return $this->update($object);
			}
			else
			{
				// This object does not have an ID, so will be saved as a new DB row
				return $this->add($object);
			}
		}

		// The object did not validate
		return false;
	}
}
