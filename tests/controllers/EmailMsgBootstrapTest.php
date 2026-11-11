<?php

namespace
{
	if (!function_exists('lang'))
	{
		function lang(string $message): string
		{
			return $message;
		}
	}
}

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
		public static bool $failRequest = false;
		public int $initializations = 0;
		public int $closures = 0;
		public bool $wrapperReadyDuringInitialization = false;
		public array $requests = [];

		public function initialize_mail_msg()
		{
			$this->initializations++;
			$this->wrapperReadyDuringInitialization = $this->mail_msg_wrappers === $this;
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
			return self::$failRequest ? false : true;
		}
	}

	class EmailMsgBootstrapTest extends TestCase
	{
		private mixed $originalSettings;

		protected function setUp(): void
		{
			$property = new \ReflectionProperty(Settings::class, 'instance');
			$property->setAccessible(true);
			$this->originalSettings = $property->getValue();
			$property->setValue(null, new class
			{
				public function get(string $key): array
				{
					return $key === 'server' ? ['install_id' => 'email-test', 'encryptkey' => '', 'mcrypt_iv' => ''] : [];
				}
			});
		}

		private function mailDouble(): MailMessageTestDouble
		{
			$mail = MailMessageTestDouble::getInstance();
			if (!$mail instanceof MailMessageTestDouble) throw new \LogicException('Unexpected mail test instance');
			return $mail;
		}

		private function withSessionCache(callable $check): void
		{
			$property = new \ReflectionProperty(Settings::class, 'instance');
			$property->setAccessible(true);
			$originalSettings = $property->getValue();
			$property->setValue(null, new class
			{
				public function get(string $key): array
				{
					return $key === 'server' ? ['install_id' => 'email-cache-test', 'encryptkey' => '', 'mcrypt_iv' => ''] : [];
				}
			});
			$originalData = [];
			foreach (['mail_state', 'mail_keys', 'test-mail-storage', '0;cached_prefs'] as $key)
			{
				$originalData[$key] = Cache::session_get('email', $key);
				Cache::session_set('email', $key, null);
			}
			try
			{
				$this->mailDouble();
				$check();
			}
			finally
			{
				foreach ($originalData as $key => $value) Cache::session_set('email', $key, $value);
				$property->setValue(null, $originalSettings);
			}
		}

		public function testEndRequestClosesObjectResourceAndStringHandles(): void
		{
			$resource = tmpfile();
			try
			{
				foreach ([new \stdClass(), $resource, 'imap_test_connection'] as $handle)
				{
					foreach ([[], ['acctnum' => 2]] as $arguments)
					{
						$mail = $this->getMockBuilder(\mail_msg::class)->disableOriginalConstructor()
							->onlyMethods(['get_isset_arg', 'get_arg_value', 'set_arg_value', 'getTransport'])->getMock();
						$mail->mail_msg_wrappers = $mail;
						$mail->extra_and_default_acounts = [['acctnum' => 2, 'status' => 'enabled']];
						$mail->method('get_isset_arg')->with('mailsvr_stream', 2)->willReturn(true);
						$mail->method('get_arg_value')->with('mailsvr_stream', 2)->willReturn($handle);
						$transport = $this->getMockBuilder(\stdClass::class)->addMethods(['close'])->getMock();
						$transport->expects($this->once())->method('close')->with($this->identicalTo($handle));
						$mail->method('getTransport')->with(2)->willReturn($transport);
						$mail->expects($this->once())->method('set_arg_value')->with('mailsvr_stream', '', 2);
						$mail->end_request($arguments);
					}
				}
			}
			finally
			{
				if (is_resource($resource)) fclose($resource);
			}
		}

		public function testEnsureStreamAndFolderReusesObjectHandle(): void
		{
			$handle = new \stdClass();
			$mail = $this->getMockBuilder(\mail_msg::class)->disableOriginalConstructor()
				->onlyMethods(['get_isset_arg', 'get_arg_value', 'getTransport', 'prep_folder_out'])->getMock();
			$mail->mail_msg_wrappers = $mail;
			$mail->method('get_isset_arg')->willReturn(true);
			$mail->method('get_arg_value')->willReturnCallback(static fn($name) => match ($name) {
				'mailsvr_stream' => $handle,
				'folder' => 'INBOX',
				'mailsvr_callstr' => '{pop-test:3995/pop3/ssl}INBOX',
				default => '',
			});
			$mail->method('prep_folder_out')->willReturnArgument(0);
			$mail->expects($this->never())->method('getTransport');
			$this->assertSame($handle, $mail->ensure_stream_and_folder(['acctnum' => 2, 'folder' => 'INBOX']));
		}

		public function testInboxDisplayValidatesOrderingEvenWhenMessageListIsCached(): void
		{
			$mail = $this->getMockBuilder(\mail_msg::class)->disableOriginalConstructor()
				->onlyMethods(['get_msgball_list'])->getMock();
			$cache = new \ReflectionProperty(\mail_msg_wrappers::class, 'sessionCache');
			$cache->setValue($mail, [2 => ['msgball_list' => ['INBOX' => ['msgball_list' => [
				['msgball[acctnum]' => 2, 'msgball[folder]' => 'INBOX', 'msgball[msgnum]' => 1],
			]]]]]);
			$mail->expects($this->once())->method('get_msgball_list')->with(2, 'INBOX')
				->willThrowException(new \RuntimeException('Sort-aware cache validation requested'));
			$this->expectExceptionMessage('Sort-aware cache validation requested');
			$mail->get_msg_list_display(['number_all' => 1, 'fldball' => ['acctnum' => 2, 'folder' => 'INBOX']]);
		}

		public function testModuleHasNoLegacyCacheBackend(): void
		{
			$violations = [];
			$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../src/modules/email'));
			foreach ($files as $file)
			{
				if (!$file->isFile() || $file->getExtension() !== 'php' || str_contains($file->getPathname(), '/setup/')) continue;
				foreach (token_get_all(file_get_contents($file->getPathname())) as $token)
				{
					if (!is_array($token)) continue;
					if (($token[0] === T_STRING && in_array($token[1], ['appsession', 'ref_SESSION'], true))
						|| ($token[0] === T_VARIABLE && in_array($token[1], ['$_SESSION', '$ref_SESSION'], true))
						|| ($token[0] === T_CONSTANT_ENCAPSED_STRING && preg_match('/(?:SELECT|INSERT|UPDATE|DELETE).*phpgw_(?:anglemail|app_sessions)/is', $token[1])))
					{
						$violations[] = $file->getFilename() . ':' . $token[2];
					}
				}
			}
			$this->assertSame([], $violations);
		}

		public function testStructuredPreferencesUpdateUserAndEffectiveValues(): void
		{
			$preferences = (new \ReflectionClass(\App\modules\phpgwapi\services\Preferences::class))->newInstanceWithoutConstructor();
			$preferences->user = ['email' => []];
			$preferences->default = ['email' => ['default_tree' => ['retained' => 'default']]];
			$preferences->forced = [];
			$preferences->data = $preferences->default;

			$preferences->add_struct('email', '["filters"][2]["filtername"]', 'Second');
			$preferences->add_struct('email', '["filters"][1]["filtername"]', 'First');
			$preferences->sort_struct('email', '["filters"]');
			$this->assertSame([1, 2], array_keys($preferences->user['email']['filters']));
			$this->assertSame($preferences->user['email']['filters'], $preferences->data['email']['filters']);

			$preferences->delete_struct('email', '["filters"][1]["filtername"]');
			$this->assertSame([2], array_keys($preferences->user['email']['filters']));
			$preferences->delete_struct('email', '["filters"]');
			$this->assertArrayNotHasKey('filters', $preferences->user['email']);
			$this->assertSame(['retained' => 'default'], $preferences->data['email']['default_tree']);
		}

		public function testMailCachePersistsAcrossInstancesAndIsolatesAccounts(): void
		{
			$this->withSessionCache(function (): void
			{
				$mail = new \mail_msg();
				$cache =& $mail->getSessionCache();
				$cache[0]['mailsvr_callstr'] = 'default-account';
				$cache[3]['mailsvr_callstr'] = 'external-account';
				$this->assertTrue($mail->persistSessionCache());
				$next = new \mail_msg();
				$this->assertSame($cache, $next->getSessionCache());
				$this->assertSame($cache, Cache::session_get('email', 'mail_state'));
			});
		}

		public function testBodyCachePersistsAndExpiresOnlyTheSelectedPart(): void
		{
			$this->withSessionCache(function (): void
			{
				$mail = new \mail_msg();
				$mail->session_cache_extreme = true;
				$msgball = ['acctnum' => 3, 'folder' => 'INBOX', 'msgnum' => 12, 'part_no' => '1.2'];
				$this->assertTrue($mail->save_session_cache_item('phpgw_fetchbody', ['msgball' => $msgball, 'phpgw_fetchbody' => 'first'], 3));
				$msgball['part_no'] = '1.3';
				$this->assertTrue($mail->save_session_cache_item('phpgw_fetchbody', ['msgball' => $msgball, 'phpgw_fetchbody' => 'second'], 3));
				$next = new \mail_msg();
				$next->session_cache_extreme = true;
				$this->assertSame('first', $next->read_session_cache_item('phpgw_fetchbody', 3, 'INBOX', 12, '1.2'));
				$this->assertTrue($next->expire_session_cache_item('phpgw_fetchbody', 3, 'INBOX', 12, '1.2'));
				$afterExpiry = new \mail_msg();
				$afterExpiry->session_cache_extreme = true;
				$this->assertFalse($afterExpiry->read_session_cache_item('phpgw_fetchbody', 3, 'INBOX', 12, '1.2'));
				$this->assertSame('second', $afterExpiry->read_session_cache_item('phpgw_fetchbody', 3, 'INBOX', 12, '1.3'));
			});
		}

		public function testBulkExpiryRetainsAccountMetadataAndDropsMessageBodies(): void
		{
			$this->withSessionCache(function (): void
			{
				$mail = new \mail_msg();
				$cache =& $mail->getSessionCache();
				$cache[3] = ['mailsvr_callstr' => 'server', 'folder_list' => [['folder_long' => 'INBOX']], 'mailsvr_namespace' => 'INBOX', 'phpgw_fetchbody' => ['stale']];
				$mail->extra_and_default_acounts = [['acctnum' => 3, 'status' => 'enabled']];
				$mail->batch_expire_cached_items('unit-test');
				$next = new \mail_msg();
				$this->assertSame(['mailsvr_callstr' => 'server', 'folder_list' => [['folder_long' => 'INBOX']], 'mailsvr_namespace' => 'INBOX'], $next->getSessionCache()[3]);
			});
		}

		public function testDisabledCacheDoesNotReadOrWriteTheBackend(): void
		{
			$this->withSessionCache(function (): void
			{
				Cache::session_set('email', 'mail_state', [3 => ['mailsvr_callstr' => 'existing']]);
				$mail = new \mail_msg();
				$mail->session_cache_enabled = false;
				$this->assertSame([], $mail->getSessionCache());
				$this->assertFalse($mail->persistSessionCache());
				$this->assertSame([3 => ['mailsvr_callstr' => 'existing']], Cache::session_get('email', 'mail_state'));
			});
		}

		public function testForcedFolderRefreshBypassesCachedFolders(): void
		{
			$cached = [['folder_long' => 'INBOX', 'folder_short' => 'INBOX', 'acctnum' => 0]];
			$mail = $this->getMockBuilder(\mail_msg::class)->onlyMethods([
				'_direct_access_arg_value', 'get_pref_value', 'get_arg_value',
				'read_session_cache_item', 'save_session_cache_item', 'phpgw_listmailbox',
				'is_imap_folder', 'get_folder_short',
			])->getMock();
			$mail->method('_direct_access_arg_value')->willReturn($cached);
			$mail->method('get_pref_value')->willReturn('imap');
			$mail->method('get_arg_value')->willReturnMap([
				['mailsvr_stream', 0, '', true, 'test-stream'],
				['mailsvr_callstr', 0, '', true, '{test}'],
				['mailsvr_namespace', 0, '', true, 'INBOX'],
				['mailsvr_delimiter', 0, '', true, '.'],
			]);
			$mail->expects($this->never())->method('read_session_cache_item');
			$mail->expects($this->once())->method('phpgw_listmailbox')
				->with('{test}', '*', 0, false)->willReturn(['{test}INBOX', '{test}INBOX.New child']);
			$mail->method('is_imap_folder')->willReturn(true);
			$mail->method('get_folder_short')->willReturnCallback(static fn($folder): string => str_replace('{test}', '', $folder));
			$mail->expects($this->once())->method('save_session_cache_item')->with('folder_list', $this->callback(
				static fn($folders): bool => in_array('INBOX.New child', array_column($folders, 'folder_long'), true)
			), 0);

			$this->assertSame($cached, $mail->get_folder_list(0));
			$this->assertSame(['INBOX', 'INBOX.New child'], array_column($mail->get_folder_list(0, true), 'folder_long'));
		}

		public function testStorageCompatibilityUsesTheStandardCacheWithoutSQL(): void
		{
			$this->withSessionCache(function (): void
			{
				require_once __DIR__ . '/../../src/modules/email/inc/class.so_mail_msg.inc.php';
				$storage = new \so_mail_msg();
				$data = ['body' => 'cached', 'count' => 0];
				$this->assertTrue($storage->so_set_data('test-mail-storage', $data, true));
				$this->assertSame($data, Cache::session_get('email', 'test-mail-storage'));
				$this->assertSame($data, $storage->so_get_data('test-mail-storage', true));
				$this->assertTrue($storage->so_appsession_passthru('0;cached_prefs', $data, true));
				$this->assertSame($data, $storage->so_appsession_passthru('0;cached_prefs', '##NOTHING##', true));
				$this->assertFalse($storage->so_am_table_exists());
				$storage->so_clear_all_data_this_user();
				$this->assertNull(Cache::session_get('email', 'test-mail-storage'));
				$this->assertNull(Cache::session_get('email', '0;cached_prefs'));
			});
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

		public function testEmailModuleUsesPreferencesServiceInsteadOfGlobalPreferencesObject(): void
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
					if (preg_match('~^\$GLOBALS\[\s*[\'\"]phpgw[\'\"]\s*\]->preferences\b~', $expression))
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
			MailMessageTestDouble::$failRequest = false;
			MailMessage::releaseInstance();
			$property = new \ReflectionProperty(Settings::class, 'instance');
			$property->setAccessible(true);
			$property->setValue(null, $this->originalSettings);
		}

		public function testConstructorsWireTheOwnedWrapperWithoutFullInitialization(): void
		{
			foreach ([\mail_msg_wrappers::class, \mail_msg::class, MailMessage::class] as $class)
			{
				$mail = new $class();
				$this->assertSame($mail, $mail->mail_msg_wrappers);
				$this->assertSame(Settings::getInstance()->get('server'), $mail->serverSettings);
				$this->assertFalse($mail->mail_msg_wrappers->get_isset_arg('already_grab_class_args_gpc'));
				$this->assertFalse($mail->been_constructed);
				$this->assertNull($mail->so);
				$this->assertFalse(MailMessage::hasInstance());
			}
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
			$this->assertTrue($mail->wrapperReadyDuringInitialization);
			$this->assertSame($mail, $mail->mail_msg_wrappers);
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
			$this->assertSame([['do_login' => false, 'do_login_ex' => \email_msg_bootstrap::LOGIN_NEVER, 'api_request' => false]], $mail->requests);
			$this->assertSame(1, $mail->initializations);
		}

		public function testBootstrapDebugLevelIsNotPassedAsAnAccount(): void
		{
			$mail = $this->mailDouble();
			$bootstrap = new \email_msg_bootstrap();
			$bootstrap->set_do_login(false);
			$bootstrap->ensure_mail_msg_exists('unit-test', 3);
			$this->assertSame(3, $bootstrap->debug_level);
			$this->assertSame([[
				'do_login' => false,
				'do_login_ex' => \email_msg_bootstrap::LOGIN_NEVER,
				'api_request' => false,
			]], $mail->requests);
		}

		public function testApiMissingCredentialsBecomeStructuredConfigurationException(): void
		{
			$originalRequestUri = $_SERVER['REQUEST_URI'] ?? null;
			$_SERVER['REQUEST_URI'] = '/portico/email/api/search';
			$mail = $this->mailDouble();
			MailMessageTestDouble::$failRequest = true;
			try
			{
				$bootstrap = new \email_msg_bootstrap();
				try
				{
					$bootstrap->ensure_mail_msg_exists('email.api.search');
					$this->fail('The API bootstrap should report missing email credentials');
				}
				catch (\App\modules\email\services\EmailMailConfigurationException $exception)
				{
					$this->assertStringContainsString('username and password', $exception->getMessage());
				}
				$this->assertTrue($mail->requests[0]['api_request']);
			}
			finally
			{
				if ($originalRequestUri === null) unset($_SERVER['REQUEST_URI']);
				else $_SERVER['REQUEST_URI'] = $originalRequestUri;
			}
		}

		/** @dataProvider imapConnectionFailures */
		public function testApiReportsSafeImapConnectionFailure(string $transportError, string $expectedReason): void
		{
			$originalRequestUri = $_SERVER['REQUEST_URI'] ?? null;
			$_SERVER['REQUEST_URI'] = '/portico/email/api/inbox';
			$mail = $this->mailDouble();
			$transport = $this->getMockBuilder(\stdClass::class)->addMethods(['server_last_error'])->getMock();
			$transport->method('server_last_error')->willReturn($transportError);
			$mail->setTransport(0, $transport);
			MailMessageTestDouble::$failRequest = true;
			try
			{
				$bootstrap = new \email_msg_bootstrap();
				$bootstrap->ensure_mail_msg_exists('email.api.inbox');
				$this->fail('The API bootstrap should report the connection failure');
			}
			catch (\App\modules\email\services\EmailMailConfigurationException $exception)
			{
				$this->assertStringContainsString($expectedReason, $exception->getMessage());
				$this->assertStringNotContainsString('private-token', $exception->getMessage());
				$this->assertStringNotContainsString('Configure your email account username', $exception->getMessage());
			}
			finally
			{
				if ($originalRequestUri === null) unset($_SERVER['REQUEST_URI']);
				else $_SERVER['REQUEST_URI'] = $originalRequestUri;
			}
		}

		public function imapConnectionFailures(): array
		{
			return [
				'modern connection' => ['connection setup failed', 'Unable to reach the IMAP server'],
				'refused' => ['Connection refused private-token', 'Unable to reach the IMAP server'],
				'timeout' => ['Connection timed out private-token', 'Unable to reach the IMAP server'],
				'dns' => ['php_network_getaddresses: getaddrinfo failed private-token', 'Unable to reach the IMAP server'],
				'authentication' => ['AUTHENTICATIONFAILED private-token', 'rejected authentication'],
				'tls' => ['SSL certificate verification failed private-token', 'secure connection'],
				'unknown' => ['Unexpected failure private-token', 'Unable to open the IMAP mailbox'],
			];
		}

		public function testApiLoginErrorBypassesLegacyRetryAndRendering(): void
		{
			$originalRequestUri = $_SERVER['REQUEST_URI'] ?? null;
			$_SERVER['REQUEST_URI'] = '/email/api/message';
			$mail = $this->mailDouble();
			$transport = $this->getMockBuilder(\stdClass::class)->addMethods(['server_last_error'])->getMock();
			$transport->method('server_last_error')->willReturn('Connection refused');
			$mail->setTransport(0, $transport);
			try
			{
				$this->expectException(\App\modules\email\services\EmailMailConfigurationException::class);
				$this->expectExceptionMessage('Unable to reach the IMAP server');
				$mail->login_error('test', 0);
			}
			finally
			{
				if ($originalRequestUri === null) unset($_SERVER['REQUEST_URI']);
				else $_SERVER['REQUEST_URI'] = $originalRequestUri;
			}
		}

		public function testBodyFetchUsesTheOwnedAccountTransport(): void
		{
			$mail = $this->getMockBuilder(\mail_msg::class)
				->onlyMethods(['read_session_cache_item', 'ensure_stream_and_folder', 'get_arg_value', 'event_msg_seen', 'save_session_cache_item'])
				->getMock();
			$msgball = ['acctnum' => 3, 'folder' => 'INBOX', 'msgnum' => 12, 'part_no' => '1.2'];
			$transport = $this->getMockBuilder(\stdClass::class)->addMethods(['fetchbody'])->getMock();
			$transport->expects($this->once())->method('fetchbody')->with('stream-3', 12, '1.2', 0)->willReturn('body');
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
			$mail = new \mail_msg();
			$this->assertNull($mail->so);
			$storage = $this->getMockBuilder(\so_mail_msg::class)
				->disableOriginalConstructor()
				->onlyMethods(['so_am_table_exists', 'so_prop_use_group_data'])
				->getMock();
			$storage->expects($this->once())->method('so_am_table_exists')->willReturn(false);
			$storage->expects($this->once())->method('so_prop_use_group_data')->with(false);
			$mail->so = $storage;
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

		public function testInitializationEnablesGroupedReadsForApiInboxRoute(): void
		{
			require_once __DIR__ . '/../../src/modules/email/inc/class.so_mail_msg.inc.php';
			$mail = new \mail_msg();
			$storage = $this->getMockBuilder(\so_mail_msg::class)
				->disableOriginalConstructor()
				->onlyMethods(['so_am_table_exists', 'so_prop_use_group_data'])
				->getMock();
			$storage->method('so_am_table_exists')->willReturn(false);
			$storage->expects($this->once())->method('so_prop_use_group_data')->with(true);
			$mail->so = $storage;
			$originalGet = $_GET;
			$originalRequestUri = $_SERVER['REQUEST_URI'] ?? null;
			$_GET = [];
			$_SERVER['REQUEST_URI'] = '/portico/email/api/inbox';
			try
			{
				$mail->initialize_mail_msg();
			}
			finally
			{
				$_GET = $originalGet;
				if ($originalRequestUri === null) unset($_SERVER['REQUEST_URI']);
				else $_SERVER['REQUEST_URI'] = $originalRequestUri;
			}
		}

		public function testInitializationDisablesCachedPreferencesForTwigRoute(): void
		{
			require_once __DIR__ . '/../../src/modules/email/inc/class.so_mail_msg.inc.php';
			$mail = new \mail_msg();
			$mail->use_cached_prefs = true;
			$storage = $this->getMockBuilder(\so_mail_msg::class)
				->disableOriginalConstructor()
				->onlyMethods(['so_am_table_exists', 'so_prop_use_group_data'])
				->getMock();
			$storage->method('so_am_table_exists')->willReturn(false);
			$storage->expects($this->once())->method('so_prop_use_group_data')->with(false);
			$mail->so = $storage;
			$originalGet = $_GET;
			$originalRequestUri = $_SERVER['REQUEST_URI'] ?? null;
			$_GET = [];
			$_SERVER['REQUEST_URI'] = '/portico/email/view/preferences';
			try
			{
				$mail->initialize_mail_msg();
				$this->assertFalse($mail->use_cached_prefs);
			}
			finally
			{
				$_GET = $originalGet;
				if ($originalRequestUri === null) unset($_SERVER['REQUEST_URI']);
				else $_SERVER['REQUEST_URI'] = $originalRequestUri;
			}
		}

		public function testMoveDoesNotEmitVerboseDebugOutput(): void
		{
			$mail = $this->getMockBuilder(\mail_msg::class)->onlyMethods(['buffer_move_commands', 'track_expungable_folders'])->getMock();
			$this->expectOutputString('');
			$mail->debug_wrapper_dcom_calls = 1;
			$msgball = ['acctnum' => 3, 'folder' => 'INBOX', 'msgnum' => 12];
			$target = ['acctnum' => 3, 'folder' => 'Archive'];
			$mail->expects($this->once())->method('buffer_move_commands')->with($msgball, $target);
			$mail->expects($this->once())->method('track_expungable_folders')->with($msgball);
			$this->assertTrue($mail->industrial_interacct_mail_move($msgball, $target));
		}

		public function testEmailModuleDoesNotDumpDataOrLogRawExceptionMessages(): void
		{
			$parser = (new \PhpParser\ParserFactory())->createForHostVersion();
			$finder = new \PhpParser\NodeFinder();
			$violations = [];
			$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../src/modules/email'));
			foreach ($files as $file)
			{
				if (!$file->isFile() || $file->getExtension() !== 'php') continue;
				$nodes = $parser->parse(file_get_contents($file->getPathname()));
				foreach ($finder->findInstanceOf($nodes, \PhpParser\Node\Expr\PropertyFetch::class) as $fetch)
				{
					if ($fetch->name instanceof \PhpParser\Node\Identifier && $fetch->name->name === 'dbug')
					{
						$violations[] = $file->getFilename() . ':' . $fetch->getStartLine() . ' legacy debug service';
					}
				}
				foreach ($finder->findInstanceOf($nodes, \PhpParser\Node\Expr\FuncCall::class) as $call)
				{
					if (!$call->name instanceof \PhpParser\Node\Name) continue;
					$name = strtolower($call->name->toString());
					if (in_array($name, ['var_dump', 'print_r'], true))
					{
						$violations[] = $file->getFilename() . ':' . $call->getStartLine() . ' data dump';
					}
					if ($name !== 'error_log') continue;
					foreach ($finder->findInstanceOf($call->args, \PhpParser\Node\Expr\MethodCall::class) as $method)
					{
						if ($method->name instanceof \PhpParser\Node\Identifier && $method->name->name === 'getMessage')
						{
							$violations[] = $file->getFilename() . ':' . $call->getStartLine() . ' raw exception log';
						}
					}
				}
			}
			$this->assertSame([], $violations);
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
				Cache::session_set('email', 'mail-session-test', null);
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

		/**
		 * @dataProvider fallbackLoginInputs
		 */
		public function testLegacyLoginFallbackResetsAPreviousNoLoginPolicy(mixed $input): void
		{
			$bootstrap = new \email_msg_bootstrap();
			$bootstrap->set_do_login(false);
			$this->assertSame(\email_msg_bootstrap::LOGIN_ONLY_IF_NEEDED, $bootstrap->set_do_login($input));
			$this->assertTrue($bootstrap->get_do_login());
			$this->assertSame(\email_msg_bootstrap::LOGIN_ONLY_IF_NEEDED, $bootstrap->get_do_login_ex());
		}

		public static function fallbackLoginInputs(): array
		{
			return [[0], [-1], [4], ['1'], [null], [1.0], [[]], ['##NOTHING##']];
		}

		public function testBootstrapHonorsDirectPublicLoginSettingBeforeFirstRequest(): void
		{
			$mail = $this->mailDouble();
			$bootstrap = new \email_msg_bootstrap();
			$bootstrap->do_login = false;
			$bootstrap->ensure_mail_msg_exists('unit-test');
			$this->assertFalse($mail->requests[0]['do_login']);
			$this->assertSame(\email_msg_bootstrap::LOGIN_NEVER, $mail->requests[0]['do_login_ex']);
		}
	}
}