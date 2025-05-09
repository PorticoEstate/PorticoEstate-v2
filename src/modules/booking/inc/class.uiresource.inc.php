<?php

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\Translation;
use App\modules\phpgwapi\services\Hooks;

phpgw::import_class('booking.uicommon');
phpgw::import_class('booking.uidocument_resource');
phpgw::import_class('booking.uipermission_resource');

phpgw::import_class('booking.uidocument_building');
phpgw::import_class('booking.uipermission_building');

//	phpgw::import_class('phpgwapi.uicommon_jquery');

class booking_uiresource extends booking_uicommon
{

	public $public_functions = array(
		'index' => true,
		'query' => true,
		'add' => true,
		'edit' => true,
		'edit_activities' => true,
		'edit_facilities' => true,
		'get_custom' => true,
		'show' => true,
		'schedule' => true,
		'toggle_show_inactive' => true,
		'get_rescategories' => true,
		'get_buildings' => true,
		'add_building' => true,
		'remove_building' => true,
		'get_e_locks'	=> true,
		'add_e_lock'	=> true,
		'remove_e_lock'	=> true,
		'get_participant_limit' => true,
		'add_participant_limit' => true
	);

	var $fields, $display_name, $sobuilding, $activity_bo, $facility_bo, $rescategory_bo;

	public function __construct()
	{
		parent::__construct();
		$this->sobuilding = CreateObject('booking.sobuilding');

		//			Analizar esta linea de permiso self::process_booking_unauthorized_exceptions();

		$this->bo = CreateObject('booking.boresource');
		$this->activity_bo = CreateObject('booking.boactivity');
		$this->facility_bo = CreateObject('booking.bofacility');
		$this->rescategory_bo = CreateObject('booking.borescategory');
		$this->fields = array(
			'name'							 => 'string',
			'description_json'				 => 'html',
			'opening_hours'					 => 'html',
			'contact_info'					 => 'html',
			'activity_id'					 => 'int',
			'active'						 => 'int',
			'capacity'						 => 'int',
			'sort'							 => 'string',
			'organizations_ids'				 => 'string',
			'rescategory_id'				 => 'int',
			'activities'					 => 'int',
			'facilities'					 => 'int',
			'direct_booking'				 => 'string',
			'simple_booking'				 => 'int',
			'booking_day_default_lenght'	 => 'int',
			'booking_dow_default_start'		 => 'int',
			//				'booking_dow_default_end' => 'int',
			'booking_time_default_start'	 => 'int',
			'booking_time_default_end'		 => 'int',
			'booking_time_minutes'			 => 'int',
			'booking_buffer_deadline'		 => 'int',
			'booking_limit_number'			 => 'int',
			'booking_limit_number_horizont'	 => 'int',
			'simple_booking_start_date'		 => 'string',
			'simple_booking_end_date'		 => 'string',
			'booking_month_horizon'			 => 'int',
			'booking_day_horizon'			 => 'int',
			'deactivate_application'		 => 'int',
			'hidden_in_frontend'			 => 'int',
			'activate_prepayment'			 => 'int',
			'deny_application_if_booked'	 => 'int',
		);
		self::set_active_menu('booking::buildings::resources::resources');
		$this->display_name = lang('resources');
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
			'form' => array(
				'toolbar' => array(
					'item' => array(
						array(
							'type' => 'filter',
							'name' => 'filter_simple_booking',
							'text' => lang('Simple booking') . ':',
							'list' => array(
								array(
									'id' => '',
									'name' => lang('Not selected')
								),
								array(
									'id' => '1',
									'name' => lang('Simple booking'),
								),
							)
						),
						//							array(
						//								'type' => 'link',
						//								'value' => $_SESSION['showall'] ? lang('Show only active') : lang('Show all'),
						//								'href' => self::link(array('menuaction' => $this->url_prefix . '.toggle_show_inactive'))
						//							),
					)
				),
			),
			'datatable' => array(
				'source' => self::link(array('menuaction' => 'booking.uiresource.index', 'phpgw_return_as' => 'json')),
				'field' => array(
					array(
						'key'	 => 'id',
						'label'	 => lang('id'),
					),
					array(
						'key' => 'name',
						'label' => lang('Resource Name'),
						'formatter' => 'JqueryPortico.formatLink'
					),
					array(
						'key' => 'sort',
						'label' => lang('Order')
					),
					array(
						'key' => 'link',
						'hidden' => true
					),
					array(
						'key' => 'building_name',
						'label' => lang('Building name'),
						'sortable' => false
					),
					array(
						'key' => 'activity_name',
						'label' => lang('Main activity')
					),
					array(
						'key' => 'rescategory_name',
						'label' => lang('Resource category'),
					),
					array(
						'key' => 'building_street',
						'label' => lang('Street'),
						'sortable' => false
					),
					array(
						'key' => 'building_city',
						'label' => lang('Postal city'),
						'sortable' => false
					),
					array(
						'key' => 'building_district',
						'label' => lang('District'),
						'sortable' => false
					),
					array(
						'key' => 'active',
						'label' => lang('Active'),
					),
					array(
						'key' => 'simple_booking',
						'label' => lang('Simple booking'),
					),
					array(
						'key' => 'direct_booking',
						'label' => lang('direct booking'),
					),
				)
			)
		);

		$data['datatable']['actions'][] = array(
			'my_name'	 => 'toggle_inactive',
			'className'	 => 'save',
			'type'		 => 'custom',
			'statustext' => $_SESSION['showall'] ? lang('Show only active') : lang('Show all'),
			'text'		 => $_SESSION['showall'] ? lang('Show only active') : lang('Show all'),
			'custom_code'	 => 'window.open("' . self::link(array('menuaction' => $this->url_prefix . '.toggle_show_inactive')) . '", "_self");',
		);

		if ($this->bo->allow_create())
		{
			$data['datatable']['new_item'] = self::link(array('menuaction' => 'booking.uiresource.add'));
		}

		self::render_template_xsl('datatable2', $data);
	}

	public function query()
	{
		$values = $this->bo->populate_grid_data("booking.uiresource.show");

		foreach ($values['results'] as &$entry)
		{
			$entry['direct_booking'] = !empty($entry['direct_booking']) ? 1 : '';
		}

		return $this->jquery_results($values);
	}

	public function add()
	{
		$errors = array();
		$resource = array();
		$resource['sort'] = '0';

		if ($_SERVER['REQUEST_METHOD'] == 'POST')
		{
			$resource = extract_values($_POST, $this->fields);
			$resource['active'] = '1';
			$building_id = Sanitizer::get_var('building_id', 'int');
			$resource['buildings'][] = $building_id;
			$building = $this->sobuilding->read_single($building_id);
			$resource['activity_id'] = $building['activity_id'];

			$errors = $this->bo->validate($resource);
			if (!$errors)
			{
				try
				{
					$receipt = $this->bo->add($resource);
					$hooks = new Hooks();
					$hooks->single('resource_add', 'booking');
					self::redirect(array('menuaction' => 'booking.uiresource.show', 'id' => $receipt['id']));
				}
				catch (booking_unauthorized_exception $e)
				{
					$errors['global'] = lang('Could not add object due to insufficient permissions');
				}
			}
		}

		$this->flash_form_errors($errors);
		self::add_javascript('booking', 'base', 'resource_new.js');
		phpgwapi_jquery::load_widget('autocomplete');


		$translation = Translation::getInstance();
		$_langs = $translation->get_installed_langs();
		$langs = array();

		foreach ($_langs as $key => $name)	// if we have a translation use it
		{
			$trans = mb_convert_case(lang($name), MB_CASE_LOWER);
			$langs[] = array(
				'lang' => $key,
				'name' => $trans != "!$name" ? $trans : $name,
				'description' => !empty($resource['description_json'][$key]) ? $resource['description_json'][$key] : ''
			);

			self::rich_text_editor(array("field_description_json_{$key}"));
		}

		self::rich_text_editor(array('field_opening_hours', 'field_contact_info'));
		$activity_data = $this->activity_bo->fetch_activities();
		$resource['cancel_link'] = self::link(array('menuaction' => 'booking.uiresource.index'));
		$tabs = array();
		$tabs['generic'] = array('label' => lang('edit permission'), 'link' => '#resource');
		$active_tab = 'generic';

		$jqcal = createObject('phpgwapi.jqcal');
		$jqcal->add_listener('direct_booking');
		$jqcal->add_listener('simple_booking_start_date');
		$jqcal->add_listener('simple_booking_end_date');

		$resource['tabs'] = phpgwapi_jquery::tabview_generate($tabs, $active_tab);
		$resource['validator'] = phpgwapi_jquery::formvalidator_generate(array(
			'location',
			'date',
			'security',
			'file'
		));

		self::render_template_xsl('resource_form', array(
			'resource'	   => $resource,
			'activitydata' => $activity_data,
			'langs'		   => $langs,
			'new_form'	   => true
		));
	}


	public function edit()
	{
		Settings::getInstance()->update('flags', ['allow_html_image' => true, 'allow_html_iframe' => true]);
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
		$resource['id'] = $id;
		$resource['building_link'] = self::link(array(
			'menuaction' => 'booking.uibuilding.show',
			'id' => $resource['id']
		));
		$resource['buildings_link'] = self::link(array('menuaction' => 'booking.uibuilding.index'));
		$resource['cancel_link'] = self::link(array(
			'menuaction' => 'booking.uiresource.show',
			'id' => $resource['id']
		));

		$errors = array();
		if ($_SERVER['REQUEST_METHOD'] == 'POST')
		{
			$resource = array_merge($resource, extract_values($_POST, $this->fields));

			$resource['simple_booking'] = Sanitizer::get_var('simple_booking', 'bool', 'POST');
			$errors = $this->bo->validate($resource);
			$location = $this->get_location();
			$location_id = $this->locations->get_id('booking', $location);

			$fields = ExecMethod('booking.custom_fields.get_fields', $location);
			$values_attribute = Sanitizer::get_var('values_attribute');
			$resource['json_representation'] = array();
			$json_representation = array();
			foreach ($fields as $attrib_id => $attrib)
			{
				$json_representation[$attrib['name']] = isset($values_attribute[$attrib_id]['value']) ? $values_attribute[$attrib_id]['value'] : null;
			}

			$resource['json_representation'][$location_id] = $json_representation;

			if (!$errors)
			{
				$receipt = $this->bo->update($resource);
				self::redirect(array('menuaction' => 'booking.uiresource.show', 'id' => $resource['id']));
			}
		}

		$this->flash_form_errors($errors);
		$translation = Translation::getInstance();
		$_langs = $translation->get_installed_langs();
		$langs = array();

		foreach ($_langs as $key => $name)	// if we have a translation use it
		{
			$trans = mb_convert_case(lang($name), MB_CASE_LOWER);
			$langs[] = array(
				'lang' => $key,
				'name' => $trans != "!$name" ? $trans : $name,
				'description' => !empty($resource['description_json'][$key]) ? $resource['description_json'][$key] : ''
			);

			self::rich_text_editor(array("field_description_json_{$key}"));
		}

		self::add_javascript('booking', 'base', 'resource_new.js');
		phpgwapi_jquery::load_widget('autocomplete');
		self::rich_text_editor(array('field_opening_hours', 'field_contact_info'));
		$activity_data = $this->activity_bo->fetch_activities();
		foreach ($activity_data['results'] as $acKey => $acValue)
		{
			$activity_data['results'][$acKey]['resource_id'] = $resource['activity_id'];
		}
		$activity_path = $this->activity_bo->get_path($resource['activity_id']);
		$top_level_activity = $activity_path ? $activity_path[0]['id'] : 0;
		$rescategory_data = $this->rescategory_bo->get_rescategories_by_activities($top_level_activity);
		$tabs = array();
		$tabs['generic'] = array('label' => lang('edit permission'), 'link' => '#resource');
		$active_tab = 'generic';

		$resource['tabs'] = phpgwapi_jquery::tabview_generate($tabs, $active_tab);
		$resource['validator'] = phpgwapi_jquery::formvalidator_generate(array(
			'location',
			'date',
			'security',
			'file'
		));
		$jqcal = createObject('phpgwapi.jqcal');
		$jqcal2 = createObject('phpgwapi.jqcal2');

		$jqcal->add_listener('direct_booking');
		$jqcal2->add_listener('simple_booking_start_date', 'datetime', !empty($resource['simple_booking_start_date']) ? $resource['simple_booking_start_date'] : 0, array('readonly' => true));
		$jqcal->add_listener('simple_booking_end_date');
		$jqcal2->add_listener('participant_limit_from', 'date');

		self::render_template_xsl(
			array('resource_form', 'datatable_inline'),
			array(
				'datatable_def'	  => self::get_datatable_def($id),
				'resource'		  => $resource,
				'activitydata'	  => $activity_data,
				'rescategorydata' => $rescategory_data,
				'seasons'		  => $this->bo->so->get_seasons($id),
				'langs'			  => $langs
			)
		);
	}

	private function get_location()
	{
		$activity_id = Sanitizer::get_var('schema_activity_id', 'int');
		$activity_path = $this->activity_bo->get_path($activity_id);
		$top_level_activity = $activity_path ? $activity_path[0]['id'] : 0;
		return ".resource.{$top_level_activity}";
	}


	public function edit_activities()
	{
		$id = Sanitizer::get_var('id', 'int');
		$resource = $this->bo->read_single($id);
		$resource['id'] = $id;

		$errors = array();
		$tabs = array();
		$tabs['generic'] = array('label' => lang('edit resource activities'), 'link' => '#resource_edit_activities');
		$active_tab = 'generic';

		if ($_SERVER['REQUEST_METHOD'] == 'POST')
		{
			array_set_default($_POST, 'activities', array());
			$resource = array_merge($resource, extract_values($_POST, $this->fields));
			$errors = $this->bo->validate($resource);
			if (!$errors)
			{
				// Exclude any activities that don't belong to the main activity. Such activities can't normally be
				// added in UI, but check for this nonetheless. Also check that the activities are active
				$activitylist = $this->activity_bo->fetch_activities_hierarchy();
				$childactivities = array();
				if (array_key_exists($resource['activity_id'], $activitylist))
				{
					$childactivities = $activitylist[$resource['activity_id']]['children'];
				}
				$resactivities = array();
				foreach ($resource['activities'] as $activity_id)
				{
					if (array_key_exists($activity_id, $childactivities))
					{
						$childactivity = $childactivities[$activity_id];
						if ($childactivity['active'])
						{
							$resactivities[] = $activity_id;
						}
					}
				}
				$resource['activities'] = $resactivities;

				try
				{
					$receipt = $this->bo->update($resource);
					self::redirect(array('menuaction' => 'booking.uiresource.show', 'id' => $resource['id']));
				}
				catch (booking_unauthorized_exception $e)
				{
					$errors['global'] = lang('Could not update object due to insufficient permissions');
				}
			}
		}

		$this->flash_form_errors($errors);
		$resource['activities_json'] = json_encode(array_map('intval', $resource['activities']));
		$resource['cancel_link'] = self::link(array('menuaction' => 'booking.uiresource.show', 'id' => $resource['id']));
		$resource['tabs'] = phpgwapi_jquery::tabview_generate($tabs, $active_tab);
		$resource['validator'] = phpgwapi_jquery::formvalidator_generate(array());

		self::render_template_xsl('resource_edit_activities', array('resource' => $resource));
	}


	public function edit_facilities()
	{
		$id = Sanitizer::get_var('id', 'int');
		$resource = $this->bo->read_single($id);
		$resource['id'] = $id;

		$errors = array();
		$tabs = array();
		$tabs['generic'] = array('label' => lang('edit resource facilities'), 'link' => '#resource_edit_facilities');
		$active_tab = 'generic';

		if ($_SERVER['REQUEST_METHOD'] == 'POST')
		{
			array_set_default($_POST, 'facilities', array());
			$resource = array_merge($resource, extract_values($_POST, $this->fields));
			$errors = $this->bo->validate($resource);
			if (!$errors)
			{
				// Unlike the editing of activities, a check for active facilities is not done, as adding an
				// inactive facility in UI is unlikely and the consequences are not grave (an inactive facility
				// will be excluded when the resource is used)
				try
				{
					$receipt = $this->bo->update($resource);
					self::redirect(array('menuaction' => 'booking.uiresource.show', 'id' => $resource['id']));
				}
				catch (booking_unauthorized_exception $e)
				{
					$errors['global'] = lang('Could not update object due to insufficient permissions');
				}
			}
		}

		$this->flash_form_errors($errors);
		$resource['facilities_json'] = json_encode(array_map('intval', $resource['facilities']));
		$resource['cancel_link'] = self::link(array('menuaction' => 'booking.uiresource.show', 'id' => $resource['id']));
		$resource['tabs'] = phpgwapi_jquery::tabview_generate($tabs, $active_tab);
		$resource['validator'] = phpgwapi_jquery::formvalidator_generate(array());

		self::render_template_xsl('resource_edit_facilities', array('resource' => $resource));
	}


	public function get_custom()
	{
		$type = Sanitizer::get_var('type', 'string', 'REQUEST', 'form');
		$resource_id = Sanitizer::get_var('resource_id', 'int');
		$resource = $this->bo->read_single($resource_id);
		$location = $this->get_location();
		$location_id = $this->locations->get_id('booking', $location);
		$custom_values = $resource['json_representation'][$location_id];
		$custom_fields = createObject('booking.custom_fields');
		$fields = $custom_fields->get_fields($location);

		if (!$fields)
		{
			Settings::getInstance()->update('flags', ['xslt_app' => false]);
			return false;
		}

		foreach ($fields as $attrib_id => &$attrib)
		{
			$attrib['value'] = isset($custom_values[$attrib['name']]) ? $custom_values[$attrib['name']] : null;

			if (isset($attrib['choice']) && is_array($attrib['choice']) && $attrib['value'])
			{
				foreach ($attrib['choice'] as &$choice)
				{
					if (is_array($attrib['value']))
					{
						$choice['selected'] = in_array($choice['id'], $attrib['value']) ? 1 : 0;
					}
					else
					{
						$choice['selected'] = $choice['id'] == $attrib['value'] ? 1 : 0;
					}
				}
			}
		}
		//			_debug_array($fields);
		$organized_fields = $custom_fields->organize_fields($location, $fields);

		$data = array(
			'attributes_group' => $organized_fields,
		);
		phpgwapi_xslttemplates::getInstance()->add_file(array("attributes_{$type}"));
		phpgwapi_xslttemplates::getInstance()->set_var('phpgw', array('custom_fields' => $data));
	}


	function get_rescategories()
	{
		$activity_id = Sanitizer::get_var('activity_id', 'int');
		$activity_path = $this->activity_bo->get_path($activity_id);
		$top_level_activity = $activity_path ? $activity_path[0]['id'] : 0;
		$rescategory_data = $this->rescategory_bo->get_rescategories_by_activities($top_level_activity);
		return $rescategory_data;
	}

	private static function get_datatable_def($id)
	{
		return array(
			self::get_building_datatable_def($id),
			self::get_e_lock_datatable_def($id),
			self::get_participant_limit_datatable_def($id),
		);
	}

	private static function get_e_lock_columns()
	{

		$columns = array(
			array('key' => 'e_lock_system_id', 'label' => lang('system id'), 'sortable' => false, 'resizeable' => true),
			array('key' => 'e_lock_resource_id', 'label' => lang('resource id'), 'sortable' => true, 'resizeable' => true),
			array('key' => 'e_lock_name', 'label' => lang('name'), 'sortable' => false, 'resizeable' => true),
			array('key' => 'access_code_format', 'label' => lang('access code format'), 'sortable' => false, 'resizeable' => true),
			array('key' => 'access_instruction', 'label' => lang('access instruction'), 'sortable' => false, 'resizeable' => true),
		);
		return $columns;
	}

	private static function get_e_lock_datatable_def($id)
	{
		return	array(
			'container' => 'datatable-container_1',
			'requestUrl' => json_encode(self::link(array(
				'menuaction' => 'booking.uiresource.get_e_locks',
				'resource_id' => $id,
				'phpgw_return_as' => 'json'
			))),
			'ColumnDefs' => self::get_e_lock_columns(),
			'data' => json_encode(array()),
			'config' => array(
				array('disableFilter' => true),
				array('disablePagination' => true)
			)
		);
	}

	public function get_e_locks()
	{
		$resource_id = Sanitizer::get_var('resource_id', 'int');

		$lock_result = $this->bo->so->get_e_locks($resource_id);

		return $this->jquery_results($lock_result);
	}

	private static function get_participant_limit_columns()
	{

		$columns = array(
			array('key' => 'from_', 'label' => lang('from'), 'sortable' => false, 'resizeable' => true),
			array('key' => 'quantity', 'label' => lang('quantity'), 'sortable' => false, 'resizeable' => true),
		);
		return $columns;
	}
	private static function get_participant_limit_datatable_def($id)
	{
		return	array(
			'container' => 'datatable-container_2',
			'requestUrl' => json_encode(self::link(array(
				'menuaction' => 'booking.uiresource.get_participant_limit',
				'resource_id' => $id,
				'phpgw_return_as' => 'json'
			))),
			'ColumnDefs' => self::get_participant_limit_columns(),
			'data' => json_encode(array()),
			'config' => array(
				array('disableFilter' => true),
				array('disablePagination' => true)
			)
		);
	}

	public function get_participant_limit()
	{
		$resource_id = Sanitizer::get_var('resource_id', 'int');

		$result = $this->bo->so->get_participant_limit($resource_id);

		$dateFormat = $this->userSettings['preferences']['common']['dateformat'];
		foreach ($result['results'] as &$entry)
		{
			$entry['from_'] = $this->phpgwapi_common->show_date(strtotime($entry['from_']), $dateFormat);
		}

		return $this->jquery_results($result);
	}

	private static function get_building_columns()
	{
		$columns = array(
			array('key' => 'id', 'label' => '#', 'sortable' => true, 'resizeable' => true),
			array('key' => 'name', 'label' => lang('name'), 'sortable' => true, 'resizeable' => true),
			array('key' => 'street', 'label' => lang('street'), 'sortable' => true, 'resizeable' => true),
			array(
				'key' => 'activity_name',
				'label' => lang('activity'),
				'sortable' => true,
				'resizeable' => true,
				'formatter' => 'ChangeSchema'
			),
			array('key' => 'active', 'label' => lang('active'), 'sortable' => true, 'resizeable' => true)
		);
		return $columns;
	}

	private static function get_building_datatable_def($id)
	{
		return array(
			'container' => 'datatable-container_0',
			'requestUrl' => json_encode(self::link(array(
				'menuaction' => 'booking.uiresource.get_buildings',
				'resource_id' => $id,
				'phpgw_return_as' => 'json'
			))),
			'ColumnDefs' => self::get_building_columns(),
			'data' => json_encode(array()),
			'config' => array(
				array('disableFilter' => true),
				array('disablePagination' => true)
			)
		);
	}

	public function add_e_lock()
	{
		$resource_id = Sanitizer::get_var('resource_id', 'int');
		$e_lock_system_id = Sanitizer::get_var('e_lock_system_id', 'int');
		$e_lock_resource_id = Sanitizer::get_var('e_lock_resource_id', 'string');
		$e_lock_name = Sanitizer::get_var('e_lock_name', 'string');
		$access_code_format = Sanitizer::get_var('access_code_format', 'string');
		$access_instruction = Sanitizer::get_var('access_instruction', 'string');



		if (!$e_lock_system_id || !$e_lock_resource_id)
		{
			return array(
				'ok' => false,
				'msg' => lang('select')
			);
		}

		try
		{
			$resource = $this->bo->read_single($resource_id);
			$receipt = $this->bo->add_e_lock($resource, $resource_id, $e_lock_system_id, $e_lock_resource_id, $e_lock_name, $access_code_format, $access_instruction);
			$msg = $receipt == 1 ? lang('added') : lang('updated');
		}
		catch (booking_unauthorized_exception $e)
		{
			return false;
			$msg = lang('Could not add object due to insufficient permissions');
		}

		return array(
			'ok' => $receipt,
			'msg' => $msg
		);
	}

	public function remove_e_lock()
	{
		$resource_id = Sanitizer::get_var('resource_id', 'int');
		$e_lock_system_id = Sanitizer::get_var('e_lock_system_id', 'int');
		$e_lock_resource_id = Sanitizer::get_var('e_lock_resource_id', 'string');

		if ($e_lock_system_id === null || $e_lock_system_id === '' || $e_lock_resource_id === null || $e_lock_resource_id === '')
		{
			return array(
				'ok' => false,
				'msg' => lang('select')
			);
		}
		try
		{
			$resource = $this->bo->read_single($resource_id);
			$receipt = $this->bo->remove_e_lock($resource, $resource_id, $e_lock_system_id, $e_lock_resource_id);
			$msg = '';
		}
		catch (booking_unauthorized_exception $e)
		{
			return false;
			$msg = lang('Could not update object due to insufficient permissions');
		}

		return array(
			'ok' => $receipt,
			'msg' => $msg
		);
	}

	public function add_participant_limit()
	{
		$resource_id = Sanitizer::get_var('resource_id', 'int');
		$limit_from = Sanitizer::get_var('limit_from', 'date');
		$limit_quantity = Sanitizer::get_var('limit_quantity', 'int');

		if (!$limit_from)
		{
			return array(
				'ok' => false,
				'msg' => lang('select')
			);
		}

		try
		{
			$resource = $this->bo->read_single($resource_id);
			$receipt = $this->bo->add_participant_limit($resource, $resource_id, $limit_from, $limit_quantity);
			$msg = $receipt == 1 ? lang('added') : lang('updated');
		}
		catch (booking_unauthorized_exception $e)
		{
			return false;
			$msg = lang('Could not add object due to insufficient permissions');
		}

		return array(
			'ok' => $receipt,
			'msg' => $msg
		);
	}


	public function get_buildings()
	{
		$resource = $this->bo->read_single(Sanitizer::get_var('resource_id', 'int'));

		$_filter_building['id'] = array_merge(array(-1), $resource['buildings']);

		$bui_result = $this->sobuilding->read(array(
			'results' => -1,
			"sort" => "name",
			"dir" => "asc",
			"filters" => $_filter_building
		));

		return $this->jquery_results($bui_result);
	}

	public function add_building()
	{
		$resource_id = Sanitizer::get_var('resource_id', 'int');
		if (!$building_id = Sanitizer::get_var('building_id', 'int'))
		{
			return array(
				'ok' => false,
				'msg' => lang('select')
			);
		}

		try
		{
			$resource = $this->bo->read_single($resource_id);
			$receipt = $this->bo->add_building($resource, $resource_id, $building_id);
			$msg = $receipt ? '' : lang('duplicate');
		}
		catch (booking_unauthorized_exception $e)
		{
			return false;
			$msg = lang('Could not add object due to insufficient permissions');
		}

		return array(
			'ok' => $receipt,
			'msg' => $msg
		);
	}

	public function remove_building()
	{
		$resource_id = Sanitizer::get_var('resource_id', 'int');
		if (!$building_id = Sanitizer::get_var('building_id', 'int'))
		{
			return array(
				'ok' => false,
				'msg' => lang('select')
			);
		}
		try
		{
			$resource = $this->bo->read_single($resource_id);
			$receipt = $this->bo->remove_building($resource, $resource_id, $building_id);
			$msg = '';
		}
		catch (booking_unauthorized_exception $e)
		{
			return false;
			$msg = lang('Could not update object due to insufficient permissions');
		}

		return array(
			'ok' => $receipt,
			'msg' => $msg
		);
	}

	public function show()
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
		$array_resource = array(&$resource);
		$this->bo->add_activity_facility_data($array_resource);

		$_filter_building['id'] = array_merge(array(-1), $resource['buildings']);

		$bui_result = $this->sobuilding->read(array(
			'results' => -1,
			"sort" => "name",
			"dir" => "asc",
			"filters" => $_filter_building
		));

		// Create text strings for the activity and facility lists
		$activitynames = array();
		foreach ($resource['activities_list'] as $activity)
		{
			$activitynames[] = $activity['name'];
		}
		$resource['activities_names'] = implode(', ', $activitynames);
		$facilitynames = array();
		foreach ($resource['facilities_list'] as $facility)
		{
			$facilitynames[] = $facility['name'];
		}
		$resource['facilities_names'] = implode(', ', $facilitynames);
		$userlang = $this->userSettings['preferences']['common']['lang'];
		$resource['description']		 = isset($resource['description_json'][$userlang]) ? $resource['description_json'][$userlang] : '';

		$resource['edit_link'] = self::link(array(
			'menuaction' => 'booking.uiresource.edit',
			'id' => $resource['id']
		));
		$resource['building_link'] = self::link(array(
			'menuaction' => 'booking.uibuilding.show',
			'id' => $resource['building_id']
		));
		$resource['buildings_link'] = self::link(array('menuaction' => 'booking.uibuilding.index'));
		$resource['schedule_link'] = self::link(array(
			'menuaction' => 'booking.uiresource.schedule',
			'id' => $resource['id']
		));
		$resource['cancel_link'] = self::link(array('menuaction' => 'booking.uiresource.index'));
		$resource['add_document_link'] = booking_uidocument::generate_inline_link('resource', $resource['id'], 'add');
		$resource['add_permission_link'] = booking_uipermission::generate_inline_link('resource', $resource['id'], 'add');
		$resource['edit_activities_link'] = self::link(array(
			'menuaction' => 'booking.uiresource.edit_activities',
			'id' => $resource['id']
		));
		$resource['edit_facilities_link'] = self::link(array(
			'menuaction' => 'booking.uiresource.edit_facilities',
			'id' => $resource['id']
		));

		$tabs = array();
		$tabs['generic'] = array(
			'label' => lang('Resource'),
			'link' => '#resource'
		);
		$active_tab = 'generic';
		$resource['tabs'] = phpgwapi_jquery::tabview_generate($tabs, $active_tab);

		$data = array(
			'datatable_def' => self::get_datatable_def($id),
			'resource' => $resource,
			'seasons'	=> $this->bo->so->get_seasons($id)

		);
		self::add_javascript('booking', 'base', 'resource_new.js'); // to render custom fields
		self::render_template_xsl(array('resource', 'datatable_inline'), $data);
	}

	public function schedule()
	{
		$resource = $this->bo->get_schedule(Sanitizer::get_var('id', 'int'), 'booking.uibuilding', 'booking.uiresource');

		$this->flags['app_header'] = lang('booking') . "::{$resource['name']}";

		$building_names = array();
		if (is_array($resource['buildings']))
		{
			foreach ($resource['buildings'] as $building_id)
			{
				$building = $this->sobuilding->read_single($building_id);
				$building_names[] = $building['name'];
			}
			$this->flags['app_header'] .= ' (' . implode('', $building_names) . ')';
		}
		Settings::getInstance()->update('flags', ['app_header' => $this->flags['app_header']]);

		$resource['application_link'] = self::link(array(
			'menuaction' => 'booking.uiapplication.add',
			'building_id' => $resource['building_id'],
			'building_name' => $resource['building_name'],
			'activity_id' => $resource['activity_id'],
			'resource' => $resource['id']
		));
		$resource['datasource_url'] = self::link(array(
			'menuaction' => 'booking.uibooking.resource_schedule',
			'resource_id' => $resource['id'],
			'phpgw_return_as' => 'json',
		));

		$resource['picker_img'] = $this->phpgwapi_common->image('phpgwapi', 'cal');

		$tabs = array();
		$tabs['generic'] = array('label' => lang('Resource Schedule'), 'link' => '#resource_schedule');
		$active_tab = 'generic';

		$resource['tabs'] = phpgwapi_jquery::tabview_generate($tabs, $active_tab);
		$resource['cancel_link'] = self::link(array(
			'menuaction' => 'booking.uiresource.show',
			'id' => $resource['id']
		));

		self::add_javascript('booking', 'base', 'schedule.js');

		phpgwapi_jquery::load_widget("datepicker");

		self::render_template_xsl('resource_schedule', array('resource' => $resource));
	}
}
