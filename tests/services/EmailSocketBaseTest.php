<?php

namespace Tests\Services;

require_once __DIR__ . '/../../vendor/autoload.php';

use App\modules\phpgwapi\services\Settings;
use PHPUnit\Framework\TestCase;

class SocketBaseImports
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

class SocketBaseCommon
{
	public function randomstring(): string
	{
		return 'socket-test';
	}
}

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class EmailSocketBaseTest extends TestCase
{
	protected function setUp(): void
	{
		class_alias(SocketBaseImports::class, 'phpgw');
		require_once __DIR__ . '/../../src/modules/email/inc/class.mail_dcom_base_sock.inc.php';
	}

	public function testConstructorInitializesParentAndRetainsMailPaths(): void
	{
		class_alias(SocketBaseCommon::class, 'phpgwapi_common');
		$property = new \ReflectionProperty(Settings::class, 'instance');
		$property->setAccessible(true);
		$original = $property->getValue();
		$property->setValue(null, new class
		{
			public function get(string $key): array
			{
				return match ($key) {
					'server' => ['temp_dir' => '/tmp/mail-test'],
					'user' => ['sessionid' => 'session-test'],
					default => throw new \LogicException('Unexpected settings: ' . $key),
				};
			}
		});
		try
		{
			$mail = new \mail_dcom_base();
			$this->assertTrue($mail->addcrlf);
			$this->assertSame(0, $mail->errorset);
			$this->assertSame(['temp_dir' => '/tmp/mail-test'], $mail->serverSettings);
			$this->assertSame(['sessionid' => 'session-test'], $mail->userSettings);
			$this->assertSame('/tmp/mail-test/socket-test.mhd', $mail->tempfile);
			$this->assertSame('/tmp/mail-test/session-test', $mail->att_files_dir);
			$this->assertSame("NOOP\r\n", $mail->add_crlf('NOOP'));
		}
		finally
		{
			$property->setValue(null, $original);
		}
	}

	/**
	 * @dataProvider encodedHeaders
	 */
	public function testHeaderDecodingUsesPayloadAndPreservesSurroundingText(string $method, string $input, string $expected): void
	{
		$mail = (new \ReflectionClass(\mail_dcom_base::class))->newInstanceWithoutConstructor();
		$this->assertSame($expected, $mail->$method($input));
	}

	public static function encodedHeaders(): array
	{
		return [
			['decode_base64', '=?UTF-8?B?Sm9obiBEb2U=?=', 'John Doe'],
			['decode_base64', "O'Brien <=?UTF-8?b?YWxpY2U=?=@example.test>", "O'Brien <alice@example.test>"],
			['decode_base64', '=?UTF-8?B?Sm9obg==?= / =?UTF-8?B?RG9l?=', 'John / Doe'],
			['decode_qp', '=?UTF-8?Q?John_Doe=27s?=', "John Doe's"],
			['decode_qp', "O'Brien <=?UTF-8?q?alice?=@example.test>", "O'Brien <alice@example.test>"],
			['decode_qp', '=?UTF-8?Q?one?= / =?UTF-8?Q?two?=', 'one / two'],
			['decode_header', '=?UTF-8?B?Sm9obiBEb2U=?= <john@example.test>', 'John Doe <john@example.test>'],
			['decode_header', '=?UTF-8?Q?John_Doe?= <john@example.test>', 'John Doe <john@example.test>'],
			['decode_header', "O'Brien <john@example.test>", "O'Brien <john@example.test>"],
			['decode_base64', "Plain O'Brien", "Plain O'Brien"],
			['decode_qp', "Plain O'Brien", "Plain O'Brien"],
			['decode_qp', '=?ISO-8859-1?Q?Andr=E9?=', "Andr\xE9"],
		];
	}

	/**
	 * @dataProvider authorHeaders
	 */
	public function testDecodedAuthorReachesAddressParser(string $header, string $expectedEmail, string $expectedName): void
	{
		$mail = (new \ReflectionClass(\mail_dcom_base::class))->newInstanceWithoutConstructor();
		$email = '';
		$name = '';
		$mail->decode_author($header, $email, $name);
		$this->assertSame($expectedEmail, $email);
		$this->assertSame($expectedName, $name);
	}

	public static function authorHeaders(): array
	{
		return [
			['=?UTF-8?Q?John_Doe?= <john@example.test>', 'john@example.test', 'John Doe'],
			['"John Doe" <john@example.test>', 'john@example.test', 'John Doe'],
			['john@example.test (John Doe)', 'john@example.test', 'John Doe'],
			['john@example.test', 'john@example.test', 'john@example.test'],
		];
	}

	/**
	 * @dataProvider multilineResponses
	 */
	public function testMultilineResponseRequiresExactTerminator(array $lines, string $expected): void
	{
		$mail = $this->getMockBuilder(\mail_dcom_base::class)->disableOriginalConstructor()
			->onlyMethods(['read_port'])->getMock();
		$mail->method('read_port')->willReturnOnConsecutiveCalls(...$lines);
		$this->assertSame($expected, $mail->read_port_glob('.'));
	}

	public static function multilineResponses(): array
	{
		return [
			[[".\r\n"], ''],
			[["header\r\n", "\r\n", "body\r\n", ".\r\n"], "header\r\n\r\nbody\r\n"],
			[[". \r\n", "..dot\r\n", ".\r\n"], ". \r\n..dot\r\n"],
		];
	}

	/**
	 * @dataProvider interruptedReaders
	 */
	public function testInterruptedResponseThrowsAndDiscardsPartialHeaders(string $method, bool $existingError): void
	{
		$mail = $this->getMockBuilder(\mail_dcom_base::class)->disableOriginalConstructor()
			->onlyMethods(['read_port', 'create_header'])->getMock();
		$mail->method('read_port')->willReturnOnConsecutiveCalls("Subject: partial\r\n", false);
		$mail->method('create_header')->willReturnCallback(static function ($line, &$headers)
		{
			$headers['Subject'] = 'partial';
		});
		if ($existingError)
		{
			$mail->set_error('Error', 'Read Timeout', 'Timed out while reading from server.');
		}
		try
		{
			$mail->$method('.');
			$this->fail('Incomplete response must not be returned as successful data.');
		}
		catch (\RuntimeException $exception)
		{
			$this->assertSame('Mail server response ended before its terminator.', $exception->getMessage());
			$this->assertSame($existingError ? 'Read Timeout' : 'Incomplete Response', $mail->error['msg']);
			if ($method === 'read_and_load')
			{
				$this->assertSame([], $mail->header);
			}
		}
	}

	public static function interruptedReaders(): array
	{
		return [['read_port_glob', false], ['read_port_glob', true], ['read_and_load', false], ['read_and_load', true]];
	}

	public function testHeaderLoadingStopsAtTerminatorWithoutConsumingNextResponse(): void
	{
		$mail = $this->getMockBuilder(\mail_dcom_base::class)->disableOriginalConstructor()
			->onlyMethods(['read_port', 'create_header'])->getMock();
		$mail->expects($this->exactly(2))->method('read_port')
			->willReturnOnConsecutiveCalls("Subject: complete\r\n", ".\r\n");
		$mail->expects($this->once())->method('create_header')
			->with("Subject: complete\r\n", [], 'True');
		$this->assertSame(1, $mail->read_and_load('.'));
	}

	/**
	 * @dataProvider interruptedPop3Reads
	 */
	public function testPop3DoesNotCacheAnInterruptedResponse(string $method, array $lines): void
	{
		require_once __DIR__ . '/../../src/modules/email/inc/class.mail_dcom_pop3_sock.inc.php';
		$mail = $this->getMockBuilder(\mail_dcom::class)->disableOriginalConstructor()
			->onlyMethods(['msg2socket', 'read_port'])->getMock();
		$mail->method('msg2socket')->willReturn(1);
		$mail->method('read_port')->willReturnOnConsecutiveCalls(...$lines);
		try
		{
			$mail->$method('', 42);
			$this->fail('POP3 must reject interrupted responses.');
		}
		catch (\RuntimeException $exception)
		{
			$this->assertSame('Mail server response ended before its terminator.', $exception->getMessage());
			$this->assertSame('', $mail->header_glob_msgnum);
			$this->assertSame('', $mail->body_array_msgnum);
			$this->assertSame([], $mail->body_array);
		}
	}

	public static function interruptedPop3Reads(): array
	{
		return [
			['get_header_raw', ["Subject: partial\r\n", false]],
			['get_body', ["Subject: complete\r\n", "\r\n", "partial body\r\n", false]],
		];
	}
}
