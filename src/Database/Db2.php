<?php

namespace App\Database;

use App\Database\Db;
use PDO;
use Exception;
use PDOException;
use App\Database\FakePDOOCI;

/**
 * Class Db2  - A non-singleton version of the Db class
 * @package App\Database
 */
class Db2 extends Db
{
	static $db2;
	private $config;
	private $db;

	/**
	 * Db2 constructor.
	 * @param string $dsn - Data Source Name, if given, the database connection will not be reused, typical use is integration with external systems
	 * @param string $username
	 * @param string $password
	 * @param array $options
	 * @throws Exception
	 */
	function __construct($dsn = null, $username = null, $password = null, $options = null)
	{
		$config = Db::getInstance()->get_config();
		if (is_null($dsn))
		{
			$dsn2 = parent::CreateDsn($config);
			$username = $config['db_user'];
			$password = $config['db_pass'];
			$options = [
				PDO::ATTR_PERSISTENT => true, // Enable persistent connections
				PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_EMULATE_PREPARES => false,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
			];
		}
		else
		{
			$dsn2 = $dsn;
		}

		// Add TrustServerCertificate=yes to the DSN
		if (strpos($dsn2, 'sqlsrv') !== false)
		{
			$dsn2 .= ';TrustServerCertificate=yes';
		}

		$db = null;
		if (is_null(self::$db2) || !is_null($dsn))
		{
			try
			{
				$db = new PDO($dsn2, $username, $password, $options);
			}
			catch (PDOException $e)
			{
				//check 'oci' in dsn
				if (strpos($dsn2, 'oci') !== false)
				{
					// try to connect with FakePDOOCI
					if (!$db = new FakePDOOCI($dsn2, $username, $password))
					{
						throw new Exception('Error: ' . $e->getMessage());
					}
				}
				else
				{
					throw new Exception($e->getMessage());
				}
			}
		}

		if (is_null($dsn) && !is_null($db))
		{
			self::$db2 = $db;
		}

		if (!is_null($db))
		{
			$this->db = $db;
		}
		else
		{
			$this->db = self::$db2;
		}


		$this->set_config($config);
		//		register_shutdown_function(array(&$this, 'disconnect'));
	}

	public function set_config($config)
	{
		$this->config = $config;
	}

	public function get_config()
	{
		return $this->config;
	}

	public function disconnect()
	{
		self::$db2 = null;
	}


	// Replicated functions from Db that refer to $this->db

	public function __call($method, $arguments)
	{
		return call_user_func_array(array($this->db, $method), $arguments);
	}

	public function transaction_begin()
	{
		if (!$this->isTransactionActive)
		{
			if ($this->config['db_type'] == 'mysql')
			{
				$this->db->setAttribute(PDO::ATTR_AUTOCOMMIT, 1);
				$this->db->setAttribute(PDO::ATTR_AUTOCOMMIT, 0);
			}
			$this->db->beginTransaction();
			$this->isTransactionActive = true;
		}
	}

	public function transaction_commit()
	{
		if ($this->isTransactionActive)
		{
			$commitSuccess = $this->db->commit();
			if ($this->config['db_type'] == 'mysql')
			{
				$this->db->setAttribute(PDO::ATTR_AUTOCOMMIT, 1);
			}

			$this->isTransactionActive = false;
			return $commitSuccess;
		}
		return false;
	}

	public function transaction_abort()
	{
		$ret = false;

		if ($this->isTransactionActive)
		{
			$this->isTransactionActive = false;

			try
			{
				$ret = $this->db->rollBack();
			}
			catch (PDOException $e)
			{
				if ($e)
				{
					trigger_error('Error: ' . $e->getMessage(), E_USER_ERROR);
				}
			}
		}
		return $ret;
	}

	public function get_last_insert_id($table, $field = '')
	{
		switch ($this->config['db_type'])
		{
			case 'postgres':
				$sequence = $this->_get_sequence_field_for_table($table, $field);
				$ret = $this->db->lastInsertId($sequence);
				break;
			case 'sqlsrv':
			case 'mssqlnative':
			case 'mssql':
				$orig_fetchmode = $this->fetchmode;
				if ($this->fetchmode == 'ASSOC')
				{
					$this->fetchmode = 'BOTH';
				}
				$this->query("SELECT @@identity", __LINE__, __FILE__);
				$this->next_record();
				$ret = $this->f(0);
				$this->fetchmode = $orig_fetchmode;
				break;
			default:
				$ret = $this->db->lastInsertId();
		}

		if ($ret)
		{
			return $ret;
		}
		return -1;
	}

	protected function _get_sequence_field_for_table($table, $field = '')
	{
		$sql = "SELECT relname FROM pg_class WHERE NOT relname ~ 'pg_.*'"
			. " AND relname = '{$table}_{$field}_seq' AND relkind='S' ORDER BY relname";
		$this->query($sql, __LINE__, __FILE__);
		if ($this->next_record())
		{
			return $this->f('relname');
		}
		$sql = "SELECT relname FROM pg_class WHERE NOT relname ~ 'pg_.*'"
			. " AND relname = 'seq_{$table}' AND relkind='S' ORDER BY relname";
		$this->query($sql, __LINE__, __FILE__);
		if ($this->next_record())
		{
			return $this->f('relname');
		}
		return false;
	}

	public function db_addslashes($string)
	{
		if ($string === '' || $string === null)
		{
			return '';
		}
		return substr($this->db->quote($string), 1, -1);
	}

	public function insert($sql, $valueset, $line = '', $file = '')
	{
		try
		{
			$statement_object = $this->db->prepare($sql);
			foreach ($valueset as $fields)
			{
				foreach ($fields as $field => $entry)
				{
					$statement_object->bindParam($field, $entry['value'], $entry['type']);
				}
				$ret = $statement_object->execute();
			}
		}
		catch (\PDOException $e)
		{
			trigger_error('Error: ' . $e->getMessage() . "<br>SQL: $sql\n in File: $file\n on Line: $line\n", E_USER_ERROR);
		}
		return $ret;
	}

	public function delete($sql, $valueset, $line = '', $file = '')
	{
		try
		{
			$statement_object = $this->db->prepare($sql);
			foreach ($valueset as $fields)
			{
				foreach ($fields as $field => $entry)
				{
					$statement_object->bindParam($field, $entry['value'], $entry['type']);
				}
				$ret = $statement_object->execute();
			}
		}
		catch (\PDOException $e)
		{
			trigger_error('Error: ' . $e->getMessage() . "<br>SQL: $sql\n in File: $file\n on Line: $line\n", E_USER_ERROR);
		}
		return $ret;
	}

	public function metadata($table, $config = null)
	{
		$config = $config ? $config : $this->config;
		$db_type = $config['db_type'];

		switch ($db_type)
		{
			case 'pgsql':
			case 'postgres':
				$stmt = $this->db->prepare("
					SELECT a.column_name, a.data_type AS type, 
						   CASE 
							   WHEN a.data_type = 'smallint' THEN 2
							   WHEN a.data_type = 'integer' THEN 4
							   WHEN a.data_type = 'bigint' THEN 8
							   ELSE a.character_maximum_length 
						   END as max_length, 
						   CASE WHEN a.is_nullable = 'NO' THEN true ELSE false END AS not_null,
						   CASE WHEN pk.column_name IS NULL THEN false ELSE true END AS primary_key,
						   CASE WHEN uk.column_name IS NULL THEN false ELSE true END AS unique_key,
						   CASE WHEN a.column_default IS NOT NULL THEN true ELSE false END AS has_default,
						   a.column_default AS default_value
					FROM information_schema.columns a
					LEFT JOIN (
						SELECT ku.table_schema, ku.table_name, ku.column_name
						FROM information_schema.table_constraints tc
						JOIN information_schema.key_column_usage ku
							ON tc.constraint_name = ku.constraint_name
							AND tc.table_schema = ku.table_schema
							AND tc.table_name = ku.table_name
						WHERE tc.constraint_type = 'PRIMARY KEY'
					) pk
					ON a.table_schema = pk.table_schema
					AND a.table_name = pk.table_name
					AND a.column_name = pk.column_name
					LEFT JOIN (
						SELECT ku.table_schema, ku.table_name, ku.column_name
						FROM information_schema.table_constraints tc
						JOIN information_schema.key_column_usage ku
							ON tc.constraint_name = ku.constraint_name
							AND tc.table_schema = ku.table_schema
							AND tc.table_name = ku.table_name
						WHERE tc.constraint_type = 'UNIQUE'
					) uk
					ON a.table_schema = uk.table_schema
					AND a.table_name = uk.table_name
					AND a.column_name = uk.column_name
					WHERE a.table_schema = 'public'
					AND a.table_name = :table");
				$stmt->execute(['table' => $table]);
				$meta = $stmt->fetchAll(PDO::FETCH_ASSOC);
				break;
			case 'mysql':
				$stmt = $this->db->prepare("
					SELECT COLUMN_NAME, DATA_TYPE AS type, CHARACTER_MAXIMUM_LENGTH AS MAX_LENGTH, 
						   COLUMN_KEY='PRI' as primary_key
					FROM INFORMATION_SCHEMA.COLUMNS 
					WHERE TABLE_NAME = :table
				");
				$stmt->execute(['table' => $table]);
				$meta = $stmt->fetchAll(PDO::FETCH_ASSOC);
				break;
			case 'mssql':
			case 'sqlsrv':
			case 'mssqlnative':
				$stmt = $this->db->prepare("
					SELECT column_name, DATA_TYPE AS type, CHARACTER_MAXIMUM_LENGTH AS max_length, 
						   CASE WHEN COLUMN_NAME IN (
							   SELECT KU.COLUMN_NAME 
							   FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS AS TC 
							   JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE AS KU 
							   ON TC.CONSTRAINT_TYPE = 'PRIMARY KEY' 
							   AND TC.CONSTRAINT_NAME = KU.CONSTRAINT_NAME 
							   WHERE KU.TABLE_NAME = :table1
						   ) THEN 1 ELSE 0 END as primary_key
					FROM INFORMATION_SCHEMA.COLUMNS 
					WHERE TABLE_NAME = :table2
				");
				$stmt->execute(['table1' => $table, 'table2' => $table]);
				$meta = $stmt->fetchAll(PDO::FETCH_ASSOC);
				break;
			case 'oci8':
				$stmt = $this->db->prepare("
					SELECT COLUMN_NAME, DATA_TYPE AS type, DATA_LENGTH AS MAX_LENGTH, 
						   COLUMN_NAME = (SELECT cols.column_name 
										  FROM all_constraints cons, all_cons_columns cols 
										  WHERE cols.table_name = :table 
										  AND cons.constraint_type = 'P' 
										  AND cons.constraint_name = cols.constraint_name 
										  AND cons.owner = cols.owner) as primary_key
					FROM ALL_TAB_COLUMNS 
					WHERE TABLE_NAME = :table
				");
				$stmt->execute(['table' => $table]);
				$meta = $stmt->fetchAll(PDO::FETCH_ASSOC);
				break;
			default:
				throw new Exception("Database type not supported");
		}
		// Convert the resulting array into an array of objects with column_name as the key
		$meta = array_reduce($meta, function ($carry, $item)
		{
			$carry[$item['column_name']] = (object) $item;
			return $carry;
		}, []);

		return $meta;
	}

	/**
	 * Finds the next ID for a record at a table
	 *
	 * @param string $table tablename in question
	 * @param array $key conditions
	 * @return int the next id
	 */

	public function next_id($table = '', $key = '', $min = 0, $max = 0)
	{

		$where = '';
		$condition = array();
		$params = array();
		if (is_array($key))
		{
			foreach ($key as $column => $value)
			{
				if ($value)
				{
					$condition[] = $column . " = :" . $column;
					$params[$column] = $value;
				}
			}

			if ($condition)
			{
				$where = 'WHERE ' . implode(' AND ', $condition);
			}
		}

		$stmt = $this->db->prepare("SELECT max(id) as maximum FROM $table $where");
		$stmt->execute($params);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		$next_id = (int)$row['maximum'] + 1;

		if ($table == 'phpgw_nextid')
		{
			$update = false;
			if ($next_id == 1)
			{
				$columns = implode(',', array_keys($params));
				$placeholders = implode(',', array_fill(0, count($params), '?'));
				$stmt = $this->db->prepare("INSERT INTO $table (id, $columns) VALUES (?, $placeholders)");
				$stmt->execute(array_merge([$next_id], array_values($params)));
			}
			else
			{
				$update = true;
			}

			//if the next id is less than the minimum id, set it to the minimum id
			if ($next_id < $min)
			{
				$next_id = $min;
			}
			if ($update)
			{
				// update the table {$table} SET id = {$next id} +1
				$stmt = $this->db->prepare("UPDATE $table SET id = id +1 $where");
				$stmt->execute($params);
			}
		}
		return $next_id;
	}

	/**
	 * Get a list of table names in the current database
	 * @param bool $include_views include views in the listing if any (optional)
	 *
	 * @return array list of the tables
	 */
	public function table_names($include_views = null)
	{
		$return = array();

		if (!isset($this->config['db_type']))
		{
			return $return;
		}

		$db_type = $this->config['db_type'];

		switch ($db_type)
		{
			case 'pgsql':
			case 'postgres':
				$stmt = $this->db->prepare("SELECT table_name as name, CAST(table_type = 'VIEW' AS INTEGER) as view
				FROM information_schema.tables
				WHERE table_schema = current_schema()");
				$stmt->execute();

				while ($entry = $stmt->fetch(PDO::FETCH_ASSOC))
				{
					if ($include_views)
					{
						$return[] =  $entry['name'];
					}
					else
					{
						if (!$entry['view'])
						{
							$return[] =  $entry['name'];
						}
					}
				}
				break;
			case 'mysql':

				$sql = "SHOW FULL TABLES";

				if (!$include_views)
				{
					$sql .= " WHERE Table_Type != 'VIEW'";
				}
				$stmt = $this->db->prepare($sql);
				$stmt->execute();
				//insert the result into the return array
				while ($entry = $stmt->fetch(PDO::FETCH_NUM))
				{
					$return[] = $entry[0];
				}

				break;
			case 'mssql':
			case 'sqlsrv':
			case 'mssqlnative':
				$stmt = $this->db->prepare("SELECT name FROM sysobjects WHERE xtype='U'");
				$stmt->execute();
				while ($entry = $stmt->fetch(PDO::FETCH_ASSOC))
				{
					$return[] = $entry['name'];
				}
				break;
			case 'oci8':
				$stmt = $this->db->prepare("SELECT TABLE_NAME as name, TABLE_TYPE as table_type FROM cat");
				$stmt->execute();
				while ($entry = $stmt->fetch(PDO::FETCH_ASSOC))
				{
					if ($include_views)
					{
						$return[] = $entry['name'];
					}
					else
					{
						if ($entry['table_type'] == 'TABLE')
						{
							$return[] = $entry['name'];
						}
					}
				}

				break;
			default:
				throw new Exception("Database type not supported");
		}

		return $return;
	}

	public function set_halt_on_error($halt_on_error = '')
	{

		switch ($halt_on_error)
		{
			case 'yes':
				$this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
				break;
			case 'report':
				$this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
				break;
			default:
				$this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
		}
	}

	/**
	 * Returns an associate array of foreign keys, or false if not supported.
	 *
	 * @param string $table name of table to describe
	 * @param boolean $primary optional, default False.
	 * @return array Index data
	 */
	public function metaindexes($table, $primary = false)
	{
		$db_type = $this->config['db_type'];

		switch ($db_type)
		{
			case 'pgsql':
			case 'postgres':
				$stmt = $this->db->prepare("SELECT
					c.relname as name,
					i.indisunique as unique,
					i.indisprimary as primary,
					pg_get_indexdef(i.indexrelid) as definition
				FROM
					pg_catalog.pg_class c,
					pg_catalog.pg_class c2,
					pg_catalog.pg_index i
				WHERE
					c.relname = :table
					and c.oid = i.indrelid
					and i.indexrelid = c2.oid
					and i.indisprimary = :primary");

				$primary = filter_var($primary, FILTER_VALIDATE_BOOLEAN);
				if ($primary)
				{
					$primary = 'true';
				}
				else
				{
					$primary = 'false';
				}

				$stmt->execute(['table' => $table, 'primary' => $primary]);
				$meta = $stmt->fetchAll(PDO::FETCH_ASSOC);
				break;
			default:
				throw new Exception("Database type not supported");
		}

		foreach ($meta as &$entry)
		{
			preg_match('/\((.*?)\)/', $entry['definition'], $matches);
			$columns = explode(',', $matches[1]);
			$entry['columns'] = array_map('trim', $columns);
		}

		return $meta;
	}

	/**
	 * Execute a query
	 *
	 * @param string $sql the query to be executed
	 * @param mixed $line the line method was called from - use __LINE__
	 * @param string $file the file method was called from - use __FILE__
	 * @param bool $exec true for exec, false for query
	 * @param bool $fetch_single true for using fetch, false for fetchAll
	 * @return integer current query id if sucesful and null if fails
	 */
	public function query($sql, $line = '', $file = '', $exec = false, $_fetch_single = false)
	{

		/**
		 * Note: JSONB operator '?' is troublesom: convert to '~@'
		 * CREATE OPERATOR ~@ (LEFTARG = jsonb, RIGHTARG = text, PROCEDURE = jsonb_exists);
		 * CREATE OPERATOR ~@| (LEFTARG = jsonb, RIGHTARG = text[], PROCEDURE = jsonb_exists_any);
		 * CREATE OPERATOR ~@& (LEFTARG = jsonb, RIGHTARG = text[], PROCEDURE = jsonb_exists_all);
		 */

		if (in_array($this->config['db_type'], array('mssql', 'mssqlnative', 'sqlsrv')))
		{
			if (preg_match('/(^SELECT)/i', $sql) && !preg_match('/TOP 100 PERCENT/i', $sql))
			{
				$sql = str_replace(array('SELECT', 'SELECT TOP 100 PERCENT DISTINCT'), array('SELECT TOP 100 PERCENT', 'SELECT DISTINCT TOP 100 PERCENT'), $sql);
			}
		}

		self::_get_fetchmode();
		self::set_fetch_single($_fetch_single);
		$exec = false;

		$fetch_single = $this->fetch_single;

		$fetch = true;
		if (preg_match('/(^INSERT INTO|^DELETE FROM|^CREATE|^DROP|^ALTER|^UPDATE|^SET)/i', $sql)) // need it for MySQL and Oracle
		{
			$fetch = false;
		}
		if (preg_match('/(^SET)/i', $sql)) // need it for MSSQL
		{
			$exec = true;
		}

		try
		{

			if ($exec)
			{
				$this->affected_rows = $this->db->exec($sql);
				return true;
			}
			else
			{
				if ($statement_object = $this->db->query($sql))
				{
					$this->affected_rows = $statement_object->rowCount();
				}
				if ($fetch)
				{
					if ($fetch_single)
					{
						$this->resultSet = $statement_object->fetch($this->pdo_fetchmode);
						$this->statement_object = $statement_object;
						unset($statement_object);
					}
					else
					{
						$this->resultSet = $statement_object->fetchAll($this->pdo_fetchmode);
					}
				}
			}
		}
		catch (PDOException $e)
		{
			if ($e && !$this->Exception_On_Error && $this->Halt_On_Error == 'yes')
			{
				$this->transaction_abort();

				if ($file)
				{
					$msg = "SQL: {$sql}<br/><br/> in File: $file<br/><br/> on Line: $line<br/><br/>";
					$msg .= 'Error: ' . ($e->getMessage());
					trigger_error($msg, E_USER_ERROR);
				}
				else
				{
					trigger_error("$sql\n" . $e->getMessage(), E_USER_ERROR);
				}
				exit;
			}
			else if ($this->Exception_On_Error && $this->Halt_On_Error == 'yes')
			{
				$this->transaction_abort();
				throw $e;
			}
			else if ($this->Exception_On_Error && $this->Halt_On_Error != 'yes')
			{
				throw $e;
			}
		}
		$this->delayPointer = true;
		return true;
	}


	/**
	 * Execute a query with limited result set
	 *
	 * @param string $sql the query to be executed
	 * @param integer $offset row to start from
	 * @param integer $line the line method was called from - use __LINE__
	 * @param string $file the file method was called from - use __FILE__
	 * @param integer $num_rows number of rows to return (optional), if unset will use $GLOBALS['phpgw_info']['user']['preferences']['common']['maxmatchs']
	 * @return integer current query id if sucesful and null if fails
	 */

	function limit_query($sql, $offset, $line = '', $file = '', $num_rows = 0)
	{
		//			self::sanitize($sql);//killing performance

		$this->_get_fetchmode();

		$sql = $this->get_offset($sql, $offset, $num_rows);

		if ($this->debug)
		{
			printf("Debug: limit_query = %s<br />offset=%d, num_rows=%d<br />\n", $sql, $offset, $num_rows);
		}

		try
		{
			$statement_object = $this->db->query($sql);
			$this->resultSet = $statement_object->fetchAll($this->pdo_fetchmode);
		}
		catch (PDOException $e)
		{
			if ($e && !$this->Exception_On_Error && $this->Halt_On_Error == 'yes')
			{
				$this->transaction_abort();

				if ($file)
				{
					trigger_error('Error: ' . $e->getMessage() . "<br>SQL: $sql\n in File: $file\n on Line: $line\n", E_USER_ERROR);
				}
				else
				{
					trigger_error("$sql\n" . $e->getMessage(), E_USER_ERROR);
				}
				exit;
			}
			else if ($this->Exception_On_Error && $this->Halt_On_Error == 'yes')
			{
				$this->transaction_abort();
				throw $e;
			}
			else if ($this->Exception_On_Error && $this->Halt_On_Error != 'yes')
			{
				throw $e;
			}
		}

		$this->delayPointer = true;
		return true;
	}


	public function next_record()
	{
		if ($this->fetch_single)
		{
			if ($this->delayPointer)
			{
				$this->delayPointer = false;
			}
			else
			{
				$this->resultSet = $this->statement_object->fetch($this->pdo_fetchmode);
			}
			$this->Record = $this->resultSet;
			return !!$this->Record;
		}
		if ($this->resultSet && current($this->resultSet))
		{
			if ($this->delayPointer)
			{
				$this->delayPointer = false;
				$this->Record = current($this->resultSet);
				return true;
			}

			$row = next($this->resultSet);
			$this->Record = &$row;
			return !!$row;
		}
		return false;
	}

	public function f($name, $strip_slashes = False)
	{
		if ($this->resultSet)
		{
			if (isset($this->Record[$name]))
			{
				if ($strip_slashes)
				{
					return $this->unmarshal($this->Record[$name], 'string');
				}
				else
				{
					return $this->Record[$name];
				}
			}
			return '';
		}
	}

	public function affected_rows()
	{
		return $this->affected_rows;
	}

	protected function _get_fetchmode()
	{
		if ($this->fetchmode == 'ASSOC')
		{
			$this->pdo_fetchmode = PDO::FETCH_ASSOC;
		}
		else
		{
			$this->pdo_fetchmode = PDO::FETCH_BOTH;
		}
	}

	public function set_fetch_single($value = false)
	{
		$this->fetch_single = $value;
	}

	public function validate_insert($values)
	{
		if (!is_array($values) || !count($values))
		{
			return '';
		}

		$insert_value = array();
		foreach ($values as $value)
		{
			if ($value && $this->isJson($value))
			{
				if ($this->config['db_type'] == 'mysql')
				{
					$insert_value[] = "'" . str_ireplace(array('\\'), array('\\\\'), $value) . "'";
				}
				else
				{
					$insert_value[]	= "'{$value}'";
				}
			}
			else if ($value || (is_numeric($value) && $value == 0))
			{
				if (is_numeric($value))
				{
					$insert_value[]	= "'{$value}'";
				}
				else
				{
					$insert_value[]	= "'" . $this->db_addslashes(stripslashes($value)) . "'";
				}
			}
			else
			{
				$insert_value[]	= 'NULL';
			}
		}
		return implode(",", $insert_value);
	}


	public function validate_update($value_set)
	{
		if (!is_array($value_set) || !count($value_set))
		{
			return '';
		}

		$value_entry = array();
		foreach ($value_set as $field => $value)
		{
			if ($value && $this->isJson($value))
			{
				$value_entry[] = "{$field}='{$value}'";
			}
			else if ($value || (is_numeric($value) && $value == 0))
			{
				if (is_numeric($value))
				{
					if ((strlen($value) > 1 && strpos($value, '0') === 0))
					{
						$value_entry[] = "{$field}='{$value}'";
					}
					else
					{
						$value_entry[] = "{$field}={$value}";
					}
				}
				else
				{
					$value_entry[] = "{$field}='" . $this->db_addslashes(stripslashes($value)) . "'";
				}
			}
			else
			{
				$value_entry[] = "{$field}=NULL";
			}
		}
		return implode(',', $value_entry);
	}

	public function last_id($table = '', $key = '', $min = 0, $max = 0)
	{
		if (!$key)
		{
			return -1;
		}

		$where = '';
		$condition = array();
		$params = array();
		if (is_array($key))
		{
			foreach ($key as $column => $value)
			{
				if ($value)
				{
					$condition[] = $column . " = :" . $column;
					$params[$column] = $value;
				}
			}

			if ($condition)
			{
				$where = 'WHERE ' . implode(' AND ', $condition);
			}
		}

		$stmt = $this->db->prepare("SELECT id FROM $table $where");
		$stmt->execute($params);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		$id = (int)$row['id'];

		return (int) $id;
	}

	public function num_rows()
	{
		if ($this->resultSet)
		{
			return count($this->resultSet);
		}
		return 0;
	}
}
