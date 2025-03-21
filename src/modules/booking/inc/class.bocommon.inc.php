<?php

use App\modules\phpgwapi\services\Settings;

class booking_bocommon
{

	var $so;
	protected $phpgwapi_common, $flags, $serverSettings, $userSettings, $apps, $dateformat, $datetimeformat;

	public function __construct()
	{
		$this->phpgwapi_common = new \phpgwapi_common();
		$this->flags = Settings::getInstance()->get('flags');
		$this->serverSettings = Settings::getInstance()->get('server');
		$this->userSettings = Settings::getInstance()->get('user');
		$this->apps = Settings::getInstance()->get('apps');
		$this->dateformat = $this->userSettings['preferences']['common']['dateformat'];
		$this->datetimeformat = "{$this->dateformat} H:i";
	}

	/**
	 * Forwards method invocations to so
	 */
	public function __call($method, $arguments)
	{
		return call_user_func_array(array($this->so, $method), $arguments);
	}

	function read_single($id)
	{
		return $this->so->read_single($id);
	}

	function show_all_objects()
	{
		$_SESSION['showall'] = "1";
	}

	function unset_show_all_objects()
	{
		unset($_SESSION['showall']);
	}

	public function link($data)
	{
		$flags = Settings::getInstance()->get('flags');
		
		if ($flags['currentapp'] == 'bookingfrontend')
		{
			return phpgw::link('/bookingfrontend/', $data);
		}
		else
		{
			return phpgw::link('/index.php', $data);
		}
	}

	function read($params = array())
	{
		$default_read_params = $this->build_default_read_params();
		return $this->so->read(array_replace($default_read_params, $params));
	}

	/**
	 * Returns all rows matching current filters using no limit.
	 */
	function read_all()
	{
		return $this->so->read($this->build_read_all_params());
	}

	protected function build_read_all_params()
	{
		$params = $this->build_default_read_params();

		if (empty($params['results']))
		{
			$params['results'] = 'all';
			unset($params['start']);
		}

		return $params;
	}

	protected function build_default_read_params()
	{
		/*
			 * Sigurd: Temporary test for new datatables
			 */
		if (Sanitizer::get_var('columns') || isset($_POST['start']))
		{
			return $this->build_default_read_params_new();
		}


		/*
			 * startIndex is used in createTable() in js/jquery/common.js
			 */
		$start = Sanitizer::get_var('startIndex', 'int', 'REQUEST', 0);
		$results = Sanitizer::get_var('results', 'int', 'REQUEST', null);
		$query = Sanitizer::get_var('query');
		$sort = Sanitizer::get_var('sort');
		$dir = Sanitizer::get_var('dir');
		$length = Sanitizer::get_var('length', 'int', 'REQUEST', 0);

		if ($length)
		{
			$results = $length;
		}

		$filters = array();
		foreach ($this->so->get_field_defs() as $field => $params)
		{
			if (isset($_REQUEST["filter_$field"]) && $_REQUEST["filter_$field"])
			{
				$filters[$field] = Sanitizer::get_var("filter_$field", $params['type']);
			}
		}

		if (!empty($filters['active']) && $filters['active'] == "-1")
		{
			unset($filters['active']);
		}
		else if (!isset($_SESSION['showall']))
		{
			if (!isset($filters['application_id']))
			{
				$filters['active'] = "1";
			}
		}

		return array(
			'start' => $start,
			'results' => $results,
			'query' => $query,
			'sort' => $sort,
			'dir' => $dir,
			'filters' => $filters
		);
	}

	protected function build_default_read_params_new()
	{

		$search = Sanitizer::get_var('search');
		$order = Sanitizer::get_var('order');
		$draw = Sanitizer::get_var('draw', 'int');
		$columns = Sanitizer::get_var('columns');

		$params = array(
			'start' => Sanitizer::get_var('start', 'int', 'REQUEST', 0),
			'results' => Sanitizer::get_var('length', 'int', 'REQUEST', 0),
			'query' => $search['value'],
			'sort' => $columns[$order[0]['column']]['data'],
			'dir' => $order[0]['dir'],
			'allrows' => Sanitizer::get_var('length', 'int') == -1,
		);

		foreach ($this->so->get_field_defs() as $field => $_params)
		{
			if (isset($_REQUEST["filter_$field"]) && $_REQUEST["filter_$field"])
			{
				$params['filters'][$field] = Sanitizer::get_var("filter_$field", $_params['type']);
			}
		}

		if (!empty($params['filters']['active']) && $params['filters']['active'] == "-1")
		{
			unset($params['filters']['active']);
		}
		else if (!isset($_SESSION['showall']))
		{
			if (!isset($params['filters']['application_id']))
			{
				$params['filters']['active'] = "1";
			}
		}

		return $params;
	}

	function add($entity)
	{
		try
		{
			$ret = $this->so->add($entity);
		}
		catch (Exception $exc)
		{
			throw $exc;
		}

		return $ret;
	}

	function smart_read($entity)
	{
		return $this->so->read($entity);
	}

	public function create_error_stack($errors = array())
	{
		return $this->so->create_error_stack($errors);
	}

	function validate(&$entity)
	{
		$error_stack = $this->create_error_stack($this->so->validate($entity));
		$this->doValidate($entity, $error_stack);
		return $error_stack->getArrayCopy();
	}

	/**
	 * Implement in subclasses to perform custom validation.
	 */
	protected function doValidate($entity, booking_errorstack $error_stack)
	{
	}

	function update($entity)
	{
		return $this->so->update($entity);
	}

	function delete($id)
	{
		return $this->so->delete($id);
	}

	function set_active($id, $active)
	{
		return $this->so->set_active($id, $active);
	}

	/**
	 * Checks if the current user has any role
	 * Use booking_sopermission::ROLE_MANAGER or booking_sopermission::CASE_OFFICER for the role parameter
	 */
	function has_role($role)
	{
		$permission_root_bo = CreateObject('booking.bopermission_root');
		$userSettings = Settings::getInstance()->get('user');
		$params = array(
			'results' => 'all',
			'filters' => array(
				'role' => $role,
				'subject_id' => $userSettings['id'] // id for the current user
			)
		);
		$booking_roles = $permission_root_bo->so->read($params);

		if (intval($booking_roles['total_records']) == 1)
		{
			return true;
		}
		else
		{
			return false;
		}
	}
}
