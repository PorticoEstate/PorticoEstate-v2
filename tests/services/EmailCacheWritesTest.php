<?php

namespace Tests\Services;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/modules/email/inc/class.mail_msg_base.inc.php';
require_once __DIR__ . '/../../src/modules/email/inc/class.mail_msg_wrappers.inc.php';

use PHPUnit\Framework\TestCase;

class EmailCacheWritesTest extends TestCase
{
	public function testNoOpOperationsDoNotPersistSessionState(): void
	{
		$mail = $this->getMockBuilder(\mail_msg_wrappers::class)->disableOriginalConstructor()
			->onlyMethods(['persistSessionCache'])->getMock();
		$mail->mail_msg_wrappers = $mail;
		$mail->session_cache_enabled = true;
		$mail->session_cache_extreme = false;
		$mail->expects($this->never())->method('persistSessionCache');
		$this->assertFalse($mail->save_session_cache_item('folder_list', [], 0));
		$this->assertFalse($mail->save_session_cache_item('folder_status_info', ['number_all' => 1], 0));
		$this->assertFalse($mail->event_msg_seen(['acctnum' => 0, 'folder' => 'INBOX', 'msgnum' => 1]));
		$mail->session_cache_enabled = false;
		$this->assertFalse($mail->save_session_cache_item('folder_list', ['INBOX'], 0));
		$this->assertFalse($mail->event_msg_seen(['acctnum' => 0, 'folder' => 'INBOX', 'msgnum' => 1]));
	}

	public function testMetadataSaveStillPersistsImmediately(): void
	{
		$mail = $this->getMockBuilder(\mail_msg_wrappers::class)->disableOriginalConstructor()
			->onlyMethods(['persistSessionCache'])->getMock();
		$mail->session_cache_enabled = false;
		$cache = &$mail->getSessionCache();
		$mail->session_cache_enabled = true;
		$mail->expects($this->once())->method('persistSessionCache')->willReturnCallback(
			function () use (&$cache): bool
			{
				$this->assertSame(['INBOX'], $cache[3]['folder_list']);
				return true;
			}
		);
		$this->assertTrue($mail->save_session_cache_item('folder_list', ['INBOX'], 3));
	}

	public function testExpirationStillPersistsImmediatelyAndKeepsOtherAccounts(): void
	{
		$mail = $this->getMockBuilder(\mail_msg_wrappers::class)->disableOriginalConstructor()
			->onlyMethods(['persistSessionCache', 'get_isset_arg'])->getMock();
		$mail->session_cache_enabled = false;
		$cache = &$mail->getSessionCache();
		$cache = [0 => ['folder_list' => ['INBOX']], 3 => ['folder_list' => ['Archive']]];
		$mail->session_cache_enabled = true;
		$mail->session_cache_extreme = true;
		$mail->method('get_isset_arg')->willReturn(false);
		$mail->expects($this->once())->method('persistSessionCache')->willReturnCallback(
			function () use (&$cache): bool
			{
				$this->assertArrayNotHasKey('folder_list', $cache[3]);
				$this->assertSame(['INBOX'], $cache[0]['folder_list']);
				return true;
			}
		);
		$this->assertTrue($mail->expire_session_cache_item('folder_list', 3));
	}
}
