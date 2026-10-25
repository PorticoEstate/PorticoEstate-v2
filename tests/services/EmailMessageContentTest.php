<?php

namespace Tests\Services;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/helpers/Sanitizer.php';

use App\modules\email\services\EmailMessageContent;
use App\modules\email\services\EmailMessageSerializer;
use App\modules\phpgwapi\services\Settings;
use PHPUnit\Framework\TestCase;

class EmailMessageContentTest extends TestCase
{
	private \ReflectionProperty $settingsProperty;
	private mixed $originalSettings;

	protected function setUp(): void
	{
		$this->settingsProperty = new \ReflectionProperty(Settings::class, 'instance');
		$this->settingsProperty->setAccessible(true);
		$this->originalSettings = $this->settingsProperty->getValue();
		$this->settingsProperty->setValue(null, new class
		{
			public function get(string $key): array
			{
				return match ($key) { 'server' => ['webserver_url' => 'https://example.test', 'usecookies' => true], 'flags' => [], default => [] };
			}
		});
	}

	protected function tearDown(): void
	{
		$this->settingsProperty->setValue(null, $this->originalSettings);
	}

	public function testBuildsPlainHtmlInlineImageAndAttachmentPayloads(): void
	{
		$parts = [
			['m_description' => 'presentable', 'm_part_num_mime' => '1', 'm_keywords' => 'plain 7bit', 'type' => 'text', 'subtype' => 'plain', 'ex_attachment' => false],
			['m_description' => 'presentable', 'm_part_num_mime' => '2', 'm_keywords' => 'html 7bit', 'type' => 'text', 'subtype' => 'html', 'ex_attachment' => false],
			['m_description' => 'presentable/image', 'm_part_num_mime' => '3', 'm_keywords' => 'base64', 'type' => 'image', 'subtype' => 'png', 'id' => '<hero-image>', 'ex_attachment' => true, 'ex_part_name' => 'hero.png', 'bytes' => 4, 'encoding' => 'base64'],
			['m_description' => 'attachment', 'm_part_num_mime' => '4', 'm_keywords' => 'base64', 'type' => 'application', 'subtype' => 'pdf', 'ex_attachment' => true, 'ex_part_name' => 'report.pdf', 'bytes' => 7, 'encoding' => 'base64'],
		];
		$mail = new class($parts) {
			public function __construct(public array $parts) {}
			public function phpgw_fetchstructure($msgball, $display) { return (object) []; }
			public function get_flat_pgw_struct($structure) { return $this->parts; }
			public function phpgw_fetchbody($msgball) { return match ($msgball['part_no']) { '1' => 'Plain &amp; readable', '2' => '<p>HTML body<img src="cid:hero-image"></p><script>bad()</script>', '3' => base64_encode('PNG!'), '4' => base64_encode('PDF data') }; }
			public function qprint($body) { return quoted_printable_decode($body); }
			public function de_base64($body) { return base64_decode($body); }
			public function normalize_crlf($body) { return str_replace(["\r\n", "\r", "\n"], "\r\n", $body); }
			public function make_clickable($body, $folder) { return $body; }
			public function get_subject($headers, $prefix) { return $headers->Subject; }
		};
		$headers = (object) ['Subject' => 'Monthly report', 'udate' => 1700000000];
		$content = (new EmailMessageContent())->build($mail, ['acctnum' => 2, 'folder' => 'INBOX', 'msgnum' => 9], $headers,
			static fn($part, $inline) => '/email/api/attachment?part=' . $part . ($inline ? '&inline=1' : ''),
			static fn($timestamp) => 'Nov 14, 2023');

		$this->assertSame('Monthly report', $content['data']['message_subject']);
		$this->assertSame('Nov 14, 2023', $content['data']['message_date']);
		$this->assertSame('show', $content['parts'][0]['d_instructions']);
		$this->assertStringContainsString('Plain &amp; readable', $content['parts'][0]['message_body']);
		$this->assertSame('Plain & readable', $content['parts'][0]['unformatted_text']);
		$this->assertStringContainsString('/email/api/attachment?part=3&amp;inline=1', $content['parts'][1]['message_body']);
		$this->assertSame('image_href', $content['parts'][2]['d_processed_as']);
		$item = EmailMessageSerializer::serialize($content['data'], $content['parts']);
		$this->assertCount(3, $item['bodyParts']);
		$this->assertCount(2, $item['attachments']);
		$this->assertStringContainsString('/email/api/attachment?part=3&amp;inline=1', $item['bodyParts'][1]['html']);
		$this->assertTrue($item['bodyParts'][1]['requiresConfirmation']);
		$this->assertStringContainsString('<script>bad()</script>', $item['bodyParts'][1]['source']);
		$this->assertStringNotContainsString('<script>', $item['bodyParts'][1]['html']);
		$this->assertSame('Plain & readable', $item['bodyParts'][0]['text']);
	}

	public function testHidesAlternativePartsMarkedAltHide(): void
	{
		$mail = new class {
			public function phpgw_fetchstructure($msgball, $display) { return (object) []; }
			public function get_flat_pgw_struct($structure) { return [['m_description' => 'presentable', 'm_part_num_mime' => '1', 'm_keywords' => 'plain alt_hide', 'type' => 'text', 'subtype' => 'plain']]; }
			public function get_subject($headers, $prefix) { return ''; }
			public function normalize_crlf($body) { return $body; }
			public function make_clickable($body, $folder) { return $body; }
		};
		$content = (new EmailMessageContent())->build($mail, ['acctnum' => 0, 'folder' => 'INBOX', 'msgnum' => 1], (object) ['udate' => 0],
			static fn($part, $inline) => '', static fn($timestamp) => '');
		$this->assertSame('', $content['parts'][0]['d_instructions'] ?? '');
	}

	public function testMissingStructureFailsInsteadOfReturningAnEmptyMessage(): void
	{
		$mail = new class {
			public function phpgw_fetchstructure($msgball, $display) { return false; }
		};
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Unable to fetch message MIME structure');
		(new EmailMessageContent())->build($mail, ['acctnum' => 0, 'folder' => 'INBOX.Drafts', 'msgnum' => 1],
			(object) [], static fn($part, $inline) => '');
	}

	public function testDraftBodyAndAttachmentRoundTripAgainstGreenMail(): void
	{
		if (getenv('GREENMAIL_TEST') !== '1') $this->markTestSkipped('Set GREENMAIL_TEST=1 to use the local GreenMail fixture.');
		require_once __DIR__ . '/../../src/modules/email/inc/ModernIMAPWrapper.php';
		require_once __DIR__ . '/../../src/modules/email/inc/class.mail_msg_base.inc.php';
		require_once __DIR__ . '/../../src/modules/email/inc/class.mail_msg_wrappers.inc.php';
		require_once __DIR__ . '/../../src/modules/email/inc/class.mail_msg_display.inc.php';
		$prefix = '{127.0.0.1:1993/imap/ssl/novalidate-cert}';
		$folder = 'message-content-' . bin2hex(random_bytes(8));
		$stream = \ModernIMAPWrapper::imap_open($prefix . 'INBOX', 'outlook-test', 'local-test-password');
		$this->assertNotFalse($stream, (string) \ModernIMAPWrapper::imap_last_error());
		$created = false;
		$file = tmpfile();
		try
		{
			fwrite($file, 'Attachment content');
			$raw = \App\modules\email\services\EmailDraftMessage::build(
				['subject' => 'Draft content regression', 'body' => 'Draft body survives MIME parsing'],
				'sender@example.test', 'Sender',
				[['path' => stream_get_meta_data($file)['uri'], 'name' => 'report.txt']]
			);
			$this->assertTrue(\ModernIMAPWrapper::imap_createmailbox($stream, $prefix . $folder));
			$created = true;
			$this->assertTrue(\ModernIMAPWrapper::imap_append($stream, $prefix . $folder, $raw, '\\Draft'));
			$this->assertTrue(\ModernIMAPWrapper::imap_reopen($stream, $prefix . $folder));
			$uids = \ModernIMAPWrapper::imap_search($stream, 'ALL', 1);
			$this->assertCount(1, $uids);
			$mail = $this->getMockBuilder(\mail_msg::class)->disableOriginalConstructor()
				->onlyMethods(['phpgw_fetchstructure', 'phpgw_fetchbody', 'make_part_clickable', 'get_subject', 'make_clickable', 'get_arg_value'])->getMock();
			$mail->method('phpgw_fetchstructure')->willReturnCallback(
				static fn($ball) => \ModernIMAPWrapper::imap_fetchstructure($stream, $ball['msgnum'], 1));
			$mail->method('phpgw_fetchbody')->willReturnCallback(
				static fn($ball) => \ModernIMAPWrapper::imap_fetchbody($stream, $ball['msgnum'], $ball['part_no'], 3));
			$mail->method('make_part_clickable')->willReturn(['part_href' => '', 'part_clickable' => '']);
			$mail->method('get_arg_value')->willReturn([]);
			$mail->method('get_subject')->willReturn('Draft content regression');
			$mail->method('make_clickable')->willReturnArgument(0);
			$content = (new EmailMessageContent())->build($mail,
				['acctnum' => 0, 'folder' => $folder, 'msgnum' => $uids[0]], (object) ['udate' => 0],
				static fn($part, $inline) => '/attachment/' . $part, static fn($time) => '');
			$item = EmailMessageSerializer::serialize($content['data'], $content['parts']);
			$this->assertCount(1, $item['bodyParts']);
			$this->assertStringContainsString('Draft body survives MIME parsing', $item['bodyParts'][0]['text']);
			$this->assertCount(1, $item['attachments']);
			$this->assertSame('report.txt', $item['attachments'][0]['fileName']);
			$this->assertSame($uids, \ModernIMAPWrapper::imap_search($stream, 'UNSEEN', 1));
		}
		finally
		{
			fclose($file);
			try
			{
				$this->assertTrue(\ModernIMAPWrapper::imap_reopen($stream, $prefix . 'INBOX'));
				if ($created) $this->assertTrue(\ModernIMAPWrapper::imap_deletemailbox($stream, $prefix . $folder));
			}
			finally
			{
				$this->assertTrue(\ModernIMAPWrapper::imap_close($stream));
			}
		}
	}
}