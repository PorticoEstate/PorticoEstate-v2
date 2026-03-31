<?php

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\controllers\Locations;
use App\modules\phpgwapi\services\Cache;
use App\modules\phpgwapi\security\Acl;
use App\modules\phpgwapi\services\CustomFields;

phpgw::import_class('booking.uicommon');

class booking_uiresource_activity_entityform extends booking_uicommon
{

	public $public_functions = array(
		'index' => true,
		'query' => true,
		'add' => true,
		'show' => true,
		'edit' => true,
		'delete' => true,
		'get_categories' => true,
		'toggle_show_inactive' => true,

	);

	var $bo, $display_name, $activity_bo, $resource_bo, $building_bo, $boadmin_entity;



	public function __construct()
	{
		parent::__construct();
		$acl_read = $this->acl->check('admin', Acl::READ, 'booking');
		if (!$acl_read)
		{
			phpgw::no_access('booking', 'Booking Admin');
		}

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
		//ACL check handled in constructor, if user has no read access, they won't get here.

		$entityforms = $this->bo->read();

		$lang_yes = lang('yes');
		$lang_no = lang('no');
		$location_obj = new Locations();
		

		array_walk($entityforms["results"], array($this, "_add_links"), "booking.uiresource_activity_entityform.show");
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
			$location = $location_obj->get_location($entityform['location_id']);
			$entityform['form_location'] = $location;
		}
		$results = $this->jquery_results($entityforms);
		return $results;
	}

	public function index()
	{
		//ACL check handled in constructor, if user has no read access, they won't get here.

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
						'key' => 'form_location',
						'label' => lang('form'),
						'sortable' => false,
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
			'my_name' => 'show',
			'statustext' => lang('view'),
			'text' => lang('view'),
			'action' => phpgw::link('/index.php', array(
				'menuaction' => 'booking.uiresource_activity_entityform.show'
			)),
			'parameters' => json_encode($parameters)
		);

		$data['datatable']['actions'][] = array(
			'my_name' => 'edit',
			'statustext' => lang('edit'),
			'text' => lang('edit'),
			'action' => phpgw::link('/index.php', array(
				'menuaction' => 'booking.uiresource_activity_entityform.edit'
			)),
			'parameters' => json_encode($parameters)
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

	public function add()
	{

		$acl_add = $this->acl->check('admin', Acl::ADD, 'booking');
		if (!$acl_add)
		{
			phpgw::no_access();
		}
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


	public function edit()
	{
		$acl_edit = $this->acl->check('admin', Acl::EDIT, 'booking');
		if (!$acl_edit)
		{
			phpgw::no_access();
		}

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


	public function delete()
	{
		$acl_delete = $this->acl->check('admin', Acl::DELETE, 'booking');
		if (!$acl_delete)
		{
			phpgw::no_access();
		}
		$status = array('deleted' => false);

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

		if($this->bo->delete($id))
		{
			$status['deleted'] = true;
		}

		return $status;
	}


	public function show()
	{
		//ACL check handled in constructor, if user has no read access, they won't get here.

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

		$activities = $this->activity_bo->fetch_activities();
		$activities = $activities['results'];
		$activity_names = array();
		foreach ($activities as $activity)
		{
			if (in_array($activity['id'], (array)$entityform['activities']))
			{
				$activity_names[] = $activity['name'];
			}
		}
		$entityform['activity_names'] = implode(', ', $activity_names);

		if (!empty($entityform['building_id']))
		{
			$building = $this->building_bo->read_single($entityform['building_id']);
			if ($building)
			{
				$entityform['building_name'] = $building['name'];
			}
		}

		if (!empty($entityform['resources']))
		{
			$resources = $this->resource_bo->so->read(array('results' => 'all', 'filters' => array(
				'id' => $entityform['resources']
			)));
			$resource_names = array();
			if (!empty($resources['results']))
			{
				foreach ($resources['results'] as $resource)
				{
					$resource_names[] = $resource['name'];
				}
			}
			$entityform['resource_names'] = implode(', ', $resource_names);
		}

		$attribute_definitions = array();
		if (!empty($entityform['location_id']))
		{
			$location = (new Locations())->get_location($entityform['location_id']);

			$entityform['location_name'] = $location;
			$attrib_data = $this->get_attributes($entityform['location_id']);
			$attributes = (array)($attrib_data['attributes'] ?? array());
			$groups = (array)($attrib_data['groups'] ?? array());

			$groups_by_id = array();
			foreach ($groups as $group)
			{
				$group_id = (int)($group['id'] ?? 0);
				if (!$group_id)
				{
					continue;
				}

				$groups_by_id[$group_id] = array(
					'id' => $group_id,
					'name' => trim(ltrim((string)($group['name'] ?? ''), '.')),
					'parent_id' => (int)($group['parent_id'] ?? 0),
				);
			}

			$group_path_cache = array();
			foreach ($attributes as $attribute)
			{
				$group_id = (int)($attribute['group_id'] ?? 0);
				$group_relation = $this->build_group_relation($group_id, $groups_by_id, $group_path_cache);

				$choice_values = array();
				if (!empty($attribute['choice']) && is_array($attribute['choice']))
				{
					foreach ($attribute['choice'] as $choice)
					{
						$choice_values[] = $choice['title'] ?: ($choice['text'] ?: ($choice['value'] ?? ''));
					}
				}

				$attribute_definitions[] = array(
					'group_relation' => $group_relation,
					'name' => $attribute['input_text'] ?: ($attribute['name'] ?? ''),
					'datatype' => $attribute['trans_datatype'] ?: ($attribute['datatype'] ?? ''),
					'choices' => implode(', ', array_filter($choice_values)),
				);
			}
		}
		$data = array(
			'entityform'  => $entityform,
			'attribute_definitions' => $attribute_definitions,
			'edit_link'   => self::link(array('menuaction' => 'booking.uiresource_activity_entityform.edit', 'id' => $id)),
			'cancel_link' => self::link(array('menuaction' => 'booking.uiresource_activity_entityform.index')),
		);

		self::render_template_xsl(array('resource_activity_entityform'), array('show' => $data));
	}

	private function build_group_relation($group_id, array $groups_by_id, array &$cache)
	{
		if (!$group_id)
		{
			return '';
		}

		if (isset($cache[$group_id]))
		{
			return $cache[$group_id];
		}

		if (empty($groups_by_id[$group_id]))
		{
			return '';
		}

		$group = $groups_by_id[$group_id];
		$parent_id = (int)($group['parent_id'] ?? 0);
		$name = (string)($group['name'] ?? '');

		if ($parent_id && !empty($groups_by_id[$parent_id]))
		{
			$parent_path = $this->build_group_relation($parent_id, $groups_by_id, $cache);
			$cache[$group_id] = $parent_path ? "{$parent_path} > {$name}" : $name;
		}
		else
		{
			$cache[$group_id] = $name;
		}

		return $cache[$group_id];
	}

	private function _render_form(array $entityform, array $errors = array())
	{
		foreach ($errors as $error)
		{
			Cache::message_set(implode("<br/>", (array)$error), 'error');
		}

		$tabs = array();

		$tab_label = empty($entityform['id']) ? lang('Add') : lang('Edit');
		$tabs['generic'] = array('label' => $tab_label, 'link' => '#entityform_new');
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
		if (!$entity_id)
		{
			return [];
		}
		$categories = $this->boadmin_entity->read_category(['entity_id' => $entity_id], ['allrows' => true]);
		return $categories;
	}


	/**
	 * Get attributes for a given location_id. This is used to display the attributes in the show view of the entity form, and also to validate the attributes when saving the entity form.
	 * @param int $location_id
	 * @return array
	 */
	public function get_attributes($location_id)
	{
		$CustomFields = new CustomFields('property');
		$attrib_data = $CustomFields->find2(
			$location_id,
			$start = 0,
			$query = '',
			$sort = 'ASC',
			$order = 'attrib_sort',
			$allrows = true,
			$inc_choices = true,
			$filter = array(),
			$results = 0
		);

		$location_obj = new Locations();
		$location = $location_obj->get_location($location_id);
		$group_data = $CustomFields->find_group('property', $location, 0, '', '', '', true);

		return array(
			'attributes' => $attrib_data,
			'groups' => $group_data
		);
	}
}
