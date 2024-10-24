<?php

/**
 * phpGroupWare - property: a Facilities Management System.
 *
 * @author Sigurd Nes <sigurdne@online.no>
 * @copyright Copyright (C) 2003,2004,2005,2006,2007,2008,2009 Free Software Foundation, Inc. http://www.fsf.org/
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
 * @subpackage admin
 * @version $Id$
 */

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\controllers\Accounts\Accounts;

phpgw::import_class('phpgwapi.uicommon_jquery');

/**
 * Description
 * @package property
 */
class property_uievent extends phpgwapi_uicommon_jquery
{

	var $grants;
	var $start;
	var $query;
	var $sort;
	var $order;
	var $sub;
	var $location_info;
	var $currentapp, $bo, $account, $bocommon, $custom, $location_id, $user_id, $status_id, $role,
		$acl, $acl_location, $acl_read, $acl_add, $acl_edit, $acl_delete, $acl_manage, $allrows,$accounts_obj;

	var $public_functions = array(
		'query'			 => true,
		'index'			 => true,
		'view'			 => true,
		'edit'			 => true,
		'delete'		 => true,
		'schedule2'		 => true,
		'_get_filters'	 => true,
		'updatereceipt'	 => true,
	);

	function __construct()
	{
		parent::__construct();

		$this->flags['xslt_app']	 = true;
		$this->account								 = $this->userSettings['account_id'];
		$this->bo									 = CreateObject('property.boevent', true);
		$this->bocommon								 = CreateObject('property.bocommon');
		$this->custom								 = &$this->bo->custom;
		$this->accounts_obj							 = new Accounts();

		$this->location_info								 = $this->bo->location_info;
		$this->flags['menu_selection']	 = $this->location_info['menu_selection'];
		$this->acl_location									 = Sanitizer::get_var('location');
		$this->acl_read										 = $this->acl->check($this->acl_location, ACL_READ, 'property');
		$this->acl_add										 = $this->acl->check($this->acl_location, ACL_ADD, 'property');
		$this->acl_edit										 = $this->acl->check($this->acl_location, ACL_EDIT, 'property');
		$this->acl_delete									 = $this->acl->check($this->acl_location, ACL_DELETE, 'property');
		$this->acl_manage									 = $this->acl->check($this->acl_location, 16, 'property');

		Settings::getInstance()->update('flags', ['xslt_app' => true, 'menu_selection' => $this->location_info['menu_selection']]);
		$this->start		 = $this->bo->start;
		$this->query		 = $this->bo->query;
		$this->sort			 = $this->bo->sort;
		$this->order		 = $this->bo->order;
		$this->allrows		 = $this->bo->allrows;
		$this->location_id	 = $this->bo->location_id;
		$this->user_id		 = $this->bo->user_id;
		$this->status_id	 = $this->bo->status_id;
	}

	function save_sessiondata()
	{
		$data = array(
			'start'			 => $this->start,
			'query'			 => $this->query,
			'sort'			 => $this->sort,
			'order'			 => $this->order,
			'allrows'		 => $this->allrows,
			'location_id'	 => $this->location_id,
			'user_id'		 => $this->user_id,
			'status_id'		 => $this->status_id
		);
		$this->bo->save_sessiondata($data);
	}

	private function _get_filters()
	{
		$values_combo_box	 = array();
		$combos				 = array();

		$values_combo_box[0] = $this->bo->get_event_location();
		$default_value		 = array('id' => -1, 'name' => lang('no category'));
		array_unshift($values_combo_box[0], $default_value);
		$combos[]			 = array(
			'type'	 => 'filter',
			'name'	 => 'location_id',
			'extra'	 => '',
			'text'	 => lang('Category'),
			'list'	 => $values_combo_box[0]
		);

		$values_combo_box[1] = $this->bocommon->get_user_list_right2('filter', 2, $this->user_id, $this->acl_location);
		array_unshift($values_combo_box[1], array(
			'id'	 => $this->userSettings['account_id'],
			'name'	 => lang('mine tasks')
		));
		$default_value		 = array('id' => '', 'name' => lang('no user'));
		array_unshift($values_combo_box[1], $default_value);
		$combos[]			 = array(
			'type'	 => 'filter',
			'name'	 => 'user_id',
			'extra'	 => '',
			'text'	 => lang('User'),
			'list'	 => $values_combo_box[1]
		);

		$values_combo_box[2] = array();
		array_unshift($values_combo_box[2], array('id' => 'exception', 'name' => lang('exception')));
		array_unshift($values_combo_box[2], array('id' => 'closed', 'name' => lang('closed')));
		array_unshift($values_combo_box[2], array('id' => 'all', 'name' => lang('all')));
		array_unshift($values_combo_box[2], array('id' => 'open', 'name' => lang('open')));
		$combos[]			 = array(
			'type'	 => 'filter',
			'name'	 => 'status_id',
			'extra'	 => '',
			'text'	 => lang('Status'),
			'list'	 => $values_combo_box[2]
		);

		return $combos;
	}

	function updatereceipt()
	{

		$idevent	 = !empty($_POST['ids']) ? $_POST['ids'] : '';
		$idchecks	 = !empty($_POST['mckec']) ? $_POST['mckec'] : '';

		$receipt = array();
		if ($idevent && $idchecks)
		{
			$values = array(
				'events' => $idchecks,
			);

			$receipt = $this->bo->update_receipt($values);
		}
		return $receipt;
	}

	function index()
	{
		$this->acl_location = '.scheduled_events';
		if (!$this->acl->check($this->acl_location, ACL_READ, 'property'))
		{
			phpgw::redirect_link('/index.php', array(
				'menuaction'	 => 'property.uilocation.stop',
				'perm'			 => 1, 'acl_location'	 => $this->acl_location
			));
		}

		$this->acl_read		 = $this->acl->check($this->acl_location, ACL_READ, 'property');
		$this->acl_add		 = $this->acl->check($this->acl_location, ACL_ADD, 'property');
		$this->acl_edit		 = $this->acl->check($this->acl_location, ACL_EDIT, 'property');
		$this->acl_delete	 = $this->acl->check($this->acl_location, ACL_DELETE, 'property');
		$this->acl_manage	 = $this->acl->check($this->acl_location, 16, 'property');

		$this->flags['menu_selection'] = "property::scheduled_events";
		Settings::getInstance()->update('flags', ['menu_selection' => $this->flags['menu_selection']]);

		$values		 = Sanitizer::get_var('values');

		$receipt = array();
		if ($values && $this->acl_edit)
		{
			$receipt = $this->bo->update_receipt($values);
		}

		$this->save_sessiondata();

		if (Sanitizer::get_var('phpgw_return_as') == 'json')
		{
			return $this->query();
		}



		$jqcal = createObject('phpgwapi.jqcal');
		$jqcal->add_listener('filter_start_date');
		$jqcal->add_listener('filter_end_date');
		phpgwapi_jquery::load_widget('datepicker');

		$appname										 = lang('scheduled events');
		$function_msg									 = lang('list %1', $appname);
		$this->flags['app_header']	 = lang('property') . "::{$appname}::{$function_msg}";
		Settings::getInstance()->update('flags', ['app_header' => $this->flags['app_header']]);

		$data	 = array(
			'datatable_name' => $appname . ': ' . $function_msg,
			'form'			 => array(
				'toolbar' => array(
					'item' => array()
				)
			),
			'datatable'		 => array(
				'source'		 => self::link(array(
					'menuaction'		 => 'property.uievent.index',
					'phpgw_return_as'	 => 'json'
				)),
				'allrows'		 => true,
				'editor_action'	 => '',
				'field'			 => array(
					array(
						'key'		 => 'schedule_time',
						'label'		 => lang('dummy'),
						'sortable'	 => FALSE,
						'hidden'	 => TRUE
					),
					array(
						'key'		 => 'location',
						'label'		 => lang('dummy'),
						'sortable'	 => FALSE,
						'hidden'	 => TRUE
					),
					array(
						'key'		 => 'location_item_id',
						'label'		 => lang('dummy'),
						'sortable'	 => FALSE,
						'hidden'	 => TRUE
					),
					array(
						'key'		 => 'attrib_id',
						'label'		 => lang('dummy'),
						'sortable'	 => FALSE,
						'hidden'	 => TRUE
					),
					array(
						'key'		 => 'id',
						'label'		 => lang('id'),
						'sortable'	 => TRUE,
						'hidden'	 => FALSE
					),
					array(
						'key'		 => 'date',
						'label'		 => lang('Date'),
						'sortable'	 => TRUE,
						'hidden'	 => FALSE
					),
					array(
						'key'		 => 'account_lid',
						'label'		 => lang('Account'),
						'sortable'	 => TRUE,
						'hidden'	 => FALSE
					),
					array(
						'key'		 => 'descr',
						'label'		 => lang('Descr'),
						'sortable'	 => FALSE,
						'hidden'	 => FALSE
					),
					array(
						'key'		 => 'exception',
						'label'		 => lang('Exception'),
						'sortable'	 => FALSE,
						'hidden'	 => FALSE,
						'formatter'	 => 'JqueryPortico.FormatterCenter'
					),
					array(
						'key'		 => 'receipt_date',
						'label'		 => lang('receipt date'),
						'sortable'	 => FALSE,
						'hidden'	 => FALSE
					),
					array(
						'key'		 => 'location_name',
						'label'		 => lang('location name'),
						'sortable'	 => FALSE,
						'hidden'	 => FALSE
					),
					array(
						'key'		 => 'url',
						'label'		 => lang('url'),
						'sortable'	 => FALSE,
						'hidden'	 => FALSE,
						'formatter'	 => 'JqueryPortico.formatLinkEvent'
					)
				)
			)
		);
		$dry_run = true;

		$filters = $this->_get_Filters();
		foreach ($filters as $filter)
		{
			array_unshift($data['form']['toolbar']['item'], $filter);
		}

		$parameters = array(
			'parameter' => array(
				array(
					'name'	 => 'location',
					'source' => 'location'
				),
				array(
					'name'	 => 'attrib_id',
					'source' => 'attrib_id'
				),
				array(
					'name'	 => 'item_id',
					'source' => 'location_item_id'
				),
				array(
					'name'	 => 'id',
					'source' => 'id'
				)
			)
		);

		if ($this->acl_edit)
		{
			$data['datatable']['actions'][] = array(
				'my_name'	 => 'edit',
				'text'		 => lang('edit serie'),
				'action'	 => phpgw::link('/index.php', array(
					'menuaction' => 'property.uievent.edit'
				)),
				'target'	 => '_blank',
				'parameters' => json_encode($parameters)
			);
		}

		$data['datatable']['actions'][] = array(
			'my_name'		 => 'save',
			'text'			 => lang('save'),
			'type'			 => 'custom',
			'custom_code'	 => "onSave();"
		);

		unset($parameters);


		self::add_javascript('property', 'base', 'event.index.js');
		self::render_template_xsl('datatable2', $data);
	}

	public function query()
	{
		$date1		 = Sanitizer::get_var('start_date');
		$date2		 = Sanitizer::get_var('end_date');
		$start_date	 = $date1;
		$end_date	 = $date2;
		$search		 = Sanitizer::get_var('search');
		$order		 = Sanitizer::get_var('order');
		$draw		 = Sanitizer::get_var('draw', 'int');
		$columns	 = Sanitizer::get_var('columns');
		$export		 = Sanitizer::get_var('export', 'bool');

		$params = array(
			'start'			 => Sanitizer::get_var('start', 'int', 'REQUEST', 0),
			'results'		 => Sanitizer::get_var('length', 'int', 'REQUEST', 0),
			'query'			 => $search['value'],
			'order'			 => $columns[$order[0]['column']]['data'],
			'sort'			 => $order[0]['dir'],
			'allrows'		 => Sanitizer::get_var('length', 'int') == -1 || $export,
			'start_date'	 => $start_date ? urldecode($start_date) : '',
			'end_date'	 	 => $end_date ? urldecode($end_date) : '',
			'location_id'	 => $this->location_id,
			'user_id'		 => $this->user_id,
			'status_id'		 => $this->status_id
		);

		$values = $this->bo->read($params);
		if ($export)
		{
			return $values;
		}
		$result_data					 = array('results' => $values);
		$result_data['total_records']	 = $this->bo->total_records;
		$result_data['draw']			 = $draw;

		return $this->jquery_results($result_data);
	}

	function edit()
	{
		$lookup = Sanitizer::get_var('lookup');

		if ($lookup)
		{
			$this->flags['noframework'] = true;
			Settings::getInstance()->update('flags', ['noframework' => true]);
		}

		if (!$this->acl_add)
		{
			$this->bocommon->no_access();
			return;
		}

		$location					 = Sanitizer::get_var('location');
		$attrib_id					 = Sanitizer::get_var('attrib_id');
		$item_id					 = Sanitizer::get_var('item_id'); //might be bigint
		$id							 = Sanitizer::get_var('id', 'int');
		$values						 = Sanitizer::get_var('values');
		$values['responsible_id']	 = Sanitizer::get_var('contact', 'int', 'POST');

		$receipt = array();

		if (is_array($values))
		{
			$values['location_id']	 = $this->locations->get_id('property', $location);
			$values['attrib_id']	 = $attrib_id;
			$values['item_id']		 = $item_id;
			$attrib					 = $this->custom->get('property', $location, $attrib_id);
			$field_name				 = $attrib ? $attrib['column_name'] : $attrib_id;

			if ((isset($values['save']) && $values['save']) || (isset($values['apply']) && $values['apply']))
			{
				if (!isset($values['descr']) || !$values['descr'])
				{
					$receipt['error'][] = array('msg' => lang('Please enter a description'));
				}
				if (!isset($values['responsible_id']) || !$values['responsible_id'])
				{
					$receipt['error'][] = array('msg' => lang('Please select a responsible'));
				}
				if (!isset($values['action']) || !$values['action'])
				{
					$receipt['error'][] = array('msg' => lang('Please select an action'));
				}
				if (!isset($values['start_date']) || !$values['start_date'])
				{
					$receipt['error'][] = array('msg' => lang('Please select a start date'));
				}
				if (!isset($values['repeat_type']) || !$values['repeat_type'])
				{
					$receipt['error'][] = array('msg' => lang('Please select a repeat type'));
				}

				/* 					if(isset($values['repeat_day']))
					  {
					  $values['repeat_interval'] = 0;
					  }
					 */
				if ($id)
				{
					$values['id'] = $id;
				}
				else
				{
					$id = $values['id'];
				}

				if (!$receipt['error'])
				{
					$receipt = $this->bo->save($values);

					$js	 = "parent.document.getElementsByName('" . $field_name . "')[0].value = '{$receipt['id']}';\n";
					$js	 .= "parent.document.getElementsByName('" . $field_name . "_descr')[0].value = '{$values['descr']}';\n";
					//$js .= "parent.document.form.submit();\n";

					if (isset($values['save']) && $values['save'])
					{
						$js .= "parent.TINY.box.hide();";
					}
					phpgwapi_js::getInstance()->add_event('load', $js);
					$id = $receipt['id'];
				}
				else
				{
					unset($values['id']);
					$id = '';
				}
			}
			else if ((isset($values['delete']) && $values['delete']))
			{
				$attrib	 = $this->custom->get('property', $location, $attrib_id);
				$js		 = "parent.document.getElementsByName('" . $field_name . "')[0].value = '';\n";
				$js		 .= "parent.document.getElementsByName('" . $field_name . "_descr')[0].value = '';\n";
				if ($this->delete($id))
				{
					phpgwapi_js::getInstance()->add_event('load', $js);
					unset($values);
					unset($id);
				}
			}
			unset($js);
			unset($attrib);
		}

		if ($id)
		{
			$values			 = $this->bo->read_single($id);
			$function_msg	 = lang('edit event');
		}
		else
		{
			$function_msg		 = lang('add event');
			$values['enabled']	 = true;
		}

		$link_data = array(
			'menuaction' => 'property.uievent.edit',
			'location'	 => $location,
			'attrib_id'	 => $attrib_id,
			'item_id'	 => $item_id,
			'id'		 => $id,
			'lookup'	 => $lookup
		);

		$link_schedule_data = array(
			'menuaction' => 'property.uievent.schedule_week',
			'location'	 => $location,
			'attrib_id'	 => $attrib_id,
			'item_id'	 => $item_id,
			'id'		 => $id
		);

		//_debug_array($link_data);

		$tabs		 = array();
		$active_tab	 = 'general';

		$tabs['general'] = array('label' => lang('general'), 'link' => '#general');
		$tabs['repeat']	 = array('label' => lang('repeat'), 'link' => '#repeat');
		if ($id)
		{
			$tabs['plan'] = array('label' => lang('plan'), 'link' => '#plan');
		}

		$jqcal = createObject('phpgwapi.jqcal');
		$jqcal->add_listener('values_start_date');
		$jqcal->add_listener('values_end_date');

		$msgbox_data = $this->bocommon->msgbox_data($receipt);

		$contact_data = $this->bocommon->initiate_ui_contact_lookup(array(
			'contact_id' => $values['responsible_id'],
			'field'		 => 'contact',
			'type'		 => 'form'
		));

		$data = array(
			'datatable_def'						 => '',
			'contact_data'						 => $contact_data,
			'link_schedule'						 => phpgw::link('/index.php', $link_schedule_data),
			'lang_start_date_statustext'		 => lang('Select the date for the event'),
			'lang_start_date'					 => lang('date'),
			'value_start_date'					 => $values['start_date'],
			'value_enabled'						 => isset($values['enabled']) ? $values['enabled'] : '',
			'lang_enabled'						 => lang('enabled'),
			'lang_end_date_statustext'			 => lang('Select the estimated end date for the event'),
			'lang_end_date'						 => lang('end date'),
			'value_end_date'					 => $values['end_date'],
			'repeat_type'						 => $this->bo->get_rpt_type_list(isset($values['repeat_type']) ? $values['repeat_type'] : ''),
			'lang_repeat_type'					 => lang('repeat type'),
			'repeat_day'						 => $this->bo->get_rpt_day_list(isset($values['repeat_day']) ? $values['repeat_day'] : ''),
			'lang_repeat_day'					 => lang('repeat day'),
			'lang_repeat_interval'				 => lang('interval'),
			'value_repeat_interval'				 => isset($values['repeat_interval']) ? $values['repeat_interval'] : 0,
			'lang_repeat_interval_statustext'	 => lang('interval'),
			'lang_action'						 => lang('action'),
			'action'							 => $this->bo->get_action(isset($values['action']) ? $values['action'] : ''),
			'msgbox_data'						 => $this->phpgwapi_common->msgbox($msgbox_data),
			'form_action'						 => phpgw::link('/index.php', $link_data),
			'done_action'						 => phpgw::link('/index.php', array(
				'menuaction' => 'property.uievent.index'
			)),
			'lang_id'							 => lang('ID'),
			'lang_descr'						 => lang('Description'),
			'lang_save'							 => lang('save'),
			'lang_cancel'						 => lang('cancel'),
			'lookup'							 => $lookup,
			'lang_apply'						 => lang('apply'),
			'value_id'							 => isset($values['id']) ? $values['id'] : '',
			'lang_next_run'						 => lang('next run'),
			'value_next_run'					 => isset($values['next']) ? $values['next'] : '',
			'value_descr'						 => $values['descr'],
			'lang_descr_text'					 => lang('Enter a description of the record'),
			'lang_save_text'					 => lang('Save the record'),
			'lang_apply_statustext'				 => lang('Apply the values'),
			'lang_cancel_statustext'			 => lang('Leave the actor untouched and return back to the list'),
			'lang_save_statustext'				 => lang('Save the actor and return back to the list'),
			'lang_delete'						 => lang('delete'),
			'lang_delete_text'					 => lang('delete the record'),
			'lang_delete_statustext'			 => lang('delete the record'),
			'textareacols'						 => isset($this->userSettings['preferences']['property']['textareacols']) && $this->userSettings['preferences']['property']['textareacols'] ? $this->userSettings['preferences']['property']['textareacols'] : 60,
			'textarearows'						 => isset($this->userSettings['preferences']['property']['textarearows']) && $this->userSettings['preferences']['property']['textarearows'] ? $this->userSettings['preferences']['property']['textarearows'] : 10,
			'active_tab'						 => $active_tab,
			'tabs'								 => phpgwapi_jquery::tabview_generate($tabs, $active_tab)
		);

		$schedule = array();

		if ($id)
		{
			$link_shedule2 = phpgw::link('/index.php', array(
				'menuaction'		 => 'property.uievent.schedule2',
				'id'				 => $id, 'phpgw_return_as'	 => 'json'
			));

			$buttons = array(
				array(
					'id'			 => 'set_receipt', 'type'			 => 'buttons', 'value'			 => 'Receipt',
					'label'			 => lang('Receipt'),
					'funct'			 => 'onActionsClick', 'classname'		 => 'actionButton', 'value_hidden'	 => ""
				),
				array(
					'id'			 => 'delete_receipt', 'type'			 => 'buttons', 'value'			 => 'Delete Receipt',
					'label'			 => lang('Delete receipt'), 'funct'			 => 'onActionsClick', 'classname'		 => 'actionButton',
					'value_hidden'	 => ""
				),
				array(
					'id'			 => 'enable_alarm', 'type'			 => 'buttons', 'value'			 => 'Enable',
					'label'			 => lang('enable'),
					'funct'			 => 'onActionsClick', 'classname'		 => 'actionButton', 'value_hidden'	 => ""
				),
				array(
					'id'			 => 'disable_alarm', 'type'			 => 'buttons', 'value'			 => 'Disable',
					'label'			 => lang('disable'),
					'funct'			 => 'onActionsClick', 'classname'		 => 'actionButton', 'value_hidden'	 => ""
				)
			);

			$tabletools = array();
			foreach ($buttons as $entry)
			{
				$tabletools[] = array(
					'my_name'		 => $entry['value'],
					'text'			 => lang($entry['value']),
					'type'			 => 'custom',
					'custom_code'	 => "
											var api = oTable0.api();
											var selected = api.rows( { selected: true } ).data();

											var numSelected = 	selected.length;

											if (numSelected ==0){
												alert('None selected');
												return false;
											}
											var values = {'{$entry['id']}': 1, 'alarm': {}};
												
											for ( var n = 0; n < selected.length; ++n )
											{
												var aData = selected[n];
												values['alarm'][aData['alarm_id']] = aData['alarm_id'];
											}
											{$entry['funct']}(values);"
				);
			}

			$link_shedule2 = str_replace('&amp;', '&', $link_shedule2);

			$code = <<<JS

	this.onActionsClick=function(values)
	{
		//console.log(values);

		$.ajax({
			type: 'POST',
			dataType: 'json',
			url: '$link_shedule2',
			data:{values:values},
			success: function(data) {
				oTable0.api().draw();
			}
			});
	}
JS;
			phpgwapi_js::getInstance()->add_code($namespace = '', $code);

			$plan_def = array(
				array('key' => 'number', 'label' => '#', 'sortable' => true),
				array('key' => 'time', 'label' => lang('plan'), 'sortable' => true),
				array('key' => 'performed', 'label' => lang('performed'), 'sortable' => true),
				array('key' => 'user', 'label' => lang('user'), 'sortable' => true),
				array('key' => 'remark', 'label' => lang('remark'), 'sortable' => true),
				array('key' => 'enabled', 'label' => lang('enabled'), 'sortable' => true, 'className' => 'center'),
				array('key' => 'alarm_id', 'label' => lang('alarm_id'), 'sortable' => true)
			);

			$datatable_def[]		 = array(
				'container'	 => 'datatable-container_0',
				'requestUrl' => json_encode($link_shedule2),
				'ColumnDefs' => $plan_def,
				'data'		 => json_encode(array()),
				'tabletools' => $tabletools,
				'config'	 => array(
					array('disableFilter' => true),
					array('disablePagination' => true)
				)
			);
			$data['datatable_def']	 = $datatable_def;
		}
		else
		{
			$data['td_count']		 = '""';
			$data['base_java_url']	 = '""';
			$data['property_js']	 = '""';
			unset($data['datatable_def']);
		}

		//$data = array_merge($schedule, $data);
		$appname = lang('event');

		$this->flags['app_header'] = lang('property') . "::{$appname}::{$function_msg}";
		Settings::getInstance()->update('flags', ['app_header' => $this->flags['app_header']]);
		self::render_template_xsl(array('event', 'datatable_inline'), array('edit' => $data));
	}

	function delete($id)
	{
		if (!$this->acl_delete)
		{
			$this->bocommon->no_access();
			return;
		}

		return $this->bo->delete($id);
	}

	function schedule2($id = 0)
	{
		if (!$id)
		{
			$id = Sanitizer::get_var('id', 'int');
		}
		$values = Sanitizer::get_var('values');

		if (is_array($values))
		{
			if ($values['alarm'])
			{
				$receipt = $this->bo->set_exceptions(
					array(
						'event_id'		 => $id,
						'alarm'			 => array_keys($values['alarm']),
						'set_exception'	 => !!$values['disable_alarm'],
						'enable_alarm'	 => !!$values['enable_alarm'],
						'set_receipt'	 => !!$values['set_receipt'],
						'delete_receipt' => !!$values['delete_receipt']
					)
				);
			}
		}

		//_debug_array($_REQUEST);
		//------------------------------get data
		$event = $this->bo->so->read_single2($id);

		$dateformat	 = $this->userSettings['preferences']['common']['dateformat'];
		$i			 = 1;
		$values		 = array();
		foreach ($event as $entry)
		{
			$values[] = array(
				'number'			 => $i,
				'time'				 => $this->phpgwapi_common->show_date($entry['schedule_time'], $dateformat),
				'performed'			 => $this->phpgwapi_common->show_date($entry['receipt_date'], $dateformat),
				'user'				 => $entry['receipt_user_id'] ? $this->accounts_obj->get($entry['receipt_user_id'])->__toString() : '',
				'alarm_id'			 => $this->phpgwapi_common->show_date($entry['schedule_time'], 'Ymd'),
				'enabled'			 => isset($entry['exception']) && $entry['exception'] == true ? '' : 1,
				'location_id'		 => $entry['location_id'],
				'location_item_id'	 => $entry['location_item_id'],
				'remark'			 => $entry['descr'],
				'url'				 => phpgw::link('/index.php', array(
					'menuaction'		 => 'booking.uievent.show',
					'location_id'		 => $entry['location_id'], 'location_item_id'	 => $entry['location_item_id']
				))
			);
			$i++;
		}


		//------------------------------end get data

		$link_data = array(
			'menuaction' => 'property.uis_agreement.edit',
			'id'		 => $id,
			'role'		 => $this->role
		);


		$msgbox_data = $this->bocommon->msgbox_data($receipt);


		$link_download = array(
			'menuaction' => 'property.uis_agreement.download',
			'id'		 => $id
		);

		$tabs = array();


		//----------JSON CODE ----------------------------------------------
		//---GET ALARM
		if (Sanitizer::get_var('phpgw_return_as') == 'json')
		{
			if (count($values))
			{
				$draw			 = Sanitizer::get_var('draw', 'int');
				$allrows		 = Sanitizer::get_var('length', 'int') == -1;
				$start			 = Sanitizer::get_var('start', 'int', 'REQUEST', 0);
				$total_records	 = count($values);

				$num_rows = Sanitizer::get_var('length', 'int', 'REQUEST', 0);

				if ($allrows)
				{
					$out = $values;
				}
				else
				{
					if ($total_records > $num_rows)
					{
						$page		 = ceil(($start / $total_records) * ($total_records / $num_rows));
						$values_part = array_chunk($values, $num_rows);
						$out		 = $values_part[$page];
					}
					else
					{
						$out = $values;
					}
				}

				$result_data					 = array('results' => $out);
				$result_data['total_records']	 = $total_records;
			}
			else
			{
				$result_data					 = array('results' => array());
				$result_data['total_records']	 = 0;
			}
			$result_data['draw'] = $draw;
			return $this->jquery_results($result_data);
		}
	}
}
