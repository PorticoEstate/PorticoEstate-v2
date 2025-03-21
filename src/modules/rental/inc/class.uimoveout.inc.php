<?php

/**
 * phpGroupWare - rental: a part of a Facilities Management System.
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
 * @internal Development of this application was funded by http://www.bergen.kommune.no/ and Nordlandssykehuset
 * @package rental
 * @subpackage moveout
 * @version $Id: $
 */

use App\modules\phpgwapi\services\Settings;

phpgw::import_class('phpgwapi.uicommon');
phpgw::import_class('phpgwapi.datetime');

include_class('rental', 'moveout', 'inc/model/');

class rental_uimoveout extends phpgwapi_uicommon
{

	public $public_functions = array(
		'add'		 => true,
		'index'		 => true,
		'query'		 => true,
		'view'		 => true,
		'edit'		 => true,
		'save'		 => true,
		'get'		 => true,
		'_get_files' => true,
		'view_file'	 => true
	);
	protected
		$bo,
		$fields,
		$permissions,
		$custom_fields;

	public function __construct()
	{
		parent::__construct();
		self::set_active_menu('rental::moveout');
		Settings::getInstance()->update('flags', ['app_header' => lang('rental') . '::' . lang('moveout')]);

		$this->bo = createObject('rental.bomoveout');
		$this->fields = rental_moveout::get_fields();
		$this->permissions = rental_moveout::get_instance()->get_permission_array();
		$this->custom_fields = rental_moveout::get_custom_fields();
	}

	public function index()
	{
		$function_msg = lang('moveout');

		if (empty($this->permissions[ACL_READ]))
		{
			phpgw::no_access($function_msg);
		}

		if (Sanitizer::get_var('phpgw_return_as') == 'json')
		{
			return $this->query();
		}

		phpgwapi_jquery::load_widget('autocomplete');

		$data = array(
			'datatable_name' => $function_msg,
			'form' => array(
				'toolbar' => array(
					'item' => array()
				)
			),
			'datatable' => array(
				'source' => self::link(array(
					'menuaction' => 'rental.uimoveout.index',
					'phpgw_return_as' => 'json'
				)),
				'allrows' => true,
				'new_item' => self::link(array('menuaction' => 'rental.uimoveout.add')),
				'editor_action' => '',
				'field' => parent::_get_fields()
			)
		);

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
			'action' => phpgw::link('/index.php', array(
				'menuaction' => 'rental.uimoveout.view'
			)),
			'parameters' => json_encode($parameters)
		);

		$data['datatable']['actions'][] = array(
			'my_name' => 'edit',
			'text' => lang('edit'),
			'action' => phpgw::link('/index.php', array(
				'menuaction' => 'rental.uimoveout.edit'
			)),
			'parameters' => json_encode($parameters)
		);

		self::add_javascript('rental', 'base', 'moveout.index.js');
		phpgwapi_jquery::load_widget('numberformat');

		self::render_template_xsl('datatable2', $data);
	}

	public function edit($values = array(), $mode = 'edit')
	{
		$active_tab = !empty($values['active_tab']) ? $values['active_tab'] : Sanitizer::get_var('active_tab', 'string', 'REQUEST', 'first_tab');
		Settings::getInstance()->update('flags', ['app_header' => lang('rental') . '::' . lang('moveout') . '::' . lang('edit')]);

		if (empty($this->permissions[ACL_ADD]))
		{
			phpgw::no_access();
		}

		if (!empty($values['object']))
		{
			$moveout = $values['object'];
		}
		else
		{
			$id = !empty($values['id']) ? $values['id'] : Sanitizer::get_var('id', 'int');
			$moveout = $this->bo->read_single($id);
		}

		$contract_id = $moveout->contract_id ? $moveout->contract_id : Sanitizer::get_var('contract_id', 'int');

		$tabs = array();
		$tabs['first_tab'] = array(
			'label' => lang('moveout'),
			'link' => '#first_tab'
		);
		//			$tabs['signature'] = array(
		//				'label' => lang('signature'),
		//				'link' => '#signature'
		//			);

		$custom_values = $moveout->attributes ? $moveout->attributes : array();

		foreach ($custom_values as $attrib_id => &$attrib)
		{
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

		$organized_fields = createObject('booking.custom_fields', 'rental')->organize_fields(rental_moveout::acl_location, $custom_values);
		$file_def = array(
			array(
				'key' => 'file_name',
				'label' => lang('Filename'),
				'sortable' => false,
				'resizeable' => true
			),
			array(
				'key' => 'picture',
				'label' => lang('picture'),
				'sortable' => false,
				'resizeable' => true,
				'formatter' => 'JqueryPortico.showPicture'
			),
			array(
				'key' => 'delete_file',
				'label' => lang('Delete file'),
				'sortable' => false,
				'resizeable' => true,
				'formatter' => 'JqueryPortico.FormatterCenter'
			),
		);

		$datatable_def[] = array(
			'container' => 'datatable-container_0',
			'requestUrl' => json_encode(self::link(array(
				'menuaction' => 'rental.uimoveout._get_files',
				'id' => $id,
				'phpgw_return_as' => 'json'
			))),
			'ColumnDefs' => $file_def,
			'data' => json_encode(array()),
			'config' => array(
				array('disableFilter' => true),
				array('disablePagination' => true)
			)
		);

		$comments = (array)$moveout->comments;
		foreach ($comments as $key => &$comment)
		{
			$comment['value_count'] = $key + 1;
			$comment['value_date'] = $this->phpgwapi_common->show_date($comment['time']);
		}

		$comments_def = array(
			array('key' => 'value_count', 'label' => '#', 'sortable' => true, 'resizeable' => true),
			array('key' => 'value_date', 'label' => lang('Date'), 'sortable' => false, 'resizeable' => true),
			array('key' => 'author', 'label' => lang('User'), 'sortable' => true, 'resizeable' => true),
			array('key' => 'comment', 'label' => lang('Note'), 'sortable' => true, 'resizeable' => true)
		);

		$datatable_def[] = array(
			'container' => 'datatable-container_1',
			'requestUrl' => "''",
			'ColumnDefs' => $comments_def,
			'data' => json_encode($comments),
			'config' => array(
				array('disableFilter' => true),
				array('disablePagination' => true)
			)
		);

		$data = array(
			'datatable_def' => $datatable_def,
			'form_action' => phpgw::link('/index.php', array('menuaction' => 'rental.uimoveout.save')),
			'cancel_url' => phpgw::link('/index.php', array('menuaction' => 'rental.uimoveout.index',)),
			'moveout' => $moveout,
			'contract'	=> createObject('rental.uicontract')->get($contract_id),
			'mode' => $mode,
			'tabs' => phpgwapi_jquery::tabview_generate($tabs, $active_tab),
			'value_active_tab' => $active_tab,
			'attributes_group' => $organized_fields,
		);
		phpgwapi_jquery::formvalidator_generate(array());
		phpgwapi_jquery::load_widget('autocomplete');
		//			self::add_javascript('phpgwapi', 'signature_pad', 'signature_pad.min.js');
		//			phpgwapi_css::getInstance()->add_external_file('phpgwapi/js/signature_pad/signature-pad.css');
		$attributes_xsl = $mode == 'edit' ? 'attributes_form' : 'attributes_view';
		self::add_javascript('rental', 'base', 'moveout.edit.js');
		self::render_template_xsl(array('moveout', 'contract_info', 'datatable_inline', $attributes_xsl), array($mode => $data));
	}

	/*
		 * Get the moveout with the id given in the http variable 'id'
		 */

	public function get($id = 0)
	{
		if (empty($this->permissions[ACL_ADD]))
		{
			phpgw::no_access();
		}

		$id = !empty($id) ? $id : Sanitizer::get_var('id', 'int');

		$moveout = $this->bo->read_single($id)->toArray();

		unset($moveout['secret']);

		return $moveout;
	}

	public function save($ajax = false)
	{
		parent::save($ajax);
	}

	/**
	 * (non-PHPdoc)
	 * @see phpgwapi/inc/phpgwapi_uicommon#query()
	 */
	public function query($relaxe_acl = false)
	{
		$params = $this->bo->build_default_read_params();
		$values = $this->bo->read($params);
		array_walk($values["results"], array($this, "_add_links"), "rental.uimoveout.edit");

		return $this->jquery_results($values);
	}

	public function view_file()
	{
		parent::view_file();
	}

	public function _get_files()
	{
		if (empty($this->permissions[ACL_READ]))
		{
			phpgw::no_access();
		}

		$id = Sanitizer::get_var('id', 'int');
		return parent::get_files('rental', 'moveout', 'rental.uimoveout.view_file', $id);
	}
	/**
	 * Store and / or delete files related to an entity
	 *
	 * @param int  $id  entity id
	 *
	 * @return void
	 */
	//		protected function _handle_files( $id )
	//		{
	//			parent::_handle_files('rental', 'moveout', $id);
	//		}

}
