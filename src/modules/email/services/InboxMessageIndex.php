<?php

/**
 * EMail - IMAP Inbox Index
 *
 * @author Sigurd Nes <sigurdne at gmail.com>
 * @copyright Copyright (C) 2026 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @package email
 */

/**
 * Synchronizes IMAP UID sort metadata with the database and selects stable, sorted inbox pages.
 */

namespace App\modules\email\services;

use App\Database\Db;
use App\modules\phpgwapi\services\Settings;
use PDO;
use RuntimeException;

final class InboxMessageIndex
{
	private const TABLE = 'phpgw_email_message_index';
	private const BATCH_SIZE = 500;
	private const INSERT_BATCH_SIZE = 100;
	private const SORT_COLUMNS = [
		0 => 'date_sent',
		1 => 'date_received',
		2 => 'from_sort',
		3 => 'subject_sort',
		4 => 'to_sort',
		5 => 'cc_sort',
		6 => 'message_size',
	];

	private object $db;
	private ?int $accountId;

	/**
	 * Configure the database and optional application-user identity for IMAP indexing.
	 *
	 * @param object|null $db The database connection or compatible test adapter; null uses the application connection where supported.
	 * @param int|null $accountId The application user identifier owning the indexed mailbox.
	 */
	public function __construct(?object $db = null, ?int $accountId = null)
	{
		$this->db = $db ?? Db::getInstance();
		$this->accountId = $accountId;
	}

	/**
	 * Synchronize IMAP sort metadata and select a stable database-sorted message page.
	 *
	 * @param object $mail The active mail runtime used for mailbox access and header decoding.
	 * @param array $folderInfo The mailbox counts, UID validity, folder, and account reference used for paging.
	 * @param int $start The zero-based message offset for the requested page.
	 * @param int $pageSize The maximum page length; null requests the complete ordered ID list where supported.
	 * @param int $sort The supported sort-column identifier.
	 * @param int $order The direction selector: 0 ascending or 1 descending.
	 * @return array Message-reference arrays for the requested page.
	 */
	public function page(object $mail, array $folderInfo, int $start, int $pageSize, int $sort, int $order): array
	{
		$account = (int) ($folderInfo['fldball']['acctnum'] ?? $mail->get_acctnum());
		$folder = (string) ($folderInfo['folder_checked'] ?? 'INBOX');
		$user = $this->accountId === null ? (array) Settings::getInstance()->get('user') : [];
		$accountId = $this->accountId ?? (int) ($user['account_id'] ?? 0);
		if ($accountId < 1 || $account < 0 || $folder === '')
		{
			throw new RuntimeException('Unable to identify the mailbox for inbox paging');
		}

		$folderKey = hash('sha256', $folder);
		$stream = $mail->get_arg_value('mailsvr_stream', $account);
		if (!$stream)
		{
			$mail->ensure_stream_and_folder([
				'acctnum' => $account,
				'folder' => $mail->prep_folder_out($folder),
			], 'InboxMessageIndex::page');
			$stream = $mail->get_arg_value('mailsvr_stream', $account);
		}
		if (!$stream)
		{
			throw new RuntimeException('Unable to open the mailbox for inbox paging');
		}
		$transport = $mail->getTransport($account);

		$this->synchronize($mail, $folderInfo, $accountId, $account, $folderKey, $folder, $stream, $transport);

		$sortColumn = self::SORT_COLUMNS[$sort] ?? self::SORT_COLUMNS[1];
		$direction = $order === 1 ? 'DESC' : 'ASC';
		$statement = $this->db->prepare(
			' SELECT message_uid FROM ' . self::TABLE
			. ' WHERE account_id = :account_id AND mail_account = :mail_account'
			. ' AND mailbox_key = :mailbox_key AND message_uid > 0'
			. ' ORDER BY ' . $sortColumn . ' ' . $direction . ', message_uid ' . $direction
			. ' LIMIT :page_size OFFSET :page_start'
		);
		$statement->bindValue(':account_id', $accountId, PDO::PARAM_INT);
		$statement->bindValue(':mail_account', $account, PDO::PARAM_INT);
		$statement->bindValue(':mailbox_key', $folderKey, PDO::PARAM_STR);
		$statement->bindValue(':page_size', max(1, $pageSize), PDO::PARAM_INT);
		$statement->bindValue(':page_start', max(0, $start), PDO::PARAM_INT);
		$statement->execute();

		$msgballs = [];
		foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $uid)
		{
			$msgballs[] = [
				'msgball[msgnum]' => (int) $uid,
				'msgball[folder]' => $mail->prep_folder_out($folder),
				'msgball[acctnum]' => $account,
			];
		}
		return $msgballs;
	}

	/**
	 * Reconcile indexed IMAP UIDs with the mailbox's current validity and message counts.
	 *
	 * @param object $mail The active mail runtime used for mailbox access and header decoding.
	 * @param array $folderInfo The mailbox counts, UID validity, folder, and account reference used for paging.
	 * @param int $accountId The application user identifier owning the indexed mailbox.
	 * @param int $account The mail account number; null selects the main preference root where supported.
	 * @param string $folderKey The stable hash identifying the indexed mailbox folder.
	 * @param string $folder The mailbox folder name in the representation expected by the mail runtime.
	 * @param resource|object|string $stream The open native connection or modern mail transport handle.
	 * @param object $transport The account-specific mail transport used to read UID metadata.
	 * @return void No value is returned.
	 */
	private function synchronize(object $mail, array $folderInfo, int $accountId, int $account, string $folderKey, string $folder, $stream, object $transport): void
	{
		$uidValidity = (int) ($folderInfo['uidvalidity'] ?? 0);
		$uidNext = (int) ($folderInfo['uidnext'] ?? 0);
		$total = (int) ($folderInfo['number_all'] ?? 0);
		$state = $this->readState($accountId, $account, $folderKey);
		$indexedCount = $this->countMessages($accountId, $account, $folderKey);

		if ($total === 0)
		{
			$this->deleteMailbox($accountId, $account, $folderKey);
			$this->saveState($accountId, $account, $folderKey, $folder, $uidValidity, $uidNext, 0);
			return;
		}

		$needsRebuild = !$state
			|| (int) $state['uidvalidity'] !== $uidValidity
			|| $uidValidity < 1
			|| $uidNext < 1
			|| $uidNext < (int) $state['uidnext']
			|| $indexedCount !== (int) $state['message_total'];

		if ($needsRebuild)
		{
			$this->rebuild($accountId, $account, $folderKey, $folder, $transport, $stream);
			$indexedCount = $this->countMessages($accountId, $account, $folderKey);
		}
		elseif ($uidNext > (int) $state['uidnext'])
		{
			$this->insertUidRange(
				$accountId,
				$account,
				$folderKey,
				$folder,
				$transport,
				$stream,
				(int) $state['uidnext'],
				$uidNext
			);
			$indexedCount = $this->countMessages($accountId, $account, $folderKey);
			if ($indexedCount !== $total)
			{
				$this->rebuild($accountId, $account, $folderKey, $folder, $transport, $stream);
				$indexedCount = $this->countMessages($accountId, $account, $folderKey);
			}
		}
		elseif ($indexedCount !== $total)
		{
			$this->rebuild($accountId, $account, $folderKey, $folder, $transport, $stream);
			$indexedCount = $this->countMessages($accountId, $account, $folderKey);
		}

		$this->saveState($accountId, $account, $folderKey, $folder, $uidValidity, $uidNext, $indexedCount);
	}

	/**
	 * Replace an account folder's indexed message metadata from its transport.
	 *
	 * @param int $accountId The application user identifier owning the indexed mailbox.
	 * @param int $account The mail account number; null selects the main preference root where supported.
	 * @param string $folderKey The stable hash identifying the indexed mailbox folder.
	 * @param string $folder The mailbox folder name in the representation expected by the mail runtime.
	 * @param object $transport The account-specific mail transport used to read UID metadata.
	 * @param resource|object|string $stream The open native connection or modern mail transport handle.
	 * @return void No value is returned.
	 */
	private function rebuild(int $accountId, int $account, string $folderKey, string $folder, object $transport, $stream): void
	{
		$uids = $transport->i_search($stream, 'ALL');
		if (!is_array($uids))
		{
			throw new RuntimeException('Unable to enumerate mailbox message identifiers');
		}
		$uids = array_values(array_unique(array_filter(array_map('intval', $uids), static fn($uid) => $uid > 0)));
		$this->deleteMailbox($accountId, $account, $folderKey);
		foreach (array_chunk($uids, self::BATCH_SIZE) as $batch)
		{
			$this->insertMetadataBatch($accountId, $account, $folderKey, $folder, $transport, $stream, $batch);
		}
	}

	/**
	 * Fetch and index metadata within the requested IMAP UID interval.
	 *
	 * @param int $accountId The application user identifier owning the indexed mailbox.
	 * @param int $account The mail account number; null selects the main preference root where supported.
	 * @param string $folderKey The stable hash identifying the indexed mailbox folder.
	 * @param string $folder The mailbox folder name in the representation expected by the mail runtime.
	 * @param object $transport The account-specific mail transport used to read UID metadata.
	 * @param resource|object|string $stream The open native connection or modern mail transport handle.
	 * @param int $firstUid The first message UID included in the requested interval.
	 * @param int $uidNext The next UID reported by the mailbox, bounding the imported range.
	 * @return void No value is returned.
	 */
	private function insertUidRange(int $accountId, int $account, string $folderKey, string $folder, object $transport, $stream, int $firstUid, int $uidNext): void
	{
		for ($batchStart = $firstUid; $batchStart < $uidNext; $batchStart += self::BATCH_SIZE)
		{
			$batchEnd = min($uidNext - 1, $batchStart + self::BATCH_SIZE - 1);
			$uids = range($batchStart, $batchEnd);
			$this->deleteUidRange($accountId, $account, $folderKey, $batchStart, $batchEnd);
			$this->insertMetadataBatch($accountId, $account, $folderKey, $folder, $transport, $stream, $uids);
		}
	}

	/**
	 * Fetch and insert a bounded batch of UID sort metadata.
	 *
	 * @param int $accountId The application user identifier owning the indexed mailbox.
	 * @param int $account The mail account number; null selects the main preference root where supported.
	 * @param string $folderKey The stable hash identifying the indexed mailbox folder.
	 * @param string $folder The mailbox folder name in the representation expected by the mail runtime.
	 * @param object $transport The account-specific mail transport used to read UID metadata.
	 * @param resource|object|string $stream The open native connection or modern mail transport handle.
	 * @param array $uids The message UIDs whose metadata should be fetched and indexed.
	 * @return void No value is returned.
	 */
	private function insertMetadataBatch(int $accountId, int $account, string $folderKey, string $folder, object $transport, $stream, array $uids): void
	{
		$metadata = $transport->fetch_sort_metadata($stream, $uids);
		if (!is_array($metadata))
		{
			throw new RuntimeException('Unable to fetch mailbox sort metadata');
		}

		$columns = ['account_id', 'mail_account', 'mailbox_key', 'folder', 'message_uid', 'date_sent', 'date_received', 'from_sort', 'to_sort', 'cc_sort', 'subject_sort', 'message_size'];
		foreach (array_chunk($metadata, self::INSERT_BATCH_SIZE) as $insertBatch)
		{
			$values = [];
			$params = [];
			$row = 0;
			foreach ($insertBatch as $item)
			{
				$uid = (int) ($item['uid'] ?? 0);
				if ($uid < 1)
                {
                	continue;
                }
				$rowValues = [
					'account_id' => $accountId,
					'mail_account' => $account,
					'mailbox_key' => $folderKey,
					'folder' => $folder,
					'message_uid' => $uid,
					'date_sent' => (int) ($item['date_sent'] ?? 0),
					'date_received' => (int) ($item['date_received'] ?? 0),
					'from_sort' => (string) ($item['from_sort'] ?? ''),
					'to_sort' => (string) ($item['to_sort'] ?? ''),
					'cc_sort' => (string) ($item['cc_sort'] ?? ''),
					'subject_sort' => (string) ($item['subject_sort'] ?? ''),
					'message_size' => (int) ($item['message_size'] ?? 0),
				];
				$placeholders = [];
				foreach ($columns as $column)
				{
					$parameter = 'row_' . $row . '_' . $column;
					$placeholders[] = ':' . $parameter;
					$params[$parameter] = $rowValues[$column];
				}
				$values[] = '(' . implode(', ', $placeholders) . ')';
				$row++;
			}
			if (!$values)
            {
            	continue;
            }
			$sql = 'INSERT INTO ' . self::TABLE . ' (' . implode(', ', $columns) . ') VALUES ' . implode(', ', $values);
			$this->db->prepare($sql)->execute($params);
		}
	}

	/**
	 * Read the synchronization state for the scoped indexed mailbox.
	 *
	 * @param int $accountId The application user identifier owning the indexed mailbox.
	 * @param int $account The mail account number; null selects the main preference root where supported.
	 * @param string $folderKey The stable hash identifying the indexed mailbox folder.
	 * @return array|null The saved mailbox state, or null when no state is stored.
	 */
	private function readState(int $accountId, int $account, string $folderKey): ?array
	{
		$statement = $this->db->prepare(
			'SELECT uidvalidity, uidnext, message_total FROM ' . self::TABLE
			. ' WHERE account_id = :account_id AND mail_account = :mail_account'
			. ' AND mailbox_key = :mailbox_key AND message_uid = 0'
		);
		$statement->execute(['account_id' => $accountId, 'mail_account' => $account, 'mailbox_key' => $folderKey]);
		$row = $statement->fetch(PDO::FETCH_ASSOC);
		return is_array($row) ? $row : null;
	}

	/**
	 * Count indexed message rows for the selected account folder.
	 *
	 * @param int $accountId The application user identifier owning the indexed mailbox.
	 * @param int $account The mail account number; null selects the main preference root where supported.
	 * @param string $folderKey The stable hash identifying the indexed mailbox folder.
	 * @return int The number of indexed messages.
	 */
	private function countMessages(int $accountId, int $account, string $folderKey): int
	{
		$statement = $this->db->prepare(
			'SELECT COUNT(*) FROM ' . self::TABLE
			. ' WHERE account_id = :account_id AND mail_account = :mail_account'
			. ' AND mailbox_key = :mailbox_key AND message_uid > 0'
		);
		$statement->execute(['account_id' => $accountId, 'mail_account' => $account, 'mailbox_key' => $folderKey]);
		return (int) $statement->fetchColumn();
	}

	/**
	 * Remove all index rows and synchronization state for the scoped mailbox.
	 *
	 * @param int $accountId The application user identifier owning the indexed mailbox.
	 * @param int $account The mail account number; null selects the main preference root where supported.
	 * @param string $folderKey The stable hash identifying the indexed mailbox folder.
	 * @return void No value is returned.
	 */
	private function deleteMailbox(int $accountId, int $account, string $folderKey): void
	{
		$statement = $this->db->prepare(
			'DELETE FROM ' . self::TABLE
			. ' WHERE account_id = :account_id AND mail_account = :mail_account AND mailbox_key = :mailbox_key'
		);
		$statement->execute(['account_id' => $accountId, 'mail_account' => $account, 'mailbox_key' => $folderKey]);
	}

	/**
	 * Remove stale index rows within a scoped UID range.
	 *
	 * @param int $accountId The application user identifier owning the indexed mailbox.
	 * @param int $account The mail account number; null selects the main preference root where supported.
	 * @param string $folderKey The stable hash identifying the indexed mailbox folder.
	 * @param int $firstUid The first message UID included in the requested interval.
	 * @param int $lastUid The last message UID included in the requested interval.
	 * @return void No value is returned.
	 */
	private function deleteUidRange(int $accountId, int $account, string $folderKey, int $firstUid, int $lastUid): void
	{
		$statement = $this->db->prepare(
			'DELETE FROM ' . self::TABLE
			. ' WHERE account_id = :account_id AND mail_account = :mail_account AND mailbox_key = :mailbox_key'
			. ' AND message_uid BETWEEN :first_uid AND :last_uid'
		);
		$statement->execute([
			'account_id' => $accountId,
			'mail_account' => $account,
			'mailbox_key' => $folderKey,
			'first_uid' => $firstUid,
			'last_uid' => $lastUid,
		]);
	}

	/**
	 * Persist the mailbox validity, next UID, and message count used for synchronization.
	 *
	 * @param int $accountId The application user identifier owning the indexed mailbox.
	 * @param int $account The mail account number; null selects the main preference root where supported.
	 * @param string $folderKey The stable hash identifying the indexed mailbox folder.
	 * @param string $folder The mailbox folder name in the representation expected by the mail runtime.
	 * @param int $uidValidity The mailbox UID-validity value identifying the current UID generation.
	 * @param int $uidNext The next UID reported by the mailbox, bounding the imported range.
	 * @param int $total The mailbox message count to persist in synchronization state.
	 * @return void No value is returned.
	 */
	private function saveState(int $accountId, int $account, string $folderKey, string $folder, int $uidValidity, int $uidNext, int $total): void
	{
		$this->deleteUidRange($accountId, $account, $folderKey, 0, 0);
		$statement = $this->db->prepare(
			'INSERT INTO ' . self::TABLE
			. ' (account_id, mail_account, mailbox_key, folder, message_uid, uidvalidity, uidnext, message_total, from_sort, to_sort, cc_sort, subject_sort)'
			. ' VALUES (:account_id, :mail_account, :mailbox_key, :folder, 0, :uidvalidity, :uidnext, :message_total, :from_sort, :to_sort, :cc_sort, :subject_sort)'
		);
		$statement->execute([
			'account_id' => $accountId,
			'mail_account' => $account,
			'mailbox_key' => $folderKey,
			'folder' => $folder,
			'uidvalidity' => $uidValidity,
			'uidnext' => $uidNext,
			'message_total' => $total,
			'from_sort' => '',
			'to_sort' => '',
			'cc_sort' => '',
			'subject_sort' => '',
		]);
	}
}