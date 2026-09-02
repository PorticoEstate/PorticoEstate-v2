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

/* $Id$ */

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\controllers\Accounts\Accounts;
use App\modules\phpgwapi\security\Acl;


class messenger_bomessenger
{

	var $so, $userSettings, $accounts_obj, $phpgwapi_common;
	var $public_functions = array(
		'delete_message' => true,
		'send_message' => true,
		'send_global_message' => true,
		'reply' => true,
		'forward' => true,
		'list_methods' => true
	);
	var $soap_functions = array();

	/**
	 * Set up the storage object and cache the current user/account context.
	 */
	function __construct()
	{
		$this->so = createobject('messenger.somessenger');
		$this->userSettings = Settings::getInstance()->get('user');
		$this->accounts_obj = new Accounts();
		$this->phpgwapi_common = new \phpgwapi_common();
	}

	/**
	 * List the users the current user is allowed to send messages to.
	 *
	 * Restricted to members of the configured group(s) unless the current
	 * user is an admin, in which case all enabled accounts are returned.
	 *
	 * @return array Map of account_id => display name
	 */
	function get_available_users()
	{
		$users = array();

		$config = createObject('phpgwapi.config', 'messenger');
		$config->read();
		if (
			isset($config->config_data['restrict_to_group']) && $config->config_data['restrict_to_group'] && !isset($this->userSettings['apps']['admin'])
		)
		{

			foreach ($config->config_data['restrict_to_group'] as $restrict_to_group)
			{
				$tmp_users = $this->accounts_obj->member($restrict_to_group, true);

				foreach ($tmp_users as $user)
				{
					$users[$user['account_id']] = $user['account_name'];
				}
			}

			if ($users)
			{
				array_multisort($users, SORT_ASC, $users);
			}
		}
		else
		{
			$tmp_users = $this->accounts_obj->get_list('accounts', -1, 'ASC', 'account_lastname', '', -1);
			foreach ($tmp_users as $user)
			{
				if ($user->enabled)
				{
					$users[$user->id] = $this->phpgwapi_common->display_fullname($user->lid, $user->firstname, $user->lastname);
				}
			}
		}
		return $users;
	}

	/**
	 * Validate and send a message to every account (admin only), then redirect.
	 *
	 * @param array|string $data Array with 'message'/'send'/'cancel', or '' to read from Sanitizer
	 * @return bool|void False when the caller lacks permission or the request was cancelled/invalid
	 */
	function send_global_message($data = '')
	{
		if (is_array($data))
		{
			$message = $data['message'];
			$send = $data['send'];
			$cancel = $data['cancel'];
		}
		else
		{
			$message = Sanitizer::get_var('message');
			$send = Sanitizer::get_var('send');
			$cancel = Sanitizer::get_var('cancel');
		}

		$acl = Acl::getInstance();
		if (!$acl->check('run', 1, 'admin') || $cancel)
		{
			phpgw::redirect_link('/messenger/view/inbox');
			return False;
		}

		if (!$message['subject'])
		{
			$errors[] = lang('You must enter a subject');
		}

		if (!$message['content'])
		{
			$errors[] = lang("You didn't enter anything for the message");
		}

		if (is_array($errors))
		{
			phpgw::redirect_link('/messenger/view/compose-global');
			return False;
		}
		else
		{
			$account_info = $this->accounts_obj->get_list('accounts');

			$this->so->db->transaction_begin();

			foreach ($account_info as $account)
			{
				$message['to'] = $account->id;
				$this->so->send_message($message, True);
			}
			$this->so->db->transaction_commit();
			phpgw::redirect_link('/messenger/view/inbox');
		}
	}

	/**
	 * Validate a message's recipient and required fields.
	 *
	 * @param array $message Message data with keys 'to', 'subject', 'content'
	 * @return array List of localized error strings, empty when valid
	 */
	function check_for_missing_fields($message)
	{
		$errors = array();
		if ($message['to'] > 0)
		{
			$user = $this->get_available_users();

			if (!isset($user[$message['to']]))
			{
				$errors[] = lang('You are not allow to send messages to the user you have selected');
			}
		}
		else
		{
			$errors[] = lang('You must select a user to send this message to');
		}

		$acct = createobject('phpgwapi.accounts', $message['to']);
		$acct->read();
		if ($acct->is_expired() && $this->accounts_obj->name2id($message['to']))
		{
			$errors[] = lang("Sorry, %1's account is not currently active", $message['to']);
		}

		if (!$message['subject'])
		{
			$errors[] = lang('You must enter a subject');
		}

		if (!$message['content'])
		{
			$errors[] = lang("You didn't enter anything for the message");
		}
		return $errors;
	}

	/**
	 * @return bool Whether the underlying message store connection is active
	 */
	function is_connected()
	{
		return $this->so->connected;
	}

	/**
	 * Send a message to every member of one or more account groups.
	 *
	 * @param array $values Form data with 'account_groups', 'subject', 'content'
	 * @return array Receipt entries confirming delivery per recipient
	 */
	public function send_to_groups($values)
	{
		foreach ($values['account_groups'] as $group)
		{
			$members = $this->accounts_obj->member($group);

			if (isset($members) and is_array($members))
			{
				foreach ($members as $user)
				{
					$accounts[$user['account_id']] = array(
						'account_id' => $user['account_id'],
						'account_name' => $user['account_name']
					);
				}
				unset($members);
			}
		}
		$receipt = array();
		foreach ($accounts as $account)
		{
			$this->so->send_message(array(
				'to' => $account['account_id'],
				'subject' => $values['subject'],
				'content' => $values['content']
			));
			$receipt['message'][] = array('msg' => lang('message sent to' . " {$account['account_name']}"));
		}
		return $receipt;
	}

	/**
	 * Validate and send a single message to its recipient, then redirect.
	 *
	 * @param array|string $data Array with 'message'/'send'/'cancel', or '' to read from $_POST
	 * @return bool|void False when required fields are missing
	 */
	function send_message($data = '')
	{
		if (is_array($data))
		{
			$message = $data['message'];
			$send = $data['send'];
			$cancel = $data['cancel'];
		}
		else
		{
			$message = $_POST['message'];
			$send = isset($_POST['send']) ? !!$_POST['send'] : false;
			$cancel = isset($_POST['cancel']) ? !!$_POST['cancel'] : false;
		}

		if ($cancel)
		{
			phpgw::redirect_link('/messenger/view/inbox');
			exit;
		}

		$errors = $this->check_for_missing_fields($message);

		if (count($errors))
		{
			phpgw::redirect_link('/messenger/view/compose');
			return False;
		}
		else
		{
			$this->so->send_message($message);
			phpgw::redirect_link('/messenger/view/inbox');
		}
	}

	
	/**
	 * @param string $status One of the N/R/O/F message status codes
	 * @return string Localized label for the status code, empty string if unknown
	 */
	private function get_status_text($status)
	{
		
		static $status_texts = [
			'N' => lang('New'),
			'R' => lang('Replied'),
			'O' => lang('Old'),
			'F' => lang('Forwarded'),
		];
	
		return isset($status_texts[$status]) ? $status_texts[$status] : '';
	}
	
	
	/**
	 * Fetch the inbox listing and format it for display (status text, account names, dates).
	 *
	 * @param array $params Filter/sort/pagination criteria, passed through to the storage object
	 * @return array List of formatted message rows
	 */
	function read_inbox($params)
	{
		$_messages = array();

		$messages = $this->so->read_inbox($params);

		foreach ($messages as $message)
		{

			$message['status_text'] = $this->get_status_text($message['status']);
			if ($message['from'] == -1)
			{
				$cached['-1'] = -1;
				$cached_names['-1'] = lang('Global Message');
			}

			// Cache our results, so we don't query the same account multiable times
			if (!isset($cached[$message['from']]) || !$cached[$message['from']])
			{
				$acct = $this->accounts_obj->get($message['from']);
				$cached[$message['from']] = $message['from'];
				$cached_names[$message['from']] = $acct->__toString();
			}

			/*
				 * * N - New
				 * * R - Replied
				 * * O - Old (read)
				 * * F - Forwarded
				 */
			if ($message['status'] == 'N')
			{
				$message['subject'] = '<b>' . $message['subject'] . '</b>';
//				$message['status'] = '&nbsp;';
				$message['date'] = '<b>' . $this->phpgwapi_common->show_date($message['date']) . '</b>';
				$message['from'] = '<b>' . $cached_names[$message['from']] . '</b>';
			}
			else
			{
				$message['date'] = $this->phpgwapi_common->show_date($message['date']);
				$message['from'] = $cached_names[$message['from']];
			}

			if ($message['status'] == 'O')
			{
//				$message['status'] = '&nbsp;';
			}

			$_messages[] = array(
				'id' => $message['id'],
				'from' => $message['from'],
				'status' => $message['status'],
				'status_text' => $message['status_text'],
				'date' => $message['date'],
				'subject' => $message['subject']
			);
		}
		return $_messages;
	}

	/**
	 * Fetch a single message and format it for display (date, sender name).
	 *
	 * @param int $message_id
	 * @return array The message, with 'from' resolved to a display name
	 */
	function read_message($message_id)
	{
		$message = $this->so->read_message($message_id);

		$message['date'] = $this->phpgwapi_common->show_date($message['date']);

		if ($message['from'] == -1)
		{
			$message['from'] = lang('Global Message');
			$message['global_message'] = True;
		}
		else if (!empty($message['from']))
		{
			$acct = $this->accounts_obj->get($message['from']);
			$message['from'] = $acct->__toString();
		}

		return $message;
	}

	/**
	 * Fetch a message and prepare a quoted reply/forward draft from it.
	 *
	 * @param int $message_id The original message being replied to/forwarded
	 * @param string $type Prefix added to the subject, e.g. 'Re' or 'Fwd'
	 * @param array|string $n_message New message data, or '' to read 'n_message' from Sanitizer
	 * @return array The original message with 'subject', 'content' and 'from_fullname' prepared for the reply
	 */
	function read_message_for_reply($message_id, $type, $n_message = '')
	{
		if (!$n_message)
		{
			$n_message = Sanitizer::get_var('n_message');
		}

		$message = $this->so->read_message($message_id);

		$acct = $this->accounts_obj->get($message['from']);

		if (!$n_message['content'])
		{
			$content_array = explode("\n", $message['content']);

			$new_content_array[] = ' ';
			$new_content_array[] = '> ' . $acct->__toString() . ' wrote:';
			$new_content_array[] = '>';
			//while (list(, $line) = each($content_array))
			foreach ($content_array as $key => $line)
			{
				$new_content_array[] = '> ' . $line;
			}
			$message['content'] = implode("\n", $new_content_array);
		}

		$message['subject'] = $type . ': ' . $message['subject'];
		$message['from_fullname'] = $acct->__toString();
		return $message;
	}

	/**
	 * Delete one or more messages, then redirect back to the inbox.
	 *
	 * @param array|string $messages List of message IDs, or '' to read 'messages' from Sanitizer
	 * @return bool|void False when $messages does not resolve to an array
	 */
	function delete_message($messages = '')
	{
		if (!$messages)
		{
			$messages = Sanitizer::get_var('messages');
		}

		if (!is_array($messages))
		{
			phpgw::redirect_link('/messenger/view/inbox');
			return False;
		}
		$this->so->transaction_begin();
		//while (list(, $message_id) = each($messages))
		foreach ($messages as $key => $message_id)
		{
			$this->so->delete_message($message_id);
		}
		$this->so->transaction_commit();
		phpgw::redirect_link('/messenger/view/inbox');
	}

	/**
	 * Validate and send a reply, mark the original message as replied, then redirect.
	 *
	 * @param int|string $message_id ID of the message being replied to, or '' to read from Sanitizer
	 * @param array|string $n_message New message data, or '' to read 'n_message' from Sanitizer
	 * @return bool|void False when required fields are missing
	 */
	function reply($message_id = '', $n_message = '')
	{
		if (Sanitizer::get_var('cancel', 'bool') == true)
		{
			phpgw::redirect_link('/messenger/view/inbox');
		}
		if (!$message_id)
		{
			$message_id = Sanitizer::get_var('message_id');
			$n_message = Sanitizer::get_var('n_message');
		}

		$errors = $this->check_for_missing_fields($n_message);
		if ($errors)
		{
			phpgw::redirect_link('/messenger/view/messages/' . (int) $message_id . '/reply');
			return False;
		}
		else
		{
			$this->so->send_message($n_message);
			$this->so->update_message_status('R', $message_id);
			phpgw::redirect_link('/messenger/view/inbox');
		}
	}

	/**
	 * Validate and send a forwarded message, mark the original as forwarded, then redirect.
	 *
	 * @param int|string $message_id ID of the message being forwarded, or '' to read from Sanitizer
	 * @param array|string $n_message Unused when $message_id is empty; forwarded content is read from Sanitizer as 'message'
	 * @return bool|void False when required fields are missing
	 */
	function forward($message_id = '', $n_message = '')
	{
		if (!$message_id)
		{
			$message_id = Sanitizer::get_var('message_id');
			$message = Sanitizer::get_var('message');
		}

		$errors = $this->check_for_missing_fields($message);

		if ($errors)
		{
			phpgw::redirect_link('/messenger/view/messages/' . (int) $message_id . '/forward');
			return False;
		}
		else
		{
			$this->so->send_message($message);
			$this->so->update_message_status('F', $message_id);
			phpgw::redirect_link('/messenger/view/inbox');
		}
	}

	/**
	 * @param string $extra_where_clause Additional raw SQL appended to the WHERE clause
	 * @return int Number of messages in the current user's inbox
	 */
	function total_messages($extra_where_clause = '')
	{
		return $this->so->total_messages($extra_where_clause);
	}

	/**
	 * Describe this class's remotely callable methods, for XML-RPC/SOAP discovery.
	 *
	 * @param string|array $_type 'xmlrpc' or 'soap', or an array with a 'type'/[0] entry
	 * @return array Method signatures/docstrings for the requested protocol
	 */
	function list_methods($_type = 'xmlrpc')
	{
		/*
			  This handles introspection or discovery by the logged in client,
			  in which case the input might be an array.  The server always calls
			  this function to fill the server dispatch map using a string.
			 */
		if (is_array($_type))
		{
			$_type = $_type['type'] ? $_type['type'] : $_type[0];
		}
		switch ($_type)
		{
			case 'xmlrpc':
				$xml_functions = array(
					'delete_message' => array(
						'function' => 'delete_message',
						'signature' => array(array(xmlrpcStruct, xmlrpcStruct)),
						'docstring' => lang('Delete a message.')
					),
					'read_message' => array(
						'function' => 'read_message',
						'signature' => array(array(xmlrpcStruct, xmlrpcString)),
						'docstring' => lang('Read a single message.')
					),
					'read_inbox' => array(
						'function' => 'read_inbox',
						'signature' => array(array(xmlrpcStruct, xmlrpcString, xmlrpcString, xmlrpcString)),
						'docstring' => lang('Read a list of messages.')
					),
					'send_message' => array(
						'function' => 'send_message',
						'signature' => array(array(xmlrpcStruct, xmlrpcStruct)),
						'docstring' => lang('Send a message to a single recipient.')
					),
					'send_global_message' => array(
						'function' => 'send_global_message',
						'signature' => array(array(xmlrpcStruct, xmlrpcStruct)),
						'docstring' => lang('Send a global message.')
					),
					'reply' => array(
						'function' => 'reply',
						'signature' => array(array(xmlrpcInt, xmlrpcInt)),
						'docstring' => lang('Reply to a received message.')
					),
					'forward' => array(
						'function' => 'forward',
						'signature' => array(array(xmlrpcStruct, xmlrpcStruct)),
						'docstring' => lang('Forward a message to another user.')
					),
					'list_methods' => array(
						'function' => 'list_methods',
						'signature' => array(array(xmlrpcStruct, xmlrpcString)),
						'docstring' => lang('Read this list of methods.')
					)
				);
				return $xml_functions;
				break;
			case 'soap':
				return $this->soap_functions;
				break;
			default:
				return array();
				break;
		}
	}
}
