<?php

namespace
{
	if (!function_exists('lang'))
	{
		function lang(string $message): string { return $message; }
	}
}

namespace Tests\Services
{
	require_once __DIR__ . '/../../vendor/autoload.php';
	require_once __DIR__ . '/../../src/modules/email/inc/class.mail_msg_base.inc.php';
	require_once __DIR__ . '/../../src/modules/email/inc/class.mail_msg_wrappers.inc.php';

	use PHPUnit\Framework\TestCase;

	class ConnectionErrorTwig
	{
		public static array $data = [];
		public function __construct($app) {}
		public function render($template, $data): string
		{
			self::$data = $data;
			$twig = new \Twig\Environment(new \Twig\Loader\FilesystemLoader(__DIR__ . '/../../src/modules/email/html/base'));
			return $twig->render('connection-error.twig', $data);
		}
	}

	class ConnectionErrorLinks
	{
		public static function link($path, $args = []): string
		{
			return $path . ($args ? '?' . http_build_query($args) : '');
		}
	}

	class ConnectionErrorMail
	{
		public static int $closed = 0;
		public static bool $released = false;
		public static function hasInstance(): bool { return true; }
		public static function getInstance(): self { return new self(); }
		public function end_request(): void { self::$closed++; }
		public static function releaseInstance(): void { self::$released = true; }
	}

	class EmailConnectionErrorTest extends TestCase
	{
		public function testRecoveryDoesNotRetryOrRenderWhenNotlsWasAlreadySelected(): void
		{
			$mail = $this->getMockBuilder(\mail_msg_wrappers::class)->disableOriginalConstructor()
				->onlyMethods(['get_mailsvr_callstr', 'set_arg_value', 'login_error', 'ensure_stream_and_folder'])->getMock();
			$mail->mail_msg_wrappers = $mail;
			$mail->expects($this->once())->method('set_arg_value')
				->with('beenthere_loginerr_tryagain_buggy_cert', 'beenthere', 3);
			$mail->expects($this->once())->method('get_mailsvr_callstr')
				->with(3)->willReturn('{imap.example.test:143/imap/notls}INBOX');
			$mail->expects($this->never())->method('login_error');
			$mail->expects($this->never())->method('ensure_stream_and_folder');
			$this->expectOutputString('');
			$this->assertFalse($mail->loginerr_tryagain_buggy_cert('test', 'failure', 'imap', 3));
		}

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 * @dataProvider accounts
		 */
		public function testLoginErrorRendersEscapedTemplateAndClosesRequest(int $account, string $url): void
		{
			class_alias(ConnectionErrorTwig::class, \App\modules\phpgwapi\helpers\TwigHelper::class);
			class_alias(ConnectionErrorLinks::class, 'phpgw');
			class_alias(ConnectionErrorMail::class, \App\modules\email\services\MailMessage::class);
			$_SERVER['REQUEST_URI'] = '/portico/email/view/inbox';
			$mail = new class extends \mail_msg_wrappers
			{
				public object $common;
				public function __construct()
				{
					$this->mail_msg_wrappers = $this;
					$this->common = new class
					{
						public int $headers = 0;
						public int $exits = 0;
						public function phpgw_header($force): void { $this->headers++; }
						public function phpgw_exit($flag): void { $this->exits++; }
					};
				}
				public function getCommon(): object { return $this->common; }
				public function get_isset_arg($arg_name = '', $acctnum = '', $extra_keys = '', $display_error = true) { return true; }
			};
			ob_start();
			try
			{
				$this->assertFalse($mail->login_error('<script>caller</script>', $account));
				$html = ob_get_contents();
			}
			finally
			{
				ob_end_clean();
			}
			$this->assertStringContainsString('&lt;script&gt;caller&lt;/script&gt;', $html);
			$this->assertStringNotContainsString('<script>', $html);
			$this->assertSame($url, ConnectionErrorTwig::$data['settingsUrl']);
			$this->assertSame('/home/', ConnectionErrorTwig::$data['homeUrl']);
			$this->assertSame(1, ConnectionErrorMail::$closed);
			$this->assertTrue(ConnectionErrorMail::$released);
			$this->assertSame(1, $mail->common->headers);
			$this->assertSame(1, $mail->common->exits);
		}

		public static function accounts(): array
		{
			return [[0, '/email/view/preferences'], [3, '/email/view/account?ex_acctnum=3']];
		}
	}
}
