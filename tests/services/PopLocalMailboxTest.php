<?php

namespace Tests\Services;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/modules/email/inc/class.mail_dcom_pop3_local.inc.php';
require_once __DIR__ . '/../../src/modules/email/inc/IMAPManager.php';

use App\modules\email\services\PopMailboxSource;
use App\modules\email\services\PopMailboxSourceException;
use App\modules\email\services\PopMessageStore;
use PHPUnit\Framework\TestCase;
use PDO;

class PopLocalMailboxTest extends TestCase
{
	private mixed $originalSettings;

	protected function setUp(): void
	{
		$property = new \ReflectionProperty(\App\modules\phpgwapi\services\Settings::class, 'instance');
		$this->originalSettings = $property->getValue();
		$property->setValue(null, new class {
			public function get($key): array { return $key === 'server' ? ['webserver_url' => 'https://example.test', 'usecookies' => true] : []; }
		});
	}

	protected function tearDown(): void
	{
		(new \ReflectionProperty(\App\modules\phpgwapi\services\Settings::class, 'instance'))->setValue(null, $this->originalSettings);
	}

	private function raw(string $subject = 'Durable POP'): string
	{
		return "From: Sender <sender@example.test>\r\nTo: user@example.test\r\nSubject: $subject\r\nDate: Tue, 27 Oct 2026 10:00:00 +0000\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=fixture\r\n\r\n--fixture\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nStored body\r\n--fixture\r\nContent-Type: application/octet-stream\r\nContent-Disposition: attachment; filename=proof.bin\r\nContent-Transfer-Encoding: base64\r\n\r\nAAEC/w==\r\n--fixture--\r\n";
	}

	private function transport(PopStoreFakeDb $db, object $source, int $owner = 17, int $account = 2): \mail_dcom_pop3_local
	{
		$transport = new \mail_dcom_pop3_local(
			static fn($owner, $account, $key) => new PopMessageStore($owner, $account, $key, $db),
			static function () use ($source) { if ($source->offline) throw new PopMailboxSourceException('Offline'); return $source; }
		);
		$transport->setAccountContext($owner, $account);
		return $transport;
	}

	private function source(): object
	{
		return new class {
			public array $messages = [];
			public array $retrieved = [];
			public bool $offline = false;
			public function uidls(): array { return array_combine(range(1, count($this->messages)), array_keys($this->messages)); }
			public function retrieve($number): string { $uidl = array_keys($this->messages)[$number - 1]; $this->retrieved[] = $uidl; return $this->messages[$uidl]; }
			public function close(): void {}
		};
	}

	public function testSyncUsesUidlsNotSequenceNumbersAndKeepsRemoteRemovedMessages(): void
	{
		$db = new PopStoreFakeDb();
		$source = $this->source();
		$source->messages = ['uid-a' => $this->raw('First'), 'uid-b' => $this->raw('Second')];
		$transport = $this->transport($db, $source);
		$stream = $transport->open('{pop.test:995/pop3/ssl}INBOX', 'user', 'password');
		$this->assertSame([1, 2], $transport->sort($stream));
		$this->assertSame('First', $transport->header($stream, 1)->subject);
		$this->assertTrue(\IMAPManager::imap_setflag_full($stream, '1', '\\Seen'));
		$this->assertTrue(\IMAPManager::imap_setflag_full($stream, '1', '\\Seen'));
		$transport->close($stream);
		$source->messages = ['uid-b' => $this->raw('Second'), 'uid-c' => $this->raw('Third')];
		$reopened = $this->transport($db, $source);
		$stream = $reopened->open('{pop.test:995/pop3/ssl}INBOX', 'user', 'password');
		$this->assertSame(3, $reopened->num_msg($stream));
		$this->assertSame('First', $reopened->header($stream, 1)->subject);
		$this->assertSame('', $reopened->header($stream, 1)->Unseen);
		$this->assertSame(['uid-a', 'uid-b', 'uid-c'], $source->retrieved);
		$this->assertSame('sender', $reopened->header($stream, 1)->from[0]->mailbox);
	}

	public function testInboxColumnsSortByDisplayedValuesInBothDirections(): void
	{
		$db = new PopStoreFakeDb();
		$source = $this->source();
		$source->messages = [
			'uid-z' => str_replace('Sender <sender@example.test>', 'Zoe <zoe@example.test>', $this->raw('Zebra')),
			'uid-a' => str_replace(['Sender <sender@example.test>', 'Tue, 27 Oct 2026 10:00:00 +0000'], ['Amy <amy@example.test>', 'Mon, 26 Oct 2026 10:00:00 +0000'], $this->raw('Alpha with a substantially longer subject')),
		];
		$transport = $this->transport($db, $source);
		$stream = $transport->open('{pop.test:995/pop3/ssl}INBOX', 'user', 'password');
		foreach ([0, 1, 2, 3] as $column)
		{
			$this->assertSame([2, 1], $transport->sort($stream, $column, 0), 'Ascending column ' . $column);
			$this->assertSame([1, 2], $transport->sort($stream, $column, 1), 'Descending column ' . $column);
		}
		$this->assertSame([1, 2], $transport->sort($stream, 6, 0));
		$this->assertSame([2, 1], $transport->sort($stream, 6, 1));
		$this->assertSame('Zoe', $transport->header($stream, 1)->from[0]->personal);
		$this->assertSame('Amy', $transport->header($stream, 2)->from[0]->personal);
	}

	public function testSortableMetadataIsExtractedDuringImport(): void
	{
		$db = new PopStoreFakeDb();
		$store = new PopMessageStore(17, 2, PopMessageStore::mailboxKey('pop.test', 995, 'user'), $db);
		$raw = $this->raw('Mixed CASE subject');
		$store->save('uid-meta', $raw);
		$this->assertSame('mixed case subject', $db->messages[1]['subject_sort']);
		$this->assertSame('sender', $db->messages[1]['from_sort']);
		$this->assertSame('user@example.test', $db->messages[1]['to_sort']);
		$this->assertSame('', $db->messages[1]['cc_sort']);
		$this->assertSame(strtotime('2026-10-27 10:00:00 +0000'), $db->messages[1]['date_sent']);
		$this->assertSame($raw, $store->raw(1));
	}

	public function testSqlPaginationUsesStableIdsAndDoesNotReadMime(): void
	{
		$db = new PopStoreFakeDb();
		$source = $this->source();
		$source->messages = ['uid-a' => $this->raw('Equal'), 'uid-b' => $this->raw('Alpha'), 'uid-c' => $this->raw('Equal'), 'uid-d' => $this->raw('Zebra')];
		$transport = $this->transport($db, $source);
		$stream = $transport->open('{pop.test:995/pop3/ssl}INBOX', 'user', 'password');
		$db->queries = [];
		$this->assertSame([2, 1], $transport->page($stream, 3, 0, 0, 2));
		$this->assertSame([3, 4], $transport->page($stream, 3, 0, 2, 2));
		$this->assertSame([4, 3], $transport->page($stream, 3, 1, 0, 2));
		$this->assertStringContainsString('ORDER BY subject_sort ASC, message_id ASC LIMIT :page_size OFFSET :page_start', $db->queries[0]);
		$this->assertStringNotContainsString('raw_message', implode(' ', $db->queries));
		$this->assertSame(PDO::PARAM_INT, $db->bindingTypes['page_size']);
		$this->assertSame(PDO::PARAM_INT, $db->bindingTypes['page_start']);
		\IMAPManager::imap_delete($stream, '1');
		\IMAPManager::imap_expunge($stream);
		$this->assertSame([2, 3], $transport->page($stream, 3, 0, 0, 2));
	}

	public function testLocalDeletionRetainsTombstoneAndNeverChangesRemoteMailbox(): void
	{
		$db = new PopStoreFakeDb();
		$source = $this->source();
		$source->messages = ['uid-a' => $this->raw()];
		$transport = $this->transport($db, $source);
		$stream = $transport->open('{pop.test:995/pop3/ssl}INBOX', 'user', 'password');
		$this->assertTrue(\IMAPManager::imap_delete($stream, '1'));
		$this->assertTrue(\IMAPManager::imap_expunge($stream));
		$this->assertSame(0, $transport->num_msg($stream));
		$reopened = $this->transport($db, $source);
		$stream = $reopened->open('{pop.test:995/pop3/ssl}INBOX', 'user', 'password');
		$this->assertSame(0, $reopened->num_msg($stream));
		$this->assertCount(1, $source->messages);
		$this->assertCount(1, $source->retrieved);
		$this->assertNotEmpty($db->messages[1]['raw_message']);
	}

	public function testBodiesAttachmentsAndFlagsRemainAvailableOffline(): void
	{
		$db = new PopStoreFakeDb();
		$source = $this->source();
		$source->messages = ['uid-a' => $this->raw()];
		$transport = $this->transport($db, $source);
		$transport->open('{pop.test:995/pop3/ssl}INBOX', 'user', 'password');
		$source->offline = true;
		$offline = $this->transport($db, $source);
		$stream = $offline->open('{pop.test:995/pop3/ssl}INBOX', 'user', 'password');
		$this->assertInstanceOf(\LocalPopConnection::class, $stream);
		$this->assertStringContainsString('Stored body', \IMAPManager::imap_body($stream, 1));
		$this->assertSame("\x00\x01\x02\xff", base64_decode(\IMAPManager::imap_fetchbody($stream, 1, '2')));
		$structure = \IMAPManager::imap_fetchstructure($stream, 1);
		$this->assertSame('proof.bin', $structure->parts[1]->dparameters[0]->value);
		$this->assertTrue(\IMAPManager::imap_setflag_full($stream, '1', '\\Seen'));
		$this->assertSame([], \IMAPManager::imap_search($stream, 'UNSEEN'));
	}

	public function testStoreSeparatesUsersAccountsAndMailboxIdentity(): void
	{
		$db = new PopStoreFakeDb();
		$key = PopMessageStore::mailboxKey('pop.test', 995, 'user');
		$store = new PopMessageStore(17, 2, $key, $db);
		$store->save('uid-a', $this->raw());
		foreach ([[18, 2, $key], [17, 3, $key], [17, 2, PopMessageStore::mailboxKey('other.test', 995, 'user')]] as $scope)
		{
			$other = new PopMessageStore(...[...$scope, $db]);
			$this->assertSame([], $other->rows());
			try { $other->raw(1); $this->fail('Cross-mailbox message access succeeded'); } catch (\RuntimeException $exception) { $this->assertSame('Local POP message no longer exists', $exception->getMessage()); }
		}
	}

	public function testIncompleteMessagesAreNotPersistedAndCorruptionIsDetected(): void
	{
		$db = new PopStoreFakeDb();
		$store = new PopMessageStore(17, 2, PopMessageStore::mailboxKey('pop.test', 995, 'user'), $db);
		try { $store->save('uid-a', 'truncated'); $this->fail('Incomplete mail was saved'); } catch (\RuntimeException $exception) { $this->assertSame([], $store->rows()); }
		$store->save('uid-a', $this->raw());
		$db->messages[1]['raw_message'] = 'corrupt';
		$this->expectExceptionMessage('Local POP message failed its integrity check');
		$store->raw(1);
	}

	public function testDuplicateInsertIsIdempotentButConflictingUidlsAreRejected(): void
	{
		$db = new PopStoreFakeDb();
		$store = new PopMessageStore(17, 2, PopMessageStore::mailboxKey('pop.test', 995, 'user'), $db);
		$store->save('uid-a', $this->raw());
		$store->save('uid-a', $this->raw());
		$this->assertCount(1, $store->rows());
		$this->expectExceptionMessage('POP UIDL collision; existing message was retained');
		$store->save('uid-a', $this->raw('Conflicting message'));
	}

	public function testInterruptedRetrievalDoesNotCreateAnIncompleteLocalMessage(): void
	{
		$db = new PopStoreFakeDb();
		$source = new class($this->raw()) {
			public function __construct(private string $raw) {}
			public function uidls(): array { return [1 => 'complete', 2 => 'interrupted']; }
			public function retrieve($number): string { if ($number === 2) throw new PopMailboxSourceException('Connection interrupted'); return $this->raw; }
			public function close(): void {}
		};
		$transport = new \mail_dcom_pop3_local(static fn($owner, $account, $key) => new PopMessageStore($owner, $account, $key, $db), static fn() => $source);
		$transport->setAccountContext(17, 2);
		$stream = $transport->open('{pop.test:995/pop3/ssl}INBOX', 'user', 'password');
		$this->assertSame(1, $transport->num_msg($stream));
		$this->assertStringContainsString('synchronization failed', $transport->server_last_error());
		$this->assertSame('complete', $db->messages[1]['uidl']);
	}

	public function testStoredMimeWorksWithApplicationContentAndAttachmentDecoding(): void
	{
		if (!defined('PHPGW_INCLUDE_ROOT')) define('PHPGW_INCLUDE_ROOT', dirname(__DIR__, 2) . '/src/modules');
		require_once __DIR__ . '/../../src/modules/email/inc/class.mail_msg.inc.php';
		require_once __DIR__ . '/../../src/helpers/Sanitizer.php';
		$db = new PopStoreFakeDb();
		$source = $this->source();
		$source->messages = ['uid-a' => $this->raw()];
		$transport = $this->transport($db, $source);
		$stream = $transport->open('{pop.test:995/pop3/ssl}INBOX', 'user', 'password');
		$mail = $this->getMockBuilder(\mail_msg::class)->disableOriginalConstructor()
			->onlyMethods(['phpgw_fetchstructure', 'phpgw_fetchbody', 'make_part_clickable', 'get_subject', 'make_clickable', 'get_arg_value'])->getMock();
		$mail->method('phpgw_fetchstructure')->willReturnCallback(static fn($ball) => $transport->fetchstructure($stream, $ball['msgnum']));
		$mail->method('phpgw_fetchbody')->willReturnCallback(static fn($ball) => $transport->fetchbody($stream, $ball['msgnum'], $ball['part_no']));
		$mail->method('make_part_clickable')->willReturn(['part_href' => '', 'part_clickable' => '']);
		$mail->method('get_arg_value')->willReturn([]);
		$mail->method('get_subject')->willReturn('Durable POP');
		$mail->method('make_clickable')->willReturnArgument(0);
		$content = (new \App\modules\email\services\EmailMessageContent())->build($mail,
			['acctnum' => 2, 'folder' => 'INBOX', 'msgnum' => 1], $transport->header($stream, 1),
			static fn($part, $inline) => '/attachment/' . $part, static fn($time) => '2026-10-27');
		$item = \App\modules\email\services\EmailMessageSerializer::serialize($content['data'], $content['parts']);
		$this->assertStringContainsString('Stored body', $item['bodyParts'][0]['text']);
		$this->assertSame('proof.bin', $item['attachments'][0]['fileName']);
		$this->assertSame("\x00\x01\x02\xff", $transport->base64($transport->fetchbody($stream, 1, $item['attachments'][0]['partNumber'])));
	}

	public function testBatchDeleteOnlyHidesTheLocalCopy(): void
	{
		$db = new PopStoreFakeDb();
		$source = $this->source();
		$source->messages = ['uid-a' => $this->raw()];
		$transport = $this->transport($db, $source);
		$stream = $transport->open('{pop.test:995/pop3/ssl}INBOX', 'user', 'password');
		$mail = new class($transport, $stream) {
			public array $extra_and_default_acounts = [['acctnum' => 2, 'status' => 'enabled']];
			public function __construct(private object $transport, private object $stream) {}
			public function get_folder_list($account, $refresh): array { return [['folder_long' => 'INBOX']]; }
			public function get_arg_value($name, $account) { return $name === 'mailsvr_stream' ? $this->stream : ''; }
			public function prep_folder_out($folder): string { return $folder; }
			public function phpgw_header($ball, $display) { return $this->transport->header($this->stream, $ball['msgnum']); }
			public function ensure_stream_and_folder(...$arguments): bool { return true; }
			public function getTransport($account): object { return $this->transport; }
			public function expire_session_cache_item(...$arguments): void {}
		};
		$result = (new \App\modules\email\services\EmailMessageActions())->execute($mail,
			['action' => 'delete', 'account' => 2, 'messages' => [['acctnum' => 2, 'folder' => 'INBOX', 'msgnum' => 1]], 'target' => []]);
		$this->assertCount(1, $result['completed']);
		$this->assertSame([], $result['failed']);
		$this->assertSame(0, $transport->num_msg($stream));
		$this->assertCount(1, $source->messages);
	}

	public function testMissingMigrationHasAnActionableConfigurationError(): void
	{
		$db = new class {
			public function prepare($sql) {
				throw new class extends \PDOException {
					public function __construct() { parent::__construct('Table missing'); $this->code = '42P01'; }
				};
			}
		};
		$store = new PopMessageStore(17, 2, PopMessageStore::mailboxKey('pop.test', 995, 'user'), $db);
		$this->expectException(\App\modules\email\services\EmailMailConfigurationException::class);
		$this->expectExceptionMessage('Upgrade the Email application to version 0.9.17.503');
		$store->uidls();
	}

	public function testPersistenceFailureDoesNotIssueRemoteDeletes(): void
	{
		$db = new class {
			public function prepare($sql): object {
				return new class {
					public function bindValue($name, $value, $type): bool { return true; }
					public function execute($values = []): bool { return false; }
				};
			}
		};
		$store = new PopMessageStore(17, 2, PopMessageStore::mailboxKey('pop.test', 995, 'user'), $db);
		$this->expectExceptionMessage('Unable to persist the local POP mailbox');
		$store->save('uid-a', $this->raw());
	}

	public function testRawMimeStoresBinaryBytesWithoutAnOuterBase64Layer(): void
	{
		$db = new PopStoreFakeDb();
		$store = new PopMessageStore(17, 2, PopMessageStore::mailboxKey('pop.test', 995, 'user'), $db);
		$raw = "From: sender@example.test\r\nContent-Transfer-Encoding: binary\r\n\r\n\x00\x01\xff\\'\r\n";
		$store->save('binary-uid', $raw);
		$this->assertSame($raw, $db->messages[1]['raw_message']);
		$this->assertSame($raw, $store->raw(1));
		$this->assertSame(PDO::PARAM_LOB, $db->bindingTypes['raw_message']);
		$stream = fopen('php://temp', 'w+b');
		try
		{
			fwrite($stream, $raw);
			rewind($stream);
			$db->messages[1]['raw_message'] = $stream;
			$this->assertSame($raw, $store->raw(1));
		}
		finally { fclose($stream); }
	}

	public function testMetadataUpgradeBackfillsBatchesWithoutChangingMimeOrFlags(): void
	{
		require_once __DIR__ . '/../../src/modules/email/setup/tables_update.inc.php';
		$db = new PopStoreFakeDb();
		$store = new PopMessageStore(17, 2, PopMessageStore::mailboxKey('pop.test', 995, 'user'), $db);
		$store->save('uid-a', $this->raw('Backfill A'));
		$store->save('uid-b', $this->raw('Backfill B'));
		$store->flag(1, '\\Seen', true);
		$store->flag(2, '\\Deleted', true);
		$store->expunge();
		$before = $db->messages;
		$db->columns = array_diff(array_keys($before[1]), ['date_sent', 'subject_sort', 'from_sort', 'to_sort', 'cc_sort']);
		foreach ($db->messages as &$row) foreach (['date_sent', 'subject_sort', 'from_sort', 'to_sort', 'cc_sort'] as $column) unset($row[$column]);
		unset($row);
		$processor = new class($db) {
			public function __construct(public object $m_odb) {}
			public function AddColumn($table, $column, $definition): bool { $this->m_odb->columns[] = $column; return true; }
			public function AlterColumn($table, $column, $definition): bool { return $definition['nullable'] === false; }
		};
		$this->assertSame('0.9.17.503', \email_upgrade0_9_17_502($processor));
		$this->assertSame($before, $db->messages);
		$this->assertSame(['begin', 'commit'], $db->transactions);
		$this->assertCount(6, array_filter($db->queries, static fn($sql) => str_starts_with($sql, 'CREATE INDEX')));
		PopMessageStore::backfillSortMetadata($db, 1);
		$this->assertSame($before, $db->messages);
	}

	public function testReadOnlySyncAgainstGreenMail(): void
	{
		if (getenv('GREENMAIL_POP_STORE_TEST') !== '1') $this->markTestSkipped('Set GREENMAIL_POP_STORE_TEST=1 with the seeded POP fixture.');
		$host = getenv('POP_TEST_HOST') ?: '127.0.0.1';
		$port = (int) (getenv('POP_TEST_PORT') ?: 1995);
		$db = new PopStoreFakeDb();
		$make = static fn() => new PopMailboxSource($host, $port, true, false, 'pop-test', 'local-test-password');
		$remote = $make();
		try { $expected = count($remote->uidls()); } finally { $remote->close(); }
		$this->assertGreaterThan(0, $expected);
		$transport = new \mail_dcom_pop3_local(static fn($owner, $account, $key) => new PopMessageStore($owner, $account, $key, $db), $make);
		$transport->setAccountContext(17, 2);
		$stream = $transport->open('{' . $host . ':' . $port . '/pop3/ssl/novalidate-cert}INBOX', 'pop-test', 'local-test-password');
		$this->assertSame($expected, $transport->num_msg($stream));
		$this->assertNotEmpty($transport->header($stream, 1)->fromaddress);
		$this->assertStringContainsString('X-Test-Message:', $transport->fetch_raw_mail($stream, 1));
		$transport->open('{' . $host . ':' . $port . '/pop3/ssl/novalidate-cert}INBOX', 'pop-test', 'local-test-password');
		$this->assertCount($expected, $db->messages);
		$remote = $make();
		try { $this->assertCount($expected, $remote->uidls()); } finally { $remote->close(); }
	}
}

final class PopStoreFakeDb
{
	public array $messages = [];
	public array $bindingTypes = [];
	public array $queries = [];
	public array $columns = [];
	public array $transactions = [];
	public function prepare(string $sql): PopStoreFakeStatement { $this->queries[] = $sql; return new PopStoreFakeStatement($this, $sql); }
	public function exec(string $sql): int { $this->queries[] = $sql; return 0; }
	public function transaction_begin(): void { $this->transactions[] = 'begin'; }
	public function transaction_commit(): bool { $this->transactions[] = 'commit'; return true; }
	public function transaction_abort(): void { $this->transactions[] = 'abort'; }
}

final class PopStoreFakeStatement
{
	private array $rows = [];
	private array $bound = [];
	public function __construct(private PopStoreFakeDb $db, private string $sql) {}
	public function bindValue(string $name, mixed $value, int $type): bool
	{
		$name = ltrim($name, ':');
		$this->bound[$name] = $value;
		$this->db->bindingTypes[$name] = $type;
		return true;
	}
	public function execute(array $values = []): bool
	{
		$values = $values + $this->bound;
		$this->rows = [];
		if (str_contains($this->sql, 'WHERE 1 = 0')) return true;
		if (str_starts_with($this->sql, 'INSERT'))
		{
			foreach ($this->db->messages as $row) if ($this->sameScope($row, $values) && $row['uidl'] === $values['uidl']) throw new \PDOException('Duplicate UIDL', 23505);
			$id = count($this->db->messages) + 1;
			$this->db->messages[$id] = ['message_id' => $id] + $values;
			return true;
		}
		foreach ($this->db->messages as &$row)
		{
			if ((isset($values['account_id']) && !$this->sameScope($row, $values)) || (isset($values['message_id']) && $row['message_id'] !== $values['message_id']) || (isset($values['uidl']) && $row['uidl'] !== $values['uidl'])) continue;
			if (isset($values['cursor']) && $row['message_id'] <= $values['cursor']) continue;
			if (str_contains($this->sql, 'is_hidden = 0') && $row['is_hidden']) continue;
			if (str_starts_with($this->sql, 'UPDATE'))
			{
				if (isset($values['date_sent'])) { foreach (['date_sent', 'subject_sort', 'from_sort', 'to_sort', 'cc_sort'] as $column) $row[$column] = $values[$column]; }
				elseif (str_contains($this->sql, 'SET is_hidden')) { if ($row['is_deleted']) $row['is_hidden'] = 1; }
				else { $column = str_contains($this->sql, 'SET is_seen') ? 'is_seen' : 'is_deleted'; $row[$column] = $values['flag_value']; }
			}
			else $this->rows[] = $row;
		}
		if (str_contains($this->sql, 'COUNT(*)'))
		{
			$this->rows = [[
				'message_count' => count($this->rows),
				'unseen_count' => count(array_filter($this->rows, static fn($row) => !$row['is_seen'])),
				'total_size' => array_sum(array_column($this->rows, 'message_size')),
				'uidnext' => $this->rows ? max(array_column($this->rows, 'message_id')) + 1 : 1,
			]];
		}
		elseif (preg_match('/ORDER BY ([a-z_]+) (ASC|DESC), message_id/', $this->sql, $sort))
		{
			usort($this->rows, static function ($left, $right) use ($sort) {
				$comparison = in_array($sort[1], ['date_sent', 'message_size'], true)
					? $left[$sort[1]] <=> $right[$sort[1]] : strcmp($left[$sort[1]], $right[$sort[1]]);
				$comparison = $comparison ?: $left['message_id'] <=> $right['message_id'];
				return $sort[2] === 'DESC' ? -$comparison : $comparison;
			});
			if (isset($values['page_size'])) $this->rows = array_slice($this->rows, $values['page_start'], $values['page_size']);
		}
		if (isset($values['batch_size'])) $this->rows = array_slice($this->rows, 0, $values['batch_size']);
		return true;
	}
	public function columnCount(): int { return count($this->db->columns); }
	public function getColumnMeta(int $index): array { return ['name' => array_values($this->db->columns)[$index]]; }
	private function sameScope(array $row, array $values): bool { return $row['account_id'] === $values['account_id'] && $row['mail_account'] === $values['mail_account'] && $row['mailbox_key'] === $values['mailbox_key']; }
	public function fetchAll(int $mode = PDO::FETCH_ASSOC): array { return $mode === PDO::FETCH_COLUMN ? array_column($this->rows, str_starts_with($this->sql, 'SELECT message_id') ? 'message_id' : 'uidl') : $this->rows; }
	public function fetch(int $mode = PDO::FETCH_ASSOC): array|false { return $this->rows[0] ?? false; }
	public function fetchColumn(): mixed { return $this->rows ? $this->rows[0][str_contains($this->sql, 'SELECT content_hash') ? 'content_hash' : 'message_id'] : false; }
}