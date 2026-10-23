<?php

namespace Tests\Services;

require_once __DIR__ . '/../../vendor/autoload.php';

use App\modules\email\services\EmailMessageActions;
use PHPUnit\Framework\TestCase;

class EmailMessageActionsTest extends TestCase
{
	public function testDestructiveOperationsRequireExplicitConfirmation(): void
	{
		$input = ['action' => 'delete', 'account' => 0, 'messages' => [['acctnum' => 0, 'folder' => 'INBOX', 'msgnum' => 5]]];
		$this->assertNull(EmailMessageActions::normalize($input));
		$this->assertNull(EmailMessageActions::normalize($input + ['confirmed' => 'true']));
		$this->assertNotNull(EmailMessageActions::normalize($input + ['confirmed' => true]));
		$this->assertNull(EmailMessageActions::normalize(['action' => 'emptyTrash', 'account' => 0]));
		$this->assertNotNull(EmailMessageActions::normalize(['action' => 'emptyTrash', 'account' => 0, 'confirmed' => true]));
	}

	public function testNormalizesCrossAccountMovesAndDeduplicatesMessages(): void
	{
		$message = ['acctnum' => '0', 'folder' => 'Projects.Source', 'msgnum' => '5'];
		$request = EmailMessageActions::normalize(['action' => 'move', 'account' => '0', 'messages' => [$message, $message],
			'target_fldball' => ['acctnum' => '2', 'folder' => 'Projects.Target']]);
		$this->assertCount(1, $request['messages']);
		$this->assertSame(['acctnum' => 0, 'folder' => 'Projects.Source', 'msgnum' => 5], $request['messages'][0]);
		$this->assertSame(['acctnum' => 2, 'folder' => 'Projects.Target'], $request['target']);
	}

	public function testRejectsOversizedBatchesAndMismatchedSourceAccounts(): void
	{
		$input = ['action' => 'delete', 'account' => 0, 'confirmed' => true];
		$message = ['acctnum' => 0, 'folder' => 'INBOX', 'msgnum' => 5];
		$this->assertNull(EmailMessageActions::normalize($input + ['messages' => array_fill(0, 101, $message)]));
		$message['acctnum'] = 2;
		$this->assertNull(EmailMessageActions::normalize($input + ['messages' => [$message]]));
		$message['acctnum'] = 0;
		$message['folder'] = "INBOX\r\nOther";
		$this->assertNull(EmailMessageActions::normalize($input + ['messages' => [$message]]));
	}

	public function testMovesDescendingAndReportsMissingMessagesWithoutAbortingBatch(): void
	{
		$mail = new class
		{
			public array $extra_and_default_acounts = [['acctnum' => 0, 'status' => 'enabled']];
			public array $expired = [];
			public object $transport;
			public function __construct()
			{
				$this->transport = new class
				{
					public bool $force_msg_uids = true;
					public array $moved = [];
					public function mail_move($stream, $number, $folder): bool { $this->moved[] = (int) $number; return true; }
				};
			}
			public function get_folder_list($account, $refresh): array { return [['folder_long' => 'Source'], ['folder_long' => 'Target']]; }
			public function get_arg_value($key, $account): string
			{
				if ($key === 'verified_trash_folder_long') throw new \LogicException('Move must not resolve Trash');
				return $key === 'mailsvr_stream' ? 'imap_test' : '';
			}
			public function prep_folder_out($folder): string { return $folder; }
			public function phpgw_header($message, $errors) { return $message['msgnum'] === 999 ? false : (object) []; }
			public function ensure_stream_and_folder($message, $operation, $errors): bool { return true; }
			public function getTransport($account): object { return $this->transport; }
			public function expire_session_cache_item($key, $account, $folder = ''): void { $this->expired[] = [$key, $account, $folder]; }
		};
		$request = EmailMessageActions::normalize(['action' => 'move', 'account' => 0,
			'messages' => array_map(static fn($number) => ['acctnum' => 0, 'folder' => 'Source', 'msgnum' => $number], [2, 999, 8]),
			'target_fldball' => ['acctnum' => 0, 'folder' => 'Target']]);
		$result = (new EmailMessageActions())->execute($mail, $request);
		$this->assertSame([8, 2], $mail->transport->moved);
		$this->assertCount(2, $result['completed']);
		$this->assertCount(1, $result['failed']);
		$this->assertSame('Message no longer exists', $result['failed'][0]['error']);
		$this->assertFalse($result['failed'][0]['copied']);
		$this->assertContains(['msgball_list', 0, ''], $mail->expired);
		$this->assertContains(['phpgw_header', 0, 'Source'], $mail->expired);
		$this->assertContains(['folder_status_info', 0, 'Target'], $mail->expired);
	}
}