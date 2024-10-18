<?php

use App\modules\phpgwapi\services\Settings;

phpgw::import_class('booking.uicommon');

class booking_uirescategory extends booking_uicommon
{

	public $public_functions = array(
		'index' => true,
		'query' => true,
		'add' => true,
		'edit' => true,
	);

	var $fields, $display_name;

	public function __construct()
	{
		parent::__construct();
		$this->bo = CreateObject('booking.borescategory');
		self::set_active_menu('booking::settings::rescategory');
		$this->fields = array('name', 'active', 'activities', 'capacity', 'e_lock', 'parent_id');
		$this->display_name = lang('Resource categories');
		Settings::getInstance()->update('flags', ['app_header' => lang('booking') . "::{$this->display_name}"]);
	}


	public function index()
	{
		if (Sanitizer::get_var('phpgw_return_as') == 'json')
		{
			return $this->query();
		}

		$data = array(
			'datatable_name' => $this->display_name,
			'datatable' => array(
				'source' => self::link(array('menuaction' => 'booking.uirescategory.index', 'phpgw_return_as' => 'json')),
				'sorted_by' => array('key' => 0),
				'field' => array(
					array(
						'key' => 'name',
						'label' => lang('Name'),
						'formatter' => 'JqueryPortico.formatLink'
					),
					array(
						'key' => 'active',
						'label' => lang('Active')
					),
					array(
						'key' => 'activities_name',
						'label' => lang('Activities'),
						'sortable' => false,
					),
					array(
						'key' => 'capacity',
						'label' => lang('capacity'),
					),
					array(
						'key' => 'e_lock',
						'label' => lang('Electronic lock'),
					),
					array(
						'key' => 'link',
						'hidden' => true
					)
				)
			)
		);

		if ($this->bo->allow_create())
		{
			$data['datatable']['new_item'] = self::link(array('menuaction' => 'booking.uirescategory.add'));
		}
		$data['datatable']['actions'][] = array();

		self::render_template_xsl('datatable2', $data);
	}


	public function query()
	{
		$search = Sanitizer::get_var('search');
		$order = Sanitizer::get_var('order');
		$columns = Sanitizer::get_var('columns');

		$params = array(
			'start' => Sanitizer::get_var('start', 'int', 'REQUEST', 0),
			'results' => Sanitizer::get_var('length', 'int', 'REQUEST', -1),
			'query' => $search['value'],
			'order' => $columns[$order[0]['column']]['data'],
			'sort' => $columns[$order[0]['column']]['data'],
			'dir' => $order[0]['dir'],
		);

		$rescategories = $this->bo->populate_grid_data($params);
		array_walk($rescategories['results'], array($this, '_add_links'), 'booking.uirescategory.edit');

		return $this->jquery_results($rescategories);
	}


	public function add()
	{
		$rescategory = array();
		$errors = array();
		$tabs = array();
		$tabs['generic'] = array('label' => lang('New resource category'), 'link' => '#rescategory_add');
		$active_tab = 'generic';

		if ($_SERVER['REQUEST_METHOD'] == 'POST')
		{
			array_set_default($_POST, 'activities', array());
			$rescategory = extract_values($_POST, $this->fields);
			$rescategory['capacity'] = Sanitizer::get_var('capacity', 'bool', 'POST');
			$rescategory['e_lock'] = Sanitizer::get_var('e_lock', 'bool', 'POST');
			$rescategory['parent_id'] = Sanitizer::get_var('parent_id', 'int', 'POST');
			$errors = $this->bo->validate($rescategory);
			if (!$errors)
			{
				try
				{
					$receipt = $this->bo->add($rescategory);
					self::redirect(array('menuaction' => 'booking.uirescategory.index'));
				}
				catch (booking_unauthorized_exception $e)
				{
					$errors['global'] = lang('Could not add object due to insufficient permissions');
				}
			}
		}
		$parent_list = $this->bo->get_parents();
		$this->flash_form_errors($errors);
		self::add_javascript('booking', 'base', 'rescategory.js');
		$rescategory['activities_json'] = json_encode(array_map('intval', (array)$rescategory['activities']));
		$rescategory['cancel_link'] = self::link(array('menuaction' => 'booking.uirescategory.index'));
		$rescategory['tabs'] = phpgwapi_jquery::tabview_generate($tabs, $active_tab);
		$rescategory['validator'] = phpgwapi_jquery::formvalidator_generate(array());

		self::render_template_xsl('rescategory_new', array(
			'rescategory' => $rescategory,
			'parent_list' => $parent_list
		));
	}


	public function edit()
	{
		$id = Sanitizer::get_var('id', 'int');
		if (!$id)
		{
			phpgw::no_access('booking', lang('missing id'));
		}
		$rescategory = $this->bo->read_single($id);
		if (!$rescategory)
		{
			phpgw::no_access('booking', lang('missing entry. Id %1 is invalid', $id));
		}
		$errors = array();
		$tabs = array();
		$tabs['generic'] = array('label' => lang('edit resource category'), 'link' => '#rescategory_edit');
		$active_tab = 'generic';

		if ($_SERVER['REQUEST_METHOD'] == 'POST')
		{
			array_set_default($_POST, 'activities', array());
			$rescategory = array_merge($rescategory, extract_values($_POST, $this->fields));
			$rescategory['capacity'] = Sanitizer::get_var('capacity', 'bool', 'POST');
			$rescategory['e_lock'] = Sanitizer::get_var('e_lock', 'bool', 'POST');
			$rescategory['parent_id'] = Sanitizer::get_var('parent_id', 'int', 'POST');

			$errors = $this->bo->validate($rescategory);
			if (!$errors)
			{
				try
				{
					$receipt = $this->bo->update($rescategory);
					self::redirect(array('menuaction' => 'booking.uirescategory.index'));
				}
				catch (booking_unauthorized_exception $e)
				{
					$errors['global'] = lang('Could not update object due to insufficient permissions');
				}
			}
		}
		$parent_list = $this->bo->get_parents($id);

		$this->flash_form_errors($errors);
		self::add_javascript('booking', 'base', 'rescategory.js');
		$rescategory['activities_json'] = json_encode(array_map('intval', $rescategory['activities']));
		$rescategory['cancel_link'] = self::link(array('menuaction' => 'booking.uirescategory.index'));
		$rescategory['tabs'] = phpgwapi_jquery::tabview_generate($tabs, $active_tab);
		$rescategory['validator'] = phpgwapi_jquery::formvalidator_generate(array());

		self::render_template_xsl('rescategory_edit', array(
			'rescategory' => $rescategory,
			'parent_list' => $parent_list
		));
	}
}
