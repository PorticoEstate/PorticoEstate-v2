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
		public array $arguments = [];
		public array $rendered = [];
		public static function getInstance(): self { return self::$instance; }
		public static function hasInstance(): bool { return true; }
		public static function releaseInstance(): void { self::$instance->released++; }
		public function get_arg_value($name) { return $this->arguments[$name] ?? ($name === 'msgball' ? ['acctnum' => 2, 'folder' => 'INBOX', 'msgnum' => 5] : null); }
		public function get_folder_status_info(): array { return ['is_imap' => false, 'number_all' => 8, 'number_new' => 0, 'fldball' => ['acctnum' => 2, 'folder' => 'INBOX']]; }
		public function get_msg_list_display($info, $messages, $paged): array {
			$this->rendered = [$messages, $paged];
			return array_map(static fn($message) => ['acctnum' => 2, 'folder' => 'INBOX', 'msgnum' => $message['msgball[msgnum]']], $messages);
		}
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

	class MessageLifecycleLinks
	{
		public static function link($path, $arguments = [], $redirect = false): string { return $path . '?' . http_build_query($arguments); }
	}

	class EmailMessageLifecycleTest extends TestCase
	{
		private function aliasMailService(): void
		{
			class_alias(MessageLifecycleMail::class, \App\modules\email\services\MailMessage::class);
		}

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function testPopInboxUsesDatabasePageBeforeRendering(): void
		{
			\registerEmailMessageLifecycleFunctions();
			$this->aliasMailService();
			class_alias(MessageLifecycleLinks::class, 'phpgw');
			require_once __DIR__ . '/../../src/modules/email/inc/class.mail_dcom_pop3_local.inc.php';
			$settings = new \ReflectionProperty(\App\modules\phpgwapi\services\Settings::class, 'instance');
			$original = $settings->getValue();
			$settings->setValue(null, new class {
				public function get($key): array { return $key === 'user' ? ['preferences' => ['common' => ['maxmatchs' => 2]]] : []; }
			});
			try
			{
				$mail = new MessageLifecycleMail();
				MessageLifecycleMail::$instance = $mail;
				$stream = new \stdClass();
				$mail->arguments = ['start' => 2, 'sort' => 3, 'order' => 0, 'mailsvr_stream' => $stream];
				$mail->transport = $this->getMockBuilder(\mail_dcom_pop3_local::class)->disableOriginalConstructor()->onlyMethods(['page'])->getMock();
				$mail->transport->expects($this->once())->method('page')->with($stream, 3, 0, 2, 2)->willReturn([4, 6]);
				$request = (new ServerRequest('GET', '/email/api/inbox'))->withQueryParams(['fldball' => ['acctnum' => 2, 'folder' => 'INBOX'], 'sort' => 3, 'order' => 0, 'start' => 2]);
				$response = (new EmailInboxController())->index($request, new Response());
				$this->assertSame(200, $response->getStatusCode());
				$data = json_decode((string) $response->getBody(), true);
				$this->assertSame([4, 6], array_column($data['items'], 'messageNumber'));
				$this->assertTrue($mail->rendered[1]);
				$this->assertSame(2, $data['start']);
				$this->assertSame(2, $data['pageSize']);
			}
			finally { $settings->setValue(null, $original); }
		}

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 * @dataProvider protocols
		 */
		public function testMessageOpeningRespectsTransportFlags(bool $supportsFlags, bool $flagResult, int $status): void
		{
			\registerEmailMessageLifecycleFunctions();
			$this->aliasMailService();
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
