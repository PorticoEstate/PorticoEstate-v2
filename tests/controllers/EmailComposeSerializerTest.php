<?php

namespace Tests\Controllers
{
	require_once __DIR__ . '/../../vendor/autoload.php';

	use App\modules\email\services\EmailComposeSerializer;
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
	}
}