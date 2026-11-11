<?php

namespace
{
	if (!function_exists('lang'))
	{
		function lang(string $value): string { return $value; }
	}
}

namespace Tests\Services
{

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/modules/email/inc/class.mail_msg_base.inc.php';
require_once __DIR__ . '/../../src/modules/email/inc/class.mail_msg_wrappers.inc.php';

use App\modules\email\services\EmailMailConfigurationException;
use PHPUnit\Framework\TestCase;

class EmailTrashFolderTest extends TestCase
{
	private function mail(?string $folder): \mail_msg_wrappers
	{
		$mail = $this->getMockBuilder(\mail_msg_wrappers::class)
			->disableOriginalConstructor()
			->onlyMethods([
				'_direct_access_arg_value', 'get_mailsvr_supports_folders',
				'folder_lookup', 'get_arg_value', 'phpgw_createmailbox_ex',
				'get_folder_list', 'getTransport', 'set_arg_value',
			])
			->getMock();
		$mail->method('_direct_access_arg_value')->willReturn(null);
		$mail->method('get_mailsvr_supports_folders')->with(0)->willReturn(true);
		$mail->set_pref_value('use_trash_folder', 'True', 0);
		if ($folder !== null)
		{
			$mail->set_pref_value('trash_folder_name', $folder, 0);
		}
		$mail->method('get_arg_value')->willReturnMap([
			['mailsvr_namespace', 0, '', true, 'INBOX'],
			['mailsvr_delimiter', 0, '', true, '.'],
		]);
		return $mail;
	}

	/**
	 * @dataProvider defaultFolderNames
	 */
	public function testMissingOrBlankFolderNameUsesDefaultTrash(?string $folder): void
	{
		$mail = $this->mail($folder);
		$this->assertSame('Trash', $mail->get_pref_value('trash_folder_name', 0));
		$mail->expects($this->once())->method('folder_lookup')->with('', 'Trash', 0)->willReturn('INBOX.Trash');
		$mail->expects($this->never())->method('phpgw_createmailbox_ex');
		$this->assertSame('INBOX.Trash', $mail->get_verified_trash_folder_long(0));
	}

	public static function defaultFolderNames(): array
	{
		return [[''], [' '], [null]];
	}

	public function testCustomTrashNameIsPreservedPerAccount(): void
	{
		$mail = $this->mail('Deleted Items');
		$mail->set_pref_value('trash_folder_name', 'Archive.Deleted', 1);
		$this->assertSame('Deleted Items', $mail->get_pref_value('trash_folder_name', 0));
		$this->assertSame('Archive.Deleted', $mail->get_pref_value('trash_folder_name', 1));
		$this->assertSame('Trash', $mail->get_pref_value('trash_folder_name', 2));
		$mail->expects($this->once())->method('folder_lookup')->with('', 'Deleted Items', 0)->willReturn('INBOX.Deleted Items');
		$this->assertSame('INBOX.Deleted Items', $mail->get_verified_trash_folder_long(0));
	}

	public function testExistingTrashUsesRequestedAccountWithoutCreatingMailbox(): void
	{
		$mail = $this->mail('Trash');
		$mail->expects($this->once())->method('folder_lookup')->with('', 'Trash', 0)->willReturn('INBOX.Trash');
		$mail->expects($this->never())->method('phpgw_createmailbox_ex');
		$this->assertSame('INBOX.Trash', $mail->get_verified_trash_folder_long(0));
	}

	public function testCreationRefreshesFolderListForRequestedAccount(): void
	{
		$mail = $this->mail(null);
		$mail->expects($this->exactly(2))->method('folder_lookup')->with('', 'Trash', 0)
			->willReturnOnConsecutiveCalls('', 'INBOX.Trash');
		$mail->expects($this->once())->method('phpgw_createmailbox_ex')
			->with(['folder' => 'INBOX.Trash', 'acctnum' => 0], false)->willReturn(true);
		$mail->expects($this->once())->method('get_folder_list')->with(0, true);
		$this->assertSame('INBOX.Trash', $mail->get_verified_trash_folder_long(0, false));
	}

	public function testCreationFailurePreservesServerErrorBeforeFolderRefresh(): void
	{
		$mail = $this->mail('Trash');
		$mail->method('folder_lookup')->willReturn('');
		$mail->method('phpgw_createmailbox_ex')->willReturn(false);
		$transport = new class
		{
			public string $error = 'Permission denied';
			public function server_last_error(): string
			{
				return $this->error;
			}
		};
		$mail->method('getTransport')->with(0)->willReturn($transport);
		$mail->method('get_folder_list')->willReturnCallback(static function () use ($transport): array
		{
			$transport->error = '';
			return [];
		});
		$this->expectException(EmailMailConfigurationException::class);
		$this->expectExceptionMessage('Unable to resolve Trash folder "INBOX.Trash" for account 0. The server did not create the mailbox. Permission denied');
		$mail->get_verified_trash_folder_long(0, false);
	}

	public function testSuccessfulCreationWithMissingFolderReportsLookupFailure(): void
	{
		$mail = $this->mail('Trash');
		$mail->method('folder_lookup')->willReturn('');
		$mail->method('phpgw_createmailbox_ex')->willReturn(true);
		$this->expectException(EmailMailConfigurationException::class);
		$this->expectExceptionMessage('The server accepted mailbox creation, but the folder is missing from its refreshed folder list.');
		$mail->get_verified_trash_folder_long(0, false);
	}
}
}
