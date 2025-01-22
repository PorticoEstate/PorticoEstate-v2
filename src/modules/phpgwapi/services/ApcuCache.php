<?php

namespace App\modules\phpgwapi\services;

use App\modules\phpgwapi\services\Settings;

/**
 * Shared memory handler class
 */
class ApcuCache
{

	public static function is_enabled()
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
		$serverSettings = Settings::getInstance()->get('server');
		if (isset($serverSettings['apcu_enable']) && $serverSettings['apcu_enable'])
		{
			$checked = true;
			$enabled = extension_loaded('apcu');
			return $enabled;
		}

		return false;
	}

	public static function store_value($key, $value, $ttl = 0)
	{
		return apcu_store($key, $value, $ttl);
	}

	public static function get_value($key)
	{
		return apcu_fetch($key);
	}

	public static function delete_key($key)
	{
		return apcu_delete($key);
	}

	public static function exists($key)
	{
		return apcu_exists($key);
	}

	public static function clear_cache()
	{
		return apcu_clear_cache();
	}
}
