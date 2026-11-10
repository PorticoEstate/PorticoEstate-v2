<?php

namespace Tests\Controllers
{
	require_once __DIR__ . '/../../vendor/autoload.php';

	use App\modules\email\services\EmailComposeSerializer;
	use App\modules\email\services\EmailDraftMessage;
	use PHPUnit\Framework\TestCase;

	class EmailComposeSerializerTest extends TestCase
	{
		public function testNormalizesMessageAndMailboxContext(): void
		{
			$this->assertSame([
				'to' => 'recipient@example.test',
				'cc' => '',
				'bcc' => '',
				'subject' => 'Hello world',
				'body' => 'Body text',
				'sender' => '',
				'fwd_proc' => '',
				'action' => 'new',
				'fldball' => ['acctnum' => 0, 'folder' => 'INBOX'],
				'attach_sig' => 'true',
			], EmailComposeSerializer::normalize([
				'to' => ' recipient@example.test ',
				'subject' => "Hello\r\nworld",
				'body' => ' Body text ',
				'fldball' => ['acctnum' => '0', 'folder' => 'INBOX', 'extra' => 'ignored'],
				'attach_sig' => true,
				'ignored' => 'value',
			]));
		}

		public function testRejectsEmptyRecipientsUnsupportedActionsAndInvalidContext(): void
		{
			$this->assertNull(EmailComposeSerializer::normalize(['subject' => 'No recipients']));
			$this->assertNull(EmailComposeSerializer::normalize(['to' => 'a@example.test', 'action' => 'admin']));
			$this->assertNull(EmailComposeSerializer::normalize(['to' => 'a@example.test', 'msgball' => ['acctnum' => -1]]));
		}

		public function testDraftAllowsEmptyRecipientsWithoutChangingSendValidation(): void
		{
			$input = ['subject' => 'Unfinished', 'body' => 'Draft body'];
			$payload = EmailComposeSerializer::normalize($input, false);
			$this->assertNotNull($payload);
			$this->assertNull(EmailComposeSerializer::normalize($input));
			$raw = EmailDraftMessage::build($payload, 'sender@example.test', 'Sender');
			$this->assertStringContainsString('Subject: Unfinished', $raw);
			$this->assertStringContainsString("\r\n\r\nDraft body", $raw);
			$this->assertStringContainsString('From: Sender <sender@example.test>', $raw);
			$this->assertStringContainsString('Content-Type: text/plain', EmailDraftMessage::build([], 'sender@example.test', 'Sender'));
		}

		public function testDraftMimePreservesRecipientsAndAttachmentContent(): void
		{
			$file = tmpfile();
			try
			{
				fwrite($file, 'Draft attachment');
				$raw = EmailDraftMessage::build(['to' => 'to@example.test', 'cc' => 'cc@example.test', 'bcc' => 'bcc@example.test', 'body' => 'Body'],
					'sender@example.test', 'Sender', [['path' => stream_get_meta_data($file)['uri'], 'name' => 'test.txt']]);
				$this->assertStringContainsString('To: to@example.test', $raw);
				$this->assertStringContainsString('Cc: cc@example.test', $raw);
				$this->assertStringContainsString('Bcc: bcc@example.test', $raw);
				$this->assertStringContainsString('Content-Type: multipart/mixed', $raw);
				$this->assertStringContainsString(base64_encode('Draft attachment'), $raw);
			}
			finally
			{
				fclose($file);
			}
		}

		public function testDraftRejectsMalformedRecipientAddresses(): void
		{
			$this->expectException(\Throwable::class);
			EmailDraftMessage::build(['to' => 'not-an-email'], 'sender@example.test', 'Sender');
		}
	}
}