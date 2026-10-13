<?php

namespace Tests\Controllers
{
	require_once __DIR__ . '/../../vendor/autoload.php';
	require_once __DIR__ . '/../../src/modules/email/inc/class.msg_bootstrap.inc.php';
	if (!defined('PHPGW_INCLUDE_ROOT')) define('PHPGW_INCLUDE_ROOT', realpath(__DIR__ . '/../../src/modules'));
	require_once __DIR__ . '/../../src/modules/email/inc/class.mail_msg.inc.php';

	use App\modules\email\services\MailMessage;
	use App\modules\phpgwapi\services\Cache;
	use App\modules\phpgwapi\services\Settings;
	use PHPUnit\Framework\TestCase;

	class MailMessageTestDouble extends \mail_msg
	{
		public static bool $failInitialization = false;
		public int $initializations = 0;
		public int $closures = 0;
		public array $requests = [];

		public function initialize_mail_msg()
		{
			$this->initializations++;
			if (self::$failInitialization) throw new \RuntimeException('Initialization failed');
		}

		public function end_request($args_array = '')
		{
			$this->closures++;
		}

		public function get_isset_arg($arg_name = '', $acctnum = '', $extra_keys = '', $display_error = true)
		{
			return $arg_name === 'already_grab_class_args_gpc' && $this->requests !== [];
		}

		public function begin_request($args_array)
		{
			$this->requests[] = $args_array;
			return true;
		}
	}

	class EmailMsgBootstrapTest extends TestCase
	{
		private function mailDouble(): MailMessageTestDouble
		{
			$mail = MailMessageTestDouble::getInstance();
			if (!$mail instanceof MailMessageTestDouble) throw new \LogicException('Unexpected mail test instance');
			return $mail;
		}

		public function testEmailModuleHasNoExecutableGlobalMailObjectReferences(): void
		{
			$violations = [];
			$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../src/modules/email'));
			foreach ($files as $file)
			{
				if (!$file->isFile() || $file->getExtension() !== 'php') continue;
				$tokens = token_get_all(file_get_contents($file->getPathname()));
				foreach ($tokens as $index => $token)
				{
					if (!is_array($token) || $token[0] !== T_VARIABLE || $token[1] !== '$GLOBALS') continue;
					$expression = implode('', array_map(static fn($item): string => is_array($item) ? $item[1] : $item, array_slice($tokens, $index, 12)));
					$coreFile = in_array($file->getFilename(), ['class.mail_msg_base.inc.php', 'class.mail_msg_wrappers.inc.php', 'class.mail_msg_display.inc.php'], true);
					if (preg_match('~^\$GLOBALS\[\s*[\'\"]phpgw[\'\"]\s*\]->(?:msg|mail_send|dcom|msg_bootstrap|uicompose|widgets|htmlwid)\b|^\$GLOBALS\[\s*[\'\"]phpgw_dcom_~', $expression)
						|| ($coreFile && preg_match('~^\$GLOBALS\[\s*[\'\"]phpgw[\'\"]\s*\]->~', $expression)))
					{
						$violations[] = $file->getFilename() . ':' . $token[2];
					}
				}
			}
			$this->assertSame([], $violations);
		}

		protected function tearDown(): void
		{
			MailMessageTestDouble::$failInitialization = false;
			MailMessage::releaseInstance();
		}

		public function testModernServiceExposesWrappersWithoutCreatingAnInstance(): void
		{
			$this->assertTrue(is_subclass_of(MailMessage::class, \mail_msg_wrappers::class));
			$this->assertFalse(MailMessage::hasInstance());
			$this->assertTrue(method_exists(MailMessage::class, 'phpgw_fetchbody'));
		}

		public function testSharedInstanceIsInitializedOnce(): void
		{
			$mail = $this->mailDouble();
			$this->assertSame($mail, MailMessage::getInstance());
			$this->assertSame($mail, MailMessageTestDouble::getInstance());
			$this->assertSame(1, $mail->initializations);
			$this->assertTrue(MailMessage::hasInstance());
		}

		public function testReleaseClosesAndClearsTheInstanceOnlyOnce(): void
		{
			$mail = $this->mailDouble();
			$mail->setTransport(0, new \stdClass());
			MailMessage::releaseInstance();
			MailMessage::releaseInstance();
			$this->assertSame(1, $mail->closures);
			$this->assertFalse($mail->hasTransport(0));
			$this->assertFalse(MailMessage::hasInstance());
			$this->assertNotSame($mail, MailMessageTestDouble::getInstance());
		}

		public function testFailedInitializationDoesNotLeaveASharedInstance(): void
		{
			MailMessageTestDouble::$failInitialization = true;
			try
			{
				MailMessageTestDouble::getInstance();
				$this->fail('Initialization should fail');
			}
			catch (\RuntimeException $exception)
			{
				$this->assertSame('Initialization failed', $exception->getMessage());
			}
			$this->assertFalse(MailMessage::hasInstance());
			MailMessageTestDouble::$failInitialization = false;
			$this->assertInstanceOf(MailMessageTestDouble::class, MailMessageTestDouble::getInstance());
		}

		public function testTransportsAreOwnedPerAccount(): void
		{
			$mail = $this->mailDouble();
			$defaultTransport = new \stdClass();
			$externalTransport = new \stdClass();
			$mail->setTransport(0, $defaultTransport);
			$mail->setTransport(3, $externalTransport);
			$this->assertSame($defaultTransport, $mail->getTransport(0));
			$this->assertSame($externalTransport, $mail->getTransport(3));
			$this->assertFalse($mail->hasTransport(4));
			$this->expectException(\LogicException::class);
			$mail->getTransport(4);
		}

		public function testBootstrapStartsTheSharedRequestOnlyOnce(): void
		{
			$mail = $this->mailDouble();
			$bootstrap = new \email_msg_bootstrap();
			$bootstrap->set_do_login(false);
			$bootstrap->ensure_mail_msg_exists('unit-test');
			$bootstrap->ensure_mail_msg_exists('unit-test');
			$this->assertSame([['do_login' => false, 'do_login_ex' => \email_msg_bootstrap::LOGIN_NEVER]], $mail->requests);
			$this->assertSame(1, $mail->initializations);
		}

		public function testBodyFetchUsesTheOwnedAccountTransport(): void
		{
			$mail = $this->getMockBuilder(\mail_msg::class)
				->onlyMethods(['read_session_cache_item', 'ensure_stream_and_folder', 'get_arg_value', 'event_msg_seen', 'save_session_cache_item'])
				->getMock();
			$msgball = ['acctnum' => 3, 'folder' => 'INBOX', 'msgnum' => 12, 'part_no' => '1.2'];
			$transport = $this->getMockBuilder(\stdClass::class)->addMethods(['fetchbody'])->getMock();
			$transport->expects($this->once())->method('fetchbody')->with('stream-3', 12, '1.2', '')->willReturn('body');
			$mail->setTransport(3, $transport);
			$mail->method('read_session_cache_item')->willReturn(false);
			$mail->expects($this->once())->method('ensure_stream_and_folder');
			$mail->expects($this->once())->method('get_arg_value')->with('mailsvr_stream', 3)->willReturn('stream-3');
			$mail->expects($this->once())->method('event_msg_seen')->with($msgball, 'phpgw_fetchbody');
			$mail->expects($this->once())->method('save_session_cache_item')->with('phpgw_fetchbody', ['msgball' => $msgball, 'phpgw_fetchbody' => 'body'], 3);
			$this->assertSame('body', $mail->phpgw_fetchbody($msgball));
		}

		public function testInitializationPreservesTypedStorageAndMissingTableFallback(): void
		{
			require_once __DIR__ . '/../../src/modules/email/inc/class.so_mail_msg.inc.php';
			require_once __DIR__ . '/../../src/modules/email/inc/class.svc_debug.inc.php';
			$mail = new \mail_msg();
			$this->assertNull($mail->so);
			$storage = $this->getMockBuilder(\so_mail_msg::class)
				->disableOriginalConstructor()
				->onlyMethods(['so_am_table_exists', 'so_prop_use_group_data'])
				->getMock();
			$storage->expects($this->once())->method('so_am_table_exists')->willReturn(false);
			$storage->expects($this->once())->method('so_prop_use_group_data')->with(false);
			$mail->so = $storage;
			$mail->dbug = new \svc_debug();
			$originalGet = $_GET;
			$_GET = [];
			try
			{
				$mail->initialize_mail_msg();
				$mail->initialize_mail_msg();
				$this->assertSame($storage, $mail->so);
				$this->assertFalse($mail->use_private_table);
				$this->assertTrue($mail->been_constructed);
			}
			finally
			{
				$_GET = $originalGet;
			}
		}

		public function testMoveDebuggingUsesTheTypedLogger(): void
		{
			require_once __DIR__ . '/../../src/modules/email/inc/class.svc_debug.inc.php';
			$mail = $this->getMockBuilder(\mail_msg::class)->onlyMethods(['buffer_move_commands', 'track_expungable_folders'])->getMock();
			$this->assertNull($mail->dbug);
			$logger = $this->getMockBuilder(\svc_debug::class)->onlyMethods(['out'])->getMock();
			$logger->expects($this->exactly(2))->method('out');
			$mail->dbug = $logger;
			$mail->debug_wrapper_dcom_calls = 1;
			$msgball = ['acctnum' => 3, 'folder' => 'INBOX', 'msgnum' => 12];
			$target = ['acctnum' => 3, 'folder' => 'Archive'];
			$mail->expects($this->once())->method('buffer_move_commands')->with($msgball, $target);
			$mail->expects($this->once())->method('track_expungable_folders')->with($msgball);
			$this->assertTrue($mail->industrial_interacct_mail_move($msgball, $target));
		}

		public function testEndRequestClosesTheOwnedAccountStream(): void
		{
			$mail = $this->getMockBuilder(\mail_msg::class)->onlyMethods(['get_isset_arg', 'get_arg_value', 'set_arg_value'])->getMock();
			$mail->extra_and_default_acounts = [['acctnum' => 3, 'status' => 'enabled']];
			$transport = $this->getMockBuilder(\stdClass::class)->addMethods(['close'])->getMock();
			$transport->expects($this->once())->method('close')->with('stream-3');
			$mail->setTransport(3, $transport);
			$mail->method('get_isset_arg')->willReturn(true);
			$mail->method('get_arg_value')->willReturn('stream-3');
			$mail->expects($this->once())->method('set_arg_value')->with('mailsvr_stream', '', 3);
			$mail->end_request();
		}

		public function testAppSessionAdapterRoundTripsModernCacheData(): void
		{
			$property = new \ReflectionProperty(Settings::class, 'instance');
			$property->setAccessible(true);
			$original = $property->getValue();
			$property->setValue(null, new class
			{
				public function get(string $key): array
				{
					return $key === 'server' ? ['install_id' => 'email-test', 'encryptkey' => '', 'mcrypt_iv' => ''] : [];
				}
			});
			try
			{
				$mail = new \mail_msg();
				$method = new \ReflectionMethod(\mail_msg_wrappers::class, 'appSession');
				$method->setAccessible(true);
				$data = ['msgball' => ['acctnum' => 3, 'folder' => 'INBOX'], 'body' => 'cached body'];
				$this->assertTrue($method->invoke($mail, 'mail-session-test', 'email', $data));
				$this->assertSame($data, $method->invoke($mail, 'mail-session-test', 'email'));
				$this->assertTrue($method->invoke($mail, 'mail-session-test', 'email', ''));
				$this->assertSame('', $method->invoke($mail, 'mail-session-test', 'email'));
			}
			finally
			{
				Cache::session_clear('email', 'mail-session-test');
				$property->setValue(null, $original);
			}
		}

		public function testIntegerLoginModesArePreserved(): void
		{
			$bootstrap = new \email_msg_bootstrap();

			$this->assertSame(\email_msg_bootstrap::LOGIN_NEVER, $bootstrap->set_do_login(\email_msg_bootstrap::LOGIN_NEVER));
			$this->assertFalse($bootstrap->get_do_login());
			$this->assertSame(\email_msg_bootstrap::LOGIN_ONLY_IF_NEEDED, $bootstrap->set_do_login(\email_msg_bootstrap::LOGIN_ONLY_IF_NEEDED));
			$this->assertTrue($bootstrap->get_do_login());
			$this->assertSame(\email_msg_bootstrap::LOGIN_YES, $bootstrap->set_do_login(\email_msg_bootstrap::LOGIN_YES));
			$this->assertTrue($bootstrap->get_do_login());
		}

		public function testBooleanLoginModeCompatibilityIsPreserved(): void
		{
			$bootstrap = new \email_msg_bootstrap();

			$this->assertFalse($bootstrap->set_do_login(false));
			$this->assertSame(\email_msg_bootstrap::LOGIN_NEVER, $bootstrap->get_do_login_ex());
			$this->assertTrue($bootstrap->set_do_login(true));
			$this->assertSame(\email_msg_bootstrap::LOGIN_ONLY_IF_NEEDED, $bootstrap->get_do_login_ex());
		}
	}
}