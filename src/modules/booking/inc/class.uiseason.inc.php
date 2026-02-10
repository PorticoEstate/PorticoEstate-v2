<?php

use App\modules\phpgwapi\services\Settings;
use App\Database\Db;
use App\modules\phpgwapi\controllers\Accounts\Accounts;
use App\modules\phpgwapi\services\Cache;

phpgw::import_class('booking.uicommon');
phpgw::import_class('booking.boresource');
phpgw::import_class('booking.uipermission_season');

phpgw::import_class('booking.uidocument_building');
phpgw::import_class('booking.uipermission_building');

class booking_uiseason extends booking_uicommon
{

	public $public_functions = array(
		'index' => true,
		'query' => true,
		'add' => true,
		'show' => true,
		'edit' => true,
		'copy_season' => true,
		'boundaries' => true,
		'delete_boundary' => true,
		'edit_boundary' => true,
		'delete_wtemplate_alloc' => true,
		'wtemplate' => true,
		'wtemplate_json' => true,
		'wtemplate_alloc' => true,
		'generate' => true,
		'toggle_show_inactive' => true
	);

	var $fields, $display_name, $resource_bo, $boundary_fields, $wtemplate_alloc_fields;

	public function __construct()
	{
		parent::__construct();

		//			Analizar esta linea de permisos self::process_booking_unauthorized_exceptions();

		$this->bo = CreateObject('booking.boseason');
		$this->resource_bo = CreateObject('booking.boresource');
		self::set_active_menu('booking::buildings::seasons::seasons');
		$this->fields = array(
			'name', 'building_id', 'building_name', 'status', 'from_',
			'to_', 'resources', 'active', 'officer_id', 'officer_name'
		);
		$this->boundary_fields = array('wday', 'from_', 'to_');
		$this->wtemplate_alloc_fields = array(
			'id'				 => 'int',
			'organization_id'	 => 'int',
			'wday'				 => 'int',
			'cost'				 => 'float',
			'from_'				 => 'string',
			'to_'				 => 'string',
			'resources'			 => 'int',
			'articles'			 => 'string'
		);
		$this->display_name = lang('seasons');
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
						//							array(
						//								'type' => 'link',
						//								'value' => $_SESSION['showall'] ? lang('Show only active') : lang('Show all'),
						//								'href' => self::link(array('menuaction' => $this->url_prefix . '.toggle_show_inactive'))
						//							),
					)
				),
			),
			'datatable' => array(
				'source' => self::link(array('menuaction' => 'booking.uiseason.index', 'phpgw_return_as' => 'json')),
				'sorted_by' => array('key' => 5, 'dir' => 'desc'), //to_
				'field' => array(
					array(
						'key' => 'id',
						'label' => lang('id'),
						'formatter' => 'JqueryPortico.formatLink'
					),
					array(
						'key' => 'name',
						'label' => lang('Season Name'),
						'formatter' => 'JqueryPortico.formatLink'
					),
					array(
						'key' => 'building_name',
						'label' => lang('Building')
					),
					array(
						'key' => 'resource_list',
						'label' => lang('Resources'),
						'sortable' => false
					),
					array(
						'key' => 'officer_name',
						'label' => lang('Officer')
					),
					array(
						'key' => 'from_',
						'label' => lang('From')
					),
					array(
						'key' => 'to_',
						'label' => lang('To')
					),
					array(
						'key' => 'link',
						'hidden' => true
					),
					array(
						'key' => 'status',
						'label' => lang('Status')
					),
					array(
						'key' => 'week_template',
						'label' => lang('Week template')
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
			$data['datatable']['new_item'] = self::link(array('menuaction' => 'booking.uiseason.add'));
		}
		self::render_template_xsl('datatable2', $data);
	}

	public function query()
	{
		$params = $this->bo->build_default_read_params();
		$seasons = $this->bo->read($params);
		array_walk($seasons["results"], array($this, "_add_links"), "booking.uiseason.show");

		$lang_week_template = lang('week template');

		$accounts_obj = new Accounts();
		foreach ($seasons['results'] as &$season)
		{
			$season['status'] = lang($season['status']);
			$season['from_'] = pretty_timestamp($season['from_']);
			$season['to_'] = pretty_timestamp($season['to_']);

			if (isset($season['resources']))
			{
				$filters['results'] = -1;
				$filters['filters']['id'] = $season['resources'];
				$resources = $this->resource_bo->so->read($filters);
				$temparray = array();
				foreach ($resources['results'] as $resource)
				{
					$temparray[] = $resource['name'];
				}
				$season['resource_list'] = implode(', ', $temparray);
			}

			$season['week_template'] = "<a href = '" . self::link(array('menuaction' => 'booking.uiseason.wtemplate', 'id' => $season['id'])) . "'>{$lang_week_template}</a>";

			$account_id = $accounts_obj->name2id($season['officer_name']);
			if ($account_id)
			{
				$season['officer_name'] = $accounts_obj->get($account_id)->__toString();
			}
		}
		return $this->jquery_results($seasons);
	}

	public function add()
	{
		$errors = array();
		$season = array();
		if ($_SERVER['REQUEST_METHOD'] == 'POST')
		{
			$season = extract_values($_POST, $this->fields);
			$season['active'] = '1';
			array_set_default($_POST, 'resources', array());

			$season['from_'] = ($season['from_']) ? date("Y-m-d", phpgwapi_datetime::date_to_timestamp($season['from_'])) : $season['from_'];
			$season['to_'] = ($season['to_']) ? date("Y-m-d", phpgwapi_datetime::date_to_timestamp($season['to_'])) : $season['to_'];

			$errors = $this->bo->validate($season);

			if (!$errors)
			{
				try
				{
					$receipt = $this->bo->add($season);

					// Invalidate Next.js caches (server-side + client-side via WebSocket)
					if (class_exists('\App\modules\bookingfrontend\services\CacheService'))
					{
						$cache = new \App\modules\bookingfrontend\services\CacheService();
						$buildingId = isset($season['building_id']) ? (int)$season['building_id'] : null;
						$cache->invalidateSeason($buildingId);
					}

					self::redirect(array('menuaction' => 'booking.uiseason.show', 'id' => $receipt['id']));
				}
				catch (booking_unauthorized_exception $e)
				{
					$errors['global'] = lang('Could not add object due to insufficient permissions');
				}
			}
		}
		else
		{
			// Initialize the array with empty data
			$season = array("resources" => array());
			$season['officer_id'] = $this->userSettings['account_id'];
			$season['officer_name'] = $this->userSettings['account_lid'];
		}

		$season['from_'] = pretty_timestamp($season['from_']);
		$season['to_'] = pretty_timestamp($season['to_']);

		$this->flash_form_errors($errors);
		self::add_javascript('booking', 'base', 'season.js');

		array_set_default($season, 'resources', array());
		$season['resources_json'] = json_encode(array_map('intval', $season['resources']));
		$season['cancel_link'] = self::link(array('menuaction' => 'booking.uiseason.index'));

		$jqcal2 = createObject('phpgwapi.jqcal2');
		$jqcal2->add_listener('from_', 'date');
		$jqcal2->add_listener('to_', 'date');

		$tabs = array();
		$tabs['generic'] = array('label' => lang('Season New'), 'link' => '#season_new');
		$active_tab = 'generic';

		$season['tabs'] = phpgwapi_jquery::tabview_generate($tabs, $active_tab);
		$season['validator'] = phpgwapi_jquery::formvalidator_generate(array(
			'location',
			'date', 'security', 'file'
		));

		self::render_template_xsl('season_new', array('season' => $season));
	}

	public function copy_season()
	{
		$id = Sanitizer::get_var('id', 'int');
		if (!$id)
		{
			phpgw::no_access('booking', lang('missing id'));
		}
		$season = $this->bo->read_single($id);
		if (!$season)
		{
			phpgw::no_access('booking', lang('missing entry. Id %1 is invalid', $id));
		}

		unset($season['id']);
		$season['status'] = 'PLANNING';
		$season['name'] = lang('copied from %1', $id) . ': ' . $season['name'];
		$errors = $this->bo->validate($season);

		if (!$errors)
		{
			try
			{
				Db::getInstance()->transaction_begin();
				$receipt = $this->bo->add($season);
				$this->bo->copy_permissions($id, $receipt['id']);
				$this->bo->copy_boundaries($id, $receipt['id']);
				$this->bo->copy_wtemplate($id, $receipt['id']);
				Db::getInstance()->transaction_commit();
				Cache::message_set(lang('season %1 copied to %2', $id, $receipt['id']));
				self::redirect(array('menuaction' => 'booking.uiseason.edit', 'id' => $receipt['id']));
			}
			catch (booking_unauthorized_exception $e)
			{
				$errors['global'] = lang('Could not add object due to insufficient permissions');
			}
		}
	}

	public function edit()
	{
		$id = Sanitizer::get_var('id', 'int');
		if (!$id)
		{
			phpgw::no_access('booking', lang('missing id'));
		}
		$season = $this->bo->read_single($id);
		if (!$season)
		{
			phpgw::no_access('booking', lang('missing entry. Id %1 is invalid', $id));
		}
		$season['buildings_link'] = self::link(array('menuaction' => 'booking.uibuilding.index'));
		$season['building_link'] = self::link(array(
			'menuaction' => 'booking.uibuilding.show',
			'id' => $season['building_id']
		));

		$errors = array();

		if ($_SERVER['REQUEST_METHOD'] == 'POST')
		{
			array_set_default($_POST, 'resources', array());
			$season = array_merge($season, extract_values($_POST, $this->fields));

			switch ($season['status'])
			{
					//	case 'PLANNING':
				case 'ARCHIVED':
					$season['active'] = 0;
					break;
				default:
					$season['active'] = 1;
					break;
			}

			$season['from_'] = ($season['from_']) ? date("Y-m-d", phpgwapi_datetime::date_to_timestamp($season['from_'])) : $season['from_'];
			$season['to_'] = ($season['to_']) ? date("Y-m-d", phpgwapi_datetime::date_to_timestamp($season['to_'])) : $season['to_'];
			$errors = $this->bo->validate($season);
			if (!$errors)
			{
				try
				{
					$receipt = $this->bo->update($season);

					// Invalidate Next.js caches (server-side + client-side via WebSocket)
					if (class_exists('\App\modules\bookingfrontend\services\CacheService'))
					{
						$cache = new \App\modules\bookingfrontend\services\CacheService();
						$buildingId = isset($season['building_id']) ? (int)$season['building_id'] : null;
						$cache->invalidateSeason($buildingId);
					}

					self::redirect(array('menuaction' => 'booking.uiseason.show', 'id' => $season['id']));
				}
				catch (booking_unauthorized_exception $e)
				{
					$errors['global'] = lang('Could not update object due to insufficient permissions');
				}
			}
		}
		$this->flash_form_errors($errors);
		self::add_javascript('booking', 'base', 'season.js');

		$season['from_'] = pretty_timestamp($season['from_']);
		$season['to_'] = pretty_timestamp($season['to_']);
		$season['resources_json'] = json_encode(array_map('intval', $season['resources']));
		$season['cancel_link'] = self::link(array(
			'menuaction' => 'booking.uiseason.show',
			'id' => $season['id']
		));

		$jqcal2 = createObject('phpgwapi.jqcal2');
		$jqcal2->add_listener('from_', 'date', phpgwapi_datetime::date_to_timestamp($season['from_']));
		$jqcal2->add_listener('to_', 'date', phpgwapi_datetime::date_to_timestamp($season['to_']));

		$tabs = array();
		$tabs['generic'] = array('label' => lang('Season Edit'), 'link' => '#season_new');
		$active_tab = 'generic';

		$season['tabs'] = phpgwapi_jquery::tabview_generate($tabs, $active_tab);
		$season['validator'] = phpgwapi_jquery::formvalidator_generate(array(
			'location',
			'date', 'security', 'file'
		));

		self::render_template_xsl('season_new', array('season' => $season));
	}

	public function show()
	{
		$id = Sanitizer::get_var('id', 'int');
		if (!$id)
		{
			phpgw::no_access('booking', lang('missing id'));
		}
		$season = $this->bo->read_single($id);
		if (!$season)
		{
			phpgw::no_access('booking', lang('missing entry. Id %1 is invalid', $id));
		}
		$season['cancel_link'] = self::link(array('menuaction' => 'booking.uiseason.index'));
		$season['buildings_link'] = self::link(array('menuaction' => 'booking.uibuilding.index'));
		$season['building_link'] = self::link(array(
			'menuaction' => 'booking.uibuilding.show',
			'id' => $season['building_id']
		));
		$season['edit_link'] = self::link(array(
			'menuaction' => 'booking.uiseason.edit',
			'id' => $season['id']
		));
		$season['boundaries_link'] = self::link(array(
			'menuaction' => 'booking.uiseason.boundaries',
			'id' => $season['id']
		));
		$season['wtemplate_link'] = self::link(array(
			'menuaction' => 'booking.uiseason.wtemplate',
			'id' => $season['id']
		));
		$season['add_permission_link'] = booking_uipermission::generate_inline_link('season', $season['id'], 'add');
		$resource_ids = '';

		if (count($season['resources']) == 0)
		{
			$resource_ids = 'filter_id=-1'; //No resources to display, so set filter that returns nothing
		}
		else
		{
			foreach ($season['resources'] as $res)
			{
				$resource_ids = $resource_ids . '&filter_id[]=' . $res;
			}
		}
		$season['resource_ids'] = $resource_ids;
		$season['status'] = $season['status'] ? lang($season['status']) : $season['status'];

		$tabs = array();
		$tabs['generic'] = array('label' => lang('Season Show'), 'link' => '#season_show');
		$active_tab = 'generic';

		$season['tabs'] = phpgwapi_jquery::tabview_generate($tabs, $active_tab);

		self::render_template_xsl('season', array('season' => $season));
	}

	public function boundaries()
	{
		$season_id = Sanitizer::get_var('id', 'int');
		$edit_id = Sanitizer::get_var('edit_id', 'int');
		$season = $this->bo->read_single($season_id);

		$boundaries = $this->bo->get_boundaries($season_id);
		$boundaries = $boundaries['results'];
		$season['buildings_link'] = self::link(array('menuaction' => 'booking.uibuilding.index'));
		$season['building_link'] = self::link(array(
			'menuaction' => 'booking.uibuilding.show',
			'id' => $season['building_id']
		));
		$season['season_link'] = self::link(array(
			'menuaction' => 'booking.uiseason.show',
			'id' => $season['id']
		));
		$season['cancel_link'] = self::link(array(
			'menuaction' => 'booking.uiseason.show',
			'id' => $season['id']
		));
		$weekdays = array(
			'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday',
			'Saturday', 'Sunday'
		);
		
		$boundary = array('wday' => array());
		$is_editing = false;
		
		// If editing, load the existing boundary
		if ($edit_id) {
			$boundary = $this->bo->read_boundary($edit_id);
			// Ensure the boundary belongs to this season
			if ($boundary && $boundary['season_id'] != $season_id) {
				phpgw::no_access('booking', lang('Boundary does not belong to this season'));
			}
			$is_editing = true;
		}
		
		foreach ($boundaries as &$b)
		{
			$b['wday_name'] = lang($weekdays[$b['wday'] - 1]);
			$b['delete_link'] = self::link(array(
				'menuaction' => 'booking.uiseason.delete_boundary',
				'id' => $b['id']
			));
			$b['edit_link'] = self::link(array(
				'menuaction' => 'booking.uiseason.boundaries',
				'id' => $season_id,
				'edit_id' => $b['id']
			));
		}
		
		$errors = array();
		if ($_SERVER['REQUEST_METHOD'] == 'POST')
		{
			$form_data = extract_values($_POST, $this->boundary_fields);
			$selected_wdays = Sanitizer::get_var('wday', 'int', 'POST');
			if (!is_array($selected_wdays)) {
				$selected_wdays = array($selected_wdays);
			}
			
			// Delete existing boundaries for selected days, then add new ones
			$all_success = true;
			foreach ($selected_wdays as $wday) {
				// First, delete any existing boundary for this day
				$existing_boundaries = $this->bo->get_boundaries($season_id);
				foreach ($existing_boundaries['results'] as $existing) {
					if ($existing['wday'] == $wday) {
						$this->bo->delete_boundary($existing);
					}
				}
				
				// Then add the new boundary for this day
				$new_boundary = array(
					'season_id' => $season_id,
					'wday' => $wday,
					'from_' => $form_data['from_'],
					'to_' => $form_data['to_']
				);
				$day_errors = $this->bo->validate_boundary($new_boundary);
				if ($day_errors) {
					$errors = array_merge($errors, $day_errors);
					$all_success = false;
				} else {
					$this->bo->add_boundary($new_boundary);
				}
			}
			
			if ($all_success && !$errors) {
				// Invalidate Next.js caches (server-side + client-side via WebSocket)
				if (class_exists('\App\modules\bookingfrontend\services\CacheService'))
				{
					$cache = new \App\modules\bookingfrontend\services\CacheService();
					$buildingId = isset($season['building_id']) ? (int)$season['building_id'] : null;
					$cache->invalidateSeason($buildingId);
				}

				self::redirect(array('menuaction' => 'booking.uiseason.boundaries', 'id' => $season_id));
			}
		}
		
		$this->flash_form_errors($errors);
		$season['cancel_link'] = self::link(array(
			'menuaction' => 'booking.uiseason.show',
			'id' => $season_id
		));

		$tabs = array();
		$tabs['generic'] = array('label' => lang('Season Boundaries'), 'link' => '#season_boundaries');
		$active_tab = 'generic';

		$season['tabs'] = phpgwapi_jquery::tabview_generate($tabs, $active_tab);
		//exclude the seconds
		if (isset($boundary['from_']))
		{
			$from_arr = explode(':', $boundary['from_']);
			$to_arr = explode(':', $boundary['to_']);
			$boundary['from_'] = "{$from_arr[0]}:{$from_arr[1]}";
			$boundary['to_'] = "{$to_arr[0]}:{$to_arr[1]}";
		}

		$jqcal2 = createObject('phpgwapi.jqcal2');
		$jqcal2->add_listener('field_from', 'time', $boundary['from_']);
		$jqcal2->add_listener('field_to', 'time', $boundary['to_']);

		$season['is_editing'] = $is_editing;
		
		self::render_template_xsl('season_boundaries', array(
			'boundary' => $boundary, 'boundaries' => $boundaries,
			'season' => $season, 'weekdays' => $weekdays
		));
	}

	public function delete_boundary()
	{
		$boundary_id = Sanitizer::get_var('id', 'int');
		$boundary = $this->bo->read_boundary($boundary_id);
		$season_id = $boundary['season_id'];
		$this->bo->delete_boundary($boundary);
		self::redirect(array('menuaction' => 'booking.uiseason.boundaries', 'id' => $season_id));
	}

	public function delete_wtemplate_alloc()
	{
		$allocation_id = Sanitizer::get_var('id', 'int');
		$alloc = $this->bo->so_wtemplate_alloc->read_single($allocation_id);
		$this->bo->delete_wtemplate_alloc($alloc);
		return 1;
	}

	public function wtemplate()
	{
		$season_id = Sanitizer::get_var('id', 'int');
		$season = $this->bo->read_single($season_id);
		$season['season_link'] = self::link(array(
			'menuaction' => 'booking.uiseason.show',
			'id' => $season_id
		));
		$season['buildings_link'] = self::link(array('menuaction' => 'booking.uibuilding.index'));
		$season['building_link'] = self::link(array(
			'menuaction' => 'booking.uibuilding.show',
			'id' => $season['building_id']
		));
		$season['resources_json'] = json_encode(array_map('intval', $season['resources']));
		$season['get_url'] = self::link(array(
			'menuaction' => 'booking.uiseason.wtemplate_alloc',
			'season_id' => $season['id'], 'phpgw_return_as' => 'json'
		));
		$season['post_url'] = self::link(array(
			'menuaction' => 'booking.uiseason.wtemplate_alloc',
			'season_id' => $season['id'], 'phpgw_return_as' => 'json'
		));
		$season['generate_url'] = self::link(array(
			'menuaction' => 'booking.uiseason.generate',
			'id' => $season['id']
		));
		$season['delete_wtemplate_alloc_url'] = self::link(array(
			'menuaction' => 'booking.uiseason.delete_wtemplate_alloc',
			'phpgw_return_as' => 'json'
		));
		$season['cancel_link'] = self::link(array(
			'menuaction' => 'booking.uiseason.show',
			'id' => $season['id']
		));
		$tabs = array();
		$tabs['generic'] = array('label' => lang('Week template'), 'link' => '#season_wtemplate');
		$active_tab = 'generic';

		$season['tabs'] = phpgwapi_jquery::tabview_generate($tabs, $active_tab);

		self::add_javascript('booking', 'base', 'schedule.js');
		//self::add_javascript('booking', 'base', 'season.wtemplate.js');
		phpgwapi_jquery::load_widget("datepicker");
		self::render_template_xsl('season_wtemplate', array('season' => $season));
	}

	public function wtemplate_json()
	{
		$season_id = Sanitizer::get_var('id', 'int');
		$allocations = $this->bo->wtemplate_schedule($season_id);
		$data = array(
			'ResultSet' => array(
				"totalResultsAvailable" => $allocations['total_records'],
				"Result" => $allocations['results']
			)
		);
		return $data;
	}
	/* Return a single wtemplate allocations as JSON */

	public function wtemplate_alloc()
	{
		//$season_id = Sanitizer::get_var('season_id', 'int');
		//$phpgw_return_as = Sanitizer::get_var('phpgw_return_as');

		if ($_SERVER['REQUEST_METHOD'] == 'POST')
		{
			$alloc = extract_values($_POST, $this->wtemplate_alloc_fields);

			if (!empty($alloc['articles']))
			{
				$alloc['articles'] = json_encode($alloc['articles']);
			}
			//$alloc['season_id'] = $season_id;
			$alloc['season_id'] = Sanitizer::get_var('season_id', 'int');

			$errors = $this->bo->validate_wtemplate_alloc($alloc);
			if (!$errors && $alloc['id'])
			{
				$receipt = $this->bo->update_wtemplate_alloc($alloc);
			}
			else if (!$errors && !$alloc['id'])
			{
				$receipt = $this->bo->add_wtemplate_alloc($alloc);
			}

			$message = array();
			if (count($errors))
			{
				foreach ($errors as $error)
				{
					$message['error'][] = array('msg' => $error[0]);
				}
			}
			return $message;
		}

		$id = Sanitizer::get_var('id', 'int');

		$_from = Sanitizer::get_var('_from', 'string');
		$_to = Sanitizer::get_var('_to', 'string');
		$wday = Sanitizer::get_var('wday', 'string'); //int?

		if (!empty($id))
		{
			$alloc = $this->bo->wtemplate_alloc_read_single($id);
			$season = $alloc;
			$_from = $alloc['from_'];
			$_to = $alloc['to_'];
			$season['resource_selected'] = json_encode($alloc['resources']);
		}
		else
		{
			$season['resource_selected'] = json_encode(array());
			$season['wday'] = $wday;
		}

		$array_from = explode(':', ($_from ? $_from : '00:00'));
		$array_to = explode(':', ($_to ? $_to : '00:00'));

		$season['from_h'] = $array_from[0];
		$season['from_m'] = $array_from[1];
		$season['to_h'] = $array_to[0];
		$season['to_m'] = $array_to[1];

		$resource_ids = Sanitizer::get_var('filter_id', 'int');
		$season['resources_json'] = json_encode(array_map('intval', (array)$alloc['resources']));

		$filters = null;
		if (count($resource_ids) == 0)
		{
			$filters = 'filter_id=-1'; //No resources to display, so set filter that returns nothing
		}
		else
		{
			foreach ($resource_ids as $res)
			{
				$filters = $filters . '&filter_id[]=' . $res;
			}
		}
		$season['resource_ids'] = $filters;

		Settings::getInstance()->update('flags', ['noframework' => true]);

		$tabs['allocations'] = array('label' => lang('Allocations'), 'link' => '#allocations');
		$active_tab = 'allocations';
		$season['tabs'] = phpgwapi_jquery::tabview_generate($tabs, $active_tab);

		phpgwapi_jquery::load_widget('autocomplete');

		$jscode = <<<JS

				$(document).ready(function() {

					var oArgs = {menuaction:'booking.uiorganization.index'};
					var sUrl = phpGWLink('index.php', oArgs, true);

					JqueryPortico.autocompleteHelper(sUrl, 'organization_name', 'organization_id', 'org_container');
				});
JS;
		phpgwapi_js::getInstance()->add_code('', $jscode);

		$config = CreateObject('phpgwapi.config', 'booking')->read();

		if (!empty($config['activate_application_articles']))
		{
			self::add_javascript('phpgwapi', 'dateformatter', 'dateformatter.js');
			self::add_javascript('booking', 'base', 'purchase_order_edit.js');
			phpgwapi_js::getInstance()->validate_file('alertify', 'alertify.min', 'phpgwapi');
			phpgwapi_css::getInstance()->add_external_file('phpgwapi/js/alertify/css/alertify.min.css');
			phpgwapi_css::getInstance()->add_external_file('phpgwapi/js/alertify/css/themes/bootstrap.min.css');
		}
		self::add_javascript('booking', 'base', 'season.wtemplate.js');

		self::render_template_xsl('season_wtemplate_allocation', array(
			'season' => $season,
			'tax_code_list'	 => json_encode(execMethod('booking.bogeneric.read', array('location_info' => array('type' => 'tax', 'order' => 'id')))),
			'config'		 => $config
		));
	}

	public function generate()
	{
		$season_id = Sanitizer::get_var('id', 'int');
		$season = $this->bo->read_single($season_id);

		$this->bo->authorize_write($season);

		$season['buildings_link'] = self::link(array('menuaction' => 'booking.uibuilding.index'));
		$season['building_link'] = self::link(array(
			'menuaction' => 'booking.uibuilding.show',
			'id' => $season['building_id']
		));
		$season['wtemplate_link'] = self::link(array(
			'menuaction' => 'booking.uiseason.wtemplate',
			'id' => $season['id']
		));
		$result = array();
		$step = 1;
		$errors = array();

		$from = pretty_timestamp($season['from_']);
		$to = pretty_timestamp($season['to_']);
		$interval = 1;

		$jqcal2 = createObject('phpgwapi.jqcal2');
		$jqcal2->add_listener('from_', 'date', strtotime($season['from_']), array('readonly' => true));
		$jqcal2->add_listener('to_', 'date', strtotime($season['to_']), array('readonly' => true));

		if ($_SERVER['REQUEST_METHOD'] == 'POST')
		{
			$step = Sanitizer::get_var('create') ? 3 : 2;
			$from = Sanitizer::get_var('from_', 'string');
			$to = Sanitizer::get_var('to_', 'string');
			$from_ = date("Y-m-d", phpgwapi_datetime::date_to_timestamp($from));
			$to_ = date("Y-m-d", phpgwapi_datetime::date_to_timestamp($to));

			$interval = Sanitizer::get_var('field_interval');
			if ($from_ < $season['from_'])
			{
				$errors['from_'] = lang('Start date must be after %1', pretty_timestamp($season['from_']));
			}
			if ($to_ > $season['to_'])
			{
				$errors['to_'] = lang('To date must be before %1', pretty_timestamp($season['to_']));
			}
			if ($errors)
			{
				$step = 1;
			}
			else
			{
				try
				{
					$result = $this->bo->generate_allocation($season_id, new DateTime($from_), new DateTime($to_), $interval, $step == 3);
				}
				catch (Exception $ex)
				{
					$errors['validation'] = $ex->getMessage();
					$step = 1;
				}
			}
			$this->bo->so->update_id_string();
		}

		$tabs = array();
		$tabs['generate_allocations'] = array(
			'label' => lang('Generate Allocations'),
			'link' => '#generate_allocations'
		);
		$active_tab = 'generate_allocations';
		$season['tabs'] = phpgwapi_jquery::tabview_generate($tabs, $active_tab);

		$this->flash_form_errors($errors);

		self::render_template_xsl('season_generate', array(
			'season'	 => $season,
			'result'	 => $result,
			'step'		 => $step,
			'interval'	 => $interval,
			'from_'		 => $from,
			'to_'		 => $to
		));
	}
}
