<?php

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\security\Acl;
use App\modules\phpgwapi\controllers\Accounts\Accounts;
use App\modules\phpgwapi\services\Cache;


phpgw::import_class('booking.uicommon');

phpgw::import_class('booking.uidocument_building');
phpgw::import_class('booking.uipermission_building');

//	phpgw::import_class('phpgwapi.uicommon_jquery');

class booking_uicompleted_reservation_export extends booking_uicommon
{

	public $public_functions = array(
		'index'	 => true,
		'query'	 => true,
		'add'	 => true,
		'archive' => true,
		'show'	 => true,
		'delete' => true
	);
	protected
		$module = 'booking',
		$fields = array(
			'season_id',
			'season_name',
			'building_id',
			'building_name',
			'from_',
			'to_',
			'export_configurations'
		);

	var $generated_files_bo, $display_name;
	public function __construct()
	{
		parent::__construct();
		$this->bo = CreateObject('booking.bocompleted_reservation_export');
		$this->generated_files_bo = CreateObject('booking.bocompleted_reservation_export_file');
		self::set_active_menu('booking::invoice_center::exported_files');
		$this->url_prefix = 'booking.uicompleted_reservation_export';
		$this->display_name = lang('Exported Files');
		Settings::getInstance()->update('flags', ['app_header' => lang('booking') . "::{$this->display_name}"]);
	}

	public function link_to($action, $params = array())
	{
		return $this->link($this->link_to_params($action, $params));
	}

	public function redirect_to($action, $params = array())
	{
		return self::redirect($this->link_to_params($action, $params));
	}

	public function link_to_params($action, $params = array())
	{
		if (isset($params['ui']))
		{
			$ui = $params['ui'];
			unset($params['ui']);
		}
		else
		{
			$ui = 'completed_reservation_export';
		}

		$action = sprintf($this->module . '.ui%s.%s', $ui, $action);
		return array_merge(array('menuaction' => $action), $params);
	}

	protected function generate_files()
	{
		$filter_to = Sanitizer::get_var('filter_to', 'string', 'REQUEST', null);
		$filter_params = is_null($filter_to) ? array() : array('filter_to' => $filter_to);

		if (!($this->acl->check('run', Acl::READ, 'admin') || $this->acl->check('admin', Acl::ADD, 'booking') || $this->bo->has_role(booking_sopermission::ROLE_MANAGER)))
		{
			//$this->flash_form_errors(array('access_denied' => lang("Access denied")));
			Cache::message_set(lang('Access denied'), 'error');
			$this->redirect_to('index', $filter_params);
		}
		//This will read all of the list data using the values of the standard search filters in the ui index view
		$exports = $this->bo->read_all();

		if (!is_array($exports) || count($exports['results']) <= 0)
		{
			//$this->flash_form_errors(array('empty_list' => lang("Cannot generate files from empty list")));
			Cache::message_set(lang('Cannot generate files from empty list'), 'error');
			$this->redirect_to('index', $filter_params);
		}

		if (is_array($this->generated_files_bo->generate_for($exports['results'])))
		{
			$this->redirect_to('index', array('ui' => 'completed_reservation_export_file'));
		}

		//$this->flash_form_errors(array('already_generated' => lang("The invoice data in this list already has generated files")));
		Cache::message_set(lang('The invoice data in this list already has generated files'), 'error');
		$this->redirect_to('index', $filter_params);
	}

	public function index()
	{
		if (Sanitizer::get_var('phpgw_return_as') == 'json')
		{
			return $this->query();
		}

		if (Sanitizer::get_var('generate_files'))
		{
			$this->generate_files();
		}
		$jqcal2 = createObject('phpgwapi.jqcal2');
		$jqcal2->add_listener('filter_to');
		phpgwapi_jquery::load_widget('datepicker');

		self::add_javascript('booking', 'base', 'completed_reservation_export.js');

		$data = array(
			'datatable_name' => $this->display_name,
			'form' => array(
				'toolbar' => array(
					'item' => array(
						array(
							'type' => 'date-picker',
							'id' => 'to',
							'name' => 'to',
							'value' => '',
							'text' => lang('To') . ':'
						),
					),
				),
				#					'list_actions' => array(
				#						'item' => array(
				#							array(
				#								'type' => 'submit',
				#								'name' => 'generate_files',
				#								'value' => lang('Generate files').'...',
				#							),
				#						)
				#					),
			),
			'datatable' => array(
				'source' => $this->link_to('index', array('phpgw_return_as' => 'json')),
				'sorted_by' => array('key' => 0, 'dir' => 'desc'), //id
				'field' => array(
					array(
						'key' => 'id',
						'label' => lang('ID'),
						'formatter' => 'JqueryPortico.formatLink'
					),
					array(
						'key' => 'building_id',
						'label' => lang('Building'),
						'formatter' => 'JqueryPortico.formatLinkGeneric',
					),
					array(
						'key' => 'season_id',
						'label' => lang('Season'),
						'formatter' => 'JqueryPortico.formatLinkGeneric',
					),
					array(
						'key' => 'from_',
						'label' => lang('From'),
					),
					array(
						'key' => 'to_',
						'label' => lang('To'),
					),
					array(
						'key' => 'created_on',
						'label' => lang('Created'),
					),
					array(
						'key' => 'created_by_name',
						'label' => lang('Created by'),
					),
					array(
						'key' => 'total_items',
						'label' => lang('Total Items'),
					),
					array(
						'key' => 'total_cost',
						'label' => lang('Total Cost'),
					),
					array(
						'key' => 'internal',
						'label' => lang('Int. invoice file'),
						'formatter' => 'JqueryPortico.formatLinkGeneric',
						'sortable' => false,
					),
					array(
						'key' => 'external',
						'label' => lang('Ext. invoice file'),
						'formatter' => 'JqueryPortico.formatLinkGeneric',
						'sortable' => false,
					),
					array(
						'key' => 'link',
						'hidden' => true
					),
				)
			)
		);
		if ($this->acl->check('run', Acl::READ, 'admin') || $this->acl->check('admin', Acl::ADD, 'booking') || $this->bo->has_role(booking_sopermission::ROLE_MANAGER))
		{
			$data['form']['list_actions'] = array(
				'item' => array(
					array(
						'type' => 'button',
						'name' => 'generate_files',
						'value' => lang('Generate files') . '...',
						'onClick' => "generatefiles();"

					),
				)
			);
		}

		$filters_to_array = extract_values($_GET, array("filter_to"));
		$filters_to = $filters_to_array["filter_to"] ? strtotime($filters_to_array["filter_to"]) : time();
		$data['filters'] = date("Y-m-d", $filters_to);

		self::render_template_xsl('datatable2', $data);
	}

	public function query() //index_json
	{
		$search = Sanitizer::get_var('search');
		$order = Sanitizer::get_var('order');
		$columns = Sanitizer::get_var('columns');

		$start = Sanitizer::get_var('start', 'int', 'REQUEST', 0);
		$results = Sanitizer::get_var('length', 'int', 'REQUEST', null);
		$query = $search['value'];
		$sort = $columns[$order[0]['column']]['data'];
		$dir = $order[0]['dir'];


		switch ($sort)
		{
			case 'id':
				$_sort = 'id';
				break;
			case 'building_id':
				$_sort = array('building_id', 'id');
				break;
			case 'season_id':
				$_sort = array('season_id', 'id');
				break;
			case 'from_':
				$_sort = array('from_', 'id');
				break;
			case 'to_':
				$_sort = array('to_', 'id');
				break;
			case 'created_on':
				$_sort = array('created_on', 'id');
				break;
			case 'created_by_name':
				$_sort = array('created_on', 'id');
				break;
			case 'total_items':
				$_sort = array('created_on', 'id');
				break;
			case 'total_cost':
				$_sort = array('created_on', 'id');
				break;
			default:
				$_sort = array('created_on', 'id');
				//					$_sort = $sort;
				$dir = 'DESC';
				break;
		}

		$filters = array();
		foreach ($this->bo->so->get_field_defs() as $field => $params)
		{
			if (Sanitizer::get_var("filter_$field"))
			{
				$filters[$field] = Sanitizer::get_var("filter_$field");
			}
		}
		$filter_to = Sanitizer::get_var('to', 'string', 'REQUEST', null);

		if ($filter_to)
		{
			$filters['where'][] = "%%table%%" . sprintf(".to_ <= '%s 23:59:59'", date("Y-m-d", phpgwapi_datetime::date_to_timestamp($filter_to)));
		}

		$params = array(
			'start' => $start,
			'results' => $results,
			'query' => $query,
			'sort' => $_sort,
			'dir' => $dir,
			'filters' => $filters
		);

		$exports = $this->bo->so->read($params);
		array_walk($exports["results"], array($this, "_add_links"), $this->module . ".uicompleted_reservation_export.show");
		$accounts_obj = new Accounts();

		foreach ($exports["results"] as &$export)
		{
			$export = $this->bo->so->initialize_entity($export);
			$this->add_default_display_data($export);
			$account_id = $accounts_obj->name2id($export['created_by_name']);
			if ($account_id)
			{
				$export['created_by_name'] = $accounts_obj->get($account_id)->__toString();
			}
		}
		$results = $this->jquery_results($exports);
		return $results;
	}

	public function create_link_data($entity, $id_key, $label_key, $null_label, $ui, $action = 'show')
	{
		$link_data = array();

		if (isset($entity[$id_key]) && !empty($entity[$id_key]))
		{
			$link_data['label'] = $entity[$label_key];
			$link_data['href'] = $this->link_to($action, array('ui' => $ui, 'id' => $entity[$id_key]));
		}
		else
		{
			$link_data['label'] = $null_label;
		}

		return $link_data;
	}

	public function create_link_data_by_ref(&$entity, $id_key, $label_key, $null_label, $ui, $action = 'show')
	{
		$entity[$id_key] = $this->create_link_data($entity, $id_key, $label_key, $null_label, $ui, $action);
	}

	public function add_default_display_data(&$export)
	{
		$this->create_link_data_by_ref($export, 'season_id', 'season_name', lang('All'), 'season');
		$this->create_link_data_by_ref($export, 'building_id', 'building_name', lang('All'), 'building');

		$export['created_on'] = pretty_timestamp($export['created_on']);
		$export['from_'] = pretty_timestamp($export['from_']);
		$export['to_'] = pretty_timestamp($export['to_']);
		$export['index_link'] = $this->link_to('index');
		$this->add_export_configurations_display_data($export);
	}

	public function add_export_configurations_display_data(&$export)
	{
		if (is_array($export['export_configurations']))
		{
			foreach ($export['export_configurations'] as $type => $conf)
			{
				if (!is_string($type))
				{
					throw new LogicException("Invalid export configuration type");
				}

				$export[$type] = $this->create_link_data($conf, 'export_file_id', 'export_file_id', lang('Not generated'), 'completed_reservation_export_file');
			}
		}
	}

	public function show()
	{
		$id = Sanitizer::get_var('id', 'int');
		if (!$id)
		{
			phpgw::no_access('booking', lang('missing id'));
		}
		$export = $this->bo->read_single($id);
		if (!$export)
		{
			phpgw::no_access('booking', lang('missing entry. Id %1 is invalid', $id));
		}
		$this->add_default_display_data($export);
		self::add_template_file('helpers');
		$export['cancel_link'] = self::link(array('menuaction' => 'booking.uicompleted_reservation_export.index'));

		$tabs = array();
		$tabs['generic'] = array('label' => lang('Export'), 'link' => '#export');
		$active_tab = 'generic';

		$export['tabs'] = phpgwapi_jquery::tabview_generate($tabs, $active_tab);

		$reversible = true;
		foreach ($export['export_configurations'] as $key => $export_configuration)
		{
			if ($export_configuration['export_file_id'])
			{
				$reversible = false;
				break;
			}
		}

		if ($export['created_by'] ==  $this->current_account_id())
		{
			$show_delete_button = true;
		}

		$export['delete_link'] = self::link(array('menuaction' => 'booking.uicompleted_reservation_export.delete', 'id' => $id));

		self::render_template_xsl('completed_reservation_export', array(
			'export'			 => $export,
			'reversible'		 => $reversible,
			'show_delete_button' => $show_delete_button
		));
	}

	function delete()
	{
		$id = Sanitizer::get_var('id', 'int');

		if (!$id)
		{
			phpgw::no_access('booking', lang('missing id'));
		}

		$export = $this->bo->read_single($id);
		$reversible = true;
		foreach ($export['export_configurations'] as $key => $export_configuration)
		{
			if ($export_configuration['export_file_id'])
			{
				$reversible = false;
				break;
			}
		}

		if ($reversible && $export['created_by'] ==  $this->current_account_id())
		{
			$this->bo->so->reverse_reservation($id);
		}
		else
		{
			Cache::message_set(lang('Access denied'), 'error');
		}

		self::redirect(array('menuaction' => 'booking.uicompleted_reservation.index'));
	}

	protected function get_export_key()
	{
		return Sanitizer::get_var('export_key', 'string', 'REQUEST', null);
	}

	public function pre_validate($export)
	{
		if (!is_array($errors = $this->bo->validate($export)))
		{
			return;
		}

		$export_errors = array_intersect_key(
			$errors,
			array('nothing_to_export' => true, 'invalid_customer_ids' => true)
		);

		if (!count($export_errors) > 0)
		{
			return;
		}

		$redirect_params = array('ui' => 'completed_reservation');

		if ($export_key = $this->get_export_key())
		{
			$redirect_params['export_key'] = $export_key;
		}

		//			$this->flash_form_errors($export_errors);
		foreach ($export_errors as $key => $value)
		{
			Cache::message_set($value, 'error');
		}
		$this->redirect_to('index', $redirect_params);
	}

	public function add()
	{
		//Values passed in from the "Export"-action in uicompleted_reservation.index
		$export = extract_values($_POST, $this->fields);
		$export['process'] = Sanitizer::get_var('process', 'int', 'POST');
		$errors = array();
		if (!Sanitizer::get_var('prevalidate', 'bool'))
		{
			//Fill in a dummy value (so as to temporarily pass validation), this will then be
			//automatically filled in by bo->add process later on.

			if (empty($export['from_']))
			{
				$export['from_'] = date('Y-m-d H:i:s');
			}

			$errors = $this->bo->validate($export);
			if (!$errors)
			{
				try
				{
					$receipt = $this->bo->add($export);
					$this->redirect_to('index');
				}
				catch (booking_unauthorized_exception $e)
				{
					$errors['global'] = lang('Could not add object due to insufficient permissions');
				}
			}
		}

		if (!isset($export['to_']) || empty($export['to_']))
		{
			$export['to_'] = date('Y-m-d');
		}

		//$this->flash_form_errors($errors);

		foreach ($errors as $key => $value)
		{
			Cache::message_set($value, 'error');
		}

		$this->pre_validate($export);

		$cancel_params = array('ui' => 'completed_reservation');
		if ($export_key = $this->get_export_key())
		{
			$cancel_params['export_key'] = $export_key;
		}

		$export['building_name'] = html_entity_decode($export['building_name']);
		$export['cancel_link'] = $this->link_to('index', $cancel_params);
		phpgwapi_jquery::load_widget('autocomplete');
		self::render_template_xsl('completed_reservation_export_form', array(
			'new_form' => true,
			'export' => $export
		));
	}

	//archive
	public function archive()
	{
		//Values passed in from the "Export"-action in uicompleted_reservation.index
		$archive = extract_values($_POST, $this->fields);
		$archive['process'] = Sanitizer::get_var('process', 'int', 'POST');
		$errors = array();
		//Fill in a dummy value (so as to temporarily pass validation), this will then be
		//automatically filled in by bo->add process later on.

		if (empty($archive['from_']))
		{
			$archive['from_'] = date('Y-m-d H:i:s');
			$filter_from = time();
		}
		else
		{
			$filter_from = phpgwapi_datetime::date_to_timestamp($archive['from_']);
		}
		if (!isset($archive['to_']) || empty($archive['to_']))
		{
			$archive['to_'] = date('Y-m-d');
			$filter_to = time();
		}
		else
		{
			$filter_to = phpgwapi_datetime::date_to_timestamp($archive['to_']);
		}

		if (!$errors)
		{
			try
			{
				$receipt = $this->bo->archive($archive);
				phpgw::redirect_link('/', ['menuaction' => 'booking.uicompleted_reservation.index', 'filter_from' => $filter_from, 'filter_to' => $filter_to]);
			}
			catch (Exception $e)
			{
				$errors['global'] =  $e->getMessage();
			}
		}


		//$this->flash_form_errors($errors);

		foreach ($errors as $key => $value)
		{
			Cache::message_set($value, 'error');
		}

		phpgw::redirect_link('/', ['menuaction' => 'booking.uicompleted_reservation.index', 'filter_from' => $filter_from, 'filter_to' => $filter_to]);
	}
}
