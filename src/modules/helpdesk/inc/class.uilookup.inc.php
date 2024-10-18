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
 * @subpackage core
 * @version $Id: class.uilookup.inc.php 15854 2016-10-19 11:39:12Z sigurdne $
 */
/**
 * Description
 * @package property
 */

use App\modules\phpgwapi\services\Settings;

phpgw::import_class('phpgwapi.uicommon_jquery');
phpgw::import_class('phpgwapi.jquery');

class helpdesk_uilookup extends phpgwapi_uicommon_jquery
{

	var $grants;
	var $cat_id;
	var $start;
	var $query;
	var $sort;
	var $order;
	var $filter;
	var $part_of_town_id;
	var $district_id;
	var $sub;
	var $currentapp;
	var $public_functions = array(
		'order_template' => true,
		'response_template' => true,
		'email_template' => true,
	);

	function __construct()
	{
		Settings::getInstance()->update('flags', ['noframework' => true, 'headonly' => true, 'xslt_app' => true]);
		parent::__construct();
	}

	public function query()
	{
	}


	function response_template()
	{
		$category = Sanitizer::get_var('category', 'int');
		if (Sanitizer::get_var('phpgw_return_as') == 'json')
		{
			$search = Sanitizer::get_var('search');
			$order = Sanitizer::get_var('order');
			$draw = Sanitizer::get_var('draw', 'int');
			$columns = Sanitizer::get_var('columns');

			$params = array(
				'start' => Sanitizer::get_var('start', 'int', 'REQUEST', 0),
				'results' => Sanitizer::get_var('length', 'int', 'REQUEST', 0),
				'query' => $search['value'],
				'order' => $columns[$order[0]['column']]['data'],
				'filter' => array('category' => $category),
				'sort' => $order[0]['dir'],
				'dir' => $order[0]['dir'],
				'allrows' => Sanitizer::get_var('length', 'int') == -1,
			);

			$values = array();
			$bo = CreateObject('helpdesk.bogeneric');
			$bo->get_location_info('response_template');
			$values = $bo->read($params);

			$result_data = array(
				'results' => $values,
				'total_records' => $bo->total_records,
				'draw' => $draw
			);
			return $this->jquery_results($result_data);
		}
		$action = <<<JS

				var encodedStr = aData["content"];
				var parser = new DOMParser;
				var dom = parser.parseFromString(encodedStr,'text/html');
				var decodedString = dom.body.textContent;
JS;
		switch ($this->userSettings['preferences']['common']['rteditor'])
		{
			default:
			case 'ckeditor':
				$action .= <<<JS

						try
						{
							parent.$.fn.insertAtCaret(encodedStr);
						}
JS;
				break;
			case 'quill':
				$action .= <<<JS

						try
						{
							parent.quill.new_note.setText('');
							parent.quill.new_note.clipboard.dangerouslyPasteHTML(0, encodedStr);

						}
JS;
				break;
			case 'summernote':
				$action .= <<<JS

						try
						{
			//		alert(encodedStr);
			//				console.log(parent.$('textarea#new_note').summernote());
			//				parent.$('textarea#new_note').summernote('reset');
							if (parent.$('textarea#new_note').summernote('isEmpty'))
							{
								parent.$('textarea#new_note').summernote('editor.insertText', '\\n');
							}

							parent.$('textarea#new_note').summernote('focus');
							parent.$('textarea#new_note').summernote('pasteHTML', encodedStr);
						}
JS;
				break;
		}

		$action .= <<<JS

				catch(e)
				{
	//				console.log(parent.quill);
					console.log(e);
					var temp = parent.document.getElementById("new_note").value;
					if(temp)
					{
						temp = temp + "\\n";
					}

					var content  = $('<textarea />').html(aData["content"]).text();
					parent.document.getElementById("new_note").value = temp + content;
				}

				parent.JqueryPortico.onPopupClose("close");
JS;

		$data = array(
			'left_click_action' => $action,
			'datatable_name' => '',
			'form' => array(
				'toolbar' => array(
					'item' => array()
				)
			),
			'datatable' => array(
				'source' => self::link(array(
					'menuaction' => 'helpdesk.uilookup.response_template',
					'query' => $this->query,
					'category' => $category,
					'type' => 'response_template',
					'phpgw_return_as' => 'json'
				)),
				'allrows' => true,
				'editor_action' => '',
				'field' => array()
			)
		);

		$cat_list = array(
			array('id' => '', 'name' => lang('no category')),
			array('id' => 1, 'name' => lang('internal')),
			array('id' => 2, 'name' => lang('external communication'))
		);


		foreach ($cat_list as &$cat_item)
		{
			$cat_item['selected'] = $cat_item['id'] == $category ? 1 : 0;
		}

		$filter = array(
			'type'	 => 'filter',
			'name'	 => 'category',
			'text'	 => lang('Category'),
			'list'	 => $cat_list
		);

		array_unshift($data['form']['toolbar']['item'], $filter);

		$uicols = array(
			'input_type' => array('text', 'text', 'text'),
			'name' => array('id', 'name', 'content'),
			'formatter' => array('', '', ''),
			'descr' => array(lang('ID'), lang('name'), lang('content'))
		);

		$count_uicols_name = count($uicols['name']);

		for ($k = 0; $k < $count_uicols_name; $k++)
		{
			$params = array(
				'key' => $uicols['name'][$k],
				'label' => $uicols['descr'][$k],
				'sortable' => $uicols['sortable'][$k],
				'hidden' => false
			);

			array_push($data['datatable']['field'], $params);
		}

		Settings::getInstance()->update('flags', ['app_header' => lang('helpdesk') . '::' . lang('template') . '::' . lang('list response template')]);

		self::render_template_xsl('datatable2', $data);
	}

	function email_template()
	{
		if (Sanitizer::get_var('phpgw_return_as') == 'json')
		{
			$search = Sanitizer::get_var('search');
			$order = Sanitizer::get_var('order');
			$draw = Sanitizer::get_var('draw', 'int');
			$columns = Sanitizer::get_var('columns');

			$params = array(
				'start' => Sanitizer::get_var('start', 'int', 'REQUEST', 0),
				'results' => Sanitizer::get_var('length', 'int', 'REQUEST', 0),
				'query' => $search['value'],
				'order' => $columns[$order[0]['column']]['data'],
				'sort' => $order[0]['dir'],
				'dir' => $order[0]['dir'],
				'allrows' => Sanitizer::get_var('length', 'int') == -1,
				'filter' => ''
			);

			$values = array();
			$bo = CreateObject('helpdesk.bogeneric');
			$bo->get_location_info('email_template');
			$values = $bo->read($params);

			$result_data = array(
				'results' => $values,
				'total_records' => $bo->total_records,
				'draw' => $draw
			);
			return $this->jquery_results($result_data);
		}

		//			$action = 'var temp = parent.document.getElementById("content").value;' . "\r\n";
		//			$action .= 'if(temp){temp = temp + "\n";}' . "\r\n";
		//			$action .= 'parent.document.getElementById("content").value = temp + aData["content"];' . "\r\n";
		//			$action .= 'parent.JqueryPortico.onPopupClose("close");' . "\r";

		$action = <<<JS

				var encodedStr = aData["content"];
				var parser = new DOMParser;
				var dom = parser.parseFromString(encodedStr,'text/html');
				var decodedString = dom.body.textContent;
JS;

		switch ($this->userSettings['preferences']['common']['rteditor'])
		{
			default:
			case 'ckeditor':
				$action .= <<<JS

						try
						{
							parent.$.fn.insertAtCaret(encodedStr);
						}
JS;
				break;
			case 'quill':
				$action .= <<<JS

						try
						{
							parent.quill.content.setText('n');
							parent.quill.content.clipboard.dangerouslyPasteHTML(0, encodedStr);
						}
JS;
				break;
			case 'summernote':
				$action .= <<<JS

						try
						{
							if (parent.$('textarea#content').summernote('isEmpty'))
							{
								parent.$('textarea#content').summernote('editor.insertText', '\\n');
							}

							parent.$('textarea#content').summernote('focus');
			//				parent.$('textarea#content').summernote('reset');
							parent.$('textarea#content').summernote('pasteHTML', encodedStr);
						}
JS;
				break;
		}

		$action .= <<<JS

				catch(e)
				{
				console.log(e);
				}

				parent.JqueryPortico.onPopupClose("close");
JS;

		$data = array(
			'left_click_action' => $action,
			'datatable_name' => '',
			'form' => array(
				'toolbar' => array(
					'item' => array()
				)
			),
			'datatable' => array(
				'source' => self::link(array(
					'menuaction' => 'helpdesk.uilookup.email_template',
					'query' => $this->query,
					'filter' => $this->filter,
					'cat_id' => $this->cat_id,
					'type' => 'email_template',
					'phpgw_return_as' => 'json'
				)),
				'allrows' => true,
				'editor_action' => '',
				'field' => array()
			)
		);

		$uicols = array(
			'input_type' => array('text', 'text', 'text'),
			'name' => array('id', 'name', 'content'),
			'formatter' => array('', '', ''),
			'descr' => array(lang('ID'), lang('name'), lang('content'))
		);

		$count_uicols_name = count($uicols['name']);

		for ($k = 0; $k < $count_uicols_name; $k++)
		{
			$params = array(
				'key' => $uicols['name'][$k],
				'label' => $uicols['descr'][$k],
				'sortable' => $uicols['sortable'][$k],
				'hidden' => false
			);

			array_push($data['datatable']['field'], $params);
		}

		Settings::getInstance()->update('flags', ['app_header' => lang('helpdesk') . '::' . lang('template') . '::' . lang('list email template')]);

		self::render_template_xsl('datatable2', $data);
	}
}
