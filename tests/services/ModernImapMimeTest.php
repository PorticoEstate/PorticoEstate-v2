<?php

namespace Tests\Services;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/modules/email/inc/ModernIMAPWrapper.php';

use App\modules\email\services\EmailDraftMessage;
use PHPUnit\Framework\TestCase;
use ZBateson\MailMimeParser\MailMimeParser;

class ModernImapMimeTest extends TestCase
{
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
}