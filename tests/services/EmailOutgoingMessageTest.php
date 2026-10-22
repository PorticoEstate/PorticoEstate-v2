<?php

namespace Tests\Services;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/modules/phpgwapi/inc/class.object_factory.inc.php';
require_once __DIR__ . '/../../src/modules/phpgwapi/inc/class.ofphpgwapi.inc.php';

use App\modules\email\services\EmailAttachmentStorage;
use App\modules\email\services\EmailOutgoingMessage;
use App\modules\phpgwapi\services\Settings;
use PHPUnit\Framework\TestCase;
use PHPMailer\PHPMailer\PHPMailer;

class EmailOutgoingMessageTest extends TestCase
{
	private string $directory;
	private \ReflectionProperty $settingsProperty;
	private mixed $originalSettings;
	private array $originalEnvironment = [];

	protected function setUp(): void
	{
		$this->directory = sys_get_temp_dir() . '/email-outgoing-' . bin2hex(random_bytes(8));
		mkdir($this->directory, 0700);
		$this->settingsProperty = new \ReflectionProperty(Settings::class, 'instance');
		$this->settingsProperty->setAccessible(true);
		$this->originalSettings = $this->settingsProperty->getValue();
		$this->settingsProperty->setValue(null, new class
		{
			public function get(string $key): array { return match ($key) { 'user' => ['fullname' => 'Current User', 'preferences' => ['email' => ['address' => 'default@example.test']]], 'server' => ['hostname' => 'example.test', 'smtp_server' => 'server-smtp.test', 'smtp_port' => 587, 'smtpSecure' => 'tls', 'smtpAuth' => 'yes', 'smtpUser' => 'server-user', 'smtpPassword' => 'server-password'], default => [] }; }
		});
		foreach (['MAIL_TO_DISK', 'MAIL_DISK_PATH', 'REDIRECT_EMAIL', 'SMTP_FROM_OVERRIDE', 'SMTP_HOST', 'SMTP_PORT', 'SMTP_SECURE', 'SMTP_TIMEOUT', 'SMTP_AUTH', 'SMTP_USER', 'SMTP_PASSWORD', 'SMTP_DEBUG'] as $key)
		{
			$this->originalEnvironment[$key] = getenv($key);
			putenv($key);
		}
	}

	protected function tearDown(): void
	{
		foreach ($this->originalEnvironment as $key => $value) $value === false ? putenv($key) : putenv($key . '=' . $value);
		$this->settingsProperty->setValue(null, $this->originalSettings);
		foreach (glob($this->directory . '/*') ?: [] as $path) unlink($path);
		rmdir($this->directory);
	}

	public function testBuildsComposerPhpMailerWithToCcBccSignatureAndAttachments(): void
	{
		$storage = new EmailAttachmentStorage($this->directory);
		$storage->storeContent('file bytes', 'notes.txt', 'text/plain');
		$mail = $this->mail();
		$sender = new EmailOutgoingMessage(static fn() => new PHPMailer(true));
		$mailer = $sender->prepare([
			'to' => '"One" <one@example.test>, two@example.test',
			'cc' => 'copy@example.test',
			'bcc' => 'blind@example.test',
			'subject' => 'Outgoing subject',
			'body' => 'Hello body',
			'action' => 'new',
			'attach_sig' => true,
		], $mail);
		$this->assertInstanceOf(PHPMailer::class, $mailer);
		$this->assertSame('from@example.test', $mailer->From);
		$this->assertCount(2, $mailer->getToAddresses());
		$this->assertCount(1, $mailer->getCcAddresses());
		$this->assertCount(1, $mailer->getBccAddresses());
		$this->assertStringContainsString("-- \r\nTest signature", $mailer->Body);
		$this->assertCount(1, $mailer->getAttachments());
		$this->assertTrue($mailer->preSend());
		$this->assertStringContainsString('Outgoing subject', $mailer->getSentMIMEMessage());
	}

	public function testRedirectAndMailToDiskCaptureAvoidSmtpDelivery(): void
	{
		putenv('MAIL_TO_DISK=1');
		putenv('MAIL_DISK_PATH=' . $this->directory);
		putenv('REDIRECT_EMAIL=capture@example.test');
		putenv('SMTP_FROM_OVERRIDE=relay@example.test');
		$sender = new EmailOutgoingMessage(static fn() => new PHPMailer(true));
		$mailer = $sender->prepare(['to' => 'original@example.test', 'subject' => 'Report', 'body' => 'Body'], $this->mail());
		$result = $sender->send($mailer);
		$this->assertTrue($result['sent']);
		$this->assertSame('relay@example.test', $mailer->From);
		$this->assertSame('capture@example.test', $mailer->getToAddresses()[0][0]);
		$this->assertStringContainsString('[REDIRECTED] Report (Original: original@example.test)', $mailer->Subject);
		$captures = glob($this->directory . '/*.html') ?: [];
		$this->assertCount(1, $captures);
		$this->assertStringContainsString('capture@example.test', file_get_contents($captures[0]));
	}

	public function testDefaultMailerUsesSharedSmtpConfigurationWithEnvironmentOverrides(): void
	{
		putenv('SMTP_HOST=environment-smtp.test');
		putenv('SMTP_PORT=2527');
		putenv('SMTP_AUTH=yes');
		putenv('SMTP_USER=environment-user');
		putenv('SMTP_PASSWORD=environment-password');
		$sender = new EmailOutgoingMessage();
		$mailer = $sender->prepare(['to' => 'recipient@example.test', 'subject' => 'SMTP config', 'body' => 'Body'], $this->mail());
		$this->assertSame('environment-smtp.test', $mailer->Host);
		$this->assertSame(2527, $mailer->Port);
		$this->assertSame('tls', $mailer->SMTPSecure);
		$this->assertTrue($mailer->SMTPAuth);
		$this->assertSame('environment-user', $mailer->Username);
	}

	public function testLegacyMailerFactoryResolvesToMailerSmtp(): void
	{
		$mailer = \phpgwapi_ofphpgwapi::createObject('phpgwapi.mailer_smtp');
		$this->assertInstanceOf(\App\modules\phpgwapi\services\MailerSmtp::class, $mailer);
		$this->assertSame('server-smtp.test', $mailer->Host);
	}

	public function testRejectsInvalidRecipientAddress(): void
	{
		$sender = new EmailOutgoingMessage(static fn() => new PHPMailer(true));
		$this->expectException(\InvalidArgumentException::class);
		$sender->prepare(['to' => 'not an address'], $this->mail());
	}

	private function mail(): object
	{
		return new class($this->directory)
		{
			public string $att_files_dir;
			public string $reply_prefix = '> ';
			public function __construct(string $directory) { $this->att_files_dir = $directory; }
			public function set_acctnum($account): void {}
			public function get_pref_value($key, $account): string { return match ($key) { 'address' => 'from@example.test', 'fullname' => 'From Person', 'email_sig' => 'Test signature', default => '' }; }
			public function html_quotes_decode($value): string { return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
			public function body_hard_wrap($body, $width): string { return $body; }
			public function normalize_crlf($body): string { return str_replace(["\r\n", "\r", "\n"], "\r\n", $body); }
		};
	}
}