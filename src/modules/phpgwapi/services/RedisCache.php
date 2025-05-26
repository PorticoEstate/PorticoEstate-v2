<?php

/**
 * phpGroupWare caching system
 *
 * @author Sigurd Nes <sigurdne@online.no>
 * @copyright Copyright (C) 2022 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License Version 2 or later
 * @package phpgroupware
 * @subpackage phpgwapi
 * @version $Id$
 */

/*
		This program is free software: you can redistribute it and/or modify
		it under the terms of the GNU Lesser General Public License as published by
		the Free Software Foundation, either version 2 of the License, or
		(at your option) any later version.

		This program is distributed in the hope that it will be useful,
		but WITHOUT ANY WARRANTY; without even the implied warranty of
		MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
		GNU Lesser General Public License for more details.

		You should have received a copy of the GNU Lesser General Public License
		along with this program.  If not, see <http://www.gnu.org/licenses/>.
	 */

/**
 * phpGroupWare caching system
 *
 * Simple data caching system with common ways to store/retreive data
 *
 * @package phpgroupware
 * @subpackage phpgwapi
 * @category caching
 */

namespace App\modules\phpgwapi\services;

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\Log;
use Exception;
use \Redis;

/**
 * Shared memory handler class
 */
class RedisCache
{
	private $redis = null;
	private static $error_connect = null;
	private static $is_connected = null;
	private $serverSettings;

	/**
	 * Constructor
	 */
	function __construct()
	{
		$this->serverSettings = Settings::getInstance()->get('server');

		if (!$this->redis && !self::$error_connect && $this->is_enabled())
		{
			$this->connect();
		}
	}

	public function get_is_connected()
	{
		return self::$is_connected;
	}

	private function log_this($msg, $line)
	{
		$log = new Log();
		$log->error(array(
			'text'	=> 'data som feiler for phpgwapi_redis::connect(). Error: %1',
			'p1'	=> $msg,
			'line'	=> $line,
			'file'	=> __FILE__
		));
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
			error_log("REDIS CONFIG ERROR: {$msg}");
			\App\modules\phpgwapi\services\Cache::message_set($msg, 'error');
			self::$error_connect = true;
			$this->log_this($msg, __LINE__);
			return;
		}

		if ($redis_database > 15)
		{
			$msg = "Redis: max number of databases is 16";
			\App\modules\phpgwapi\services\Cache::message_set($msg, 'error');
			self::$error_connect = true;
			$this->log_this($msg, __LINE__);
			return;
		}

		try
		{
			$connectResult = $this->redis->connect($host, $port);
			$ping = $this->redis->ping();
			$this->redis->select($redis_database);
			self::$error_connect = empty($ping);
			self::$is_connected = !!$ping;
			
		}
		catch (Exception $e)
		{
			$msg = 'Redis: ' . $e->getMessage();
			error_log("REDIS CONFIG ERROR: {$msg}");
			\App\modules\phpgwapi\services\Cache::message_set($msg, 'error');
			self::$error_connect = true;

			$this->log_this($msg, __LINE__);
		}
	}


	/**
	 * Get a value from memory
	 *
	 * @todo document me properly
	 */
	function get_value($key)
	{
		return $this->redis->get($key);
	}

	/**
	 * Store a value in memory
	 *
	 * @todo document me properly
	 */
	function store_value($key, $value)
	{
		return $this->redis->set($key, $value);
	}



	/**
	 * Delete an entry from the cache
	 *
	 * @param int $key the entry to delete from the cache
	 */
	function delete_key($key)
	{
		return $this->redis->delete(array($key));
	}

	/**
	 * Clear all values from the cache?
	 *
	 * @todo document me properly
	 */
	function clear_cache()
	{
		return $this->redis->delete($this->redis->keys('*'));
	}

	/**
	 * Atomically acquire a lock using Redis SETNX
	 *
	 * @param string $key The lock key
	 * @param string $value The lock value (usually session ID)
	 * @param int $ttl Time to live in seconds
	 * @return bool True if lock was acquired, false if already locked
	 */
	function acquire_lock($key, $value, $ttl = 30)
	{
		if (!$this->redis) {
			return false;
		}
		
		// Use SETNX with expiration for atomic lock acquisition
		// This will only set the key if it doesn't exist
		$result = $this->redis->set($key, $value, array('nx', 'ex' => $ttl));
		return $result === true;
	}

	/**
	 * Release a lock if it belongs to the given value
	 *
	 * @param string $key The lock key
	 * @param string $value The expected lock value (session ID)
	 * @return bool True if lock was released, false otherwise
	 */
	function release_lock($key, $value)
	{
		if (!$this->redis) {
			return false;
		}
		
		// Use Lua script to atomically check value and delete
		$lua_script = "
			if redis.call('GET', KEYS[1]) == ARGV[1] then
				return redis.call('DEL', KEYS[1])
			else
				return 0
			end
		";
		
		return $this->redis->eval($lua_script, array($key, $value), 1) > 0;
	}

	/**
	 * Get keys matching a pattern (Redis KEYS command)
	 *
	 * @param string $pattern The pattern to match
	 * @return array Array of matching keys
	 */
	function get_keys_by_pattern($pattern)
	{
		if (!$this->redis) {
			return array();
		}
		
		return $this->redis->keys($pattern);
	}


	/**
	 * Delete stale entries from the cache
	 */


	/**
	 * Check if redis is enabled
	 *
	 * @return bool is it enabled?
	 */
	function is_enabled()
	{
		/**
		 * cache results within session
		 */
		static $enabled = false;
		static $checked = false;

		if ($checked)
		{
			return $enabled;
		}

		if (isset($this->serverSettings['redis_enable']) && $this->serverSettings['redis_enable'])
		{
			$checked = true;
			$enabled = extension_loaded('redis');
			return $enabled;
		}

		return false;
	}
}
