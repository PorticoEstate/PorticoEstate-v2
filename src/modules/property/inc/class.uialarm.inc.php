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
 * @subpackage admin
 * @version $Id$
 */

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\Cache;
use App\modules\phpgwapi\controllers\Accounts\Accounts;


/**
 * Description
 * @package property
 */
phpgw::import_class('phpgwapi.uicommon_jquery');
phpgw::import_class('phpgwapi.jquery');

class property_uialarm extends phpgwapi_uicommon_jquery
{

	var $grants;
	var $start;
	var $query;
	var $sort;
	var $order;
	var $filter;
	var $account, $bocommon, $entity_id, $cat_id, $allrows, $type, $type_app,
		$acl, $acl_location, $acl_read, $acl_add, $acl_edit, $acl_delete, $acl_manage;
	var $boasync, $method_id;

	var $public_functions = array(
		'query'		 => true,
		'index'		 => true,
		'view'		 => true,
		'edit'		 => true,
		'delete'	 => true,
		'list_alarm' => true,
		'run'		 => true,
		'edit_alarm' => true,
		'query_list' => true,
	);
	private $bo;

	function __construct()
	{
		parent::__construct();

		$this->flags['xslt_app']			 = true;
		$this->flags['menu_selection']	 = 'admin::property::admin_async';
		Settings::getInstance()->set('flags', $this->flags);


		$this->account = $this->userSettings['account_id'];

		$this->bo		 = CreateObject('property.boalarm', true);
		$this->boasync	 = CreateObject('property.boasync');
		$this->bocommon	 = CreateObject('property.bocommon');

		$this->start	 = $this->bo->start;
		$this->query	 = $this->bo->query;
		$this->sort		 = $this->bo->sort;
		$this->order	 = $this->bo->order;
		$this->filter	 = $this->bo->filter;
		$this->method_id = $this->bo->method_id;
		$this->allrows	 = $this->bo->allrows;
	}

	function save_sessiondata()
	{
		$data = array(
			'start'		 => $this->start,
			'query'		 => $this->query,
			'sort'		 => $this->sort,
			'order'		 => $this->order,
			'filter'	 => $this->filter,
			'method_id'	 => $this->method_id,
			'allrows'	 => $this->allrows
		);
		$this->bo->save_sessiondata($data);
	}

	public function query()
	{
		$search	 = Sanitizer::get_var('search');
		$order	 = Sanitizer::get_var('order');
		$draw	 = Sanitizer::get_var('draw', 'int');
		$columns = Sanitizer::get_var('columns');

		$params = array(
			'start'		 => Sanitizer::get_var('start', 'int', 'REQUEST', 0),
			'results'	 => Sanitizer::get_var('length', 'int', 'REQUEST', 0),
			'query'		 => $search['value'],
			'order'		 => $columns[$order[0]['column']]['data'],
			'sort'		 => $order[0]['dir'],
			'filter'	 => $this->filter,
			'id'		 => '',
			'allrows'	 => Sanitizer::get_var('length', 'int') == -1
		);

		$list = $this->bo->read($params);
		foreach ($list as $alarm)
		{
			$link_edit				 = '';
			$lang_edit_statustext	 = '';
			$text_edit				 = '';

			if (substr($alarm['id'], 0, 8) == 'fm_async')
			{
				$link_edit	 = phpgw::link('/index.php', array(
					'menuaction' => 'property.uialarm.edit',
					'async_id'	 => urlencode($alarm['id'])
				));
				$text_edit	 = lang('edit');
				$link_edit	 = "<a href=\"$link_edit\">$text_edit</a>";
			}
			else
			{
				$link_edit = "-";
			}

			$check_box = "<input type=\"checkbox\" name=\"values[alarm][" . $alarm['id'] . "]\" value=\"" . $alarm['id'] . "\" class=\"myValuesForPHP\">";

			$content[] = array(
				'id'		 => $alarm['id'],
				'next_run'	 => $this->phpgwapi_common->show_date($alarm['next']),
				'times'		 => is_array($alarm['times']) ? print_r($alarm['times'], true) : $this->phpgwapi_common->show_date($alarm['times']),
				'method'	 => $alarm['method'],
				'data'		 => print_r($alarm['data'], true),
				'enabled'	 => $alarm['enabled'],
				'user'		 => $alarm['user'],
				'edit'		 => $link_edit
			);
		}

		$result_data = array('results' => $content);

		$result_data['total_records']	 = $this->bo->total_records;
		$result_data['draw']			 = $draw;

		return $this->jquery_results($result_data);
	}

	function edit_alarm()
	{
		$ids_alarm	 = !empty($_POST['ids']) ? $_POST['ids'] : '';
		$type_alarm	 = !empty($_POST['type']) ? $_POST['type'] : '';

		if (($type_alarm == 'disable_alarm' || $type_alarm == 'enable_alarm') && count($ids_alarm))
		{
			$_enable_alarm = ($type_alarm == 'disable_alarm') ? false : true;
			$this->bo->enable_alarm('fm_async', $ids_alarm, $_enable_alarm);
		}
		else if ($type_alarm == 'test_cron')
		{
			$this->bo->test_cron($ids_alarm);
		}
		else if ($type_alarm == 'delete_alarm' && count($ids_alarm))
		{
			$this->bo->delete_alarm('fm_async', $ids_alarm);
		}
	}

	function index()
	{
		$values = Sanitizer::get_var('values');

		if (Sanitizer::get_var('phpgw_return_as') == 'json')
		{
			return $this->query();
		}


		$appname		 = lang('alarm');
		$function_msg	 = lang('list alarm');

		$this->flags['app_header'] = $appname . ': ' . $function_msg;
		Settings::getInstance()->update('flags', ['app_header' => $this->flags['app_header']]);

		$data = array(
			'datatable_name' => $appname . ': ' . $function_msg,
			'datatable'		 => array(
				'source'	 => self::link(array(
					'menuaction'		 => 'property.uialarm.index',
					'cat_id'			 => $this->cat_id,
					'filter'			 => $this->filter,
					'phpgw_return_as'	 => 'json'
				)),
				'new_item'	 => self::link(array(
					'menuaction' => 'property.uialarm.edit'
				)),
				'allrows'	 => true,
				'editor'	 => '',
				'field'		 => array(
					array('key' => 'id', 'label' => lang('alarm id'), 'sortable' => true, 'formatter' => 'JqueryPortico.FormatterCenter'),
					array(
						'key'		 => 'next_run', 'label'		 => lang('Next run'), 'sortable'	 => true,
						'formatter'	 => 'JqueryPortico.FormatterCenter'
					),
					array('key' => 'times', 'label' => lang('Times'), 'sortable' => false, 'formatter' => 'JqueryPortico.FormatterCenter'),
					array('key' => 'method', 'label' => lang('Method'), 'sortable' => true, 'formatter' => 'JqueryPortico.FormatterCenter'),
					array('key' => 'data', 'label' => lang('Data'), 'sortable' => false, 'formatter' => 'JqueryPortico.FormatterCenter'),
					array(
						'key'		 => 'enabled', 'label'		 => lang('enabled'), 'sortable'	 => false,
						'formatter'	 => 'JqueryPortico.FormatterCenter'
					),
					array('key' => 'user', 'label' => lang('User'), 'sortable' => true, 'formatter' => 'JqueryPortico.FormatterCenter'),
					array('key' => 'edit', 'label' => lang('edit'), 'sortable' => false, 'formatter' => 'JqueryPortico.FormatterCenter')
				)
			)
		);

		$requestUrl = json_encode(
			self::link(
				array(
					'menuaction'		 => 'property.uialarm.index',
					'cat_id'			 => $this->cat_id,
					'filter'			 => $this->filter,
					'phpgw_return_as'	 => 'json'
				)
			)
		);

		$parameters = array(
			'parameter' => array(
				array(
					'name'	 => 'id',
					'source' => 'id'
				),
			)
		);


		$data['datatable']['actions'][] = array(
			'my_name'	 => 'edit',
			'text'		 => lang('run'),
			'action'	 => phpgw::link('/index.php', array(
				'menuaction' => 'property.uialarm.run',
			)),
			'parameters' => json_encode($parameters)
		);

		$data['datatable']['actions'][] = array(
			'my_name'		 => 'disable_alarm',
			'text'			 => lang('Disable'),
			'type'			 => 'custom',
			'custom_code'	 => "
					var api = oTable.api();
					var selected = api.rows( { selected: true } ).data();

                    var numSelected = 	selected.length;

                    if (numSelected ==0){
                        alert('None selected');
                        return false;
				}
                    var  ids = {};
                    for ( var n = 0; n < selected.length; ++n )
			{
                        var aData = selected[n];
                        //ids.push(aData['id']);
                        ids[aData['id']] = aData['id'];
			}
                    onActionsClick_Toolbar('disable_alarm', ids);
                   "
		);

		$data['datatable']['actions'][] = array(
			'my_name'		 => 'enable_alarm',
			'text'			 => lang('Enable'),
			'type'			 => 'custom',
			'custom_code'	 => "
					var api = oTable.api();
					var selected = api.rows( { selected: true } ).data();

                    var numSelected = 	selected.length;

                    if (numSelected ==0){
                        alert('None selected');
                        return false;
				}
                    var  ids = {};
                    for ( var n = 0; n < selected.length; ++n )
			{
                        var aData = selected[n];
                        //ids.push(aData['id']);
                        ids[aData['id']] = aData['id'];
			}
                    onActionsClick_Toolbar('enable_alarm', ids);
                   "
		);

		$data['datatable']['actions'][] = array(
			'my_name'		 => 'test_cron',
			'text'			 => lang('test cron'),
			'type'			 => 'custom',
			'custom_code'	 => "
					var api = oTable.api();
					var selected = api.rows( { selected: true } ).data();

                    var numSelected = 	selected.length;

                    if (numSelected ==0){
                        alert('None selected');
                        return false;
                    }
                    var  ids = {};
                    for ( var n = 0; n < selected.length; ++n )
			{
                        var aData = selected[n];
                        //ids.push(aData['id']);
                        ids[aData['id']] = aData['id'];
			}
                    onActionsClick_Toolbar('test_cron', ids);
                   "
		);

		$data['datatable']['actions'][] = array(
			'my_name'		 => 'delete_alarm',
			'text'			 => lang('Delete'),
			'type'			 => 'custom',
			'custom_code'	 => "
					var api = oTable.api();
					var selected = api.rows( { selected: true } ).data();

                    var numSelected = 	selected.length;

                    if (numSelected ==0){
                        alert('None selected');
                        return false;
                    }
                    var  ids = {};
                    for ( var n = 0; n < selected.length; ++n )
			{
                        var aData = selected[n];
                        //ids.push(aData['id']);
                        ids[aData['id']] = aData['id'];
			}
                    onActionsClick_Toolbar('delete_alarm', ids);
                   "
		);

		phpgwapi_jquery::load_widget('core');
		phpgwapi_jquery::load_widget('numberformat');

		self::add_javascript('property', 'base', 'uialarm.index.js');
		self::render_template_xsl('datatable2', $data);
	}

	public function query_list()
	{
		$search	 = Sanitizer::get_var('search');
		$order	 = Sanitizer::get_var('order');
		$draw	 = Sanitizer::get_var('draw', 'int');
		$columns = Sanitizer::get_var('columns');

		switch ($columns[$order[0]['column']]['data'])
		{
			case 'next_run':
				$order_field = 'next';
				break;
			default:
				$order_field = $columns[$order[0]['column']]['data'];
		}

		$params = array(
			'start'		 => Sanitizer::get_var('start', 'int', 'REQUEST', 0),
			'results'	 => Sanitizer::get_var('length', 'int', 'REQUEST', 0),
			'query'		 => $search['value'],
			'order'		 => $order_field,
			'sort'		 => $order[0]['dir'],
			'filter'	 => $this->filter,
			'id'		 => '%',
			'allrows'	 => Sanitizer::get_var('length', 'int') == -1
		);

		$list = $this->bo->read($params);

		//            echo '<pre>'; print_r($list); echo '</pre>';
		//while (is_array($list) && list($id, $alarm) = each($list))
		if (is_array($list))
		{
			$times = '';
			foreach ($list as $id => $alarm)
			{
				if (is_array($alarm['times']))
				{
					//while (is_array($alarm['times']) && list($key, $value) = each($alarm['times']))
					foreach ($alarm['times'] as $key => $value)
					{
						$times .= $key . ' => ' . $value . ' ';
					}
				}
				else
				{
					$times = $this->phpgwapi_common->show_date($alarm['times']);
				}

				if (is_array($alarm['data']))
				{
					$accounts_obj = new Accounts();
					//while (is_array($alarm['data']) && list($key, $value) = each($alarm['data']))
					$data = '';
					foreach ($alarm['data'] as $key => $value)
					{
						if ($key == 'owner')
						{
							$value = $accounts_obj->id2name($value);
						}
						$data .= $key . ' => ' . $value . ' ';
					}
				}

				$id = explode(':', $id);

				if ($id[0] == 's_agreement' || $id[0] == 'agreement')
				{
					$link_edit				 = phpgw::link('/index.php', array(
						'menuaction' => 'property.ui' . $id[0] . '.edit',
						'id'		 => $id[1]
					));
					$lang_edit_statustext	 = lang('edit the alarm');
					$text_edit				 = lang('edit');
				}

				$content[] = array(
					'id_cod'	 => $id[1],
					'id'		 => $alarm['id'],
					'next_run'	 => $this->phpgwapi_common->show_date($alarm['next']),
					'method'	 => $alarm['method'],
					'times'		 => $times,
					'data'		 => $data,
					'enabled'	 => $alarm['enabled'],
					'user'		 => $alarm['user'],
					//					'link_edit'			=> $link_edit,
					//					'lang_edit_statustext'		=> $lang_edit_statustext,
					//					'text_edit'			=> $text_edit
				);
				unset($alarm);
				unset($data);
				unset($times);
				unset($link_edit);
				unset($lang_edit_statustext);
				unset($text_edit);
			}
		}

		$result_data = array('results' => $content);

		$result_data['total_records']	 = $this->bo->total_records;
		$result_data['draw']			 = $draw;

		return $this->jquery_results($result_data);
	}

	function list_alarm()
	{
		$this->flags['menu_selection']	 = 'property::agreement::alarm';
		$receipt	= Cache::session_get('session_data', 'alarm_receipt');
		Cache::session_clear('session_data', 'alarm_receipt');

		$values = Sanitizer::get_var('values');
		if ($values['delete_alarm'] && count($values['alarm']))
		{
			$receipt = $this->bo->delete_alarm('fm_async', $values['alarm']);
		}
		else if (($values['enable_alarm'] || $values['disable_alarm']) && count($values['alarm']))
		{
			$receipt = $this->bo->enable_alarm('fm_async', $values['alarm'], $values['enable_alarm']);
		}
		else if ($values['test_cron'])
		{
			$this->bo->test_cron();
		}

		if (Sanitizer::get_var('phpgw_return_as') == 'json')
		{
			return $this->query_list();
		}


		$appname		 = lang('alarm');
		$function_msg	 = lang('list alarm');

		$this->flags['app_header'] = $appname . ': ' . $function_msg;
		Settings::getInstance()->update('flags', ['app_header' => $this->flags['app_header']]);

		$data = array(
			'datatable_name' => $appname,
			'form'			 => array(
				//                    'toolbar' => array(
				//                        'item'  => array()
				//                    )
			),
			'datatable'		 => array(
				'source'		 => self::link(array(
					'menuaction'		 => 'property.uialarm.list_alarm',
					'phpgw_return_as'	 => 'json'
				)),
				'allrows'		 => true,
				'editor_action'	 => '',
				'field'			 => array(
					array(
						'key'		 => 'id_cod',
						//                            'name'=>'id_cod',	
						'descr'		 => '',
						'sortable'	 => false,
						'hidden'	 => true
					),
					array(
						'key'		 => 'id',
						//                            'name'=>'id',		
						'label'		 => lang('alarm id'),
						'sortable'	 => true
					),
					array(
						'key'		 => 'next_run',
						'label'		 => lang('Next run'),
						'sortable'	 => true
					),
					array(
						'key'		 => 'data',
						'label'		 => lang('Data'),
						'sortable'	 => false
					),
					array(
						'key'		 => 'enabled',
						'label'		 => lang('enabled'),
						'sortable'	 => false
					),
					array(
						'key'		 => 'user',
						'label'		 => lang('User'),
						'sortable'	 => true
					)
				)
			)
		);


		$parameters = array(
			'parameter' => array(
				array(
					'name'	 => 'id',
					'source' => 'id_cod'
				),
			)
		);

		$data['datatable']['actions'][] = array(
			'my_name'	 => 'edit',
			'text'		 => lang('edit'),
			'action'	 => phpgw::link('/index.php', array(
				'menuaction' => 'property.uis_agreement.edit',
			)),
			'parameters' => json_encode($parameters)
		);

		unset($parameters);

		phpgwapi_jquery::load_widget('core');
		phpgwapi_jquery::load_widget('numberformat');

		self::render_template_xsl('datatable2', $data);
	}

	function edit()
	{
		$method_id	 = Sanitizer::get_var('method_id', 'int');
		$async_id	 = urldecode(Sanitizer::get_var('async_id'));
		$values		 = Sanitizer::get_var('values');

		if ($async_id)
		{
			$async_id_elements	 = explode(':', $async_id);
			$method_id			 = $async_id_elements[1];
		}

		$tabs			 = array();
		$tabs['general'] = array('label' => lang('general'), 'link' => '#general');
		$active_tab		 = 'general';

		$this->method_id = $method_id ? $method_id : $this->method_id;

		phpgwapi_xslttemplates::getInstance()->add_file(array('alarm'));


		if ($values['save'] || $values['apply'])
		{

			$units = array(
				'year',
				'month',
				'day',
				'dow',
				'hour',
				'min'
			);

			$times = array();
			foreach ($units as $u)
			{
				if ($values[$u] !== '')
				{
					$times[$u] = $values[$u];
				}
			}

			if (!$receipt['error'])
			{
				$this->method_id = $values['method_id'] ? $values['method_id'] : $this->method_id;

				$values['alarm_id'] = $alarm_id;

				$async					 = $this->boasync->read_single($this->method_id);
				//_debug_array($async);
				$data_set				 = unserialize($async['data']);
				$data_set['enabled']	 = true;
				$data_set['times']		 = $times;
				$data_set['owner']		 = $this->account;
				$data_set['event_id']	 = $this->method_id;
				$data_set['id']			 = $async_id;

				$async_id	 = $this->bo->save_alarm($alarm_type	 = 'fm_async', $entity_id	 = $this->method_id, $alarm		 = $data_set, $async['name']);

				if ($values['save'])
				{
					Cache::session_set('session_data', 'alarm_receipt', $receipt);
					phpgw::redirect_link('/index.php', array('menuaction' => 'property.uialarm.index'));
				}
			}
		}

		if ($values['cancel'])
		{
			phpgw::redirect_link('/index.php', array('menuaction' => 'property.uialarm.index'));
		}

		if ($async_id)
		{
			$alarm		 = $this->bo->read_alarm($alarm_type	 = 'fm_async', $async_id);

			$this->method_id = $alarm['event_id'] ? $alarm['event_id'] : $this->method_id;
		}

		$link_data = array(
			'menuaction' => 'property.uialarm.edit',
			'async_id'	 => $async_id
		);

		$msgbox_data = $this->bocommon->msgbox_data($receipt);

		//_debug_array($alarm);
		$data											 = array(
			'msgbox_data'			 => $this->phpgwapi_common->msgbox($msgbox_data),
			'abook_data'			 => $abook_data,
			'edit_url'				 => phpgw::link('/index.php', $link_data),
			'lang_async_id'			 => lang('ID'),
			'value_async_id'		 => $async_id,
			'lang_method'			 => lang('method'),
			'lang_save'				 => lang('save'),
			'lang_cancel'			 => lang('cancel'),
			'lang_apply'			 => lang('apply'),
			'lang_apply_statustext'	 => lang('Apply the values'),
			'lang_cancel_statustext' => lang('Leave the owner untouched and return back to the list'),
			'lang_save_statustext'	 => lang('Save the owner and return back to the list'),
			'lang_no_method'		 => lang('no method'),
			'lang_method_statustext' => lang('Select the method for this times service'),
			'method_list'			 => $this->bo->select_method_list($this->method_id),
			'lang_timing'			 => lang('timing'),
			'lang_year'				 => lang('year'),
			'value_year'			 => $alarm['times']['year'],
			'lang_month'			 => lang('month'),
			'value_month'			 => $alarm['times']['month'],
			'lang_day'				 => lang('day'),
			'value_day'				 => $alarm['times']['day'],
			'lang_dow'				 => lang('Day of week (0-6, 0=Sun)'),
			'value_dow'				 => $alarm['times']['dow'],
			'lang_hour'				 => lang('hour'),
			'value_hour'			 => $alarm['times']['hour'],
			'lang_minute'			 => lang('minute'),
			'value_minute'			 => $alarm['times']['min'],
			'lang_data'				 => lang('data'),
			'lang_data_statustext'	 => lang('inputdata for the method'),
			'tabs'					 => phpgwapi_jquery::tabview_generate($tabs, $active_tab),
			'validator'				 => phpgwapi_jquery::formvalidator_generate(array(
				'location',
				'date', 'security', 'file'
			))
		);
		//_debug_array($data);
		$this->flags['app_header']	 = lang('async') . ': ' . ($async_id ? lang('edit timer') : lang('add timer'));
		Settings::getInstance()->update('flags', ['app_header' => $this->flags['app_header']]);

		phpgwapi_xslttemplates::getInstance()->set_var('phpgw', array('edit' => $data));
	}

	/**
	 * @todo remove or alter this function
	 */
	function delete()
	{
		$owner_id	 = Sanitizer::get_var('owner_id', 'int');
		$confirm	 = Sanitizer::get_var('confirm', 'bool', 'POST');

		$link_data = array(
			'menuaction' => 'property.uiowner.index'
		);

		if (Sanitizer::get_var('confirm', 'bool', 'POST'))
		{
			$this->bo->delete($owner_id);
			phpgw::redirect_link('/index.php', $link_data);
		}

		phpgwapi_xslttemplates::getInstance()->add_file(array('app_delete'));

		$data = array(
			'done_action'			 => phpgw::link('/index.php', $link_data),
			'delete_action'			 => phpgw::link('/index.php', array(
				'menuaction' => 'property.uiowner.delete',
				'owner_id'	 => $owner_id
			)),
			'lang_confirm_msg'		 => lang('do you really want to delete this entry'),
			'lang_yes'				 => lang('yes'),
			'lang_yes_statustext'	 => lang('Delete the entry'),
			'lang_no_statustext'	 => lang('Back to the list'),
			'lang_no'				 => lang('no')
		);

		$appname		 = lang('owner');
		$function_msg	 = lang('delete owner');

		$this->flags['app_header'] = lang('property') . ' - ' . $appname . ': ' . $function_msg;
		Settings::getInstance()->update('flags', ['app_header' => $this->flags['app_header']]);
		phpgwapi_xslttemplates::getInstance()->set_var('phpgw', array('delete' => $data));
	}

	function view()
	{
		$owner_id = Sanitizer::get_var('owner_id', 'int', 'GET');

		$this->flags['app_header'] = lang('owner') . ': ' . lang('view owner');
		Settings::getInstance()->update('flags', ['app_header' => $this->flags['app_header']]);

		phpgwapi_xslttemplates::getInstance()->add_file('owner');

		$owner = $this->bo->read_single($owner_id);

		$data = array(
			'done_action'		 => phpgw::link('/index.php', array('menuaction' => 'property.uiowner.index')),
			'lang_name'			 => lang('name'),
			'lang_category'		 => lang('category'),
			'lang_time_created'	 => lang('time created'),
			'lang_done'			 => lang('done'),
			'value_name'		 => $owner['name'],
			'value_cat'			 => $this->bo->read_category_name($owner['cat_id']),
			'value_date'		 => $this->phpgwapi_common->show_date($owner['entry_date'])
		);

		phpgwapi_xslttemplates::getInstance()->set_var('phpgw', array('view' => $data));
	}

	function run()
	{
		$id		 = Sanitizer::get_var('id');
		$confirm = Sanitizer::get_var('confirm', 'bool', 'POST');

		$link_data = array(
			'menuaction' => 'property.uialarm.index'
		);

		if (Sanitizer::get_var('confirm', 'bool', 'POST'))
		{
			$this->bo->test_cron(array($id => $id));
		}

		phpgwapi_xslttemplates::getInstance()->add_file(array('app_delete'));

		$data = array(
			'done_action'			 => phpgw::link('/index.php', $link_data),
			'delete_action'			 => phpgw::link('/index.php', array(
				'menuaction' => 'property.uialarm.run',
				'id'		 => $id
			)),
			'lang_confirm_msg'		 => lang('do you really want to run this entry'),
			'lang_yes'				 => lang('yes'),
			'lang_yes_statustext'	 => lang('Run'),
			'lang_no_statustext'	 => lang('Back to the list'),
			'lang_no'				 => lang('no')
		);


		$this->flags['app_header'] = lang('property') . "::cron::run ::" . $id;
		Settings::getInstance()->update('flags', ['app_header' => $this->flags['app_header']]);
		phpgwapi_xslttemplates::getInstance()->set_var('phpgw', array('delete' => $data));
	}
}
