<?php

namespace App\modules\phpgwapi\services;

use PDO;
use App\helpers\DebugArray;
use Exception;

class Settings
{
	private static $instance;
	private $settings;
	private $db;
	private $config_data = [];
	private $account_id;

	private function __construct($account_id = null)
	{
		$this->db = \App\Database\Db::getInstance();
		$this->account_id = $account_id;
		// Load settings from the database
		$this->settings = $this->loadSettingsFromDatabase();
		if ($account_id)
		{
			$this->set('account_id', $account_id);
		}
	}

	public static function getInstance()
	{
		if (null === static::$instance)
		{
			static::$instance = new static();
		}

		return static::$instance;
	}

	private function loadSettingsFromDatabase()
	{
		return $this->read_repository();
	}

	private function read_repository()
	{
		$modules = array('phpgwapi', 'admin');

		static $data_cache = array();

		if (!empty($data_cache[$modules[0]]))
		{
			$this->config_data = $data_cache[$modules[0]];
			return $this->config_data;
		}

		$rootDir = dirname(__DIR__, 4);

		if (!is_file($rootDir . '/config/header.inc.php'))
		{
			define('PHPGW_SERVER_ROOT', dirname(__DIR__, 2));
			define('PHPGW_INCLUDE_ROOT', PHPGW_SERVER_ROOT);
			define('PHPGW_MODULES_PATH', '/src/modules'); //Internal structure of the modules directory

			$this->config_data['server']['default_lang'] = 'en';
			$this->config_data['server']['isConnected'] = $this->db->isConnected();
			return $this->config_data;
		}

		$settings = require $rootDir . '/config/header.inc.php';
		$this->config_data = array_merge($this->config_data, $settings['phpgw_info']);
		$this->config_data['external_db'] = $settings['external_db'];

		//$this->config_data  = require_once SRC_ROOT_PATH . '/../config/config.php';
		require_once(SRC_ROOT_PATH . '/modules/phpgwapi/setup/setup.inc.php');

		$this->config_data['server']['versions'] =  $setup_info['phpgwapi']['versions'];


		$this->config_data['server']['db_type'] = $this->db->get_config()['db_type']??'';
		$this->config_data['server']['isConnected'] = $this->db->isConnected();

		//		_debug_array($this->config_data);die();

		/**
		 * check if the config table phpgw_config exists
		 */
		$tables = $this->db->table_names();
		if (in_array('phpgw_config', $tables))
		{
			$placeholders = implode(',', array_fill(0, count($modules), '?'));
			$sql = "SELECT * FROM phpgw_config WHERE config_app IN ($placeholders)";
			$stmt = $this->db->prepare($sql);
			$stmt->execute($modules);

			while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
			{
				$test = @unserialize($row['config_value']);
				if ($test)
				{
					$this->config_data['server'][$row['config_name']] = $test;
				}
				else
				{
					$this->config_data['server'][$row['config_name']] = $row['config_value'];
				}
			}
		}

		//check if the temp_dir is set and is writable
		if ($modules[0] == 'phpgwapi' && (empty($this->config_data['server']['temp_dir']) || !is_writable($this->config_data['server']['temp_dir'])))
		{
			$this->config_data['server']['temp_dir'] = '/tmp';
		}

		if ($modules[0] == 'phpgwapi' && isset($this->config_data['server']['versions']['header']))
		{
			if ($this->config_data['server']['versions']['header'] < $setup_info['phpgwapi']['versions']['current_header'])
			{
				//check in "/setup" is part of php_self
				if (!preg_match('/\/setup/', $_SERVER['REDIRECT_URL']))
				{
					$msg =  "You need to port your settings to the new header.inc.php version. <a href='/setup'>Run setup now</a>";
					echo "<div style='background-color: #FF0000; color: #FFFFFF; padding: 5px; text-align: center; font-weight: bold;'>$msg</div>";
					exit;
				}
			}
		}

		$this->config_data['server']['default_domain'] = $this->db->get_domain();

		if (!isset($this->config_data['server']['webserver_url']))
		{
			$this->config_data['server']['webserver_url'] = '';
		}
		$data_cache[$modules[0]] = $this->config_data;
		//	DebugArray::debug($this->config_data);

		return $this->config_data;
	}

	public function get_config_data()
	{
		return $this->config_data;
	}

	public function setAccountId($account_id)
	{
		$this->account_id = $account_id;
		$this->set('account_id', $account_id);
		$this->settings = array_merge($this->settings, array('user' => array('account_id' => $account_id)));
	}


	public function set($name, $value)
	{
		$this->settings = array_merge($this->settings, array($name => $value));
	}

	public function update($name, $data_set)
	{
		if (!is_array($data_set))
		{
			throw new Exception('Data set must be an array');
		}
		if (!isset($this->settings[$name]))
		{
			$this->settings[$name] = [];
		}
		$this->settings[$name] = array_merge($this->settings[$name], $data_set);
	}

	public function get($name)
	{
		return $this->settings[$name] ?? null;
	}
}
