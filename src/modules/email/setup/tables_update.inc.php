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

	$oProc->DropTable('phpgw_anglemail');
	if ($oProc->m_odb->transaction_commit()) return '0.9.17.502';
}

$test[] = '0.9.17.502';
function email_upgrade0_9_17_502($oProc)
{
	require __DIR__ . '/tables_current.inc.php';
	$db = $oProc->m_odb;
	$db->transaction_begin();
	try
	{
		$probe = $db->prepare('SELECT * FROM phpgw_email_pop_messages WHERE 1 = 0');
		$probe->execute();
		$existing = [];
		for ($index = 0; $index < $probe->columnCount(); $index++)
		{
			$existing[] = $probe->getColumnMeta($index)['name'];
		}
		$added = [];
		foreach (['date_sent', 'subject_sort', 'from_sort', 'to_sort', 'cc_sort'] as $column)
		{
			if (in_array($column, $existing, true))
			{
				continue;
			}
			$definition = $phpgw_baseline['phpgw_email_pop_messages']['fd'][$column];
			$definition['nullable'] = true;
			if (!$oProc->AddColumn('phpgw_email_pop_messages', $column, $definition))
			{
				throw new \RuntimeException('Unable to add POP sort metadata column');
			}
			$added[] = $column;
		}
		\App\modules\email\services\PopMessageStore::backfillSortMetadata($db);
		foreach ($added as $column)
		{
			$definition = $phpgw_baseline['phpgw_email_pop_messages']['fd'][$column];
			if (!$oProc->AlterColumn('phpgw_email_pop_messages', $column, $definition))
			{
				throw new \RuntimeException('Unable to finalize POP sort metadata column');
			}
		}
		if ($added)
		{
			foreach (['date_sent', 'subject_sort', 'from_sort', 'to_sort', 'cc_sort', 'message_size'] as $column)
			{
				if ($db->exec('CREATE INDEX pop_' . $column . '_idx ON phpgw_email_pop_messages (account_id, mail_account, mailbox_key, is_hidden, ' . $column . ', message_id)') === false)
				{
					throw new \RuntimeException('Unable to create POP sort index');
				}
			}
		}
		if ($db->transaction_commit())
		{
			return '0.9.17.503';
		}
	}
	catch (\Throwable $exception)
	{
		$db->transaction_abort();
		throw $exception;
	}
}
