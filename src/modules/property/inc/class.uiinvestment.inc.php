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
 * @subpackage eco
 * @version $Id$
 */

use App\modules\phpgwapi\services\Cache;
use App\modules\phpgwapi\services\Settings;

/**
 * Description
 * @package property
 */
phpgw::import_class('phpgwapi.uicommon_jquery');

class property_uiinvestment extends phpgwapi_uicommon_jquery
{

	var $grants;
	var $cat_id;
	var $start;
	var $query;
	var $sort;
	var $order;
	var $filter;
	var $part_of_town_id;
	var $currentapp;
	var $bo, $bocommon, $allrows, $user_id,
		$acl, $acl_location, $acl_read, $acl_add, $acl_edit, $acl_delete, $acl_manage, $admin_invoice,
		$custom, $account, $bolocation;

	var $public_functions = array(
		'query'			 => true,
		'index'			 => true,
		'history'		 => true,
		'get_history'	 => true,
		'add'			 => true,
		'delete'		 => true
	);

	function __construct()
	{
		parent::__construct();

		$this->flags['xslt_app']			 = true;
		$this->flags['menu_selection']	 = 'property::economy::investment';
		Settings::getInstance()->update('flags', ['menu_selection' => $this->flags['menu_selection'], 'xslt_app' => true]);

		$this->account = $this->userSettings['account_id'];

		$this->bo			 = CreateObject('property.boinvestment', true);
		$this->bocommon		 = CreateObject('property.bocommon');
		$this->bolocation	 = CreateObject('property.bolocation');
		$this->acl_location	 = '.invoice';
		$this->acl_read		 = $this->acl->check('.invoice', ACL_READ, 'property');
		$this->acl_add		 = $this->acl->check('.invoice', ACL_ADD, 'property');
		$this->acl_edit		 = $this->acl->check('.invoice', ACL_EDIT, 'property');
		$this->acl_delete	 = $this->acl->check('.invoice', ACL_DELETE, 'property');

		$this->start			 = $this->bo->start;
		$this->query			 = $this->bo->query;
		$this->sort				 = $this->bo->sort;
		$this->order			 = $this->bo->order;
		$this->filter			 = $this->bo->filter;
		$this->cat_id			 = $this->bo->cat_id;
		$this->part_of_town_id	 = $this->bo->part_of_town_id;
		$this->allrows			 = $this->bo->allrows;
		$this->admin_invoice	 = $this->acl->check('.invoice', 16, 'property');
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
			'part_of_town_id'	 => $this->part_of_town_id,
			'this->allrows'		 => $this->allrows
		);
		$this->bo->save_sessiondata($data);
	}

	private function _get_filters()
	{
		$values_combo_box	 = array();
		$combos				 = array();

		$values_combo_box[0] = $this->bo->select_category('select', $this->cat_id);
		$default_value		 = array('id' => '', 'name' => lang('no category'));
		array_unshift($values_combo_box[0], $default_value);
		$combos[]			 = array(
			'type'	 => 'filter',
			'name'	 => 'cat_id',
			'extra'	 => '',
			'text'	 => lang('Category'),
			'list'	 => $values_combo_box[0]
		);

		$values_combo_box[1] = $this->bocommon->select_part_of_town('', $this->part_of_town_id);
		$default_value		 = array('id' => '', 'name' => lang('Part of town'));
		array_unshift($values_combo_box[1], $default_value);
		$combos[]			 = array(
			'type'	 => 'filter',
			'name'	 => 'part_of_town_id',
			'extra'	 => '',
			'text'	 => lang('Part of Town'),
			'list'	 => $values_combo_box[1]
		);

		$values_combo_box[2] = $this->bo->filter('select', $this->filter);
		$default_value		 = array('id' => '', 'name' => lang('Show all'));
		array_unshift($values_combo_box[2], $default_value);
		$combos[]			 = array(
			'type'	 => 'filter',
			'name'	 => 'filter',
			'extra'	 => '',
			'text'	 => lang('Filter'),
			'list'	 => $values_combo_box[2]
		);

		return $combos;
	}

	public function query()
	{
		$search				 = Sanitizer::get_var('search');
		$order				 = Sanitizer::get_var('order');
		$draw				 = Sanitizer::get_var('draw', 'int');
		$columns			 = Sanitizer::get_var('columns');
		$export				 = Sanitizer::get_var('export', 'bool');
		$order[0]['column']	 = 2;
		$order[0]['dir']	 = "desc";

		$params = array(
			'start'				 => Sanitizer::get_var('start', 'int', 'REQUEST', 0),
			'results'			 => Sanitizer::get_var('length', 'int', 'REQUEST', 0),
			'query'				 => $search['value'],
			'order'				 => $columns[$order[0]['column']]['data'],
			'sort'				 => $order[0]['dir'],
			'filter'			 => $this->filter,
			'cat_id'			 => $this->cat_id,
			'part_of_town_id'	 => $this->part_of_town_id,
			'allrows'			 => Sanitizer::get_var('length', 'int') == -1 || $export
		);

		$investment_list = $this->bo->read($params);

		$dateformat						 = strtolower($this->userSettings['preferences']['common']['dateformat']);
		$sep							 = '/';
		$dlarr[strpos($dateformat, 'y')] = 'Y';
		$dlarr[strpos($dateformat, 'm')] = 'm';
		$dlarr[strpos($dateformat, 'd')] = 'd';
		ksort($dlarr);
		$dateformat						 = (implode($sep, $dlarr));

		$jqcal = createObject('phpgwapi.jqcal');
		$jqcal->add_listener('start_date');
		$counter			 = $sum_initial_value	 = $sum_value			 = 0;

		if (is_array($investment_list))
		{
			foreach ($investment_list as $investment)
			{
				$link_history	 = $check			 = "";
				if ($this->admin_invoice)
				{
					$link_history = "<a href=\"" . phpgw::link('/index.php', array(
						'menuaction'	 => 'property.uiinvestment.history', 'entity_id'		 => $investment['entity_id'],
						'investment_id'	 => $investment['investment_id'], 'entity_type'	 => $this->cat_id
					)) . "\">" . lang('History') . "</a>";
					if ($investment['value'] != 0)
					{
						//$check = "<input counter=\"".$counter."\" type=\"hidden\" name=\"values[update][".$counter."]\" class=\"myValuesForPHP select_hidden\"  />";
						$check = "<input type=\"checkbox\" name=\"values[update_tmp][" . $counter . "]\" value=\"" . $counter . "\" class=\"mychecks select_check\"  id=\"check\" />";
					}
				}

				$content[] = array(
					'order_dummy'		 => $investment['part_of_town'],
					'district_id'		 => $investment['district_id'],
					'part_of_town'		 => $investment['part_of_town'],
					'entity_id'			 => $investment['entity_id'],
					'investment_id'		 => $investment['investment_id'],
					'descr'				 => $investment['descr'],
					'entity_name'		 => $investment['entity_name'],
					'initial_value_ex'	 => ($investment['initial_value'] == "" ? 0 : $investment['initial_value']),
					'initial_value'		 => number_format((float)$investment['initial_value'], 0, ',', ''),
					'value_ex'			 => ($investment['value'] == "" ? 0 : $investment['value']),
					'value'				 => number_format((float)$investment['value'], 0, ',', ''),
					'this_index'		 => $investment['this_index'],
					'this_write_off_ex'	 => $investment['this_write_off'],
					'this_write_off'	 => number_format((float)$investment['this_write_off'], 0, ',', ''),
					'date'				 => date($dateformat, strtotime($investment['date'])),
					'index_count'		 => $investment['index_count'],
					'link_history'		 => $link_history,
					'check'				 => $check,
					'counter'			 => $counter
				);
				$counter++;
			}
		}

		if ($export)
		{
			return $content;
		}


		$result_data					 = array('results' => $content);
		$result_data['total_records']	 = (!empty($this->bo->total_records)) ? $this->bo->total_records : 0;
		$result_data['draw']			 = $draw;
		$result_data['sum_budget']		 = number_format((float)$this->bo->sum_budget_cost, 0, ',', ' ');

		return $this->jquery_results($result_data);
	}

	function index()
	{
		if (!$this->acl_read)
		{
			phpgw::redirect_link('/index.php', array(
				'menuaction'	 => 'property.uilocation.stop',
				'perm'			 => 1, 'acl_location'	 => $this->acl_location
			));
		}

		$preserve	 = Sanitizer::get_var('preserve', 'bool');
		$values		 = Sanitizer::get_var('values');
		$msgbox_data = "";

		if ($preserve)
		{
			$this->bo->read_sessiondata();

			$this->start			 = $this->bo->start;
			$this->query			 = $this->bo->query;
			$this->sort				 = $this->bo->sort;
			$this->order			 = $this->bo->order;
			$this->filter			 = $this->bo->filter;
			$this->cat_id			 = $this->bo->cat_id;
			$this->part_of_town_id	 = $this->bo->part_of_town_id;
			$this->allrows			 = $this->bo->allrows;
		}

		if ($values && Sanitizer::get_var('phpgw_return_as') == 'json')
		{
			return $this->update_investment($values);
		}

		if (Sanitizer::get_var('phpgw_return_as') == 'json')
		{
			return $this->query();
		}


		$jqcal = createObject('phpgwapi.jqcal');
		$jqcal->add_listener('filter_start_date');
		$jqcal->add_listener('filter_end_date');
		phpgwapi_jquery::load_widget('datepicker');

		$this->flags['app_header'] = lang('property') . ' - ' . lang('investment') . ': ' . lang('list investment');
		Settings::getInstance()->update('flags', ['app_header' => $this->flags['app_header']]);


		$data = array(
			'datatable_name' => lang('list investment'),
			'form'			 => array(
				'toolbar' => array(
					'item' => array()
				)
			),
			'datatable'		 => array(
				'source'		 => self::link(array(
					'menuaction'		 => 'property.uiinvestment.index',
					'phpgw_return_as'	 => 'json'
				)),
				'new_item'		 => self::link(array(
					'menuaction' => 'property.uiinvestment.add'
				)),
				'allrows'		 => true,
				'editor_action'	 => '',
				'field'			 => array(
					array(
						'key'		 => 'order_dummy', 'label'		 => lang('Order dummy'), 'sortable'	 => false,
						'hidden'	 => TRUE, 'className'	 => 'center'
					),
					array(
						'key'		 => 'district_id', 'label'		 => lang('District'), 'sortable'	 => false,
						'hidden'	 => FALSE, 'className'	 => 'center'
					),
					array(
						'key'		 => 'part_of_town', 'label'		 => lang('Part of town'), 'sortable'	 => false,
						'hidden'	 => FALSE
					),
					array(
						'key'		 => 'entity_id', 'label'		 => lang('entity id'), 'sortable'	 => false,
						'hidden'	 => FALSE, 'className'	 => 'center'
					),
					array(
						'key'		 => 'investment_id', 'label'		 => lang('investment id'), 'sortable'	 => false,
						'hidden'	 => FALSE, 'className'	 => 'center'
					),
					array('key' => 'descr', 'label' => lang('Descr'), 'sortable' => false, 'hidden' => FALSE),
					array(
						'key'		 => 'entity_name', 'label'		 => lang('Entity name'), 'sortable'	 => false,
						'hidden'	 => FALSE
					),
					array(
						'key'		 => 'initial_value_ex', 'label'		 => lang('initial value ex'),
						'sortable'	 => false,
						'hidden'	 => TRUE
					),
					array(
						'key'		 => 'initial_value', 'label'		 => lang('Initial value'), 'sortable'	 => false,
						'hidden'	 => FALSE, 'className'	 => 'right', 'formatter'	 => 'JqueryPortico.FormatterAmount0'
					),
					array(
						'key'		 => 'value_ex', 'label'		 => lang('value ex'), 'sortable'	 => false,
						'hidden'	 => TRUE, 'className'	 => 'center'
					),
					array(
						'key'		 => 'value', 'label'		 => lang('Value'), 'sortable'	 => false,
						'hidden'	 => FALSE,
						'className'	 => 'right', 'formatter'	 => 'JqueryPortico.FormatterAmount0'
					),
					array(
						'key'		 => 'this_index', 'label'		 => lang('Last index'), 'sortable'	 => false,
						'hidden'	 => FALSE, 'className'	 => 'center'
					),
					array(
						'key'		 => 'this_write_off_ex', 'label'		 => lang('write off ex'), 'sortable'	 => false,
						'hidden'	 => TRUE, 'className'	 => 'center'
					),
					array(
						'key'		 => 'this_write_off', 'label'		 => lang('Write off'), 'sortable'	 => false,
						'hidden'	 => FALSE, 'className'	 => 'right', 'formatter'	 => 'JqueryPortico.FormatterAmount0'
					),
					array(
						'key'		 => 'date', 'label'		 => lang('Date'), 'sortable'	 => false, 'hidden'	 => FALSE,
						'className'	 => 'center'
					),
					array(
						'key'		 => 'index_count', 'label'		 => lang('Index count'), 'sortable'	 => false,
						'hidden'	 => FALSE, 'className'	 => 'center'
					),
					array(
						'key'		 => 'link_history', 'label'		 => lang('History'), 'sortable'	 => false,
						'hidden'	 => FALSE, 'className'	 => 'center'
					),
					array(
						'key'		 => 'check', 'label'		 => lang('Select'), 'sortable'	 => false,
						'hidden'	 => FALSE,
						'className'	 => 'center'
					),
				)
			),
			'end-toolbar'	 => array(
				'fields' => array(
					'field' => array(
						array(
							'type'	 => 'label',
							'id'	 => 'lbl_input_index',
							'value'	 => lang('New Index'),
							'style'	 => 'filter',
							'group'	 => '1'
						),
						array(
							'type'		 => 'text',
							'id'		 => 'txt_index',
							'name'		 => 'txt_index',
							'tab_index'	 => 5,
							'style'		 => 'filter',
							'group'		 => '1'
						),
						array(
							'type'	 => 'date-picker',
							'id'	 => 'start_date',
							'name'	 => 'start_date',
							'value'	 => '',
							'style'	 => 'filter',
							'group'	 => '1'
						),
						array(
							'type'		 => 'button',
							'id'		 => 'btn_update',
							'value'		 => lang('Update'),
							'tab_index'	 => 5,
							'style'		 => 'filter',
							'group'		 => '1',
							'action'	 => 'onclikUpdateinvestment()'
						),
					)
				)
			)
		);

		$filters = $this->_get_filters();
		foreach ($filters as $filter)
		{
			array_unshift($data['form']['toolbar']['item'], $filter);
		}

		$data['datatable']['actions'] = '';

		phpgwapi_jquery::load_widget('numberformat');
		self::add_javascript('property', 'base', 'investment.index.js');
		self::render_template_xsl('datatable2', $data);
	}

	function update_investment($values = '')
	{

		$receipt = array();

		if (!$values['date'])
		{
			$receipt['error'][] = array('msg' => lang('Please select a date !'));
		}
		if (!$values['new_index'])
		{
			$receipt['error'][] = array('msg' => lang('Please set a new index !'));
		}
		if (!$values['update'])
		{
			$receipt['error'][] = array('msg' => lang('Nothing to do!'));
		}

		if (!$receipt['error'])
		{
			$receipt = $this->bo->update_investment($values);
		}
		return $receipt;
	}

	function get_history_cols()
	{
		$uicols = array(
			'input_type' => array('text', 'text', 'text', 'text', 'text', 'text', 'hidden'),
			'name'		 => array(
				'initial_value', 'value', 'this_index', 'this_write_off', 'date',
				'index_count', 'is_admin'
			),
			'formatter'	 => array('', '', '', '', '', '', ''),
			'descr'		 => array(
				lang('Initial value'), lang('Value'), lang('Last index'),
				lang('Write off'), lang('Date'), lang('Index count'), ''
			),
			'className'	 => array(
				'center', 'right', 'right', 'right', 'center', 'center',
				''
			),
			'hidden'	 => array(false, false, false, false, true, false, true)
		);

		return $uicols;
	}

	function get_history()
	{
		$draw			 = Sanitizer::get_var('draw', 'int');
		$entity_id		 = Sanitizer::get_var('entity_id', 'int');
		$investment_id	 = Sanitizer::get_var('investment_id', 'int');
		$values			 = Sanitizer::get_var('values');
		if ($values)
		{
			return $this->update_investment($values);
		}

		$investment_list = $this->bo->read_single($entity_id, $investment_id);

		$dateformat						 = strtolower($this->userSettings['preferences']['common']['dateformat']);
		$sep							 = '/';
		$dlarr[strpos($dateformat, 'y')] = 'Y';
		$dlarr[strpos($dateformat, 'm')] = 'm';
		$dlarr[strpos($dateformat, 'd')] = 'd';
		ksort($dlarr);
		$dateformat						 = (implode($sep, $dlarr));

		$uicols	 = $this->get_history_cols();
		$values	 = array();
		if (isset($investment_list) && is_array($investment_list))
		{
			foreach ($investment_list as $investment)
			{
				for ($i = 0; $i < count($uicols['name']); $i++)
				{
					$json_row[$uicols['name'][$i]] = '';
					if ($uicols['name'][$i] == 'date')
					{
						$json_row[$uicols['name'][$i]] = date($dateformat, strtotime($investment[$uicols['name'][$i]]));
					}
					else if ($uicols['name'][$i] == 'is_admin')
					{
						$json_row[$uicols['name'][$i]] = $this->admin_invoice;
					}
					else
					{
						if ($uicols['name'][$i] == 'initial_value' || $uicols['name'][$i] == 'value' || $uicols['name'][$i] == 'this_write_off')
						{
							$json_row[$uicols['name'][$i]] = number_format((float)$investment[$uicols['name'][$i]], 0, ',', '');
						}
						else
						{
							$json_row[$uicols['name'][$i]] = $investment[$uicols['name'][$i]];
						}
					}
				}
				$values[] = $json_row;
			}
		}
		$result_data = array('results' => $values);

		$result_data['total_records']	 = count($values);
		$result_data['draw']			 = $draw;

		return $this->jquery_results($result_data);
	}

	function history()
	{
		$entity_type	 = Sanitizer::get_var('entity_type');
		$entity_id		 = Sanitizer::get_var('entity_id', 'int');
		$investment_id	 = Sanitizer::get_var('investment_id', 'int');

		$uicols = $this->get_history_cols();

		$jqcal = createObject('phpgwapi.jqcal');
		$jqcal->add_listener('filter_start_date');
		phpgwapi_jquery::load_widget('datepicker');

		$column_def		 = array();
		$count_uicols	 = count($uicols['name']);
		for ($k = 0; $k < $count_uicols; $k++)
		{
			$params = array(
				'key'		 => $uicols['name'][$k],
				'label'		 => $uicols['descr'][$k],
				'className'	 => $uicols['className'][$k],
				'sortable'	 => ($uicols['sortable'][$k]) ? true : false,
				'hidden'	 => ($uicols['input_type'][$k] == 'hidden') ? true : false
			);

			array_push($column_def, $params);
		}

		$datatable_def[] = array(
			'container'	 => 'datatable-container_0',
			'requestUrl' => json_encode(self::link(array(
				'menuaction'		 => 'property.uiinvestment.get_history',
				'entity_id'			 => $entity_id, 'investment_id'		 => $investment_id, 'phpgw_return_as'	 => 'json'
			))),
			'ColumnDefs' => $column_def,
			'data'		 => '',
			'config'	 => array(
				array('disableFilter' => true),
				array('disablePagination' => true)
			)
		);

		$top_toolbar = array(
			array(
				'type'	 => 'button',
				'id'	 => 'btn_cancel',
				'value'	 => lang('Cancel'),
				'url'	 => self::link(array(
					'menuaction' => 'property.uiinvestment.index'
				))
			)
		);

		$end_toolbar = array(
			array(
				'type'	 => 'label',
				'id'	 => 'lbl_input_index',
				'value'	 => lang('New Index'),
				'style'	 => 'filter',
				'group'	 => '1'
			),
			array(
				'type'		 => 'text',
				'id'		 => 'txt_index',
				'name'		 => 'txt_index',
				'tab_index'	 => 5,
				'style'		 => 'filter',
				'group'		 => '1'
			),
			array(
				'type'	 => 'date-picker',
				'id'	 => 'start_date',
				'name'	 => 'start_date',
				'value'	 => '',
				'style'	 => 'filter',
				'group'	 => '1'
			),
			array(
				'type'		 => 'button',
				'id'		 => 'btn_update',
				'value'		 => lang('Update'),
				'tab_index'	 => 5,
				'style'		 => 'filter',
				'group'		 => '1',
				'action'	 => 'onclikUpdateinvestment()'
			)
		);

		$info				 = array();
		$info[0]['name']	 = lang('Entity Type');
		$info[0]['value']	 = lang($entity_type);
		$info[1]['name']	 = lang('Entity Id');
		$info[1]['value']	 = $entity_id;
		$info[2]['name']	 = lang('Investment Id');
		$info[2]['value']	 = $investment_id;

		$hidden[0]['name']	 = 'entity_id';
		$hidden[0]['value']	 = $entity_id;
		$hidden[1]['name']	 = 'investment_id';
		$hidden[1]['value']	 = $investment_id;

		$data = array(
			'datatable_def'	 => $datatable_def,
			'top_toolbar'	 => $top_toolbar,
			'end_toolbar'	 => $end_toolbar,
			'info'			 => $info,
			'hidden'		 => $hidden,
			'msgbox_data'	 => $this->phpgwapi_common->msgbox($msgbox_data),
		);

		$appname		 = lang('investment');
		$function_msg	 = lang('investment history');

		//Title of Page
		$this->flags['app_header'] = lang('property') . ' - ' . $appname . ': ' . $function_msg;
		Settings::getInstance()->update('flags', ['app_header' => $this->flags['app_header']]);
		self::add_javascript('property', 'base', 'investment.history.js');

		self::render_template_xsl(array('investment', 'datatable_inline'), array('history' => $data));
	}

	function add()
	{
		if (!$this->acl_add && !$this->acl_edit)
		{
			phpgw::redirect_link('/index.php', array(
				'menuaction'	 => 'property.uilocation.stop',
				'perm'			 => 2, 'acl_location'	 => $this->acl_location
			));
		}
		$values			 = Sanitizer::get_var('values');
		$tabs			 = array();
		$tabs['general'] = array('label' => lang('general'), 'link' => '#general');
		$active_tab		 = 'general';

		phpgwapi_xslttemplates::getInstance()->add_file(array('investment'));

		if (isset($values['save']) && $values['save'])
		{
			$insert_record	=	Cache::session_get('property',	'insert_record');
			$insert_record_entity = (array)Cache::session_get('property',	'insert_record_entity');

			for ($j = 0; $j < count($insert_record_entity); $j++)
			{
				$insert_record['extra'][$insert_record_entity[$j]] = $insert_record_entity[$j];
			}

			$values = $this->bocommon->collect_locationdata($values, $insert_record);

			if (!$values['type'])
			{
				$receipt['error'][] = array('msg' => lang('Please select a type !'));
			}

			if (!$values['period'] && !$values['new_period'])
			{
				$receipt['error'][] = array('msg' => lang('Please select a period for write off !'));
			}

			if (!$values['date'])
			{
				$receipt['error'][] = array('msg' => lang('Please select a date !'));
			}

			if (!$values['initial_value'])
			{
				$receipt['error'][] = array('msg' => lang('Please set an initial value!'));
			}

			if (!$values['location']['loc1'] && !$values['extra']['p_num'])
			{
				$receipt['error'][] = array('msg' => lang('Please select a location - or an entity!'));
			}

			//_debug_array($values['extra']);
			if (!$receipt['error'])
			{
				$receipt = $this->bo->save_investment($values);
				unset($values);
			}
			else
			{
				if ($values['location'])
				{
					$location_code			 = implode("-", $values['location']);
					$values['location_data'] = $this->bolocation->read_single($location_code, $values['extra']);
				}

				if ($values['extra']['p_num'])
				{
					$values['p'][$values['extra']['p_entity_id']]['p_num']		 = $values['extra']['p_num'];
					$values['p'][$values['extra']['p_entity_id']]['p_entity_id'] = $values['extra']['p_entity_id'];
					$values['p'][$values['extra']['p_entity_id']]['p_cat_id']	 = $values['extra']['p_cat_id'];
					$values['p'][$values['extra']['p_entity_id']]['p_cat_name']	 = Sanitizer::get_var('entity_cat_name_' . $values['extra']['p_entity_id'], 'string', 'POST');
				}
			}
		}

		$location_data = $this->bolocation->initiate_ui_location(
			array(
				'values'		 => $values['location_data'],
				'type_id'		 => -1, // calculated from location_types
				'no_link'		 => false, // disable lookup links for location type less than type_id
				'required_level' => 1,
				'lookup_type'	 => 'form',
				'lookup_entity'	 => $this->bocommon->get_lookup_entity('investment'),
				'entity_data'	 => $values['p']
			)
		);


		$link_data = array(
			'menuaction' => 'property.uiinvestment.add'
		);

		$msgbox_data = $this->bocommon->msgbox_data($receipt);

		$jqcal = createObject('phpgwapi.jqcal');

		$jqcal->add_listener('values_date');

		$data = array(
			'msgbox_data'						 => $this->phpgwapi_common->msgbox($msgbox_data),
			'location_data'						 => $location_data,
			'lang_date_statustext'				 => lang('insert the date for the initial value'),
			'lang_date'							 => lang('Date'),
			'lang_location'						 => lang('Location'),
			'lang_select_location_statustext'	 => lang('select either a location or an entity'),
			'form_action'						 => phpgw::link('/index.php', $link_data),
			'done_action'						 => phpgw::link('/index.php', array(
				'menuaction' => 'property.uiinvestment.index',
				'preserve'	 => 1
			)),
			'lang_write_off_period'				 => lang('Write off period'),
			'lang_new'							 => lang('New'),
			'lang_select'						 => lang('Select'),
			'cat_list'							 => $this->bo->write_off_period_list($values['period']),
			'lang_descr'						 => lang('Description'),
			'lang_type'							 => lang('Type'),
			'lang_amount'						 => lang('Amount'),
			'lang_value_statustext'				 => lang('insert the value at the start-date as a positive amount'),
			'lang_new_period_statustext'		 => lang('Enter a new writeoff period if it is NOT in the list'),
			'filter_list'						 => $this->bo->filter('select', $values['type']),
			'filter_name'						 => 'values[type]',
			'lang_filter_statustext'			 => lang('Select the type of value'),
			'lang_show_all'						 => lang('Select'),
			'lang_name'							 => lang('name'),
			'lang_save'							 => lang('save'),
			'lang_done'							 => lang('done'),
			'value_new_period'					 => $values['new_period'],
			'value_inital_value'				 => $values['initial_value'],
			'value_date'						 => $values['date'],
			'value_descr'						 => $values['descr'],
			'lang_done_statustext'				 => lang('Back to the list'),
			'lang_save_statustext'				 => lang('Save the investment'),
			'lang_no_cat'						 => lang('Select'),
			'lang_cat_statustext'				 => lang('Select the category the investment belongs to. To do not use a category select NO CATEGORY'),
			'select_name'						 => 'values[period]',
			'investment_type_id'				 => $values['investment_type_id'],
			'tabs'								 => phpgwapi_jquery::tabview_generate($tabs, $active_tab),
			'validator'							 => phpgwapi_jquery::formvalidator_generate(array(
				'location',
				'date', 'security', 'file'
			))
		);

		$appname		 = lang('investment');
		$function_msg	 = lang('add investment');

		$this->flags['app_header'] = lang('property') . ' - ' . $appname . ': ' . $function_msg;
		Settings::getInstance()->update('flags', ['app_header' => $this->flags['app_header']]);

		phpgwapi_xslttemplates::getInstance()->set_var('phpgw', array('add' => $data));
	}

	function delete()
	{
		$entity_id		 = Sanitizer::get_var('entity_id', 'int');
		$investment_id	 = Sanitizer::get_var('investment_id', 'int');
		$index_count	 = Sanitizer::get_var('index_count', 'int');
		$entity_type	 = Sanitizer::get_var('entity_type');

		$confirm = Sanitizer::get_var('confirm', 'bool', 'POST');

		$link_data = array(
			'menuaction'	 => 'property.uiinvestment.history',
			'entity_id'		 => $entity_id,
			'investment_id'	 => $investment_id,
			'index_count'	 => $index_count,
			'entity_type'	 => $entity_type
		);

		if (Sanitizer::get_var('confirm', 'bool', 'POST'))
		{

			$this->bo->delete($entity_id, $investment_id, $index_count);
			phpgw::redirect_link('/index.php', $link_data);
		}

		phpgwapi_xslttemplates::getInstance()->add_file(array('app_delete'));

		$data = array(
			'done_action'			 => phpgw::link('/index.php', $link_data),
			'delete_action'			 => phpgw::link('/index.php', array(
				'menuaction'	 => 'property.uiinvestment.delete',
				'entity_id'		 => $entity_id, 'investment_id'	 => $investment_id, 'index_count'	 => $index_count,
				'entity_type'	 => $entity_type
			)),
			'lang_confirm_msg'		 => lang('do you really want to delete this entry'),
			'lang_yes'				 => lang('yes'),
			'lang_yes_statustext'	 => lang('Delete the entry'),
			'lang_no_statustext'	 => lang('Back to the list'),
			'lang_no'				 => lang('no')
		);

		$appname		 = lang('investment');
		$function_msg	 = lang('delete investment history element');

		$this->flags['app_header'] = lang('property') . ' - ' . $appname . ': ' . $function_msg;
		Settings::getInstance()->update('flags', ['app_header' => $this->flags['app_header']]);

		phpgwapi_xslttemplates::getInstance()->set_var('phpgw', array('delete' => $data));
	}
}
