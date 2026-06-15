<?php

/**
 * phpGroupWare - property: a Facilities Management System.
 *
 * @author Sigurd Nes <sigurdne@online.no>
 * @copyright Copyright (C) 2003,2004,2005,2006,2007 Free Software Foundation, Inc. http://www.fsf.org/
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
/**
 * Description
 * @package property
 */

use App\modules\phpgwapi\services\Cache;
use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\controllers\Accounts\Accounts;
use App\Database\Db;

phpgw::import_class('phpgwapi.uicommon_jquery');
phpgw::import_class('phpgwapi.jquery');

class property_uiworkorder extends phpgwapi_uicommon_jquery
{

	private $receipt		 = array();
	var $grants, $acl, $bo, $bocommon, $acl_read, $acl_add, $acl_edit, $acl_delete, $acl_manage, $cats;
	var $status_id,	$wo_hour_cat_id, $start_date, $end_date,
		$b_group,
		$ecodimb,
		$paid,
		$b_account,
		$district_id,
		$obligation,
		$decimal_separator, $type_id, $type, $accounts_obj;

	var $cat_id;
	var $start;
	var $query;
	var $sort;
	var $order;
	var $filter;
	var $part_of_town_id;
	var $sub;
	var $currentapp;
	var $criteria_id;
	var $filter_year;
	var $account;
	var $acl_location;
	var $jqcal;
	var $public_functions = array(
		'columns'					 => true,
		'query'					 => false,
		'index'						 => true,
		'view'						 => true,
		'add'						 => true,
		'edit'						 => true,
		'add_invoice'				 => true,
		'recalculate'				 => true,
		'get_vendor_contract'		 => false,
	);

	function __construct()
	{
		parent::__construct();

		$this->flags['xslt_app']			 = true;
		$this->flags['menu_selection']	 = 'property::project::workorder';
		Settings::getInstance()->update('flags', ['xslt_app' => true, 'menu_selection' => $this->flags['menu_selection']]);

		$this->account = $this->userSettings['account_id'];

		$this->bo			 = CreateObject('property.boworkorder');
		$this->bocommon		 = CreateObject('property.bocommon');
		$this->cats			 = &$this->bo->cats;
		$this->acl_location	 = '.project.workorder';
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
		$this->start_date		 = $this->bo->start_date;
		$this->end_date			 = $this->bo->end_date;
		$this->b_group			 = $this->bo->b_group;
		$this->ecodimb			 = $this->bo->ecodimb;
		$this->paid				 = $this->bo->paid;
		$this->b_account		 = $this->bo->b_account;
		$this->district_id		 = $this->bo->district_id;
		$this->criteria_id		 = $this->bo->criteria_id;
		$this->obligation		 = $this->bo->obligation;
		$this->filter_year		 = $this->bo->filter_year;
		$this->decimal_separator = ',';
		$this->jqcal = createObject('phpgwapi.jqcal');
		$this->accounts_obj = new Accounts();
	}


	function columns()
	{
		$receipt = array();
		phpgwapi_xslttemplates::getInstance()->add_file(array(
			'columns'
		));

		$this->flags['noframework']	 = true;
		$this->flags['nofooter']		 = true;
		Settings::getInstance()->update('flags', ['noframework' => true, 'nofooter' => true]);

		$values = Sanitizer::get_var('values');

		$preferences = createObject('phpgwapi.preferences', $this->account);

		if (isset($values['save']) && $values['save'])
		{
			$preferences->add('property', 'workorder_columns', $values['columns'], 'user');
			$preferences->save_repository();
			$receipt['message'][] = array(
				'msg' => lang('columns is updated')
			);
		}

		$function_msg = lang('Select Column');

		$link_data = array(
			'menuaction' => 'property.uiworkorder.columns',
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
		phpgwapi_xslttemplates::getInstance()->set_var('phpgw', array(
			'columns' => $data
		));
	}

	private function _get_filters($selected = 0)
	{
		$values_combo_box	 = array();
		$combos				 = array();

		$values_combo_box[0] = $this->bocommon->select_district_list('filter', $this->district_id);
		$default_value		 = array(
			'id'	 => '',
			'name'	 => lang('no district')
		);
		array_unshift($values_combo_box[0], $default_value);
		$combos[]			 = array(
			'type'	 => 'filter',
			'name'	 => 'district_id',
			'extra'	 => '',
			'text'	 => lang('district'),
			'list'	 => $values_combo_box[0]
		);


		$_cats				 = $this->cats->return_sorted_array(0, false, '', '', '', false, false);
		$values_combo_box[1] = array();
		foreach ($_cats as $_cat)
		{
			if ($_cat['level'] == 0 && $_cat['active'] != 2)
			{
				$values_combo_box[1][] = $_cat;
			}
		}
		$default_value	 = array(
			'id'	 => '',
			'name'	 => lang('no category')
		);
		array_unshift($values_combo_box[1], $default_value);
		$combos[]		 = array(
			'type'	 => 'filter',
			'name'	 => 'cat_id',
			'extra'	 => '',
			'text'	 => lang('Category'),
			'list'	 => $values_combo_box[1]
		);


		$values_combo_box[2] = $this->bo->select_status_list('filter', $this->status_id);
		array_unshift($values_combo_box[2], array(
			'id'	 => 'open',
			'name'	 => lang('open')
		));
		array_unshift($values_combo_box[2], array(
			'id'	 => 'all',
			'name'	 => lang('all')
		));
		$combos[]			 = array(
			'type'	 => 'filter',
			'name'	 => 'status_id',
			'extra'	 => '',
			'text'	 => lang('status'),
			'list'	 => $values_combo_box[2]
		);
		//

		$values_combo_box[3] = $this->bocommon->select_category_list(array(
			'format'	 => 'filter',
			'selected'	 => $this->wo_hour_cat_id,
			'type'		 => 'wo_hours',
			'order'		 => 'id'
		));
		$default_value		 = array(
			'id'	 => '',
			'name'	 => lang('no hour category')
		);
		array_unshift($values_combo_box[3], $default_value);
		$combos[]			 = array(
			'type'	 => 'filter',
			'name'	 => 'wo_hour_cat_id',
			'extra'	 => '',
			'text'	 => lang('Hour Category'),
			'list'	 => $values_combo_box[3]
		);

		$values_combo_box[4] = $this->bo->get_criteria_list($this->criteria_id);
		$default_value		 = array(
			'id'	 => '',
			'name'	 => lang('no criteria')
		);
		array_unshift($values_combo_box[4], $default_value);
		$combos[]			 = array(
			'type'	 => 'filter',
			'name'	 => 'criteria_id',
			'extra'	 => '',
			'text'	 => lang('search criteria'),
			'list'	 => $values_combo_box[4]
		);

		$values_combo_box[5] = execMethod('property.boproject.get_filter_year_list', $this->filter_year);
		array_unshift($values_combo_box[5], array(
			'id'	 => 'all',
			'name'	 => lang('all') . ' ' . lang('year')
		));
		$combos[]			 = array(
			'type'	 => 'filter',
			'name'	 => 'filter_year',
			'extra'	 => '',
			'text'	 => lang('Year'),
			'list'	 => $values_combo_box[5]
		);

		$values_combo_box[6] = $this->bo->get_user_list($this->filter);
		array_unshift($values_combo_box[6], array(
			'id'	 => $this->userSettings['account_id'],
			'name'	 => lang('mine orders')
		));
		$default_value		 = array(
			'id'	 => '',
			'name'	 => lang('no user')
		);
		array_unshift($values_combo_box[6], $default_value);
		$combos[]			 = array(
			'type'	 => 'filter',
			'name'	 => 'filter',
			'extra'	 => '',
			'text'	 => lang('User'),
			'list'	 => $values_combo_box[6]
		);
		$values_combo_box[7]		 = execMethod('property.bogeneric.get_list', array(
			'type' => 'budget_account',
			'filter' => array('active' => 1),
			'selected' => ''
		));

		$default_value		 = array('id' => '', 'name' => lang('select'));
		array_unshift($values_combo_box[7], $default_value);
		$combos[]			 = array(
			'type'	 => 'filter',
			'name'	 => 'b_account',
			'text'	 => lang('budget account'),
			'list'	 => $values_combo_box[7]
		);

		return $combos;
	}

	public function query()
	{
		return [];
	}

	function index()
	{
		if (!$this->acl_read)
		{
			phpgw::no_access();
			return;
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

		$default_district = (isset($this->userSettings['preferences']['property']['default_district']) ? $this->userSettings['preferences']['property']['default_district'] : '');

		if ($default_district && !isset($_REQUEST['district_id']))
		{
			$this->bo->district_id	 = $default_district;
			$this->district_id		 = $default_district;
		}

		$start_date	 = $this->start_date ? urldecode($this->start_date) : null;
		$end_date	 = $this->end_date ? urldecode($this->end_date) : null;

		$query = Sanitizer::get_var('query');

		phpgwapi_jquery::load_widget('numberformat');
		self::add_javascript('property', 'base', 'workorder.index.js');

		$this->jqcal->add_listener('filter_start_date');
		$this->jqcal->add_listener('filter_end_date');
		phpgwapi_jquery::load_widget('datepicker');

		$appname										 = lang('Workorder');
		$function_msg									 = lang('list workorder');
		$this->flags['app_header']	 = lang('property') . ' - ' . $appname . ': ' . $function_msg;
		Settings::getInstance()->update('flags', ['app_header' => $this->flags['app_header']]);


		$data = array(
			'datatable_name' => $appname . ': ' . $function_msg,
			'form'			 => array(
				'toolbar' => array(
					'item' => array(
						array(
							'type'	 => 'date-picker',
							'id'	 => 'start_date',
							'name'	 => 'start_date',
							'value'	 => $start_date,
							'text'	 => lang('from')
						),
						array(
							'type'	 => 'date-picker',
							'id'	 => 'end_date',
							'name'	 => 'end_date',
							'value'	 => $end_date,
							'text'	 => lang('to')
						)
					)
				)
			),
			'datatable'		 => array(
				'source'		 => phpgw::link('/property/workorder', array(
					'lookup'		 => $lookup,
					'make_relation'	 => $make_relation,
					'relation_id'	 => $relation_id,
					'relation_type'	 => $relation_type,
					'district_id'	 => $this->district_id,
					'start_date'	 => $start_date,
					'end_date'		 => $end_date,
					'b_group'		 => $this->b_group,
					'b_account'		 => $this->b_account,
					'paid'			 => $this->paid,
					'obligation'	 => $this->obligation,
					'ecodimb'		 => $this->ecodimb,
				)),
				'download'		 => phpgw::link('/property/workorder/reports/download', array(
					'start_date' => $start_date,
					'end_date'	 => $end_date,
					'b_group'	 => $this->b_group,
					'b_account'	 => $this->b_account,
					'paid'		 => $this->paid,
					'obligation' => $this->obligation,
					'ecodimb'	 => $this->ecodimb,
					'export'	 => true,
					'allrows'	 => true
				)),
				"columns"		 => array('onclick' => "JqueryPortico.openPopup({menuaction:'property.uiworkorder.columns', appname:'{$this->bo->appname}',type:'{$this->type}', type_id:'{$this->type_id}'}, {closeAction:'reload'})"),
				'new_item'		 => self::link(array(
					'menuaction' => 'property.uiworkorder.add'
				)),
				'allrows'		 => true,
				'select_all'	 => $make_relation,
				'editor_action'	 => '',
				'query'			 => $query,
				'field'			 => array()
			)
		);

		$filters = $this->_get_filters();
		krsort($filters);
		foreach ($filters as $filter)
		{
			array_unshift($data['form']['toolbar']['item'], $filter);
		}

		$this->bo->read(array(
			'dry_run' => true
		));
		$uicols = $this->bo->uicols;

		//$uicols_count indicates the number of columns to display in actuall option-menu. this variable was set in $this->bo->read()
		$uicols_count = count($uicols['name']);
		for ($k = 0; $k < $uicols_count; $k++)
		{
			$params = array(
				'key'		 => $uicols['name'][$k],
				'label'		 => $uicols['descr'][$k],
				'sortable'	 => ($uicols['sortable'][$k]) ? true : false,
				'hidden'	 => ($uicols['input_type'][$k] == 'hidden') ? true : false
			);

			#if(!empty($uicols['formatter'][$k]))
			#{
			#    $params['formatter'] = $uicols['formatter'][$k];
			#}

			switch ($uicols['name'][$k])
			{
				case 'project_id':
					if (!$lookup)
					{
						$params['formatter'] = 'linktToProject';
					}
					break;
				case 'workorder_id':
					if (!$lookup)
					{
						$params['formatter'] = 'linktToOrder';
					}
					break;
				case 'loc1':
					$params['formatter'] = 'JqueryPortico.searchLink';
					break;
				case 'actual_cost':
				case 'obligation':
				case 'combined_cost':
				case 'diff':
				case 'budget':
					$params['formatter'] = 'JqueryPortico.FormatterAmount0';
					break;
				default:
					break;
			}
			array_push($data['datatable']['field'], $params);
		}


		// NO pop-up
		if (!$lookup)
		{
			$parameters = array(
				'parameter' => array(
					array(
						'name'	 => 'id',
						'source' => 'workorder_id'
					),
				)
			);

			$parameters2 = array(
				'parameter' => array(
					array(
						'name'	 => 'workorder_id',
						'source' => 'workorder_id'
					),
				)
			);
			if ($this->acl_read)
			{
				$data['datatable']['actions'][]	 = array(
					'my_name'	 => 'view',
					'text'		 => lang('view'),
					'action'	 => phpgw::link('/index.php', array(
						'menuaction' => 'property.uiworkorder.view'
					)),
					'parameters' => json_encode($parameters)
				);
				$data['datatable']['actions'][]	 = array(
					'my_name'	 => 'view',
					'text'		 => lang('open view in new window'),
					'action'	 => phpgw::link('/index.php', array(
						'menuaction' => 'property.uiworkorder.view'
					)),
					'target'	 => '_blank',
					'parameters' => json_encode($parameters)
				);

				$jasper = execMethod('property.sojasper.read', array(
					'location_id' => $this->locations->get_id('property', $this->acl_location)
				));

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
				$data['datatable']['actions'][]	 = array(
					'my_name'	 => 'edit',
					'text'		 => lang('edit'),
					'action'	 => phpgw::link('/index.php', array(
						'menuaction' => 'property.uiworkorder.edit'
					)),
					'parameters' => json_encode($parameters)
				);
				$data['datatable']['actions'][]	 = array(
					'my_name'	 => 'edit',
					'text'		 => lang('open edit in new window'),
					'action'	 => phpgw::link('/index.php', array(
						'menuaction' => 'property.uiworkorder.edit'
					)),
					'target'	 => '_blank',
					'parameters' => json_encode($parameters)
				);

				$data['datatable']['actions'][] = array(
					'my_name'	 => 'calculate',
					'text'		 => lang('calculate'),
					'action'	 => phpgw::link('/index.php', array(
						'menuaction' => 'property.uiwo_hour.index'
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
										var requestUrl = phpGWLink('property/workorder/' + aData['workorder_id'], {});
										execute_ajax(requestUrl, function(result){
											var message = result && result.message ? result.message : result;
											document.getElementById('message').innerHTML += '<br/>' + message;
											api.draw('page');
										}, {}, 'DELETE', 'json');
									}
								"
				);
			}
			unset($parameters);
		}
		/*
			if ($lookup && !$make_relation)
			{
				$from = Sanitizer::get_var('from');

				$oArg = "{menuaction: 'property.ui{$from}.edit',"
					. "origin:'" . Sanitizer::get_var('origin') . "',"
					. "origin_id:'" . Sanitizer::get_var('origin_id') . "',"
					. "order_id: aData['workorder_id']}";

				$data['left_click_action'] = "window.open(phpGWLink('index.php', {$oArg}),'_self');";
			}
*/
		if ($make_relation)
		{
			$parameters3 = array(
				'parameter' => array(
					array(
						'name'	 => 'add_relation',
						'source' => 'workorder_id'
					),
				)
			);

			$data['datatable']['actions'][] = array(
				'my_name'	 => 'update_ticket',
				'text'		 => $lang_update_relation,
				'action'	 => phpgw::link(
					'/index.php',
					array(
						'menuaction'	 => $update_menuaction,
						'id'			 => $relation_id,
						'relation_type'	 => 'workorder',
					)
				),
				'parameters' => json_encode($parameters3)
			);
		}

		self::render_template_xsl('datatable2', $data);
	}


	private function _handle_files($values)
	{
		$id = (int)$values['id'];
		if (empty($id))
		{
			throw new Exception('uiworkorder::_handle_files() - missing id');
		}

		$bofiles = CreateObject('property.bofiles');
		if (isset($values['file_action']) && is_array($values['file_action']))
		{
			$bofiles->delete_file("/workorder/{$id}/", $values);
		}

		$values['file_name'] = @str_replace(' ', '_', $_FILES['file']['name']);

		if ($values['file_name'])
		{
			$to_file = $bofiles->fakebase . '/workorder/' . $id . '/' . $values['file_name'];

			if ($bofiles->vfs->file_exists(array(
				'string'	 => $to_file,
				'relatives'	 => array(
					RELATIVE_NONE
				)
			)))
			{
				$this->receipt['error'][] = array(
					'msg' => lang('This file already exists !')
				);
			}
			else
			{
				$bofiles->create_document_dir("workorder/$id");
				$bofiles->vfs->override_acl = 1;

				if (!$bofiles->vfs->cp(array(
					'from'		 => $_FILES['file']['tmp_name'],
					'to'		 => $to_file,
					'relatives'	 => array(
						RELATIVE_NONE | VFS_REAL,
						RELATIVE_ALL
					)
				)))
				{
					$this->receipt['error'][] = array(
						'msg' => lang('Failed to upload file !')
					);
				}
				$bofiles->vfs->override_acl = 0;
			}
		}
	}


	function edit($values = array(), $mode = 'edit')
	{

		if ($this->flags['nonavbar'] = Sanitizer::get_var('nonavbar', 'bool'))
		{
			$this->flags['noheader_xsl']	 = true;
			$this->flags['nofooter']		 = true;
			Settings::getInstance()->update('flags', ['nonavbar' => true, 'noheader_xsl' => true, 'nofooter' => true]);
		}

		$_lean = Sanitizer::get_var('lean', 'bool');

		// in case of bigint
		$id = !empty($values['id']) ? $values['id'] : Sanitizer::get_var('id');

		if (!$id)
		{
			$id = 0;
		}

		if ($mode == 'edit' && (!$this->acl_add && !$this->acl_edit))
		{
			phpgw::redirect_link('/index.php', array(
				'menuaction' => 'property.uiworkorder.view',
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
				Cache::message_set('ID is required for the function uiworkorder::view()', 'error');
				phpgw::redirect_link('/index.php', array(
					'menuaction' => 'property.uiworkorder.index'
				));
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

		$boproject	 = CreateObject('property.boproject');
		$bolocation	 = CreateObject('property.bolocation');
		$config		 = CreateObject('phpgwapi.config', 'property');
		$datatable_def = array();
		$location_id = $this->locations->get_id('property', $this->acl_location);
		$config->read();
		$project_id	 = Sanitizer::get_var('project_id', 'int');
		$origin		 = Sanitizer::get_var('origin');
		$origin_id	 = Sanitizer::get_var('origin_id', 'int');

		$this->_resolve_origin_details($values, $origin, $origin_id);

		if ($project_id && !isset($values['project_id']))
		{
			$values['project_id'] = $project_id;
		}

		$project = (isset($values['project_id']) ? $boproject->read_single_mini($values['project_id']) : '');

		if ($project)
		{
			$external_project_id = $project['external_project_id'];

			$external_project = execMethod('property.bogeneric.read_single', array(
				'id'			 => $external_project_id,
				'location_info'	 => array(
					'type' => 'external_project'
				)
			));

			if ($external_project['eco_service_id'])
			{
				$values['service_id'] = $external_project['eco_service_id'];
			}
		}
		if (!$this->receipt['error'])
		{
			if ($values['id'])
			{
				$id = $values['id'];
			}

			if ($id)
			{
				$values = $this->bo->read_single($id);

				if (!isset($values['origin']))
				{
					$values['origin'] = '';
				}
			}
			if ($project_id && !isset($values['project_id']))
			{
				$values['project_id'] = $project_id;
			}

			if (!$project && isset($values['project_id']) && $values['project_id'])
			{
				$project = $boproject->read_single_mini($values['project_id']);
			}

			$acl_required = $mode == 'edit' ? ACL_EDIT : ACL_READ;
			if (!$this->bocommon->check_perms2($project['coordinator'], $this->bo->so->grants, ACL_EDIT))
			{
				$this->receipt['error'][] = array(
					'msg' => lang('You have no edit right for this project')
				);

				Cache::session_set('property', 'receipt', $this->receipt);

				switch ($mode)
				{
					case 'edit':
						self::redirect(array('menuaction' => 'property.uiworkorder.view', 'id' => $id));
						break;
					default:
						self::redirect(array('menuaction' => 'property.uiworkorder.index'));
						break;
				}
			}

			$this->_apply_project_defaults_to_values($values, $project, $config);
		}


		if ($id)
		{
			$record_history = $this->bo->read_record_history($id);
		}
		else
		{
			$record_history = array();
		}

		if ($id)
		{
			$function_msg = lang("{$mode} workorder");
		}
		else
		{
			$function_msg = lang('Add workorder');
		}

		if (isset($values['cat_id']) && $values['cat_id'])
		{
			$this->cat_id = $values['cat_id'];
		}

		$location_context = $this->_initialize_location_context($values, $project, $mode, $bolocation, $config);
		$location_data = $location_context['location_data'];
		$location_template_type = $location_context['location_template_type'];
		$_location_data = $location_context['_location_data'];

		$vendor_budget_context = $this->_initialize_vendor_budget_context($values, $project, $mode, $config);
		$vendor_data = $vendor_budget_context['vendor_data'];
		$b_group_data = $vendor_budget_context['b_group_data'];
		$b_account_data = $vendor_budget_context['b_account_data'];
		$b_account_list = $vendor_budget_context['b_account_list'];
		$ecodimb_data = $vendor_budget_context['ecodimb_data'];

		$event_criteria	 = array(
			'location'	 => $this->acl_location,
			'name'		 => 'event_id',
			'event_name' => lang('schedule'),
			'event_id'	 => $values['event_id'],
			'item_id'	 => $id,
			'type'		 => $mode
		);
		$event_data		 = $this->bocommon->initiate_event_lookup($event_criteria);

		if (isset($event_data['count']) && $event_data['count'])
		{
			$sum_estimated_cost = $event_data['count'] * $values['calculation'];
		}
		else
		{
			$sum_estimated_cost = $values['calculation'];
		}

		$sum_estimated_cost		 = number_format((float)$sum_estimated_cost, 2, $this->decimal_separator, '.');
		$values['calculation']	 = number_format((float)$values['calculation'], 2, $this->decimal_separator, '.');

		$form_action = $this->_resolve_workorder_form_action($mode, $id);
		$this->_apply_default_workorder_status($values);
		$this->_add_workorder_date_listeners($project);
		$this->_append_history_datatable($datatable_def, $record_history);

		$files_def = array(
			array(
				'key'		 => 'file_name',
				'label'		 => lang('Filename'),
				'sortable'	 => false,
				'resizeable' => true
			),
			array(
				'key'		 => 'picture',
				'label'		 => lang('picture'),
				'sortable'	 => true,
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

		$requestUrl	 = json_encode(phpgw::link('/property/workorder/' . $id . '/files/actions', array(
			'phpgw_return_as' => 'json'
		)));
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
						var api = oTable1.api();
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
		var api = oTable1.api();
		var requestUrl = api.ajax.url();
		requestUrl = requestUrl.split('&tags[]=')[0];
		$.each(tags, function (k, v)
		{
			requestUrl += '&tags[]=' + v;
		});
		JqueryPortico.updateinlineTableHelper('datatable-container_1', requestUrl);
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
				if( data != null)
				{

				}
				JqueryPortico.updateinlineTableHelper('datatable-container_1');

				if(action=='delete_file')
				{
					var strURL = phpGWLink('property/workorder/{$id}/files-attachments', {phpgw_return_as:'json'}, true);
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
			'container'	 => 'datatable-container_1',
			'requestUrl' => json_encode(phpgw::link('/property/workorder/' . $id . '/files', array(
				'phpgw_return_as' => 'json'
			))),
			'data'		 => json_encode(array()),
			'ColumnDefs' => $files_def,

			'tabletools' => $tabletools,
			'config'	 => array(
				array('disableFilter' => true),
				array('disablePagination' => true),
				array('order' => json_encode(array(0, 'asc'))),
			)
		);

		$invoices = array();
		if ($id)
		{
			$invoices = createObject('property.soinvoice')->read_invoice_sub_sum(
				array(
					'order_id'	 => $id,
					'paid'		 => 'both',
					'allrows'	 => true
				)
			);
		}

		$link_data_invoice1	 = array(
			'menuaction' => 'property.uiinvoice.index',
			'user_lid'	 => 'all'
		);
		$link_data_invoice2	 = array(
			'menuaction' => 'property.uiinvoice2.index'
		);

		$_disable_link	 = $_lean;
		$content_invoice = array();
		$amount_ex_tax	 = 0;
		$amount_tax		 = 0;
		$amount			 = 0;
		$approved_amount = 0;
		foreach ($invoices as $entry)
		{
			$entry['voucher_id'] = $entry['transfer_time'] ? -1 * $entry['voucher_id'] : $entry['voucher_id'];
			if ($entry['voucher_out_id'])
			{
				$voucher_out_id = $entry['voucher_out_id'];
			}
			else
			{
				$voucher_out_id = abs($entry['voucher_id']);
			}

			if ($config->config_data['invoicehandler'] == 2)
			{
				$voucher_id = $entry['transfer_time'] ? -1 * $entry['voucher_id'] : $entry['voucher_id'];
				if ($entry['voucher_id'] > 0)
				{
					$link_data_invoice2['voucher_id']	 = $entry['voucher_id'];
					$url								 = phpgw::link('/index.php', $link_data_invoice2);
				}
				else
				{
					$link_data_invoice1['voucher_id']	 = abs($entry['voucher_id']);
					$link_data_invoice1['paid']			 = 'true';
					$url								 = phpgw::link('/index.php', $link_data_invoice1);
				}
			}
			else
			{
				$_disable_link	 = true;
				$voucher_id		 = $entry['external_voucher_id'];
			}

			$link_voucher_id = "<a href='" . $url . "'>" . $voucher_out_id . "</a>";

			$content_invoice[] = array(
				'voucher_id'			 => ($_disable_link) ? $voucher_id : $link_voucher_id,
				'voucher_out_id'		 => $entry['voucher_out_id'],
				'status'				 => $entry['status'],
				'period'				 => $entry['period'],
				'periodization'			 => $entry['periodization'],
				'periodization_start'	 => $entry['periodization_start'],
				'invoice_id'			 => $entry['invoice_id'],
				'budget_account'		 => $entry['budget_account'],
				'dima'					 => $entry['dima'],
				'dimb'					 => $entry['dimb'],
				'dimd'					 => $entry['dimd'],
				'type'					 => $entry['type'],
				'amount_ex_tax'			 => ((float)$entry['amount'] * 0.8),
				'amount_tax'			 => ((float)$entry['amount'] * 0.2),
				'amount'				 => $entry['amount'],
				'approved_amount'		 => $entry['approved_amount'],
				'vendor'				 => $entry['vendor'],
				'external_project_id'	 => $entry['project_id'],
				'currency'				 => $entry['currency'],
				'tax_code'				 => $entry['tax_code'],
				'budget_responsible'	 => $entry['budget_responsible'],
				'budsjettsigndato'		 => $entry['budsjettsigndato'] ? $this->phpgwapi_common->show_date(strtotime($entry['budsjettsigndato']), $this->userSettings['preferences']['common']['dateformat']) : '',
				'transfer_time'			 => $entry['transfer_time'] ? $this->phpgwapi_common->show_date(strtotime($entry['transfer_time']), $this->userSettings['preferences']['common']['dateformat']) : '',
			);

			$amount_ex_tax	 += ((float)$entry['amount'] * 0.8);
			$amount_tax		 += ((float)$entry['amount'] * 0.2);
			$amount			 += $entry['amount'];
			$approved_amount += $entry['approved_amount'];
		}
		unset($entry);

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

		$invoice_def = array(
			array(
				'key'			 => 'voucher_id',
				'label'			 => lang('bilagsnr'),
				'sortable'		 => true,
				'value_footer'	 => lang('Sum')
			),
			array(
				'key'	 => 'voucher_out_id',
				'hidden' => true
			),
			array(
				'key'		 => 'invoice_id',
				'label'		 => lang('invoice number'),
				'sortable'	 => false
			),
			array(
				'key'		 => 'vendor',
				'label'		 => lang('vendor'),
				'sortable'	 => false
			),
			array(
				'key'			 => 'amount_ex_tax',
				'label'			 => lang('ex tax'),
				'sortable'		 => true,
				'className'		 => 'right',
				'formatter' => 'JqueryPortico.FormatterAmount2',
				'value_footer'	 => number_format((float)$amount_ex_tax, 2, $this->decimal_separator, '.')
			),
			array(
				'key'			 => 'amount_tax',
				'label'			 => lang('tax'),
				'sortable'		 => true,
				'className'		 => 'right',
				'formatter' => 'JqueryPortico.FormatterAmount2',
				'value_footer'	 => number_format((float)$amount_tax, 2, $this->decimal_separator, '.')
			),
			array(
				'key'			 => 'amount',
				'label'			 => lang('amount'),
				'sortable'		 => true,
				'className'		 => 'right',
				'formatter' => 'JqueryPortico.FormatterAmount2',
				'value_footer'	 => number_format((float)$amount, 2, $this->decimal_separator, '.')
			),
			//				array(
			//					'key' => 'approved_amount',
			//					'label' => lang('approved amount'),
			//					'sortable' => true,
			//					'className' => 'right',
			//					'value_footer' => number_format((float)$approved_amount, 2, $this->decimal_separator, '.')),
			array(
				'key'		 => 'period',
				'label'		 => lang('period'),
				'sortable'	 => true
			),
			array(
				'key'		 => 'periodization',
				'label'		 => lang('periodization'),
				'sortable'	 => false
			),
			array(
				'key'		 => 'periodization_start',
				'label'		 => lang('periodization start'),
				'sortable'	 => false
			),
			array(
				'key'		 => 'currency',
				'label'		 => lang('currency'),
				'sortable'	 => false
			),
			array(
				'key'		 => 'type',
				'label'		 => lang('type'),
				'sortable'	 => false
			),
			array(
				'key'		 => 'tax_code',
				'label'		 => lang('tax code'),
				'sortable'	 => false,
				'className'	 => 'right',
			),
			array(
				'key'		 => 'budget_responsible',
				'label'		 => lang('budget responsible'),
				'sortable'	 => false
			),
			array(
				'key'		 => 'budsjettsigndato',
				'label'		 => lang('budsjettsigndato'),
				'sortable'	 => false
			),
			array(
				'key'		 => 'transfer_time',
				'label'		 => lang('transfer time'),
				'sortable'	 => false
			)
		);

		$datatable_def[] = array(
			'container'	 => 'datatable-container_2',
			'requestUrl' => "''",
			'data'		 => json_encode($content_invoice),
			'ColumnDefs' => $invoice_def,
			'config'	 => array(
				array('singleSelect' => true),
				//			array('disableFilter' => true),
				array('disablePagination' => true)
			)
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
				'requestUrl'		 => json_encode(self::link(array(
					'menuaction'		 => 'property.notify.update_data',
					'location_id'		 => $location_id,
					'location_item_id'	 => $id,
					'action'			 => 'refresh_notify_contact',
					'phpgw_return_as'	 => 'json'
				))),
			)
		);

		$datatable_def[] = array(
			'container'	 => 'datatable-container_6',
			//				'requestUrl' => "''",
			//				'data'		 => json_encode($attachmen_list),
			'requestUrl' => json_encode(phpgw::link('/property/project/attachments', array(
				'phpgw_return_as' => 'json'
			))),
			'data'		 => json_encode(array()),

			'ColumnDefs' => $attachmen_def,
			'config'	 => array(
				array('disableFilter' => true),
				array('disablePagination' => true),
			)
		);

		$datatable_def[] = array(
			'container'	 => 'datatable-container_3',
			'requestUrl' => json_encode(self::link(array(
				'menuaction'		 => 'property.notify.update_data',
				'location_id'		 => $location_id,
				'location_item_id'	 => $id,
				'action'			 => 'refresh_notify_contact',
				'phpgw_return_as'	 => 'json'
			))),
			'ColumnDefs' => $notify_info['column_defs']['values'],
			'data'		 => json_encode(array()),
			'tabletools' => $mode == 'edit' ? $notify_info['tabletools'] : array(),
			'config'	 => array(
				array(
					'disableFilter' => true
				),
				array(
					'disablePagination' => true
				)
			)
		);

		$content_email = execMethod('property.bocommon.get_vendor_email', isset($values['vendor_id']) ? $values['vendor_id'] : 0);

		if (isset($values['mail_recipients']) && is_array($values['mail_recipients']))
		{
			$_recipients_found = array();
			foreach ($content_email as &$vendor_email)
			{
				if (in_array($vendor_email['value_email'], $values['mail_recipients']))
				{
					$vendor_email['value_select']	 = str_replace("type='checkbox'", "type='checkbox' checked='checked'", $vendor_email['value_select']);
					$_recipients_found[]			 = $vendor_email['value_email'];
				}
			}
			$value_extra_mail_address = implode(',', array_diff($values['mail_recipients'], $_recipients_found));
		}

		$email_def = array(
			array(
				'key'		 => 'value_email',
				'label'		 => lang('email'),
				'sortable'	 => true,
				'resizeable' => true
			),
			array(
				'key'		 => 'value_select',
				'label'		 => lang('select'),
				'sortable'	 => false,
				'resizeable' => true
			)
		);

		$datatable_def[] = array(
			'container'	 => 'datatable-container_4',
			'requestUrl' => "''",
			'data'		 => json_encode($content_email),
			'ColumnDefs' => $email_def,
			'config'	 => array(
				array(
					'disableFilter' => true
				),
				array(
					'disablePagination' => true
				)
			)
		);

		$content_budget = $this->bo->get_budget($id);

		$lang_delete	 = lang('Check to delete period');
		$lang_close		 = lang('Check to close period');
		$lang_active	 = lang('Check to activate period');
		$lang_fictive	 = lang('fictive');

		$maxmatchs = $this->userSettings['preferences']['common']['maxmatchs'];
		$rows_per_page	 = $maxmatchs ? $maxmatchs : 10;
		$initial_page	 = 1;

		if ($content_budget && $project['periodization_id'])
		{
			$_year_count = array();
			foreach ($content_budget as $key => $row)
			{
				$_year_count[$row['year']]	 += 1;
				$rows_per_page				 = max($_year_count[$row['year']], $maxmatchs);
			}
			$initial_page = floor(count($content_budget) / $rows_per_page);
		}

		$budget			 = 0;
		$sum_orders		 = 0;
		$sum_oblications = 0;
		$actual_cost	 = 0;
		$diff			 = 0;
		$deviation		 = 0;
		foreach ($content_budget as &$b_entry)
		{
			$checked				 = $b_entry['active'] ? 'checked="checked"' : '';
			$b_entry['flag_active']	 = $b_entry['active'] == 1;
			if ($b_entry['fictive'])
			{
				$b_entry['delete_period']	 = $lang_fictive;
				$disabled					 = 'disabled="disabled"';
			}
			else
			{
				$b_entry['delete_period'] = "<input type='checkbox' name='values[delete_b_period][]' value='{$b_entry['year']}_{$b_entry['month']}' title='{$lang_delete}'>";
			}

			if ($b_entry['active'] == 2)
			{
				$b_entry['month']	 = 'Split';
				$b_entry['closed']	 = 'Split';
			}
			else
			{
				$b_entry['closed'] = $b_entry['closed'] ? 'X' : '';
			}

			if ($b_entry['active'] == 1)
			{
				$budget			 += $b_entry['budget'];
				$sum_orders		 += $b_entry['sum_orders'];
				$sum_oblications += $b_entry['sum_oblications'];
				$actual_cost	 += $b_entry['actual_cost'];
				$diff			 += $b_entry['diff'];
				$deviation		 += $b_entry['deviation_period'];
			}

			$b_entry['active']		 = "<input type='checkbox' name='values[active_b_period][]' value='{$b_entry['year']}_{$b_entry['month']}' title='{$lang_active}' {$checked} {$disabled}>";
			$b_entry['active_orig']	 = "<input type='checkbox' name='values[active_orig_b_period][]' value='{$b_entry['year']}_{$b_entry['month']}' {$checked} {$disabled} style='display:none'>";
		}
		unset($b_entry);

		$budget_def = array(
			array(
				'key'			 => 'year',
				'label'			 => lang('year'),
				'sortable'		 => true,
				'className'		 => 'center',
				'value_footer'	 => lang('Sum')
			),
			array(
				'key'		 => 'month',
				'label'		 => lang('month'),
				'sortable'	 => false,
				'className'	 => 'center'
			),
			array(
				'key'			 => 'budget',
				'label'			 => lang('budget'),
				'sortable'		 => false,
				'className'		 => 'right',
				'formatter'		 => 'JqueryPortico.FormatterAmount0',
				'value_footer'	 => number_format((float)$budget, 0, $this->decimal_separator, '.')
			),
			array(
				'key'			 => 'sum_orders',
				'label'			 => lang('order'),
				'sortable'		 => false,
				'className'		 => 'right',
				'formatter'		 => 'JqueryPortico.FormatterAmount0',
				'value_footer'	 => number_format((float)$sum_orders, 0, $this->decimal_separator, '.')
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
				'value_footer'	 => number_format((float)$actual_cost, 0, $this->decimal_separator, '.')
			),
			array(
				'key'			 => 'diff',
				'label'			 => lang('difference'),
				'sortable'		 => false,
				'className'		 => 'right',
				'formatter'		 => 'JqueryPortico.FormatterAmount0',
				'value_footer'	 => number_format((float)$diff, 0, $this->decimal_separator, '.')
			),
			array(
				'key'			 => 'deviation_period',
				'label'			 => lang('deviation'),
				'sortable'		 => false,
				'className'		 => 'right',
				'formatter'		 => 'JqueryPortico.FormatterAmount0',
				'value_footer'	 => number_format((float)$deviation, 0, $this->decimal_separator, '.')
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
				'className'	 => 'center'
			),
			array(
				'key'		 => 'active',
				'label'		 => lang('active'),
				'sortable'	 => false,
				'className'	 => 'center',
				'formatter'	 => 'JqueryPortico.FormatterActive'
			),
			array(
				'key'		 => 'delete_period',
				'label'		 => lang('Delete'),
				'sortable'	 => false,
				'className'	 => 'center'
			)
		);

		$datatable_def[] = array(
			'container'	 => 'datatable-container_5',
			'requestUrl' => "''",
			'data'		 => json_encode($content_budget),
			'ColumnDefs' => $budget_def,
			'config'	 => array(
				array('disableFilter' => true),
				//					array('disablePagination' => true),
				array('rows_per_page' => $rows_per_page),
			)
		);

		$link_claim = '';
		if (!empty($values['charge_tenant']))
		{
			$claim = execMethod('property.sotenant_claim.read', array(
				'project_id' => $project['project_id']
			));
			if ($claim)
			{
				$link_claim = phpgw::link('/index.php', array(
					'menuaction' => 'property.uitenant_claim.edit',
					'claim_id'	 => $claim[0]['claim_id']
				));
			}
			else
			{
				$link_claim = phpgw::link('/index.php', array(
					'menuaction' => 'property.uitenant_claim.check',
					'project_id' => $project['project_id']
				));
			}
		}

		$_cat_sub	 = $this->cats->return_sorted_array(0, false);

		$selected_cat		 = $values['cat_id'] ? $values['cat_id'] : $project['cat_id'];
		$validatet_category	 = '';
		$cat_sub			 = array();
		foreach ($_cat_sub as $entry)
		{
			if ($entry['active'] == 2 && $entry['id'] != $selected_cat) //hidden
			{
				continue;
			}

			if (!$validatet_category)
			{
				if ($entry['active'] && $entry['id'] == $selected_cat)
				{
					$_category = $this->cats->return_single($entry['id']);
					if ($_category[0]['is_node'])
					{
						$validatet_category = 1;
					}
				}
			}
			$entry['name']	 = str_repeat(' . ', (int)$entry['level']) . $entry['name'];
			$entry['title']	 = $entry['description'];
			$cat_sub[]		 = $entry;
		}

		$suppresscoordination	 = isset($config->config_data['project_suppresscoordination']) && $config->config_data['project_suppresscoordination'] ? 1 : '';
		$user_list				 = $this->bocommon->get_user_list_right2('', ACL_ADD | ACL_EDIT, !empty($values['user_id']) ? $values['user_id'] : $this->account, $this->acl_location);

		$value_coordinator = isset($project['coordinator']) ? $this->accounts_obj->get($project['coordinator'])->__toString() : $this->accounts_obj->get($this->account)->__toString();

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

		if (isset($this->receipt['error']) && $this->receipt['error'])
		{
			$year_list = $this->bocommon->select_list($_POST['values']['budget_year'], $year_list);
		}

		$sogeneric			 = CreateObject('property.sogeneric');
		$sogeneric->get_location_info('periodization', false);
		$periodization_data	 = $sogeneric->read_single(array(
			'id' => (int)$project['periodization_id']
		), array());

		$msgbox_data = $this->bocommon->msgbox_data($this->receipt);

		$active_tab = Sanitizer::get_var('active_tab', 'string', 'REQUEST', 'general');

		$collect_building_part	 = false;
		$building_part_list		 = array();
		$order_dim1_list		 = array();
		if (isset($config->config_data['workorder_require_building_part']))
		{
			if ($config->config_data['workorder_require_building_part'] == 1)
			{
				$collect_building_part	 = true;
				$filter_buildingpart	 = isset($config->config_data['filter_buildingpart']) ? $config->config_data['filter_buildingpart'] : array();

				$_filter_buildingpart	 = array();
				if ($filter_key				 = array_search('.b_account', $filter_buildingpart))
				{
					$_filter_buildingpart = array("filter_{$filter_key}" => 1);
				}
				$building_part_list	 = array('options' => $this->bocommon->select_category_list(array(
					'type'		 => 'building_part',
					'selected'	 => $values['building_part'],
					'order'		 => 'id',
					'id_in_name' => 'num',
					'filter'	 => $_filter_buildingpart
				)));
				$order_dim1_list	 = array('options' => $this->bocommon->select_category_list(array(
					'type'		 => 'order_dim1',
					'selected'	 => $values['order_dim1'],
					'order'		 => 'id',
					'id_in_name' => 'num'
				)));
			}
		}

		$unspsc_code = $values['unspsc_code'] ? $values['unspsc_code'] : $this->userSettings['preferences']['property']['unspsc_code'];

		$enable_unspsc			 = isset($config->config_data['enable_unspsc']) && $config->config_data['enable_unspsc'] ? true : false;
		$enable_order_service_id = isset($config->config_data['enable_order_service_id']) && $config->config_data['enable_order_service_id'] ? true : false;

		$approval_level = !empty($config->config_data['approval_level']) ? $config->config_data['approval_level'] : 'order';

		$accumulated_budget_amount = 0;
		if ($approval_level == 'project')
		{
			$accumulated_budget_amount = $this->bo->get_accumulated_budget_amount($values['project_id']);
		}

		$_origin = array();
		if (isset($values['origin_data']) && $values['origin_data'])
		{
			foreach ($values['origin_data'] as $__origin)
			{
				foreach ($__origin['data'] as $_origin_data)
				{
					$_origin[] = array(
						'url'	 => "<a href='{$_origin_data['link']}'>{$_origin_data['id']} </a>",
						'type'	 => $__origin['descr'],
						'title'	 => $_origin_data['title'],
						'status' => $_origin_data['statustext'],
					);
				}
			}
		}

		$origin_def = array(
			array('key' => 'url', 'label' => lang('id'), 'sortable' => true),
			array('key' => 'type', 'label' => lang('type'), 'sortable' => true),
			array('key' => 'title', 'label' => lang('title'), 'sortable' => false),
			array('key' => 'status', 'label' => lang('status'), 'sortable' => false)
		);

		$datatable_def[] = array(
			'container'	 => 'datatable-container_7',
			'requestUrl' => "''",
			'data'		 => json_encode($_origin),
			'ColumnDefs' => $origin_def,
			'config'	 => array(
				array('disableFilter' => true),
				array('disablePagination' => true)
			)
		);

		$attach_file_def	 = array(
			array(
				'key'		 => 'source',
				'label'		 => lang('source'),
				'sortable'	 => false,
				'resizeable' => true
			),
			array(
				'key'		 => 'file_name',
				'label'		 => lang('Filename'),
				'sortable'	 => false,
				'resizeable' => true
			),
			array(
				'key'		 => 'picture',
				'label'		 => lang('picture'),
				'sortable'	 => false,
				'resizeable' => true,
				'formatter'	 => 'JqueryPortico.showPicture'
			),
			array(
				'key'		 => 'attach_file',
				'label'		 => lang('attach file'),
				'sortable'	 => false,
				'resizeable' => true,
				'formatter'	 => 'JqueryPortico.FormatterCenter'
			)
		);
		$file_attachments	 = isset($values['file_attachments']) && is_array($values['file_attachments']) ? $values['file_attachments'] : array();

		$bofiles = CreateObject('property.bofiles');
		$image_list		 = array();

		$content_attachments = array();
		$link_view_file		 = phpgw::link('/property/workorder/files/view');
		$view_image_url		 = phpgw::link('/property/workorder/' . (int)$id . '/files/image');
		$lang_view_file		 = lang('click to view file');
		$lang_select_file	 = lang('Check to attach file');
		$lang_workorder		 = lang('workorder');

		$sort_array = array();
		$sort_array2 = array();

		$z = 0;
		foreach ($values['files'] as $_entry)
		{
			$_checked = '';
			if (in_array($_entry['file_id'], $file_attachments))
			{
				$_checked = 'checked="checked"';
			}
			$sort_array[] = $_entry['name'];

			$content_attachments[] = array(
				'source'		 => $lang_workorder,
				'file_name'		 => "<a href='{$link_view_file}&amp;file_id={$_entry['file_id']}' target='_blank' title='{$lang_view_file}'>{$_entry['name']}</a>",
				'attach_file'	 => "<input type='checkbox' $_checked  name='values[file_attach][]' value='{$_entry['file_id']}' title='{$lang_select_file}'>"
			);

			if ($bofiles->is_image("{$bofiles->rootdir}{$_entry['directory']}/{$_entry['name']}"))
			{
				$sort_array2[] = $_entry['name'];
				$content_attachments[$z]['file_name']	 = $_entry['name'];
				$content_attachments[$z]['img_id']		 = $_entry['file_id'];
				$content_attachments[$z]['img_url']		 = phpgw::link($view_image_url, array(
					'img_id'	 => $_entry['file_id'],
					'file'		 => $_entry['directory'] . '/' . $_entry['file_name']
				));
				$content_attachments[$z]['thumbnail_flag'] = 'thumb=1';

				$image_list[] = array(
					'image_url'		 => "{$link_view_file}&file_id={$_entry['file_id']}",
					'image_name'	 => $_entry['name']
				);
			}
			$z++;
		}
		unset($_entry);

		$link_view_file			 = phpgw::link('/property/project/files/view');

		$files			 = $boproject->get_files($project['project_id']);
		$lang_project	 = lang('project');

		foreach ($files as $_entry)
		{
			$sort_array[] = $_entry['name'];

			$_checked = '';
			if (in_array($_entry['file_id'], $file_attachments))
			{
				$_checked = 'checked="checked"';
			}
			$content_attachments[] = array(
				'source'		 => $lang_project,
				'file_name'		 => "<a href='{$link_view_file}&amp;file_id={$_entry['file_id']}' target='_blank' title='{$lang_view_file}'>{$_entry['name']}</a>",
				'attach_file'	 => "<input type='checkbox' $_checked  name='values[file_attach][]' value='{$_entry['file_id']}' title='{$lang_select_file}'>"
			);

			if ($bofiles->is_image("{$bofiles->rootdir}{$_entry['directory']}/{$_entry['name']}"))
			{
				$sort_array2[] = $_entry['name'];
				$content_attachments[$z]['file_name']	 = $_entry['name'];
				$content_attachments[$z]['img_id']		 = $_entry['file_id'];
				$content_attachments[$z]['img_url']		 = phpgw::link($view_image_url, array(
					'img_id'	 => $_entry['file_id'],
					'file'		 => $_entry['directory'] . '/' . $_entry['file_name']
				));
				$content_attachments[$z]['thumbnail_flag'] = 'thumb=1';

				$image_list[] = array(
					'image_url'		 => "{$link_view_file}&file_id={$_entry['file_id']}",
					'image_name'	 => $_entry['name']
				);
			}
			$z++;
		}
		unset($_entry);

		array_multisort($sort_array, SORT_ASC, $content_attachments);
		array_multisort($sort_array2, SORT_ASC, $image_list);

		$datatable_def[] = array(
			'container'	 => 'datatable-container_8',
			'requestUrl' => "''",
			'ColumnDefs' => $attach_file_def,
			'data'		 => json_encode($content_attachments),
			'config'	 => array(
				array('disableFilter' => true),
				array('disablePagination' => true),
				array('order' => json_encode(array(1, 'asc'))),
			)
		);

		if (!empty($_location_data['location_code']))
		{
			$location_exceptions = $bolocation->get_location_exception($_location_data['location_code']);

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

		$delivery_address = $values['delivery_address'] ? $values['delivery_address'] : $project['delivery_address'];

		if (!$delivery_address && !empty($_location_data['loc1']))
		{
			$delivery_address = CreateObject('property.solocation')->get_delivery_address($_location_data['loc1']);
		}

		$delivery_address = str_replace('__username__', $this->userSettings['fullname'], $delivery_address);

		$default_tax_code = (!empty($project['tax_code']) || $project['tax_code'] === 0) ? $project['tax_code'] : (int)$this->userSettings['preferences']['property']['default_tax_code'];

		$data = array(
			'datatable_def'							 => $datatable_def,
			'periodization_data'					 => $periodization_data,
			'year_list'								 => array(
				'options' => $year_list
			),
			'mode'									 => $mode,
			'value_coordinator'						 => $value_coordinator,
			'event_data'							 => $event_data,
			'link_claim'							 => $link_claim,
			'suppressmeter'							 => isset($config->config_data['project_suppressmeter']) && $config->config_data['project_suppressmeter'] ? 1 : '',
			'suppresscoordination'					 => $suppresscoordination,
			'enable_unspsc'							 => $enable_unspsc,
			'enable_order_service_id'				 => $enable_order_service_id,
			'tabs'									 => self::_generate_tabs(array(), $active_tab, $_disable								 = array(
				'budget'		 => !$id && empty($this->receipt['error']) ? true : false,
				'coordination'	 => $id ? false : true,
				'documents'		 => $id ? false : true,
				'history'		 => $id ? false : true
			)),
			'value_active_tab'						 => $active_tab,
			'msgbox_data'							 => $this->phpgwapi_common->msgbox($msgbox_data),
			'value_origin'							 => isset($values['origin_data']) ? $values['origin_data'] : '',
			'value_origin_type'						 => isset($origin) ? $origin : '',
			'value_origin_id'						 => isset($origin_id) ? $origin_id : '',
			'project_link'							 => phpgw::link('/index.php', array(
				'menuaction' => 'property.uiproject.edit'
			)),
			'b_group_data'							 => $b_group_data,
			'b_account_data'						 => $b_account_data,
			'b_account_as_listbox'					 => $this->userSettings['preferences']['property']['b_account_as_listbox'],
			'b_account_list'						 => array('options' => $b_account_list),
			'value_start_date'						 => $values['start_date'],
			'value_end_date'						 => $values['end_date'],
			'value_tender_deadline'					 => $values['tender_deadline'],
			'value_tender_received'					 => $values['tender_received'],
			'value_tender_delay'					 => $values['tender_delay'],
			'value_inspection_on_completion'		 => $values['inspection_on_completion'],
			'value_end_date_delay'					 => $values['end_date_delay'],
			'contact_phone'							 => (isset($project['contact_phone']) ? $project['contact_phone'] : ''),
			'charge_tenant'							 => (isset($values['charge_tenant']) ? $values['charge_tenant'] : ''),
			'value_power_meter'						 => (isset($project['power_meter']) ? $project['power_meter'] : ''),
			'value_addition_rs'						 => (isset($values['addition_rs']) ? $values['addition_rs'] : ''),
			'value_addition_percentage'				 => (isset($values['addition_percentage']) ? $values['addition_percentage'] : ''),
			'value_budget'							 => isset($this->receipt['error']) && $this->receipt['error'] ? $_POST['values']['budget'] : '',
			'check_for_budget'						 => abs($budget),
			'local_value_budget'					 => $budget,
			'accumulated_budget_amount'				 => $accumulated_budget_amount ? $accumulated_budget_amount : $budget,
			'value_calculation'						 => (isset($values['calculation']) ? $values['calculation'] : ''),
			'value_sum_estimated_cost'				 => $sum_estimated_cost,
			'value_contract_sum'					 => isset($this->receipt['error']) && $this->receipt['error'] ? $_POST['values']['contract_sum'] : '',
			'ecodimb_data'							 => $ecodimb_data,
			'project_ecodimb'						 => $project['ecodimb'],
			'vendor_data'							 => $vendor_data,
			'location_data'							 => $location_data,
			'location_template_type'				 => $location_template_type,
			'form_action'							 => $form_action, //avoid accidents
			'done_action'							 => phpgw::link('/index.php', array(
				'menuaction' => 'property.uiworkorder.index'
			)),
			'value_title'							 => $values['title'],
			'value_project_name'					 => (isset($project['name']) ? $project['name'] : ''),
			'value_project_id'						 => $values['project_id'],
			'value_workorder_id'					 => (isset($id) ? $id : ''),
			'value_other_branch'					 => (isset($project['other_branch']) ? $project['other_branch'] : ''),
			'value_descr'							 => $values['descr'],
			'value_remark'							 => (isset($values['remark']) ? $values['remark'] : ''),
			'cat_sub_list'							 => $this->bocommon->select_list($selected_cat, $cat_sub),
			'cat_sub_name'							 => 'values[cat_id]',
			'validatet_category'					 => $validatet_category,
			'sum_workorder_budget'					 => (isset($values['sum_workorder_budget']) ? $values['sum_workorder_budget'] : ''),
			'workorder_budget'						 => (isset($values['workorder_budget']) ? $values['workorder_budget'] : ''),
			'select_user_name'						 => 'values[coordinator]',
			'user_list'								 => array(
				'options' => $user_list
			),
			'status_list'							 => $this->bo->select_status_list('select', $values['status']),
			'status_name'							 => 'values[status]',
			'status_required'						 => true,
			'branch_list'							 => $boproject->select_branch_p_list($project['project_id']),
			'key_responsible_list'					 => $boproject->select_branch_list($project['key_responsible']),
			'key_fetch_list'						 => $this->bo->select_key_location_list((isset($values['key_fetch']) ? $values['key_fetch'] : '')),
			'key_deliver_list'						 => $this->bo->select_key_location_list((isset($values['key_deliver']) ? $values['key_deliver'] : '')),
			'value_approved'						 => isset($values['approved']) ? $values['approved'] : '',
			'value_continuous'						 => isset($values['continuous']) ? $values['continuous'] : '',
			'value_fictive_periodization'			 => isset($values['fictive_periodization']) ? $values['fictive_periodization'] : '',
			'need_approval'							 => !empty($config->config_data['workorder_approval']),
			'currency'								 => $this->userSettings['preferences']['common']['currency'],
			'link_view_file'						 => phpgw::link('/property/workorder/files/view'),
			'link_to_files'							 => (isset($config->config_data['files_url']) ? $config->config_data['files_url'] : ''),
			'files'									 => isset($values['files']) ? $values['files'] : '',
			'value_billable_hours'					 => $values['billable_hours'],
			'base_java_url'							 => "{menuaction:'property.bocommon.get_vendor_email',phpgw_return_as:'json'}",
			'location_item_id'						 => $id,
			'edit_action'							 => phpgw::link('/index.php', array(
				'menuaction' => 'property.uiworkorder.edit',
				'id'		 => $id
			)),
			'value_extra_mail_address'				 => $value_extra_mail_address,
			'lean'									 => $_lean ? 1 : 0,
			'decimal_separator'						 => $this->decimal_separator,
			'value_service_id'						 => $values['service_id'],
			'value_service_name'					 => $this->_get_eco_service_name($values['service_id']),
			'collect_tax_code'						 => !empty($config->config_data['workorder_require_tax_code']),
			'tax_code_list'							 => array('options' => $this->bocommon->select_category_list(
				array(
					'type' => 'tax',
					'selected'	 => (!empty($values['tax_code']) || $values['tax_code'] === 0) ? $values['tax_code'] : $default_tax_code,
					'order'		 => 'id',
					'id_in_name' => 'num'
				)
			)),
			'contract_list'							 => array('options' => $this->get_vendor_contract($values['vendor_id'], $values['contract_id'])),
			'value_unspsc_code'						 => $unspsc_code,
			'value_unspsc_code_name'				 => $this->_get_unspsc_code_name($unspsc_code),
			'collect_building_part'					 => $collect_building_part,
			'building_part_list'					 => $building_part_list,
			'order_dim1_list'						 => $order_dim1_list,
			'value_order_sent'						 => !!$values['order_sent'],
			'value_order_received'					 => $values['order_received'] ? $this->phpgwapi_common->show_date($values['order_received']) : '[ DD/MM/YYYY - H:i ]',
			'value_order_received_amount'			 => (int)$values['order_received_amount'],
			'value_delivery_address'				 => $delivery_address,
			'multiple_uploader'						 => true,
			'multi_upload_action' => phpgw::link('/property/workorder/' . (int)$id . '/multi-upload'),
			'image_list'							 => $image_list,
			'tag_list'							 => array('options' => $bofiles->get_all_tags())
		);

		$appname = lang('Workorder');

		$this->flags['app_header'] = lang('property') . ' - ' . $appname . ': ' . $function_msg;
		Settings::getInstance()->update('flags', ['app_header' => $this->flags['app_header']]);


		phpgwapi_jquery::formvalidator_generate(array('date', 'security', 'file'));
		phpgwapi_jquery::load_widget('core');
		phpgwapi_jquery::load_widget('select2');
		phpgwapi_jquery::load_widget('glider');
		phpgwapi_jquery::load_widget('numberformat');
		phpgwapi_jquery::load_widget('file-upload-minimum');

		self::add_javascript('property', 'base', 'workorder.edit.js');

		self::render_template_xsl(array(
			'workorder',
			'datatable_inline',
			'multi_upload_file_inline',
			'cat_sub_select'
		), array(
			'edit' => $data
		));
	}

	private function _resolve_workorder_form_action($mode, $id)
	{
		return $mode == 'edit'
			? ($id
				? phpgw::link('/property/workorder/' . (int)$id, array())
				: phpgw::link('/property/workorder/create', array()))
			: phpgw::link('/home/');
	}

	private function _apply_default_workorder_status(array &$values)
	{
		$workorder_status = isset($this->userSettings['preferences']['property']['workorder_status'])
			? $this->userSettings['preferences']['property']['workorder_status']
			: '';

		if (!$values['status'])
		{
			$values['status'] = $workorder_status;
		}
	}

	private function _add_workorder_date_listeners(array $project)
	{
		$this->jqcal->add_listener(
			'values_start_date',
			'date',
			'',
			array(
				'min_date' => date('F j, Y, g:i a', phpgwapi_datetime::date_to_timestamp($project['start_date'])),
				//			'max_date' => date('F j, Y, g:i a', phpgwapi_datetime::date_to_timestamp($project['end_date'])),
			)
		);

		$this->jqcal->add_listener(
			'values_end_date',
			'date',
			'',
			array(
				'min_date' => date("F j, Y, g:i a", phpgwapi_datetime::date_to_timestamp($project['start_date'])),
				//			'max_date' => date("F j, Y, g:i a", phpgwapi_datetime::date_to_timestamp($project['end_date'])),
			)
		);

		$this->jqcal->add_listener('values_tender_deadline');
		$this->jqcal->add_listener('values_tender_received');
		$this->jqcal->add_listener('values_inspection_on_completion');
	}

	private function _resolve_origin_details(array &$values, &$origin, &$origin_id)
	{
		if ($origin == '.ticket' && $origin_id && !$values['descr'])
		{
			$boticket		 = CreateObject('property.botts');
			$ticket			 = $boticket->read_single($origin_id);
			$values['descr'] = strip_tags($ticket['details']);
			$values['title'] = $ticket['subject'] ? $ticket['subject'] : $ticket['category_name'];
			$ticket_notes	 = $boticket->read_additional_notes($origin_id);
			$i				 = count($ticket_notes) - 1;
			if (isset($ticket_notes[$i]['value_note']) && $ticket_notes[$i]['value_note'])
			{
				$values['descr'] .= ": " . $ticket_notes[$i]['value_note'];
			}

			$values['location_data'] = $ticket['location_data'];
		}
		else if ($origin && preg_match("/(^.entity.|^.catch.)/i", $origin) && $origin_id)
		{
			$_origin				 = explode('.', $origin);
			$_boentity				 = CreateObject('property.boentity', false, $_origin[1], $_origin[2], $_origin[3]);
			$_entity				 = $_boentity->read_single(array(
				'entity_id'	 => $_origin[2],
				'cat_id'	 => $_origin[3],
				'id'		 => $origin_id,
				'view'		 => true
			));
			$values['location_data'] = $_entity['location_data'];
			unset($_origin);
			unset($_boentity);
			unset($_entity);
		}
		else if ($origin == '.project.request' && $origin_id)
		{
			$_borequest				 = CreateObject('property.borequest', false);
			$_request				 = $_borequest->read_single($origin_id, array(), true);
			$values['descr']		 = $_request['descr'];
			$values['title']		 = $_request['title'];
			$values['location_data'] = $_request['location_data'];
			unset($_origin);
			unset($_borequest);
			unset($_request);
		}

		if (isset($values['origin']) && $values['origin'])
		{
			$origin		 = $values['origin'];
			$origin_id	 = $values['origin_id'];
		}

		$interlink = &$this->bo->interlink;
		if (isset($origin) && $origin)
		{
			$values['origin_data'][0]['location']	 = $origin;
			$values['origin_data'][0]['descr']		 = $interlink->get_location_name($origin);
			$values['origin_data'][0]['data'][]		 = array(
				'id'	 => $origin_id,
				'link'	 => $interlink->get_relation_link(array(
					'location' => $origin
				), $origin_id),
			);
		}
	}

	private function _apply_project_defaults_to_values(array &$values, array $project, $config)
	{
		if ($project['key_fetch'] && !$values['key_fetch'])
		{
			$values['key_fetch'] = $project['key_fetch'];
		}

		if ($project['key_deliver'] && !$values['key_deliver'])
		{
			$values['key_deliver'] = $project['key_deliver'];
		}

		if ($project['start_date'] && !$values['start_date'])
		{
			$_start_date = max(time(), phpgwapi_datetime::date_to_timestamp($project['start_date']));
			$values['start_date'] = $this->phpgwapi_common->show_date($_start_date, $this->userSettings['preferences']['common']['dateformat']);
			if ($project['project_type_id'] == 1) //operation
			{
				phpgw::import_class('phpgwapi.datetime');
				if ($project['end_date'] && phpgwapi_datetime::date_to_timestamp($project['end_date']) < time())
				{
					$values['start_date'] = $project['end_date'];
				}
			}
		}

		$last_day_of_year = mktime(13, 0, 0, 12, 31, date("Y"));

		if ($project['end_date'] && !$values['end_date'])
		{
			if ($project['project_type_id'] == 1 && isset($config->config_data['delay_operation_workorder_end_date']) && $config->config_data['delay_operation_workorder_end_date'] == 1) //operation
			{
				$values['end_date'] = $this->phpgwapi_common->show_date($last_day_of_year, $this->userSettings['preferences']['common']['dateformat']);
			}
			else
			{
				$values['end_date'] = $project['end_date'];
			}
		}
		else if (!$project['end_date'] && !$values['end_date'])
		{
			if ($project['project_type_id'] == 1 && isset($config->config_data['delay_operation_workorder_end_date']) && $config->config_data['delay_operation_workorder_end_date'] == 1) //operation
			{
				$values['end_date'] = $this->phpgwapi_common->show_date($last_day_of_year, $this->userSettings['preferences']['common']['dateformat']);
			}
			else
			{
				$values['end_date'] = $this->phpgwapi_common->show_date(time(), $this->userSettings['preferences']['common']['dateformat']);
			}
		}

		if ($project['name'] && !isset($values['title']))
		{
			$values['title'] = $project['name'];
		}
		if ($project['descr'] && !isset($values['descr']))
		{
			$values['descr'] = $project['descr'];
		}
	}

	private function _append_history_datatable(array &$datatable_def, array $record_history)
	{
		$history_def = array(
			array(
				'key'		 => 'number',
				'label'		 => '#',
				'sortable'	 => true,
				'resizeable' => true
			),
			array(
				'key'		 => 'value_date',
				'label'		 => lang('Date'),
				'sortable'	 => false,
				'resizeable' => true
			),
			array(
				'key'		 => 'value_user',
				'label'		 => lang('User'),
				'sortable'	 => false,
				'resizeable' => true
			),
			array(
				'key'		 => 'value_action',
				'label'		 => lang('Action'),
				'sortable'	 => true,
				'resizeable' => true
			),
			array(
				'key'		 => 'value_old_value',
				'label'		 => lang('old value'),
				'sortable'	 => false,
				'resizeable' => true
			),
			array(
				'key'		 => 'value_new_value',
				'label'		 => lang('New Value'),
				'sortable'	 => false,
				'resizeable' => true
			)
		);

		$datatable_def[] = array(
			'container'	 => 'datatable-container_0',
			'requestUrl' => "''",
			'data'		 => json_encode($record_history),
			'ColumnDefs' => $history_def,
			'config'	 => array(
				array(
					'disableFilter' => true
				),
				array(
					'disablePagination' => true
				)
			)
		);
	}

	private function _initialize_location_context(array &$values, array $project, $mode, $bolocation, $config)
	{
		if (isset($config->config_data['location_at_workorder']) && $config->config_data['location_at_workorder'])
		{
			$admin_location	 = &$bolocation->soadmin_location;
			$location_types	 = $admin_location->select_location_type();
			$max_level		 = count($location_types);

			$location_level			 = isset($project['location_data']['location_code']) && $project['inherit_location'] ? count(explode('-', $project['location_data']['location_code'])) : 0;
			$location_template_type	 = 'form';
			$_location_data			 = array();

			if (!$values['location_data'] && ($values['location_code'] || $values['location']))
			{
				$location_code			 = isset($values['location_code']) && $values['location_code'] ? $values['location_code'] : implode("-", $values['location']);
				$values['extra']['view'] = true;
				$values['location_data'] = $bolocation->read_single($location_code, $values['extra']);
			}

			if ($values['location_data'])
			{
				$_location_data = $values['location_data'];
			}
			else if (isset($values['location']) && is_array($values['location']))
			{
				$location_code			 = implode("-", $values['location']);
				$values['extra']['view'] = true;
				$_location_data			 = $bolocation->read_single($location_code, $values['extra']);
			}
			else
			{
				if (isset($project['location_data']) && $project['location_data'] && $project['inherit_location'])
				{
					$_location_data = $project['location_data'];
				}
			}

			if ($mode == 'view')
			{
				$location_template_type = 'view';
			}

			$location_data = $bolocation->initiate_ui_location(array(
				'values'			 => $_location_data,
				'type_id'			 => $mode == 'edit' ? $max_level : count(explode('-', $_location_data['location_code'])),
				'no_link'			 => false, // disable lookup links for location type less than type_id
				'tenant'			 => true,
				'block_parent'		 => $location_level,
				'lookup_type'		 => $location_template_type,
				'lookup_entity'		 => $this->bocommon->get_lookup_entity('project'),
				'entity_data'		 => (isset($values['p']) ? $values['p'] : ''),
				'filter_location'	 => $project['inherit_location'] ? $project['location_data']['location_code'] : false,
				'required_level'	 => 1
			));
		}
		else
		{
			$location_template_type	 = 'view';
			$_location_data			 = !empty($project['location_data']) ? $project['location_data'] : '';
			$location_data			 = $bolocation->initiate_ui_location(array(
				'values'		 => $_location_data,
				'type_id'		 => (isset($project['location_data']['location_code']) ? count(explode('-', $project['location_data']['location_code'])) : ''),
				'no_link'		 => false, // disable lookup links for location type less than type_id
				'tenant'		 => (isset($project['location_data']['tenant_id']) ? $project['location_data']['tenant_id'] : ''),
				'lookup_type'	 => 'view'
			));
		}

		if (!empty($project['contact_phone']) || !empty($values['contact_phone']))
		{
			for ($i = 0; $i < count($location_data['location']); $i++)
			{
				if ($location_data['location'][$i]['input_name'] == 'contact_phone')
				{
					$location_data['location'][$i]['value'] = $values['contact_phone'] ? $values['contact_phone'] : $project['contact_phone'];
				}
			}
		}

		return array(
			'location_data' => $location_data,
			'location_template_type' => $location_template_type,
			'_location_data' => $_location_data
		);
	}

	private function _initialize_vendor_budget_context(array $values, array $project, $mode, $config)
	{
		$vendor_data = $this->bocommon->initiate_ui_vendorlookup(array(
			'vendor_id'		 => $values['vendor_id'],
			'vendor_name'	 => $values['vendor_name'],
			'type'			 => $mode,
			'required'		 => isset($config->config_data['workorder_require_vendor']) && $config->config_data['workorder_require_vendor'] == 1
		));

		$b_group_data = $this->bocommon->initiate_ui_budget_account_lookup(array(
			'b_account_id'	 => $project['b_account_group'],
			'role'			 => 'group',
			'type'			 => $mode
		));

		$b_account_data = $this->bocommon->initiate_ui_budget_account_lookup(array(
			'b_account_id'	 => $values['b_account_id'] ? $values['b_account_id'] : $project['b_account_id'],
			//				'b_account_name' => $values['b_account_name'],
			'disabled'		 => '',
			'parent'		 => $project['b_account_group'],
			'type'			 => $mode,
			'required'		 => true
		));

		$b_account_list_favorite = ExecMethod('property.sob_account_user.get_favorite', $this->account);

		if ($b_account_list_favorite)
		{
			$b_account_list = $b_account_list_favorite;
		}
		else
		{
			$b_account_list = execMethod('property.bogeneric.get_list', array(
				'type'		 => 'budget_account',
				'selected'	 => $values['b_account_id'] ? $values['b_account_id'] : $project['b_account_id'],
				'add_empty'	 => true,
				'filter'	 => array('active' => 1)
			));
		}

		$_b_account_found = false;
		foreach ($b_account_list as &$entry)
		{
			$entry['name'] = "{$entry['id']} {$entry['name']}";
			if (!empty($b_account_data['value_b_account_id']) && $b_account_data['value_b_account_id'] == $entry['id'])
			{
				$_b_account_found = true;
			}
		}
		if (!empty($b_account_data['value_b_account_id']) && !$_b_account_found)
		{
			array_unshift($b_account_list, array(
				'id'	 => $b_account_data['value_b_account_id'],
				'name'	 => "{$b_account_data['value_b_account_id']} {$b_account_data['value_b_account_name']}"
			));
		}

		unset($entry);

		$ecodimb_data = $this->bocommon->initiate_ecodimb_lookup(
			array(
				'ecodimb'		 => $values['ecodimb'] ? $values['ecodimb'] : $project['ecodimb'],
				'ecodimb_descr'	 => $values['ecodimb_descr'],
				'disabled'		 => $project['ecodimb'] || $mode == 'view'
			)
		);

		return array(
			'vendor_data' => $vendor_data,
			'b_group_data' => $b_group_data,
			'b_account_data' => $b_account_data,
			'b_account_list' => $b_account_list,
			'ecodimb_data' => $ecodimb_data
		);
	}

	function add()
	{
		if (!$this->acl_edit)
		{
			phpgw::redirect_link('/index.php', array(
				'menuaction'	 => 'property.uilocation.stop',
				'perm'			 => 2,
				'acl_location'	 => $this->acl_location
			));
		}

		$link_data = array(
			'menuaction' => 'property.uiworkorder.index'
		);

		phpgwapi_xslttemplates::getInstance()->add_file(array(
			'workorder',
			'search_field'
		));

		$data = array(
			'done_action'			 => phpgw::link('/index.php', $link_data),
			'add_action'			 => phpgw::link('/index.php', array(
				'menuaction' => 'property.uiproject.edit'
			)),
			'search_action'			 => phpgw::link('/index.php', array(
				'menuaction' => 'property.uiproject.index',
				'lookup'	 => true,
				'from'		 => 'workorder'
			)),
			'lang_done_statustext'	 => lang('Back to the workorder list'),
			'lang_add_statustext'	 => lang('Adds a new project - then a new workorder'),
			'lang_search_statustext' => lang('Adds a new workorder to an existing project'),
			'lang_done'				 => lang('Done'),
			'lang_add'				 => lang('Add'),
			'lang_search'			 => lang('Search')
		);

		$appname		 = lang('Workorder');
		$function_msg	 = lang('Add workorder');

		$this->flags['app_header'] = lang('property') . ' - ' . $appname . ': ' . $function_msg;
		Settings::getInstance()->update('flags', ['app_header' => $this->flags['app_header']]);
		phpgwapi_xslttemplates::getInstance()->set_var('phpgw', array(
			'add' => $data
		));
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

	function add_invoice()
	{
		if (!$this->acl_add)
		{
			$this->flags['xslt_app'] = true;
			Settings::getInstance()->update('flags', ['xslt_app' => true]);
			phpgw::no_access();
		}

		$order_id = Sanitizer::get_var('order_id');

		$receipt = array();

		$bolocation	 = CreateObject('property.bolocation');
		$boinvoice	 = CreateObject('property.boinvoice');

		$referer = parse_url(Sanitizer::get_var('HTTP_REFERER', 'url', 'SERVER'));
		parse_str($referer['query'], $output); // produce $menuaction
		if (Sanitizer::get_var('cancel', 'bool'))
		{
			$redirect = true;
		}

		if ($add_invoice = Sanitizer::get_var('add', 'bool'))
		{
			$values = Sanitizer::get_var('values');

			if (isset($this->userSettings['preferences']['common']['currency']))
			{
				$values['amount'] = str_ireplace($this->userSettings['preferences']['common']['currency'], '', $values['amount']);
			}
			$values['amount'] = str_replace(array(' ', ','), array('', '.'), $values['amount']);

			$values['b_account_id']			 = Sanitizer::get_var('b_account_id');
			$values['external_project_id']	 = Sanitizer::get_var('external_project_id');
			$values['dimb']					 = Sanitizer::get_var('ecodimb');
			$values['vendor_id']			 = Sanitizer::get_var('vendor_id');
		}


		if ($add_invoice && is_array($values))
		{
			if ($values['order_id'] && !ctype_digit($values['order_id']))
			{
				$receipt['error'][] = array(
					'msg' => lang('Please enter an integer for order!')
				);
				unset($values['order_id']);
			}

			if (!execMethod('property.soXport.check_order', $values['order_id']))
			{
				$receipt['error'][] = array(
					'msg' => lang('Not a valid order!')
				);
			}

			if (!$values['amount'])
			{
				$receipt['error'][] = array(
					'msg' => lang('Please - enter an amount!')
				);
			}
			if (!$values['artid'])
			{
				$receipt['error'][] = array(
					'msg' => lang('Please - select type invoice!')
				);
			}

			if ($values['artid'] == 2)
			{
				$values['amount'] = abs((float)$values['amount']) * -1;
			}

			if ($values['vendor_id'] == 99)
			{
				$values['invoice_id'] = $boinvoice->get_auto_generated_invoice_num($values['vendor_id']);
			}
			else if (!$values['vendor_id'])
			{
				$receipt['error'][] = array(
					'msg' => lang('Please - select Vendor!')
				);
			}
			else if (!$boinvoice->check_vendor($values['vendor_id']))
			{
				$receipt['error'][] = array(
					'msg' => lang('That Vendor ID is not valid !') . ' : ' . $values['vendor_id']
				);
			}

			if (!$values['typeid'])
			{
				$values['typeid'] = 1;
				//					$receipt['error'][] = array(
				//						'msg' => lang('Please - select type order!'));
			}

			if (!$values['budget_responsible'])
			{
				$receipt['error'][] = array(
					'msg' => lang('Please - select budget responsible!')
				);
			}

			if (!$values['voucher_out_id'])
			{
				$receipt['error'][] = array(
					'msg' => lang('enter invoice number')
				);
			}

			if (!$values['invoice_id'])
			{
				$receipt['error'][] = array(
					'msg' => lang('please enter a invoice num!')
				);
			}

			if (!$values['payment_date'] && !$values['num_days'])
			{
				$receipt['error'][] = array(
					'msg' => lang('Please - select either payment date or number of days from invoice date !')
				);
			}

			//_debug_array($values);
			if (!is_array($receipt['error']))
			{
				$values['regtid'] = date(Db::getInstance()->datetime_format());

				$_receipt	 = array(); //local errors

				$values['external_voucher_id'] = $values['voucher_out_id'];
				$receipt	 = $boinvoice->add_manual_invoice($values);

				if (!isset($receipt['error'])) // all ok
				{
					$file_name = @str_replace(' ', '_', $_FILES['file']['name']);

					if ($file_name)
					{

						$config = CreateObject('admin.soconfig', $this->locations->get_id('property', '.invoice'));

						$directory_local		 = rtrim($config->config_data['import']['local_path'], '/');
						$directory_attachment	 = "{$directory_local}/attachment/{$values['voucher_out_id']}";

						if ($directory_local)
						{
							if (!$this->check_storage_dir($directory_attachment))
							{
								$this->create_storage_dir($directory_attachment);
							}

							copy($_FILES['file']['tmp_name'], "{$directory_attachment}/{$file_name}");
						}
						else
						{

							$bofiles = CreateObject('property.bofiles');
							$to_file = $bofiles->fakebase . '/workorder/' . $order_id . '/' . $file_name;

							if ($bofiles->vfs->file_exists(array(
								'string'	 => $to_file,
								'relatives'	 => array(
									RELATIVE_NONE
								)
							)))
							{
								$this->receipt['error'][] = array(
									'msg' => lang('This file already exists !')
								);
							}
							else
							{
								$bofiles->create_document_dir("workorder/{$order_id}");
								$bofiles->vfs->override_acl = 1;

								if (!$bofiles->vfs->cp(array(
									'from'		 => $_FILES['file']['tmp_name'],
									'to'		 => $to_file,
									'relatives'	 => array(
										RELATIVE_NONE | VFS_REAL,
										RELATIVE_ALL
									)
								)))
								{
									$this->receipt['error'][] = array(
										'msg' => lang('Failed to upload file !')
									);
								}
								$bofiles->vfs->override_acl = 0;
							}
						}
					}

					execMethod('property.soXport.update_actual_cost_from_archive', array(
						$values['order_id'] => true
					));
					$redirect = true;
				}
			}
		}

		if ($workorder = $this->bo->read_single($values['order_id'] ? $values['order_id'] : $order_id))
		{
			$project = execMethod('property.boproject.read_single_mini', $workorder['project_id']);

			if (!$add_invoice && !$redirect)
			{
				$_criteria						 = array(
					'dimb' => $workorder['ecodimb']
				);
				$_responsible					 = $boinvoice->set_responsible($_criteria, $workorder['user_id'], $workorder['b_account_id'] ? $workorder['b_account_id'] : $values['b_account_id']);
				$values['janitor']				 = $_responsible['janitor'];
				$values['supervisor']			 = $_responsible['supervisor'];
				$values['budget_responsible']	 = $_responsible['budget_responsible'];
			}
		}

		if (isset($values['location_data']) && $values['location_data'])
		{
			$location_code = $values['location_data']['location_code'];
		}
		else if (isset($workorder['location_data']) && $workorder['location_data'])
		{
			$location_code = $workorder['location_data']['location_code'];;
		}
		else if (isset($project['location_data']) && $project['location_data'])
		{
			$location_code = $project['location_data']['location_code'];;
		}
		else
		{
			$location_code = '';
		}

		$b_account_data = $this->bocommon->initiate_ui_budget_account_lookup(
			array(
				'b_account_id'	 => isset($values['b_account_id']) && $values['b_account_id'] ? $values['b_account_id'] : $workorder['b_account_id'],
				'b_account_name' => isset($values['b_account_name']) ? $values['b_account_name'] : ''
			)
		);

		$b_account_list_favorite = ExecMethod('property.sob_account_user.get_favorite', $this->account);

		if ($b_account_list_favorite)
		{
			$b_account_list = $b_account_list_favorite;
		}
		else
		{
			$b_account_list = execMethod('property.bogeneric.get_list', array(
				'type'		 => 'budget_account',
				'selected'	 => $values['b_account_id'] ? $values['b_account_id'] : $project['b_account_id'],
				'add_empty'	 => true,
				'filter'	 => array('active' => 1)
			));
		}

		$_b_account_found = false;
		foreach ($b_account_list as &$entry)
		{
			$entry['name'] = "{$entry['id']} {$entry['name']}";
			if (!empty($b_account_data['value_b_account_id']) && $b_account_data['value_b_account_id'] == $entry['id'])
			{
				$_b_account_found = true;
			}
		}
		if (!empty($b_account_data['value_b_account_id']) && !$_b_account_found)
		{
			array_unshift($b_account_list, array(
				'id'	 => $b_account_data['value_b_account_id'],
				'name'	 => "{$b_account_data['value_b_account_id']} {$b_account_data['value_b_account_name']}"
			));
		}

		$vendor_data = $this->bocommon->initiate_ui_vendorlookup(array(
			'vendor_id'		 => $values['vendor_id'] ? $values['vendor_id'] : $workorder['vendor_id'],
			'vendor_name'	 => $values['vendor_name'],
			'type'			 => 'edit'
		));


		$ecodimb_data = $this->bocommon->initiate_ecodimb_lookup(
			array(
				'ecodimb'		 => $values['ecodimb'] ? $values['ecodimb'] : $workorder['ecodimb'],
				'ecodimb_descr'	 => $values['ecodimb_descr']
			)
		);


		$link_data = array(
			'menuaction' => 'property.uiworkorder.add_invoice'
		);

		if ($_receipt)
		{
			$receipt = array_merge($receipt, $_receipt);
		}
		$msgbox_data = $this->bocommon->msgbox_data($receipt);


		$this->jqcal->add_listener('invoice_date');
		$this->jqcal->add_listener('payment_date');
		$this->jqcal->add_listener('paid_date');

		$order_id = isset($values['order_id']) && $values['order_id'] ? $values['order_id'] : $order_id;

		$account_lid = $this->accounts_obj->get($this->account)->lid;
		$data		 = array(
			'msgbox_data'				 => $this->phpgwapi_common->msgbox($msgbox_data),
			'form_action'				 => phpgw::link('/index.php', $link_data),
			'cancel_action'				 => phpgw::link('/index.php', array(
				'menuaction' => 'property.uiinvoice.index'
			)),
			'action_url'				 => phpgw::link('/index.php', array(
				'menuaction' => 'property' . '.uiinvoice.add'
			)),
			'value_invoice_date'		 => isset($values['invoice_date']) ? $values['invoice_date'] : '',
			'value_payment_date'		 => isset($values['payment_date']) ? $values['payment_date'] : '',
			'value_paid_date'			 => isset($values['paid_date']) ? $values['paid_date'] : '',
			'vendor_data'				 => $vendor_data,
			'ecodimb_data'				 => $ecodimb_data,
			'value_external_project_id'			 => $values['external_project_id'] ? $values['external_project_id'] : $project['external_project_id'],
			'value_external_project_name'		 => $this->bocommon->get_external_project_name($values['external_project_id'] ? $values['external_project_id'] : $project['external_project_id']),
			'value_service_id'			 => $values['service_id'],
			'value_service_name'		 => $this->_get_eco_service_name($values['service_id']),
			'tax_code_list'				 => array('options' => $this->bocommon->select_category_list(array(
				'type'		 => 'tax',
				'selected'	 => $values['tax_code'],
				'order'		 => 'id',
				'id_in_name' => 'num'
			))),
			'contract_list'				 => array('options' => $this->get_vendor_contract($workorder['vendor_id'], $workorder['contract_id'])),
			'value_unspsc_code'			 => $values['unspsc_code'],
			'value_unspsc_code_name'	 => $this->_get_unspsc_code_name($values['unspsc_code']),
			'value_kidnr'				 => isset($values['kidnr']) ? $values['kidnr'] : '',
			'value_invoice_id'			 => isset($values['invoice_id']) ? $values['invoice_id'] : '',
			'value_voucher_out_id'		 => isset($values['voucher_out_id']) ? $values['voucher_out_id'] : '',
			'value_merknad'				 => isset($values['merknad']) ? $values['merknad'] : '',
			'value_num_days'			 => isset($values['num_days']) ? $values['num_days'] : '',
			'value_amount'				 => isset($values['amount']) ? $values['amount'] : '',
			'value_order_id'			 => $order_id,
			'art_list'					 => array(
				'options' => $boinvoice->get_lisfm_ecoart(isset($values['artid']) ? $values['artid'] : '')
			),
			'type_list'					 => array(
				'options' => $boinvoice->get_type_list(isset($values['typeid']) ? $values['typeid'] : '')
			),
			'janitor_list'				 => array(
				'options_lid' => $this->bocommon->get_user_list_right(32, isset($values['janitor']) && $values['janitor'] ? $values['janitor'] : $account_lid, '.invoice')
			),
			'supervisor_list'			 => array(
				'options_lid' => $this->bocommon->get_user_list_right(64, isset($values['supervisor']) && $values['supervisor'] ? $values['supervisor'] : $account_lid, '.invoice')
			),
			'budget_responsible_list'	 => array(
				'options_lid' => $this->bocommon->get_user_list_right(128, isset($values['budget_responsible']) && $values['budget_responsible'] ? $values['budget_responsible'] : $account_lid, '.invoice')
			),
			'location_code'				 => $location_code,
			'b_account_data'			 => $b_account_data,
			'b_account_list'			 => array('options' => $b_account_list),
			'b_account_as_listbox'		 => $this->userSettings['preferences']['property']['b_account_as_listbox'],
			'redirect'					 => !empty($redirect) ? phpgw::link('/index.php', array(
				'menuaction' => 'property.uiworkorder.edit',
				'id'		 => $order_id,
				'active_tab' => 'budget'
			)) : null,
		);

		self::add_javascript('property', 'base', 'workorder.add_invoice.js');
		phpgwapi_jquery::formvalidator_generate(array('date', 'security', 'file'));
		phpgwapi_xslttemplates::getInstance()->add_file(array('workorder'));
		$this->flags['noframework'] = true;
		Settings::getInstance()->update('flags', ['noframework' => true]);
		phpgwapi_xslttemplates::getInstance()->set_var('phpgw', array('add_invoice' => $data));
	}

	protected function check_storage_dir($files_path)
	{
		if (is_dir($files_path) && is_writable($files_path) && is_readable($files_path))
		{
			return true;
		}
	}

	protected function create_storage_dir($files_path)
	{
		$dirMode = 0777;
		if (!mkdir($files_path, $dirMode, true))
		{
			// failed to create the directory
			throw new Exception(sprintf('Failed to create file storage "%s".', $files_path));
		}

		chmod($files_path, $dirMode);
		return true;
	}

	function recalculate()
	{
		if (!$this->acl->check('run', ACL_READ, 'admin') && !$this->acl->check('admin', ACL_ADD, 'property'))
		{
			phpgw::redirect_link('/index.php', array(
				'menuaction'	 => 'property.uilocation.stop',
				'perm'			 => 8,
				'acl_location'	 => $this->acl_location
			));
		}

		$confirm = Sanitizer::get_var('confirm', 'bool', 'POST');

		$link_data = array(
			'menuaction' => 'property.uiworkorder.index'
		);

		if (Sanitizer::get_var('confirm', 'bool', 'POST'))
		{
			$this->bo->recalculate();
			phpgw::redirect_link('/index.php', $link_data);
		}

		phpgwapi_xslttemplates::getInstance()->add_file(array(
			'app_delete'
		));

		$data = array(
			'done_action'			 => phpgw::link('/index.php', $link_data),
			'delete_action'			 => phpgw::link('/index.php', array(
				'menuaction' => 'property.uiworkorder.recalculate'
			)),
			'lang_confirm_msg'		 => lang('do you really want to recalculate all actual cost for all workorders'),
			'lang_yes'				 => lang('yes'),
			'lang_yes_statustext'	 => lang('recalculate'),
			'lang_no_statustext'	 => lang('Back to the list'),
			'lang_no'				 => lang('no')
		);

		$appname		 = lang('workorder');
		$function_msg	 = lang('delete workorder');

		$this->flags['app_header'] = lang('property') . ' - ' . $appname . ': ' . $function_msg;
		Settings::getInstance()->update('flags', ['app_header' => $this->flags['app_header']]);
		phpgwapi_xslttemplates::getInstance()->set_var('phpgw', array(
			'delete' => $data
		));
	}

	public function get_vendor_contract($vendor_id = 0, $selected = 0)
	{
		$contract_list	 = $this->bocommon->get_vendor_contract($vendor_id, $selected);
		$config			 = CreateObject('phpgwapi.config', 'property')->read();

		if ($contract_list || !empty($config['alternative_to_contract_1']))
		{
			if (!empty($config['alternative_to_contract_1']))
			{
				$contract_list[] = array('id' => -1, 'name' => $config['alternative_to_contract_1']);
			}
			else
			{
				$contract_list[] = array('id' => -1, 'name' => lang('outside contract'));
			}

			if (!empty($config['alternative_to_contract_2']))
			{
				$contract_list[] = array('id' => -2, 'name' => $config['alternative_to_contract_2']);
			}
			if (!empty($config['alternative_to_contract_3']))
			{
				$contract_list[] = array('id' => -3, 'name' => $config['alternative_to_contract_3']);
			}
			if (!empty($config['alternative_to_contract_4']))
			{
				$contract_list[] = array('id' => -4, 'name' => $config['alternative_to_contract_4']);
			}
		}

		if ($selected)
		{
			foreach ($contract_list as &$contract)
			{
				$contract['selected'] = $selected == $contract['id'] ? 1 : 0;
			}
		}
		return $contract_list;
	}



	private function _get_eco_service_name($id)
	{
		return $this->bocommon->get_eco_service_name($id);
	}

	private function _get_unspsc_code_name($id)
	{
		return $this->bocommon->get_unspsc_code_name($id);
	}

	protected function _generate_tabs($tabs_ = array(), $active_tab = 'general', $_disable = array())
	{
		$tabs	 = array(
			'general'		 => array(
				'label'	 => lang('general'),
				'link'	 => '#general'
			),
			'budget'		 => array(
				'label'	 => lang('Time and budget'),
				'link'	 => '#budget'
			),
			'coordination'	 => array(
				'label'	 => lang('coordination'),
				'link'	 => '#coordination'
			),
			'documents'		 => array(
				'label'	 => lang('documents'),
				'link'	 => '#documents'
			),
			'history'		 => array(
				'label'	 => lang('history'),
				'link'	 => '#history'
			),
		);
		$tabs	 = array_merge($tabs, $tabs_);

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
