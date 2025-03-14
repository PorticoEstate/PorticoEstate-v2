<?php

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\controllers\Accounts\Accounts;

phpgw::import_class('booking.uicommon');
phpgw::import_class('booking.account_ui_utils');

abstract class booking_uipermission extends booking_uicommon
{

	protected
		$object_type = null;
	public
		$public_functions = array(
			'index' => true,
			'query' => true,
			'index_accounts' => true,
			'show' => true,
			'add' => true,
			'edit' => true,
			'delete' => true,
		);

	var $fields, $display_name;

	public function __construct()
	{
		//			_debug_array('hei');

		parent::__construct();

		//			Analizar esta linea de permiso self::process_booking_unauthorized_exceptions();

		$this->set_business_object();

		$this->fields = array(
			'subject_id', 'subject_name', 'object_id', 'object_name',
			'role'
		);

		$obj_info = explode('_', get_class($this));
		$this->display_name = lang($obj_info[2]) . '::' . lang(ltrim($obj_info[1], 'ui') . 's');
		Settings::getInstance()->update('flags', ['app_header' => lang('booking') . "::{$this->display_name}"]);
	}

	protected function set_business_object(booking_bopermission|null $bo = null)
	{
		$this->bo = is_null($bo) ? $this->create_business_object() : $bo;
	}

	protected function create_business_object()
	{
		return CreateObject(sprintf('booking.bopermission_%s', $this->get_object_type()));
	}

	protected function get_object_type()
	{
		if (!$this->object_type)
		{
			$this->set_object_type();
		}
		return $this->object_type;
	}

	protected function set_object_type($type = null)
	{
		is_null($type) and $type = substr(get_class($this), 21);
		$this->object_type = $type;
	}

	public function get_parent_url_link_params()
	{
		$inlineParams = $this->get_inline_params();
		return array(
			'menuaction' => sprintf('booking.ui%s.show', $this->get_object_type()),
			'id' => $inlineParams['filter_object_id']
		);
	}

	public function redirect_to_parent_if_inline()
	{
		if ($this->is_inline())
		{
			self::redirect($this->get_parent_url_link_params());
		}

		return false;
	}

	public function get_object_typed_link_params($action, $params = array())
	{
		$action = sprintf('booking.uipermission_%s.%s', $this->get_object_type(), $action);
		return array_merge(array('menuaction' => $action), $this->apply_inline_params($params));
	}

	public function get_object_typed_link($action, $params = array())
	{
		return $this->link($this->get_object_typed_link_params($action, $params));
	}

	public function apply_inline_params(&$params)
	{
		if ($this->is_inline())
		{
			$params['filter_object_id'] = Sanitizer::get_var('filter_object_id', 'int');
		}
		return $params;
	}

	protected function get_parent_if_inline()
	{
		return $this->is_inline() ? $this->bo->read_object($this->get_parent_id()) : null;
	}

	public function get_parent_id()
	{
		$inlineParams = $this->get_inline_params();
		return $inlineParams['filter_object_id'];
	}

	public function get_inline_params()
	{
		return array('filter_object_id' => Sanitizer::get_var('filter_object_id', 'int', 'REQUEST'));
	}

	public function is_inline()
	{
		return false != Sanitizer::get_var('filter_object_id', 'int', 'REQUEST');
	}

	public static function generate_inline_link($object_type, $permissionObjectId, $action)
	{
		return self::link(array(
			'menuaction' => sprintf('booking.uipermission_%s.%s', $object_type, $action),
			'filter_object_id' => $permissionObjectId
		));
	}

	public function index()
	{
		if (Sanitizer::get_var('phpgw_return_as') == 'json')
		{
			return $this->query();
		}

		$this->redirect_to_parent_if_inline();

		$data = array(
			'datatable_name' => $this->display_name,
			'form' => array(
				'toolbar' => array(
					'item' => array()
				),
			),
			'datatable' => array(
				'source' => $this->get_object_typed_link('index', array('phpgw_return_as' => 'json')),
				'field' => array(
					array(
						'key' => 'subject_name',
						'label' => lang('User'),
					),
					array(
						'key' => 'object_name',
						'label' => lang($this->get_object_type()),
					),
					array(
						'key' => 'role',
						'label' => lang('Role'),
					),
					array(
						'key' => 'option_edit',
						'label' => lang('Edit'),
						'formatter' => 'JqueryPortico.formatLinkGeneric',
						'sortable' => false
					),
					array(
						'key' => 'option_delete',
						'label' => lang('Delete'),
						'formatter' => 'JqueryPortico.formatLinkGeneric',
						'sortable' => false
					),
					// array(
					// 	'key' => 'link',
					// 	'hidden' => true
					// )
				)
			)
		);

		if ($this->bo->allow_create())
		{
			$data['datatable']['actions'][] = array(
				'my_name'	 => 'new_item',
				'className'	 => 'save',
				'type'		 => 'custom',
				'statustext' => lang(sprintf('New %s Permission', self::humanize($this->get_object_type()))),
				'text'		 => lang(sprintf('New %s Permission', self::humanize($this->get_object_type()))),
				'custom_code'	 => 'window.open("' . $this->get_object_typed_link('add') . '", "_self");',
			);
		}
		else
		{
			$data['datatable']['actions'][] = array();
		}

		self::render_template_xsl('datatable2', $data);
	}

	public function query()
	{
		$permissions = $this->bo->read();
		$accounts_obj = new Accounts();
		foreach ($permissions['results'] as &$permission)
		{
			$permission['link'] = $this->get_object_typed_link('edit', array('id' => $permission['id']));
			$permission['role'] = lang(self::humanize($permission['role']));
			#$permission['active'] = $permission['active'] ? lang('Active') : lang('Inactive');

			$permission_actions = array();


			try
			{
				if ($this->bo->allow_write($permission))
				{
					$permission['option_edit'] = $this->get_object_typed_link('edit', array('id' => $permission['id']));
				}
			}
			catch (booking_unauthorized_exception $ex)
			{
				//nothing
			}

			try
			{
				if ($this->bo->allow_delete($permission))
				{
					$permission['option_delete'] = $this->get_object_typed_link('delete', array(
						'id' => $permission['id']
					));
				}
			}
			catch (booking_unauthorized_exception $ex)
			{
				//nothing
			}

			//				if ($this->bo->allow_write($permission))
			//				{
			//					$permission['option_edit'] = $this->get_object_typed_link('edit', array('id' => $permission['id']));
			//				}
			//				if ($this->bo->allow_delete($permission))
			//				{
			//					$permission['option_delete'] = $this->get_object_typed_link('delete', array(
			//						'id' => $permission['id']));
			//				}

			$account_id = $accounts_obj->name2id($permission['subject_name']);
			if ($account_id)
			{
				$permission['subject_name'] = $accounts_obj->get($account_id)->__toString();
			}

			$permission['actions'] = $permission_actions;
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
		$permission_data['parent_pathway'] = $this->get_parent_pathway($permission_data);
		$permission_data['object_type'] = $this->get_object_type();
		$permission_data['object_type_label'] = ucfirst($permission_data['object_type']);
		$permission_data['inline'] = $this->is_inline();
		$permission_data['available_roles'] = $this->get_available_roles();
		$permission_data['permissions_link'] = $this->get_object_typed_link('index');
		$permission_data['cancel_link'] = $this->get_object_typed_link('index');
	}

	public function show()
	{
		#$this->check_active('booking.uipermission_building.show');
		$id = Sanitizer::get_var('id', 'int');
		if (!$id)
		{
			phpgw::no_access('booking', lang('missing id'));
		}
		$permission = $this->bo->read_single($id);
		if (!$permission)
		{
			phpgw::no_access('booking', lang('missing entry. Id %1 is invalid', $id));
		}
		$this->add_default_display_data($permission);
		self::render_template_xsl('permission', array('permission' => $permission));
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
				try
				{
					$receipt = $this->bo->add($permission);
					$this->redirect_to_parent_if_inline();
					self::redirect($this->get_object_typed_link_params('index'));
				}
				catch (booking_unauthorized_exception $e)
				{
					$errors['global'] = lang('Could not add object due to insufficient permissions');
				}
			}
		}

		self::add_javascript('booking', 'base', 'permission.js');
		phpgwapi_jquery::load_widget('autocomplete');

		$this->add_default_display_data($permission);

		if (is_array($parentData = $this->get_parent_if_inline()))
		{
			$permission['object_id'] = $parentData['id'];
			$permission['object_name'] = $parentData['name'];
		}

		$this->flash_form_errors($errors);

		$tabs = array();
		$tabs['generic'] = array('label' => lang('edit permission'), 'link' => '#permission');
		$active_tab = 'generic';

		$permission['tabs'] = phpgwapi_jquery::tabview_generate($tabs, $active_tab);
		$permission['validator'] = phpgwapi_jquery::formvalidator_generate(array(
			'location',
			'date', 'security', 'file'
		));

		self::render_template_xsl('permission_form', array('permission' => $permission));
	}

	public function edit()
	{
		$id = Sanitizer::get_var('id', 'int');
		if (!$id)
		{
			phpgw::no_access('booking', lang('missing id'));
		}
		$permission = $this->bo->read_single($id);
		if (!$permission)
		{
			phpgw::no_access('booking', lang('missing entry. Id %1 is invalid', $id));
		}

		$errors = array();
		if ($_SERVER['REQUEST_METHOD'] == 'POST')
		{
			$permission = array_merge($permission, extract_values($_POST, $this->fields));
			$errors = $this->bo->validate($permission);
			if (!$errors)
			{
				try
				{
					$receipt = $this->bo->update($permission);
					$this->redirect_to_parent_if_inline();
					self::redirect($this->get_object_typed_link_params('index'));
				}
				catch (booking_unauthorized_exception $e)
				{
					$errors['global'] = lang('Could not update object due to insufficient permissions');
				}
			}
		}

		self::add_javascript('booking', 'base', 'permission.js');
		phpgwapi_jquery::load_widget('autocomplete');

		$this->add_default_display_data($permission);

		$this->flash_form_errors($errors);

		$tabs = array();
		$tabs['generic'] = array('label' => lang('edit permission'), 'link' => '#permission');
		$active_tab = 'generic';

		$permission['tabs'] = phpgwapi_jquery::tabview_generate($tabs, $active_tab);
		$permission['validator'] = phpgwapi_jquery::formvalidator_generate(array(
			'location',
			'date', 'security', 'file'
		));

		self::render_template_xsl('permission_form', array('permission' => $permission));
	}

	public function delete()
	{
		$id = Sanitizer::get_var('id', 'int');
		$this->bo->delete($id);

		$this->redirect_to_parent_if_inline();
		self::redirect($this->get_object_typed_link_params('index'));
	}

	/**
	 * Implement to return the full hierarchical pathway to this permission's object(s).
	 *
	 * @param array $forPermissionData
	 *
	 * @return array of url(s) to owner(s) in order of hierarchy.
	 */
	protected function get_parent_pathway(array $forPermissionData)
	{
		return array();
	}
}
