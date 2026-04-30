<?php

/**
 * Setup detection
 * @author Dan Kuykendall <seek3r@phpgroupware.org>
 * @author Miles Lott <milosch@phpgroupware.org>
 * @copyright Portions Copyright (C) 2001-2004 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.fsf.org/licenses/gpl.html GNU General Public License
 * @package phpgwapi
 * @subpackage application
 * @version $Id$
 */

namespace App\modules\phpgwapi\services\setup;

use App\modules\phpgwapi\services\Settings;
use App\Database\Db;
use Exception;
use App\modules\phpgwapi\services\setup\Setup;
use App\modules\phpgwapi\services\Migration\MigrationService;
use PDO;

/**
 * Setup detection
 * 
 * @package phpgwapi
 * @subpackage application
 */
class Detection
{
	//constructor
	private $setup;
	private $db;
	private $serverSettings;


	public function __construct()
	{
		$this->serverSettings = Settings::getInstance()->get('server');
		$this->db = Db::getInstance();
		$this->setup = new Setup();
	}
	function get_versions()
	{
		$d = dir(realpath(SRC_ROOT_PATH . '/modules'));
		while ($entry = $d->read())
		{
			// skip the . and .. directories
			if ($entry == '.' || $entry == '..')
			{
				continue;
			}

			if ($entry != 'Setup' && is_dir(SRC_ROOT_PATH . '/modules/' . $entry))
			{
				$f = SRC_ROOT_PATH . '/modules/' . $entry . '/setup/setup.inc.php';

				if (file_exists($f))
				{
					require $f;
					$setup_info[strtolower($entry)]['filename'] = $f;
				}
			}
		}
		$d->close();

		// _debug_array($setup_info);
		ksort($setup_info);
		return $setup_info;
	}

	function get_db_versions($setup_info = array())
	{
		$tname = array();
		$this->db->set_halt_on_error('no');
		$tables =  $this->db->table_names();
		$newapps = '';
		$oldapps = '';
		if (count($tables) > 0 && is_array($tables))
		{
			foreach ($tables as $key => $val)
			{
				$tname[] = $val;
			}
			$tbl_exists = in_array('phpgw_applications', $tname);
		}

		if ((count($tables) > 0) && (is_array($tables)) && $tbl_exists)
		{
			$stmt = $this->db->prepare('SELECT * FROM phpgw_applications');
			$stmt->execute();

			while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
			{
				$setup_info[$row['app_name']]['currentver'] = $row['app_version'];
				$setup_info[$row['app_name']]['enabled'] = $row['app_enabled'];
			}
			/* This is to catch old setup installs that did not have phpgwapi listed as an app */
			$tmp = null;
			if (isset($setup_info['phpgwapi']['version']))
			{
				$tmp = $setup_info['phpgwapi']['version']; /* save the file version */
			}

			if (!$setup_info['phpgwapi']['currentver'])
			{
				$setup_info['phpgwapi']['currentver'] = $setup_info['admin']['currentver'];
				$setup_info['phpgwapi']['version'] = $setup_info['admin']['currentver'];
				$setup_info['phpgwapi']['enabled'] = $setup_info['admin']['enabled'];
				// _debug_array($setup_info['phpgwapi']);exit;
				// There seems to be a problem here.  If ['phpgwapi']['currentver'] is set,
				// The GLOBALS never gets set.
				Settings::getInstance()->set('setup_info', $setup_info);
				$this->setup->register_app('phpgwapi');
			}
			else
			{
				Settings::getInstance()->set('setup_info', $setup_info);
			}
			$setup_info['phpgwapi']['version'] = $tmp; /* restore the file version */
		}
		Settings::getInstance()->set('setup_info', $setup_info);
		// _debug_array($setup_info);
		return $setup_info;
	}

	/* app status values:
		U	Upgrade required/available
		R	upgrade in pRogress
		C	upgrade Completed successfully
		D	Dependency failure
		P	Post-install dependency failure
		F	upgrade Failed
		V	Version mismatch at end of upgrade (Not used, proposed only)
		M	Missing files at start of upgrade (Not used, proposed only)
		*/
	function compare_versions($setup_info)
	{
		$migrationService = null;

		foreach ($setup_info as $key => $value)
		{
			//echo '<br>'.$value['name'].'STATUS: '.$value['status'];
			/* Only set this if it has not already failed to upgrade - Milosch */
			if (!isset($value['status']) || (!($value['status'] == 'F' || $value['status'] == 'C')))
			{
				// For migration-based modules, version = migration count
				$appname = $value['name'] ?? '';
				if ($appname)
				{
					if (!$migrationService)
					{
						$migrationService = new MigrationService();
					}

					if ($migrationService->moduleHasMigrations($appname))
					{
						$setup_info[$key]['version'] = $migrationService->getTargetVersion($appname);
						$setup_info[$key]['currentver'] = $migrationService->getCurrentVersion($appname);
						$value = $setup_info[$key]; // refresh for comparison below
					}
				}

				//if ($setup_info[$key]['currentver'] > $setup_info[$key]['version'])
				if (
					isset($value['currentver']) && isset($value['version'])
					&& $this->setup->amorethanb($value['currentver'], $value['version'])
				)
				{
					$setup_info[$key]['status'] = 'V';
				}
				else if (
					isset($value['currentver']) && isset($value['version'])
					&& $value['currentver'] == $value['version']
				)
				{
					$setup_info[$key]['status'] = 'C';
				}
				else if (
					isset($value['currentver']) && isset($value['version'])
					&& $this->setup->alessthanb($value['currentver'], $value['version'])
				)
				{
					$setup_info[$key]['status'] = 'U';
				}
				else
				{
					$setup_info[$key]['status'] = 'U';
				}
			}
		}
		// _debug_array($setup_info);

		Settings::getInstance()->set('setup_info', $setup_info);
		return $setup_info;
	}

	function check_depends($setup_info)
	{
		$migrationService = null;

		/* Run the list of apps */
		foreach ($setup_info as $key => $value)
		{
			/* Does this app have any depends */
			if (isset($value['depends']))
			{
				/* If so find out which apps it depends on */
				foreach ($value['depends'] as $depkey => $depvalue)
				{
					/* I set this to False until we find a compatible version of this app */
					$setup_info['depends'][$depkey]['status'] = False;

					$depAppName = $depvalue['appname'];

					// Check if the dependency target uses migrations
					if (!$migrationService)
					{
						$migrationService = new MigrationService();
					}

					if ($migrationService->moduleHasMigrations($depAppName))
					{
						// Migration-based dependency: version is migration count
						// Satisfied if applied count >= required count
						$appliedCount = (int) $migrationService->getCurrentVersion($depAppName);

						foreach ($depvalue['versions'] as $requiredVersion)
						{
							if ($appliedCount >= (int) $requiredVersion)
							{
								$setup_info['depends'][$depkey]['status'] = True;
								break;
							}
						}
					}
					else
					{
						// Legacy version-based dependency check
						foreach ($depvalue['versions'] as $depskey => $depsvalue)
						{
							if (!isset($setup_info[$depAppName]['currentver']))
							{
								$setup_info[$depAppName]['currentver'] = null;
							}
							else
							{
								$major = $this->setup->get_major($setup_info[$depAppName]['currentver']);
								if ($major == $depsvalue)
								{
									$setup_info['depends'][$depkey]['status'] = True;
								}
								else
								{
									$major_depsvalue = $this->setup->get_major($depsvalue);
									$depsvalue_arr = explode('.', $depsvalue);
									$minor_depsvalue = isset($depsvalue_arr[3]) ? $depsvalue_arr[3] : null;

									$_app_version = isset($setup_info[$depAppName]['currentver']) ? $setup_info[$depAppName]['currentver'] : $setup_info[$depAppName]['version'];
									$currentver_arr =  explode('.', $_app_version);
									$minor = isset($currentver_arr[3]) ? $currentver_arr[3] : null;
									if ($major == $major_depsvalue && $minor <= $minor_depsvalue)
									{
										$setup_info['depends'][$depkey]['status'] = True;
									}
								}
							}
						}
					}
				}
				/*
					 Finally, we loop through the dependencies again to look for apps that still have a failure status
					 If we find one, we set the apps overall status as a dependency failure.
					*/
				foreach ($value['depends'] as $depkey => $depvalue)
				{
					if ($setup_info['depends'][$depkey]['status'] == False)
					{
						/* Only set this if it has not already failed to upgrade - Milosch */
						if ($setup_info[$key]['status'] != 'F') //&& $setup_info[$key]['status'] != 'C')
						{
							if ($setup_info[$key]['status'] == 'C')
							{
								$setup_info[$key]['status'] = 'D';
							}
							else
							{
								$setup_info[$key]['status'] = 'P';
							}
						}
					}
				}
			}
		}
		Settings::getInstance()->set('setup_info', $setup_info);
		return $setup_info;
	}

	/*
		 Called during the mass upgrade routine (Stage 1) to check for apps
		 that wish to be excluded from this process.
		*/
	function upgrade_exclude($setup_info)
	{
		foreach ($setup_info as $key => $value)
		{
			if (isset($value['no_mass_update']) || !isset($value['enable']))
			{
				unset($setup_info[$key]);
			}
		}
		return $setup_info;
	}

	/*
		 Called during the first install.
		 only install core tables and the admin and preferences applications
		*/
	function base_install($setup_info)
	{
		$core_elements = array(
			'phpgwapi'		=> true,
			//if this isn't here, it can never be installed as it is part of the api - skwashd
			'notifywindow'	=> true,
			'admin'			=> true,
			'preferences'	=> true
		);


		foreach (array_keys($setup_info) as $key)
		{
			if (
				!isset($core_elements[$key])
				|| !$core_elements[$key]
			)
			{
				unset($setup_info[$key]);
			}
		}
		return $setup_info;
	}


	//FIXME
	function check_header()
	{
		//         $setup_info = Settings::getInstance()->get('setup_info');
		//			$server_info = Settings::getInstance()->get('server');
		$setup_data = Settings::getInstance()->get('setup');

		if (!file_exists(SRC_ROOT_PATH . '/../config/header.inc.php'))
		{
			$setup_data['header_msg'] = 'Stage One';
			$setup_data['stage']['header'] = 1;
			Settings::getInstance()->set('setup', $setup_data);
			return 1;
		}
		else
		{
			if (!isset($this->serverSettings['header_admin_password']))
			{
				$setup_data['header_msg'] = 'Stage One (No header admin password set)';
				$setup_data['stage']['header'] = 2;
				Settings::getInstance()->set('setup', $setup_data);
				return 2;
			}
			elseif (empty($this->db->get_domain()))
			{
				$setup_data['header_msg'] = 'Stage One (Upgrade your header.inc.php)';
				$setup_data['stage']['header'] = 3;
				Settings::getInstance()->set('setup', $setup_data);
				return 3;
			}
			elseif (
				isset($this->serverSettings['versions']['header'])
				&& isset($this->serverSettings['versions']['current_header'])
				&& $this->serverSettings['versions']['header'] != $this->serverSettings['versions']['current_header']
			)
			{
				$setup_data['header_msg'] = 'Stage One (Upgrade your header.inc.php)';
				$setup_data['stage']['header'] = 3;
				Settings::getInstance()->set('setup', $setup_data);
				return 3;
			}
		}
		/* header.inc.php part settled. Moving to authentication */
		$setup_data['header_msg'] = 'Stage One (Completed)';
		$setup_data['stage']['header'] = 10;
		Settings::getInstance()->set('setup', $setup_data);
		return 10;
	}

	function check_db()
	{
		//			$setup_info = $GLOBALS['setup_info'];
		$setup_info = Settings::getInstance()->get('setup_info');
		$setup_data = Settings::getInstance()->get('setup'); // to be removed

		$this->db->set_halt_on_error('no');
		// _debug_array($setup_info);

		//error message supression
		if (isset($GLOBALS['DEBUG']) && $GLOBALS['DEBUG'])
		{
			flush(); //push what we have
			ob_start(); //get the output
		}

		if (!isset($setup_info['phpgwapi']['currentver']))
		{
			try
			{
				$setup_info = $this->get_db_versions($setup_info);
			}
			catch (Exception $e)
			{
				return 1;
			}
		}

		// _debug_array($setup_info);
		if (isset($setup_info['phpgwapi']['currentver']))
		{
			if (
				isset($setup_info['phpgwapi']['version'])
				&& $setup_info['phpgwapi']['currentver'] == $setup_info['phpgwapi']['version']
			)
			{
				$setup_data['header_msg'] = 'Stage 1 (Tables Complete)';
				$setup_info['setup']['header_msg'] = 'Stage 1 (Tables Complete)';
				Settings::getInstance()->set('setup', $setup_data);
				Settings::getInstance()->set('setup_info', $setup_info);
				return 10;
			}
			else
			{
				$setup_data['header_msg'] = 'Stage 1 (Tables need upgrading)';
				$setup_info['setup']['header_msg'] = 'Stage 1 (Tables need upgrading)';
				Settings::getInstance()->set('setup', $setup_data);
				Settings::getInstance()->set('setup_info', $setup_info);
				return 4;
			}
		}
		else
		{
			/* no tables, so checking if we can create them */
			//Consider try/catch block

			$this->db->exec('CREATE TABLE phpgw_testrights ( testfield varchar(5) NOT NULL )');

			if (ob_get_contents())
			{
				ob_end_clean(); //dump the output
			}

			if ($this->db->errorCode() == '00000')
			{
				$this->db->exec('DROP TABLE phpgw_testrights');
				$setup_info['setup']['header_msg'] = 'Stage 3 (Install Applications)';
				$setup_data['header_msg'] = 'Stage 3 (Install Applications)';
				Settings::getInstance()->set('setup', $setup_data);
				Settings::getInstance()->set('setup_info', $setup_info);
				return 3;
			}
			else
			{
				$setup_info['setup']['header_msg'] = 'Stage 1 (Create Database)';
				$setup_data['header_msg'] = 'Stage 1 (Create Database)';
				Settings::getInstance()->set('setup', $setup_data);
				Settings::getInstance()->set('setup_info', $setup_info);
				return 1;
			}
		}
	}

	function check_config()
	{
		$setup_data = Settings::getInstance()->get('setup');

		$this->db->set_halt_on_error('no');
		if (
			!isset($setup_data['stage']['db'])
			|| $setup_data['stage']['db'] != 10
		)
		{
			$setup_data['stage']['config'] = '';
			Settings::getInstance()->set('setup', $setup_data);
			return '';
		}

		$stmt = $this->db->prepare("SELECT config_value FROM phpgw_config WHERE config_name='freshinstall'");
		$stmt->execute();

		$result = $stmt->fetch(PDO::FETCH_ASSOC);
		$configed = isset($result['config_value']) ? $result['config_value'] : null;
		if ($configed)
		{
			$setup_data['header_msg'] = 'Stage 2 (Needs Configuration)';
			$setup_data['stage']['config'] = 1;
			Settings::getInstance()->set('setup', $setup_data);
			return 1;
		}
		else
		{
			$setup_data['header_msg'] = 'Stage 2 (Configuration OK)';
			$setup_data['stage']['config'] = 10;
			Settings::getInstance()->set('setup', $setup_data);
			return 10;
		}
	}

	function check_lang($check = true)
	{
		$setup_data = Settings::getInstance()->get('setup');
		$setup_info = Settings::getInstance()->get('setup_info');


		$this->db->set_halt_on_error('no');
		if (
			$check
			&& (!isset($setup_data['stage']['db'])
				|| $setup_data['stage']['db'] != 10)
		)
		{
			$setup_data['stage']['lang'] = '';
			Settings::getInstance()->set('setup', $setup_data);
			return '';
		}
		if (!$check)
		{
			if (!isset($setup_info) || !is_array($setup_info))
			{
				$setup_info = array();
			}

			$setup_info = $this->get_db_versions($setup_info);
		}

		$langtbl  = '';
		$languagestbl = '';

		$setup_data['installed_langs'] = array();

		$stmt = $this->db->prepare("SELECT COUNT(DISTINCT lang) as langcount FROM phpgw_lang");
		$stmt->execute();

		$result = $stmt->fetch(PDO::FETCH_ASSOC);

		if ($result['langcount'] == 0)
		{
			$setup_data['header_msg'] = 'Stage 3 (No languages installed)';
			$setup_data['stage']['lang'] = 1;
			Settings::getInstance()->set('setup', $setup_data);
			return 1;
		}
		else
		{
			$stmt = $this->db->prepare("SELECT DISTINCT lang FROM phpgw_lang");
			$stmt->execute();

			$setup_data['installed_langs'] = array();

			while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
			{
				$setup_data['installed_langs'][$row['lang']] = $row['lang'];
			}

			foreach ($setup_data['installed_langs'] as $key => $value)
			{
				$stmt = $this->db->prepare("SELECT lang_name FROM phpgw_languages WHERE lang_id = :value");
				$stmt->execute([':value' => $value]);

				$result = $stmt->fetch(PDO::FETCH_ASSOC);
				$setup_data['installed_langs'][$value] = $result['lang_name'];
			}
			$setup_data['header_msg'] = 'Stage 3 (Completed)';
			$setup_data['stage']['lang'] = 10;
			Settings::getInstance()->set('setup', $setup_data);
			return 10;
		}
	}

	/**
	 * Verify that all of an app's tables exist in the db
	 * @param $appname
	 * @param $any optional, set to True to see if any of the apps tables are installed
	 */
	function check_app_tables($appname, $any = False)
	{
		$none = 0;
		$setup_info = Settings::getInstance()->get('setup_info');

		if (
			isset($setup_info[$appname]['tables'])
			&& $setup_info[$appname]['tables']
		)
		{
			/* Make a copy, else we send some callers into an infinite loop */
			$copy = $setup_info;
			$this->db->set_halt_on_error('no');
			$table_names =  $this->db->table_names();
			$tables = array();
			foreach ($table_names as $key => $val)
			{
				$tables[] = $val;
			}
			foreach ($copy[$appname]['tables'] as $key => $val)
			{
				if ($GLOBALS['DEBUG'])
				{
					echo '<br>check_app_tables(): Checking: ' . $appname . ',table: ' . $val;
				}
				if (!in_array($val, $tables))
				{
					if ($GLOBALS['DEBUG'])
					{
						echo '<br>check_app_tables(): ' . $val . ' missing!';
					}
					if (!$any)
					{
						return False;
					}
					else
					{
						$none++;
					}
				}
				else
				{
					if ($any)
					{
						if ($GLOBALS['DEBUG'])
						{
							echo '<br>check_app_tables(): Some tables installed';
						}
						return True;
					}
				}
			}
		}
		if ($none && $any)
		{
			if ($GLOBALS['DEBUG'])
			{
				echo '<br>check_app_tables(): No tables installed';
			}
			return False;
		}
		else
		{
			if ($GLOBALS['DEBUG'])
			{
				echo '<br>check_app_tables(): All tables installed';
			}
			return True;
		}
	}
}
