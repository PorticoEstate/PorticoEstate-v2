<?php

namespace Tests\Services;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/modules/email/inc/class.mail_msg_base.inc.php';
require_once __DIR__ . '/../../src/modules/email/inc/class.mail_msg_wrappers.inc.php';
require_once __DIR__ . '/../../src/modules/email/inc/class.mail_msg_display.inc.php';

use PHPUnit\Framework\TestCase;

class EmailMessageListTest extends TestCase
{
	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testDisplayReusesCommonServiceButReadsPreferencesForEachRow(): void
	{
		$linker = new class
		{
			public static function link($path, $args, $absolute): string
			{
				return $path . '?' . http_build_query($args);
			}
		};
		class_alias(get_class($linker), 'phpgw');
		$mail = $this->getMockBuilder(\mail_msg::class)->disableOriginalConstructor()
			->onlyMethods(['ball_data_parse_str', 'get_pref_value', 'phpgw_header', 'get_subject',
				'get_arg_value', 'make_flags_str', 'decode_header_string', 'htmlspecialchars_encode',
				'ascii2utf', 'getCommon', 'phpgw_fetchstructure'])->getMock();
		$mail->method('ball_data_parse_str')->willReturnArgument(0);
		$mail->expects($this->exactly(4))->method('get_pref_value')->willReturnOnConsecutiveCalls(
			'pop3', 'from', 'pop3', 'replyto'
		);
		$mail->expects($this->never())->method('phpgw_fetchstructure');
		$from = (object) ['personal' => 'Sender', 'mailbox' => 'sender', 'host' => 'example.test'];
		$reply = (object) ['mailbox' => 'reply', 'host' => 'example.test'];
		$mail->method('phpgw_header')->willReturn((object) [
			'Size' => 1024, 'udate' => 946684800, 'from' => [$from], 'reply_to' => [$reply], 'to' => [$from],
		]);
		$mail->method('get_subject')->willReturn('Subject');
		$mail->method('get_arg_value')->willReturn(0);
		$mail->method('make_flags_str')->willReturn('Seen Answered');
		foreach (['decode_header_string', 'htmlspecialchars_encode', 'ascii2utf'] as $method)
		{
			$mail->method($method)->willReturnArgument(0);
		}
		$common = new class
		{
			public function show_date($timestamp, $format = null): string
			{
				return $format === 'Ymd' ? '20000101' : '2000-01-01 - 12:00';
			}
		};
		$mail->expects($this->once())->method('getCommon')->willReturn($common);
		$messages = [
			['msgnum' => 1, 'folder' => 'INBOX', 'acctnum' => 0, 'uri' => ['msgnum' => 1]],
			['msgnum' => 2, 'folder' => 'Archive', 'acctnum' => 3, 'uri' => ['msgnum' => 2]],
		];
		$rows = $mail->get_msg_list_display(['number_all' => 2, 'is_imap' => false], $messages, true);
		$this->assertCount(2, $rows);
		$this->assertSame('(sender@example.test)', $rows[0]['display_address_from']);
		$this->assertSame('&lt;reply@example.test&gt;', $rows[1]['display_address_from']);
		foreach ($rows as $index => $row)
		{
			$this->assertSame($messages[$index]['acctnum'], $row['acctnum']);
			$this->assertSame($index === 0, $row['first_item']);
			$this->assertFalse($row['has_attachment']);
			$this->assertFalse($row['is_unseen']);
			$this->assertTrue($row['is_answered']);
			$this->assertSame('2000-01-01', $row['msg_date']);
			$this->assertStringContainsString('msgnum=' . ($index + 1), $row['subject_link']);
		}
	}

	/**
	 * @dataProvider accounts
	 */
	public function testExplicitFolderIsUsedForCacheMissSelectionMessagesAndMetadata(int $account): void
	{
		$mail = $this->getMockBuilder(\mail_msg_wrappers::class)->disableOriginalConstructor()
			->onlyMethods(['read_session_cache_item', 'save_session_cache_item',
				'ensure_stream_and_folder', 'get_arg_value'])->getMock();
		$folder = 'Archive%2F2026';
		$mail->expects($this->once())->method('read_session_cache_item')
			->with('msgball_list', $account, $folder)->willReturn(false);
		$mail->expects($this->once())->method('ensure_stream_and_folder')
			->with(['acctnum' => $account, 'folder' => $folder], $this->anything(), false)->willReturn('selected-stream');
		$mail->method('get_arg_value')->willReturnCallback(static function ($key, $selectedAccount) use ($account): mixed
		{
			if ($selectedAccount !== $account)
			{
				throw new \LogicException('Unexpected account');
			}
			return match ($key) {
				'mailsvr_stream' => 'selected-stream',
				'sort' => 0,
				'order' => 1,
				default => throw new \LogicException('Unexpected argument: ' . $key),
			};
		});
		$transport = $this->getMockBuilder(\stdClass::class)->addMethods(['sort'])->getMock();
		$transport->expects($this->once())->method('sort')->with('selected-stream', 0, 1)->willReturn([42, 43]);
		$mail->setTransport($account, $transport);
		$expected = [
			['msgball[msgnum]' => 42, 'msgball[folder]' => $folder, 'msgball[acctnum]' => $account],
			['msgball[msgnum]' => 43, 'msgball[folder]' => $folder, 'msgball[acctnum]' => $account],
		];
		$mail->expects($this->once())->method('save_session_cache_item')->with('msgball_list', [
			'msgball_list' => $expected, 'validity' => ['fldball' => ['acctnum' => $account, 'folder' => $folder]],
		], $account, $folder);
		$this->assertSame($expected, $mail->get_msgball_list($account, $folder, false, false));
	}

	public static function accounts(): array
	{
		return [[0], [3]];
	}

	public function testCacheHitDoesNotSelectOrSortFolder(): void
	{
		$mail = $this->getMockBuilder(\mail_msg_wrappers::class)->disableOriginalConstructor()
			->onlyMethods(['read_session_cache_item', 'ensure_stream_and_folder'])->getMock();
		$cached = [['msgball[msgnum]' => 42, 'msgball[folder]' => 'Archive', 'msgball[acctnum]' => 3]];
		$mail->expects($this->once())->method('read_session_cache_item')
			->with('msgball_list', 3, 'Archive')->willReturn($cached);
		$mail->expects($this->never())->method('ensure_stream_and_folder');
		$this->assertSame($cached, $mail->get_msgball_list(3, 'Archive'));
	}

	public function testFailedFolderSelectionDoesNotSortOrCacheMessages(): void
	{
		$mail = $this->getMockBuilder(\mail_msg_wrappers::class)->disableOriginalConstructor()
			->onlyMethods(['read_session_cache_item', 'ensure_stream_and_folder', 'save_session_cache_item'])->getMock();
		$mail->method('read_session_cache_item')->willReturn(false);
		$mail->expects($this->once())->method('ensure_stream_and_folder')
			->with(['acctnum' => 3, 'folder' => 'Archive'], $this->anything(), false)->willReturn(false);
		$mail->expects($this->never())->method('save_session_cache_item');
		$this->assertFalse($mail->get_msgball_list_oldschool(3, 'Archive', false, false));
	}
}
