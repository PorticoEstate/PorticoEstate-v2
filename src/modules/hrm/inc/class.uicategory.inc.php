<?php

/**
 * phpGroupWare - HRM: a  human resource competence management system.
 *
 * @author Sigurd Nes <sigurdne@online.no>
 * @copyright Copyright (C) 2003-2005 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @internal Development of this application was funded by http://www.bergen.kommune.no/bbb_/ekstern/
 * @package hrm
 * @subpackage admin
 * @version $Id$
 */

use App\modules\phpgwapi\services\Settings;

/**
 * Description
 * @package hrm
 */

class hrm_uicategory
{
	var $grants;
	var $start;
	var $query;
	var $sort;
	var $order;
	var $sub;
	var $currentapp;

	var $public_functions = array(
		'index'  => true,
		'view'   => true,
		'edit'   => true,
		'delete' => true
	);

	var $nextmatchs, $account, $bo, $bocommon, $allrows, $userSettings;
	function __construct()
	{
		$this->userSettings = Settings::getInstance()->get('user');

		Settings::getInstance()->update('flags', ['xslt_app' => true]);
		$this->nextmatchs			= CreateObject('phpgwapi.nextmatchs');
		$this->account				= $this->userSettings['account_id'];
		$this->bo				= CreateObject('hrm.bocategory', true);
		$this->bocommon				= CreateObject('hrm.bocommon');

		$this->start				= $this->bo->start;
		$this->query				= $this->bo->query;
		$this->sort				= $this->bo->sort;
		$this->order				= $this->bo->order;
		$this->allrows				= $this->bo->allrows;
		$type						= Sanitizer::get_var('type');
		Settings::getInstance()->update('flags', ['menu_selection' => "admin::hrm::$type"]);
	}

	function save_sessiondata()
	{
		$data = array(
			'start'		=> $this->start,
			'query'		=> $this->query,
			'sort'		=> $this->sort,
			'order'		=> $this->order,
			'allrows'	=> $this->allrows
		);
		$this->bo->save_sessiondata($data);
	}

	function index()
	{
		$type	= Sanitizer::get_var('type');
		$type_id	= Sanitizer::get_var('type_id', 'int');

		phpgwapi_xslttemplates::getInstance()->add_file(array(
			'category',
			'nextmatchs',
			'search_field'
		));

		$category_list = $this->bo->read($type, $type_id);

		//while (is_array($category_list) && list(,$category) = each($category_list))
		if (is_array($category_list))
		{
			foreach ($category_list as $key => $category)
			{
				$words = explode(' ', $category['descr']);
				$first = "$words[0] $words[1] $words[2] $words[3]";

				$content[] = array(
					'id'				=> $category['id'],
					'first'				=> $first,
					'link_edit'			=> phpgw::link('/index.php', array('menuaction' => 'hrm.uicategory.edit', 'id' => $category['id'], 'type' => $type,  'type_id' => $type_id)),
					'link_delete'			=> phpgw::link('/index.php', array('menuaction' => 'hrm.uicategory.delete', 'id' => $category['id'], 'type' => $type,  'type_id' => $type_id)),
					'lang_view_categorytext'	=> lang('view the category'),
					'lang_edit_categorytext'	=> lang('edit the category'),
					'lang_delete_categorytext'	=> lang('delete the category'),
					'text_view'			=> lang('view'),
					'text_edit'			=> lang('edit'),
					'text_delete'			=> lang('delete')
				);
			}
		}

		//_debug_array($content);

		$table_header[] = array(

			'lang_descr'		=> lang('Descr'),
			'lang_edit'		=> lang('edit'),
			'lang_delete'		=> lang('delete'),
			'sort_id'		=> $this->nextmatchs->show_sort_order(array(
				'sort'	=> $this->sort,
				'var'	=> 'id',
				'order'	=> $this->order,
				'extra'	=> array(
					'menuaction'	=> 'hrm.uicategory.index',
					'type'	=> $type,
					'type_id' => $type_id
				)
			)),
			'lang_id'		=> lang('category id'),
		);

		$table_add[] = array(
			'lang_add'			=> lang('add'),
			'lang_add_categorytext'		=> lang('add a category'),
			'add_action'			=> phpgw::link('/index.php', array('menuaction' => 'hrm.uicategory.edit', 'type' => $type, 'type_id' => $type_id)),
			'lang_done'			=> lang('done'),
			'lang_done_categorytext'	=> lang('back to admin'),
			'done_action'			=> phpgw::link('/admin/index.php')
		);

		if (!$this->allrows)
		{
			$record_limit	= $this->userSettings['preferences']['common']['maxmatchs'];
		}
		else
		{
			$record_limit	= $this->bo->total_records;
		}

		$phpgwapi_common = new \phpgwapi_common();
		$data = array(
			'allow_allrows'					=> true,
			'allrows'					=> $this->allrows,
			'start_record'					=> $this->start,
			'record_limit'					=> $record_limit,
			'num_records'					=> count($category_list),
			'all_records'					=> $this->bo->total_records,
			'link_url'					=> phpgw::link('/index.php', array('menuaction' => 'hrm.uicategory.index', 'type' => $type,  'type_id' => $type_id)),
			'img_path'					=> $phpgwapi_common->get_image_path('phpgwapi', 'default'),
			'lang_searchfield_categorytext'			=> lang('Enter the search string. To show all entries, empty this field and press the SUBMIT button again'),
			'lang_searchbutton_categorytext'		=> lang('Submit the search string'),
			'query'						=> $this->query,
			'lang_search'					=> lang('search'),
			'table_header'					=> $table_header,
			'values'					=> $content,
			'table_add'					=> $table_add
		);

		$appname = lang($type) . ' ' . $type_id;
		$function_msg	= lang('list %1 category', $type);

		Settings::getInstance()->update('flags', ['app_header' => lang('hrm') . ' - ' . $appname . ': ' . $function_msg]);
		phpgwapi_xslttemplates::getInstance()->set_var('phpgw', array('list' => $data));
		//	phpgwapi_xslttemplates::getInstance()->pp();
		$this->save_sessiondata();
	}

	function edit()
	{
		$type		= Sanitizer::get_var('type');
		$type_id	= Sanitizer::get_var('type_id', 'int');
		$id		= Sanitizer::get_var('id', 'int');
		$values		= Sanitizer::get_var('values');


		phpgwapi_xslttemplates::getInstance()->add_file(array('category'));

		if ($values['save'])
		{
			if (!$id && !ctype_digit($values['id']))
			{
				$receipt['error'][] = array('msg' => lang('Please enter an integer !'));
				unset($values['id']);
			}

			if ($id)
			{
				$values['id'] = $id;
				$action = 'edit';
			}
			else
			{
				$id =	$values['id'];
			}

			if (!$receipt['error'])
			{
				$receipt = $this->bo->save($values, $type_id, $action, $type);
			}
		}

		if ($id)
		{
			$category = $this->bo->read_single($id, $type, $type_id);
			$function_msg = lang('edit category');
			$action = 'edit';
		}
		else
		{
			$function_msg = lang('add category');
			$action = 'add';
		}


		$link_data = array(
			'menuaction'	=> 'hrm.uicategory.edit',
			'id'		=> $id,
			'type'		=> $type,
			'type_id'	=> $type_id
		);
		//_debug_array($link_data);

		$msgbox_data = $this->bocommon->msgbox_data($receipt);
		$phpgwapi_common = new \phpgwapi_common();

		$data = array(
			'msgbox_data'				=> $phpgwapi_common->msgbox($msgbox_data),
			'form_action'				=> phpgw::link('/index.php', $link_data),
			'done_action'				=> phpgw::link('/index.php', array('menuaction' => 'hrm.uicategory.index', 'type' => $type, 'type_id' => $type_id)),
			'lang_id'				=> lang('category ID'),
			'lang_descr'				=> lang('Descr'),
			'lang_save'				=> lang('save'),
			'lang_done'				=> lang('done'),
			'value_id'				=> $id,
			'lang_id_categorytext'			=> lang('Enter the category ID'),
			'lang_descr_categorytext'		=> lang('Enter a description the category'),
			'lang_done_categorytext'		=> lang('Back to the list'),
			'lang_save_categorytext'		=> lang('Save the category'),
			'type_id'				=> $category['type_id'],
			'value_descr'				=> $category['descr']
		);

		$appname	= lang($type) . ' ' . $type_id;

		Settings::getInstance()->update('flags', ['app_header' => lang('hrm') . ' - ' . $appname . ': ' . $function_msg]);
		phpgwapi_xslttemplates::getInstance()->set_var('phpgw', array('edit' => $data));
		//	phpgwapi_xslttemplates::getInstance()->pp();
	}

	function delete()
	{
		$type		= Sanitizer::get_var('type');
		$type_id	= Sanitizer::get_var('type_id', 'int');
		$id		= Sanitizer::get_var('id', 'int');
		$confirm	= Sanitizer::get_var('confirm', 'bool', 'POST');

		$link_data = array(
			'menuaction'	=> 'hrm.uicategory.index',
			'type'		=> $type,
			'type_id'	=> $type_id
		);

		if (Sanitizer::get_var('confirm', 'bool', 'POST'))
		{
			$this->bo->delete($id, $type, $type_id);
			phpgw::redirect_link('/index.php', $link_data);
		}

		phpgwapi_xslttemplates::getInstance()->add_file(array('app_delete'));

		$data = array(
			'done_action'			=> phpgw::link('/index.php', $link_data),
			'delete_action'			=> phpgw::link('/index.php', array('menuaction' => 'hrm.uicategory.delete', 'id' => $id, 'type' => $type, 'type_id' => $type_id)),
			'lang_confirm_msg'		=> lang('do you really want to delete this entry'),
			'lang_yes'			=> lang('yes'),
			'lang_yes_categorytext'		=> lang('Delete the entry'),
			'lang_no_categorytext'		=> lang('Back to the list'),
			'lang_no'			=> lang('no')
		);

		$appname	= lang($type) . ' ' . $type_id;
		$function_msg	= lang('delete ' . $type . ' category');

		Settings::getInstance()->update('flags', ['app_header' => lang('hrm') . ' - ' . $appname . ': ' . $function_msg]);
		phpgwapi_xslttemplates::getInstance()->set_var('phpgw', array('delete' => $data));
	}
}
