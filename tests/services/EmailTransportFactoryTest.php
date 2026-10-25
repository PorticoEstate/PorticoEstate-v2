<?php

namespace
{
	function include_class($module, $class): bool
	{
		require_once __DIR__ . '/../../src/modules/' . $module . '/inc/class.' . $class . '.inc.php';
		return true;
	}
}

namespace Tests\Services
{
	require_once __DIR__ . '/../../vendor/autoload.php';
	require_once __DIR__ . '/../../src/modules/phpgwapi/inc/class.object_factory.inc.php';
	require_once __DIR__ . '/../../src/modules/email/inc/class.ofemail.inc.php';

	use App\modules\phpgwapi\services\Settings;
	use PHPUnit\Framework\TestCase;

	class TransportFactoryImports
	{
		public static function import_class($name): void
		{
			[$module, $class] = explode('.', $name);
			\include_class($module, $class);
		}
	}

	class TransportFactoryCommon
	{
		public function randomstring(): string
		{
			return 'factory-test';
		}
	}

	/**
	 * @runTestsInSeparateProcesses
	 * @preserveGlobalState disabled
	 */
	class EmailTransportFactoryTest extends TestCase
	{
		protected function setUp(): void
		{
			class_alias(TransportFactoryImports::class, 'phpgw');
			class_alias(TransportFactoryCommon::class, 'phpgwapi_common');
			$property = new \ReflectionProperty(Settings::class, 'instance');
			$property->setAccessible(true);
			$property->setValue(null, new class
			{
				public function get(string $key): array
				{
					return $key === 'server' ? ['temp_dir' => '/tmp'] : [
						'sessionid' => 'test', 'preferences' => ['email' => ['mail_server_type' => 'pop3']],
					];
				}
			});
		}

		/**
		 * @dataProvider protocolOrders
		 */
		public function testAccountsSelectIndependentTransportsInEitherOrder(array $protocols): void
		{
			$transports = [];
			foreach ($protocols as $protocol)
			{
				$transport = \email_ofemail::createObject('email.mail_dcom', $protocol);
				$expected = str_starts_with(strtolower($protocol), 'imap')
					? 'email_mail_dcom'
					: (extension_loaded('imap') && function_exists('imap_open') ? 'mail_dcom_pop3' : 'mail_dcom_pop3_sock');
				$this->assertSame($expected, get_class($transport));
				$transports[] = $transport;
			}
			$this->assertNotSame($transports[0], $transports[1]);
		}

		public static function protocolOrders(): array
		{
			return [[['imap', 'pop3']], [['pop3s', 'imaps']], [['IMAPS', 'POP3S']]];
		}

		public function testOmittedProtocolUsesDefaultPreferences(): void
		{
			$transport = \email_ofemail::createObject('email.mail_dcom', '_UNDEF_');
			$this->assertSame(extension_loaded('imap') && function_exists('imap_open')
				? 'mail_dcom_pop3' : 'mail_dcom_pop3_sock', get_class($transport));
		}

		/**
		 * @dataProvider appendResults
		 */
		public function testImapAppendReturnsBackendResult(bool $succeeds): void
		{
			$transport = \email_ofemail::createObject('email.mail_dcom', 'imap');
			$property = new \ReflectionProperty(\ModernIMAPWrapper::class, 'connections');
			$property->setAccessible(true);
			$original = $property->getValue();
			$response = $this->getMockBuilder(\stdClass::class)->addMethods(['validatedData'])->getMock();
			if ($succeeds)
			{
				$response->expects($this->once())->method('validatedData')->willReturn([]);
			}
			else
			{
				$response->expects($this->once())->method('validatedData')
					->willThrowException(new \RuntimeException('APPEND rejected'));
			}
			$protocol = $this->getMockBuilder(\stdClass::class)->addMethods(['appendMessage'])->getMock();
			$protocol->expects($this->once())->method('appendMessage')
				->with('Sent', "Subject: Test\r\n\r\nBody\r\n", ['\\Seen'])->willReturn($response);
			$client = $this->getMockBuilder(\stdClass::class)->addMethods(['getConnection'])->getMock();
			$client->method('getConnection')->willReturn($protocol);
			$property->setValue(null, ['append-test' => ['client' => $client]]);
			try
			{
				$this->assertSame($succeeds, $transport->append(
					'append-test', '{test:993/imap/ssl}Sent', "Subject: Test\r\n\r\nBody\r\n", '\\Seen'
				));
			}
			finally
			{
				$property->setValue(null, $original);
			}
		}

		public static function appendResults(): array
		{
			return [[true], [false]];
		}

		public function testAppendRoundTripAgainstDisposableGreenMailFolder(): void
		{
			if (getenv('GREENMAIL_TEST') !== '1')
			{
				$this->markTestSkipped('Set GREENMAIL_TEST=1 to use the local GreenMail fixture.');
			}
			$transport = \email_ofemail::createObject('email.mail_dcom', 'imap');
			\IMAPManager::setMode('modern');
			$prefix = '{127.0.0.1:1993/imap/ssl/novalidate-cert}';
			$folder = 'append-regression-' . bin2hex(random_bytes(8));
			$messageId = '<' . $folder . '@example.test>';
			$message = "From: sender@example.test\r\nTo: outlook-test@outlook.test\r\n"
				. "Subject: Append regression\r\nMessage-ID: " . $messageId . "\r\n\r\nUnique append body\r\n";
			$stream = $transport->open($prefix . 'INBOX', 'outlook-test', 'local-test-password');
			$this->assertNotFalse($stream, (string) $transport->server_last_error());
			$created = false;
			try
			{
				$this->assertTrue(\IMAPManager::imap_createmailbox($stream, $prefix . $folder));
				$created = true;
				$this->assertTrue($transport->reopen($stream, $prefix . $folder));
				$this->assertSame(0, \IMAPManager::imap_num_msg($stream));
				$this->assertTrue($transport->append($stream, $prefix . $folder, $message, '\\Seen'));
				$this->assertTrue($transport->reopen($stream, $prefix . $folder));
				$this->assertSame(1, \IMAPManager::imap_num_msg($stream));
				$this->assertSame([1], \IMAPManager::imap_search($stream, 'ALL'));
				$this->assertStringContainsString($messageId, \IMAPManager::imap_fetchheader($stream, 1));
				$this->assertStringContainsString('Unique append body', \IMAPManager::imap_body($stream, 1));
				$this->assertFalse($transport->append($stream, $prefix . $folder . '-missing', $message, '\\Seen'));
			}
			finally
			{
				try
				{
					if ($created)
					{
						$this->assertTrue($transport->reopen($stream, $prefix . 'INBOX'));
						$this->assertTrue(\IMAPManager::imap_deletemailbox($stream, $prefix . $folder), $folder . ': ' . $transport->server_last_error());
					}
				}
				finally
				{
					$this->assertTrue($transport->close($stream));
				}
			}
		}

		/**
		 * @dataProvider greenMailOperations
		 */
		public function testGreenMailMessageLifecycle(string $operation): void
		{
			if (getenv('GREENMAIL_TEST') !== '1')
			{
				$this->markTestSkipped('Set GREENMAIL_TEST=1 to use the local GreenMail fixture.');
			}
			$transport = \email_ofemail::createObject('email.mail_dcom', 'imap');
			\IMAPManager::setMode('modern');
			$prefix = '{127.0.0.1:1993/imap/ssl/novalidate-cert}';
			$source = 'lifecycle-' . bin2hex(random_bytes(8));
			$destination = $source . '-destination';
			$messageId = '<' . $source . '@example.test>';
			$message = "From: sender@example.test\r\nTo: outlook-test@outlook.test\r\n"
				. "Subject: Lifecycle regression\r\nMessage-ID: " . $messageId . "\r\n\r\nLifecycle body\r\n";
			$stream = $transport->open($prefix . 'INBOX', 'outlook-test', 'local-test-password');
			$this->assertNotFalse($stream, (string) $transport->server_last_error());
			$created = [];
			try
			{
				foreach ([$source, $destination] as $folder)
				{
					$this->assertTrue(\IMAPManager::imap_createmailbox($stream, $prefix . $folder));
					$created[] = $folder;
				}
				$this->assertTrue($transport->append($stream, $prefix . $source, $message, '\\Seen'));
				$this->assertTrue($transport->reopen($stream, $prefix . $source));
				$uids = \IMAPManager::imap_search($stream, 'ALL', 1);
				$this->assertIsArray($uids);
				$this->assertCount(1, $uids);
				$uid = (string) $uids[0];
				switch ($operation)
				{
					case 'metadata':
						$this->assertTrue($transport->append($stream, $prefix . $source, $message, '\\Seen'));
						$this->assertTrue($transport->delete($stream, $uid, 1));
						$this->assertTrue($transport->expunge($stream));
						$second = str_replace('Lifecycle regression', 'Second message', $message);
						$this->assertTrue($transport->append($stream, $prefix . $source, $second, '\\Seen'));
						$this->assertSame([1, 2], \IMAPManager::imap_search($stream, 'ALL', 0));
						$this->assertSame([2, 3], \IMAPManager::imap_search($stream, 'ALL', 1));
						$this->assertSame([2], \ModernIMAPWrapper::imap_prefetch_message_metadata($stream, [2], 1));
						$this->assertSame('Lifecycle regression', \ModernIMAPWrapper::imap_header($stream, 2, 0, 0, '', 1)->subject);
						$this->assertSame('Second message', \ModernIMAPWrapper::imap_header($stream, 2)->subject);
						$this->assertSame([1], \ModernIMAPWrapper::imap_prefetch_message_metadata($stream, [1], 0));
						$this->assertSame('Lifecycle regression', \ModernIMAPWrapper::imap_header($stream, 1)->subject);
						$this->assertTrue(\IMAPManager::imap_setflag_full($stream, '2', '\\Flagged', 1));
						$this->assertSame('F', \ModernIMAPWrapper::imap_header($stream, 2, 0, 0, '', 1)->Flagged);
						$this->assertSame([1], \ModernIMAPWrapper::imap_prefetch_message_metadata($stream, [1], 0));
						$this->assertTrue($transport->append($stream, $prefix . $destination, $second, '\\Seen'));
						$this->assertTrue($transport->reopen($stream, $prefix . $destination));
						$this->assertSame('Second message', \ModernIMAPWrapper::imap_header($stream, 1)->subject);
						break;
					case 'flags':
						$this->assertTrue(\IMAPManager::imap_setflag_full($stream, $uid, '\\Flagged', 1));
						$this->assertSame($uids, \IMAPManager::imap_search($stream, 'FLAGGED', 1));
						$this->assertSame($uids, \IMAPManager::imap_search($stream, 'SEEN', 1));
						$this->assertTrue(\IMAPManager::imap_clearflag_full($stream, $uid, '\\Flagged', 1));
						$this->assertSame([], \IMAPManager::imap_search($stream, 'FLAGGED', 1));
						$this->assertSame($uids, \IMAPManager::imap_search($stream, 'SEEN', 1));
						break;
					case 'copy':
					case 'move':
						$result = $operation === 'copy'
							? $transport->mailcopy($stream, $uid, $prefix . $destination, 1)
							: $transport->mail_move($stream, $uid, $prefix . $destination, 1);
						$this->assertTrue($result, (string) $transport->server_last_error());
						if ($operation === 'move')
						{
							$this->assertTrue($transport->expunge($stream));
						}
						$this->assertSame($operation === 'copy' ? 1 : 0, $transport->num_msg($stream));
						$this->assertTrue($transport->reopen($stream, $prefix . $destination));
						$this->assertSame(1, $transport->num_msg($stream));
						$this->assertStringContainsString($messageId, \IMAPManager::imap_fetchheader($stream, 1));
						$this->assertStringContainsString('Lifecycle body', \IMAPManager::imap_body($stream, 1));
						break;
					case 'delete':
						$this->assertTrue($transport->delete($stream, $uid, 1));
						$this->assertSame($uids, \IMAPManager::imap_search($stream, 'DELETED', 1));
						$this->assertSame(1, $transport->num_msg($stream));
						$this->assertTrue($transport->expunge($stream));
						$this->assertSame(0, $transport->num_msg($stream));
						$this->assertSame([], \IMAPManager::imap_search($stream, 'ALL', 1));
						break;
					case 'reconnect':
						$this->assertTrue($transport->reopen($stream, $prefix . 'INBOX'));
						$this->assertTrue($transport->close($stream));
						$stream = $transport->open($prefix . $source, 'outlook-test', 'local-test-password');
						$this->assertNotFalse($stream, (string) $transport->server_last_error());
						$this->assertTrue($transport->noop_ping_test($stream));
						$this->assertSame(1, $transport->num_msg($stream));
						$this->assertSame($uids, \IMAPManager::imap_search($stream, 'ALL', 1));
						$this->assertStringContainsString($messageId, \IMAPManager::imap_fetchheader($stream, 1));
						break;
				}
			}
			finally
			{
				try
				{
					if ($stream === false)
					{
						$stream = $transport->open($prefix . 'INBOX', 'outlook-test', 'local-test-password');
						$this->assertNotFalse($stream, 'Unable to reconnect for temporary-folder cleanup.');
					}
					$this->assertTrue($transport->reopen($stream, $prefix . 'INBOX'), (string) $transport->server_last_error());
					foreach (array_reverse($created) as $folder)
					{
						$this->assertTrue(\IMAPManager::imap_deletemailbox($stream, $prefix . $folder), $folder . ': ' . $transport->server_last_error());
					}
				}
				finally
				{
					$this->assertTrue($transport->close($stream));
				}
			}
		}

		public static function greenMailOperations(): array
		{
			return [['flags'], ['copy'], ['move'], ['delete'], ['reconnect'], ['metadata']];
		}

		public function testNativeAndSocketPop3ClassesCanCoexistWithImap(): void
		{
			$imap = \email_ofemail::createObject('email.mail_dcom', 'imap');
			require_once __DIR__ . '/../../src/modules/email/inc/class.mail_dcom_pop3.inc.php';
			require_once __DIR__ . '/../../src/modules/email/inc/class.mail_dcom_base_sock.inc.php';
			require_once __DIR__ . '/../../src/modules/email/inc/class.mail_dcom_pop3_sock.inc.php';
			$native = new \mail_dcom_pop3();
			$socket = new \mail_dcom_pop3_sock();
			$this->assertInstanceOf(\mail_dcom_base::class, $imap);
			$this->assertInstanceOf(\mail_dcom_base::class, $native);
			$this->assertInstanceOf(\mail_dcom_base_sock::class, $socket);
			$native->force_msg_uids = true;
			$this->assertFalse($socket->force_msg_uids);
			$this->assertFalse($imap->force_msg_uids);
		}

		public function testOtherEmailObjectsStillUseTheStandardFactory(): void
		{
			$bootstrap = \email_ofemail::createObject('email.msg_bootstrap');
			$this->assertInstanceOf(\email_msg_bootstrap::class, $bootstrap);
			$this->assertSame(\email_msg_bootstrap::LOGIN_NEVER, $bootstrap->set_do_login(1));
		}

		public function testUnsupportedProtocolDoesNotSilentlySelectImap(): void
		{
			$this->expectException(\InvalidArgumentException::class);
			\email_ofemail::createObject('email.mail_dcom', 'nntp');
		}

		public function testImapTransportPassesOauthAndSslToModernBackend(): void
		{
			$transport = \email_ofemail::createObject('email.mail_dcom', 'imaps');
			$manager = new class
			{
				public array $config = [];
				public function make(array $config): object
				{
					$this->config = $config;
					return new class
					{
						public function connect(): void {}
						public function disconnect(): void {}
						public function getFolderByPath($path, $create): object { return (object) ['path' => $path]; }
					};
				}
			};
			$property = new \ReflectionProperty(\ModernIMAPWrapper::class, 'clientManager');
			$property->setAccessible(true);
			$property->setValue(null, $manager);
			$stream = $transport->open('{imap.test:993/imap/ssl}INBOX', 'user', 'token', 0, 'oauth');
			$this->assertIsString($stream);
			$this->assertSame('oauth', $manager->config['authentication']);
			$this->assertSame('ssl', $manager->config['encryption']);
			$this->assertSame('imap', $manager->config['protocol']);
			$this->assertSame(993, $manager->config['port']);
			$this->assertTrue($transport->close($stream));
		}
	}
}
