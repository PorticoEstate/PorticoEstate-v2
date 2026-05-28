<?php

/**
 * phpGroupWare - property: a Facilities Management System.
 *
 * @author Sigurd Nes <sigurdne@online.no>
 * @copyright Copyright (C) 2003,2004,2005,2006,2007,2008,2009,2010,2011,2012 Free Software Foundation, Inc. http://www.fsf.org/
 * This file is part of phpGroupWare.
 *
 * phpGroupWare is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * phpGroupWare is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with phpGroupWare; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @internal Development of this application was funded by http://www.bergen.kommune.no/bbb_/ekstern/
 * @package property
 * @subpackage project
 * @version $Id$
 */

use App\modules\phpgwapi\services\Cache;
use App\modules\phpgwapi\services\Settings;

/**
 * Import the jQuery class
 */
phpgw::import_class('phpgwapi.uicommon_jquery');
phpgw::import_class('phpgwapi.jquery');

/**
 * Description
 * @package property
 */
class property_uiproject extends phpgwapi_uicommon_jquery
{

	private $receipt		 = array();
	var $grants;
	var $cat_id;
	var $start;
	var $query;
	var $sort;
	var $order;
	var $filter;
	var $part_of_town_id;
	var $sub;
	var $currentapp;
	var $district_id;
	var $criteria_id;
	var $project_type_id;
	var $ecodimb;
	var $bypass_error	 = false;
	var $account, $bo, $bocommon, $cats, $custom, $acl, $acl_location, $acl_read, $acl_add, $acl_edit,
		$acl_delete, $acl_manage, $status_id, $wo_hour_cat_id, $user_id, $filter_year, $decimal_separator,
		$type_id, $config;
	var $public_functions = array(
		'query'							 => false,
		'index'							 => true,
		'view'							 => true,
		'edit'							 => true,
		'add'							 => true,
		'columns'						 => true,
		'bulk_update_status'			 => true,
	);

	function __construct()
	{
		parent::__construct();

		$this->flags['xslt_app']			 = true;
		$this->flags['menu_selection']	 = 'property::project';
		Settings::getInstance()->update('flags', ['xslt_app' => true, 'menu_selection' => 'property::project']);

		$this->account	 = $this->userSettings['account_id'];
		$this->bo		 = CreateObject('property.boproject', true);
		$this->bocommon	 = &$this->bo->bocommon;
		$this->cats		 = &$this->bo->cats;
		$this->custom	 = &$this->bo->custom;

		$this->acl_location	 = '.project';
		$this->acl_read		 = $this->acl->check('.project', ACL_READ, 'property');
		$this->acl_add		 = $this->acl->check('.project', ACL_ADD, 'property');
		$this->acl_edit		 = $this->acl->check('.project', ACL_EDIT, 'property');
		$this->acl_delete	 = $this->acl->check('.project', ACL_DELETE, 'property');
		$this->acl_manage	 = $this->acl->check('.project', 16, 'property');

		$this->start			 = $this->bo->start;
		$this->query			 = $this->bo->query;
		$this->sort				 = $this->bo->sort;
		$this->order			 = $this->bo->order;
		$this->filter			 = $this->bo->filter;
		$this->cat_id			 = $this->bo->cat_id;
		$this->status_id		 = $this->bo->status_id;
		$this->wo_hour_cat_id	 = $this->bo->wo_hour_cat_id;
		$this->district_id		 = $this->bo->district_id;
		$this->user_id			 = $this->bo->user_id;
		$this->criteria_id		 = $this->bo->criteria_id;
		$this->project_type_id	 = $this->bo->project_type_id;
		$this->filter_year		 = $this->bo->filter_year;
		$this->decimal_separator = ',';
		$this->config				 = CreateObject('phpgwapi.config', 'property')->read();
	}

	function save_sessiondata()
	{
		$data = array(
			'start'				 => $this->start,
			'query'				 => $this->query,
			'sort'				 => $this->sort,
			'order'				 => $this->order,
			'filter'			 => $this->filter,
			'cat_id'			 => $this->cat_id,
			'status_id'			 => $this->status_id,
			'wo_hour_cat_id'	 => $this->wo_hour_cat_id,
			'district_id'		 => $this->district_id,
			'user_id'			 => $this->user_id,
			'criteria_id'		 => $this->criteria_id,
			'project_type_id'	 => $this->project_type_id
		);
		$this->bo->save_sessiondata($data);
	}

	function columns()
	{
		$receipt = array();
		phpgwapi_xslttemplates::getInstance()->add_file(array('columns'));

		$this->flags['noframework']	 = true;
		$this->flags['nofooter']		 = true;
		Settings::getInstance()->update('flags', ['noframework' => true, 'nofooter' => true]);

		$values = Sanitizer::get_var('values');

		$preferences = createObject('phpgwapi.preferences', $this->account);

		if (isset($values['save']) && $values['save'])
		{
			$preferences->add('property', 'project_columns', $values['columns'], 'user');
			$preferences->save_repository();
			$receipt['message'][] = array('msg' => lang('columns is updated'));
		}

		$function_msg = lang('Select Column');

		$link_data = array(
			'menuaction' => 'property.uiproject.columns',
		);

		$selected	 = isset($values['columns']) && $values['columns'] ? $values['columns'] : array();
		$msgbox_data = $this->phpgwapi_common->msgbox_data($receipt);

		$data = array(
			'msgbox_data'	 => $this->phpgwapi_common->msgbox($msgbox_data),
			'column_list'	 => $this->bo->column_list($selected, $this->type_id, $allrows		 = true),
			'function_msg'	 => $function_msg,
			'form_action'	 => phpgw::link('/index.php', $link_data),
			'lang_columns'	 => lang('columns'),
			'lang_none'		 => lang('None'),
			'lang_save'		 => lang('save'),
		);

		$this->flags['app_header'] = $function_msg;
		Settings::getInstance()->update('flags', ['app_header' => $this->flags['app_header']]);

		phpgwapi_xslttemplates::getInstance()->set_var('phpgw', array('columns' => $data));
	}

	private function _get_Filters()
	{
		$values_combo_box	 = array();
		$combos				 = array();

		$values_combo_box[0] = $this->bo->get_project_types($this->project_type_id);
		array_unshift($values_combo_box[0], array('id' => '', 'name' => lang('project type')));
		$combos[]			 = array(
			'type'	 => 'filter',
			'name'	 => 'project_type_id',
			'text'	 => lang('Project Type'),
			'list'	 => $values_combo_box[0]
		);

		$values_combo_box[1] = $this->bocommon->select_district_list('filter', $this->district_id);
		$default_value		 = array('id' => '', 'name' => lang('no district'));
		array_unshift($values_combo_box[1], $default_value);
		$combos[]			 = array(
			'type'	 => 'filter',
			'name'	 => 'district_id',
			'text'	 => lang('District'),
			'list'	 => $values_combo_box[1]
		);

		$_cats				 = $this->cats->return_sorted_array(0, false, '', '', '', false, false);
		$values_combo_box[2] = array();
		foreach ($_cats as $_cat)
		{
			if ($_cat['level'] == 0 && $_cat['active'] != 2)
			{
				$values_combo_box[2][] = $_cat;
			}
		}

		$default_value	 = array('id' => '', 'name' => lang('no category'));
		array_unshift($values_combo_box[2], $default_value);
		$combos[]		 = array(
			'type'	 => 'filter',
			'name'	 => 'cat_id',
			'text'	 => lang('Category'),
			'list'	 => $values_combo_box[2]
		);

		$values_combo_box[3] = $this->bo->select_status_list('filter');
		array_unshift($values_combo_box[3], array('id' => 'open', 'name' => lang('open')));
		array_unshift($values_combo_box[3], array('id' => 'all', 'name' => lang('all')));

		foreach ($values_combo_box[3] as &$_entry)
		{
			$_entry['selected'] = $_entry['id'] == $this->status_id ? 1 : 0;
		}
		$combos[] = array(
			'type'	 => 'filter',
			'name'	 => 'status_id',
			'text'	 => lang('Status'),
			'list'	 => $values_combo_box[3]
		);

		$values_combo_box[4] = $this->bocommon->select_category_list(array(
			'format'	 => 'filter',
			'selected'	 => $this->wo_hour_cat_id,
			'type'		 => 'wo_hours',
			'order'		 => 'id'
		));
		$default_value		 = array('id' => '', 'name' => lang('no hour category'));
		array_unshift($values_combo_box[4], $default_value);
		$combos[]			 = array(
			'type'	 => 'filter',
			'name'	 => 'wo_hour_cat_id',
			'text'	 => lang('Hour Category'),
			'list'	 => $values_combo_box[4]
		);

		$values_combo_box[5] = $this->bo->get_criteria_list($this->criteria_id);
		$default_value		 = array('id' => '', 'name' => lang('no criteria'));
		array_unshift($values_combo_box[5], $default_value);
		$combos[]			 = array(
			'type'	 => 'filter',
			'name'	 => 'criteria_id',
			'text'	 => lang('search criteria'),
			'list'	 => $values_combo_box[5]
		);

		$values_combo_box[6] = $this->bo->get_filter_year_list($this->filter_year);
		array_unshift($values_combo_box[6], array('id' => 'all', 'name' => lang('all') . ' ' . lang('year')));
		$combos[]			 = array(
			'type'	 => 'filter',
			'name'	 => 'filter_year',
			'text'	 => lang('Year'),
			'list'	 => $values_combo_box[6]
		);

		$values_combo_box[7] = $this->bo->get_user_list($this->filter);
		array_unshift($values_combo_box[7], array(
			'id'	 => $this->userSettings['account_id'],
			'name'	 => lang('mine projects')
		));
		$default_value		 = array('id' => '', 'name' => lang('no user'));
		array_unshift($values_combo_box[7], $default_value);
		$combos[]			 = array(
			'type'	 => 'filter',
			'name'	 => 'filter',
			'text'	 => lang('User'),
			'list'	 => $values_combo_box[7]
		);

		if (isset($this->config['budget_account_at_project']) && $this->config['budget_account_at_project'])
		{
			$values_combo_box[8]		 = execMethod('property.bogeneric.get_list', array(
				'type' => 'budget_account',
				'filter' => array('active' => 1),
				'selected' => ''
			));

			$default_value		 = array('id' => '', 'name' => lang('select'));
			array_unshift($values_combo_box[8], $default_value);
			$combos[]			 = array(
				'type'	 => 'filter',
				'name'	 => 'b_account_id',
				'text'	 => lang('budget account'),
				'list'	 => $values_combo_box[8]
			);
		}

		return $combos;
	}

	function index()
	{
		$this->flags['menu_selection'] .= '::project';

		if ($this->cat_id)
		{
			$this->flags['menu_selection'] .= "::{$this->cat_id}";
		}
		Settings::getInstance()->update('flags', ['menu_selection' => $this->flags['menu_selection']]);

		if (!$this->acl_read)
		{
			phpgw::no_access();
		}

		$lookup			 = Sanitizer::get_var('lookup', 'bool');
		$make_relation	 = Sanitizer::get_var('make_relation', 'bool');
		$relation_id	 = Sanitizer::get_var('relation_id', 'int');
		$relation_type	 = Sanitizer::get_var('relation_type');
		if ($make_relation)
		{
			$lookup = true;
		}

		switch ($relation_type)
		{
			case 'ticket':
				$update_menuaction		 = 'property.uitts.view';
				$lang_update_relation	 = lang('update ticket');
				break;
			default:
				break;
		}



		$from = Sanitizer::get_var('from');

		$default_district = (isset($this->userSettings['preferences']['property']['default_district']) ? $this->userSettings['preferences']['property']['default_district'] : '');

		if ($default_district && !isset($_REQUEST['district_id']))
		{
			$this->bo->district_id	 = $default_district;
			$this->district_id		 = $default_district;
		}

		phpgwapi_jquery::load_widget('numberformat');

		$jqcal = CreateObject('phpgwapi.jqcal');
		$jqcal->add_listener('filter_start_date');
		$jqcal->add_listener('filter_end_date');
		phpgwapi_jquery::load_widget('datepicker');

		$appname		 = lang('Project');
		$function_msg	 = lang('list Project');

		$this->flags['app_header'] = lang('property') . ' - ' . $appname . ': ' . $function_msg;
		Settings::getInstance()->update('flags', ['app_header' => $this->flags['app_header']]);

		$query = Sanitizer::get_var('query');

		$data = array(
			'datatable_name' => $appname . ': ' . $function_msg,
			'form'			 => array(
				'toolbar' => array(
					'item' => array(
						array(
							'type'	 => 'date-picker',
							'id'	 => 'start_date',
							'name'	 => 'start_date',
							'value'	 => '',
							'text'	 => lang('from')
						),
						array(
							'type'	 => 'date-picker',
							'id'	 => 'end_date',
							'name'	 => 'end_date',
							'value'	 => '',
							'text'	 => lang('to')
						)
					)
				)
			),
			'datatable'		 => array(
				'source'		 => \phpgw::link('/property/project/datatable', array(
					'lookup'			 => $lookup,
					'from'				 => $from,
					'make_relation'		 => $make_relation,
					'relation_id'		 => $relation_id,
					'relation_type'		 => $relation_type
				)),
				'download'		 => phpgw::link('/property/project/reports/download', array(
					'export'		 => true,
					'skip_origin'	 => true,
					'allrows'		 => true
				)),
				"columns"		 => array('onclick' => "JqueryPortico.openPopup({menuaction:'property.uiproject.columns'},{closeAction:'reload'})"),
				'new_item'		 => self::link(array(
					'menuaction' => 'property.uiproject.add',
					'location_code'	=> $this->criteria_id == 4 ? $query : '',
					'bypass'		=> $this->criteria_id == 4 && $query ? true : false
				)),
				'allrows'		 => true,
				'select_all'	 => $make_relation,
				'editor_action'	 => '',
				'field'			 => array(),
				'query'			 => $query
			)
		);

		$filters = $this->_get_Filters();
		krsort($filters);
		foreach ($filters as $filter)
		{
			array_unshift($data['form']['toolbar']['item'], $filter);
		}

		$this->bo->read(array('dry_run' => true));
		$uicols				 = $this->bo->uicols;
		$count_uicols_name	 = count($uicols['name']);

		for ($k = 0; $k < $count_uicols_name; $k++)
		{
			$params = array(
				'key'		 => $uicols['name'][$k],
				'label'		 => $uicols['descr'][$k],
				'sortable'	 => ($uicols['sortable'][$k]) ? true : false,
				'hidden'	 => ($uicols['input_type'][$k] == 'hidden') ? true : false
			);

			if ($uicols['name'][$k] == 'project_id' && !$lookup)
			{
				$params['formatter'] = 'JqueryPortico.formatProject';
			}

			if ($uicols['name'][$k] == 'ticket' && !$lookup)
			{
				$params['formatter'] = 'formatLinkTicket';
			}

			if ($uicols['name'][$k] == 'loc1')
			{
				# Aca debe ser el Onclick
				$params['formatter'] = 'JqueryPortico.searchLink';
			}
			if ($uicols['name'][$k] == 'actual_cost')
			{
				$params['formatter'] = 'JqueryPortico.FormatterAmount0';
			}
			if ($uicols['name'][$k] == 'combined_cost')
			{
				$params['formatter'] = 'JqueryPortico.FormatterAmount0';
			}
			if ($uicols['name'][$k] == 'budget')
			{
				$params['formatter'] = 'JqueryPortico.FormatterAmount0';
			}
			if ($uicols['name'][$k] == 'diff')
			{
				$params['formatter'] = 'JqueryPortico.FormatterAmount0';
			}
			if ($uicols['name'][$k] == 'obligation')
			{
				$params['formatter'] = 'JqueryPortico.FormatterAmount0';
			}

			array_push($data['datatable']['field'], $params);
		}

		if (!$lookup)
		{
			$parameters = array(
				'parameter' => array(
					array(
						'name'	 => 'id',
						'source' => 'project_id'
					),
				)
			);

			$parameters2 = array(
				'parameter' => array(
					array(
						'name'	 => 'project_id',
						'source' => 'project_id'
					),
				)
			);

			if ($this->acl_read)
			{
				$data['datatable']['actions'][] = array(
					'my_name'	 => 'view',
					'statustext' => lang('view the project'),
					'text'		 => lang('view'),
					'action'	 => phpgw::link('/index.php', array(
						'menuaction' => 'property.uiproject.view'
					)),
					'parameters' => json_encode($parameters)
				);

				$data['datatable']['actions'][] = array(
					'my_name'	 => 'view',
					'statustext' => lang('view the project'),
					'text'		 => lang('open view in new window'),
					'action'	 => phpgw::link('/index.php', array(
						'menuaction' => 'property.uiproject.view'
					)),
					'target'	 => '_blank',
					'parameters' => json_encode($parameters)
				);

				$jasper = execMethod('property.sojasper.read', array('location_id' => $this->locations->get_id('property', $this->acl_location)));

				foreach ($jasper as $report)
				{
					$data['datatable']['actions'][] = array(
						'my_name'	 => 'edit',
						'text'		 => lang('open JasperReport %1 in new window', $report['title']),
						'action'	 => phpgw::link('/index.php', array(
							'menuaction' => 'property.uijasper.view',
							'jasper_id'	 => $report['id']
						)),
						'target'	 => '_blank',
						'parameters' => json_encode($parameters)
					);
				}
			}

			if ($this->acl_edit)
			{
				$data['datatable']['actions'][] = array(
					'my_name'	 => 'edit',
					'statustext' => lang('edit the project'),
					'text'		 => lang('edit'),
					'action'	 => phpgw::link('/index.php', array(
						'menuaction' => 'property.uiproject.edit'
					)),
					'parameters' => json_encode($parameters)
				);

				$data['datatable']['actions'][] = array(
					'my_name'	 => 'edit',
					'statustext' => lang('edit the project'),
					'text'		 => lang('open edit in new window'),
					'action'	 => phpgw::link('/index.php', array(
						'menuaction' => 'property.uiproject.edit'
					)),
					'target'	 => '_blank',
					'parameters' => json_encode($parameters)
				);

				$data['datatable']['actions'][] = array(
					'my_name'	 => 'edit',
					'statustext' => lang('Add a workorder to this project'),
					'text'		 => lang('Add a workorder to this project'),
					'action'	 => phpgw::link('/index.php', array(
						'menuaction' => 'property.uiworkorder.edit'
					)),
					'parameters' => json_encode($parameters2)
				);
			}

			if ($this->acl_delete)
			{
				$data['datatable']['actions'][] = array(
					'my_name'		 => 'delete',
					'text'			 => lang('delete'),
					'confirm_msg'	 => lang('do you really want to delete this entry'),
					'type'			 => 'custom',
					'custom_code'	 => "
									var api = oTable.api();
									var selected = api.rows( { selected: true } ).data();
									for ( var n = 0; n < selected.length; ++n )
									{
										var aData = selected[n];
										var requestUrl = phpGWLink('property/project/' + aData['project_id'], {});
										execute_ajax(requestUrl, function(result){
											var message = result && result.message ? result.message : result;
											document.getElementById('message').innerHTML += '<br/>' + message;
											api.draw('page');
										}, {}, 'DELETE', 'json');
									}
								"
				);
			}
		}

		unset($parameters);

		$function_exchange_values = '';
		if ($lookup && !$make_relation)
		{
			$oArg = "{menuaction: 'property.ui{$from}.edit',"
				. "origin:'" . Sanitizer::get_var('origin') . "',"
				. "origin_id:'" . Sanitizer::get_var('origin_id') . "',"
				. "project_id: aData['project_id']}";

			$confirm_msg = addslashes(lang('new') . ' ' . lang(str_replace('_', ' ', $from)) . '?');
			$data['left_click_action'] = "if(confirm('{$confirm_msg}')){window.open(phpGWLink('index.php', {$oArg}),'_self');}";
		}

		if ($make_relation)
		{
			$parameters3 = array(
				'parameter' => array(
					array(
						'name'	 => 'add_relation',
						'source' => 'project_id'
					),
				)
			);

			$data['datatable']['actions'][] = array(
				'my_name'	 => 'update_ticket',
				'text'		 => lang('update ticket'),
				'action'	 => phpgw::link(
					'/index.php',
					array(
						'menuaction'	 => $update_menuaction,
						'id'			 => $relation_id,
						'relation_type'	 => 'project',
					)
				),
				'parameters' => json_encode($parameters3)
			);
		}

		if (!empty($query))
		{
			$code = <<<JS
					function initCompleteDatatable(oSettings, json, oTable)
					{
						setTimeout(function() {
							var api = oTable.api();
							api.search( '$query' ).draw();
						}, 1);
		}
JS;

			phpgwapi_js::getInstance()->add_code('', $code, true);
		}


		self::add_javascript('property', 'base', 'project.index.js');
		self::render_template_xsl('datatable2', $data);
	}

	//deprecated function, but neede for compatibility with parent class
	public function query()
	{
		$values = [];

		$result_data					 = array('results' => $values);
		$result_data['total_records']	 = 0;
		$result_data['draw']			 = 1;

		return $this->jquery_results($result_data);
	}


	public function add()
	{
		$this->edit();
	}

	function edit($values = array(), $mode = 'edit')
	{
		$this->flags['menu_selection'] .= '::project';
		Settings::getInstance()->update('flags', ['menu_selection' => $this->flags['menu_selection']]);

		$id = isset($values['id']) && $values['id'] ? $values['id'] : (int)Sanitizer::get_var('id', 'int');

		if ($mode == 'edit' && (!$this->acl_add && !$this->acl_edit))
		{
			phpgw::redirect_link('/index.php', array(
				'menuaction' => 'property.uiproject.view',
				'id'		 => $id
			));
		}

		if ($mode == 'view')
		{
			if (!$this->acl_read)
			{
				$this->bocommon->no_access();
				return;
			}

			if (!$id)
			{
				Cache::message_set('ID is required for the function uiproject::view()', 'error');
				phpgw::redirect_link('/index.php', array('menuaction' => 'property.uiproject.index'));
			}
		}
		else
		{
			if (!$this->acl_add && !$this->acl_edit)
			{
				$this->bocommon->no_access();
				return;
			}
		}

		$bypass		 = Sanitizer::get_var('bypass', 'bool');
		$add_request = Sanitizer::get_var('add_request');

		if (Sanitizer::get_var('origin') == '.project.request' && Sanitizer::get_var('origin_id', 'int') && !$bypass)
		{
			$id			 = Sanitizer::get_var('project_id', 'int');
			$add_request = array('request_id' => array(Sanitizer::get_var('origin_id', 'int')));
		}

		if ($mode == 'edit' && $add_request && $id)
		{
			$receipt = $this->bo->add_request($add_request, $id);
		}

		$bolocation = CreateObject('property.bolocation');

		// origin

		if (!$id && $bypass)
		{
			$location_code								 = Sanitizer::get_var('location_code');
			$tenant_id									 = Sanitizer::get_var('tenant_id', 'int');
			$values['descr']							 = Sanitizer::get_var('descr');
			$p_entity_id								 = Sanitizer::get_var('p_entity_id', 'int');
			$p_cat_id									 = Sanitizer::get_var('p_cat_id', 'int');
			$p_num										 = Sanitizer::get_var('p_num');
			$values['p'][$p_entity_id]['p_entity_id']	 = $p_entity_id;
			$values['p'][$p_entity_id]['p_cat_id']		 = $p_cat_id;
			$values['p'][$p_entity_id]['p_num']			 = $p_num;

			$origin		 = Sanitizer::get_var('origin');
			$origin_id	 = Sanitizer::get_var('origin_id', 'int');

			if ($origin == '.ticket' && $origin_id && !$values['descr'])
			{
				$boticket		 = CreateObject('property.botts');
				$ticket			 = $boticket->read_single($origin_id);
				$values['descr'] = strip_tags($ticket['details']);
				$values['name']	 = $ticket['subject'] ? $ticket['subject'] : $ticket['category_name'];
				$ticket_notes	 = $boticket->read_additional_notes($origin_id);
				$i				 = count($ticket_notes) - 1;
				if (isset($ticket_notes[$i]['value_note']) && $ticket_notes[$i]['value_note'])
				{
					$values['descr'] .= ": " . $ticket_notes[$i]['value_note'];
				}
				$values['contact_id']		 = $ticket['contact_id'];
				$tts_status_create_project	 = isset($this->userSettings['preferences']['property']['tts_status_create_project']) ? $this->userSettings['preferences']['property']['tts_status_create_project'] : '';
				if ($tts_status_create_project)
				{
					$boticket->update_status(array('status' => $tts_status_create_project), $origin_id);
				}

				if (isset($this->userSettings['preferences']['property']['auto_create_project_from_ticket']) && $this->userSettings['preferences']['property']['auto_create_project_from_ticket'] == 'yes')
				{
					$auto_create = true;
				}
			}

			if ($p_entity_id && $p_cat_id)
			{
				$boadmin_entity = CreateObject('property.boadmin_entity');

				$entity_category						 = $boadmin_entity->read_single_category($p_entity_id, $p_cat_id);
				$values['p'][$p_entity_id]['p_cat_name'] = $entity_category['name'];
			}

			if ($location_code)
			{
				$values['location_data'] = $bolocation->read_single($location_code, array(
					'tenant_id'	 => $tenant_id,
					'p_num'		 => $p_num,
					'view'		 => true
				));
			}

			if (isset($origin) && $origin)
			{
				$interlink								 = CreateObject('property.interlink');
				$values['origin_data'][0]['location']	 = $origin;
				$values['origin_data'][0]['descr']		 = $interlink->get_location_name($origin);
				$values['origin_data'][0]['data'][]		 = array(
					'id'	 => $origin_id,
					'link'	 => $interlink->get_relation_link(array('location' => $origin, 'appname' => 'property'), $origin_id),
				);
			}
		}

		// end origin

		$location_id = $this->locations->get_id('property', $this->acl_location);

		$record_history = array();
		if ($this->bypass_error || ((!$this->receipt['error'] || $add_request) && !$bypass) && $id)
		{

			$values = $this->bo->read_single($id);
			if (!isset($values['origin']))
			{
				$values['origin'] = '';
			}

			if (!$this->bocommon->check_perms2($values['coordinator'], $this->bo->so->grants, ACL_EDIT))
			{
				$this->receipt['error'][] = array('msg' => lang('You have no edit right for this project'));
				Cache::session_set('property', 'receipt', $this->receipt);

				switch ($mode)
				{
					case 'edit':
						self::redirect(array('menuaction' => 'property.uiproject.view', 'id' => $id));
						break;
					default:
						self::redirect(array('menuaction' => 'property.uiproject.index'));
						break;
				}
			}
			else
			{
				$record_history = $this->bo->read_record_history($id);
			}
		}

		/* Preserve attribute values from post */
		if ($this->receipt['error'] && (isset($values_attribute) && is_array($values_attribute)))
		{
			$values = $this->bocommon->preserve_attribute_values($values, $values_attribute);
		}

		if ($id)
		{
			$function_msg = lang("{$mode} project");
		}
		else
		{
			$function_msg	 = lang('Add Project');
			$values			 = $this->bo->read_single(0, $values);
		}

		$tabs = array();
		if (isset($values['attributes']) && is_array($values['attributes']))
		{
			foreach ($values['attributes'] as &$attribute)
			{
				if ($attribute['history'] == true)
				{
					$link_history_data = array(
						'menuaction' => 'property.uiproject.attrib_history',
						'attrib_id'	 => $attribute['id'],
						'id'		 => $id,
						'edit'		 => true
					);

					$attribute['link_history'] = phpgw::link('/index.php', $link_history_data);
				}
			}
		}


		if (isset($values['cat_id']))
		{
			$this->cat_id = $values['cat_id'];
		}

		$lookup_type = $mode == 'edit' ? 'form' : 'view';

		//			_debug_array($values);
		$location_data = $bolocation->initiate_ui_location(
			array(
				'values'		 => (isset($values['location_data']) ? $values['location_data'] : ''),
				'type_id'		 => -1, // calculated from location_types
				'no_link'		 => false, // disable lookup links for location type less than type_id
				'tenant'		 => true,
				'lookup_type'	 => $lookup_type,
				'lookup_entity'	 => $this->bocommon->get_lookup_entity('project'),
				'entity_data'	 => (isset($values['p']) ? $values['p'] : ''),
				'required_level' => 1
			)
		);

		$b_account_data	 = array();
		$ecodimb_data	 = array();
		if (isset($this->config['budget_at_project']) && $this->config['budget_at_project'])
		{
			$b_account_group_data = $this->bocommon->initiate_ui_budget_account_lookup(
				array(
					'b_account_id'	 => $values['b_account_group'],
					'b_account_name' => $values['b_account_group_name'],
					'role'			 => 'group',
					'type'			 => $lookup_type
				)
			);


			$b_account_data = array();

			if (isset($this->config['budget_account_at_project']) && $this->config['budget_account_at_project'])
			{
				$b_account_data = $this->bocommon->initiate_ui_budget_account_lookup(
					array(
						'b_account_id'	 => $values['b_account_id'],
						'b_account_name' => $values['b_account_name'],
						'type'			 => $lookup_type
					)
				);
			}
		}

		$default_ecodimb = (isset($this->userSettings['preferences']['property']['dimb']) ? $this->userSettings['preferences']['property']['dimb'] : '');

		$ecodimb_data = $this->bocommon->initiate_ecodimb_lookup(array(
			'ecodimb'		 => $values['ecodimb'] ? $values['ecodimb'] : $default_ecodimb,
			'ecodimb_descr'	 => $values['ecodimb_descr'],
			'disabled'		 => $mode == 'view'
		));

		$contact_data = $this->bocommon->initiate_ui_contact_lookup(
			array(
				'contact_id'	 => $values['contact_id'],
				'contact_name'	 => $values['contact_name'],
				'field'			 => 'contact',
				'type'			 => $lookup_type
			)
		);


		if (!empty($values['contact_phone']))
		{
			for ($i = 0; $i < count($location_data['location']); $i++)
			{
				if ($location_data['location'][$i]['input_name'] == 'contact_phone')
				{
					$location_data['location'][$i]['value'] = $values['contact_phone'];
				}
			}
		}

		$project_form_action = phpgw::link('/property/project' . ($id ? '/' . (int)$id : ''));

		$link_request_data = array(
			'menuaction'	 => 'property.uirequest.index',
			'query'			 => (isset($values['location_data']['loc1']) ? $values['location_data']['loc1'] : ''),
			'make_relation'	 => !!$id,
			'relation_id'	 => isset($id) ? $id : 0,
			'relation_type'	 => 'project'
		);


		$project_status		 = (isset($this->userSettings['preferences']['property']['project_status']) ? $this->userSettings['preferences']['property']['project_status'] : '');
		$project_category	 = (isset($this->userSettings['preferences']['property']['project_category']) ? $this->userSettings['preferences']['property']['project_category'] : '');
		if (!isset($values['status']))
		{
			$values['status'] = $project_status;
		}

		if (!isset($values['cat_id']))
		{
			$values['cat_id'] = $project_category;
		}

		if (!isset($values['coordinator']))
		{
			$values['coordinator'] = $this->account;
		}

		if (!isset($values['start_date']) || !$values['start_date'])
		{
			$values['start_date'] = $this->phpgwapi_common->show_date(mktime(0, 0, 0, date("m"), date("d"), date("Y")), $this->userSettings['preferences']['common']['dateformat']);
		}

		$values['sum'] = isset($values['budget']) ? $values['budget'] : 0;

		if (isset($values['reserve']) && (int)$values['reserve'] != 0)
		{
			$reserve_remainder	 = (float)$values['reserve'] - (float)$values['deviation'];
			$remainder_percent	 = number_format((float)($reserve_remainder / (float)$values['reserve']) * 100, 2, $this->decimal_separator, '');
			$values['sum']		 = (float)$values['sum'] + (float)$values['reserve'];
		}

		$value_remainder = $values['sum'];

		$jqcal = CreateObject('phpgwapi.jqcal');
		$jqcal->add_listener('values_start_date');
		$jqcal->add_listener('values_end_date');

		$external_project_data = $this->bocommon->initiate_external_project_lookup(array(
			'external_project_id'	 => $values['external_project_id'],
			'external_project_name'	 => $values['external_project_name']
		));


		//---datatable settings---------------------------------------------------

		$sum_actual_cost = 0;
		$sum_oblications = 0;
		$maxmatchs = $this->userSettings['preferences']['common']['maxmatchs'];
		$rows_per_page	 = $maxmatchs ? $maxmatchs : 10;
		$initial_page	 = 1;

		$s_budget		 = 0;
		$s_orders		 = 0;
		$s_actual_cost	 = 0;
		$s_diff			 = 0;
		$s_deviation	 = 0;

		if ($id)
		{
			$content_budget	 = $this->bo->get_budget($id);
			$lang_delete	 = lang('Check to delete period');
			$lang_close		 = lang('Check to close period');
			$lang_active	 = lang('Check to activate period');
			$values['sum']	 = 0;

			if ($content_budget && $values['periodization_id'])
			{
				$_year_count = array();
				foreach ($content_budget as $key => $row)
				{
					$_year_count[$row['year']]	 += 1;
					$rows_per_page				 = max($_year_count[$row['year']], $maxmatchs);
				}
				$initial_page = floor(count($content_budget) / $rows_per_page);
			}

			foreach ($content_budget as &$b_entry)
			{
				if ($b_entry['active'])
				{
					$sum_actual_cost += $b_entry['actual_cost'];
					$sum_oblications += $b_entry['sum_oblications'];
					$values['sum']	 += $b_entry['budget'];

					$s_budget		 += $b_entry['budget'];
					$s_orders		 += $b_entry['sum_orders'];
					$s_actual_cost	 += $b_entry['actual_cost'];
					$s_diff			 += $b_entry['diff'];
					$s_deviation	 += $b_entry['deviation_period'];
				}

				$checked	 = $b_entry['closed'] ? 'checked="checked"' : '';
				$checked2	 = $b_entry['active'] ? 'checked="checked"' : '';

				$b_entry['delete_year']	 = "<input type='checkbox' name='values[delete_b_period][]' value='{$b_entry['year']}_{$b_entry['month']}' title='{$lang_delete}'>";
				$b_entry['closed']		 = "<input type='checkbox' name='values[closed_b_period][]' value='{$b_entry['year']}_{$b_entry['month']}' title='{$lang_close}' $checked>";
				$b_entry['closed_orig']	 = "<input type='checkbox' name='values[closed_orig_b_period][]' value='{$b_entry['year']}_{$b_entry['month']}' $checked style='display:none'>";
				$b_entry['active']		 = "<input type='checkbox' name='values[active_b_period][]' value='{$b_entry['year']}_{$b_entry['month']}' title='{$lang_active}' $checked2>";
				$b_entry['active_orig']	 = "<input type='checkbox' name='values[active_orig_b_period][]' value='{$b_entry['year']}_{$b_entry['month']}' $checked2 style='display:none'>";
			}
			unset($b_entry);
		}

		if (isset($values['reserve']) && (int)$values['reserve'] != 0)
		{
			$reserve_remainder	 = (float)$values['reserve'] - (float)$values['deviation'];
			$remainder_percent	 = number_format((float)($reserve_remainder / (float)$values['reserve']) * 100, 2, $this->decimal_separator, '.');
			$values['sum']		 = (float)$values['sum'] + (float)$values['reserve'];
		}

		$value_remainder = (float)$values['sum'] - (float)$sum_actual_cost - (float)$sum_oblications;
		$values['sum']	 = number_format((float)$values['sum'], 0, $this->decimal_separator, '.');
		$value_remainder = number_format((float)$value_remainder, 0, $this->decimal_separator, '.');

		$s_amount_in	 = 0;
		$s_amount_out	 = 0;

		if (isset($values['project_type_id']) && $values['project_type_id'] == 3)
		{
			$content_budget = $this->bo->get_buffer_budget($id);
			foreach ($content_budget as &$b_entry)
			{
				$b_entry['entry_date']	 = $this->phpgwapi_common->show_date($b_entry['entry_date'], $this->userSettings['preferences']['common']['dateformat']);
				$s_amount_in			 += $b_entry['amount_in'];
				$s_amount_out			 += $b_entry['amount_out'];
			}
			unset($b_entry);

			$budget_def = array(
				array('key' => 'year', 'label' => lang('year'), 'sortable' => true, 'value_footer' => lang('Sum')),
				array('key' => 'entry_date', 'label' => lang('entry date'), 'sortable' => false),
				array(
					'key'			 => 'amount_in',
					'label'			 => lang('amount in'),
					'sortable'		 => false,
					'className'		 => 'right',
					'formatter'		 => 'JqueryPortico.FormatterAmount0',
					'value_footer'	 => number_format((float)$s_amount_in, 0, $this->decimal_separator, '.')
				),
				array(
					'key'		 => 'from_project',
					'label'		 => lang('from project'),
					'sortable'	 => true,
					'className'	 => 'right',
					'formatter'	 => 'project_link'
				),
				array(
					'key'			 => 'amount_out',
					'label'			 => lang('amount out'),
					'sortable'		 => false,
					'className'		 => 'right',
					'formatter'		 => 'JqueryPortico.FormatterAmount0',
					'value_footer'	 => number_format((float)$s_amount_out, 0, $this->decimal_separator, '.')
				),
				array(
					'key'		 => 'to_project',
					'label'		 => lang('to project'),
					'sortable'	 => true,
					'formatter'	 => 'project_link'
				),
				array('key' => 'remark', 'label' => lang('remark'), 'sortable' => true)
			);
		}
		else
		{
			$budget_def = array(
				array('key' => 'year', 'label' => lang('year'), 'sortable' => true, 'value_footer' => lang('Sum')),
				array('key' => 'month', 'label' => lang('month'), 'sortable' => false),
				array(
					'key'			 => 'budget',
					'label'			 => lang('budget'),
					'sortable'		 => false,
					'className'		 => 'right',
					'formatter'		 => 'JqueryPortico.FormatterAmount0',
					'value_footer'	 => number_format((float)$s_budget, 0, $this->decimal_separator, '.')
				),
				array(
					'key'			 => 'sum_oblications',
					'label'			 => lang('sum orders'),
					'sortable'		 => false,
					'className'		 => 'right',
					'formatter'		 => 'JqueryPortico.FormatterAmount0',
					'value_footer'	 => number_format((float)$sum_oblications, 0, $this->decimal_separator, '.')
				),
				array(
					'key'			 => 'actual_cost',
					'label'			 => lang('actual cost'),
					'sortable'		 => false,
					'className'		 => 'right',
					'formatter'		 => 'JqueryPortico.FormatterAmount0',
					'value_footer'	 => number_format((float)$s_actual_cost, 0, $this->decimal_separator, '.')
				),
				array(
					'key'			 => 'diff',
					'label'			 => lang('difference'),
					'sortable'		 => false,
					'className'		 => 'right',
					'formatter'		 => 'JqueryPortico.FormatterAmount0',
					'value_footer'	 => number_format((float)$s_diff, 0, $this->decimal_separator, '.')
				),
				array(
					'key'			 => 'deviation_period',
					'label'			 => lang('deviation'),
					'sortable'		 => false,
					'className'		 => 'right',
					'formatter'		 => 'JqueryPortico.FormatterAmount0',
					'value_footer'	 => number_format((float)$s_deviation, 0, $this->decimal_separator, '.')
				),
				array(
					'key'		 => 'deviation_acc',
					'label'		 => lang('deviation') . '::' . lang('accumulated'),
					'sortable'	 => false,
					'className'	 => 'right',
					'formatter'	 => 'JqueryPortico.FormatterAmount0'
				),
				array(
					'key'		 => 'deviation_percent_period',
					'label'		 => lang('deviation') . '::' . lang('percent'),
					'sortable'	 => false,
					'className'	 => 'right',
					'formatter'	 => 'JqueryPortico.FormatterAmount2'
				),
				array(
					'key'		 => 'deviation_percent_acc',
					'label'		 => lang('percent') . '::' . lang('accumulated'),
					'sortable'	 => false,
					'className'	 => 'right',
					'formatter'	 => 'JqueryPortico.FormatterAmount2'
				),
				array(
					'key'		 => 'closed',
					'label'		 => lang('closed'),
					'sortable'	 => false,
					'className'	 => 'center',
					'formatter'	 => 'JqueryPortico.FormatterClosed'
				),
				array(
					'key'		 => 'active',
					'label'		 => lang('active'),
					'sortable'	 => false,
					'className'	 => 'center',
					'formatter'	 => 'JqueryPortico.FormatterActive'
				),
				array(
					'key'		 => 'delete_year',
					'label'		 => lang('Delete'),
					'sortable'	 => false,
					'className'	 => 'center'
				)
			);
		}

		$check_for_budget = (int)abs($s_budget) + (int)abs($s_amount_in);

		$datatable_def[] = array(
			'container'	 => 'datatable-container_0',
			'requestUrl' => "''",
			'data'		 => json_encode($content_budget),
			'ColumnDefs' => $budget_def,
			'config'	 => array(
				array('disableFilter' => true),
				array('rows_per_page' => $rows_per_page),
				//					array('disablePagination' => true),
				array('initial_page' => $initial_page)
			)
		);


		//			$order_data = array();
		$order_data = $this->bo->get_orders(
			array(
				'start'		 => 0,
				'project_id' => $id,
				//	'year' => date('Y'),
				'order'		 => 'year',
				'sort'		 => 'asc',
				'results'	 => -1,
				'query'		 => '',
			)
		);

		$first_year	 = false;
		$_order_data = array();

		$_order_budget		 = 0;
		$_order_cost		 = 0;
		$_order_obligation	 = 0;
		$_order_actual_cost	 = 0;
		$_order_diff		 = 0;

		foreach ($order_data as &$_order_entry)
		{
			$_order_budget		 += $_order_entry['budget'];
			$_order_cost		 += $_order_entry['cost'];
			$_order_obligation	 += $_order_entry['obligation'];
			$_order_actual_cost	 += $_order_entry['actual_cost'];
			$_order_diff		 += $_order_entry['diff'];

			if (!empty($_order_entry['year']) && !$first_year)
			{
				$first_year = $_order_entry['year'];
			}

			if ($first_year && $first_year != $_order_entry['year'])
			{
				//					continue;
			}

			$_order_entry['send_order'] = '';
			if (isset($_order_entry['mail_recipients'][0]) && $_order_entry['mail_recipients'][0] && !$_order_entry['order_sent'])
			{
				$_title						 = implode(';', $_order_entry['mail_recipients']);
				$_order_entry['send_order']	 = "<input type='checkbox' name='values[send_order][]' value='{$_order_entry['workorder_id']}' title='{$_title}'>";
			}
			else if ($_order_entry['order_sent'])
			{
				$_order_entry['send_order'] = $this->phpgwapi_common->show_date($_order_entry['order_sent'], $this->userSettings['preferences']['common']['dateformat']);
			}
			$_order_data[] = $_order_entry;
		}

		$orders_def = array(
			array(
				'key'			 => 'workorder_id',
				'label'			 => lang('Workorder'),
				'sortable'		 => true,
				'formatter'		 => 'formatLink',
				'value_footer'	 => lang('Sum')
			),
			array('key' => 'year', 'label' => lang('year'), 'sortable' => true),
			array('key' => 'title', 'label' => lang('title'), 'sortable' => true),
			array(
				'key'		 => 'b_account_id',
				'label'		 => lang('Budget account'),
				'sortable'	 => true,
				'className'	 => 'right'
			),
			array(
				'key'		 => 'budget',
				'label'		 => lang('budget'),
				'sortable'	 => true,
				'className'	 => 'right',
				'formatter'	 => 'JqueryPortico.FormatterAmount0',
				'value_footer'	 => number_format((float)$_order_budget, 0, $this->decimal_separator, '.')
			),
			array(
				'key'		 => 'cost',
				'label'		 => lang('cost'),
				'sortable'	 => true,
				'className'	 => 'right',
				'formatter'	 => 'JqueryPortico.FormatterAmount0',
				'value_footer'	 => number_format((float)$_order_cost, 0, $this->decimal_separator, '.')
			),
			array('key' => 'addition_percentage', 'label' => '%', 'sortable' => true, 'className' => 'right'),
			array(
				'key'		 => 'obligation',
				'label'		 => lang('sum orders'),
				'sortable'	 => true,
				'className'	 => 'right',
				'formatter'	 => 'JqueryPortico.FormatterAmount0',
				'value_footer'	 => number_format((float)$_order_obligation, 0, $this->decimal_separator, '.')
			),
			array(
				'key'		 => 'actual_cost',
				'label'		 => lang('actual cost'),
				'sortable'	 => true,
				'className'	 => 'right',
				'formatter'	 => 'JqueryPortico.FormatterAmount0',
				'value_footer'	 => number_format((float)$_order_actual_cost, 0, $this->decimal_separator, '.')
			),
			array(
				'key'		 => 'diff',
				'label'		 => lang('difference'),
				'sortable'	 => true,
				'className'	 => 'right',
				'formatter'	 => 'JqueryPortico.FormatterAmount0',
				'value_footer'	 => number_format((float)$_order_diff, 0, $this->decimal_separator, '.')
			),
			array('key' => 'vendor_name', 'label' => lang('Vendor'), 'sortable' => true),
			array('key' => 'status', 'label' => lang('Status'), 'sortable' => true),
			array(
				'key'		 => 'send_order',
				'label'		 => lang('send workorder'),
				'sortable'	 => false,
				'className'	 => 'center'
			)
		);

		$datatable_def[] = array(
			'container'	 => 'datatable-container_1',
			'requestUrl' => "''",
			'data'		 => json_encode($_order_data),
			'ColumnDefs' => $orders_def,
			'config'	 => array(
				//		array('disableFilter' => true),
				//					array('disablePagination' => false),
				//					array('allrows' => true),
				array('order' => json_encode(array(0, 'desc')))
			)
		);

		$_formatter_voucher_link = isset($this->config['invoicehandler']) && $this->config['invoicehandler'] == 2 ? 'formatLink_invoicehandler_2' : ''; //formatLink_voucher';

		$invoice_def = array(
			array(
				'key' => 'workorder_id',
				'label' => lang('Workorder'),
				'sortable' => true,
				'formatter' => 'formatLink',
				'value_footer' => lang('Sum')
			),
			array(
				'key' => 'voucher_id',
				'label' => lang('bilagsnr'),
				'sortable' => true,
				'formatter' => $_formatter_voucher_link
			),
			//				array('key' => 'voucher_out_id', 'hidden' => true),
			array('key' => 'invoice_id', 'label' => lang('invoice number'), 'sortable' => false),
			array('key' => 'vendor', 'label' => lang('vendor'), 'sortable' => false),
			array(
				'key' => 'amount_ex_tax',
				'label' => lang('ex tax'),
				'sortable' => true,
				'className' => 'right',
				'formatter' => 'JqueryPortico.FormatterAmount2'
			),
			array(
				'key' => 'amount_tax',
				'label' => lang('tax'),
				'sortable' => true,
				'className' => 'right',
				'formatter' => 'JqueryPortico.FormatterAmount2'
			),
			array(
				'key' => 'amount',
				'label' => lang('amount'),
				'sortable' => true,
				'className' => 'right',
				'formatter' => 'JqueryPortico.FormatterAmount2'
			),
			//				array('key' => 'approved_amount', 'label' => lang('approved amount'), 'sortable' => false,
			//					'className' => 'right', 'formatter' => 'JqueryPortico.FormatterAmount2'),
			array('key' => 'period', 'label' => lang('period'), 'sortable' => true),
			array('key' => 'periodization', 'label' => lang('periodization'), 'sortable' => false),
			array(
				'key' => 'periodization_start',
				'label' => lang('periodization start'),
				'sortable' => false
			),
			array('key' => 'currency', 'label' => lang('currency'), 'sortable' => false),
			array('key' => 'type', 'label' => lang('type'), 'sortable' => true),
			array('key' => 'budget_responsible', 'label' => lang('budget responsible'), 'sortable' => true),
			array('key' => 'budsjettsigndato', 'label' => lang('budsjettsigndato'), 'sortable' => true),
			array('key' => 'transfer_time', 'label' => lang('transfer time'), 'sortable' => true)
		);

		$datatable_def[] = array(
			'container'	 => 'datatable-container_2',
			'requestUrl' => json_encode(phpgw::link('/property/project/' . $id . '/vouchers', array())),
			'data'		 => json_encode(array()),
			'ColumnDefs' => $invoice_def,
			'tabletools' => array(),
			'config'	 => array(
				array('disableFilter' => false),
				//					array('disablePagination' => false),
				array('singleSelect' => true),
				array('allrows' => true),
				array('order' => json_encode(array(1, 'asc')))
			)

			//				'config'	 => array(
			//					array('disableFilter' => false),
			//					array('disablePagination' => false),
			//					array('singleSelect' => true)
			//				)
		);

		/*
			 * start new notify-table
			 * Sigurd: this one is for the new notify-table
			 */

		$notify_info = execMethod(
			'property.notify.get_jquery_table_def',
			array(
				'location_id'		 => $location_id,
				'location_item_id'	 => $id,
				'count'				 => count($datatable_def), //3
				'requestUrl'		 => json_encode(phpgw::link('/property/project/' . $id . '/notify-contacts', array(
					'location_id'		 => $location_id
				))),
			)
		);

		$datatable_def[] = array(
			'container'	 => 'datatable-container_3',
			'requestUrl' => json_encode(phpgw::link('/property/project/' . $id . '/notify-contacts', array(
				'location_id'		 => $location_id
			))),
			'ColumnDefs' => $notify_info['column_defs']['values'],
			'data'		 => json_encode(array()),
			'tabletools' => $mode == 'edit' ? $notify_info['tabletools'] : array(),
			'config'	 => array(
				array('disableFilter' => true),
				array('disablePagination' => true)
			)
		);

		/* end new notify-table */

		$history_def = array(
			array('key' => 'number', 'label' => '#', 'sortable' => true),
			array('key' => 'value_date', 'label' => lang('Date'), 'sortable' => false),
			array('key' => 'value_user', 'label' => lang('User'), 'sortable' => false),
			array('key' => 'value_action', 'label' => lang('action'), 'sortable' => false),
			array('key' => 'value_old_value', 'label' => lang('old value'), 'sortable' => false),
			array('key' => 'value_new_value', 'label' => lang('new value'), 'sortable' => false)
		);

		$datatable_def[] = array(
			'container'	 => 'datatable-container_4',
			'requestUrl' => "''",
			'ColumnDefs' => $history_def,
			'data'		 => json_encode($record_history),
			'config'	 => array(
				array('disableFilter' => true),
				array('disablePagination' => true)
			)
		);

		//--------------files
		$link_view_file = phpgw::link('/property/project/files/view');

		$_files = $this->bo->get_files($id);

		$bofiles = CreateObject('property.bofiles');
		$image_list		 = array();

		$sort_array = array();
		foreach ($_files as $_file)
		{
			if ($bofiles->is_image("{$bofiles->rootdir}{$_file['directory']}/{$_file['name']}"))
			{
				$sort_array[] = $_file['name'];
				$image_list[] = array(
					'image_url'		 => "{$link_view_file}&amp;file_id={$_file['file_id']}",
					'image_name'	 => $_file['name']
				);
			}
		}

		array_multisort($sort_array, SORT_ASC, $image_list);


		$files_def = array(
			array(
				'key'	=> 'file_name',
				'label'	=> lang('Filename'),
				'sortable'	 => false,
				'resizeable' => true
			),
			array(
				'key' => 'picture',
				'label'		 => lang('picture'),
				'sortable'	 => false,
				'resizeable' => true,
				'formatter'	 => 'JqueryPortico.showPicture'
			),
			array(
				'key' => 'tags',
				'label'	=> lang('tags'),
				'sortable' => false,
				'resizeable' => true,
				'formatter' => 'JqueryPortico.formatJsonArray'
			)
		);

		//---file tagging

		$requestUrl	 = json_encode(phpgw::link('/property/project/' . $id . '/files/actions', array()));
		$requestUrl = str_replace('&amp;', '&', $requestUrl);

		$buttons = array(
			array(
				'action' => 'filter_tag',
				'type'	 => 'buttons',
				'name'	 => 'filter_tag',
				'label'	 => lang('filter tag'),
				'funct'	 => 'onActionsClick_filter_files',
				'classname'	=> 'actionButton',
				'value_hidden'	 => ""
			),
			array(
				'action' => 'set_tag',
				'type'	 => 'buttons',
				'name'	 => 'set_tag',
				'label'	 => lang('set tag'),
				'funct'	 => 'onActionsClick_files',
				'classname'	=> '',
				'value_hidden'	 => ""
			),
			array(
				'action' => 'remove_tag',
				'type'	 => 'buttons',
				'name'	 => 'remove_tag',
				'label'	 => lang('remove tag'),
				'funct'	 => 'onActionsClick_files',
				'classname'	=> '',
				'value_hidden'	 => ""
			),
			array(
				'action' => 'delete_file',
				'type'	 => 'buttons',
				'name'	 => 'delete',
				'label'	 => lang('Delete file'),
				'funct'	 => 'onActionsClick_files',
				'classname'	 => '',
				'value_hidden'	 => "",
				'confirm_msg'		=> "Vil du slette fil(er)"
			),
		);

		$tabletools = array(
			array('my_name' => 'select_all'),
			array('my_name' => 'select_none')
		);

		foreach ($buttons as $entry)
		{
			$tabletools[] = array(
				'my_name'		 => $entry['name'],
				'text'			 => $entry['label'],
				'className'		 =>	$entry['classname'],
				'confirm_msg'	=>	$entry['confirm_msg'],
				'type'			 => 'custom',
				'custom_code'	 => "
						var api = oTable5.api();
						var selected = api.rows( { selected: true } ).data();
						var ids = [];
						for ( var n = 0; n < selected.length; ++n )
						{
							var aData = selected[n];
							ids.push(aData['file_id']);
						}
						{$entry['funct']}('{$entry['action']}', ids);
						"
			);
		}

		$code		 = <<<JS

	this.onActionsClick_filter_files=function(action, ids)
	{
		var tags = $('select#tags').val();
		var api = oTable5.api();
		var requestUrl = api.ajax.url();
		requestUrl = requestUrl.split('&tags[]=')[0];
		$.each(tags, function (k, v)
		{
			requestUrl += '&tags[]=' + v;
		});
		JqueryPortico.updateinlineTableHelper('datatable-container_5', requestUrl);
	}

	this.onActionsClick_files=function(action, ids)
	{
		var numSelected = 	ids.length;

		if (numSelected ==0)
		{
			alert('None selected');
			return false;
		}
		var tags = $('select#tags').val();

		$.ajax({
			type: 'POST',
			dataType: 'json',
			url: {$requestUrl},
			data:{ids:ids, tags:tags, action:action},
			success: function(data) {
				if (data != null && data.message)
				{
					if (!document.getElementById('message_files_5'))
					{
						$('#datatable-container_5').before('<div id="message_files_5" class="message"></div>');
					}
					$('#message_files_5').empty();
					$.each(data.message, function(k, v) {
						$('#message_files_5').append(v.msg + '<br>');
					});
					setTimeout(function() { $('#message_files_5').fadeOut(600, function() { $(this).empty().show(); }); }, 3000);
				}
				JqueryPortico.updateinlineTableHelper('datatable-container_5');

				if(action=='delete_file')
				{
					var strURL = phpGWLink('property/project/{$id}/files', {});
					refresh_glider(strURL);
				}
			},
			error: function(data) {
				alert('feil');
			}
		});
	}
JS;
		phpgwapi_js::getInstance()->add_code('', $code);

		//-- file tagging



		$datatable_def[] = array(
			'container'	 => 'datatable-container_5',
			'requestUrl' => json_encode(phpgw::link('/property/project/' . $id . '/files', array())),
			'data'		 => json_encode(array()),
			'ColumnDefs' => $files_def,
			'tabletools' => $tabletools,
			'config'	 => array(
				array('disableFilter' => true),
				array('disablePagination' => true),
				array('order' => json_encode(array(0, 'asc')))
			)
		);

		//--------------files
		$lang_from = lang('from');
		$lang_to = lang('to');

		$lang_delete_request_statustext	 = lang('Check to delete this request from this project');
		$_origin						 = array();
		if (isset($values['origin_data']) && $values['origin_data'])
		{
			foreach ($values['origin_data'] as $__origin)
			{
				foreach ($__origin['data'] as $_origin_data)
				{
					$_select = '';
					if ($__origin['location'] == '.project.request')
					{
						$_select = "<input type=\"checkbox\" name=\"values[delete_request][]\" value=\"{$_origin_data['id']}\" title=\"{$lang_delete_request_statustext}\">";
					}

					$_origin[] = array(
						'url'	 => "<a href='{$_origin_data['link']}'>{$lang_from}: {$_origin_data['id']} </a>",
						'type'	 => $__origin['descr'],
						'title'	 => $_origin_data['title'],
						'status' => $_origin_data['statustext'],
						'select' => $_select
					);
				}
			}
		}

		if (!empty($values['target']))
		{
			foreach ($values['target'] as $__target)
			{
				foreach ($__target['data'] as $_target_data)
				{
					$_origin[] = array(
						'url'	 => "<a href='{$_target_data['link']}'>{$lang_to}: {$_target_data['id']} </a>",
						'type'	 => $__target['descr'],
						'title'	 => $_target_data['title'],
						'status' => $_target_data['statustext'],
						'select' => $_select
					);
				}
			}
		}



		$origin_def = array(
			array('key' => 'url', 'label' => lang('id'), 'sortable' => true),
			array('key' => 'type', 'label' => lang('type'), 'sortable' => true),
			array('key' => 'title', 'label' => lang('title'), 'sortable' => false),
			array('key' => 'status', 'label' => lang('status'), 'sortable' => false),
			array(
				'key'		 => 'select',
				'label'		 => lang('select'),
				'className'	 => 'center',
				'sortable'	 => false
			)
		);

		$datatable_def[] = array(
			'container'	 => 'datatable-container_6',
			'requestUrl' => "''",
			'data'		 => json_encode($_origin),
			'ColumnDefs' => $origin_def,
			'config'	 => array(
				array('disableFilter' => true),
				array('disablePagination' => true)
			)
		);


		$other_projects_def	 = array(
			array('key' => 'url', 'label' => lang('id'), 'sortable' => false),
			array('key' => 'location_code', 'label' => lang('location'), 'sortable' => true),
			array('key' => 'name', 'label' => lang('name'), 'sortable' => true),
			array('key' => 'start_date', 'label' => lang('start date'), 'sortable' => true),
			array('key' => 'coordinator', 'label' => lang('coordinator'), 'sortable' => false),
			array('key' => 'status', 'label' => lang('status'), 'sortable' => true),
		);

		$datatable_def[] = array(
			'container'	 => 'datatable-container_7',
			'requestUrl' => json_encode(phpgw::link('/property/project/' . $id . '/other-projects', array(
				'location_code' => $values['location_data']['location_code']
			))),
			'data'		 => json_encode(array()),
			'ColumnDefs' => $other_projects_def,
			'config'	 => array(
				array('order' => json_encode(array(array(1, 'desc'), array(3, 'desc'))))
			)
		);

		$attachmen_def = array(
			array(
				'key'	 => 'voucher_id',
				'label'	 => 'key',
				'hidden' => false
			),
			array(
				'key'		 => 'file_name',
				'label'		 => lang('attachments'),
				'hidden'	 => false,
				'sortable'	 => true,
			)
		);

		$datatable_def[] = array(
			'container'	 => 'datatable-container_8',
			'requestUrl' => json_encode(phpgw::link('/property/project/attachments', array())),
			'data'		 => json_encode(array()),
			'ColumnDefs' => $attachmen_def,
			'config'	 => array(
				array('disableFilter' => true),
				array('disablePagination' => true)
			)
		);

		//----------------------------------------------datatable settings--------

		$suppresscoordination = isset($this->config['project_suppresscoordination']) && $this->config['project_suppresscoordination'] ? 1 : '';

		$year	 = date('Y') - 1;
		$limit	 = $year + 8;

		while ($year < $limit)
		{
			$year_list[] = array(
				'id'	 => $year,
				'name'	 => $year
			);
			$year++;
		}

		$periodization_list = $this->bo->get_periodizations_with_outline();

		$sub_entry_action_data	 = array();
		$sub_entry_action_data	 = array(
			'menuaction' => 'property.uiworkorder.edit',
			'project_id' => $id
		);

		if ($id && !$values['project_type_id'] == 3)
		{
			$sub_entry_action_data = array(
				'menuaction' => 'property.uiworkorder.edit',
				'project_id' => $id
			);
		}
		else if ($id && $values['project_type_id'] == 3)
		{
			$sub_entry_action_data = array(
				'menuaction' => 'property.uiproject.edit',
				'bypass'	 => 1,
				'parent_id'	 => $id,
				'origin'	 => '.project',
				'origin_id'	 => $id
			);
		}
		$msgbox_data = $this->bocommon->msgbox_data($this->receipt);

		if (!empty($values['location_data']['location_code']))
		{
			$location_exceptions = $bolocation->get_location_exception($values['location_data']['location_code']);

			foreach ($location_exceptions as $location_exception)
			{
				$message = $location_exception['severity'];
				if ($location_exception['category'])
				{
					$message .= "/{$location_exception['category']}";
				}
				if ($location_exception['category_text'])
				{
					$message .= ": {$location_exception['category_text']}";
				}
				if ($location_exception['location_descr'])
				{
					$message .= "<br/> {$location_exception['location_descr']}";
				}
				Cache::message_set($message, $location_exception['alert_vendor'] == 1 ? 'error' : 'message');
			}
		}

		$project_type_id = isset($values['project_type_id']) && $values['project_type_id'] ? $values['project_type_id'] : $this->userSettings['preferences']['property']['default_project_type'];
		$active_tab		 = Sanitizer::get_var('active_tab', 'string', 'REQUEST', 'location');

		$delivery_address = $values['delivery_address'];

		if (!$delivery_address && !empty($values['location_data']['loc1']))
		{
			$delivery_address = CreateObject('property.solocation')->get_delivery_address($values['location_data']['loc1']);
		}

		$ticket_link_data = array(
			'menuaction'	 => 'property.uitts.add',
			'bypass'		 => true,
			'location_code'	 => $values['location_code'],
			'origin'		 => '.project',
			'origin_id'		 => $id
		);
		$data = array(
			'datatable_def'						 => $datatable_def,
			'project_types'						 => array('options' => $this->bo->get_project_types($project_type_id)),
			'project_type_id'					 => $values['project_type_id'],
			'ticket_link'						 => phpgw::link('/index.php', $ticket_link_data),
			'inherit_location'					 => $id ? $values['inherit_location'] : 1,
			'mode'								 => $mode,
			'suppressmeter'						 => isset($this->config['project_suppressmeter']) && $this->config['project_suppressmeter'] ? 1 : '',
			'suppresscoordination'				 => $suppresscoordination,
			'custom_attributes'					 => array('attributes' => $values['attributes']),
			'lookup_functions'					 => isset($values['lookup_functions']) ? $values['lookup_functions'] : '',
			'b_account_group_data'				 => $b_account_group_data,
			'b_account_data'					 => $b_account_data,
			'ecodimb_data'						 => $ecodimb_data,
			'contact_data'						 => $contact_data,
			'tabs'								 => self::_generate_tabs($tabs, $active_tab, $_disable							 = array(
				'general'		 => !$id && empty($this->receipt['error']) ? true : false,
				'budget'		 => !$id && empty($this->receipt['error']) ? true : false,
				'coordination'	 => $id ? false : true,
				'documents'		 => $id ? false : true,
				'history'		 => $id ? false : true
			)),
			'value_active_tab'					 => $active_tab,
			'msgbox_data'						 => $this->phpgwapi_common->msgbox($msgbox_data),
			'value_target'						 => $values['target'],
			'value_origin'						 => isset($values['origin_data']) ? $values['origin_data'] : '',
			'value_origin_type'					 => isset($origin) ? $origin : '',
			'value_origin_id'					 => isset($origin_id) ? $origin_id : '',
			'year_list'							 => array('options' => $year_list),
			'order_time_span'					 => array('options' => $this->bo->get_order_time_span($id, $first_year)),
			'periodization_list'				 => array('options' => $periodization_list),
			'lang_select_request_statustext'	 => lang('Add request for this project'),
			'lang_request_statustext'			 => lang('Link to the request for this project'),
			'link_select_request'				 => phpgw::link('/index.php', $link_request_data),
			'add_sub_entry_action'				 => phpgw::link('/index.php', $sub_entry_action_data),
			'lang_add_sub_entry'				 => $values['project_type_id'] == 3 ? lang('add project') : lang('Add workorder'),
			'lang_add_sub_entry_statustext'		 => $values['project_type_id'] == 3 ? lang('add a project to this buffer') : lang('Add a workorder to this project'),
			'lang_no_workorders'				 => lang('No workorder budget'),
			'workorder_link'					 => phpgw::link('/index.php', array('menuaction' => 'property.uiworkorder.edit')),
			'record_history'					 => $record_history,
			'lang_history'						 => lang('History'),
			'lang_no_history'					 => lang('No history'),
			'lang_start_date_statustext'		 => lang('Select the estimated end date for the Project'),
			'lang_start_date'					 => lang('Project start date'),
			'value_start_date'					 => $values['start_date'],
			'lang_end_date_statustext'			 => lang('Select the estimated end date for the Project'),
			'lang_end_date'						 => lang('Project end date'),
			'value_end_date'					 => isset($values['end_date']) ? $values['end_date'] : '',
			'lang_copy_project'					 => lang('Copy project ?'),
			'lang_copy_project_statustext'		 => lang('Choose Copy Project to copy this project to a new project'),
			'lang_charge_tenant'				 => lang('Charge tenant'),
			'lang_charge_tenant_statustext'		 => lang('Choose charge tenant if the tenant i to pay for this project'),
			'charge_tenant'						 => isset($values['charge_tenant']) ? $values['charge_tenant'] : '',
			'lang_power_meter'					 => lang('Power meter'),
			'lang_power_meter_statustext'		 => lang('Enter the power meter'),
			'value_power_meter'					 => isset($values['power_meter']) ? $values['power_meter'] : '',
			'value_budget'						 => isset($values['budget']) && $this->receipt['error'] ? $values['budget'] : '',
			'check_for_budget'					 => $check_for_budget,
			'lang_reserve'						 => lang('reserve'),
			'value_reserve'						 => isset($values['reserve']) ? $values['reserve'] : '',
			'lang_reserve_statustext'			 => lang('Enter the reserve'),
			'value_sum'							 => isset($values['sum']) ? $values['sum'] : '',
			'lang_reserve_remainder'			 => lang('reserve remainder'),
			'value_reserve_remainder'			 => isset($reserve_remainder) ? $reserve_remainder : '',
			'value_reserve_remainder_percent'	 => isset($remainder_percent) ? $remainder_percent : '',
			//					'lang_planned_cost'					=> lang('planned cost'),
			//					'value_planned_cost'				=> $values['planned_cost'],
			'location_data'						 => $location_data,
			'location_type'						 => 'form',
			'form_action'						 => $project_form_action,
			'done_action'						 => phpgw::link('/index.php', array('menuaction' => 'property.uiproject.index')),
			'lang_year'							 => lang('Year'),
			'lang_category'						 => lang('category'),
			'lang_save'							 => lang('save'),
			'lang_done'							 => lang('done'),
			'lang_name'							 => lang('Name'),
			'lang_project_id'					 => lang('Project ID'),
			'value_project_id'					 => isset($id) ? $id : '',
			//				'external_project_data' => $external_project_data,
			'value_external_project_id'			 => $values['external_project_id'],
			'value_external_project_name'		 => $this->_get_external_project_name($values['external_project_id']),
			'value_name'						 => isset($values['name']) ? $values['name'] : '',
			'lang_name_statustext'				 => lang('Enter Project Name'),
			'lang_other_branch'					 => lang('Other branch'),
			'lang_other_branch_statustext'		 => lang('Enter other branch if not found in the list'),
			'value_other_branch'				 => isset($values['other_branch']) ? $values['other_branch'] : '',
			'lang_descr_statustext'				 => lang('Enter a description of the project'),
			'lang_descr'						 => lang('Description'),
			'value_descr'						 => isset($values['descr']) ? $values['descr'] : '',
			'lang_remark_statustext'			 => lang('Enter a remark to add to the history of the project'),
			'lang_remark'						 => lang('remark'),
			'value_remark'						 => isset($values['remark']) ? $values['remark'] : '',
			'value_delivery_address'			 => $delivery_address,
			'lang_done_statustext'				 => lang('Back to the list'),
			'lang_save_statustext'				 => lang('Save the project'),
			'lang_no_cat'						 => lang('Select category'),
			'value_cat_id'						 => isset($values['cat_id']) ? $values['cat_id'] : '',
			'cat_select'						 => $this->cats->formatted_xslt_list(array(
				'select_name'	 => 'values[cat_id]',
				'selected'		 => $values['cat_id'],
				'data_validation' =>  'category',
				'class'			 => 'pure-input-1-2'
			)),
			'lang_workorder_id'					 => lang('Workorder ID'),
			'lang_sum'							 => lang('Sum'),
			'value_remainder'					 => $value_remainder,
			'lang_remainder'					 => lang('remainder'),
			'lang_coordinator'					 => lang('Coordinator'),
			'lang_user_statustext'				 => lang('Select the coordinator the project belongs to. To do not use a category select NO USER'),
			'select_user_name'					 => 'values[coordinator]',
			'lang_no_user'						 => lang('Select coordinator'),
			'user_list'							 => $this->bocommon->get_user_list_right2('select', 4, $values['coordinator'], $this->acl_location),
			'status_list'						 => $this->bo->select_status_list('select', $values['status']),
			'status_name'						 => 'values[status]',
			'status_required'					 => true,
			'lang_no_status'					 => lang('Select status'),
			'lang_status'						 => lang('Status'),
			'lang_status_statustext'			 => lang('What is the current status of this project ?'),
			'lang_confirm_status'				 => lang('Confirm status'),
			'lang_confirm_statustext'			 => lang('Confirm status to the history'),
			'branch_list'						 => $this->bo->select_branch_p_list((isset($id) ? $id : '')),
			'lang_branch'						 => lang('branch'),
			'lang_branch_statustext'			 => lang('Select the branches for this project'),
			'key_responsible_list'				 => $this->bo->select_branch_list((isset($values['key_responsible']) ? $values['key_responsible'] : '')),
			'lang_no_key_responsible'			 => lang('Select key responsible'),
			'lang_key_responsible'				 => lang('key responsible'),
			'lang_key_responsible_statustext'	 => lang('Select the key responsible for this project'),
			'key_fetch_list'					 => $this->bo->select_key_location_list((isset($values['key_fetch']) ? $values['key_fetch'] : '')),
			'lang_no_key_fetch'					 => lang('Where to fetch the key'),
			'lang_key_fetch'					 => lang('key fetch location'),
			'lang_key_fetch_statustext'			 => lang('Select where to fetch the key'),
			'key_deliver_list'					 => $this->bo->select_key_location_list((isset($values['key_deliver']) ? $values['key_deliver'] : '')),
			'lang_no_key_deliver'				 => lang('Where to deliver the key'),
			'lang_key_deliver'					 => lang('key deliver location'),
			'lang_key_deliver_statustext'		 => lang('Select where to deliver the key'),
			'lang_ask_approval'					 => lang('Ask for approval'),
			'lang_ask_approval_statustext'		 => lang('Check this to send a mail to your supervisor for approval'),
			'currency'							 => $this->userSettings['preferences']['common']['currency'],
			'base_java_url'						 => "{menuaction:'property.bocommon.get_vendor_email',phpgw_return_as:'json'}",
			'location_item_id'					 => $id,
			'edit_action'						 => phpgw::link('/property/uiproject/edit', array(
				'id'		 => $id
			)),
			'lang_edit_statustext'				 => lang('Edit this entry '),
			'lang_edit'							 => lang('Edit'),
			'decimal_separator'					 => $this->decimal_separator,
			'validator'							 => phpgwapi_jquery::formvalidator_generate(array(
				'location',
				'date',
				'security',
				'file'
			)),
			'multiple_uploader'					 => true,
			'multi_upload_action' => phpgw::link('/property/project/' . $id . '/multi-upload'),
			'street_name'						 => $values['location_data']['street_name'],
			'street_number'						 => $values['location_data']['street_number'],
			'image_list'						 => $image_list,
			'tag_list'							 => array('options' => $bofiles->get_all_tags()),
			'collect_tax_code'						 => !empty($this->config['workorder_require_tax_code']),
			'tax_code_list'							 => array('options' => $this->bocommon->select_category_list(
				array(
					'type' => 'tax',
					'selected'	 => (!empty($values['tax_code']) || $values['tax_code'] === 0) ? $values['tax_code'] : (int)$this->userSettings['preferences']['property']['default_tax_code'],
					'order'		 => 'id',
					'id_in_name' => 'num'
				)
			)),

		);
		if ($auto_create)
		{
			$location = explode('-', $values['location_data']['location_code']);

			$level = count($location);
			for ($i = 1; $i < $level + 1; $i++)
			{
				$values['location']["loc$i"] = $location[($i - 1)];
			}

			$values['location_name'] = $values['location_data']["loc{$level}_name"];
			$values['extra']		 = $values['p'][0];

			unset($values['location_data']);


			unset($values['p']);

			$receipt = $this->bo->save($values, 'add', array());

			if (!$receipt['error'])
			{
				$id = $receipt['id'];
				phpgw::redirect_link('/index.php', array(
					'menuaction' => 'property.uiworkorder.edit',
					'project_id' => $id
				));
			}
		}

		$appname										 = lang('project');
		$this->flags['app_header']	 = lang('property') . ' - ' . $appname . ': ' . $function_msg;
		Settings::getInstance()->update('flags', ['app_header' => $this->flags['app_header']]);


		phpgwapi_jquery::load_widget('core');
		phpgwapi_jquery::load_widget('select2');
		phpgwapi_jquery::load_widget('glider');
		phpgwapi_jquery::load_widget('numberformat');
		phpgwapi_jquery::load_widget('autocomplete');
		phpgwapi_jquery::load_widget('file-upload-minimum');

		self::add_javascript('property', 'base', 'navigation-api-boundary.js');
		self::add_javascript('property', 'base', 'project.edit.js');
		self::render_template_xsl(array('project', 'datatable_inline', 'multi_upload_file_inline', 'attributes_form'), array(
			'edit' => $data
		));
	}

	function bulk_update_status()
	{
		if (!$this->acl_edit)
		{
			phpgw::redirect_link('/index.php', array(
				'menuaction'	 => 'property.uilocation.stop',
				'perm'			 => ACL_EDIT,
				'acl_location'	 => $this->acl_location
			));
		}

		$this->flags['menu_selection'] .= '::project_bulk_update_status';
		Settings::getInstance()->update('flags', ['menu_selection' => $this->flags['menu_selection']]);


		$start_date		 = Sanitizer::get_var('start_date');
		$end_date		 = Sanitizer::get_var('end_date');
		$get_list		 = Sanitizer::get_var('get_list', 'bool', 'POST');
		$execute		 = Sanitizer::get_var('execute', 'bool', 'POST');
		$status_filter	 = Sanitizer::get_var('status_filter');
		$status_new		 = Sanitizer::get_var('status_new');
		$type			 = Sanitizer::get_var('type', 'string', 'REQUEST', 'project');
		$ecodimb		 = Sanitizer::get_var('ecodimb');
		$ids			 = Sanitizer::get_var('ids');
		$paid			 = Sanitizer::get_var('paid', 'bool', 'POST');
		$closed_orders	 = Sanitizer::get_var('closed_orders', 'bool', 'POST');
		$transfer_budget = Sanitizer::get_var('transfer_budget', 'integer');
		$b_account_id	 = Sanitizer::get_var('b_account_id', 'integer');
		$b_account_name	 = Sanitizer::get_var('b_account_name');

		if (isset($_POST['coordinator']))
		{
			$coordinator = Sanitizer::get_var('coordinator', 'int');
		}
		else
		{
			$coordinator = $this->account;
		}

		$new_coordinator = Sanitizer::get_var('new_coordinator', 'int');

		$ids = $ids ? $ids : array();

		$new_budget = array();

		foreach ($ids as $id)
		{
			$new_budget[$id]['budget_amount']	 = Sanitizer::get_var("{$id}::budget_amount");
			$new_budget[$id]['obligation']		 = Sanitizer::get_var("{$id}::obligation");
			$new_budget[$id]['order_amount']	 = Sanitizer::get_var("{$id}::order_amount");
			$new_budget[$id]['latest_year']		 = Sanitizer::get_var("{$id}::latest_year");
		}

		$link_data = array(
			'menuaction' => 'property.uiproject.index'
		);

		$jqcal = CreateObject('phpgwapi.jqcal');
		$jqcal->add_listener('values_start_date');
		$jqcal->add_listener('values_end_date');

		$list = array();
		if (($execute || $get_list) && $type)
		{
			$list = $this->bo->bulk_update_status($start_date, $end_date, $status_filter, $status_new, $execute, $type, $coordinator, $new_coordinator, $ids, $paid, $closed_orders, $ecodimb, $transfer_budget, $new_budget, $b_account_id);
		}

		foreach ($list as &$entry)
		{
			$_obligation		 = '';
			$entry['new_budget'] = '';

			if ($entry['project_type_id'] == 1 || $entry['project_type_id'] == 4 || $entry['continuous']) // operation or continuous
			{
				$_obligation = 0;
				$_order		 = 0;

				if (!$entry['closed'] && $type == 'project')
				{
					$_budget_arr = $this->bo->get_budget($entry['id']);
				}

				if (!$entry['closed'] && $type == 'workorder')
				{
					$_budget_arr = execMethod('property.soworkorder.get_budget', $entry['id']);
				}

				if ($_budget_arr)
				{
					foreach ($_budget_arr as $_budget_entry)
					{
						if ($_budget_entry['active'])
						{
							$_obligation += $_budget_entry['sum_oblications'];
							$_order		 += $_budget_entry['sum_orders'];
						}
					}

					$_obligation = round($_obligation);

					$entry['new_budget'] = "<input type='text'     name='{$entry['id']}::budget_amount' value='{$_obligation}' title=''></input>";
					$entry['new_budget'] .= "<input type='hidden'  name='{$entry['id']}::obligation' value='{$_obligation}' ></input>";
					$entry['new_budget'] .= "<input type='hidden'  name='{$entry['id']}::order_amount' value='{$_order}'></input>";
					$entry['new_budget'] .= "<input type='hidden'  name='{$entry['id']}::latest_year' value='{$entry['latest_year']}'></input>";
				}
			}
			else if ($entry['project_type_id'] == 2)
			{
				$entry['new_budget'] = 'auto';
				$entry['new_budget'] .= "<input type='hidden' class='myValuesForPHP' id='{$entry['id']}::latest_year' name='{$entry['id']}::latest_year' value='{$entry['latest_year']}'></input>";
			}
			else if ($entry['project_type_id'] == 3)
			{
				$entry['budget'] = '';
			}

			$entry['obligation'] = $_obligation;

			$entry['link'] = phpgw::link('/index.php', array(
				'menuaction' => "property.ui{$type}.edit",
				'id'		 => $entry['id']
			));
		}

		$total_records = count($list);

		switch ($type)
		{
			case 'project':
				$lang_coordinator		 = lang('coordinator');
				$lang_new_coordinator	 = lang('new coordinator');
				$myColumnDefs			 = array(
					array('key' => 'id', 'label' => lang('id'), 'sortable' => true, 'formatter' => 'JqueryPortico.formatLink'),
					array('key' => 'coordinator_name', 'label' => lang('coordinator'), 'sortable' => false),
					array('key' => 'start_date', 'label' => lang('date'), 'sortable' => false),
					array('key' => 'title', 'label' => lang('title'), 'sortable' => true),
					array('key' => 'status', 'label' => lang('status'), 'sortable' => true),
					array(
						'key'		 => 'num_open',
						'label'		 => lang('open'),
						'sortable'	 => true,
						'className'	 => 'right',
						'formatter'	 => 'JqueryPortico.FormatterAmount0'
					),
					array('key' => 'project_type', 'label' => lang('project type'), 'sortable' => true),
					array('key' => 'budget', 'label' => lang('budget'), 'sortable' => false),
					array(
						'key'		 => 'obligation',
						'label'		 => lang('obligation'),
						'sortable'	 => true,
						'className'	 => 'right',
						'formatter'	 => 'JqueryPortico.FormatterAmount0'
					),
					array('key' => 'new_budget', 'label' => lang('new'), 'sortable' => false),
					array(
						'key'		 => 'select',
						'label'		 => lang('select'),
						'sortable'	 => false,
						'className'	 => 'center',
						'formatter'	 => 'myFormatterCheck'
					)
				);
				$b_account_data			 = array();
				$td_count				 = 9;
				break;

			case 'workorder':
				$lang_coordinator		 = lang('janitor');
				$lang_new_coordinator	 = lang('new janitor');
				$lang_actual_cost		 = $paid ? lang('actual cost') . ' ' . lang('total') : lang('actual cost') . ' ' . (date('Y') - 1);

				$myColumnDefs = array(
					array('key' => 'project_id', 'label' => lang('project'), 'sortable' => true),
					array('key' => 'id', 'label' => lang('id'), 'sortable' => true, 'formatter' => 'JqueryPortico.formatLink'),
					array('key' => 'coordinator_name', 'label' => lang('janitor'), 'sortable' => false),
					array('key' => 'start_date', 'label' => lang('date'), 'sortable' => false),
					array('key' => 'title', 'label' => lang('title'), 'sortable' => true),
					array('key' => 'status', 'label' => lang('status'), 'sortable' => true),
					array('key' => 'project_type', 'label' => lang('project type'), 'sortable' => true),
					array('key' => 'b_account_id', 'label' => lang('budget account'), 'sortable' => true),
					array('key' => 'budget', 'label' => lang('budget'), 'sortable' => false),
					array(
						'key'		 => 'obligation',
						'label'		 => lang('obligation'),
						'sortable'	 => true,
						'className'	 => 'right',
						'formatter'	 => 'JqueryPortico.FormatterAmount0'
					),
					array('key' => 'continuous', 'label' => lang('continuous'), 'sortable' => true),
					array('key' => 'new_budget', 'label' => lang('new'), 'sortable' => false),
					array(
						'key'		 => 'actual_cost',
						'label'		 => $lang_actual_cost,
						'sortable'	 => true,
						'className'	 => 'right',
						'formatter'	 => 'JqueryPortico.FormatterAmount0'
					),
					array(
						'key'		 => 'select',
						'label'		 => lang('select'),
						'sortable'	 => false,
						'className'	 => 'center',
						'formatter'	 => 'myFormatterCheck'
					)
				);

				$b_account_data	 = $this->bocommon->initiate_ui_budget_account_lookup(
					array(
						'b_account_id'	 => $b_account_id,
						'disabled'		 => '',
						'parent'		 => $b_account_id,
						'type'			 => 'form'
					)
				);
				$td_count		 = 12;
				break;
		}

		$tabletools = array(
			array('my_name' => 'select_all'),
			array('my_name' => 'select_none')
		);

		$datatable_def[] = array(
			'container'	 => 'datatable-container_0',
			'requestUrl' => "''",
			'data'		 => json_encode($list),
			'tabletools' => $tabletools,
			'ColumnDefs' => $myColumnDefs,
			'config'	 => array(
				array('disableFilter' => true),
				array('disablePagination' => true)
			)
		);

		$user_list = $this->bocommon->get_user_list_right2('select', ACL_EDIT, $coordinator, $this->acl_location);

		switch ($type)
		{
			case 'project':
				$status_list_filter	 = execMethod('property.bogeneric.get_list', array('type' => 'project_status'));
				$status_list_new	 = execMethod('property.bogeneric.get_list', array(
					'type'		 => 'project_status',
					'selected'	 => $status_new
				));
				break;
			case 'workorder':
				$status_list_filter	 = execMethod('property.bogeneric.get_list', array('type' => 'workorder_status'));
				$status_list_new	 = execMethod('property.bogeneric.get_list', array(
					'type'		 => 'workorder_status',
					'selected'	 => $status_new
				));
				break;
			default:
				$status_list_filter	 = array();
		}

		if ($status_list_filter)
		{
			array_unshift($status_list_filter, array('id' => 'open', 'name' => lang('open')));
		}

		$status_list_filter = $this->bocommon->select_list($status_filter, $status_list_filter);

		$type_array = array(
			array(
				'id'	 => '0',
				'name'	 => lang('select')
			),
			array(
				'id'	 => 'workorder',
				'name'	 => lang('workorder')
			),
			array(
				'id'	 => 'project',
				'name'	 => lang('project')
			)
		);

		foreach ($type_array as &$entry)
		{
			$entry['selected'] = $entry['id'] == $type ? 1 : 0;
		}

		$year	 = date('Y') - 2;
		$limit	 = $year + 4;

		while ($year < $limit)
		{
			$year_list[] = array(
				'id'	 => $year,
				'name'	 => $year
			);
			$year++;
		}

		$tabs			 = array();
		$tabs['generic'] = array('label' => lang('generic'), 'link' => '#generic');
		$active_tab		 = 'generic';

		$data = array(
			'datatable_def'			 => $datatable_def,
			'year_list'				 => array('options' => $year_list),
			'done_action'			 => phpgw::link('/index.php', $link_data),
			'update_action'			 => phpgw::link('/index.php', array('menuaction' => 'property.uiproject.bulk_update_status')),
			'status_list_filter'	 => array('options' => $status_list_filter),
			'status_list_new'		 => array('options' => $status_list_new),
			'type_list'				 => array('options' => $type_array),
			'user_list'				 => array('options' => $user_list),
			'ecodimb_list'			 => array('options' => $this->bocommon->select_category_list(array(
				'type'		 => 'dimb',
				'selected'	 => $ecodimb
			))),
			'start_date'			 => $start_date,
			'end_date'				 => $end_date,
			'total_records'			 => $total_records,
			'paid'					 => $paid,
			'closed_orders'			 => $closed_orders,
			'check_paid'			 => $type == 'workorder' ? 1 : 0,
			'check_closed_orders'	 => $type == 'project' ? 1 : 0,
			'type'					 => $type,
			'b_account_data'		 => $b_account_data,
			'tabs'					 => phpgwapi_jquery::tabview_generate($tabs, $active_tab),
			'lang_coordinator'		 => $lang_coordinator,
			'lang_new_coordinator'	 => $lang_new_coordinator,
		);

		$appname		 = lang('project');
		$function_msg	 = lang('bulk update status');

		$this->flags['app_header'] = lang('property') . ' - ' . $appname . ': ' . $function_msg;
		Settings::getInstance()->update('flags', ['app_header' => $this->flags['app_header']]);


		phpgwapi_jquery::load_widget('numberformat');

		self::add_javascript('property', 'base', 'project.bulk_update_status.js');
		self::render_template_xsl(array('project', 'datatable_inline'), array('bulk_update_status' => $data));
	}

	function view()
	{
		if (!$this->acl_read)
		{
			$this->bocommon->no_access();
			return;
		}
		$this->edit(array(), $mode = 'view');
	}

	private function _get_external_project_name($id)
	{
		return $this->bocommon->get_external_project_name($id);
	}

	protected function _generate_tabs($tabs_ = array(), $active_tab = 'location', $_disable = array())
	{
		$tabs = array(
			'location'		 => array('label' => lang('location'), 'link' => '#location'), //, 'function' => "set_tab('location')"),
			'general'		 => array('label' => lang('general'), 'link' => '#general'), //, 'function' => "set_tab('general')"),
			'budget'		 => array('label' => lang('Time and budget'), 'link' => '#budget'), //, 'function' => "set_tab('budget')"),
			'coordination'	 => array('label' => lang('coordination'), 'link' => '#coordination'), //,'function' => "set_tab('coordination')"),
			'documents'		 => array('label' => lang('documents'), 'link' => '#documents'), //, 'function' => "set_tab('documents')"),
			'history'		 => array('label' => lang('history'), 'link' => '#history'), //, 'function' => "set_tab('history')"),
		);

		$tabs = array_merge($tabs, $tabs_);
		foreach ($_disable as $tab => $disable)
		{
			if ($disable)
			{
				$tabs[$tab]['disable'] = true;
			}
		}

		return phpgwapi_jquery::tabview_generate($tabs, $active_tab);
	}
}
