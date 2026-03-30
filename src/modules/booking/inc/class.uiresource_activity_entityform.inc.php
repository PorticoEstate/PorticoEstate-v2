<?php

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\controllers\Locations;
use App\modules\phpgwapi\services\Cache;

phpgw::import_class('booking.uicommon');

class booking_uiresource_activity_entityform extends booking_uicommon
{

	public $public_functions = array(
		'index' => true,
		'query' => true,
		'add' => true,
		'show' => true,
		'edit' => true,
		'get_categories' => true,
		'toggle_show_inactive' => true,

	);
	var $bo, $display_name, $activity_bo, $resource_bo, $building_bo, $boadmin_entity;


	public function __construct()
	{
		parent::__construct();

		self::process_booking_unauthorized_exceptions();

		$this->bo = CreateObject('booking.boresource_activity_entityform');
		$this->activity_bo = CreateObject('booking.boactivity');
		$this->resource_bo = CreateObject('booking.boresource');
		$this->building_bo		 = CreateObject('booking.bobuilding');
		$this->boadmin_entity			 = CreateObject('property.boadmin_entity');

		$this->display_name = lang('resource_activity_entityform');

		self::set_active_menu('booking::settings::resource_activity_entityform');
		Settings::getInstance()->update('flags', ['app_header' => lang('booking') . "::" . $this->display_name]);
	}



	public function query()
	{
		$entityforms = $this->bo->read();

		$lang_yes = lang('yes');
		$lang_no = lang('no');

		array_walk($entityforms["results"], array($this, "_add_links"), "booking.uiresource_activity_entityform.edit");
		foreach ($entityforms["results"] as &$entityform)
		{
			$entityform['active'] = $entityform['active'] == 1 ? $lang_yes : $lang_no;
			$resource_names = array();

			if ($entityform['resources'])
			{
				$resources = $this->resource_bo->so->read(array('results' => 'all', 'filters' => array(
					'id' => $entityform['resources']
				)));

				if ($resources['results'])
				{
					foreach ($resources['results'] as $resource)
					{
						$resource_names[] = $resource['name'];
					}
				}
			}

			$entityform['resource_names'] = implode(', ', $resource_names);

			$activity_names = array();

			if ($entityform['activities'])
			{
				$activities = $this->activity_bo->so->read(array('results' => 'all', 'filters' => array(
					'id' => $entityform['activities']
				)));

				if ($activities['results'])
				{
					foreach ($activities['results'] as $activity)
					{
						$activity_names[] = $activity['name'];
					}
				}
			}

			$entityform['activity_names'] = implode(', ', $activity_names);
		}
		$results = $this->jquery_results($entityforms);
		return $results;
	}

	public function index()
	{
		if (Sanitizer::get_var('phpgw_return_as') == 'json')
		{
			return $this->query();
		}

		$data = array(
			'datatable_name'	=> $this->display_name,
			'form' => array(
				'toolbar' => array(
					'item' => array()
				),
			),
			'datatable' => array(
				'source' => self::link(array(
					'menuaction' => 'booking.uiresource_activity_entityform.index',
					'phpgw_return_as' => 'json'
				)),
				'field' => array(
					array(
						'key' => 'id',
						'label' => lang('id')
					),
					array(
						'key' => 'name',
						'label' => lang('Name'),
						'formatter' => 'JqueryPortico.formatLink'
					),
					array(
						'key' => 'resource_names',
						'label' => lang('Resource'),
						'sortable' => false,
					),
					array(
						'key' => 'activity_names',
						'label' => lang('Activity'),
						'sortable' => false,
					),
					array(
						'key' => 'active',
						'label' => lang('Active'),
					),
					array(
						'key' => 'link',
						'hidden' => true
					)
				)
			)
		);
		$data['datatable']['new_item'] = self::link(array('menuaction' => 'booking.uiresource_activity_entityform.add'));
		$parameters = array(
			'parameter' => array(
				array(
					'name' => 'id',
					'source' => 'id'
				),
			)
		);
		$data['datatable']['actions'][] = array(
			'my_name' => 'delete',
			'statustext' => lang('delete'),
			'text' => lang('delete'),
			'confirm_msg' => lang('do you really want to delete this entry?'),
			'action' => phpgw::link('/index.php', array(
				'menuaction' => 'booking.uiresource_activity_entityform.delete'
			)),
			'parameters' => json_encode($parameters)
		);

		$data['datatable']['actions'][] = array(
			'my_name'	 => 'toggle_inactive',
			'className'	 => 'save',
			'type'		 => 'custom',
			'statustext' => $_SESSION['showall'] ? lang('Show only active') : lang('Show all'),
			'text'		 => $_SESSION['showall'] ? lang('Show only active') : lang('Show all'),
			'custom_code'	 => 'window.open("' . self::link(array('menuaction' => $this->url_prefix . '.toggle_show_inactive')) . '", "_self");',
		);
		self::render_template_xsl('datatable2', $data);
	}


	public function edit()
	{
		$id = Sanitizer::get_var('id', 'int');
		if (!$id)
		{
			phpgw::no_access('booking', lang('missing id'));
		}
		$entityform = $this->bo->read_single($id);
		if (!$entityform)
		{
			phpgw::no_access('booking', lang('missing entry. Id %1 is invalid', $id));
		}
		$errors = array();
		if ($_SERVER['REQUEST_METHOD'] == 'POST')
		{
			$_values = extract_values($_POST, array('name', 'active', 'activities', 'building_id', 'resources', 'location_id'));
			$entityform = array_merge($entityform, $_values);
			$entityform['active'] = !empty($_values['active']) ? 1 : 0;
			$errors = $this->bo->validate($entityform);
			if (!$errors)
			{
				$this->bo->update($entityform);
				self::redirect(array('menuaction' => 'booking.uiresource_activity_entityform.index'));
			}
		}
		$this->_render_form($entityform, $errors);
	}

	public function add()
	{
		$errors = array();
		$entityform = array(
			'id' => '',
			'name' => '',
			'active' => 1,
			'activities' => array(),
			'resources' => array(),
			'building_id' => '',
			'location_id' => ''
		);
		if ($_SERVER['REQUEST_METHOD'] == 'POST')
		{
			$_values = extract_values($_POST, array('name', 'active', 'activities', 'building_id', 'resources', 'location_id'));
			$entityform = array_merge($entityform, $_values);
			$entityform['active'] = !empty($_values['active']) ? 1 : 0;
			$errors = $this->bo->validate($entityform);
			if (!$errors)
			{
				$this->bo->add($entityform);
				self::redirect(array('menuaction' => 'booking.uiresource_activity_entityform.index'));
			}
		}
		$this->_render_form($entityform, $errors);
	}

	private function _render_form(array $entityform, array $errors = array())
	{
		foreach ($errors as $error)
		{
			Cache::message_set(implode("<br/>", (array)$error), 'error');
		}

		$tabs = array();
		$tabs['generic'] = array('label' => lang('Entityform New'), 'link' => '#entityform_new');
		$active_tab = 'generic';

		$activities = $this->activity_bo->fetch_activities();
		$activities = $activities['results'];
		foreach ($activities as &$activity)
		{
			$activity['selected'] = in_array($activity['id'], (array)$entityform['activities']);
		}

		if (!empty($entityform['building_id']))
		{
			$building = $this->building_bo->read_single($entityform['building_id']);
			if ($building)
			{
				$entityform['building_name'] = $building['name'];
			}
		}

		$entities = $this->boadmin_entity->read();
		$categories = array();

		if (!empty($entityform['location_id']))
		{
			$location_obj = new Locations();
			$location = $location_obj->get_location($entityform['location_id']);
			$entity_id = explode('.', $location)[2];

			foreach ($entities as &$entity)
			{
				$entity['selected'] = $entity['id'] == $entity_id ? 1 : 0;
			}

			if ($entity_id)
			{
				$categories = $this->boadmin_entity->read_category(['entity_id' => $entity_id], ['allrows' => true]);
				foreach ($categories as &$category)
				{
					$category['id'] = $category['location_id'];
					$category['selected'] = $category['location_id'] == $entityform['location_id'] ? 1 : 0;
				}
			}
		}

		$data = array(
			'tabs' => phpgwapi_jquery::tabview_generate($tabs, $active_tab),
			'entityform' => $entityform,
			'activities' => array('options' => $activities),
			'entities' => array('options' => $entities),
			'categories' => array('options' => $categories),
			'resources_json' => json_encode($entityform['resources']),
			'cancel_link' => self::link(array('menuaction' => 'booking.uiresource_activity_entityform.index')),
			'validator' => phpgwapi_jquery::formvalidator_generate(array(
				'location',
				'date',
				'security',
				'file'
			))
		);

		phpgwapi_jquery::load_widget('select2');
		self::add_javascript('booking', 'base', 'resource_activity_entityform.js');

		self::render_template_xsl(array('resource_activity_entityform'), array(
			'edit' => $data
		));
	}

	public function get_categories()
	{
		$entity_id = Sanitizer::get_var('entity_id', 'int');
		$categories = $this->boadmin_entity->read_category(['entity_id' => $entity_id], ['allrows' => true]);
		return $categories;
	}
}
