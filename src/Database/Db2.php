<?php

namespace App\Database;

use App\Database\Db;
use PDO;
use Exception;
use PDOException;

/**
 * Class Db2  - A non-singleton version of the Db class
 * @package App\Database
 */
class Db2 extends Db
{
	static $db2;
	private $config;

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

		if (is_null(self::$db2) || !is_null($dsn))
		{
			try
			{
				self::$db2 = new PDO($dsn2, $username, $password, $options);
			}
			catch (PDOException $e)
			{
				throw new Exception($e->getMessage());
			}
		}

		$this->db = self::$db2;

		$this->set_config($config);
		//not the paren, just this one
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

	public function metadata($table, $config = null)
	{
		return parent::metadata($table, $this->config);
	}

}
