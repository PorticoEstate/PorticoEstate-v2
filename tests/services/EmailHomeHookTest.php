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
			public function set_do_login($mode): void {}
			public function ensure_mail_msg_exists($caller, $account): void {}
		};
	}
}

namespace Tests\Services
{
	require_once __DIR__ . '/../../vendor/autoload.php';

	use PHPUnit\Framework\TestCase;

	class HomeHookSettings
	{
		public static self $instance;
		public array $flags = ['currentapp' => 'home'];
		public static function getInstance(): self { return self::$instance; }
		public function get($key): array { return $this->flags; }
		public function update($key, $values): void { $this->flags = array_merge($this->flags, $values); }
	}

	class HomeHookMail
	{
		public static self $instance;
		public string $scenario = '';
		public int $closed = 0;
		public static function getInstance(): self { return self::$instance; }
		public function get_pref_value($key, $account): string
		{
			if ($this->scenario === 'load-error')
			{
				throw new \RuntimeException('load-error');
			}
			return 'False';
		}
		public function end_request(): void
		{
			$this->closed++;
			if ($this->scenario === 'cleanup-error')
			{
				throw new \RuntimeException('cleanup-error');
			}
		}
	}

	class EmailHomeHookTest extends TestCase
	{
		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 * @dataProvider scenarios
		 */
		public function testHookRestoresApplicationAndClosesRequest(string $scenario): void
		{
			class_alias(HomeHookSettings::class, \App\modules\phpgwapi\services\Settings::class);
			class_alias(HomeHookMail::class, \App\modules\email\services\MailMessage::class);
			if (!defined('BS_LOGIN_NEVER'))
			{
				define('BS_LOGIN_NEVER', 0);
			}
			HomeHookSettings::$instance = new HomeHookSettings();
			HomeHookMail::$instance = new HomeHookMail();
			HomeHookMail::$instance->scenario = $scenario;
			$settingsInstance = HomeHookSettings::$instance;
			$mailInstance = HomeHookMail::$instance;
			try
			{
				require __DIR__ . '/../../src/modules/email/inc/hook_home.inc.php';
				$this->assertSame('disabled', $scenario);
			}
			catch (\RuntimeException $exception)
			{
				$this->assertSame($scenario, $exception->getMessage());
			}
			$this->assertSame('home', $settingsInstance->flags['currentapp']);
			$this->assertSame(1, $mailInstance->closed);
		}

		public static function scenarios(): array
		{
			return [['disabled'], ['load-error'], ['cleanup-error']];
		}
	}
}
