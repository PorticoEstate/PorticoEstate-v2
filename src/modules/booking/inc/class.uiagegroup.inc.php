<?php

use App\modules\phpgwapi\services\Settings;

phpgw::import_class('booking.uicommon');

phpgw::import_class('booking.uidocument_building');
phpgw::import_class('booking.uipermission_building');

//	phpgw::import_class('phpgwapi.uicommon_jquery');

class booking_uiagegroup extends booking_uicommon
{

	public $public_functions = array(
		'index'					 => true,
		'query'					 => true,
		'add'					 => true,
		'active'				 => true,
		'edit'					 => true,
		'toggle_show_inactive'	 => true,
	);
	var $bo, $activity_bo;

	public function __construct()
	{
		parent::__construct();

		//			Analizar esta linea de permisos self::process_booking_unauthorized_exceptions();

		$this->bo = CreateObject('booking.boagegroup');
		$this->activity_bo = CreateObject('booking.boactivity');

		self::set_active_menu('booking::settings::agegroup');
		Settings::getInstance()->update('flags', ['app_header' => lang('booking') . "::" . lang('agegroup')]);
	}

	public function active()
	{
		if (isset($_SESSION['showall']) && !empty($_SESSION['showall']))
		{
			$this->bo->unset_show_all_objects();
		}
		else
		{
			$this->bo->show_all_objects();
		}
		self::redirect(array('menuaction' => 'booking.uiagegroup.index'));
	}

	function treeitem($children, $parent_id)
	{
		$nodes = array();
		foreach ($children[$parent_id] as $activity)
		{
			$nodes[] = array("type" => "text", "label" => $activity['name'], 'children' => $this->treeitem($children, $activity['id']));
		}
		return $nodes;
	}

	public function index()
	{
		if (Sanitizer::get_var('phpgw_return_as') == 'json')
		{
			return $this->query();
		}

		$data = array(
			'form' => array(
				'toolbar' => array(),
			),
			'datatable' => array(
				'source' => self::link(array('menuaction' => 'booking.uiagegroup.index', 'phpgw_return_as' => 'json')),
				'field' => array(
					array(
						'key' => 'sort',
						'label' => lang('Order')
					),
					array(
						'key' => 'name',
						'label' => lang('Name'),
						'formatter' => 'JqueryPortico.formatLink'
					),
					array(
						'key' => 'activity_name',
						'label' => lang('activity')
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
			$data['datatable']['new_item'] = self::link(array('menuaction' => 'booking.uiagegroup.add'));
		}

		$data['datatable']['actions'][] = array(
			'my_name'	 => 'toggle_inactive',
			'className'	 => 'save',
			'type'		 => 'custom',
			'statustext' => $_SESSION['showall'] ? lang('Show only active') : lang('Show all'),
			'text'		 => $_SESSION['showall'] ? lang('Show only active') : lang('Show all'),
			'custom_code'	 => 'window.open("' . self::link(array('menuaction' => $this->url_prefix . '.toggle_show_inactive')) . '", "_self");',
		);

		try
		{
			if (!$this->bo->allow_write())
			{
				//Remove link to edit
				unset($data['datatable']['field'][0]['formatter']);
				unset($data['datatable']['field'][2]);
			}
		}
		catch (booking_unauthorized_exception $exc)
		{
			//Remove link to edit
			unset($data['datatable']['field'][0]['formatter']);
			unset($data['datatable']['field'][2]);
		}


		self::render_template_xsl('datatable2', $data);
	}

	public function query()
	{
		$groups = $this->bo->read();
		foreach ($groups['results'] as &$agegroup)
		{
			$agegroup['link'] = $this->link(array(
				'menuaction' => 'booking.uiagegroup.edit',
				'id' => $agegroup['id']
			));
			$agegroup['active'] = $agegroup['active'] ? lang('Active') : lang('Inactive');
		}
		return $this->jquery_results($groups);
	}

	public function add()
	{
		$errors = array();
		$agegroup = array();

		$activity_id = Sanitizer::get_var('activity_id', 'int', 'POST');
		$activities = $this->activity_bo->get_top_level($activity_id);

		if ($_SERVER['REQUEST_METHOD'] == 'POST')
		{
			$agegroup = extract_values($_POST, array('activity_id', 'name', 'sort', 'description'));
			$agegroup['active'] = true;
			$errors = $this->bo->validate($agegroup);
			if (!$errors)
			{
				$receipt = $this->bo->add($agegroup);
				self::redirect(array('menuaction' => 'booking.uiagegroup.index', 'id' => $receipt['id']));
			}
		}
		$this->flash_form_errors($errors);
		$agegroup['cancel_link'] = self::link(array('menuaction' => 'booking.uiagegroup.index'));
		array_set_default($agegroup, 'sort', '0');

		$tabs = array();
		$tabs['generic'] = array('label' => lang('add agegroup'), 'link' => '#agegroup_add');
		$active_tab = 'generic';

		$agegroup['tabs'] = phpgwapi_jquery::tabview_generate($tabs, $active_tab);
		phpgwapi_jquery::formvalidator_generate(array());

		self::render_template_xsl('agegroup_new', array('agegroup' => $agegroup, 'activities' => $activities));
	}

	public function edit()
	{
		$id = Sanitizer::get_var('id', 'int');
		if (!$id)
		{
			phpgw::no_access('booking', lang('missing id'));
		}
		$resource = $this->bo->read_single($id);
		if (!$resource)
		{
			phpgw::no_access('booking', lang('missing entry. Id %1 is invalid', $id));
		}
		$activities = $this->activity_bo->get_top_level($resource['activity_id']);

		$resource['id'] = $id;
		$resource['resource_link'] = self::link(array(
			'menuaction' => 'booking.uiagegroup.show',
			'id' => $resource['id']
		));
		$resource['resources_link'] = self::link(array('menuaction' => 'booking.uiresource.index'));
		$resource['agegroup_link'] = self::link(array('menuaction' => 'booking.uiagegroup.index'));
		$resource['building_link'] = self::link(array('menuaction' => 'booking.uibuilding.index'));
		$errors = array();
		if ($_SERVER['REQUEST_METHOD'] == 'POST')
		{
			$resource = array_merge($resource, extract_values($_POST, array(
				'name', 'sort',
				'description', 'active'
			)));
			$errors = $this->bo->validate($resource);
			if (!$errors)
			{
				$receipt = $this->bo->update($resource);
				self::redirect(array('menuaction' => 'booking.uiagegroup.index', 'id' => $resource['id']));
			}
		}
		$this->flash_form_errors($errors);

		$tabs = array();
		$tabs['generic'] = array('label' => lang('edit agegroup'), 'link' => '#agegroup_edit');
		$active_tab = 'generic';

		$resource['tabs'] = phpgwapi_jquery::tabview_generate($tabs, $active_tab);

		$resource['cancel_link'] = self::link(array('menuaction' => 'booking.uiagegroup.index'));
		phpgwapi_jquery::formvalidator_generate(array());
		self::render_template_xsl('agegroup_edit', array('resource' => $resource, 'activities' => $activities));
	}
}
