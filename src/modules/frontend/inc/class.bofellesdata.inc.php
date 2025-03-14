<?php

/**
 * Frontend : a simplified tool for end users.
 *
 * @copyright Copyright (C) 2010 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @package Frontend
 * @version $Id$
 */
/*
	  This program is free software: you can redistribute it and/or modify
	  it under the terms of the GNU General Public License as published by
	  the Free Software Foundation, either version 2 of the License, or
	  (at your option) any later version.

	  This program is distributed in the hope that it will be useful,
	  but WITHOUT ANY WARRANTY; without even the implied warranty of
	  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	  GNU General Public License for more details.

	  You should have received a copy of the GNU General Public License
	  along with this program.  If not, see <http://www.gnu.org/licenses/>.
	 */

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\Cache;
use App\modules\phpgwapi\services\Log;
use App\Database\Db2;

class frontend_bofellesdata
{

	// Instance variable
	protected static $bo;

	protected $db;
	/**
	 * Get a static reference to the storage object associated with this model object
	 *
	 * @return the storage object
	 */
	public static function get_instance()
	{
		if (self::$bo == null)
		{
			self::$bo = CreateObject('frontend.bofellesdata');
		}
		return self::$bo;
	}
	/* our simple php ping function */

	function ping($host)
	{
		exec(sprintf('ping -c 1 -W 5 %s', escapeshellarg($host)), $res, $rval);
		return $rval === 0;
	}

	public function get_db()
	{
		if ($this->db && is_object($this->db))
		{
			return $this->db;
		}

		$config = CreateObject('phpgwapi.config', 'rental');
		$config->read();

		if (!$config->config_data['external_db_host'] || !$this->ping($config->config_data['external_db_host']))
		{
			$message = "Database server {$config->config_data['external_db_host']} is not accessible";
			Cache::message_set($message, 'error');
			return false;
		}

		
		$dsn = Db2::CreateDsn([
			'db_type' => $config->config_data['external_db_type'],
			'db_host' => $config->config_data['external_db_host'],
			'db_port' => $config->config_data['external_db_port'],
			'db_name' => $config->config_data['external_db_name']
		]);
		


		try
		{
			$db = new Db2($dsn, $config->config_data['external_db_user'], $config->config_data['external_db_password'], [
				PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_EMULATE_PREPARES => false,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
			]);
		}
		catch (Exception $e)
		{
			Cache::message_set('Could not connect to backend-server ' . $config->config_data['external_db_host'], 'error');
		}
		$this->db = $db;
		return $db;
	}

	public function populate_result_units(array $unit_ids)
	{
		$this->log(__class__, __function__);

		$columns = "V_ORG_ENHET.ORG_ENHET_ID, V_ORG_ENHET.ORG_NAVN, V_ORG_ENHET.RESULTATENHET";
		$table = "V_ORG_ENHET";

		if (!$db = $this->get_db())
		{
			return array();
		}

		$result_units = array();

		$unit_ids_string = implode(',', $unit_ids);


		$sql = "SELECT $columns FROM $table WHERE V_ORG_ENHET.ORG_ENHET_ID IN ($unit_ids_string)";
		//var_dump($sql);
		if ($db->Type == 'postgres')
		{
			$sql = strtolower($sql);
		}
		$db->query($sql, __LINE__, __FILE__);

		while ($db->next_record())
		{
			if ($db->Type == 'postgres')
			{
				$result_units[] = array(
					"ORG_UNIT_ID" => (int)$db->f('org_enhet_id'),
					"ORG_NAME" => $db->f('org_navn'),
					"UNIT_ID" => $db->f('resultatenhet'),
					"LEADER" => false
				);
			}
			else
			{
				$result_units[] = array(
					"ORG_UNIT_ID" => (int)$db->f('ORG_ENHET_ID'),
					"ORG_NAME" => $db->f('ORG_NAVN'),
					"UNIT_ID" => $db->f('RESULTATENHET'),
					"LEADER" => false
				);
			}
		}

		return $result_units;
	}

	/**
	 * Method for retrieving the result units this user has access to
	 *
	 * @param string $username the username
	 * @return an array of (result unit number => result unit name)
	 */
	public function get_result_units($username)
	{
		$this->log(__class__, __function__);

		/* 1. Get all organizational units this user has access to
			 * 2. Check level for each unit and traverse if necessary
			 * 3. Build an array of result units this user has access to
			 */
		$result_units = array();
		$org_unit_ids = array();

		$columns = "V_ORG_ENHET.ORG_ENHET_ID, V_ORG_ENHET.ORG_NIVAA, V_ORG_ENHET.ORG_NAVN, V_ORG_ENHET.ENHET_ID, V_ORG_ENHET.RESULTATENHET";
		$table = "V_ORG_ENHET";
		$joins = "LEFT JOIN V_ORG_PERSON_ENHET ON (V_ORG_PERSON_ENHET.ORG_ENHET_ID = V_ORG_ENHET.ORG_ENHET_ID) " .
			"LEFT JOIN V_ORG_PERSON ON (V_ORG_PERSON.ORG_PERSON_ID = V_ORG_PERSON_ENHET.ORG_PERSON_ID)";

		$sql = "SELECT $columns FROM $table $joins WHERE V_ORG_PERSON.BRUKERNAVN = '$username'";
		//var_dump($sql);

		if (!$db = $this->get_db())
		{
			return array();
		}

		$db1 = clone ($db);
		//var_dump($db->Type);
		if ($db->Type == "postgres")
		{
			$sql = strtolower($sql);
		}
		//var_dump($sql);
		$db->query($sql, __LINE__, __FILE__);



		while ($db->next_record())
		{
			if ($db->Type == "postgres")
			{
				$identifier = (int)$db->f('org_enhet_id');
				$level = (int)$db->f('org_nivaa', 'int');
				$name = $db->f('org_navn');
				$unit_id = $db->f('resultatenhet');
			}
			else
			{
				$identifier = (int)$db->f('ORG_ENHET_ID');
				$level = (int)$db->f('ORG_NIVAA', 'int');
				$name = $db->f('ORG_NAVN');
				$unit_id = $db->f('RESULTATENHET');
			}

			switch ($level)
			{
				case 1:
					break; // TODO: Access to all result units
				case 2:   // LEVEL: Byrådsavdeling
					//Must traverse down the hierarchy
					$columns = "V_ORG_ENHET.ORG_ENHET_ID, V_ORG_ENHET.ORG_NIVAA, V_ORG_ENHET.ORG_NAVN, V_ORG_ENHET.ENHET_ID, V_ORG_ENHET.RESULTATENHET";
					$tables = "V_ORG_ENHET";
					$joins = "LEFT JOIN V_ORG_KNYTNING ON (V_ORG_KNYTNING.ORG_ENHET_ID = V_ORG_ENHET.ORG_ENHET_ID)";
					$sql = "SELECT $columns FROM $tables $joins WHERE V_ORG_ENHET.ORG_NIVAA = 4 AND V_ORG_KNYTNING.ORG_ENHET_ID_KNYTNING = {$identifier}";

					if ($db1->Type == "postgres")
					{
						$sql = strtolower($sql);
					}
					$db1->query($sql, __LINE__, __FILE__);
					while ($db1->next_record())
					{
						if ($db1->Type == "postgres")
						{
							if (!isset($org_unit_ids[(int)$db1->f('org_enhet_id')]))
							{
								$result_units[] = array(
									"ORG_UNIT_ID" => (int)$db1->f('org_enhet_id'),
									"ORG_NAME" => $db1->f('org_navn'),
									"UNIT_ID" => $db1->f('resultatenhet'),
									"LEADER" => true
								);

								$org_unit_ids[(int)$db1->f('org_enhet_id')] = true;
							}
						}
						else
						{
							if (!isset($org_unit_ids[(int)$db1->f('ORG_ENHET_ID')]))
							{
								$result_units[] = array(
									"ORG_UNIT_ID" => (int)$db1->f('ORG_ENHET_ID'),
									"ORG_NAME" => $db1->f('ORG_NAVN'),
									"UNIT_ID" => $db1->f('RESULTATENHET'),
									"LEADER" => true
								);

								$org_unit_ids[(int)$db1->f('ORG_ENHET_ID')] = true;
							}
						}
					}
					break;
				case 3:
					break; // LEVEL: Seksjon (not in use)
				case 4:   // LEVEL: Resultatenhet
					//Insert in result array
					if (!isset($org_unit_ids[$identifier]))
					{
						$result_units[] = array(
							"ORG_UNIT_ID" => $identifier,
							"ORG_NAME" => $name,
							"UNIT_ID" => $unit_id,
							"LEADER" => true
						);
						$org_unit_ids[$identifier] = true;
					}
					break;
			}
		}

		return $result_units;
		/*
			  return array(
			  array(
			  "ORG_UNIT_ID" => 1,
			  "ORG_NAME" => "Seksjon informasjon",
			  "UNIT_ID" => "0130"
			  ),
			  array(
			  "ORG_UNIT_ID" => 1,
			  "ORG_NAME" => "Byrådsleders avdeling, stab",
			  "UNIT_ID" => "0133"
			  )
			  );
			 */
	}

	/**
	 *
	 * @param int $number the result unit number
	 */
	public function get_organisational_unit_name($number)
	{
		$this->log(__class__, __function__);

		if (isset($number) && is_numeric($number))
		{
			$sql = "SELECT V_ORG_ENHET.ORG_NAVN FROM V_ORG_ENHET WHERE V_ORG_ENHET.RESULTATENHET = $number";
			if (!$db = $this->get_db())
			{
				return;
			}

			if ($db->Type == "postgres")
			{
				$sql = strtolower($sql);
			}
			$db->query($sql, __LINE__, __FILE__);
			if ($db->num_rows() > 0)
			{
				$db->next_record();
				if ($db->Type == "postgres")
				{
					return $db->f('org_navn', true);
				}
				else
				{
					return $db->f('ORG_NAVN', true);
				}
			}
		}
		else
		{
			return lang('no_name_organisational_unit');
		}
		//return "No name";
	}

	public function get_organisational_unit_info($number)
	{
		$this->log(__class__, __function__);

		if (isset($number) && is_numeric($number))
		{
			$sql = "SELECT V_ORG_ENHET.ORG_NAVN, V_ORG_ENHET.RESULTATENHET FROM V_ORG_ENHET WHERE V_ORG_ENHET.ORG_ENHET_ID = $number";
			if (!$db = $this->get_db())
			{
				return;
			}

			if ($db->Type == "postgres")
			{
				$sql = strtolower($sql);
			}
			$db->query($sql, __LINE__, __FILE__);
			if ($db->num_rows() > 0)
			{
				$db->next_record();
				if ($db->Type == "postgres")
				{
					return array(
						'UNIT_NAME' => $db->f('org_navn', true),
						'UNIT_NUMBER' => $db->f('resultatenhet', true)
					);
				}
				else
				{
					return array(
						'UNIT_NAME' => $db->f('ORG_NAVN', true),
						'UNIT_NUMBER' => $db->f('RESULTATENHET', true)
					);
				}
			}
		}
		else
		{
			return lang('no_name_organisational_unit');
		}
	}

	/**
	 * Get user info from Fellesdata based on a username
	 *
	 * @param $username the username in question
	 * @return an array containing username, firstname, lastname and email if user exist, false otherwise
	 */
	public function get_user(string $username)
	{
		$this->log(__class__, __function__);

		$sql = "SELECT BRUKERNAVN, FORNAVN, ETTERNAVN, EPOST, RESSURSNR FROM FELLESDATA.V_PORTICO_ANSATT WHERE BRUKERNAVN = '{$username}'";

		if (!$db = $this->get_db())
		{
			return;
		}

		if ($db->Type == "postgres")
		{
			$sql = strtolower($sql);
		}
		$db->query($sql, __LINE__, __FILE__);
		if ($db->num_rows() > 0)
		{
			$db->next_record();
			if ($db->Type == "postgres")
			{
				return array(
					'username' => $db->f('brukernavn', true),
					'firstname' => $db->f('fornavn', true),
					'lastname' => $db->f('etternavn', true),
					'email' => $db->f('epost', true),
					'ressursnr' => $db->f('ressursnr', true)
				);
			}
			else
			{
				return array(
					'username' => $db->f('BRUKERNAVN', true),
					'firstname' => $db->f('FORNAVN', true),
					'lastname' => $db->f('ETTERNAVN', true),
					'email' => $db->f('EPOST', true),
					'ressursnr' => $db->f('RESSURSNR'),
				);
			}
		}
		else
		{
			return false;
		}
	}

	protected function log($class, $function)
	{
		$serverSettings = Settings::getInstance()->get('server');

		if (isset($serverSettings['log_levels']['module']['frontend']) && $serverSettings['log_levels']['module']['frontend'])
		{
			$log = new Log();
			$bt = debug_backtrace();
			$log->debug(array(
				'text' => "{$class}::{$function}() called from file: {$bt[1]['file']} line: {$bt[1]['line']}",
				'p1' => '',
				'p2' => '',
				'line' => __LINE__,
				'file' => __FILE__
			));
			unset($bt);
		}
	}
}
