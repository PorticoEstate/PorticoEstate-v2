<?php
/*	 * ************************************************************************\
	 * phpGroupWare - Messenger                                                 *
	 * http://www.phpgroupware.org                                              *
	 * This application written by Joseph Engo <jengo@phpgroupware.org>         *
	 * --------------------------------------------                             *
	 * Funding for this program was provided by http://www.checkwithmom.com     *
	 * --------------------------------------------                             *
	 *  This program is free software; you can redistribute it and/or modify it *
	 *  under the terms of the GNU General Public License as published by the   *
	 *  Free Software Foundation; either version 2 of the License, or (at your  *
	 *  option) any later version.                                              *
	  \************************************************************************* */

use App\helpers\Template;
use App\modules\phpgwapi\security\Acl;
use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\controllers\Accounts\Accounts;
use App\modules\phpgwapi\services\Log;


phpgw::import_class('phpgwapi.uicommon_jquery');

class messenger_uimessenger extends phpgwapi_uicommon_jquery
{

	var $bo;
	var $template, $acl_location, $acl_read, $acl_add, $acl_edit, $acl_delete, $nextmatchs;
	var $public_functions = array(
		'index' => true,
		'inbox' => true,
		'compose' => true,
		'compose_groups' => true,
		'compose_global' => true,
		'read_message' => true,
		'reply' => true,
		'forward' => true,
		'delete' => true
	);

	function __construct()
	{
		parent::__construct();

		$this->template = Template::getInstance();
		$this->bo = CreateObject('messenger.bomessenger');
		$this->nextmatchs = createobject('phpgwapi.nextmatchs');
		if (!$this->bo->is_connected())
		{
			$this->_error_not_connected();
		}
		$this->acl_location = 'run';
		$this->acl_read = $this->acl->check($this->acl_location, ACL_READ, 'messenger');
		$this->acl_add = $this->acl->check($this->acl_location, ACL_ADD, 'messenger');
		$this->acl_edit = $this->acl->check($this->acl_location, ACL_EDIT, 'messenger');
		$this->acl_delete = $this->acl->check($this->acl_location, ACL_DELETE, 'messenger');
	}

	function compose($errors = '')
	{
		if (!$this->acl->check('.compose', ACL_ADD, 'messenger'))
		{
			$this->_no_access('compose');
		}

		$query = Sanitizer::get_var('query', 'string', 'REQUEST', '###');
		phpgwapi_jquery::load_widget('select2');
		$lang_to = lang('to');
		$code		 = <<<JS
$(document).ready(function ()
{


	$("#recipient").select2({
	  ajax: {
		url: phpGWLink('index.php', {menuaction: 'preferences.boadmin_acl.get_users'}, true),
		dataType: 'json',
		delay: 250,
		data: function (params) {
		  return {
			query: params.term, // search term
			page: params.page || 1
		  };
		},
		cache: true
	  },
	  width: '100%',
	  placeholder: "{$lang_to}",
	  minimumInputLength: 2,
	  language: "no",
	  allowClear: true
	});

	// Fetch the preselected item, and add to the control
	var studentSelect = $('#recipient');
	$.ajax({
		type: 'GET',
		url: phpGWLink('index.php', {menuaction: 'preferences.boadmin_acl.get_users', query: '{$query}'}, true),

	}).then(function (data) {
		// create the option and append to Select2
		
		if(typeof(data.results[0]) !== undefined)
		{
			var user = data.results[0];
			var option = new Option(user.text, data.id, true, true);
			studentSelect.append(option).trigger('change');

			// manually trigger the `select2:select` event
			studentSelect.trigger({
				type: 'select2:select',
				params: {
					data: user
				}
			});
		}
	});
});

JS;
		phpgwapi_js::getInstance()->add_code('', $code);

		self::set_active_menu('messenger::compose');

		$message = isset($_POST['message']) ? $_POST['message'] : array(
			'subject' => '',
			'content' => ''
		);

		$this->_display_headers();
		$this->_set_compose_read_blocks();

		if (is_array($errors))
		{
			$this->template->set_var('errors', $this->phpgwapi_common->error_list($errors));
		}

		$this->_set_common_langs();
		$this->template->set_var('header_message', lang('Compose message'));

		//			$users = $this->bo->get_available_users();
		//
		//			array_unshift($users, array
		//					(
		//					'uid' => '',
		//					'full_name' => lang('select')
		//				));
		//
		//			foreach ($users as $uid => $name)
		//			{
		//				$this->template->set_var(array
		//					(
		//					'uid' => $uid,
		//					'full_name' => $name
		//				));
		//				$this->template->parse('select_tos', 'select_to', true);
		//			}

		$this->template->set_var('form_action', phpgw::link('/index.php', array(
			'menuaction' => 'messenger.bomessenger.send_message'
		)));
		//$this->template->set_var('value_to','<input name="message[to]" value="' . $message['to'] . '" size="30">');
		$this->template->set_var('value_subject', '<input class="form-control" name="message[subject]" value="' . $message['subject'] . '" size="30">');
		$this->template->set_var('value_content', '<textarea class="form-control" name="message[content]" rows="20" wrap="hard" cols="76">' . $message['content'] . '</textarea>');

		$this->template->set_var('button_send', '<input class="btn btn-primary" type="submit" name="send" value="' . lang('Send') . '">');
		$this->template->set_var('button_cancel', '<input class="btn btn-primary" type="submit" name="cancel" value="' . lang('Cancel') . '">');

		$this->template->fp('to', 'form_to');
		$this->template->fp('buttons', 'form_buttons');
		$this->template->pfp('out', 'form');
	}

	function compose_groups()
	{
		if (!$this->acl->check('.compose_groups', ACL_ADD, 'messenger'))
		{
			$this->_no_access('compose_groups');
		}

		Settings::getInstance()->update('flags', ['xslt_app' => true]);
		self::set_active_menu('messenger::compose_groups');

		$values = Sanitizer::get_var('values');
		$values['account_groups'] = (array)Sanitizer::get_var('account_groups', 'int', 'POST');
		$receipt = array();

		if (isset($values['save']))
		{
			if (!$values['account_groups'])
			{
				$receipt['error'][] = array('msg' => lang('Missing groups'));
			}

			if (phpgw::is_repost())
			{
				$receipt['error'][] = array('msg' => lang('repost'));
			}

			if (!isset($values['subject']) || !$values['subject'])
			{
				$receipt['error'][] = array('msg' => lang('Missing subject'));
			}

			if (!isset($values['content']) || !$values['content'])
			{
				$receipt['error'][] = array('msg' => lang('Missing content'));
			}

			if (isset($values['save']) && $values['account_groups'] && !$receipt['error'])
			{
				$receipt = $this->bo->send_to_groups($values);
			}
		}
		$group_list = array();

		$accounts_obj = new Accounts();
		$all_groups = $accounts_obj->get_list('groups');

		if (!$this->acl->check('run', Acl::READ, 'admin'))
		{
			$available_apps = $this->apps;
			$valid_groups = array();
			foreach ($available_apps as $_app => $dummy)
			{
				if ($this->acl->check('admin', Acl::ADD, $_app))
				{
					$valid_groups = array_merge($valid_groups, $this->acl->get_ids_for_location('run', Acl::READ, $_app));
				}
			}

			$valid_groups = array_unique($valid_groups);
		}
		else
		{
			$valid_groups = array_keys($all_groups);
		}

		foreach ($all_groups as $group)
		{
			$group_list[$group->id] = array(
				'account_id' => $group->id,
				'account_lid' => $group->__toString(),
				'i_am_admin' => in_array($group->id, $valid_groups),
				'selected' => in_array($group->id, $values['account_groups'])
			);
		}

		$data = array(
			'msgbox_data' => $this->phpgwapi_common->msgbox($this->phpgwapi_common->msgbox_data($receipt)),
			'form_action' => phpgw::link('/index.php', array('menuaction' => 'messenger.uimessenger.compose_groups')),
			'group_list' => $group_list,
			'value_subject' => isset($values['subject']) ? $values['subject'] : '',
			'value_content' => isset($values['content']) ? $values['content'] : ''
		);

		phpgwapi_jquery::load_widget('bootstrap-multiselect');

		phpgwapi_xslttemplates::getInstance()->add_file(array('messenger'));
		phpgwapi_xslttemplates::getInstance()->set_var('phpgw', array('compose_groups' => $data));
	}

	function compose_global($errors = '')
	{
		if (!$this->acl->check('.compose_global', ACL_ADD, 'messenger'))
		{
			$this->_no_access('compose_global');
		}

		self::set_active_menu('messenger::compose_global');

		global $message;

		$appname = lang('messenger');
		$function_msg = lang('compose global');

		Settings::getInstance()->update('flags', ['app_header' => "{$appname}::{$function_msg}"]);
		$this->_display_headers();
		$this->_set_compose_read_blocks();

		if (is_array($errors))
		{
			$this->template->set_var('errors', $this->phpgwapi_common->error_list($errors));
		}

		$this->_set_common_langs();
		$this->template->set_var('header_message', lang('Compose global message'));

		$this->template->set_var('form_action', phpgw::link('/index.php', array(
			'menuaction' => 'messenger.bomessenger.send_global_message'
		)));
		$this->template->set_var('value_subject', '<input class="form-control" name="message[subject]" value="' . $message['subject'] . '">');
		$this->template->set_var('value_content', '<textarea  class="form-control" name="message[content]" rows="20" wrap="hard" cols="76">' . $message['content'] . '</textarea>');

		$this->template->set_var('button_send', '<input type="submit" class="btn btn-primary" name="send" value="' . lang('Send') . '">');
		$this->template->set_var('button_cancel', '<input type="submit" class="btn btn-primary" name="cancel" value="' . lang('Cancel') . '">');

		$this->template->fp('buttons', 'form_buttons');
		$this->template->pfp('out', 'form');
	}

	function delete()
	{
		$messages = $_REQUEST['messages'];
		$this->bo->delete_message($messages);

		//			$this->inbox();
	}

	public function query()
	{

		$search = Sanitizer::get_var('search');
		$order = Sanitizer::get_var('order');
		$draw = Sanitizer::get_var('draw', 'int');
		$columns = Sanitizer::get_var('columns');

		$params = array(
			'start' => $this->start,
			'results' => Sanitizer::get_var('length', 'int', 'REQUEST', 0),
			'query' => $search['value'],
			'sort' => $order[0]['dir'],
			'order' => $columns[$order[0]['column']]['data'],
			'allrows' => Sanitizer::get_var('length', 'int') == -1,
			'entity_id' => $entity_id,
			'cat_id' => $cat_id,
			'status' => Sanitizer::get_var('status')
		);

		switch ($columns[$order[0]['column']]['data'])
		{
			case 'id':
				$params['order'] = 'message_id';
				break;
			case 'from':
				$params['order'] = 'message_from';
				break;
			case 'subject':
				$params['order'] = 'message_subject';
				break;
			default:
				$params['order'] = $columns[$order[0]['column']]['data'];
				break;
		}

		$result_objects = array();
		$result_count = 0;

		$values = $this->bo->read_inbox($params);

		$new_values = array();
		foreach ($values as &$value)
		{
			$value['status'] = $value['status'] == '&nbsp;' ? '' : $value['status'];
			$value['message_date'] = $value['date'];
			$value['subject_text'] = $value['subject'];
			$value['subject'] = "<a href='" . phpgw::link('/index.php', array(
				'menuaction' => 'messenger.uimessenger.read_message',
				'message_id' => $value['id']
			)) . "'>" . $value['subject'] . "</a>";
			$new_values[] = $value;
		}

		if (Sanitizer::get_var('export', 'bool'))
		{
			return $new_values;
		}

		$result_data = array('results' => $new_values);
		$result_data['total_records'] = $this->bo->total_messages();
		$result_data['draw'] = $draw;
		$variable = array('menuaction' => 'messenger.uimessenger.read_message');
		array_walk($result_data['results'], array($this, '_add_links'), $variable);
		return $this->jquery_results($result_data);
	}

	function index()
	{
		if (!$this->acl_read)
		{
			phpgw::no_access();
		}

		self::set_active_menu('messenger::inbox');

		if (Sanitizer::get_var('phpgw_return_as') == 'json')
		{

			return $this->query();
		}

	
		$appname = lang('messenger');
		$function_msg = lang('inbox');

		Settings::getInstance()->update('flags', ['app_header' => "{$appname}::{$function_msg}"]);

		$data = array(
			'datatable_name' => $appname,
			'form' => array(
				'toolbar' => array(
					'item' => array()
				)
			),
			'datatable' => array(
				'source' => self::link(array(
					'menuaction' => 'messenger.uimessenger.index',
					'phpgw_return_as' => 'json'
				)),
				'allrows' => true,
				'editor_action' => '',
				'new_item'		 => self::link(array(
					'menuaction' => 'messenger.uimessenger.compose'
				)),
				'field' => array(
					array(
						'key' => 'id',
						'label' => lang('id'),
						'sortable' => false
					),
					//						array(
					//							'key' => 'status',
					//							'label' => lang('status'),
					//							'sortable' => false
					//						),
					array(
						'key' => 'message_date',
						'label' => lang('date'),
						'sortable' => true
					),
					array(
						'key' => 'from',
						'label' => lang('from'),
						'sortable' => true
					),
					array(
						'key' => 'subject',
						'label' => lang('subject'),
						'sortable' => true
					),
					array(
						'key' => 'select',
						'label' => lang('select'),
						'sortable' => false,
						'formatter' => 'JqueryPortico.formatCheckCustom'
					)
				)
			)
		);

		$parameters = array(
			'parameter' => array(
				array(
					'name' => 'id',
					'source' => 'id'
				),
			)
		);

		if ($this->acl_edit)
		{

			$data['datatable']['actions'][] = array(
				'my_name' => 'reply',
				'statustext' => lang('reply'),
				'text' => lang('reply'),
				'action' => phpgw::link(
					'/index.php',
					array(
						'menuaction' => 'messenger.uimessenger.reply'
					)
				),
				'parameters' => json_encode($parameters)
			);
		}

		//		if($this->acl_delete)
		{
			$data['datatable']['actions'][] = array(
				'my_name' => 'delete',
				'statustext' => lang('Delete'),
				'text' => lang('Delete'),
				'confirm_msg' => lang('do you really want to delete this entry'),
				'action' => phpgw::link(
						'/index.php',
						array('menuaction' => 'messenger.uimessenger.delete')
					),
				'parameters' => json_encode($parameters)
			);
		}

		unset($parameters);

		self::render_template_xsl('datatable2', $data);
	}

	function inbox()
	{
		$start = (int)Sanitizer::get_var('start', 'int', 'REQUEST', 0);
		$order = Sanitizer::get_var('order', 'string');
		$sort = Sanitizer::get_var('sort', 'string');
		$total = $this->bo->total_messages();

		$extra_menuaction = '&menuaction=messenger.uimessenger.inbox';
		$extra_header_info['nextmatchs_left'] = $this->nextmatchs->left('/index.php', $start, $total, $extra_menuaction);
		$extra_header_info['nextmatchs_right'] = $this->nextmatchs->right('/index.php', $start, $total, $extra_menuaction);

		$this->_display_headers($extra_header_info);

		$this->template->set_file('_inbox', 'inbox.tpl');
		$this->template->set_block('_inbox', 'row', 'rows');
		$this->template->set_block('_inbox', 'list');
		$this->template->set_block('_inbox', 'row_empty');

		$this->_set_common_langs();
		$this->template->set_var('sort_date', '<a href="' . $this->nextmatchs->show_sort_order($sort, 'message_date', $order, '/index.php', '', '&menuaction=messenger.uimessenger.inbox', False) . '" class="topsort">' . lang('Date') . '</a>');
		$this->template->set_var('sort_subject', '<a href="' . $this->nextmatchs->show_sort_order($sort, 'message_subject', $order, '/index.php', '', '&menuaction=messenger.uimessenger.inbox', False) . '" class="topsort">' . lang('Subject') . '</a>');
		$this->template->set_var('sort_from', '<a href="' . $this->nextmatchs->show_sort_order($sort, 'message_from', $order, '/index.php', '', '&menuaction=messenger.uimessenger.inbox', False) . '" class="topsort">' . lang('From') . '</a>');

		$params = array(
			'start' => $start,
			'order' => $order,
			'sort' => $sort
		);
		$messages = $this->bo->read_inbox($params);

		if (!is_array($messages))
		{
			$this->template->set_var('lang_empty', lang('You have no messages'));
			$this->template->fp('rows', 'row_empty', True);
		}
		else
		{
			$this->template->set_var('form_action', phpgw::link('/index.php', array(
				'menuaction' => 'messenger.uimessenger.delete'
			)));
			$this->template->set_var('button_delete', '<input type="image" src="' . PHPGW_IMAGES . '/delete.gif" name="delete" title="' . lang('Delete selected') . '" border="0">');
			$i = 0;
			foreach ($messages as $message)
			{
				$status = $message['status'];
				if ($message['status'] == 'N' || $message['status'] == 'O')
				{
					$status = '&nbsp;';
				}

				$this->template->set_var(array(
					'row_class' => $i % 2 ? 'row_on' : 'row_off',
					'row_date' => $message['date'],
					'row_from' => $message['from'],
					'row_msg_id' => $message['id'],
					'row_status' => $status,
					'row_subject' => phpgw::strip_html($message['subject']),
					'row_url' => phpgw::link('/index.php', array(
						'menuaction' => 'messenger.uimessenger.read_message',
						'message_id' => $message['id']
					))
				));
				$this->template->parse('rows', 'row', true);
				++$i;
			}
		}

		$this->template->pfp('out', 'list');
	}

	function read_message()
	{
		$message_id = Sanitizer::get_var('message_id', 'int');
		$message_id = $message_id ? $message_id : Sanitizer::get_var('id', 'int');
		$message = $this->bo->read_message($message_id);

		$this->_display_headers();
		$this->_set_compose_read_blocks();
		$this->_set_common_langs();

		$this->template->set_var('header_message', lang('Read message'));

		$this->template->set_var('value_from', $message['from']);
		$this->template->set_var('value_subject', phpgw::strip_html($message['subject']));
		$this->template->set_var('value_date', $message['date']);
		$this->template->set_var('value_content', nl2br(wordwrap(phpgw::strip_html($message['content']), 80)));
		$this->template->set_var('lang_delete', lang('Delete'));
		$this->template->set_var('lang_reply', lang('Reply'));
		$this->template->set_var('lang_forward', lang('Forward'));
		$this->template->set_var('lang_inbox', lang('Inbox'));
		$this->template->set_var('lang_compose', lang('Compose'));
		$this->template->set_var('value_from', $message['from']);
		$this->template->set_var('value_from', $message['from']);

		$this->template->set_var('link_delete', phpgw::link('/index.php', array(
			'menuaction' => 'messenger.uimessenger.delete',
			'messages[]' => $message['id']
		)));

		$this->template->set_var('link_reply',  phpgw::link('/index.php', array(
			'menuaction' => 'messenger.uimessenger.reply',
			'message_id' => $message['id']
		)));

		$this->template->set_var('link_forward', phpgw::link('/index.php', array(
			'menuaction' => 'messenger.uimessenger.forward',
			'message_id' => $message['id']
		)));

		$this->template->set_var('link_inbox', phpgw::link('/index.php', array(
			'menuaction' => 'messenger.uimessenger.index'
		)));
		$this->template->set_var('link_compose', phpgw::link('/index.php', array(
			'menuaction' => 'messenger.uimessenger.compose'
		)));

		switch ($message['status'])
		{
			case 'N':
				$this->template->set_var('value_status', lang('New'));
				break;
			case 'R':
				$this->template->set_var('value_status', lang('Replied'));
				break;
			case 'F':
				$this->template->set_var('value_status', lang('Forwarded'));
				break;
		}

		if (isset($message['global_message']) && $message['global_message'])
		{
			$this->template->fp('read_buttons', 'form_read_buttons_for_global');
		}
		else
		{
			$this->template->fp('read_buttons', 'form_read_buttons');
		}

		$this->template->fp('date', 'form_date');
		$this->template->fp('from', 'form_from');
		$this->template->pfp('out', 'form');
	}

	function reply($errors = '', $message = '')
	{
		$message_id = $_REQUEST['message_id'];

		if (is_array($errors))
		{
			$errors = $errors['errors'];
			$message = $errors['message'];
		}

		if (!$message)
		{
			$message = $this->bo->read_message_for_reply($message_id, 'RE');
		}

		$this->_display_headers();
		$this->_set_compose_read_blocks();
		$this->_set_common_langs();
		$this->template->set_block('_form', 'form_reply_to');

		if (is_array($errors))
		{
			$this->template->set_var('errors', $this->phpgwapi_common->error_list($errors));
		}

		$this->template->set_var('header_message', lang('Reply to a message'));

		$this->template->set_var('form_action', phpgw::link('/index.php', array(
			'menuaction' => 'messenger.bomessenger.reply',
			'message_id' => $message['id']
		)));
		$this->template->set_var('value_to', "<input type= 'hidden' name='n_message[to]' value={$message['from']}>{$message['from_fullname']}");
		$this->template->set_var('value_subject', '<input name="n_message[subject]" value="' . phpgw::strip_html(stripslashes($message['subject'])) . '" size="30">');
		$this->template->set_var('value_content', '<textarea name="n_message[content]" rows="20" wrap="hard" cols="76">' . phpgw::strip_html(stripslashes($message['content'])) . '</textarea>');

		$this->template->set_var('button_send', '<input type="submit" name="send" value="' . lang('Send') . '">');
		$this->template->set_var('button_cancel', '<input type="submit" name="cancel" value="' . lang('Cancel') . '">');

		$this->template->fp('to', 'form_reply_to');
		$this->template->fp('buttons', 'form_buttons');
		$this->template->pfp('out', 'form');
	}

	function forward($errors = array(), $message = '')
	{
		$message_id = $_REQUEST['message_id'];

		if ($errors)
		{
			$errors = $errors['errors'];
			//				$message = $errors['message'];
		}

		if (!$message)
		{
			$message = $this->bo->read_message_for_reply($message_id, 'FW');
		}

		$this->_display_headers();
		$this->_set_compose_read_blocks();
		$this->_set_common_langs();

		$users = $this->bo->get_available_users();
		foreach ($users as $uid => $name)
		{
			$this->template->set_var(array(
				'uid' => $uid,
				'full_name' => $name
			));
			$this->template->parse('select_tos', 'select_to', true);
		}


		if ($errors)
		{
			$this->template->set_var('errors', $this->phpgwapi_common->error_list($errors));
		}

		$this->template->set_var('header_message', lang('Forward a message'));

		$this->template->set_var('form_action', phpgw::link('/index.php', array(
			'menuaction' => 'messenger.bomessenger.forward',
			'message_id' => $message['id']
		)));
		$this->template->set_var('value_to', '<input name="message[to]" value="' . $message['from'] . '" size="30">');
		$this->template->set_var('value_subject', '<input name="message[subject]" value="' . phpgw::strip_html(stripslashes($message['subject'])) . '" size="30">');
		$this->template->set_var('value_content', '<textarea name="message[content]" rows="20" wrap="hard" cols="76">' . phpgw::strip_html(stripslashes($message['content'])) . '</textarea>');

		$this->template->set_var('button_send', '<input type="submit" name="send" value="' . lang('Send') . '">');
		$this->template->set_var('button_cancel', '<input type="submit" name="cancel" value="' . lang('Cancel') . '">');

		$this->template->fp('to', 'form_to');
		$this->template->fp('buttons', 'form_buttons');
		$this->template->pfp('out', 'form');
	}

	function _display_headers($extras = '')
	{
		$this->template->set_file('_header', 'header.tpl');
		$this->template->set_block('_header', 'global_header');
		$this->template->set_var('lang_inbox', '<a href="' . phpgw::link('/index.php', array(
			'menuaction' => 'messenger.uimessenger.inbox'
		)) . '">' . lang('Inbox') . '</a>');
		$this->template->set_var('lang_compose', '<a href="' . phpgw::link('/index.php', array(
			'menuaction' => 'messenger.uimessenger.compose'
		)) . '">' . lang('Compose') . '</a>');

		if (isset($extras['nextmatchs_left']) && $extras['nextmatchs_left'])
		{
			$this->template->set_var('nextmatchs_left', $extras['nextmatchs_left']);
		}

		if (isset($extras['nextmatchs_right']) && $extras['nextmatchs_right'])
		{
			$this->template->set_var('nextmatchs_right', $extras['nextmatchs_right']);
		}

		$this->template->fp('app_header', 'global_header');

		$this->phpgwapi_common->phpgw_header(true);
	}

	function _error_not_connected()
	{
		$this->_display_headers();
		die(lang('exiting with error!') . "<br />\n" . lang('Unable to connect to server, please contact your system administrator'));
	}

	function _set_common_langs()
	{
		$this->template->set_var('lang_to', lang('Send message to'));
		$this->template->set_var('lang_from', lang('Message from'));
		$this->template->set_var('lang_subject', lang('Subject'));
		$this->template->set_var('lang_content', lang('Message'));
		$this->template->set_var('lang_date', lang('Date'));
	}

	function _set_compose_read_blocks()
	{
		$this->template->set_file('_form', 'form.tpl');

		$this->template->set_block('_form', 'form');
		$this->template->set_block('_form', 'select_to', 'select_tos');
		$this->template->set_block('_form', 'form_to');
		$this->template->set_block('_form', 'form_date');
		$this->template->set_block('_form', 'form_from');
		$this->template->set_block('_form', 'form_buttons');
		$this->template->set_block('_form', 'form_read_buttons');
		$this->template->set_block('_form', 'form_read_buttons_for_global');
	}

	function _no_access($location)
	{
		$this->phpgwapi_common->phpgw_header(true);

		$log_args = array(
			'severity' => 'W',
			'text' => 'W-Permissions, Attempted to access %1 from %2',
			'p1' => "{$this->flags['currentapp']}::{$location}",
			'p2' => Sanitizer::get_ip_address(true)
		);

		$log = new Log();
		$log->warn($log_args);

		$lang_denied = lang('Access not permitted');
		echo <<<HTML
			<div class="error">$lang_denied</div>
HTML;
		$this->phpgwapi_common->phpgw_exit(True);
	}
}
