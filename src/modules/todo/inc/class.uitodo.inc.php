<?php

/**
 * Todo user interface compatibility shim.
 *
 * Legacy menuaction endpoints are preserved and redirected to
 * the modern Twig/REST routes.
 *
 * @package todo
 */

phpgw::import_class('phpgwapi.uicommon_jquery');

class todo_uitodo extends phpgwapi_uicommon_jquery
{
	public $public_functions = array(
		'show_list' => true,
		'view' => true,
		'add' => true,
		'edit' => true,
		'delete' => true,
		'matrix' => true,
		'query' => true,
	);

	private $botodo;

	public function __construct()
	{
		parent::__construct('todo');
		$this->botodo = CreateObject('todo.botodo', true);
	}

	public function show_list()
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
	 * Todo list data is served by todo.botodo and returned as jquery_results.
	 */
	public function query()
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
			'results' => is_array($todo_list) ? $todo_list : array(),
		));
	}

	public function add()
	{
		$redirect_params = array();
		$cat_id = Sanitizer::get_var('cat_id', 'int', 'REQUEST', 0);
		$parent = Sanitizer::get_var('parent', 'int', 'REQUEST', 0);

		if ($cat_id)
		{
			$redirect_params['cat_id'] = $cat_id;
		}

		if ($parent)
		{
			$redirect_params['parent'] = $parent;
		}

		phpgw::redirect_link('/todo/view/todos/add', $redirect_params);
	}

	public function view()
	{
		$todo_id = Sanitizer::get_var('todo_id', 'int', 'REQUEST', 0);
		if ($todo_id)
		{
			phpgw::redirect_link('/todo/view/todos/' . $todo_id);
		}

		phpgw::redirect_link('/todo/view/todos');
	}

	public function edit()
	{
		$todo_id = Sanitizer::get_var('todo_id', 'int', 'REQUEST', 0);
		if ($todo_id)
		{
			phpgw::redirect_link('/todo/view/todos/' . $todo_id . '/edit');
		}

		phpgw::redirect_link('/todo/view/todos');
	}

	public function delete()
	{
		$todo_id = Sanitizer::get_var('todo_id', 'int', 'REQUEST', 0);
		if ($todo_id)
		{
			phpgw::redirect_link('/todo/view/todos/' . $todo_id . '/delete');
		}

		phpgw::redirect_link('/todo/view/todos');
	}

	public function matrix()
	{
		$month = Sanitizer::get_var('month', 'int', 'REQUEST', (int) date('n'));
		$year = Sanitizer::get_var('year', 'int', 'REQUEST', (int) date('Y'));

		phpgw::redirect_link('/todo/view/todos/matrix', array(
			'month' => $month,
			'year' => $year,
		));
	}
}
