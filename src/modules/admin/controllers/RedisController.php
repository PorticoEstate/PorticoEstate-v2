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
	private $redis = null;
	private static $error_connect = null;
	private $serverSettings;
	private $errormsg;

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

		// Fetch keyspace hits and misses
		$keyspaceHits = $this->redis->info('stats')['keyspace_hits'];
		$keyspaceMisses = $this->redis->info('stats')['keyspace_misses'];

		// Calculate hit and miss rates
		$totalRequests = $keyspaceHits + $keyspaceMisses;
		$hitRate = $totalRequests > 0 ? ($keyspaceHits / $totalRequests) * 100 : 0;
		$missRate = $totalRequests > 0 ? ($keyspaceMisses / $totalRequests) * 100 : 0;

		// Get Redis connection details
		$redisHost = $this->serverSettings['redis_host'];
		$redisPort = 6379;
		$redisDatabase = $this->serverSettings['redis_database'];

		// Create the response body as a table
		$responseBody = "<h1>Redis Cache Info</h1>";
		$responseBody .= "<table class=\"pure-table pure-table-bordered pure-table-striped \" style='width:100%;'>";
		$responseBody .= "<tr><th style='text-align: left;'>Metric</th><th style='text-align: left;'>Value</th></tr>";
		$responseBody .= "<tr><td>Redis Host</td><td>" . $redisHost . "</td></tr>";
		$responseBody .= "<tr><td>Redis Port</td><td>" . $redisPort . "</td></tr>";
		$responseBody .= "<tr><td>Selected Database</td><td>" . $redisDatabase . "</td></tr>";
		$responseBody .= "<tr><td>Memory Usage</td><td>" . round($memoryUsage / (1024 * 1024), 1) . " MB</td></tr>";
		$responseBody .= "<tr><td>Connected Clients</td><td>" . $clients . "</td></tr>";
		$responseBody .= "<tr><td>Number of Keys</td><td>" . $keys . "</td></tr>";
		$responseBody .= "<tr><td>Oldest Key</td><td>" . $oldestKey['key'] . "</td></tr>";
		$responseBody .= "<tr><td>Idle Time of Oldest Key</td><td>" . $oldestKey['idle_time'] . " seconds</td></tr>";
		$responseBody .= "<tr><td>Keyspace Hits</td><td>" . $keyspaceHits . "</td></tr>";
		$responseBody .= "<tr><td>Keyspace Misses</td><td>" . $keyspaceMisses . "</td></tr>";
		$responseBody .= "<tr><td>Hit Rate</td><td>" . round($hitRate, 2) . "%</td></tr>";
		$responseBody .= "<tr><td>Miss Rate</td><td>" . round($missRate, 2) . "%</td></tr>";
		$responseBody .= "</table>";


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
			return;
		}

		if ($redis_database > 15)
		{
			$msg = "Redis: max number of databases is 16";
			\App\modules\phpgwapi\services\Cache::message_set($msg, 'error');
			self::$error_connect = true;
			return;
		}

		try
		{
			$this->redis->connect($host, $port);
			$ping = $this->redis->ping();
			$this->redis->select($redis_database);
			self::$error_connect = empty($ping);
		}
		catch (Exception $e)
		{
			$msg = 'Redis: ' . $e->getMessage();
			\App\modules\phpgwapi\services\Cache::message_set($msg, 'error');
			self::$error_connect = true;
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
