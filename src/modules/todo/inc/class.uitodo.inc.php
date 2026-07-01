<?php

/**
 * Todo user interface
 *
 * @author Joseph Engo <jengo@phpgroupware.org>
 * @author Bettina Gille [ceb@phpgroupware.org]
 * @copyright Copyright (C) 2000-2003,2005 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @package todo
 * @version $Id$
 */

use App\helpers\Template;
use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\security\Acl;
use App\modules\phpgwapi\controllers\Accounts\Accounts;

/*
	* Import required classes
	*/

phpgw::import_class('phpgwapi.sbox');
phpgw::import_class('phpgwapi.uicommon_jquery');

/**
 * Todo user interface
 *  
 * @package todo
 */
class todo_uitodo extends phpgwapi_uicommon_jquery
{
	var $grants;
	var $historylog;
	var $t;
	var $public_functions = array(
		'show_list'	=> True,
		'view'      => True,
		'add'       => True,
		'edit'      => True,
		'delete'	=> True,
		'matrix'	=> True
	);

	var $botodo;
	var $cats;
	var $matrix;
	var $account;
	var $start;
	var $query;
	var $filter;
	var $order;
	var $sort;
	var $cat_id;
	var $nextmatchs;
	var $phpgwapi_common;
	var $userSettings;

	function __construct()
	{
		parent::__construct('todo');

		$this->userSettings = Settings::getInstance()->get('user');
		$this->botodo		= CreateObject('todo.botodo', True);
		$this->nextmatchs	= CreateObject('phpgwapi.nextmatchs');
		$this->historylog	= CreateObject('phpgwapi.historylog', 'todo');
		$this->historylog->types = array(
			'A' => lang('Entry added'),
			'C' => lang('Category changed'),
			'S' => lang('Start date changed'),
			'E' => lang('End date changed'),
			'U' => lang('Urgency changed'),
			's' => lang('Status changed'),
			'T' => lang('Title changed'),
			'D' => lang('Description changed'),
			'a' => lang('Access changed'),
			'P' => lang('Parent changed')
		);

		$this->historylog->alternate_handlers = array(
			'S' => '$this->phpgwapi_common->show_date',
			'E' => '$this->phpgwapi_common->show_date',
//			'C' => '$GLOBALS[\'phpgw\']->categories->id2name'
		);

		$this->cats       = CreateObject('phpgwapi.categories');

		$this->phpgwapi_common = new \phpgwapi_common();

		$this->account    = $this->userSettings['account_id'];
		$this->t          = Template::getInstance($this->phpgwapi_common->get_tpl_dir('todo'));
		$this->grants     = Acl::getInstance()->get_grants('todo', '.');

		$this->start      = $this->botodo->start;
		$this->query      = $this->botodo->query;
		$this->filter     = $this->botodo->filter;
		$this->order      = $this->botodo->order;
		$this->sort       = $this->botodo->sort;
		$this->cat_id     = $this->botodo->cat_id;
	}

	function save_sessiondata()
	{
		$data = array(
			'start'		=> $this->start,
			'query'		=> $this->query,
			'filter'	=> $this->filter,
			'order'		=> $this->order,
			'sort'		=> $this->sort,
			'cat_id'	=> $this->cat_id
		);
		$this->botodo->save_sessiondata($data);
	}

	function set_app_langs()
	{
		$this->t->set_var('lang_category', lang('Category'));
		$this->t->set_var('lang_select', lang('Select'));
		$this->t->set_var('lang_descr', lang('Description'));
		$this->t->set_var('lang_title', lang('Title'));
		$this->t->set_var('lang_none', lang('None'));
		$this->t->set_var('lang_nobody', lang('Nobody'));
		$this->t->set_var('lang_urgency', lang('Urgency'));
		$this->t->set_var('lang_completed', lang('Completed'));
		$this->t->set_var('lang_start_date', lang('Start Date'));
		$this->t->set_var('lang_end_date', lang('End Date'));
		$this->t->set_var('lang_date_due', lang('date due'));
		$this->t->set_var('lang_access', lang('Private'));
		$this->t->set_var('lang_parent', lang('Parent project'));
		$this->t->set_var('lang_submit', lang('Submit'));
		$this->t->set_var('lang_save', lang('Save'));
		$this->t->set_var('lang_done', lang('Done'));
		$this->t->set_var('lang_assigned_group', lang('Assigned to group'));
		$this->t->set_var('lang_assigned_user', lang('Assigned to user'));
		$this->t->set_var('lang_owner', lang('Created by'));
	}

	function show_list()
	{
		$redirect_params = array();
		$cat_id = Sanitizer::get_var('cat_id', 'int', 'REQUEST', 0);
		$filter = Sanitizer::get_var('filter', 'string', 'REQUEST', 'none');

		if ($cat_id)
		{
			$redirect_params['cat_id'] = $cat_id;
		}

		if ($filter)
		{
			$redirect_params['filter'] = $filter;
		}

		phpgw::redirect_link('/todo/view/todos', $redirect_params);
	}

	/**
	 * Compatibility shim for phpgwapi_uicommon_jquery.
	 * Todo list data is now served by /todo/todos via TodoController.
	 */
	function query()
	{
		$search = Sanitizer::get_var('search');
		$order = Sanitizer::get_var('order');
		$columns = Sanitizer::get_var('columns');

		$start = Sanitizer::get_var('start', 'int', 'REQUEST', 0);
		$length = Sanitizer::get_var('length', 'int', 'REQUEST', 25);
		$filter = Sanitizer::get_var('filter', 'string', 'REQUEST', 'none');
		$cat_id = Sanitizer::get_var('cat_id', 'int', 'REQUEST', 0);

		$sort_map = array(
			'id' => 'todo_id',
			'title' => 'todo_title',
			'status' => 'todo_status',
			'pri' => 'todo_pri',
			'sdate' => 'todo_startdate',
			'edate' => 'todo_enddate',
			'owner' => 'todo_owner',
		);

		$sort = 'todo_id';
		$dir = 'ASC';

		if (is_array($order) && isset($order[0]['column']) && isset($columns[$order[0]['column']]['data']))
		{
			$column_key = $columns[$order[0]['column']]['data'];
			if (isset($sort_map[$column_key]))
			{
				$sort = $sort_map[$column_key];
			}

			$order_dir = isset($order[0]['dir']) ? strtolower($order[0]['dir']) : 'asc';
			$dir = $order_dir === 'desc' ? 'DESC' : 'ASC';
		}

		$todo_list = $this->botodo->_list(
			$start,
			$length,
			is_array($search) && isset($search['value']) ? $search['value'] : '',
			$filter,
			$sort,
			$dir,
			$cat_id,
			'all'
		);

		return $this->jquery_results(array(
			'total_records' => $this->botodo->total_records,
			'results' => is_array($todo_list) ? $todo_list : array()
		));
	}

	function formatted_user($type, $selected = '')
	{
		if (!$selected)
		{
			$selected = $this->account;
		}

		if (! is_array($selected))
		{
			$selected = explode(',', $selected);
		}

		$user_list = '';

		$accounts = $this->botodo->employee_list($type);
		//_debug_array($accounts);
		$accounts_obj = new Accounts();

		foreach ($accounts as $account)
		{
			$user_list .= '<option value="' . $account->id . '"';
			if (in_array($account->id, $selected))
			{
				$user_list .= ' selected';
			}
			$user_list .= '>' . $accounts_obj->id2name($account->id) . "</option>\n";
		}
		return $user_list;
	}

	function formatted_todo($selected = '')
	{
		$todos = $this->botodo->_list(0, False);

		$todo_select = '';
		foreach ($todos as $todo)
		{
			$todo_select .= '<option value="' . $todo['id'] . '"';
			if ($todo['id'] == $selected)
			{
				$todo_select .= ' selected';
			}
			if (! $todo['title'])
			{
				$words = explode(' ', phpgw::strip_html($todo['descr']));
				$title = "$words[0] $words[1] $words[2] $words[3] ...";
				$todo_select .= ">$title";
			}
			else
			{
				$todo_select .= '>' . phpgw::strip_html($todo['title']);
			}
			$todo_select .= '</option>';
		}
		return $todo_select;
	}

	function add()
	{
		$cat_id			= Sanitizer::get_var('cat_id', 'int', 'REQUEST', 0);
		$new_cat		= Sanitizer::get_var('new_cat', 'int', 'REQUEST', 0);
		$values			= Sanitizer::get_var('values');
		$submit			= Sanitizer::get_var('submit', 'bool');
		$new_parent		= Sanitizer::get_var('new_parent', 'int', 'REQUEST', 0);
		$parent			= Sanitizer::get_var('parent', 'int', 'REQUEST', 0);
		$assigned		= Sanitizer::get_var('assigned');
		$assigned_group	= Sanitizer::get_var('assigned_group');

		if ($new_parent)
		{
			$parent = $new_parent;
		}

		if ($new_cat)
		{
			$cat_id = $new_cat;
		}

		if ($submit)
		{
			$values['cat'] = $cat_id;
			$values['parent'] = $parent;
			if (!isset($values['main']))
			{
				$values['main'] = $cat_id;
				$values['level'] = 0;
			}

			$values['assigned'] = '';
			if (is_array($assigned))
			{
				$values['assigned'] = implode(',', $assigned);
				if (count($assigned) > 1)
				{
					$values['assigned'] = ", {$values['assigned']} ,";
				}
			}

			$values['assigned_group'] = '';
			if (is_array($assigned_group))
			{
				$values['assigned_group'] = implode(',', $assigned_group);
				if (count($assigned_group) > 1)
				{
					$values['assigned_group'] = ',' . $values['assigned_group'] . ',';
				}
			}

			$error = $this->botodo->check_values($values);
			if (is_array($error))
			{
				$this->t->set_var('error', $this->phpgwapi_common->error_list($error));
			}
			else
			{
				$this->botodo->save($values);
				phpgw::redirect_link('/index.php', array('menuaction' => 'todo.uitodo.show_list', 'cat_id' => (int) $cat_id));
				exit;
			}
		}

		$this->phpgwapi_common->phpgw_header(true);

		$this->t->set_file('todo_add', 'form.tpl');
		$this->t->set_block('todo_add', 'add', 'addhandle');
		$this->t->set_block('todo_add', 'edit', 'edithandle');

		$this->set_app_langs();
		$this->t->set_var('actionurl', phpgw::link('/index.php', array('menuaction' => 'todo.uitodo.add')));

		if ($parent)
		{
			$this->t->set_var('lang_todo_action', lang('Add sub project'));
		}
		else
		{
			$this->t->set_var('lang_todo_action', lang('Add main project'));
		}

		if (!isset($values['descr']) || !$values['descr'])
		{
			$values['descr'] = '';
		}

		if (!isset($values['smonth']) || !$values['smonth'])
		{
			$values['smonth'] = date('m', time());
		}

		if (!isset($values['sday']) || !$values['sday'])
		{
			$values['sday'] = date('d', time());
		}

		if (! isset($values['syear']) || !$values['syear'])
		{
			$values['syear'] = date('Y', time());
		}

		$plus1week = strtotime('+1 week');
		if (!isset($values['eday']) || !$values['eday'])
		{
			$values['eday'] = date('d', $plus1week);
		}

		if (!isset($values['emonth']) || !$values['emonth'])
		{
			$values['emonth'] = date('m', $plus1week);
		}

		if (!isset($values['eyear']) || !$values['eyear'])
		{
			$values['eyear'] = date('Y', $plus1week);
		}
		unset($plus1week);

		$this->t->set_var($values + array(
			'cat_list'			=> $this->cats->formatted_list('select', 'all', $cat_id, 'True'),
			'todo_list'			=> $this->formatted_todo($parent),
			'pri_list'			=> phpgwapi_sbox::getPriority('values[pri]'),
			'stat_list'			=> phpgwapi_sbox::getPercentage('values[status]', 0),
			'user_list'			=> $this->formatted_user('accounts', $assigned),
			'group_list'		=> $this->formatted_user('groups', $assigned_group),
			'lang_selfortoday'	=> lang('or: select for today:'),
			'lang_daysfromstartdate' => lang('or: days from startdate:'),
			'lang_submit'		=> lang('Submit'),
			'lang_reset'		=> lang('Clear form'),
			'edithandle'		=> '',
			'addhandle'			=> '',
			'start_select_date'	=> $this->phpgwapi_common->dateformatorder(
				phpgwapi_sbox::getYears('values[syear]', $values['syear']),
				phpgwapi_sbox::getMonthText('values[smonth]', $values['smonth']),
				phpgwapi_sbox::getDays('values[sday]', $values['sday'])
			),
			'end_select_date'	=> $this->phpgwapi_common->dateformatorder(
				phpgwapi_sbox::getYears('values[eyear]', $values['eyear']),
				phpgwapi_sbox::getMonthText('values[emonth]', $values['emonth']),
				phpgwapi_sbox::getDays('values[eday]', $values['eday'])
			),
			'selfortoday'		=> '<input type="checkbox" name="values[seltoday]" value="True">',
			'daysfromstartdate'	=> '<input type="text" name="values[daysfromstart]" size="3" maxlength="3">',
			'access_list'		=> '<input type="checkbox" name="values[access]" value="True"' . (!isset($values['access']) || $values['access'] == 'private' ? ' checked' : '') . '>'
		));

		$this->t->pfp('out', 'todo_add');
		$this->t->pfp('addhandle', 'add');
	}

	function view()
	{
		$this->phpgwapi_common->phpgw_header(true);

		$values = $this->botodo->read($_REQUEST['todo_id']);
		$this->t->set_file('_view', 'view.tpl');

		$this->set_app_langs();

		$this->t->set_var('lang_todo_action', lang('View todo item'));
		$this->t->set_var('value_title', phpgw::strip_html($values['title']));
		$this->t->set_var('value_descr', phpgw::strip_html($values['descr']));
		$this->t->set_var('value_category', $this->cats->id2name($values['cat']));

		$sdate = $values['sdate'] - $this->botodo->datetime->tz_offset;
		$this->t->set_var('value_start_date', $this->phpgwapi_common->show_date($sdate, $this->userSettings['preferences']['common']['dateformat']));

		if ($values['edate'] && $values['edate'] != 0)
		{
			$edate = $values['edate'] - $this->botodo->datetime->tz_offset;
			$this->t->set_var('value_end_date', $this->phpgwapi_common->show_date($edate, $this->userSettings['preferences']['common']['dateformat']));
		}

		$parent_values = $this->botodo->read($values['parent']);
		if (is_array($parent_values) && count($parent_values))
		{
			$this->t->set_var('value_parent', phpgw::strip_html($parent_values['title']));
		}
		else
		{
			$this->t->set_var('value_parent', '');
		}

		$this->t->set_var('value_completed', $values['status']);

		$assigned = $this->botodo->list_assigned($this->botodo->format_assigned($values['assigned']));
		$assigned .= $this->botodo->list_assigned($this->botodo->format_assigned($values['assigned_group']));

		$this->t->set_var('assigned', $assigned);

		$cached_data = $this->botodo->cached_accounts($values['owner']);


		/**
		 * Begin Orlando Fix
		 *
		 * I had to change how $cached_data variables were used( as arrays)
		 * so they can be read as: object -> attribute
		 */
		$this->t->set_var('owner', $this->phpgwapi_common->display_fullname(
			$cached_data->lid,
			$cached_data->firstname,
			$cached_data->lastname
		));
		/**
		 * End Orlando Fix
		 */


		$pri = '';
		switch ($values['pri'])
		{
			case 1:
				$pri = lang('Low');
				break;
			case 2:
				$pri = lang('normal');
				break;
			case 3:
				$pri = '<font color="CC0000"><b>' . lang('high') . '</b></font>';
				break;
		}

		$this->t->set_var('value_urgency', $pri);

		$this->t->set_var('lang_access', lang('Access'));
		$this->t->set_var('access', lang($values['access']));

		$this->t->set_var('history', $this->historylog->return_html(array(), '', '', $_REQUEST['todo_id']));
		$this->t->set_var('done_action', phpgw::link('/index.php', array('menuaction' => 'todo.uitodo.show_list')));
		$this->t->pfp('out', '_view');
	}

	function edit()
	{
		$todo_id = isset($_REQUEST['todo_id']) ? (int) $_REQUEST['todo_id'] : 0;
		$cat_id = isset($_POST['cat_id']) ? (int) $_POST['cat_id'] : 0;
		$new_cat = isset($_POST['new_cat']) ? (int) $_POST['new_cat'] : 0;
		$values = isset($_POST['values']) ? (array) $_POST['values'] : array();
		$submit = isset($_POST['submit']) ? !!$_POST['submit'] : false;
		$new_parent = isset($_POST['new_parent']) ? $_POST['new_parent'] : 0;
		$parent = isset($_POST['parent']) ? (int) $_POST['parent'] : 0;
		$assigned = isset($_POST['assigned']) ? $_POST['assigned'] : 0;
		$assigned_group = isset($_POST['assigned_group']) ? $_POST['assigned_group'] : 0;

		if ($new_parent)
		{
			$parent = $new_parent;
		}

		if ($new_cat)
		{
			$cat_id = $new_cat;
		}

		if ($submit)
		{
			$values['cat'] = $cat_id;
			$values['parent'] = $parent;
			$values['id'] = $todo_id;

			if (is_array($assigned))
			{
				$values['assigned'] = implode(',', $assigned);
				if (count($assigned) > 1)
				{
					$values['assigned'] = ',' . $values['assigned'] . ',';
				}
			}

			if (is_array($assigned_group))
			{
				$values['assigned_group'] = implode(',', $assigned_group);
				if (count($assigned_group) > 1)
				{
					$values['assigned_group'] = ',' . $values['assigned_group'] . ',';
				}
			}

			$error = $this->botodo->check_values($values);
			if (is_array($error))
			{
				$this->t->set_var('error', $this->phpgwapi_common->error_list($error));
			}
			else
			{
				$this->botodo->save($values, 'edit');
				phpgw::redirect_link('/', array('menuaction' => 'todo.uitodo.show_list', 'cat_id' => $cat_id));
				exit;
			}
		}

		$this->phpgwapi_common->phpgw_header(true);

		$this->t->set_file(array('todo_edit' => 'form.tpl'));
		$this->t->set_block('todo_edit', 'add', 'addhandle');
		$this->t->set_block('todo_edit', 'edit', 'edithandle');

		$this->set_app_langs();
		$this->t->set_var('actionurl', phpgw::link('/index.php', array('menuaction' => 'todo.uitodo.edit', 'todo_id' => $todo_id)));

		$values = $this->botodo->read($todo_id);

		if ($values['parent'] != 0)
		{
			$this->t->set_var('lang_todo_action', lang('Edit sub project'));
		}
		else
		{
			$this->t->set_var('lang_todo_action', lang('Edit main project'));
		}

		$this->t->set_var('cat_list', $this->cats->formatted_list('select', 'all', $values['cat'], 'True'));
		$this->t->set_var('todo_list', $this->formatted_todo($values['parent']));

		$this->t->set_var('descr', phpgw::strip_html($values['descr']));
		$this->t->set_var('title', phpgw::strip_html($values['title']));

		$this->t->set_var('pri_list', phpgwapi_sbox::getPriority('values[pri]', $values['pri']));
		$this->t->set_var('stat_list', phpgwapi_sbox::getPercentage('values[status]', $values['status']));
		$this->t->set_var('user_list', $this->formatted_user('accounts', $this->botodo->format_assigned($values['assigned'])));
		$this->t->set_var('group_list', $this->formatted_user('groups', $this->botodo->format_assigned($values['assigned_group'])));

		if ($values['sdate'] == 0)
		{
			$values['sday'] = 0;
			$values['smonth'] = 0;
			$values['syear'] = 0;
		}
		else
		{
			$values['sday'] = date('d', $values['sdate']);
			$values['smonth'] = date('m', $values['sdate']);
			$values['syear'] = date('Y', $values['sdate']);
		}

		$this->t->set_var('start_select_date', $this->phpgwapi_common->dateformatorder(
			phpgwapi_sbox::getYears('values[syear]', $values['syear']),
			phpgwapi_sbox::getMonthText('values[smonth]', $values['smonth']),
			phpgwapi_sbox::getDays('values[sday]', $values['sday'])
		));

		if ($values['edate'] == 0)
		{
			$values['eday'] = 0;
			$values['emonth'] = 0;
			$values['eyear'] = 0;
		}
		else
		{
			$values['eday'] = date('d', $values['edate']);
			$values['emonth'] = date('m', $values['edate']);
			$values['eyear'] = date('Y', $values['edate']);
		}

		$this->t->set_var('end_select_date', $this->phpgwapi_common->dateformatorder(
			phpgwapi_sbox::getYears('values[eyear]', $values['eyear']),
			phpgwapi_sbox::getMonthText('values[emonth]', $values['emonth']),
			phpgwapi_sbox::getDays('values[eday]', $values['eday'])
		));

		$this->t->set_var('selfortoday', '&nbsp;');
		$this->t->set_var('lang_selfortoday', '&nbsp;');
		$this->t->set_var('lang_daysfromstartdate', '&nbsp;');
		$this->t->set_var('daysfromstartdate', '&nbsp;');

		$this->t->set_var('access_list', '<input type="checkbox" name="values[access]" value="True"' . ($values['access'] == 'private' ? ' checked' : '') . '>');

		if ($this->botodo->check_perms($values['owner'], $this->grants, ACL_DELETE) || $values['owner'] == $this->userSettings['account_id'])
		{
			$this->t->set_var('delete', '<form method="POST" action="' . phpgw::link('/index.php', array('menuaction' => 'todo.uitodo.delete', 'todo_id' => $values['id']))
				. '"><input type="submit" value="' . lang('Delete') . '"></form>');
		}
		else
		{
			$this->t->set_var('delete', '&nbsp;');
		}

		$this->t->set_var('lang_submit', lang('Submit'));
		$this->t->set_var('edithandle', '');
		$this->t->set_var('addhandle', '');
		$this->t->pfp('out', 'todo_edit');
		$this->t->pfp('edithandle', 'edit');
	}

	function delete()
	{
		$todo_id = isset($_REQUEST['todo_id']) ? (int)$_REQUEST['todo_id'] : 0;

		if (isset($_POST['confirm']) && $_POST['confirm'])
		{
			if (isset($_POST['subs']) && $_POST['subs'])
			{
				$this->botodo->delete($todo_id, true);
			}
			else
			{
				$this->botodo->delete($todo_id);
			}
			phpgw::redirect_link('/index.php', array('menuaction' => 'todo.uitodo.show_list'));
		}
		$this->phpgwapi_common->phpgw_header(true);

		$this->t->set_file('todo_delete', 'delete.tpl');
		$this->t->set_var('actionurl', phpgw::link('/index.php', array('menuaction' => 'todo.uitodo.delete', 'todo_id' => $todo_id)));

		$exists = $this->botodo->exists($todo_id);

		if ($exists)
		{
			$this->t->set_var('lang_subs', lang('Do you also want to delete all sub projects ?'));
			$this->t->set_var('subs', '<input type="checkbox" name="subs" value="True">');
		}
		else
		{
			$this->t->set_var('lang_subs', '');
			$this->t->set_var('subs', '');
		}

		$this->t->set_var('nolink', phpgw::link('/index.php', array('menuaction' => 'todo.uitodo.show_list')));
		$this->t->set_var('deleteheader', lang('Are you sure you want to delete this entry'));
		$this->t->set_var('lang_no', lang('No'));
		$this->t->set_var('lang_yes', lang('Yes'));

		$this->t->pfp('out', 'todo_delete');
	}

	function matrix()
	{
		$o = 0;
		$month = isset($_REQUEST['month']) ? (int) $_REQUEST['month'] : date('n');
		$year = isset($_REQUEST['year']) ? (int) $_REQUEST['year'] : date('Y');

		$this->phpgwapi_common->phpgw_header(true);

		$colors = array(
			'#cc0033',
			'#006600',
			'#00ccff',
			'#ff6600',
			'#0000ff'
		);

		$matrix  = CreateObject('phpgwapi.matrixview', $month, $year);


		$entries = $this->botodo->_list(0, 0, '', '', '', '', '', 'mains');

		foreach ($entries as $entry)
		{
			++$o;
			$ind = $o % count($colors);

			if ($entry['sdate_epoch'] > 0 && $entry['edate_epoch'] > 0)
			{
				$title = '<a href="' . phpgw::link('/index.php', array('menuaction' => 'todo.uitodo.view', 'todo_id' => $entry['id'])) . '">' . phpgw::strip_html($entry['title']) . '</a>';
				$startd = date('Y', $entry['sdate_epoch']) . date('m', $entry['sdate_epoch']) . date('d', $entry['sdate_epoch']);
				$endd = date('Y', $entry['edate_epoch']) . date('m', $entry['edate_epoch']) . date('d', $entry['edate_epoch']);
				$matrix->setPeriod($title, $startd, $endd, $colors[$ind]);

				$subentries = $this->botodo->_list(0, 0, '', '', '', '', '', 'subs', $entry['id']);
				foreach ($subentries as $subentry)
				{
					if ($subentry['sdate_epoch'] > 0 && $subentry['edate_epoch'] > 0)
					{
						$title = '<a href="' . phpgw::link('/index.php', array('menuaction' => 'todo.uitodo.view', 'todo_id' => $subentry['id'])) . '">' . phpgw::strip_html($subentry['title']) . '</a>';
						$startd = date('Y', $subentry['sdate_epoch']) . date('m', $subentry['sdate_epoch']) . date('d', $subentry['sdate_epoch']);
						$endd = date('Y', $subentry['edate_epoch']) . date('m', $subentry['edate_epoch']) . date('d', $subentry['edate_epoch']);
						$matrix->setPeriod(phpgw::strip_html($subentry['title']), $startd, $endd, $colors[$ind]);
					}
				}
			}
		}
		$matrix->out(phpgw::link('/', array('menuaction' => 'todo.uitodo.matrix')));
	}
}
