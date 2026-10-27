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

$test[] = '0.9.13.002';
/**
 * Update from 0.9.13.002 to 0.9.13.003
 *
 * @return string New version number
 */
function email_upgrade0_9_13_002($oProc)
{
	$oProc->m_odb->transaction_begin();

	$oProc->CreateTable(
		'phpgw_anglemail',
		array(
			'fd' => array(
				'account_id' => array('type' => 'varchar', 'precision' => 20, 'nullable' => false),
				'data_key' => array('type' => 'varchar', 'precision' => 255, 'nullable' => False, 'default' => ''),
				'content' => array('type' => 'text', 'nullable' => False, 'default' => ''),
			),
			'pk' => array('account_id', 'data_key'),
			'fk' => array(),
			'ix' => array(),
			'uc' => array()
		)
	);
	if ($oProc->m_odb->transaction_commit())
	{
		return	'0.9.13.003';
	}
}

$test[] = '0.9.13.003';
function email_upgrade0_9_13_003()
{
	return  '0.9.17.500';
}

$test[] = '0.9.17.500';
function email_upgrade0_9_17_500($oProc)
{
	$oProc->m_odb->transaction_begin();
	$oProc->CreateTable(
		'phpgw_email_message_index',
		array(
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
	if ($oProc->m_odb->transaction_commit())
	{
		return '0.9.17.501';
	}
}

$test[] = '0.9.17.501';
function email_upgrade0_9_17_501($oProc)
{
	require __DIR__ . '/tables_current.inc.php';
	$oProc->m_odb->transaction_begin();
	if (!$oProc->CreateTable('phpgw_email_pop_messages', $phpgw_baseline['phpgw_email_pop_messages']))
	{
		$oProc->m_odb->transaction_abort();
		return false;
	}
	if ($oProc->m_odb->transaction_commit()) return '0.9.17.502';
}
