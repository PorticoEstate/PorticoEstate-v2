<?php

namespace Tests\Services;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/modules/email/inc/ModernIMAPWrapper.php';

use App\modules\email\services\EmailDraftMessage;
use PHPUnit\Framework\TestCase;
use ZBateson\MailMimeParser\MailMimeParser;

class ModernImapMimeTest extends TestCase
{
	/**
	 * @dataProvider closeModes
	 */
	public function testCloseOnlyExpungesWhenRequested(int $flags, bool $expungeSucceeds): void
	{
		$property = new \ReflectionProperty(\ModernIMAPWrapper::class, 'connections');
		$property->setAccessible(true);
		$original = $property->getValue();
		$response = $this->getMockBuilder(\stdClass::class)->addMethods(['validatedData'])->getMock();
		if ($expungeSucceeds)
		{
			$response->method('validatedData')->willReturn([]);
		}
		else
		{
			$response->method('validatedData')->willThrowException(new \RuntimeException('CLOSE rejected'));
		}
		$protocol = $this->getMockBuilder(\stdClass::class)->addMethods(['requestAndResponse'])->getMock();
		$protocol->expects($flags ? $this->once() : $this->never())->method('requestAndResponse')
			->with('CLOSE', [], true)->willReturn($response);
		$client = $this->getMockBuilder(\stdClass::class)->addMethods(['openFolder', 'getConnection', 'disconnect', 'setActiveFolder'])->getMock();
		$client->expects($flags ? $this->once() : $this->never())->method('openFolder')->with('Test');
		$client->method('getConnection')->willReturn($protocol);
		$closed = !$flags || $expungeSucceeds;
		$client->expects($closed ? $this->once() : $this->never())->method('disconnect');
		$property->setValue(null, ['close-test' => ['client' => $client, 'folder' => (object) ['path' => 'Test']]]);
		try
		{
			$this->assertSame($closed, \ModernIMAPWrapper::imap_close('close-test', $flags));
			$this->assertSame(!$closed, in_array('close-test', \ModernIMAPWrapper::getActiveConnections(), true));
			if (!$closed)
			{
				$this->assertSame('CLOSE rejected', \ModernIMAPWrapper::imap_last_error());
			}
		}
		finally
		{
			$property->setValue(null, $original);
		}
	}

	public static function closeModes(): array
	{
		return [[0, true], [32768, true], [32768, false]];
	}

	/**
	 * @dataProvider mailboxReferences
	 */
	public function testMailboxListingUsesServerPatternsAndAttributes(string $reference, string $serverReference, string $prefix): void
	{
		$property = new \ReflectionProperty(\ModernIMAPWrapper::class, 'connections');
		$property->setAccessible(true);
		$original = $property->getValue();
		$response = new class {
			public function validatedData(): array
			{
				return [
					'Root/Child' => ['delimiter' => '/', 'flags' => ['\\NoSelect', '\\HasChildren', '\\Marked']],
					'Flat' => ['delimiter' => null, 'flags' => ['\\NoInferiors', '\\HasNoChildren', '\\Unmarked', '\\Unknown']],
				];
			}
		};
		$protocol = $this->getMockBuilder(\stdClass::class)->addMethods(['folders'])->getMock();
		$protocol->expects($this->exactly(2))->method('folders')->with($serverReference, '%')->willReturn($response);
		$client = $this->getMockBuilder(\stdClass::class)->addMethods(['getConnection'])->getMock();
		$client->method('getConnection')->willReturn($protocol);
		$property->setValue(null, ['list-test' => ['client' => $client]]);
		try
		{
			$mailboxes = \ModernIMAPWrapper::imap_getmailboxes('list-test', $reference, '%');
			$this->assertSame($prefix . 'Root/Child', $mailboxes[0]->name);
			$this->assertSame('/', $mailboxes[0]->delimiter);
			$this->assertSame(38, $mailboxes[0]->attributes);
			$this->assertNull($mailboxes[1]->delimiter);
			$this->assertSame(73, $mailboxes[1]->attributes);
			$this->assertSame([$prefix . 'Root/Child', $prefix . 'Flat'], \ModernIMAPWrapper::imap_list('list-test', $reference, '%'));
		}
		finally
		{
			$property->setValue(null, $original);
		}
	}

	public static function mailboxReferences(): array
	{
		return [['', '', ''], ['Root/', 'Root/', ''], ['{test:993/imap/ssl}Root/', 'Root/', '{test:993/imap/ssl}']];
	}

	/**
	 * @dataProvider subscriptionCommands
	 */
	public function testSubscriptionsValidateServerResponse(string $method, string $command, string $mailbox, bool $succeeds): void
	{
		$property = new \ReflectionProperty(\ModernIMAPWrapper::class, 'connections');
		$property->setAccessible(true);
		$original = $property->getValue();
		$response = $this->getMockBuilder(\stdClass::class)->addMethods(['validatedData'])->getMock();
		if ($succeeds)
		{
			$response->expects($this->once())->method('validatedData')->willReturn([]);
		}
		else
		{
			$response->expects($this->once())->method('validatedData')->willThrowException(new \RuntimeException('Subscription rejected'));
		}
		$protocol = $this->getMockBuilder(\stdClass::class)->addMethods(['subscribeFolder', 'unsubscribeFolder'])->getMock();
		$protocol->expects($this->once())->method($command)->with('Folder &- Test')->willReturn($response);
		$client = $this->getMockBuilder(\stdClass::class)->addMethods(['getConnection'])->getMock();
		$client->method('getConnection')->willReturn($protocol);
		$property->setValue(null, ['subscription-test' => ['client' => $client]]);
		try
		{
			$this->assertSame($succeeds, \ModernIMAPWrapper::$method('subscription-test', $mailbox));
			if (!$succeeds)
			{
				$this->assertSame('Subscription rejected', \ModernIMAPWrapper::imap_last_error());
			}
			$this->assertFalse(\ModernIMAPWrapper::$method('unknown-stream', $mailbox));
		}
		finally
		{
			$property->setValue(null, $original);
		}
	}

	public static function subscriptionCommands(): array
	{
		$cases = [];
		foreach (['imap_subscribe' => 'subscribeFolder', 'imap_unsubscribe' => 'unsubscribeFolder'] as $method => $command)
		{
			foreach (['Folder &- Test', '{test:993/imap/ssl}Folder &- Test'] as $mailbox)
			{
				foreach ([true, false] as $succeeds)
				{
					$cases[] = [$method, $command, $mailbox, $succeeds];
				}
			}
		}
		return $cases;
	}

	/**
	 * @dataProvider openFailures
	 */
	public function testFailedOpenDisconnectsWithoutPublishingConnection(string $stage): void
	{
		$property = new \ReflectionProperty(\ModernIMAPWrapper::class, 'clientManager');
		$property->setAccessible(true);
		$original = $property->getValue();
		$connections = \ModernIMAPWrapper::getActiveConnections();
		$client = $this->getMockBuilder(\stdClass::class)
			->addMethods(['connect', 'getFolderByPath', 'openFolder', 'disconnect'])->getMock();
		if ($stage === 'connect')
		{
			$client->method('connect')->willThrowException(new \RuntimeException('connect failed'));
			$client->expects($this->never())->method('getFolderByPath');
		}
		elseif ($stage === 'lookup')
		{
			$client->method('getFolderByPath')->willThrowException(new \RuntimeException('lookup failed'));
		}
		else
		{
			$client->method('getFolderByPath')->willReturn(
				$stage === 'select' ? (object) ['path' => 'Missing'] : null
			);
		}
		if ($stage === 'select')
		{
			$client->expects($this->once())->method('openFolder')->with('Missing', true)
				->willThrowException(new \RuntimeException('select failed'));
		}
		else
		{
			$client->expects($this->never())->method('openFolder');
		}
		$cleanup = $client->expects($this->once())->method('disconnect');
		if ($stage === 'cleanup')
		{
			$cleanup->willThrowException(new \RuntimeException('cleanup sensitive detail'));
		}
		$manager = $this->getMockBuilder(\stdClass::class)->addMethods(['make'])->getMock();
		$manager->method('make')->willReturn($client);
		$property->setValue(null, $manager);
		try
		{
			$this->assertFalse(\ModernIMAPWrapper::imap_open('{test:993/imap/ssl}Missing', 'user', 'password'));
			$this->assertSame($connections, \ModernIMAPWrapper::getActiveConnections());
			$this->assertSame(match ($stage) {
				'connect', 'lookup', 'select' => $stage . ' failed',
				'cleanup' => 'Mailbox does not exist Connection cleanup failed (RuntimeException).',
				default => 'Mailbox does not exist',
			}, \ModernIMAPWrapper::imap_last_error());
		}
		finally
		{
			$property->setValue(null, $original);
		}
	}

	public static function openFailures(): array
	{
		return [['connect'], ['lookup'], ['missing'], ['select'], ['cleanup']];
	}

	/**
	 * @dataProvider searchIdentifierModes
	 */
	public function testSearchReturnsRequestedIdentifierMode(int $flags, array $expected): void
	{
		$property = new \ReflectionProperty(\ModernIMAPWrapper::class, 'connections');
		$property->setAccessible(true);
		$original = $property->getValue();
		$message = new class {
			public function getUid(): int { return 42; }
			public function getMsgn(): int { return 2; }
		};
		$query = $this->getMockBuilder(\stdClass::class)->addMethods(['all', 'get'])->getMock();
		$query->expects($this->once())->method('all');
		$query->method('get')->willReturn([$message]);
		$folder = $this->getMockBuilder(\stdClass::class)->addMethods(['query'])->getMock();
		$folder->method('query')->willReturn($query);
		$property->setValue(null, ['search-test' => ['folder' => $folder]]);
		try
		{
			$this->assertSame($expected, \ModernIMAPWrapper::imap_search('search-test', 'ALL', $flags));
		}
		finally
		{
			$property->setValue(null, $original);
		}
	}

	public static function searchIdentifierModes(): array
	{
		return [[0, [2]], [1, [42]]];
	}

	/**
	 * @dataProvider flagSearchCriteria
	 */
	public function testFlagSearchCriteriaAreForwarded(string $criteria): void
	{
		$property = new \ReflectionProperty(\ModernIMAPWrapper::class, 'connections');
		$property->setAccessible(true);
		$original = $property->getValue();
		$query = $this->getMockBuilder(\stdClass::class)->addMethods(['where', 'deleted', 'get'])->getMock();
		if ($criteria === 'FLAGGED')
		{
			$query->expects($this->once())->method('where')->with('FLAGGED');
			$query->expects($this->never())->method('deleted');
		}
		else
		{
			$query->expects($this->never())->method('where');
			$query->expects($this->once())->method('deleted');
		}
		$query->method('get')->willReturn([]);
		$folder = $this->getMockBuilder(\stdClass::class)->addMethods(['query'])->getMock();
		$folder->method('query')->willReturn($query);
		$property->setValue(null, ['flags-test' => ['folder' => $folder]]);
		try
		{
			$this->assertSame([], \ModernIMAPWrapper::imap_search('flags-test', $criteria, 1));
		}
		finally
		{
			$property->setValue(null, $original);
		}
	}

	public static function flagSearchCriteria(): array
	{
		return [['FLAGGED'], ['DELETED']];
	}

	/**
	 * @dataProvider deletionResults
	 */
	public function testDeletionMarksWithoutExpungingAndPropagatesFailure(bool $result, $set, int $flags, string $expectedSet): void
	{
		$property = new \ReflectionProperty(\ModernIMAPWrapper::class, 'connections');
		$property->setAccessible(true);
		$original = $property->getValue();
		$response = $this->getMockBuilder(\stdClass::class)->addMethods(['validatedData'])->getMock();
		if ($result)
		{
			$response->method('validatedData')->willReturn([]);
		}
		else
		{
			$response->method('validatedData')->willThrowException(new \RuntimeException('STORE rejected'));
		}
		$protocol = $this->getMockBuilder(\stdClass::class)->addMethods(['requestAndResponse', 'expunge'])->getMock();
		$protocol->expects($this->once())->method('requestAndResponse')
			->with(($flags & 1) ? 'UID STORE' : 'STORE', [$expectedSet, '+FLAGS.SILENT', '(\\Deleted)'], true)->willReturn($response);
		$protocol->expects($this->never())->method('expunge');
		$client = $this->getMockBuilder(\stdClass::class)->addMethods(['openFolder', 'getConnection'])->getMock();
		$client->expects($this->once())->method('openFolder')->with('Test');
		$client->method('getConnection')->willReturn($protocol);
		$property->setValue(null, ['delete-test' => ['folder' => (object) ['path' => 'Test'], 'client' => $client]]);
		try
		{
			$this->assertSame($result, \ModernIMAPWrapper::imap_delete('delete-test', $set, $flags));
			if (!$result)
			{
				$this->assertSame('STORE rejected', \ModernIMAPWrapper::imap_last_error());
			}
		}
		finally
		{
			$property->setValue(null, $original);
		}
	}

	public static function deletionResults(): array
	{
		return [
			[true, '42', 1, '42'],
			[false, '42', 1, '42'],
			[true, '2:5,8', 0, '2:5,8'],
			[true, '1:*', 0, '1:*'],
			[true, '*:2', 1, '*:2'],
			[true, ['2', '4:7'], 1, '2,4:7'],
		];
	}

	/**
	 * @dataProvider invalidDeletionSets
	 */
	public function testInvalidDeletionSetFailsBeforeSendingCommands(string $set): void
	{
		$property = new \ReflectionProperty(\ModernIMAPWrapper::class, 'connections');
		$property->setAccessible(true);
		$original = $property->getValue();
		$client = $this->getMockBuilder(\stdClass::class)->addMethods(['openFolder', 'getConnection'])->getMock();
		$client->expects($this->never())->method('openFolder');
		$client->expects($this->never())->method('getConnection');
		$property->setValue(null, ['invalid-delete' => ['folder' => (object) ['path' => 'Test'], 'client' => $client]]);
		try
		{
			$this->assertFalse(\ModernIMAPWrapper::imap_delete('invalid-delete', $set));
			$this->assertSame('Invalid message set', \ModernIMAPWrapper::imap_last_error());
		}
		finally
		{
			$property->setValue(null, $original);
		}
	}

	public static function invalidDeletionSets(): array
	{
		return [[''], ['0'], ['1:'], ['1,,2'], ["1\r\nEXPUNGE"], ['-1'], ["1\n"]];
	}

	/**
	 * @dataProvider messageCounts
	 */
	public function testMessageCountUsesStatusWithoutChangingSelectedMailbox(array $data, $expected): void
	{
		$property = new \ReflectionProperty(\ModernIMAPWrapper::class, 'connections');
		$property->setAccessible(true);
		$original = $property->getValue();
		$response = new class($data) {
			private array $data;
			public function __construct(array $data) { $this->data = $data; }
			public function validatedData(): array { return $this->data; }
		};
		$protocol = $this->getMockBuilder(\stdClass::class)->addMethods(['folderStatus'])->getMock();
		$protocol->expects($this->once())->method('folderStatus')->with('INBOX.Test', ['MESSAGES'])->willReturn($response);
		$client = $this->getMockBuilder(\stdClass::class)->addMethods(['getConnection', 'openFolder'])->getMock();
		$client->method('getConnection')->willReturn($protocol);
		$client->expects($this->never())->method('openFolder');
		$property->setValue(null, ['count-test' => [
			'client' => $client, 'folder' => (object) ['path' => 'INBOX.Test'],
		]]);
		try
		{
			$this->assertSame($expected, \ModernIMAPWrapper::imap_num_msg('count-test'));
			if ($expected === false)
			{
				$this->assertStringContainsString('message count', \ModernIMAPWrapper::imap_last_error());
			}
			$this->assertFalse(\ModernIMAPWrapper::imap_num_msg('unknown-stream'));
		}
		finally
		{
			$property->setValue(null, $original);
		}
	}

	public static function messageCounts(): array
	{
		return [
			'empty' => [['MESSAGES' => 0], 0],
			'populated' => [['messages' => '3'], 3],
			'missing count' => [[], false],
		];
	}

	private function structure(string $raw): object
	{
		$part = (new MailMimeParser())->parse($raw, false);
		$convert = new \ReflectionMethod(\ModernIMAPWrapper::class, 'convertMimeStructure');
		$convert->setAccessible(true);
		return $convert->invoke(null, $part);
	}

	public function testDraftRetainsTwoAttachmentsAndTransferEncodings(): void
	{
		$first = tmpfile();
		$second = tmpfile();
		try
		{
			fwrite($first, 'First attachment');
			fwrite($second, 'Second attachment');
			$raw = EmailDraftMessage::build(['subject' => 'Draft with files', 'body' => 'Draft body'], 'sender@example.test', 'Sender', [
				['path' => stream_get_meta_data($first)['uri'], 'name' => 'first.txt'],
				['path' => stream_get_meta_data($second)['uri'], 'name' => 'second.pdf'],
			]);
			$structure = $this->structure($raw);
			$this->assertSame(1, $structure->type);
			$this->assertSame('MIXED', $structure->subtype);
			$this->assertCount(3, $structure->parts);
			$this->assertSame('PLAIN', $structure->parts[0]->subtype);
			foreach (['first.txt', 'second.pdf'] as $index => $name)
			{
				$part = $structure->parts[$index + 1];
				$this->assertSame('ATTACHMENT', $part->disposition);
				$this->assertSame($name, $part->dparameters[0]->value);
				$this->assertSame(3, $part->encoding);
				$this->assertGreaterThan(0, $part->bytes);
			}
		}
		finally
		{
			fclose($first);
			fclose($second);
		}
	}

	public function testNestedAlternativeAndInlineImageRetainTheirTree(): void
	{
		$mail = new \PHPMailer\PHPMailer\PHPMailer(true);
		$mail->setFrom('sender@example.test');
		$mail->addAddress('recipient@example.test');
		$mail->isHTML(true);
		$mail->Body = '<p>Hello<img src="cid:image"></p>';
		$mail->AltBody = 'Hello';
		$mail->addStringEmbeddedImage('image bytes', 'image', 'image.png', 'base64', 'image/png');
		$mail->addStringAttachment('file bytes', 'file.txt');
		$mail->preSend();
		$structure = $this->structure($mail->getSentMIMEMessage());
		$this->assertSame('MIXED', $structure->subtype);
		$this->assertSame('ALTERNATIVE', $structure->parts[0]->subtype);
		$this->assertSame('PLAIN', $structure->parts[0]->parts[0]->subtype);
		$this->assertSame('RELATED', $structure->parts[0]->parts[1]->subtype);
		$this->assertSame('HTML', $structure->parts[0]->parts[1]->parts[0]->subtype);
		$this->assertSame('image', $structure->parts[0]->parts[1]->parts[1]->id);
		$this->assertSame('file.txt', $structure->parts[1]->dparameters[0]->value);
	}

	/**
	 * @dataProvider bodyFetchModes
	 */
	public function testFetchBodyUsesExactSectionAndReturnsEncodedBytes(int $flags): void
	{
		$property = new \ReflectionProperty(\ModernIMAPWrapper::class, 'connections');
		$property->setAccessible(true);
		$original = $property->getValue();
		$protocol = $this->getMockBuilder(\stdClass::class)->addMethods(['fetch'])->getMock();
		$response = new class {
			public function validatedData(): array { return [42 => ['BODY[1.2]' => base64_encode('attachment bytes'), 'UID' => 42]]; }
		};
		$protocol->expects($this->once())->method('fetch')->with(
			[($flags & 2) ? 'BODY.PEEK[1.2]' : 'BODY[1.2]', 'UID'], [42], null,
			($flags & 1) ? \Webklex\PHPIMAP\IMAP::ST_UID : \Webklex\PHPIMAP\IMAP::ST_MSGN
		)->willReturn($response);
		$client = $this->getMockBuilder(\stdClass::class)->addMethods(['openFolder', 'getConnection'])->getMock();
		$client->expects($this->once())->method('openFolder')->with('INBOX.Drafts');
		$client->method('getConnection')->willReturn($protocol);
		$property->setValue(null, ['mime-test' => ['client' => $client, 'folder' => (object) ['path' => 'INBOX.Drafts']]]);
		try
		{
			$this->assertSame(base64_encode('attachment bytes'), \ModernIMAPWrapper::imap_fetchbody('mime-test', 42, '1.2', $flags));
		}
		finally
		{
			$property->setValue(null, $original);
		}
	}

	public static function bodyFetchModes(): array
	{
		return [[0], [1], [2], [3]];
	}

	public function testFetchStructureUsesBodyStructureInsteadOfFetchingMessageBody(): void
	{
		$property = new \ReflectionProperty(\ModernIMAPWrapper::class, 'connections');
		$property->setAccessible(true);
		$original = $property->getValue();
		$protocol = $this->getMockBuilder(\stdClass::class)->addMethods(['fetch'])->getMock();
		$response = new class {
			public function validatedData(): array
			{
				return [42 => ['UID' => 42, 'BODYSTRUCTURE' => [
					'APPLICATION', 'PDF', [['NAME', 'report.pdf']], null, null, 'BASE64', 256, null,
					['ATTACHMENT', [['FILENAME', 'report.pdf']]], null, null, null,
				]]];
			}
		};
		$protocol->expects($this->once())->method('fetch')->with(
			['BODYSTRUCTURE', 'UID'],
			[42],
			null,
			\Webklex\PHPIMAP\IMAP::ST_UID
		)->willReturn($response);
		$client = $this->getMockBuilder(\stdClass::class)->addMethods(['openFolder', 'getConnection'])->getMock();
		$client->expects($this->once())->method('openFolder')->with('INBOX');
		$client->method('getConnection')->willReturn($protocol);
		$property->setValue(null, ['structure-test' => [
			'client' => $client,
			'folder' => (object) ['path' => 'INBOX'],
		]]);
		try
		{
			$structure = \ModernIMAPWrapper::imap_fetchstructure('structure-test', 42, 1);
			$this->assertSame(3, $structure->type);
			$this->assertSame('ATTACHMENT', $structure->disposition);
			$this->assertSame('report.pdf', $structure->dparameters[0]->value);
		}
		finally
		{
			$property->setValue(null, $original);
		}
	}

	public function testMetadataPrefetchCachesHeadersAndStructuresForUidRows(): void
	{
		$property = new \ReflectionProperty(\ModernIMAPWrapper::class, 'connections');
		$property->setAccessible(true);
		$original = $property->getValue();
		$protocol = $this->getMockBuilder(\stdClass::class)->addMethods(['fetch'])->getMock();
		$response = new class {
			public function validatedData(): array
			{
				return [42 => [
					'UID' => 42,
					'INTERNALDATE' => '06-Oct-2026 10:00:00 +0000',
					'RFC822.SIZE' => 256,
					'BODY[HEADER]' => "From: Sender <sender@example.test>\r\nSubject: Batch header\r\nDate: Tue, 06 Oct 2026 10:00:00 +0000\r\nMessage-ID: <batch@example.test>\r\n",
					'FLAGS' => ['\\Seen'],
				]];
			}
		};
		$protocol->expects($this->once())->method('fetch')->with(
			['UID', 'INTERNALDATE', 'RFC822.SIZE', 'BODY.PEEK[HEADER]', 'FLAGS'],
			[42, 43],
			null,
			\Webklex\PHPIMAP\IMAP::ST_UID
		)->willReturn($response);
		$config = new \Webklex\PHPIMAP\Config(['options' => []]);
		$client = $this->getMockBuilder(\stdClass::class)->addMethods(['openFolder', 'getConnection', 'getConfig'])->getMock();
		$client->expects($this->once())->method('openFolder')->with('INBOX');
		$client->method('getConnection')->willReturn($protocol);
		$client->method('getConfig')->willReturn($config);
		$property->setValue(null, ['prefetch-test' => [
			'client' => $client,
			'folder' => (object) ['path' => 'INBOX'],
		]]);
		try
		{
			$this->assertSame([42], \ModernIMAPWrapper::imap_prefetch_message_metadata('prefetch-test', [42, 42, 43], 1));
			$header = \ModernIMAPWrapper::imap_header('prefetch-test', 42, 0, 0, '', 1);
			$this->assertSame('Batch header', $header->subject);
			$this->assertSame(' ', $header->Unseen);
			$this->assertSame(256, (int) $header->Size);
		}
		finally
		{
			$property->setValue(null, $original);
		}
	}

	public function testSortAcceptsNumericStringCriteriaFromRequests(): void
	{
		$property = new \ReflectionProperty(\ModernIMAPWrapper::class, 'connections');
		$property->setAccessible(true);
		$original = $property->getValue();
		$protocol = $this->getMockBuilder(\stdClass::class)->addMethods(['fetch'])->getMock();
		$response = new class {
			public function validatedData(): array
			{
				return [1 => [
					'UID' => 42,
					'INTERNALDATE' => '06-Oct-2026 10:00:00 +0000',
					'RFC822.SIZE' => 256,
					'BODY[HEADER]' => "Subject: Sortable subject\r\nFrom: sender@example.test\r\n",
				]];
			}
		};
		$protocol->expects($this->once())->method('fetch')->with(
			['UID', 'INTERNALDATE', 'RFC822.SIZE', 'BODY.PEEK[HEADER]'],
			1,
			INF,
			\Webklex\PHPIMAP\IMAP::ST_MSGN
		)->willReturn($response);
		$client = $this->getMockBuilder(\stdClass::class)->addMethods(['openFolder', 'getConnection', 'getConfig'])->getMock();
		$client->expects($this->once())->method('openFolder')->with('INBOX');
		$client->method('getConnection')->willReturn($protocol);
		$client->method('getConfig')->willReturn(new \Webklex\PHPIMAP\Config(['options' => []]));
		$property->setValue(null, ['sort-test' => [
			'client' => $client,
			'folder' => (object) ['path' => 'INBOX'],
		]]);
		try
		{
			$this->assertSame([42], \ModernIMAPWrapper::imap_sort('sort-test', '3', '0', 1));
		}
		finally
		{
			$property->setValue(null, $original);
		}
	}
}