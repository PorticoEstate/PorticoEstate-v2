<?php

namespace Tests\Services;

require_once __DIR__ . '/../../vendor/autoload.php';

use App\modules\email\services\EmailComposePrefill;
use PHPUnit\Framework\TestCase;

class EmailComposePrefillTest extends TestCase
{
	private string $directory;

	protected function setUp(): void
	{
		$this->directory = sys_get_temp_dir() . '/email-compose-prefill-' . bin2hex(random_bytes(8));
		mkdir($this->directory, 0700);
	}

	protected function tearDown(): void
	{
		foreach (glob($this->directory . '/*') ?: [] as $path) unlink($path);
		rmdir($this->directory);
	}

	public function testNewMessagePrefillAndSignaturePreference(): void
	{
		$prefill = (new EmailComposePrefill($this->mail()))->build([
			'to' => 'person@example.test', 'personal' => 'Example Person', 'subject' => 'Hello', 'body' => 'Text',
		]);
		$this->assertSame('"Example Person" <person@example.test>', $prefill['to']);
		$this->assertSame('Hello', $prefill['subject']);
		$this->assertSame('Text', $prefill['body']);
		$this->assertTrue($prefill['has_signature']);
		$this->assertTrue($prefill['attach_signature']);
	}

	public function testReplyAndReplyAllUseReplyToAndQuoteTheFirstReadablePart(): void
	{
		$reply = (new EmailComposePrefill($this->mail()))->build($this->context('reply'));
		$this->assertSame('"Reply Person" <reply@example.test>', $reply['to']);
		$this->assertSame('Re: Project update', $reply['subject']);
		$this->assertStringContainsString("> Please review\r\n> \r\n> the updated plan", $reply['body']);

		$replyAll = (new EmailComposePrefill($this->mail()))->build($this->context('replyall'));
		$this->assertStringContainsString('reply@example.test', $replyAll['to']);
		$this->assertStringContainsString('team@example.test', $replyAll['to']);
		$this->assertSame('copy@example.test', $replyAll['cc']);
	}

	public function testEditingDraftRestoresAddressesBodyAndAttachments(): void
	{
		$mail = $this->mail(true);
		$prefill = (new EmailComposePrefill($mail))->build($this->context('edit'));
		$this->assertSame('"Recipient" <to@example.test>', $prefill['to']);
		$this->assertSame('cc@example.test', $prefill['cc']);
		$this->assertSame('hidden@example.test', $prefill['bcc']);
		$this->assertSame('Saved message', $prefill['subject']);
		$this->assertSame('Draft body', $prefill['body']);
		$files = (new \App\modules\email\services\EmailAttachmentStorage($this->directory))->list();
		$this->assertCount(1, $files);
		$this->assertSame('notes.txt', $files[0]['name']);
		$this->assertSame("draft\r\nattachment\0bytes", file_get_contents($this->directory . '/' . $files[0]['id']));
	}

	public function testEditRejectsNonDraftMessage(): void
	{
		$mail = $this->mail();
		$mail->headers->Draft = '';
		$this->expectException(\InvalidArgumentException::class);
		(new EmailComposePrefill($mail))->build($this->context('edit'));
	}

	private function context(string $action): array
	{
		return ['action' => $action, 'msgball' => ['acctnum' => 0, 'folder' => 'Drafts', 'msgnum' => 7]];
	}

	private function mail(bool $draft = false): object
	{
		$address = static fn($mailbox, $personal = '') => (object) ['mailbox' => $mailbox, 'host' => 'example.test', 'personal' => $personal];
		$headers = (object) [
			'from' => [$address('sender', 'Sender')],
			'reply_to' => [$address('reply', 'Reply Person')],
			'to' => [$address($draft ? 'to' : 'team', $draft ? 'Recipient' : '')],
			'cc' => [$address($draft ? 'cc' : 'copy')],
			'bcc' => [$address('hidden')],
			'Subject' => $draft ? 'Saved message' : 'Project update',
			'udate' => 1700000000,
			'Draft' => $draft ? 'DRAFT' : '',
		];
		$parts = [
			['m_description' => 'presentable', 'bytes' => 28, 'm_part_num_mime' => '1', 'encoding' => '7bit', 'subtype' => 'plain', 'type' => 'text', 'ex_attachment' => false],
		];
		if ($draft) $parts[] = ['m_description' => 'attachment', 'bytes' => 16, 'm_part_num_mime' => '2', 'encoding' => '7bit', 'subtype' => 'plain', 'type' => 'text', 'ex_attachment' => true, 'ex_part_name' => 'notes.txt'];
		return new class($headers, $parts, $this->directory, $draft) {
			public string $reply_prefix = '> ';
			public string $att_files_dir;
			public function __construct(public object $headers, public array $parts, string $directory, public bool $draft) { $this->att_files_dir = $directory; }
			public function get_pref_value($name) { return $name === 'email_sig' ? 'Regards' : false; }
			public function get_arg_value($name) { return ['acctnum' => 0, 'folder' => 'Drafts', 'msgnum' => 7]; }
			public function phpgw_header($msgball, $display) { return $this->headers; }
			public function phpgw_fetchstructure($msgball, $display) { return (object) ['parts' => []]; }
			public function get_flat_pgw_struct($structure) { return $this->parts; }
			public function make_rfc2822_address($address, $html = false) { return empty($address->personal) ? $address->mailbox . '@' . $address->host : '"' . $address->personal . '" <' . $address->mailbox . '@' . $address->host . '>'; }
			public function get_subject($headers, $prefix = '') { $subject = (string) $headers->Subject; return $prefix !== '' && stripos($subject, trim($prefix)) !== 0 ? $prefix . $subject : $subject; }
			public function decode_header_string($value) { return $value; }
			public function phpgw_fetchbody($msgball) { return ($msgball['part_no'] ?? '') === '2' ? "draft\r\nattachment\0bytes" : ($this->draft ? 'Draft body' : "Please review\r\nthe updated plan"); }
			public function normalize_crlf($body) { return str_replace(["\r\n", "\r", "\n"], "\r\n", $body); }
			public function body_hard_wrap($body, $width) { return wordwrap($body, $width, "\r\n"); }
			public function qprint($body) { return quoted_printable_decode($body); }
			public function de_base64($body) { return base64_decode($body); }
		};
	}
}