<?php

namespace Tests\Services;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/modules/phpgwapi/inc/class.network.inc.php';

use PHPUnit\Framework\TestCase;

class NetworkPartialWriteStream
{
	public $context;
	public static string $data = '';
	public static int $limit = 3;
	public static ?int $stopAfter = null;

	public function stream_open($path, $mode, $options, &$openedPath): bool
	{
		return true;
	}

	public function stream_write($data): int
	{
		if (self::$stopAfter !== null && strlen(self::$data) >= self::$stopAfter)
		{
			return 0;
		}
		$length = min(strlen($data), self::$limit);
		self::$data .= substr($data, 0, $length);
		return $length;
	}

	public function stream_eof(): bool
	{
		return false;
	}

	public function stream_stat(): array
	{
		return [];
	}
}

class NetworkStreamTest extends TestCase
{
	private function network($socket = null): \phpgwapi_network
	{
		$network = (new \ReflectionClass(\phpgwapi_network::class))->newInstanceWithoutConstructor();
		$network->socket = $socket;
		return $network;
	}

	public function testLongLinesAreNotSplitAndFinalUnterminatedLineIsPreserved(): void
	{
		$socket = fopen('php://memory', 'r+');
		$network = $this->network($socket);
		$line = str_repeat('header-data', 1000) . "\r\n";
		try
		{
			fwrite($socket, $line . "\r\nlast-line");
			rewind($socket);
			$this->assertSame($line, $network->read_port());
			$this->assertSame("\r\n", $network->read_port());
			$this->assertSame('last-line', $network->read_port());
			$this->assertFalse($network->read_port());
			$this->assertSame('End Of Stream', $network->error['msg']);
		}
		finally
		{
			$network->close_port();
		}
	}

	public function testBinaryReadPreservesByteCountAndNullBytes(): void
	{
		$socket = fopen('php://memory', 'r+');
		$network = $this->network($socket);
		try
		{
			fwrite($socket, "a\0bc");
			rewind($socket);
			$this->assertSame("a\0", $network->bs_read_port(2));
			$this->assertSame('bc', $network->bs_read_port(64));
			$this->assertFalse($network->bs_read_port(1));
		}
		finally
		{
			$network->close_port();
		}
	}

	/**
	 * @dataProvider writeCases
	 */
	public function testWritesPreserveCrlfAndBinaryLength(bool $crlf, int $bytes, string $expected): void
	{
		$socket = fopen('php://memory', 'r+');
		$network = $this->network($socket);
		$network->set_addcrlf($crlf);
		try
		{
			$this->assertSame(1, $network->bs_write_port("a\0bc", $bytes));
			rewind($socket);
			$this->assertSame($expected, stream_get_contents($socket));
		}
		finally
		{
			$network->close_port();
		}
	}

	public static function writeCases(): array
	{
		return [[true, 0, "a\0bc\r\n"], [false, 0, "a\0bc"], [true, 2, "a\0"], [true, 5, "a\0bc\r"]];
	}

	/**
	 * @dataProvider partialWriteCases
	 */
	public function testPartialWritesCompleteOrFailWithoutSpinning(string $method, bool $stop): void
	{
		$this->assertTrue(stream_wrapper_register('networkpartial', NetworkPartialWriteStream::class));
		NetworkPartialWriteStream::$data = '';
		NetworkPartialWriteStream::$stopAfter = $stop ? 3 : null;
		$network = $this->network(fopen('networkpartial://test', 'w'));
		try
		{
			$this->assertSame($stop ? 0 : 1, $network->$method('command'));
			$this->assertSame($stop ? 'com' : "command\r\n", NetworkPartialWriteStream::$data);
			if ($stop)
			{
				$this->assertSame(1, $network->errorset);
			}
		}
		finally
		{
			$network->close_port();
			stream_wrapper_unregister('networkpartial');
		}
	}

	public static function partialWriteCases(): array
	{
		return [['write_port', false], ['write_port', true], ['bs_write_port', false], ['bs_write_port', true]];
	}

	public function testClosingAndUsingAnAlreadyClosedSocketDoesNotThrow(): void
	{
		$network = $this->network(fopen('php://memory', 'r+'));
		$this->assertTrue($network->close_port());
		$this->assertNull($network->socket);
		$this->assertTrue($network->close_port());
		$this->assertFalse($network->read_port());
		$this->assertFalse($network->bs_read_port(1));
		$this->assertSame(0, $network->write_port('command'));
		$this->assertSame(0, $network->bs_write_port('command'));
		$response = null;
		$this->assertSame(0, $network->msg2socket('command', '^OK', $response));
	}

	/**
	 * @dataProvider unexpectedResponses
	 */
	public function testUnexpectedResponseCodeAndDescriptionAreParsed(string $response, string $code, string $description): void
	{
		$network = $this->getMockBuilder(\phpgwapi_network::class)->disableOriginalConstructor()
			->onlyMethods(['write_port', 'read_port'])->getMock();
		$network->socket = fopen('php://memory', 'r+');
		$network->method('write_port')->willReturn(1);
		$network->method('read_port')->willReturn($response);
		try
		{
			$received = null;
			$this->assertSame(0, $network->msg2socket('command', '^200', $received));
			$this->assertSame($response, $received);
			$this->assertSame($code, $network->error['code']);
			$this->assertSame($description, $network->error['desc']);
		}
		finally
		{
			$network->close_port();
		}
	}

	public static function unexpectedResponses(): array
	{
		return [["500 failed\r\n", '500', "failed\r\n"], ["500\r\n", '500', '']];
	}

	public function testCommandReadFailureRetainsTheNetworkError(): void
	{
		$network = $this->getMockBuilder(\phpgwapi_network::class)->disableOriginalConstructor()
			->onlyMethods(['write_port', 'read_port'])->getMock();
		$network->socket = fopen('php://memory', 'r+');
		$network->method('write_port')->willReturn(1);
		$network->method('read_port')->willReturnCallback(static function () use ($network)
		{
			$network->set_error('Error', 'Read Timeout', 'Read timed out.');
			return false;
		});
		try
		{
			$response = null;
			$this->assertSame(0, $network->msg2socket('NOOP', '^200', $response));
			$this->assertFalse($response);
			$this->assertSame('Read Timeout', $network->error['msg']);
		}
		finally
		{
			$network->close_port();
		}
	}

	public function testCommandSuccessPreservesTheServerResponse(): void
	{
		$network = $this->getMockBuilder(\phpgwapi_network::class)->disableOriginalConstructor()
			->onlyMethods(['write_port', 'read_port'])->getMock();
		$network->socket = fopen('php://memory', 'r+');
		$network->expects($this->once())->method('write_port')->with('NOOP')->willReturn(1);
		$network->method('read_port')->willReturn("+OK ready\r\n");
		try
		{
			$response = null;
			$this->assertSame(1, $network->msg2socket('NOOP', '^\\+OK', $response));
			$this->assertSame("+OK ready\r\n", $response);
			$this->assertSame(0, $network->errorset);
		}
		finally
		{
			$network->close_port();
		}
	}

	/**
	 * @dataProvider timedReads
	 */
	public function testReadTimeoutDoesNotReturnPartialSuccess(string $method, string $partial): void
	{
		$sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
		$this->assertIsArray($sockets);
		$network = $this->network($sockets[0]);
		stream_set_timeout($sockets[0], 0, 20000);
		try
		{
			if ($partial !== '')
			{
				fwrite($sockets[1], $partial);
			}
			$this->assertFalse($network->$method(32));
			$this->assertSame('Read Timeout', $network->error['msg']);
			$this->assertSame(1, $network->errorset);
		}
		finally
		{
			$network->close_port();
			fclose($sockets[1]);
		}
	}

	public static function timedReads(): array
	{
		return [['read_port', ''], ['read_port', 'partial'], ['bs_read_port', '']];
	}
}
