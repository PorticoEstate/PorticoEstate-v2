<?php

use App\modules\phpgwapi\services\Settings;

phpgw::import_class('booking.uicommon');

class booking_uifacility extends booking_uicommon
{

	public $public_functions = array(
		'index' => true,
		'query' => true,
		'add' => true,
		'edit' => true,
	);

	var $display_name, $fields;

	public function __construct()
	{
		parent::__construct();
		$this->bo = CreateObject('booking.bofacility');
		self::set_active_menu('booking::settings::facility');
		$this->fields = array('name', 'active');
		$this->display_name = lang('facilities');
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
				'source' => self::link(array('menuaction' => 'booking.uifacility.index', 'phpgw_return_as' => 'json')),
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
						'key' => 'link',
						'hidden' => true
					)
				)
			)
		);

		if ($this->bo->allow_create())
		{
			$data['datatable']['new_item'] = self::link(array('menuaction' => 'booking.uifacility.add'));
		}
		$data['datatable']['actions'][] = array();

		self::render_template_xsl('datatable2', $data);
	}


	public function query()
	{
		$search = Sanitizer::get_var('search');
		$order = Sanitizer::get_var('order');
		$columns = Sanitizer::get_var('columns');
		if ($order)
		{
			$sort = $columns[$order[0]['column']]['data'];
			$dir = $order[0]['dir'];
		}
		else
		{
			$sort = 'name';
			$dir = 'asc';
		}

		$params = array(
			'start' => Sanitizer::get_var('start', 'int', 'REQUEST', 0),
			'results' => Sanitizer::get_var('length', 'int', 'REQUEST', -1),
			'query' => $search['value'],
			'order' => $columns[$order[0]['column']]['data'],
			'sort' => $sort,
			'dir' => $dir,
		);

		$facilities = $this->bo->populate_grid_data($params);
		array_walk($facilities['results'], array($this, '_add_links'), 'booking.uifacility.edit');

		return $this->jquery_results($facilities);
	}


	public function add()
	{
		$facility = array();
		$errors = array();
		$tabs = array();
		$tabs['generic'] = array('label' => lang('New facility'), 'link' => '#facility_add');
		$active_tab = 'generic';

		if ($_SERVER['REQUEST_METHOD'] == 'POST')
		{
			$facility = extract_values($_POST, $this->fields);
			$errors = $this->bo->validate($facility);
			if (!$errors)
			{
				try
				{
					$receipt = $this->bo->add($facility);
					self::redirect(array('menuaction' => 'booking.uifacility.index'));
				}
				catch (booking_unauthorized_exception $e)
				{
					$errors['global'] = lang('Could not add object due to insufficient permissions');
				}
			}
		}

		$this->flash_form_errors($errors);
		$facility['cancel_link'] = self::link(array('menuaction' => 'booking.uifacility.index'));
		$facility['tabs'] = phpgwapi_jquery::tabview_generate($tabs, $active_tab);
		$facility['validator'] = phpgwapi_jquery::formvalidator_generate(array());

		self::render_template_xsl('facility_new', array('facility' => $facility));
	}


	public function edit()
	{
		$id = Sanitizer::get_var('id', 'int');
		if (!$id)
		{
			phpgw::no_access('booking', lang('missing id'));
		}
		$facility = $this->bo->read_single($id);
		if (!$facility)
		{
			phpgw::no_access('booking', lang('missing entry. Id %1 is invalid', $id));
		}
		$errors = array();
		$tabs = array();
		$tabs['generic'] = array('label' => lang('edit facility'), 'link' => '#facility_edit');
		$active_tab = 'generic';

		if ($_SERVER['REQUEST_METHOD'] == 'POST')
		{
			$facility = array_merge($facility, extract_values($_POST, $this->fields));
			$errors = $this->bo->validate($facility);
			if (!$errors)
			{
				try
				{
					$receipt = $this->bo->update($facility);
					self::redirect(array('menuaction' => 'booking.uifacility.index'));
				}
				catch (booking_unauthorized_exception $e)
				{
					$errors['global'] = lang('Could not update object due to insufficient permissions');
				}
			}
		}

		$this->flash_form_errors($errors);
		$facility['cancel_link'] = self::link(array('menuaction' => 'booking.uifacility.index'));
		$facility['tabs'] = phpgwapi_jquery::tabview_generate($tabs, $active_tab);
		$facility['validator'] = phpgwapi_jquery::formvalidator_generate(array());

		self::render_template_xsl('facility_edit', array('facility' => $facility));
	}
}
