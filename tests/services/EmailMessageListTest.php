<?php

namespace Tests\Services;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/modules/email/inc/class.mail_msg_base.inc.php';
require_once __DIR__ . '/../../src/modules/email/inc/class.mail_msg_wrappers.inc.php';

use PHPUnit\Framework\TestCase;

class EmailMessageListTest extends TestCase
{
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
