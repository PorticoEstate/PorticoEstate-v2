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

	public function testFetchBodyUsesExactSectionAndReturnsEncodedBytes(): void
	{
		$property = new \ReflectionProperty(\ModernIMAPWrapper::class, 'connections');
		$property->setAccessible(true);
		$original = $property->getValue();
		$protocol = $this->getMockBuilder(\stdClass::class)->addMethods(['fetch'])->getMock();
		$response = new class {
			public function validatedData(): array { return [42 => ['BODY[1.2]' => base64_encode('attachment bytes'), 'UID' => 42]]; }
		};
		$protocol->expects($this->once())->method('fetch')->with(['BODY.PEEK[1.2]', 'UID'], [42], null, \Webklex\PHPIMAP\IMAP::ST_UID)->willReturn($response);
		$client = $this->getMockBuilder(\stdClass::class)->addMethods(['openFolder', 'getConnection'])->getMock();
		$client->expects($this->once())->method('openFolder')->with('INBOX.Drafts');
		$client->method('getConnection')->willReturn($protocol);
		$property->setValue(null, ['mime-test' => ['client' => $client, 'folder' => (object) ['path' => 'INBOX.Drafts']]]);
		try
		{
			$this->assertSame(base64_encode('attachment bytes'), \ModernIMAPWrapper::imap_fetchbody('mime-test', 42, '1.2', 3));
		}
		finally
		{
			$property->setValue(null, $original);
		}
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