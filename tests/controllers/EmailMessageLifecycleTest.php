<?php

namespace
{
	function registerEmailMessageLifecycleFunctions(): void
	{
		if (!function_exists('CreateObject'))
		{
			function CreateObject($name)
			{
				if ($name !== 'email.msg_bootstrap') throw new \LogicException('Unexpected object: ' . $name);
				return new class {
					public function ensure_mail_msg_exists($caller): void {}
				};
			}
		}
		if (!function_exists('lang'))
		{
			function lang(string $message): string { return $message; }
		}
	}
}

namespace Tests\Controllers
{
	require_once __DIR__ . '/../../vendor/autoload.php';

	use App\modules\email\controllers\EmailInboxController;
	use Nyholm\Psr7\Response;
	use Nyholm\Psr7\ServerRequest;
	use PHPUnit\Framework\TestCase;

	class MessageLifecycleMail
	{
		public static self $instance;
		public object $transport;
		public bool $flagResult = true;
		public int $flagCalls = 0;
		public int $closed = 0;
		public int $released = 0;
		public static function getInstance(): self { return self::$instance; }
		public static function hasInstance(): bool { return true; }
		public static function releaseInstance(): void { self::$instance->released++; }
		public function get_arg_value($name) { return $name === 'msgball' ? ['acctnum' => 2, 'folder' => 'INBOX', 'msgnum' => 5] : null; }
		public function getTransport($account): object { return $this->transport; }
		public function phpgw_header($message): object { return (object) ['Draft' => '']; }
		public function phpgw_set_flag($message, $flag, $display): bool { $this->flagCalls++; return $this->flagResult; }
		public function expire_session_cache_item(...$arguments): void {}
		public function prep_folder_in($folder, $account): string { return $folder; }
		public function prev_next_navigation(): array { return []; }
		public function end_request(): void { $this->closed++; }
	}

	class MessageLifecycleContent
	{
		public function build($mail, $message, $headers, $attachmentUrl): array
		{
			return [
				'data' => ['account' => 2, 'folder' => 'INBOX', 'messageNumber' => 5, 'message_subject' => 'Fixture message', 'message_date' => '2026-10-27'],
				'parts' => [],
			];
		}
	}

	class EmailMessageLifecycleTest extends TestCase
	{
		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 * @dataProvider protocols
		 */
		public function testMessageOpeningRespectsTransportFlags(bool $supportsFlags, bool $flagResult, int $status): void
		{
			\registerEmailMessageLifecycleFunctions();
			class_alias(MessageLifecycleMail::class, \App\modules\email\services\MailMessage::class);
			class_alias(MessageLifecycleContent::class, \App\modules\email\services\EmailMessageContent::class);
			$mail = new MessageLifecycleMail();
			MessageLifecycleMail::$instance = $mail;
			$mail->transport = $supportsFlags ? new class {
				public function set_flag(): void {}
			} : new \stdClass();
			$mail->flagResult = $flagResult;
			$request = (new ServerRequest('GET', '/email/api/message'))->withQueryParams([
				'msgball' => ['acctnum' => 2, 'folder' => 'INBOX', 'msgnum' => 5],
			]);
			$response = (new EmailInboxController())->message($request, new Response());
			$this->assertSame($status, $response->getStatusCode());
			$this->assertSame($supportsFlags ? 1 : 0, $mail->flagCalls);
			$this->assertSame(1, $mail->closed);
			$this->assertSame(1, $mail->released);
			if ($status === 200)
			{
				$data = json_decode((string) $response->getBody(), true);
				$this->assertSame(5, $data['item']['messageNumber']);
			}
		}

		public static function protocols(): array
		{
			return ['POP' => [false, false, 200], 'IMAP' => [true, true, 200], 'IMAP flag failure' => [true, false, 422]];
		}
	}
}
