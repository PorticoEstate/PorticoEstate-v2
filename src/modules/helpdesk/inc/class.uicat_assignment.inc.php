<?php

/**
 * phpGroupWare - property: a Facilities Management System.
 *
 * @author Sigurd Nes <sigurdne@online.no>
 * @copyright Copyright (C) 2018 Free Software Foundation, Inc. http://www.fsf.org/
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
 * @subpackage helpdesk
 * @version $Id$
 */
/**
 * Description
 * @package property
 */

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\Cache;


phpgw::import_class('phpgwapi.uicommon');

class helpdesk_uicat_assignment extends phpgwapi_uicommon
{

	var $public_functions = array(
		'edit'			=> true,
	);

	protected
		$acl_location,
		$acl_read,
		$acl_add,
		$acl_edit,
		$acl_delete,
		$bo,
		$cats,
		$receipt;

	public function __construct()
	{
		parent::__construct();

		self::set_active_menu("admin::helpdesk::cat_assignment");

		Settings::getInstance()->update('flags', ['app_header' => lang('helpdesk') . '::' . lang('category assignment')]);

		$this->bo			= CreateObject('helpdesk.bocat_assignment');
		$this->cats			= $this->bo->cats;
		$this->acl_location = '.ticket';
		$this->acl_read = $this->acl->check($this->acl_location, ACL_READ, $this->currentapp);
		$this->acl_add = $this->acl->check($this->acl_location, ACL_ADD, $this->currentapp);
		$this->acl_edit = $this->acl->check($this->acl_location, ACL_EDIT, $this->currentapp);
		$this->acl_delete = $this->acl->check($this->acl_location, ACL_DELETE, $this->currentapp);
	}

	public function add()
	{
		if (!$this->acl_add)
		{
			phpgw::no_access();
		}

		$this->edit();
	}

	public function view()
	{
		if (!$this->acl_read)
		{
			phpgw::no_access();
		}

		/**
		 * Do not allow save / send here
		 */
		if (Sanitizer::get_var('save', 'bool') || Sanitizer::get_var('send', 'bool') || Sanitizer::get_var('init_preview', 'bool'))
		{
			phpgw::no_access();
		}
		$this->edit(array(), 'view');
	}


	public function edit($values = array(), $mode = 'edit', $error = false)
	{
		if (!$this->acl_add || !$this->acl_edit)
		{
			phpgw::no_access();
		}


		if (!$error && (Sanitizer::get_var('save', 'bool') || Sanitizer::get_var('send', 'bool')))
		{
			$this->save();
		}

		$group_list = createObject('helpdesk.botts')->get_group_list();

		$categories = $this->cats->return_sorted_array(0, false);
		$cat_assignment = $this->bo->read();
		//			_debug_array($cat_assignment);die();

		$cat_header[] = array(
			'lang_name'				=> lang('name'),
			'lang_status'			=> lang('status'),
			'lang_edit'				=> lang('edit'),
		);

		$content = array();
		foreach ($categories as $cat)
		{
			$level		= $cat['level'];
			$cat_name	= phpgw::strip_html($cat['name']);

			$main = 'yes';
			if ($level > 0)
			{
				$space = ' . ';
				$spaceset = str_repeat($space, $level);
				$cat_name = $spaceset . $cat_name;
				$main = 'no';
			}

			$selected_group = !empty($cat_assignment[$cat['id']]['group_id']) ? $cat_assignment[$cat['id']]['group_id'] : 0;

			$_group_list = $group_list;

			foreach ($_group_list as &$group)
			{
				$group['selected'] = $selected_group == $group['id'] ? 1 : 0;
			}

			$content[] = array(
				'cat_id'					=> $cat['id'],
				'name'						=> $cat_name,
				'group_list'				=> array('options' => $_group_list),
				'main'						=> $main,
				'status'					=> $cat['active'],
				'status_text'				=> $cat['active'] == 1 ? 'active' : 'disabled',
			);
		}

		$link_data['menuaction'] = 'helpdesk.uicat_assignment.edit';

		$cat_add[] = array(
			'lang_add'				=> lang('add'),
			'lang_add_statustext'	=> lang('add a category'),
			'action_url'			=> phpgw::link('/index.php', $link_data),
			'lang_done'				=> lang('done'),
			'lang_done_statustext'	=> lang('return to admin mainscreen')
		);
		$data = array(
			'form_action'	=> self::link(array('menuaction' => "{$this->currentapp}.uicat_assignment.edit")),
			'edit_action' => self::link(array('menuaction' => "{$this->currentapp}.uicat_assignment.edit")),
			'cancel_url' => self::link(array('menuaction' => "{$this->currentapp}.uitts.index")),
			'cat_header'	=> $cat_header,
			'cat_data'		=> $content,
			'cat_add'		=> $cat_add
		);

		Settings::getInstance()->update('flags', ['app_header' => lang('helpdesk') . '::' . lang('category assignment') . '::' . lang($mode)]);

		self::render_template_xsl(array('cat_assignment'), array('edit' => $data));
	}


	public function save($dummy = false)
	{
		$values = Sanitizer::get_var('values');

		try
		{
			$receipt = $this->bo->save($values);
		}
		catch (Exception $e)
		{
			if ($e)
			{
				Cache::message_set($e->getMessage(), 'error');
				$this->edit($values, 'edit', $error = true);
				return;
			}
		}

		$this->receipt['message'][] = array('msg' => lang('category assignment has been saved'));

		self::message_set($this->receipt);
		self::redirect(array('menuaction' => "{$this->currentapp}.uicat_assignment.edit"));
	}
}
