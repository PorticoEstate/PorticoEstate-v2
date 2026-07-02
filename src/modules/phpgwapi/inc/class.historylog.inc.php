<?php

/**
 * Record history logging
 * @author Joseph Engo <jengo@phpgroupware.org>
 * @copyright Copyright (C) 2001-2004 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.fsf.org/licenses/lgpl.html GNU Lesser General Public License
 * @package phpgwapi
 * @subpackage application
 * @version $Id$
 */

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\controllers\Locations;
use App\Database\Db;
use App\modules\phpgwapi\controllers\Accounts\Accounts;


/**
 * Record history logging
 * 
 * @package phpgwapi
 * @subpackage application
 */
class phpgwapi_historylog
{
	var $db;
	protected $location_id;
	var $template;
	var $nextmatchs;
	var $phpgwapi_common;
	var $types = array(
		'C' => 'Created',
		'D' => 'Deleted',
		'E' => 'Edited'
	);
	var $alternate_handlers = array();

	private function apply_alternate_handler($handler, $value)
	{
		if (!$handler)
		{
			return $value;
		}

		if (is_callable($handler))
		{
			return call_user_func($handler, $value);
		}

		if (is_string($handler))
		{
			if (preg_match('/^\$this->([a-zA-Z_][a-zA-Z0-9_]*)->([a-zA-Z_][a-zA-Z0-9_]*)$/', $handler, $matches))
			{
				$property = $matches[1];
				$method = $matches[2];
				if (isset($this->$property) && is_object($this->$property) && is_callable(array($this->$property, $method)))
				{
					return $this->$property->$method($value);
				}
			}

			if (function_exists($handler))
			{
				return $handler($value);
			}
		}

		return $value;
	}

	function __construct($appname, $location = '.')
	{
		$flags = Settings::getInstance()->get('flags');
		if (! $appname)
		{
			$appname = $flags['currentapp'];
		}
		$locations_obj = new Locations();

		$location_id = $locations_obj->get_id($appname, $location);
		$this->location_id = (int) $location_id;
		$this->db      = Db::getInstance();
		$this->phpgwapi_common = new \phpgwapi_common();

	}

	function delete($record_id)
	{
		$this->db->query("DELETE FROM phpgw_history_log WHERE history_record_id='" . (int)$record_id . "' and "
			. "location_id=" . (int)$this->location_id, __LINE__, __FILE__);
	}

	function add($status, $record_id, $new_value, $old_value = '')
	{
		$userSettings = Settings::getInstance()->get('user');

		if ($new_value != $old_value)
		{

			$value_set = array(
				'history_record_id'	=> (int)$record_id,
				'location_id'		=> $this->location_id,
				'history_owner'		=> $userSettings['account_id'],
				'history_status'	=> $status,
				'history_new_value'	=> $this->db->db_addslashes($new_value),
				'history_old_value'	=> $this->db->db_addslashes($old_value),
				'history_timestamp'	=> date($this->db->datetime_format())
			);

			$this->db->query('INSERT INTO phpgw_history_log (' .  implode(',', array_keys($value_set))   . ') VALUES ('
				. $this->db->validate_insert(array_values($value_set)) . ')', __LINE__, __FILE__);
		}
	}

	function return_array($filter_out = array(), $only_show = array(), $_orderby = '', $sort = '', $record_id = 0)
	{
		$record_id = (int) $record_id;

		if (!$record_id)
		{
			return array();
		}

		if (! $sort || ! $_orderby)
		{
			$orderby = 'ORDER BY history_timestamp, history_id';
		}
		else
		{
			$orderby = "ORDER BY $_orderby $sort";
		}

		$filter = '';
		if (count($filter_out))
		{
			$filtered = array();
			foreach ($filter_out as $_filter)
			{
				$filtered[] = "history_status != '" . $this->db->db_addslashes($_filter) . "'";
			}
			$filter = ' AND ' . implode(' AND ', $filtered);
		}

		$only_show_filter = '';
		if (count($only_show))
		{
			$_only_show = array();
			foreach ($only_show as $_filter)
			{
				$_only_show[] = "history_status='$_filter'";
			}
			$only_show_filter = ' AND (' . implode(' OR ', $_only_show) . ')';
		}

		$this->db->query('SELECT * FROM phpgw_history_log'
			. " WHERE location_id= {$this->location_id} AND history_record_id = {$record_id} $filter $only_show_filter "
			. $orderby, __LINE__, __FILE__);

		$return_values = array();
		$accounts_obj = new Accounts();
		while ($this->db->next_record())
		{
			$return_values[] = array(
				'id'		=> $this->db->f('history_id'),
				'record_id'	=> $this->db->f('history_record_id'),
				'owner'		=> $accounts_obj->id2name($this->db->f('history_owner')),
				//					'status'	=> lang($this->types[$this->db->f('history_status')]),
				'status'	=> str_replace(' ', '', $this->db->f('history_status')),
				'new_value'	=> $this->db->f('history_new_value', true),
				'old_value'	=> $this->db->f('history_old_value', true),
				'datetime'	=> $this->db->from_timestamp($this->db->f('history_timestamp')),
				'publish'	=> $this->db->f('publish')
			);
		}
		return $return_values;
	}

	function return_html($filter_out, $orderby = '', $sort = '', $record_id = 0)
	{
		$this->template   = createObject('phpgwapi.template', PHPGW_TEMPLATE_DIR);
		$this->nextmatchs = createObject('phpgwapi.nextmatchs');

		$this->template->set_file('_history', 'history_list.tpl');

		$this->template->set_block('_history', 'row_no_history');
		$this->template->set_block('_history', 'list');
		$this->template->set_block('_history', 'row');

		$this->template->set_var('lang_user', lang('User'));
		$this->template->set_var('lang_date', lang('Date'));
		$this->template->set_var('lang_action', lang('Action'));
		$this->template->set_var('lang_new_value', lang('New Value'));

		$this->template->set_var('sort_date', lang('Date'));
		$this->template->set_var('sort_owner', lang('User'));
		$this->template->set_var('sort_status', lang('Status'));
		$this->template->set_var('sort_new_value', lang('New value'));
		$this->template->set_var('sort_old_value', lang('Old value'));

		$values = $this->return_array($filter_out, array(), $orderby, $sort, $record_id);

		if (! is_array($values))
		{
			$this->template->set_var('lang_no_history', lang('No history for this record'));
			$this->template->fp('rows', 'row_no_history');
			return $this->template->fp('out', 'list');
		}
		$phpgwapi_common = new \phpgwapi_common();
		$i = 0;
		foreach ($values as $value)
		{
			$this->template->set_var('tr_class', $this->nextmatchs->alternate_row_class($i));

			$this->template->set_var('row_date', $phpgwapi_common->show_date($value['datetime']));
			$this->template->set_var('row_owner', $value['owner']);

			if ($this->alternate_handlers[$value['status']])
			{
				$handler = $this->alternate_handlers[$value['status']];
				$this->template->set_var('row_new_value', $this->apply_alternate_handler($handler, $value['new_value']));
				$this->template->set_var('row_old_value', $this->apply_alternate_handler($handler, $value['old_value']));
			}
			else
			{
				$this->template->set_var('row_new_value', stripslashes($value['new_value']));
				$this->template->set_var('row_old_value', stripslashes($value['old_value']));
			}

			$this->template->set_var('row_status', $this->types[$value['status']]);

			$this->template->fp('rows', 'row', True);
		}
		return $this->template->fp('out', 'list');
	}
}
