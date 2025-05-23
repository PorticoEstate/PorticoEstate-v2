<?php

/**
 * phpGroupWare - eventplanner: a part of a Facilities Management System.
 *
 * @author Sigurd Nes <sigurdne@online.no>
 * @copyright Copyright (C) 2016 Free Software Foundation, Inc. http://www.fsf.org/
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
 * @internal Development of this application was funded by http://www.bergen.kommune.no/
 * @package eventplanner
 * @subpackage vendor_report
 * @version $Id: $
 */

use App\modules\phpgwapi\services\Settings;

phpgw::import_class('phpgwapi.uicommon');
phpgw::import_class('phpgwapi.datetime');

//	include_class('eventplanner', 'vendor_report', 'inc/model/');

class eventplanner_uivendor_report extends phpgwapi_uicommon
{

	public $public_functions = array(
		'columns' => true,
		'add' => true,
		'index' => true,
		'query' => true,
		'view' => true,
		'edit' => true,
		'save' => true,
	);

	protected
		$fields,
		$permissions,
		$custom_fields,
		$currentapp, $cats, $account, $acl_location;

	public function __construct()
	{
		parent::__construct();
		Settings::getInstance()->update('flags', ['app_header' => lang('eventplanner') . '::' . lang('vendor report')]);

		$this->bo = createObject('eventplanner.bovendor_report');
		$this->cats = &$this->bo->cats;
		$this->fields = eventplanner_vendor_report::get_fields();
		$this->permissions = eventplanner_vendor_report::get_instance()->get_permission_array();
		$this->custom_fields = eventplanner_vendor_report::get_instance()->get_custom_fields();
		$this->currentapp = $this->flags['currentapp'];
		self::set_active_menu("{$this->currentapp}::vendor_report");
		$this->account = $this->userSettings['account_id'];
		$this->acl_location = eventplanner_vendor_report::acl_location;
	}


	private function _get_filters()
	{
		$combos = array();
		$combos[] = array(
			'type' => 'autocomplete',
			'name' => 'vendor',
			'app' => 'eventplanner',
			'ui' => 'vendor',
			'label_attr' => 'name',
			'text' => lang('vendor') . ':',
			'requestGenerator' => 'requestWithVendorFilter'
		);

		return $combos;
	}

	function columns()
	{

		Settings::getInstance()->update('flags', ['xslt_app' => true, 'noframework' => true, 'nofooter' => true]);

		phpgwapi_xslttemplates::getInstance()->add_file(array('columns'), PHPGW_SERVER_ROOT . "/property/templates/base");


		$values = Sanitizer::get_var('values');
		$receipt = array();

		if (isset($values['save']) && $values['save'])
		{
			$preferences = createObject('phpgwapi.preferences',$this->account);
			$preferences->add('eventplanner', 'vendor_report_columns', (array)$values['columns'], 'user');
			$preferences->save_repository();

			$receipt['message'][] = array('msg' => lang('columns is updated'));
		}

		$function_msg = lang('Select Column');

		$link_data = array(
			'menuaction' => "{$this->currentapp}.uivendor_report.columns",
		);

		$msgbox_data = $this->bo->msgbox_data($receipt);
		//			self::message_set($receipt);

		$data = array(
			'msgbox_data' => $this->phpgwapi_common->msgbox($msgbox_data),
			'column_list' => $this->bo->column_list($values['columns'], $allrows = true),
			'function_msg' => $function_msg,
			'form_action' => phpgw::link('/index.php', $link_data),
			'lang_columns' => lang('columns'),
			'lang_none' => lang('None'),
			'lang_save' => lang('save'),
		);

		Settings::getInstance()->update('flags', ['app_header' => $function_msg]);
		phpgwapi_xslttemplates::getInstance()->set_var('phpgw', array('columns' => $data));
	}


	function _get_fields()
	{
		$fields = parent::_get_fields();
		$custom_fields = (array)createObject('phpgwapi.custom_fields')->find('eventplanner', $this->acl_location, 0, '', '', '', true, false, $filter);
		$selected = (array)$this->userSettings['preferences']['eventplanner']['vendor_report_columns'];

		foreach ($custom_fields as $custom_field)
		{
			if (in_array($custom_field['id'], $selected)  ||  $custom_field['list'])
			{
				$fields[] = array(
					'key' => $custom_field['name'],
					'label' =>  $custom_field['input_text'],
					'sortable' => true,
					'hidden' => false,
				);
			}
		}

		return $fields;
	}

	public function index()
	{
		if (empty($this->permissions[ACL_READ]))
		{
			phpgw::no_access();
		}

		if (Sanitizer::get_var('phpgw_return_as') == 'json')
		{
			return $this->query();
		}

		phpgwapi_jquery::load_widget('autocomplete');

		$function_msg = lang('vendor report');

		$data = array(
			'datatable_name' => $function_msg,
			'form' => array(
				'toolbar' => array(
					'item' => array()
				)
			),
			'datatable' => array(
				'source' => self::link(array(
					'menuaction' => "{$this->currentapp}.uivendor_report.index",
					'phpgw_return_as' => 'json'
				)),
				'allrows' => true,
				"columns" => array('onclick' => "JqueryPortico.openPopup({menuaction:'{$this->currentapp}.uivendor_report.columns'}, {closeAction:'reload'})"),
				'editor_action' => '',
				'field' => self::_get_fields()
			)
		);

		$filters = $this->_get_filters();

		foreach ($filters as $filter)
		{
			array_unshift($data['form']['toolbar']['item'], $filter);
		}

		$parameters = array(
			'parameter' => array(
				array(
					'name' => 'id',
					'source' => 'id'
				)
			)
		);

		$data['datatable']['actions'][] = array(
			'my_name' => 'view',
			'text' => lang('show'),
			'action' => self::link(array(
				'menuaction' => "{$this->currentapp}.uivendor_report.view"
			)),
			'parameters' => json_encode($parameters)
		);

		$data['datatable']['actions'][] = array(
			'my_name' => 'edit',
			'text' => lang('edit'),
			'action' => self::link(array(
				'menuaction' => "{$this->currentapp}.uivendor_report.edit"
			)),
			'parameters' => json_encode($parameters)
		);

		self::add_javascript($this->currentapp, 'base', 'vendor_report.index.js');
		phpgwapi_jquery::load_widget('numberformat');

		self::render_template_xsl('datatable2', $data);
	}

	/*
		 * Edit the price item with the id given in the http variable 'id'
		 */

	public function edit($values = array(), $mode = 'edit')
	{
		$active_tab = !empty($values['active_tab']) ? $values['active_tab'] : Sanitizer::get_var('active_tab', 'string', 'REQUEST', 'first_tab');
		Settings::getInstance()->update('flags', ['app_header' => lang('eventplanner') . '::' . lang('vendor report') . '::' . lang('edit')]);

		if (empty($this->permissions[ACL_ADD]))
		{
			phpgw::no_access();
		}

		if (!empty($values['object']))
		{
			$vendor_report = $values['object'];
		}
		else
		{
			$id = !empty($values['id']) ? $values['id'] : Sanitizer::get_var('id', 'int');
			$vendor_report = $this->bo->read_single($id);
		}

		$booking_id = $vendor_report->booking_id ? $vendor_report->booking_id : Sanitizer::get_var('booking_id', 'int');
		$booking = createObject('eventplanner.bobooking')->read_single($booking_id);
		$calendar_id = $booking->calendar_id;
		$calendar = createObject('eventplanner.bocalendar')->read_single($calendar_id, true, $relaxe_acl = true);

		$application = createObject('eventplanner.boapplication')->read_single($calendar->application_id, true, $relaxe_acl = true);
		$application->summary = '';
		$application->remark = '';

		$application_type_list = execMethod('eventplanner.bogeneric.get_list', array('type' => 'application_type'));
		$types = (array)$application->types;
		if ($types)
		{
			foreach ($application_type_list as &$application_type)
			{
				foreach ($types as $type)
				{
					if ((!empty($type['type_id']) && $type['type_id'] == $application_type['id']) || ($type == $application_type['id']))
					{
						$application_type['selected'] = 1;
						break;
					}
				}
			}
		}

		$custom_values = $vendor_report->json_representation ? $vendor_report->json_representation : array();
		$custom_fields = createObject('booking.custom_fields', 'eventplanner');
		$fields = $this->custom_fields;
		foreach ($fields as $attrib_id => &$attrib)
		{
			$attrib['value'] = isset($custom_values[$attrib['name']]) ? $custom_values[$attrib['name']] : null;

			if (isset($attrib['choice']) && is_array($attrib['choice']) && $attrib['value'])
			{
				foreach ($attrib['choice'] as &$choice)
				{
					if (is_array($attrib['value']))
					{
						$choice['selected'] = in_array($choice['id'], $attrib['value']) ? 1 : 0;
					}
					else
					{
						$choice['selected'] = $choice['id'] == $attrib['value'] ? 1 : 0;
					}
				}
			}
		}
		//			_debug_array($fields);
		$organized_fields = $custom_fields->organize_fields(eventplanner_vendor_report::acl_location, $fields);

		$tabs = array();
		$tabs['first_tab'] = array(
			'label' => lang('vendor report'),
			'link' => '#first_tab',
			'function' => "set_tab('first_tab')"
		);

		$application->public_type = $application->non_public == 1 ? lang('application public type non public') : lang('application public type public');

		$data = array(
			'form_action' => self::link(array('menuaction' => "{$this->currentapp}.uivendor_report.save")),
			'cancel_url' => self::link(array('menuaction' => "{$this->currentapp}.uivendor_report.index",)),
			'report' => $vendor_report,
			'booking'		=> $booking,
			'application'	=> $application,
			'application_type_list' => $application_type_list,
			'booking_url' => self::link(array('menuaction' => "{$this->currentapp}.uibooking.edit", 'id' => $booking->id, 'active_tab' => 'reports')),
			'mode' => $mode,
			'tabs' => phpgwapi_jquery::tabview_generate($tabs, $active_tab),
			'value_active_tab' => $active_tab,
			'attributes_group' => $organized_fields,
		);
		phpgwapi_jquery::formvalidator_generate(array('date', 'security', 'file'));
		phpgwapi_jquery::load_widget('autocomplete');
		//	self::add_javascript($this->currentapp, 'base', 'vendor_report.edit.js');
		self::render_template_xsl(array('report', 'application_info', 'datatable_inline', 'attributes_form'), array($mode => $data));
	}
}
