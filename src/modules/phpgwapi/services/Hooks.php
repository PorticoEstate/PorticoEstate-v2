<?php

namespace App\modules\phpgwapi\services;
require_once SRC_ROOT_PATH . '/helpers/LegacyObjectHandler.php';

/**
 * Allows applications to "hook" into each other
 * @author Dan Kuykendall <seek3r@phpgroupware.org>
 * @copyright Copyright (C) 2000-2004 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.fsf.org/licenses/lgpl.html GNU Lesser General Public License
 * @package phpgwapi
 * @subpackage application
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
	   GNU General Public License for more details.

	   You should have received a copy of the GNU General Public License
	   along with this program.  If not, see <http://www.gnu.org/licenses/>.
	 */

/**
 * Ability for applications to set and use hooks to communicate with each other
 * 
 * @package phpgwapi
 * @subpackage application
 */

use App\modules\phpgwapi\controllers\Applications;
use PDO;

class Hooks
{
	var $found_hooks = array();
	protected $db = null;
	private $apps = null;
	private $flags = null;
	private $user = null;

	public function __construct($db = null)
	{
		$this->db = $db ?? \App\Database\Db::getInstance();
		$this->apps = Settings::getInstance()->get('apps');
		$this->flags = Settings::getInstance()->get('flags');
		$this->user = Settings::getInstance()->get('user');

		//check for installed tables

		$tables = $this->db->table_names();
		if (in_array('phpgw_hooks', $tables))
		{
			$this->read();
		}
	}

	/**
	 * Read all the hooks
	 */
	public function read()
	{
		$stmt = $this->db->prepare("SELECT hook_appname, hook_location, hook_filename FROM phpgw_hooks");
		$stmt->execute();

		$this->found_hooks = array();

		while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
		{
			$this->found_hooks[$row['hook_appname']][$row['hook_location']] = $row['hook_filename'];
		}

		return $this->found_hooks;
	}

	/**
	 * executes all the hooks (the user has rights to) for a given location 
	 *
	 * @param $args location-name as string or array:
	 * @param $args['location'] location-name
	 * @param $order or $args['order'] array of appnames (as value), which should be executes first
	 * @param $args is passed to the hook, if its a new method-hook
	 * @param $no_permission_check if True execute all hooks, not only the ones a user has rights to
	 * $no_permission_check should *ONLY* be used when it *HAS* to be. (jengo)
	 * @return array with results of each hook call (with appname as key): \
	 * 	False if no hook exists, True if old hook exists \
	 * 	and whatever the new methode-hook returns (can be True or False too!).
	 */
	public function process($args, $order = '', $no_permission_check = False)
	{
		//echo "<p>hooks::process("; print_r($args); echo ")</p>\n";
		if ($order == '')
		{
			$order = is_array($args) && isset($args['order']) ? $args['order'] :
				array($this->flags['currentapp']);
		}

		$results = array();
		/* First include the ordered apps hook file */
		foreach ($order as $appname)
		{
			$results[$appname] = $this->single($args, $appname, $no_permission_check);

			if (!isset($results[$appname]))	// happens if th methode hook has no return-value
			{
				$results[$appname] = False;
			}
		}

		/* Then add the rest */
		$apps = array();
		if ($no_permission_check)
		{
			$apps = $this->apps;
		}
		else if (isset($this->user['apps']))
		{
			$apps = $this->user['apps'];
		}

		// Run any API hooks first
		$results['phpgwapi'] = $this->single($args, 'phpgwapi', false);

		if (is_array($apps))
		{
			foreach ($apps as $app)
			{
				if (isset($app['name']) && $app['name'])
				{
					$appname = $app['name'];
					if (!isset($results[$appname]))
					{
						$results[$appname] = $this->single($args, $appname, $no_permission_check);
					}
				}
			}
		}
		return $results;
	}

	/**
	 * executes a single hook of a given location and application
	 *
	 * @param $args location-name as string or array:
	 * @param $args['location'] location-name
	 * @param $appname or $args['appname'] name of the app, which's hook to execute, if empty the current app is used
	 * @param $args is passed to the hook, if its a new method-hook
	 * @param $no_permission_check if True execute all hooks, not only the ones a user has rights to
	 * @param $try_unregisterd If true, try to include old file-hook anyway (for setup)
	 * $no_permission_check should *ONLY* be used when it *HAS* to be. (jengo)
	 * @return False if no hook exists, True if an old hook exist and whatever the new method-hook returns
	 */
	public function single($args, $appname = '', $no_permission_check = False, $try_unregistered = False)
	{
		//echo "<p>hooks::single("; print_r($args); echo ",'$appname','$no_permission_check','$try_unregistered')</p>\n";
		if (is_array($args))
		{
			$location = $args['location'];
		}
		else
		{
			$location = $args;
		}
		if (!$appname)
		{
			$appname = is_array($args) && isset($args['appname']) ? $args['appname'] : $this->flags['currentapp'];
		}

		/* First include the ordered apps hook file */
		if (isset($this->found_hooks[$appname][$location]) || $try_unregistered)
		{
			$parts = array();
			if (isset($this->found_hooks[$appname][$location]))
			{
				$parts = explode('.', $method = $this->found_hooks[$appname][$location]);
			}

			if (count($parts) != 3 || ($parts[1] == 'inc' && $parts[2] == 'php'))
			{
				if ($try_unregistered && empty($methode))
				{
					$method = 'hook_' . $location . '.inc.php';
				}
				$f = SRC_ROOT_PATH . "/modules/" . $appname . "/inc/{$method}";
				if (((isset($this->user['apps'][$appname]) && $this->user['apps'][$appname])
						|| (($no_permission_check || $location == 'config' || $appname == 'phpgwapi') && $appname))
					&& file_exists($f)
				)
				{
					include_once($f);
					return true;
				}
				return false;
			}
			else	// new style method-hook
			{
				return ExecMethod($method, $args);
			}
		}
		return false;
	}

	/**
	 * loop through the applications and count the hooks
	 */
	public function count($location)
	{
		$count = 0;
		foreach ($this->user['apps'] as $appname => $data)
		{
			if (isset($this->found_hooks[$appname][$location]))
			{
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Register and/or de-register an application's hooks
	 *
	 * @param $appname	Application 'name' 
	 * @param $hooks array with hooks to register, eg $setup_info[$app]['hooks'] or not used for only deregister the hooks
	 */
	public function register_hooks($appname, $hooks = '')
	{
		if (!$appname)
		{
			return false;
		}

		$db_appname = $this->db->db_addslashes($appname);
		$stmt = $this->db->prepare("DELETE FROM phpgw_hooks WHERE hook_appname = :appname");
		$stmt->execute([':appname' => $db_appname]);

		if (!is_array($hooks)) // only deregister
		{
			return true;
		}
		foreach ($hooks as $key => $hook)
		{
			if (!is_numeric($key)) // new method based hook
			{
				$location = $key;
				$filename = $hook;
			}
			else
			{
				$location = $hook;
				$filename = "hook_$hook.inc.php";
			}
			$stmt = $this->db->prepare("INSERT INTO phpgw_hooks (hook_appname,hook_location,hook_filename) VALUES (:appname, :location, :filename)");
			$stmt->execute([
				':appname' => $appname,
				':location' => $location,
				':filename' => $filename
			]);
		}
		return true;
	}
	/**
	 * Register the hooks of all applications (used by admin)
	 *
	 */
	public function register_all_hooks()
	{
		if (!isset($this->apps) || !is_array($this->apps))
		{
			$applications = new Applications();
			$this->apps = $applications->read_installed_apps();
		}

		$app_list = array_keys($this->apps);
		$app_list[] = 'phpgwapi';

		foreach ($app_list as $appname)
		{
			$f = PHPGW_SERVER_ROOT . "/$appname/setup/setup.inc.php";
			if (file_exists($f))
			{
				//DO NOT USE include_once here it breaks API hooks - skwashd dec07
				include $f;
				if (isset($setup_info[$appname]['hooks']))
				{
					$this->register_hooks($appname, $setup_info[$appname]['hooks']);
				}
			}
		}
	}
}
