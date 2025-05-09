<?php

/**
 * phpGroupWare - property: a Facilities Management System.
 *
 * @author Sigurd Nes <sigurdne@online.no>
 * @copyright Copyright (C) 2008 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @internal Development of this application was funded by http://www.bergen.kommune.no/bbb_/ekstern/
 * @package property
 * @subpackage admin
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


/**
 * NOTE: Not finnished yet
 */

use App\Database\Db;
use App\Database\Db2;
use App\modules\phpgwapi\services\Cache;
use App\modules\phpgwapi\services\SchemaProc\SchemaProc;

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\setup\Process;

use App\modules\phpgwapi\controllers\Accounts\Accounts;
use App\modules\phpgwapi\controllers\Locations;
use App\modules\phpgwapi\security\Acl;

/**
 * Description
 * @package property
 */
class property_bomigrate
{

	private $use_session;
	public $start;
	var $acl_location, $oProc;

	public function __construct($session = false)
	{
		$this->acl_location = '.admin';

		if ($session)
		{
			$this->read_sessiondata();
			$this->use_session = true;
		}

		$start = Sanitizer::get_var('start', 'int', 'REQUEST', 0);
	}

	public function save_sessiondata($data)
	{
		if ($this->use_session)
		{
			Cache::session_set('migrate', 'session_data', $data);
		}
	}

	private function read_sessiondata()
	{
		$data = Cache::session_get('migrate', 'session_data');

		//_debug_array($data);
	}

	public function get_acl_location()
	{
		return $this->acl_location;
	}

	public function read()
	{
		$serverSettings = Settings::getInstance()->get('server');
		$phpgw_domain = $this->get_phpgw_domain();
		unset($phpgw_domain[$serverSettings['default_domain']]);
		return $phpgw_domain;
	}

	private function get_phpgw_domain()
	{
		$settings = require SRC_ROOT_PATH . '/../config/header.inc.php';
		$phpgw_domain = $settings['phpgw_domain'];
		return $phpgw_domain;
	}

	public function migrate($values, $download_script = false)
	{

		$tables = Db::getInstance()->table_names();
		$process = new Process();

		$table_def = array();
		$foreign_keys = array();
		//			$tables = array('bb_season_boundary');
		foreach ($tables as $table)
		{
			$tableinfo = $process->sql_to_array($table);
//			_debug_array($table);
//			_debug_array($tableinfo);

			$fd_temp	 = '$fd = array(' . str_replace("\t", '', $tableinfo[0]) . ');';
			//evil..
			try
			{
				eval($fd_temp);
			}
			catch (Exception $exc)
			{
				echo $exc->getMessage();
				$phpgwapi_common = new \phpgwapi_common();
				$phpgwapi_common->phpgw_exit();
			}

			$table_def[$table]['fd'] = $fd;
			$table_def[$table]['pk'] = $tableinfo[1];
			$table_def[$table]['fk'] = array(); //later...  $tableinfo[2];
			$table_def[$table]['ix'] = $tableinfo[3];
			$table_def[$table]['uc'] = $tableinfo[4];
//			_debug_array($table_def);
//			die();

			/* prepare for updating with foreign keys
			 */
			if ($tableinfo[2])
			{
				foreach ($tableinfo[2] as $ref_set => $ref_fields)
				{
					$fk_temp				 = '$fk = array(' . $ref_fields . ');';
					eval($fk_temp);
					$fk_table				 = array_keys($fk);
					//		$ForeignKeys[$table][]	 = $fk_table[0];
					$foreign_keys[$table]['fk']	 = $fk;
				}
			}
		}

		set_time_limit(0);

		$phpgw_domain = $this->get_phpgw_domain();

		foreach ($values as $domain)
		{
			$this->oProc =	new SchemaProc($phpgw_domain[$domain]['db_type']);
			if (!$download_script)
			{

				$db_config = array(
					'db_type' => $phpgw_domain[$domain]['db_type'],
					'db_host' => $phpgw_domain[$domain]['db_host'],
					'db_port' => $phpgw_domain[$domain]['db_port'],
					'db_name' => $phpgw_domain[$domain]['db_name'],
					'db_user' => $phpgw_domain[$domain]['db_user'],
					'db_pass' => $phpgw_domain[$domain]['db_pass'],
				);

				$dsn = Db::CreateDsn($db_config);

				$this->oProc->m_odb		 = new Db2($dsn, $db_config['db_user'], $db_config['db_pass']);
				$this->oProc->m_odb->set_config($db_config);
				$this->oProc->m_odb->Halt_On_Error	 = 'yes';

				if ($this->oProc->m_odb->table_names())
				{
//					throw new Exception("There is already tables in the database '{$this->oProc->m_odb->Database}'");
				}
			}

			if ($download_script)
			{
				$script		 = $this->GenerateScripts($table_def, false, true);
				$filename	 = $domain . '_' . $phpgw_domain[$domain]['db_name'] . '_' . $phpgw_domain[$domain]['db_type'] . '.sql';
				$this->download_script($script, $filename);
			}
			else
			{
				$this->oProc->ExecuteScripts($table_def, true);
				$this->copy_data($table_def);
				$this->oProc->AlterTables($foreign_keys, true);
			}
		}
	}

	function copy_data($table_defs = array())
	{
		$db = Db::getInstance();
		$db->fetchmode = 'ASSOC';

		foreach ($table_defs as $table => $table_def)
		{
			$this->oProc->m_odb->query("SELECT count(*) as cnt FROM {$table}");
			$this->oProc->m_odb->next_record();
			if ($this->oProc->m_odb->f('cnt'))
			{
				_debug_array("Skip copy data to {$table}");
				continue;
			}

			if ($table == 'fm_ecobilagoverf' || $table == 'phpgw_lang')
			{
				continue;
			}
			switch ($table)
			{
				case 'fm_document_history':
				case 'fm_entity_history':
				case 'fm_request_history':
				case 'fm_project_history':
				case 'fm_tts_history':
				case 'fm_s_agreement_history':
				case 'fm_workorder_history':
				case 'phpgw_history_log':
					$db->query("UPDATE {$table} SET history_new_value = 'NIL' WHERE history_new_value = ''", __LINE__, __FILE__, true);
					break;
				case 'fm_project':
					$db->query("UPDATE {$table} SET name = 'NIL' WHERE name = ''", __LINE__, __FILE__, true);
					$db->query("UPDATE {$table} SET loc1 = '0000', location_code = '0000' WHERE loc1 = ''", __LINE__, __FILE__, true);
					$db->query("UPDATE {$table} SET status = 'closed' WHERE status = ''", __LINE__, __FILE__, true);
					break;
				case 'fm_workorder':
					$db->query("UPDATE {$table} SET title = 'NIL' WHERE title = ''", __LINE__, __FILE__, true);
					break;
				case 'fm_ecodimd':
					$db->query("UPDATE {$table} SET descr = 'NIL' WHERE descr = ''", __LINE__, __FILE__, true);
					break;
				case 'phpgw_categories':
					$db->query("UPDATE {$table} SET cat_description = 'NIL' WHERE cat_description = ''", __LINE__, __FILE__, true);
					break;
				case 'phpgw_contact_others':
					$db->query("UPDATE {$table} SET other_value = 'NIL' WHERE other_value = ''", __LINE__, __FILE__, true);
					break;
				case 'phpgw_contact_person':
					$db->query("DELETE FROM {$table} WHERE first_name = ''", __LINE__, __FILE__, true);
					break;
				case 'phpgw_log_msg':
					$db->query("UPDATE {$table} SET log_msg_parms = 'NIL' WHERE log_msg_parms = ''", __LINE__, __FILE__, true);
					$db->query("UPDATE {$table} SET log_msg_file = 'NIL' WHERE log_msg_file = ''", __LINE__, __FILE__, true);
					break;
				case 'phpgw_sms_tbluserphonebook':
					$db->query("UPDATE {$table} SET p_email = 'NIL' WHERE p_email = ''", __LINE__, __FILE__, true);
					break;
				case 'phpgw_sms_tblsmsoutgoing':
					$db->query("UPDATE {$table} SET p_footer = 'NIL' WHERE p_footer = ''", __LINE__, __FILE__, true);
					$db->query("UPDATE {$table} SET p_src = 'NIL' WHERE p_src = ''", __LINE__, __FILE__, true);
					break;
				case 'activity_activity':
					$db->query("UPDATE {$table} SET title = 'NIL' WHERE title = ''", __LINE__, __FILE__, true);
					break;
				case 'activity_arena':
					$db->query("UPDATE {$table} SET arena_name = 'NIL' WHERE arena_name = ''", __LINE__, __FILE__, true);
					break;
				case 'activity_contact_person':
					$db->query("UPDATE {$table} SET email = 'NIL' WHERE email = ''", __LINE__, __FILE__, true);
					$db->query("UPDATE {$table} SET address = 'NIL' WHERE address = ''", __LINE__, __FILE__, true);
					$db->query("UPDATE {$table} SET zipcode = 'NIL' WHERE zipcode = ''", __LINE__, __FILE__, true);
					$db->query("UPDATE {$table} SET city = 'NIL' WHERE city = ''", __LINE__, __FILE__, true);
					$db->query("UPDATE {$table} SET phone = 'NIL' WHERE phone = ''", __LINE__, __FILE__, true);
					$db->query("UPDATE {$table} SET name = 'NIL' WHERE name = ''", __LINE__, __FILE__, true);
					$db->query("UPDATE {$table} SET group_id = 'NIL' WHERE group_id = ''", __LINE__, __FILE__, true);
					$db->query("UPDATE {$table} SET organization_id = 'NIL' WHERE organization_id = ''", __LINE__, __FILE__, true);
					break;
				case 'activity_group':
					$db->query("UPDATE {$table} SET organization_id = 'NIL' WHERE organization_id = ''", __LINE__, __FILE__, true);
					$db->query("UPDATE {$table} SET name = 'NIL' WHERE name = ''", __LINE__, __FILE__, true);
					$db->query("UPDATE {$table} SET description = 'NIL' WHERE description = ''", __LINE__, __FILE__, true);
					$db->query("UPDATE {$table} SET change_type = 'NIL' WHERE change_type = ''", __LINE__, __FILE__, true);
					break;
				case 'activity_organization':
					$db->query("UPDATE {$table} SET name = 'NIL' WHERE name = ''", __LINE__, __FILE__, true);
					$db->query("UPDATE {$table} SET district = 'NIL' WHERE district = ''", __LINE__, __FILE__, true);
					$db->query("UPDATE {$table} SET homepage = 'NIL' WHERE homepage = ''", __LINE__, __FILE__, true);
					$db->query("UPDATE {$table} SET description = 'NIL' WHERE description = ''", __LINE__, __FILE__, true);
					$db->query("UPDATE {$table} SET email = 'NIL' WHERE email = ''", __LINE__, __FILE__, true);
					$db->query("UPDATE {$table} SET phone = 'NIL' WHERE phone = ''", __LINE__, __FILE__, true);
					$db->query("UPDATE {$table} SET address = 'NIL' WHERE address = ''", __LINE__, __FILE__, true);
					$db->query("UPDATE {$table} SET orgno = 'NIL' WHERE orgno = ''", __LINE__, __FILE__, true);
					$db->query("UPDATE {$table} SET change_type = 'NIL' WHERE change_type = ''", __LINE__, __FILE__, true);
					break;
				default:
			}

			$identity_sequence = false;
			if (in_array($this->oProc->m_odb->Type, array('mssql', 'sqlsrv', 'mssqlnative')))
			{
				$this->oProc->m_odb->query("EXEC sp_columns '$table'", __LINE__, __FILE__);
				while ($this->oProc->m_odb->next_record())
				{
					if ($this->oProc->m_odb->f('TYPE_NAME') == 'int identity')
					{
						$identity_sequence = true;
					}
				}
				if ($identity_sequence)
				{
					$this->oProc->m_odb->query("SET identity_insert {$table} ON", __LINE__, __FILE__);
				}
			}

			$this->oProc->m_odb->query("DELETE FROM {$table}");

			$db->query("SELECT * FROM {$table}");

			foreach ($db->resultSet as $row)
			{

				$field_names = array();
				$data = array();
				foreach ($table_def['fd'] as $field_name => $data_field)
				{
					if (isset($data_field['default']) && !empty($data_field['default']) && empty($data_field['nullable']) && empty($row[$field_name]))
					{
						continue;
					}
					else if (!empty($data_field['nullable']) && empty($row[$field_name]))
					{
						continue;
					}
					else if (isset($data_field['default']) && $data_field['default'] === "" && empty($data_field['nullable']) && empty($row[$field_name]))
					{
						$row[$field_name] = 'NIL';
					}
					else if (empty($data_field['nullable']) && $data_field['type'] == 'bool' && empty($row[$field_name]))
					{
						$row[$field_name] = 0;
					}
					else if (empty($data_field['nullable']) && $row[$field_name] === "")
					{
						$row[$field_name] = 'NIL';
					}

					$field_names[] = $field_name;
					switch ($data_field['type'])
					{
						case 'datetime':
						case 'timestamp':
							$data[] = $this->oProc->m_odb->to_timestamp(strtotime($row[$field_name]) + 1);
							break;
						case 'date':
							$data[] = date('Y-m-d', strtotime($row[$field_name]));
							break;
						case 'time':
							$data[] = date('H:i:s', strtotime($row[$field_name]));
							break;
						default:
							$data[] = $row[$field_name];
					}
				}

				$insert_values	 = $this->oProc->m_odb->validate_insert($data);
				$insert_fields	 = implode(',', $field_names);
				$this->oProc->m_odb->query("INSERT INTO {$table} ({$insert_fields}) VALUES ({$insert_values})");
			}

			if ($identity_sequence && in_array($this->oProc->m_odb->Type, array('mssql', 'sqlsrv', 'mssqlnative')))
			{
				$this->oProc->m_odb->query("SET identity_insert {$table} OFF", __LINE__, __FILE__);
			}
		}
	}

	private function download_script($script, $filename)
	{
		Settings::getInstance()->update('flags', ['noheader' => true, 'nofooter' => true, 'xslt_app' => false]);

		$browser = CreateObject('phpgwapi.browser');
		$size	 = strlen($script);
		$browser->content_header($filename, '', $size);
		echo $script;
	}

	/**
	 * Generate Script for db-schema
	 *
	 * @param array	$aTables 		array holding schema definition for the database
	 * @param bool	$bOutputHTML	print to browser - or not
	 * @param bool	$return_script  return sql-sqript - or not
	 *
	 * @return string sql-script for generate database for chosen db-platform.
	 */
	function GenerateScripts($aTables, $bOutputHTML = false, $return_script = false)
	{
		if (!is_array($aTables))
		{
			return false;
		}
		$this->oProc->m_aTables = $aTables;

		$sAllTableSQL = '';
		foreach ($this->oProc->m_aTables as $sTableName => $aTableDef)
		{
			$sSequenceSQL							 = '';
			$sTriggerSQL							 = '';
			$sTableSQL								 = '';
			$this->oProc->m_oTranslator->indexes_sql = array();
			if ($this->oProc->_GetTableSQL($sTableName, $aTableDef, $sTableSQL, $sSequenceSQL, $sTriggerSQL))
			{
				$sTableSQL = "CREATE TABLE $sTableName (\n$sTableSQL\n)"
					. $this->oProc->m_oTranslator->m_sStatementTerminator;
				if ($sSequenceSQL != '')
				{
					$sAllTableSQL .= $sSequenceSQL . "\n";
				}

				if ($sTriggerSQL != '')
				{
					$sAllTableSQL .= $sTriggerSQL . "\n";
				}

				$sAllTableSQL .= $sTableSQL . "\n\n";

				// postgres and mssql
				if (isset($this->oProc->m_oTranslator->indexes_sql) && is_array($this->oProc->m_oTranslator->indexes_sql) && count($this->oProc->m_oTranslator->indexes_sql) > 0)
				{
					foreach ($this->oProc->m_oTranslator->indexes_sql as $key => $sIndexSQL)
					{
						$ix_name		 = $key . '_' . $sTableName . '_idx';
						$IndexSQL		 = str_replace(array('__index_name__', '__table_name__'), array(
							$ix_name, $sTableName
						), $sIndexSQL);
						$sAllTableSQL	 .= $IndexSQL . "\n\n";
					}
				}
			}
			else
			{
				if ($bOutputHTML)
				{
					print('<br>Failed generating script for <b>' . $sTableName . '</b><br>');
					echo '<pre style="text-align: left;">' . $sTableName . ' = ';
					print_r($aTableDef);
					echo "</pre>\n";
				}

				return false;
			}
		}

		if ($bOutputHTML)
		{
			print('<pre>' . $sAllTableSQL . '</pre><br><br>');
		}

		if ($return_script)
		{
			return $sAllTableSQL;
		}
	}
}
