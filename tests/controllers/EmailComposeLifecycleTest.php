<?php

namespace
{
	function CreateObject($name)
	{
		if ($name !== 'email.msg_bootstrap')
		{
			throw new \LogicException('Unexpected object: ' . $name);
		}
		return new class
		{
			public function ensure_mail_msg_exists($caller): void
			{
				throw new \InvalidArgumentException('bootstrap-error');
			}
		};
	}
}

namespace Tests\Controllers
{
	require_once __DIR__ . '/../../vendor/autoload.php';

	use App\modules\email\controllers\EmailComposeController;
	use Nyholm\Psr7\Response;
	use Nyholm\Psr7\ServerRequest;
	use PHPUnit\Framework\TestCase;

	class ComposeLifecycleMail
	{
		public static int $releases = 0;
		public static bool $fail = false;

		public static function releaseInstance(): void
		{
			self::$releases++;
			if (self::$fail)
			{
				throw new \RuntimeException('cleanup-error');
			}
		}
	}

	class EmailComposeLifecycleTest extends TestCase
	{
		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 * @dataProvider scenarios
		 */
		public function testReleaseAndGlobalRestoration(string $action, bool $cleanupFails): void
		{
			class_alias(ComposeLifecycleMail::class, \App\modules\email\services\MailMessage::class);
			ComposeLifecycleMail::$fail = $cleanupFails;
			$originalGet = $_GET;
			$originalPost = $_POST;
			$originalRequest = $_REQUEST;
			$_GET = ['original-get' => true];
			$_POST = ['original-post' => true];
			$_REQUEST = ['original-request' => true];
			try
			{
				$request = (new ServerRequest('POST', '/email/api/' . $action))
					->withQueryParams(['fldball' => ['acctnum' => 3, 'folder' => 'Archive']])
					->withParsedBody(['to' => 'recipient@example.test', 'fldball' => ['acctnum' => 3, 'folder' => 'INBOX']]);
				try
				{
					$response = (new EmailComposeController())->$action($request, new Response());
					$this->assertFalse($cleanupFails);
					$this->assertSame(422, $response->getStatusCode());
					$this->assertSame(['error' => 'bootstrap-error'], json_decode((string) $response->getBody(), true));
				}
				catch (\RuntimeException $exception)
				{
					$this->assertTrue($cleanupFails);
					$this->assertSame('cleanup-error', $exception->getMessage());
				}
				$this->assertSame(1, ComposeLifecycleMail::$releases);
				$this->assertSame(['original-get' => true], $_GET);
				$this->assertSame(['original-post' => true], $_POST);
				$this->assertSame(['original-request' => true], $_REQUEST);
			}
			finally
			{
				$_GET = $originalGet;
				$_POST = $originalPost;
				$_REQUEST = $originalRequest;
			}
		}

		public static function scenarios(): array
		{
			return [
				['compose', false], ['compose', true],
				['draft', false], ['draft', true],
				['send', false], ['send', true],
			];
		}
	}
}
