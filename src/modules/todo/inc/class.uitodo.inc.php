<?php

/**
 * Todo user interface compatibility shim.
 *
 * Legacy menuaction endpoints are preserved and redirected to
 * the modern Twig/REST routes.
 *
 * @package todo
 */


class todo_uitodo
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


	public function __construct()
	{
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
