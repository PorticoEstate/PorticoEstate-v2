<?php

/**
 * phpGroupWare EMail - http://phpGroupWare.org
 *
 * @author Angles <angles@phpgroupware.org>
 * @copyright Copyright (C) 2001-2004 Angelo Tony Puglisi
 * @copyright Portions Copyright (C) 2003-2005 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @package email
 * @subpackage setup
 * @version $Id$
 * @internal Based on AeroMail by Mark Cushman <mark@cushman.net>
 */

$phpgw_baseline = array(
	'phpgw_email_pop_messages' => array(
		'fd' => array(
			'message_id' => array('type' => 'auto', 'nullable' => false),
			'account_id' => array('type' => 'int', 'precision' => 4, 'nullable' => false),
			'mail_account' => array('type' => 'int', 'precision' => 4, 'nullable' => false),
			'mailbox_key' => array('type' => 'varchar', 'precision' => 64, 'nullable' => false),
			'uidl' => array('type' => 'varchar', 'precision' => 70, 'nullable' => false),
			'raw_message' => array('type' => 'blob', 'nullable' => false),
			'content_hash' => array('type' => 'varchar', 'precision' => 64, 'nullable' => false),
			'message_size' => array('type' => 'int', 'precision' => 8, 'nullable' => false),
			'received_at' => array('type' => 'int', 'precision' => 8, 'nullable' => false),
			'date_sent' => array('type' => 'int', 'precision' => 8, 'nullable' => false, 'default' => 0),
			'subject_sort' => array('type' => 'varchar', 'precision' => 255, 'nullable' => false, 'default' => ''),
			'from_sort' => array('type' => 'varchar', 'precision' => 255, 'nullable' => false, 'default' => ''),
			'to_sort' => array('type' => 'varchar', 'precision' => 255, 'nullable' => false, 'default' => ''),
			'cc_sort' => array('type' => 'varchar', 'precision' => 255, 'nullable' => false, 'default' => ''),
			'is_seen' => array('type' => 'int', 'precision' => 2, 'nullable' => false, 'default' => 0),
			'is_deleted' => array('type' => 'int', 'precision' => 2, 'nullable' => false, 'default' => 0),
			'is_hidden' => array('type' => 'int', 'precision' => 2, 'nullable' => false, 'default' => 0),
		),
		'pk' => array('message_id'),
		'fk' => array(),
		'ix' => array(
			array('account_id', 'mail_account', 'mailbox_key', 'is_hidden', 'date_sent', 'message_id'),
			array('account_id', 'mail_account', 'mailbox_key', 'is_hidden', 'subject_sort', 'message_id'),
			array('account_id', 'mail_account', 'mailbox_key', 'is_hidden', 'from_sort', 'message_id'),
			array('account_id', 'mail_account', 'mailbox_key', 'is_hidden', 'to_sort', 'message_id'),
			array('account_id', 'mail_account', 'mailbox_key', 'is_hidden', 'cc_sort', 'message_id'),
			array('account_id', 'mail_account', 'mailbox_key', 'is_hidden', 'message_size', 'message_id'),
		),
		'uc' => array(array('account_id', 'mail_account', 'mailbox_key', 'uidl')),
	),
	'phpgw_email_message_index' =>	array(
		'fd' => array(
			'account_id' => array('type' => 'int', 'precision' => 4, 'nullable' => false),
			'mail_account' => array('type' => 'int', 'precision' => 4, 'nullable' => false),
			'mailbox_key' => array('type' => 'varchar', 'precision' => 64, 'nullable' => false),
			'folder' => array('type' => 'text', 'nullable' => false),
			'message_uid' => array('type' => 'int', 'precision' => 8, 'nullable' => false),
			'uidvalidity' => array('type' => 'int', 'precision' => 8, 'nullable' => true),
			'uidnext' => array('type' => 'int', 'precision' => 8, 'nullable' => true),
			'message_total' => array('type' => 'int', 'precision' => 8, 'nullable' => true),
			'date_sent' => array('type' => 'int', 'precision' => 8, 'nullable' => true),
			'date_received' => array('type' => 'int', 'precision' => 8, 'nullable' => true),
			'from_sort' => array('type' => 'varchar', 'precision' => 255, 'nullable' => false, 'default' => ''),
			'to_sort' => array('type' => 'varchar', 'precision' => 255, 'nullable' => false, 'default' => ''),
			'cc_sort' => array('type' => 'varchar', 'precision' => 255, 'nullable' => false, 'default' => ''),
			'subject_sort' => array('type' => 'varchar', 'precision' => 255, 'nullable' => false, 'default' => ''),
			'message_size' => array('type' => 'int', 'precision' => 8, 'nullable' => true),
		),
		'pk' => array('account_id', 'mail_account', 'mailbox_key', 'message_uid'),
		'fk' => array(),
		'ix' => array(),
		'uc' => array()
	)

);
