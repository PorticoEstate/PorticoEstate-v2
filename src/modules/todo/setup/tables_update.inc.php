<?php
	/**
	* Todo - setup
	*
	* @author Joseph Engo <jengo@phpgroupware.org>
	* @copyright Copyright (C) 2000-2005 Free Software Foundation, Inc. http://www.fsf.org/
	* @license http://www.gnu.org/licenses/gpl.html GNU General Public License
	* @package todo
	* @subpackage setup
	* @version $Id$
	*/

	/**
	 * Update from 0.9.2 to 0.9.3
	 * 
	 * @param string $table Table name
	 * @param string $field Field name
	 */
	function todo_v0_9_2to0_9_3update_owner($table, $field, $oProc)
	{
		$oProc->query("select distinct($field) from $table");
		$owner = array();
		if ($oProc->num_rows())
		{
			while ($oProc->next_record())
			{
				$owner[count($owner)] = $oProc->f($field);
			}
			if($oProc->alessthanb($GLOBALS['setup_info']['phpgwapi']['currentver'],'0.9.10pre4'))
			{
				$acctstbl = 'accounts';
			}
			else
			{
				$acctstbl = 'phpgw_accounts';
			}
			for($i=0;$i<count($owner);$i++)
			{
				$oProc->query("SELECT account_id FROM $acctstbl WHERE account_lid='".$owner[$i]."'");
				$oProc->next_record();
				$oProc->query("UPDATE $table SET $field=".$oProc->f("account_id")." WHERE $field='".$owner[$i]."'");
			}
		}
		$oProc->AlterColumn($table, $field, array('type' => 'int', 'precision' => 4, 'nullable' => false, 'default' => 0));
	}

	$test[] = '0.9.1';
	function todo_upgrade0_9_1($oProc)
	{
		return '0.9.2';
	}

	$test[] = '0.9.2';
	function todo_upgrade0_9_2($oProc)
	{
		return '0.9.3pre1';
	}

	$test[] = '0.9.3pre1';
	function todo_upgrade0_9_3pre1($oProc)
	{
		todo_v0_9_2to0_9_3update_owner('todo','todo_owner', $oProc);

		return '0.9.3pre2';
	}

	$test[] = '0.9.3pre2';
	function todo_upgrade0_9_3pre2($oProc)
	{
		return '0.9.3pre3';
	}

	$test[] = '0.9.3pre3';
	function todo_upgrade0_9_3pre3($oProc)
	{
		$oProc->AddColumn("todo", "todo_id_parent", array("type" => "int", "precision" => 4, "nullable" => false, "default" => "0"));

		return '0.9.3pre4';
	}

	$test[] = '0.9.3pre4';
	function todo_upgrade0_9_3pre4($oProc)
	{
		return '0.9.3pre4';
	}

	$test[] = '0.9.3pre5';
	function todo_upgrade0_9_3pre5($oProc)
	{
		return '0.9.3pre6';
	}

	$test[] = '0.9.3pre6';
	function todo_upgrade0_9_3pre6($oProc)
	{
		return '0.9.3pre7';
	}

	$test[] = '0.9.3pre7';
	function todo_upgrade0_9_3pre7($oProc)
	{
		return '0.9.3pre8';
	}

	$test[] = '0.9.3pre8';
	function todo_upgrade0_9_3pre8($oProc)
	{
		return '0.9.3pre9';
	}

	$test[] = '0.9.3pre9';
	function todo_upgrade0_9_3pre9($oProc)
	{
		return '0.9.3pre10';
	}

	$test[] = '0.9.3pre10';
	function todo_upgrade0_9_3pre10($oProc)
	{
		return '0.9.3';
	}

	$test[] = '0.9.3';
	function todo_upgrade0_9_3($oProc)
	{
		return '0.9.4pre1';
	}

	$test[] = '0.9.4pre1';
	function todo_upgrade0_9_4pre1($oProc)
	{
		return '0.9.4pre2';
	}

	$test[] = '0.9.4pre2';
	function todo_upgrade0_9_4pre2($oProc)
	{
		return '0.9.4pre3';
	}

	$test[] = '0.9.4pre3';
	function todo_upgrade0_9_4pre3($oProc)
	{
		$oProc->AddColumn("todo", "todo_startdate", array("type" => "int", "precision" => 4));
		$oProc->RenameColumn("todo", "todo_datedue", "todo_enddate");

		return '0.9.4pre4';
	}

	$test[] = '0.9.4pre4';
	function todo_upgrade0_9_4pre4($oProc)
	{
		return '0.9.4pre5';
	}

	$test[] = '0.9.4pre5';
	function todo_upgrade0_9_4pre5($oProc)
	{
		return '0.9.4';
	}

	$test[] = '0.9.4';
	function todo_upgrade0_9_4($oProc)
	{
		return '0.9.5pre1';
	}


	$test[] = '0.9.5pre1';
	function todo_upgrade0_9_5pre1($oProc)
	{
		return '0.9.5pre2';
	}

	$test[] = '0.9.5pre2';
	function todo_upgrade0_9_5pre2($oProc)
	{
		return '0.9.5';
	}

	$test[] = '0.9.5';
	function todo_upgrade0_9_5($oProc)
	{
		return '0.9.6';
	}

	$test[] = '0.9.6';
	function todo_upgrade0_9_6($oProc)
	{
		return '0.9.7pre1';
	}

	$test[] = '0.9.7pre1';
	function todo_upgrade0_9_7pre1($oProc)
	{
		return '0.9.7pre2';
	}

	$test[] = '0.9.7pre2';
	function todo_upgrade0_9_7pre2($oProc)
	{
		return '0.9.7pre3';
	}

	$test[] = '0.9.7pre3';
	function todo_upgrade0_9_7pre3($oProc)
	{
		return '0.9.7';
	}

	$test[] = '0.9.7';
	function todo_upgrade0_9_7($oProc)
	{
		return '0.9.8pre1';
	}

	$test[] = '0.9.8pre1';
	function todo_upgrade0_9_8pre1($oProc)
	{
		return '0.9.8pre2';
	}

	$test[] = '0.9.8pre2';
	function todo_upgrade0_9_8pre2($oProc)
	{
		return '0.9.8pre3';
	}

	$test[] = '0.9.8pre3';
	function todo_upgrade0_9_8pre3($oProc)
	{
		return '0.9.8pre4';
	}

	$test[] = '0.9.8pre4';
	function todo_upgrade0_9_8pre4($oProc)
	{
		return '0.9.8pre5';
	}

	$test[] = '0.9.8pre5';
	function todo_upgrade0_9_8pre5($oProc)
	{
		return '0.9.9pre1';
	}

	$test[] = '0.9.9pre1';
	function todo_upgrade0_9_9pre1($oProc)
	{
		return '0.9.9';
	}

	$test[] = '0.9.9';
	function todo_upgrade0_9_9($oProc)
	{
		return '0.9.10pre1';
	}

	$test[] = '0.9.10pre1';
	function todo_upgrade0_9_10pre1($oProc)
	{
		return '0.9.10pre2';
	}

	$test[] = '0.9.10pre2';
	function todo_upgrade0_9_10pre2($oProc)
	{
		return '0.9.10pre3';
	}

	$test[] = '0.9.10pre3';
	function todo_upgrade0_9_10pre3($oProc)
	{
		return '0.9.10pre4';
	}

	$test[] = '0.9.10pre4';
	function todo_upgrade0_9_10pre4($oProc)
	{
		return '0.9.10pre5';
	}

	$test[] = '0.9.10pre5';
	function todo_upgrade0_9_10pre5($oProc)
	{
		return '0.9.10pre6';
	}

	$test[] = '0.9.10pre6';
	function todo_upgrade0_9_10pre6($oProc)
	{
		return '0.9.10pre7';
	}

	$test[] = '0.9.10pre7';
	function todo_upgrade0_9_10pre7($oProc)
	{
		return '0.9.10pre8';
	}

	$test[] = '0.9.10pre8';
	function todo_upgrade0_9_10pre8($oProc)
	{
		return '0.9.10pre9';
	}

	$test[] = '0.9.10pre9';
	function todo_upgrade0_9_10pre9($oProc)
	{
		return '0.9.10pre10';
	}

	$test[] = '0.9.10pre10';
	function todo_upgrade0_9_10pre10($oProc)
	{
		return '0.9.10pre11';
	}

	$test[] = '0.9.10pre11';
	function todo_upgrade0_9_10pre11($oProc)
	{
		return '0.9.10pre12';
	}

	$test[] = '0.9.10pre12';
	function todo_upgrade0_9_10pre12($oProc)
	{
		return '0.9.10pre13';
	}

	$test[] = '0.9.10pre13';
	function todo_upgrade0_9_10pre13($oProc)
	{
		return '0.9.10pre14';
	}

	$test[] = '0.9.10pre14';
	function todo_upgrade0_9_10pre14($oProc)
	{
		return '0.9.10pre15';
	}

	$test[] = '0.9.10pre15';
	function todo_upgrade0_9_10pre15($oProc)
	{
		return '0.9.10pre16';
	}

	$test[] = '0.9.10pre16';
	function todo_upgrade0_9_10pre16($oProc)
	{
		return '0.9.10pre17';
	}

	$test[] = '0.9.10pre17';
	function todo_upgrade0_9_10pre17($oProc)
	{
		return '0.9.10pre18';
	}

	$test[] = '0.9.10pre18';
	function todo_upgrade0_9_10pre18($oProc)
	{
		return '0.9.10pre19';
	}

	$test[] = '0.9.10pre19';
	function todo_upgrade0_9_10pre19($oProc)
	{
		return '0.9.10pre20';
	}

	$test[] = '0.9.10pre20';
	function todo_upgrade0_9_10pre20($oProc)
	{
		return '0.9.10pre21';
	}

	$test[] = '0.9.10pre21';
	function todo_upgrade0_9_10pre21($oProc)
	{
		return '0.9.10pre22';
	}

	$test[] = '0.9.10pre22';
	function todo_upgrade0_9_10pre22($oProc)
	{
		$oProc->RenameTable('todo','phpgw_todo');

		return '0.9.10pre23';
	}

	$test[] = '0.9.10pre23';
	function todo_upgrade0_9_10pre23($oProc)
	{
		return '0.9.10pre24';
	}

	$test[] = '0.9.10pre24';
	function todo_upgrade0_9_10pre24($oProc)
	{
		return '0.9.10pre25';
	}

	$test[] = '0.9.10pre25';
	function todo_upgrade0_9_10pre25($oProc)
	{
		return '0.9.10pre26';
	}

	$test[] = '0.9.10pre26';
	function todo_upgrade0_9_10pre26($oProc)
	{
		$oProc->AddColumn('phpgw_todo','todo_cat',array('type' => 'int','precision' => 4,'nullable' => True));

		return '0.9.10pre27';
	}

	$test[] = '0.9.10pre27';
	function todo_upgrade0_9_10pre27($oProc)
	{
		return '0.9.10pre28';
	}

	$test[] = '0.9.10pre28';
	function todo_upgrade0_9_10pre28($oProc)
	{
		return '0.9.10';
	}

	$test[] = '0.9.10';
	function todo_upgrade0_9_10($oProc)
	{
		return '0.9.11.001';
	}

	$test[] = '0.9.11';
	function todo_upgrade0_9_11($oProc)
	{
		return '0.9.11.001';
	}

	$test[] = '0.9.11.001';
	function todo_upgrade0_9_11_001($oProc)
	{
		return '0.9.11.002';
	}

	$test[] = '0.9.11.003';
	function todo_upgrade0_9_11_003($oProc)
	{
		return '0.9.11.004';
	}

	$test[] = '0.9.11.004';
	function todo_upgrade0_9_11_004($oProc)
	{
		return '0.9.11.005';
	}

	$test[] = '0.9.11.005';
	function todo_upgrade0_9_11_005($oProc)
	{
		return '0.9.11.006';
	}

	$test[] = '0.9.11.006';
	function todo_upgrade0_9_11_006($oProc)
	{
		return '0.9.11.007';
	}

	$test[] = '0.9.11.007';
	function todo_upgrade0_9_11_007($oProc)
	{
		return '0.9.11.008';
	}

	$test[] = '0.9.11.008';
	function todo_upgrade0_9_11_008($oProc)
	{
		return '0.9.11.009';
	}

	$test[] = '0.9.11.009';
	function todo_upgrade0_9_11_009($oProc)
	{
		return '0.9.11.010';
	}

	$test[] = '0.9.11.010';
	function todo_upgrade0_9_11_010($oProc)
	{
		return '0.9.11.011';
	}

	$test[] = '0.9.11.011';
	function todo_upgrade0_9_11_011($oProc)
	{
		return '0.9.13.001';
	}

	$test[] = '0.9.13.001';
	function todo_upgrade0_9_13_001($oProc)
	{
		return '0.9.13.002';
	}

	$test[] = '0.9.13.002';
	function todo_upgrade0_9_13_002($oProc)
	{
		$oProc->AddColumn('phpgw_todo','todo_id_main',array('type' => 'int','precision' => 4,'default' => 0,'nullable' => False));
		$oProc->AddColumn('phpgw_todo','todo_level',array('type' => 'int','precision' => 2,'default' => 0,'nullable' => False));
		$oProc->AlterColumn('phpgw_todo','todo_id_parent',array('type' => 'int','precision' => 4,'default' => 0,'nullable' => False));
		$oProc->AlterColumn('phpgw_todo','todo_cat',array('type' => 'int','precision' => 4,'default' => 0,'nullable' => False));
		$oProc->AlterColumn('phpgw_todo','todo_enddate',array('type' => 'int','precision' => 4,'default' => 0,'nullable' => False));

		$db = $oProc->db;

		$oProc->query("select todo_id from phpgw_todo where todo_id_main='0'");

		while ($oProc->next_record())
		{
			$db->query("update phpgw_todo set todo_id_main='" . $oProc->f('todo_id') . "' "
						. "where todo_id='" . $oProc->f('todo_id') . "'");

		}

		$oProc->query("select todo_id_parent from phpgw_todo");

		while ($oProc->next_record())
		{
			if ($oProc->f('todo_id_parent') != 0)
			{
				$db->query("update phpgw_todo set todo_id_main='" . $oProc->f('todo_id_parent') . "',"
							. "todo_level='1' where todo_id_parent='" . $oProc->f('todo_id_parent') . "'");
			}
		}

		return '0.9.13.003';
	}

	$test[] = '0.9.13.003';
	function todo_upgrade0_9_13_003($oProc)
	{
		$oProc->AddColumn('phpgw_todo','todo_title',array('type' => 'varchar','precision' => 255,'nullable' => False));
		$oProc->AlterColumn('phpgw_todo','todo_owner',array('type' => 'int','precision' => 4,'default' => 0,'nullable' => False));

		return '0.9.13.004';
	}

	$test[] = '0.9.13.004';
	function todo_upgrade0_9_13_004($oProc)
	{
		return '0.9.15.001';

	}

	$test[] = '0.9.14';
	function todo_upgrade0_9_14($oProc)
	{
		return '0.9.15.001';
	}

	$test[] = '0.9.14.500';
	function todo_upgrade0_9_14_500($oProc)
	{
		return '0.9.15.001';
	}

	$test[] = '0.9.15.001';
	function todo_upgrade0_9_15_001($oProc)
	{
		$oProc->m_odb->transaction_begin();
		$oProc->AddColumn('phpgw_todo','todo_assigned',array('type' => 'varchar','precision' => 255,'nullable' => False));
		$oProc->AddColumn('phpgw_todo','assigned_group',array('type' => 'varchar','precision' => 255,'nullable' => False));

		if($oProc->m_odb->transaction_commit())
		{
			return '0.9.15.002';
		}
	}

	$test[] = '0.9.15.002';
	function todo_upgrade0_9_15_002($oProc)
	{
		$oProc->m_odb->transaction_begin();

		$oProc->AddColumn('phpgw_todo','entry_date',array('type' => 'int','precision' => 4,'default' => 0,'nullable' => False));

		if($oProc->m_odb->transaction_commit())
		{
			return '0.9.15.003';
		}
	}
