<?php

use App\modules\phpgwapi\services\Settings;

phpgw::import_class('booking.uidocument_building');
phpgw::import_class('booking.uipermission_building');

phpgw::import_class('booking.uicommon');

class booking_uiaudience extends booking_uicommon
{

	public $public_functions = array(
		'index'					 => true,
		'query'					 => true,
		'add'					 => true,
		'show'					 => true,
		'active'				 => true,
		'edit'					 => true,
		'toggle_show_inactive'	 => true,
	);
	var $activity_bo;

	public function __construct()
	{
		parent::__construct();

		//			Analizar esta linea de permisos self::process_booking_unauthorized_exceptions();

		$this->bo = CreateObject('booking.boaudience');
		$this->activity_bo = CreateObject('booking.boactivity');

		self::set_active_menu('booking::settings::audience');
		Settings::getInstance()->update('flags', ['app_header' => lang('booking') . '::' . lang('audience')]);
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
		self::redirect(array('menuaction' => 'booking.uiaudience.index'));
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

		if (
			extract_values($_GET, array('sessionShowAll')) &&
			!$_SESSION['ActiveSession']
		)
		{
			$this->bo->set_active_session();
		}

		if (
			extract_values($_GET, array('unsetShowAll')) &&
			$_SESSION['ActiveSession']
		)
		{
			$this->bo->actUnSet();
		}

		$sessionLink = $this->link(array('menuaction' => 'booking.uiaudience.index', 'sessionShowAll' => 'activate'));
		$data = array(
			'form' => array(
				'toolbar' => array(
					//						'item' => array(
					//							array(
					//								'type' => 'link',
					//								'value' => $_SESSION['showall'] ? lang('Show only active') : lang('Show all'),
					//								'href' => self::link(array('menuaction' => 'booking.uiaudience.active'))
					//							),
					//						)
				),
			),
			'datatable' => array(
				'source' => self::link(array('menuaction' => 'booking.uiaudience.index', 'phpgw_return_as' => 'json')),
				'field' => array(
					array(
						'key' => 'sort',
						'label' => lang('Order')
					),
					array(
						'key' => 'name',
						'label' => lang('Target Audience'),
						'formatter' => 'JqueryPortico.formatLink'
					),
					array(
						'key' => 'description',
						'label' => lang('Description')
					),
					array(
						'key' => 'activity_name',
						'label' => lang('activity')
					),
					array(
						'key' => 'link',
						'hidden' => true
					)
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

		try
		{
			if ($this->bo->allow_create())
			{
				$data['datatable']['new_item'] = self::link(array('menuaction' => 'booking.uiaudience.add'));
			}
		}
		catch (booking_unauthorized_exception $ex)
		{
		}


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

		foreach ($groups['results'] as &$audience)
		{
			$audience['link'] = $this->link(array(
				'menuaction' => 'booking.uiaudience.edit',
				'id' => $audience['id']
			));
			//				$audience['active'] = $audience['active'] ? lang('Active') : lang('Inactive');
		}
		return $this->jquery_results($groups);
	}

	public function add()
	{
		$errors = array();
		$audience = array();
		$activity_id = Sanitizer::get_var('activity_id', 'int', 'POST');
		$activities = $this->activity_bo->get_top_level($activity_id);
		if ($_SERVER['REQUEST_METHOD'] == 'POST')
		{
			$audience = extract_values($_POST, array('activity_id', 'name', 'sort', 'description'));
			$audience['active'] = 1;
			$errors = $this->bo->validate($audience);
			if (!$errors)
			{
				$receipt = $this->bo->add($audience);
				self::redirect(array('menuaction' => 'booking.uiaudience.index'));
			}
		}
		array_set_default($audience, 'sort', '0');
		$this->flash_form_errors($errors);
		$audience['cancel_link'] = self::link(array('menuaction' => 'booking.uiaudience.index'));

		$tabs = array();
		$tabs['generic'] = array('label' => lang('new audience group'), 'link' => '#audience_add');
		$active_tab = 'generic';

		//                      $data = array();
		$audience['tabs'] = phpgwapi_jquery::tabview_generate($tabs, $active_tab);
		phpgwapi_jquery::formvalidator_generate(array());
		self::render_template_xsl('audience_new', array('audience' => $audience, 'activities' => $activities));
	}

	public function edit()
	{
		$id = Sanitizer::get_var('id', 'int');
		if (!$id)
		{
			phpgw::no_access('booking', lang('missing id'));
		}
		$audience = $this->bo->read_single($id);

		if (!$audience)
		{
			phpgw::no_access('booking', lang('missing entry. Id %1 is invalid', $id));
		}

		$activities = $this->activity_bo->get_top_level($audience['activity_id']);

		$audience['id'] = $id;
		$audience['resource_link'] = self::link(array(
			'menuaction' => 'booking.uiaudience.show',
			'id' => $audience['id']
		));
		$audience['resources_link'] = self::link(array('menuaction' => 'booking.uiresource.index'));
		$audience['audience_link'] = self::link(array('menuaction' => 'booking.uiaudience.index'));
		$audience['building_link'] = self::link(array('menuaction' => 'booking.uibuilding.index'));
		$errors = array();
		if ($_SERVER['REQUEST_METHOD'] == 'POST')
		{
			$audience = array_merge($audience, extract_values($_POST, array(
				'name', 'sort',
				'description', 'active'
			)));
			$errors = $this->bo->validate($audience);
			if (!$errors)
			{
				$audience = $this->bo->update($audience);
				self::redirect(array('menuaction' => 'booking.uiaudience.index', 'id' => $audience['id']));
			}
		}
		$this->flash_form_errors($errors);

		$tabs = array();
		$tabs['generic'] = array('label' => lang('edit audience group'), 'link' => '#audience_edit');
		$active_tab = 'generic';

		$audience['tabs'] = phpgwapi_jquery::tabview_generate($tabs, $active_tab);

		$audience['cancel_link'] = self::link(array('menuaction' => 'booking.uiaudience.index'));
		phpgwapi_jquery::formvalidator_generate(array());

		self::render_template_xsl('audience_edit', array('audience' => $audience, 'activities' => $activities));
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

		$lang['title'] = lang('New audience');
		$lang['name'] = lang('Name');
		$lang['description'] = lang('Description');
		$lang['resource'] = lang('Resource');
		$lang['create'] = lang('Create');
		$lang['buildings'] = lang('Buildings');
		$lang['resources'] = lang('Resources');
		$resource['edit_link'] = self::link(array(
			'menuaction' => 'booking.uiaudience.edit',
			'id' => $resource['id']
		));
		$resource['cancel_link'] = self::link(array('menuaction' => 'booking.uiaudience.index'));
		$lang['audience'] = lang('audience');
		$lang['save'] = lang('Save');
		$lang['edit'] = lang('Edit');
		$lang['cancel'] = lang('Cancel');
		$data = array(
			'resource' => $resource
		);
		self::render_template_xsl('audience', array('audience' => $data, 'lang' => $lang));
	}
}
