<?php

namespace Tests\Services;

require_once __DIR__ . '/../../vendor/autoload.php';

use PHPUnit\Framework\TestCase;

class Pop3LoopbackImports
{
	public static function import_class($name): void
	{
		if ($name !== 'phpgwapi.network')
		{
			throw new \LogicException('Unexpected import: ' . $name);
		}
		require_once __DIR__ . '/../../src/modules/phpgwapi/inc/class.network.inc.php';
	}
}

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class EmailPop3LoopbackTest extends TestCase
{
	private $server;
	private array $pipes = [];
	private ?\mail_dcom_pop3_sock $mail = null;
	private array $temporaryFiles = [];
	private $originalCertificateFile;

	protected function setUp(): void
	{
		$this->originalCertificateFile = getenv('SSL_CERT_FILE');
		class_alias(Pop3LoopbackImports::class, 'phpgw');
		require_once __DIR__ . '/../../src/modules/email/inc/class.mail_dcom_base_sock.inc.php';
		require_once __DIR__ . '/../../src/modules/email/inc/class.mail_dcom_pop3_sock.inc.php';
		$this->mail = (new \ReflectionClass(\mail_dcom_pop3_sock::class))->newInstanceWithoutConstructor();
		$this->mail->set_addcrlf(true);
		$this->mail->serverSettings = [];
	}

	protected function tearDown(): void
	{
		$this->mail->close_port();
		if (is_resource($this->server))
		{
			proc_terminate($this->server);
			foreach ($this->pipes as $pipe)
			{
				if (is_resource($pipe))
				{
					fclose($pipe);
				}
			}
			proc_close($this->server);
		}
		putenv($this->originalCertificateFile === false ? 'SSL_CERT_FILE' : 'SSL_CERT_FILE=' . $this->originalCertificateFile);
		foreach ($this->temporaryFiles as $path)
		{
			unlink($path);
		}
	}

	private function startServer(array $steps, bool $expectDisconnect = false, array $tls = []): string
	{
		$this->server = proc_open(
			[PHP_BINARY, __DIR__ . '/../fixtures/pop3-scripted-server.php'],
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$this->pipes
		);
		$this->assertIsResource($this->server);
		$script = json_encode(['steps' => $steps, 'expectDisconnect' => $expectDisconnect, 'tls' => $tls], JSON_THROW_ON_ERROR) . "\n";
		$this->assertSame(strlen($script), fwrite($this->pipes[0], $script));
		fclose($this->pipes[0]);
		stream_set_timeout($this->pipes[1], 10);
		$address = fgets($this->pipes[1]);
		$this->assertIsString($address, 'Scripted server did not report readiness.');
		$this->assertMatchesRegularExpression('/^127\.0\.0\.1:[0-9]+\n$/', $address);
		return '{' . trim($address) . ($tls ? '/pop3/ssl' : '/pop3/notls') . '}INBOX';
	}

	private function createCertificate(string $extension): array
	{
		$config = [
			'config' => __DIR__ . '/../fixtures/pop3-tls.cnf',
			'digest_alg' => 'sha256',
			'private_key_bits' => 2048,
			'x509_extensions' => $extension,
		];
		$key = openssl_pkey_new($config);
		$this->assertNotFalse($key);
		$request = openssl_csr_new(['commonName' => 'POP3 loopback test'], $key, $config);
		$this->assertNotFalse($request);
		$certificate = openssl_csr_sign($request, null, $key, 1, $config);
		$this->assertNotFalse($certificate);
		$paths = [];
		foreach (['certificate', 'key'] as $kind)
		{
			$path = tempnam(sys_get_temp_dir(), 'pop3-tls-');
			$this->assertNotFalse($path);
			$this->temporaryFiles[] = $path;
			$this->assertTrue(chmod($path, 0600));
			$paths[$kind] = $path;
		}
		$this->assertTrue(openssl_x509_export_to_file($certificate, $paths['certificate']));
		$this->assertTrue(openssl_pkey_export_to_file($key, $paths['key']));
		return $paths;
	}

	public function testTrustedPop3sCertificateAllowsEncryptedSession(): void
	{
		$tls = $this->createCertificate('loopback');
		putenv('SSL_CERT_FILE=' . $tls['certificate']);
		$folder = $this->startServer(array_merge($this->loginSteps(), [
			['STAT', "+OK 1 100\r\n"],
			['QUIT', "+OK goodbye\r\n"],
		]), false, $tls);
		$this->expectOutputString('');
		$stream = $this->mail->open($folder, 'test-user', 'test-password');
		$this->assertIsResource($stream, $this->mail->server_last_error());
		$metadata = stream_get_meta_data($stream);
		$this->assertStringStartsWith('TLS', $metadata['crypto']['protocol']);
		$this->assertSame('1', $this->mail->num_msg($stream));
		$this->assertTrue($this->mail->close());
		$this->assertServerCompleted();
	}

	/**
	 * @dataProvider rejectedCertificates
	 */
	public function testPop3sRejectsInvalidCertificatesBeforeAuthentication(string $extension, bool $trusted): void
	{
		$tls = $this->createCertificate($extension);
		$ca = $trusted ? $tls : $this->createCertificate('loopback');
		putenv('SSL_CERT_FILE=' . $ca['certificate']);
		$tls['expectFailure'] = true;
		$folder = $this->startServer([], false, $tls);
		$this->expectOutputString('');
		$this->assertFalse($this->mail->open($folder, 'test-user', 'test-password'));
		$this->assertNull($this->mail->socket);
		$this->assertNotSame('', $this->mail->server_last_error());
		$this->assertStringNotContainsString('test-password', $this->mail->server_last_error());
		$this->assertServerCompleted();
	}

	public static function rejectedCertificates(): array
	{
		return ['untrusted' => ['loopback', false], 'hostname mismatch' => ['wrong_host', true]];
	}

	private function assertServerCompleted(): void
	{
		$output = stream_get_contents($this->pipes[1]);
		$errors = stream_get_contents($this->pipes[2]);
		fclose($this->pipes[1]);
		fclose($this->pipes[2]);
		$code = proc_close($this->server);
		$this->server = null;
		$this->assertSame(0, $code, $errors);
		$this->assertSame("OK\n", $output);
		$this->assertSame('', $errors);
	}

	private function loginSteps(): array
	{
		return [['USER test-user', "+OK user\r\n"], ['PASS test-password', "+OK authenticated\r\n"]];
	}

	/**
	 * @dataProvider noopResponses
	 */
	public function testNoopChecksConnectionWithoutCommittingDeletions(string $response, bool $healthy): void
	{
		$steps = array_merge($this->loginSteps(), [
			['DELE 1', "+OK marked\r\n"],
			['NOOP', $response],
		]);
		if ($healthy)
		{
			$steps[] = ['STAT', "+OK 1 100\r\n"];
			$steps[] = ['QUIT', "+OK committed\r\n"];
		}
		$folder = $this->startServer($steps, !$healthy && $response !== '');
		$this->expectOutputString('');
		$stream = $this->mail->open($folder, 'test-user', 'test-password');
		$this->assertIsResource($stream);
		$this->assertTrue($this->mail->delete($stream, '1'));
		$this->assertSame($healthy, $this->mail->noop_ping_test($stream));
		if ($healthy)
		{
			$this->assertIsResource($this->mail->socket);
			$this->assertSame('1', $this->mail->num_msg($stream));
		}
		else
		{
			$this->assertNull($this->mail->socket);
			$this->assertNotSame('', $this->mail->server_last_error());
		}
		$this->assertTrue($this->mail->close());
		$this->assertFalse($this->mail->noop_ping_test($stream));
		$this->assertNotSame('', $this->mail->server_last_error());
		$this->assertServerCompleted();
	}

	public static function noopResponses(): array
	{
		return [
			'positive' => ["+OK alive\r\n", true],
			'bare positive' => ["+OK\r\n", true],
			'rejected' => ["-ERR unavailable\r\n", false],
			'invalid prefix' => ["+OKAY invalid\r\n", false],
			'disconnected' => ['', false],
		];
	}

	public function testLoginSortRetrieveCacheAndDeleteOverTcp(): void
	{
		$header = "Subject: Loopback\r\n\tcontinued\r\nFrom: sender@example.test\r\n";
		$body = ".leading\r\n" . str_repeat('x', 2048) . "\r\n";
		$folder = $this->startServer(array_merge($this->loginSteps(), [
			['STAT', "+OK 1 2200\r\n"],
			['TOP 1 0', "+OK headers\r\n" . $header . "\r\n.\r\n"],
			['RETR 1', "+OK message\r\n" . $header . "\r\n." . $body . ".\r\n"],
			['DELE 1', "+OK marked\r\n"],
			['QUIT', "+OK committed\r\n"],
		]));
		$this->expectOutputString('');
		$stream = $this->mail->open($folder, 'test-user', 'test-password');
		$this->assertIsResource($stream);
		$this->assertSame('1', $this->mail->num_msg($stream));
		$this->assertSame([1 => 'Loopback continued'], $this->mail->fetch_header_element(1, 1, 'Subject'));
		$this->assertSame($header . "\r\n", $this->mail->get_header_raw($stream, 1));
		$this->assertSame($body, $this->mail->get_body($stream, 1, '', false));
		$this->assertSame($header . "\r\n" . $body, $this->mail->get_body($stream, 1));
		$this->assertSame($header, $this->mail->get_header_raw($stream, 1));
		$this->assertTrue($this->mail->delete($stream, '1'));
		$this->assertTrue($this->mail->close($stream));
		$this->assertNull($this->mail->socket);
		$this->assertTrue($this->mail->close());
		$this->assertServerCompleted();
	}

	public function testAuthenticationRejectionDisconnectsWithoutQuit(): void
	{
		$folder = $this->startServer([
			['USER test-user', "+OK user\r\n"],
			['PASS test-password', "-ERR authentication rejected\r\n"],
		], true);
		$this->expectOutputString('');
		$this->assertFalse($this->mail->open($folder, 'test-user', 'test-password'));
		$this->assertNull($this->mail->socket);
		$this->assertNotSame('', $this->mail->server_last_error());
		$this->assertStringNotContainsString('test-password', $this->mail->server_last_error());
		$this->assertServerCompleted();
	}

	public function testRejectedTopAfterDeletionDisconnectsWithoutCommitting(): void
	{
		$folder = $this->startServer(array_merge($this->loginSteps(), [
			['DELE 1', "+OK marked\r\n"],
			['TOP 2 0', "-ERR no such message\r\n"],
		]), true);
		$this->expectOutputString('');
		$stream = $this->mail->open($folder, 'test-user', 'test-password');
		$this->assertIsResource($stream);
		$this->assertTrue($this->mail->delete($stream, '1'));
		try
		{
			$this->mail->fetch_header_element(2, 2, 'Subject');
			$this->fail('Rejected TOP must throw.');
		}
		catch (\RuntimeException $exception)
		{
			$this->assertSame('Unable to complete the POP3 operation.', $exception->getMessage());
		}
		$this->assertNull($this->mail->socket);
		$this->assertTrue($this->mail->close());
		$this->assertServerCompleted();
	}

	public function testInterruptedRetrievalDoesNotPublishPartialCache(): void
	{
		$folder = $this->startServer(array_merge($this->loginSteps(), [
			['RETR 1', "+OK message\r\nSubject: Truncated\r\n\r\npartial body\r\n"],
		]));
		$this->expectOutputString('');
		$stream = $this->mail->open($folder, 'test-user', 'test-password');
		$this->assertIsResource($stream);
		try
		{
			$this->mail->get_body($stream, 1);
			$this->fail('Missing terminator must throw.');
		}
		catch (\RuntimeException $exception)
		{
			$this->assertNotSame('', $exception->getMessage());
		}
		$this->assertSame([], $this->mail->body_array);
		$this->assertSame('', $this->mail->header_glob_msgnum);
		$this->mail->close_port();
		$this->assertServerCompleted();
	}
}
