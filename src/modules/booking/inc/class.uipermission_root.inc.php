<?php

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\controllers\Accounts\Accounts;

phpgw::import_class('booking.uicommon');
phpgw::import_class('booking.account_ui_utils');

phpgw::import_class('booking.uidocument_building');
phpgw::import_class('booking.uipermission_building');

//	phpgw::import_class('phpgwapi.uicommon_jquery');

class booking_uipermission_root extends booking_uicommon
{

	public
		$public_functions = array(
			'index' => true,
			'query' => true,
			'index_accounts' => true,
			'add' => true,
			'delete' => true,
		);

	var $fields, $display_name;

	public function __construct()
	{
		parent::__construct();

		//		Analiza esta linea de permisos self::process_booking_unauthorized_exceptions();

		$this->set_business_object();

		$this->fields = array('subject_id', 'subject_name', 'role');

		self::set_active_menu('booking::settings::permissions');
		$this->display_name = lang('Root Permissions');
		Settings::getInstance()->update('flags', ['app_header' => lang('booking') . "::{$this->display_name}"]);
	}

	protected function set_business_object(booking_bopermission_root|null $bo = null)
	{
		$this->bo = is_null($bo) ? $this->create_business_object() : $bo;
	}

	protected function create_business_object()
	{
		return CreateObject('booking.bopermission_root');
	}

	public function generate_link_params($action, $params = array())
	{
		$action = sprintf('booking.uipermission_root.%s', $action);
		return array_merge(array('menuaction' => $action), $params);
	}

	public function generate_link($action, $params = array())
	{
		return $this->link($this->generate_link_params($action, $params));
	}

	public function index()
	{
		if (Sanitizer::get_var('phpgw_return_as') == 'json')
		{
			return $this->query();
		}

		$data = array(
			'datatable_name' => $this->display_name,
			'form' => array(
				'toolbar' => array(),
			),
			'datatable' => array(
				'source' => $this->generate_link('index', array('phpgw_return_as' => 'json')),
				'field' => array(
					array(
						'key' => 'subject_name',
						'label' => lang('User'),
						'sortable' => true,
					),
					array(
						'key' => 'role',
						'label' => lang('Role'),
						'sortable' => true,
					),
					array(
						'key' => 'actions',
						'label' => lang('Actions'),
						'sortable' => false,
						'formatter' => 'JqueryPortico.formatLinkGeneric',
					),
				)
			)
		);


		try
		{
			if (!$this->bo->allow_delete())
			{
				unset($data['datatable']['field'][2]); //Delete action
			}
		}
		catch (booking_unauthorized_exception $ex)
		{
			unset($data['datatable']['field'][2]); //Delete action
		}

		try
		{
			if ($this->bo->allow_create())
			{
				$data['datatable']['new_item'] = $this->generate_link('add');
			}
		}
		catch (booking_unauthorized_exception $ex)
		{
		}

		$data['datatable']['actions'][] = array();
		self::render_template_xsl('datatable2', $data);
	}

	public function query()
	{
		$permissions = $this->bo->read();
		$accounts_obj = new Accounts();

		foreach ($permissions['results'] as &$permission)
		{
			$permission['link'] = $this->generate_link('edit', array('id' => $permission['id']));
			#$permission['active'] = $permission['active'] ? lang('Active') : lang('Inactive');
			$permission['actions'] = array(
				$this->generate_link('delete', array('id' => $permission['id'])),
			);

			$account_id = $accounts_obj->name2id($permission['subject_name']);
			if ($account_id)
			{
				$permission['subject_name'] = $accounts_obj->get($account_id)->__toString();
			}
		}
		return $this->jquery_results($permissions);
	}

	public function index_accounts()
	{
		return booking_account_ui_utils::yui_accounts();
	}

	protected function get_available_roles()
	{
		$roles = array();
		foreach ($this->bo->get_roles() as $role)
		{
			$roles[$role] = self::humanize($role);
		}
		return $roles;
	}

	protected function add_default_display_data(&$permission_data)
	{
		$permission_data['available_roles'] = $this->get_available_roles();
		$permission_data['permissions_link'] = $this->generate_link('index');
		$permission_data['cancel_link'] = $this->generate_link('index');
	}

	public function add()
	{
		$errors = array();
		$permission = array();

		if ($_SERVER['REQUEST_METHOD'] == 'POST')
		{
			$permission = extract_values($_POST, $this->fields);
			$errors = $this->bo->validate($permission);
			if (!$errors)
			{
				$receipt = $this->bo->add($permission);
				self::redirect($this->generate_link_params('index'));
			}
		}

		self::add_javascript('booking', 'base', 'permission_root.js');
		phpgwapi_jquery::load_widget('autocomplete');

		$this->add_default_display_data($permission);

		$this->flash_form_errors($errors);

		$tabs = array();
		$tabs['generic'] = array('label' => lang('Permission Add'), 'link' => '#permission_add');
		$active_tab = 'generic';

		//            $data = array();
		$permission['tabs'] = phpgwapi_jquery::tabview_generate($tabs, $active_tab);
		$permission['validator'] = phpgwapi_jquery::formvalidator_generate(array(
			'location',
			'date', 'security', 'file'
		));

		self::render_template_xsl('permission_root_form', array('permission' => $permission));
	}

	public function delete()
	{
		$id = Sanitizer::get_var('id', 'int');
		$this->bo->delete($id);
		self::redirect($this->generate_link_params('index'));
	}
}
