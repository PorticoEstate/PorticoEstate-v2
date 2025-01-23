<?php

namespace App\modules\admin\controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

use App\modules\phpgwapi\security\Acl;
use App\modules\phpgwapi\services\Settings;
use Sanitizer;
use \Redis;
use Exception;

class RedisController
{
	private $phpgwapi_common;
	private $redis = null;
	private static $error_connect = null;
	private static $is_connected = null;
	private $serverSettings;

	public function __construct()
	{
		$this->serverSettings = Settings::getInstance()->get('server');
		require_once SRC_ROOT_PATH . '/helpers/LegacyObjectHandler.php';
		Settings::getInstance()->update('flags', ['menu_selection' => 'admin::admin::redis_monitor']);

		$acl = Acl::getInstance();

		$is_admin	 = $acl->check('run', Acl::READ, 'admin');

		if (!$is_admin)
		{
			\phpgw::no_access();
		}

		if (!empty($this->serverSettings['redis_enable']) && extension_loaded('redis'))
		{
			$this->connect();
		}
	}

	public function showRedisCache(Request $request, Response $response, array $args): Response
	{
		$phpgwapi_common = new \phpgwapi_common();
		$phpgwapi_common->phpgw_header(true);

		if (!$this->redis)
		{
			$response->getBody()->write('Redis is not enabled or not connected');
			return $response;
		}

		// Fetch memory usage
		$memoryUsage = $this->redis->info('memory')['used_memory'];

		// Fetch connected clients
		$clients = $this->redis->info('clients')['connected_clients'];


		//get the number of keys in the connected database
		$keys = $this->redis->dbSize();

		// Get the oldest key in the connected database
		$oldestKey = $this->getOldestKey();

		// Create the response body
		$responseBody = "Memory Usage: " . round($memoryUsage / (1024 * 1024), 1) . " M bytes<br>";
		$responseBody .= "Connected Clients: " . $clients;
		$responseBody .= "<br>Number of keys in the connected database: " . $keys;
		$responseBody .= "<br>Oldest key in the connected database: " . $oldestKey['key'];
		$responseBody .= "<br>Idle time of the oldest key: " . $oldestKey['idle_time'] . " seconds";

		// Write the response body to the response
		$response->getBody()->write($responseBody);

		return $response;
	}


	private function connect()
	{
		//Connecting to Redis server on localhost 
		$this->redis = new Redis();
		//			$host = 'redis';// docker...
		//			$host = '127.0.0.1';// local
		$host = $this->serverSettings['redis_host'];
		$redis_database = (int)$this->serverSettings['redis_database'];
		$port = 6379;

		if (!$host)
		{
			$msg = 'Redis host not configured';
			\App\modules\phpgwapi\services\Cache::message_set($msg, 'error');
			self::$error_connect = true;
			//		$this->log_this($msg, __LINE__);
			return;
		}

		if ($redis_database > 15)
		{
			$msg = "Redis: max number of databases is 16";
			\App\modules\phpgwapi\services\Cache::message_set($msg, 'error');
			self::$error_connect = true;
			//		$this->log_this($msg, __LINE__);
			return;
		}

		try
		{
			$this->redis->connect($host, $port);
			$ping = $this->redis->ping();
			$this->redis->select($redis_database);
			self::$error_connect = empty($ping);
			self::$is_connected = !!$ping;
		}
		catch (Exception $e)
		{
			$msg = 'Redis: ' . $e->getMessage();
			\App\modules\phpgwapi\services\Cache::message_set($msg, 'error');
			self::$error_connect = true;

			//		$this->log_this($msg, __LINE__);
		}
	}

	private function getOldestKey()
	{
		$iterator = null;
		$oldestKey = null;
		$maxIdleTime = -1;

		while ($keys = $this->redis->scan($iterator))
		{
			foreach ($keys as $key)
			{
				$idleTime = $this->redis->object('idletime', $key);
				if ($idleTime > $maxIdleTime)
				{
					$maxIdleTime = $idleTime;
					$oldestKey = $key;
				}
			}
		}

		return ['key' => $oldestKey, 'idle_time' => $maxIdleTime];
	}
}
