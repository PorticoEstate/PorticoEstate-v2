<?php

namespace Tests\Services;

require_once __DIR__ . '/../../vendor/autoload.php';

use App\modules\email\services\InboxMessageIndex;
use PHPUnit\Framework\TestCase;

class InboxMessageIndexTest extends TestCase
{
	public function testPagesBySelectedSortAndReusesIndexedMailboxMetadata(): void
	{
		$db = new InboxIndexFakeDb();
		$transport = new InboxIndexFakeTransport();
		$mail = new InboxIndexFakeMail($transport);
		$index = new InboxMessageIndex($db, 17);
		$folderInfo = [
			'fldball' => ['acctnum' => 0, 'folder' => 'INBOX'],
			'folder_checked' => 'INBOX',
			'uidvalidity' => 42,
			'uidnext' => 8,
			'number_all' => 5,
		];

		$firstPage = $index->page($mail, $folderInfo, 1, 2, 3, 1);
		$secondPage = $index->page($mail, $folderInfo, 3, 2, 3, 1);

		$this->assertSame([3, 2], array_column($firstPage, 'msgball[msgnum]'));
		$this->assertSame([7, 1], array_column($secondPage, 'msgball[msgnum]'));
		$this->assertCount(1, $transport->indexedBatches);
		$this->assertStringContainsString('ORDER BY subject_sort DESC, message_uid DESC', $db->lastPageQuery);
		$this->assertStringContainsString('LIMIT :page_size OFFSET :page_start', $db->lastPageQuery);
	}
}

final class InboxIndexFakeDb
{
	public array $messages = [];
	public ?array $state = null;
	public string $lastPageQuery = '';

	public function prepare(string $sql): InboxIndexFakeStatement
	{
		if (str_contains($sql, 'ORDER BY')) $this->lastPageQuery = $sql;
		return new InboxIndexFakeStatement($this, $sql);
	}
}

final class InboxIndexFakeStatement
{
	private array $bound = [];
	private mixed $result = null;

	public function __construct(private InboxIndexFakeDb $db, private string $sql)
	{
	}

	public function bindValue(string $name, mixed $value, int $type = 0): bool
	{
		$this->bound[$name] = $value;
		return true;
	}

	public function execute(array $params = []): bool
	{
		$params = array_merge($this->bound, $params);
		$sql = ltrim($this->sql);
		if (str_starts_with($sql, 'SELECT uidvalidity'))
		{
			$this->result = $this->db->state;
		}
		elseif (str_contains($this->sql, 'COUNT(*)'))
		{
			$this->result = count($this->db->messages);
		}
		elseif (str_starts_with($sql, 'SELECT message_uid'))
		{
			preg_match('/ORDER BY ([a-z_]+) (ASC|DESC)/', $this->sql, $sort);
			$column = $sort[1] ?? 'message_uid';
			$descending = ($sort[2] ?? 'ASC') === 'DESC';
			$messages = array_values($this->db->messages);
			usort($messages, static function (array $left, array $right) use ($column, $descending): int {
				$comparison = $left[$column] <=> $right[$column];
				if ($comparison === 0) $comparison = $left['message_uid'] <=> $right['message_uid'];
				return $descending ? -$comparison : $comparison;
			});
			$this->result = array_map(
				static fn(array $message): int => (int) $message['message_uid'],
				array_slice($messages, (int) $params[':page_start'], (int) $params[':page_size'])
			);
		}
		elseif (str_starts_with($sql, 'DELETE') && str_contains($sql, 'BETWEEN'))
		{
			if ((int) $params['first_uid'] === 0 && (int) $params['last_uid'] === 0)
			{
				$this->db->state = null;
			}
			else
			{
				foreach ($this->db->messages as $uid => $message)
				{
					if ($uid >= (int) $params['first_uid'] && $uid <= (int) $params['last_uid']) unset($this->db->messages[$uid]);
				}
			}
		}
		elseif (str_starts_with($sql, 'DELETE'))
		{
			$this->db->messages = [];
			$this->db->state = null;
		}
		elseif (str_starts_with($sql, 'INSERT'))
		{
			$rowUids = array_filter(array_keys($params), static fn(string $key): bool => preg_match('/^row_\d+_message_uid$/', $key) === 1);
			if (!$rowUids)
			{
				$this->db->state = $params;
			}
			else
			{
				foreach ($rowUids as $uidKey)
				{
					preg_match('/^row_(\d+)_message_uid$/', $uidKey, $matches);
					$rowPrefix = 'row_' . $matches[1] . '_';
					$uid = (int) $params[$uidKey];
					$this->db->messages[$uid] = [
						'message_uid' => $uid,
						'subject_sort' => $params[$rowPrefix . 'subject_sort'],
					];
				}
			}
		}
		return true;
	}

	public function fetch(int $mode = 0): array|false
	{
		return $this->result ?? false;
	}

	public function fetchColumn(int $column = 0): int
	{
		return (int) $this->result;
	}

	public function fetchAll(int $mode = 0): array
	{
		return (array) $this->result;
	}
}

final class InboxIndexFakeTransport
{
	public array $indexedBatches = [];
	private array $metadata = [
		1 => 'a',
		2 => 'c',
		3 => 'd',
		5 => 'z',
		7 => 'b',
	];

	public function i_search($stream, string $criteria): array
	{
		return array_keys($this->metadata);
	}

	public function fetch_sort_metadata($stream, array $uids): array
	{
		$this->indexedBatches[] = $uids;
		$result = [];
		foreach ($uids as $uid)
		{
			if (!isset($this->metadata[$uid])) continue;
			$result[] = [
				'uid' => $uid,
				'date_sent' => $uid,
				'date_received' => $uid,
				'from_sort' => '',
				'to_sort' => '',
				'cc_sort' => '',
				'subject_sort' => $this->metadata[$uid],
				'message_size' => $uid,
			];
		}
		return $result;
	}
}

final class InboxIndexFakeMail
{
	public function __construct(private InboxIndexFakeTransport $transport)
	{
	}

	public function get_acctnum(): int { return 0; }
	public function get_arg_value(string $key, int $account = 0): string { return $key === 'mailsvr_stream' ? 'imap-fixture' : ''; }
	public function getTransport(int $account): InboxIndexFakeTransport { return $this->transport; }
	public function prep_folder_out(string $folder): string { return $folder; }
}