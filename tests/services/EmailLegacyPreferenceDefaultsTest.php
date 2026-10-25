<?php

namespace App\modules\phpgwapi\services
{
	function createObject(string $name): object
	{
		if ($name !== 'email.mail_msg')
		{
			throw new \LogicException('Unexpected legacy object creation: ' . $name);
		}
		return (new \ReflectionClass(\mail_msg_wrappers::class))->newInstanceWithoutConstructor();
	}

	function print_debug(...$arguments): void {}
}

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

	use App\modules\phpgwapi\services\Preferences;
	use App\modules\phpgwapi\services\Settings;
	use PHPUnit\Framework\TestCase;

	class LegacyPreferenceAccountsDouble
	{
		public function get($accountId): string
		{
			return 'Target &quot;Name&quot;';
		}

		public function id2lid($accountId): string
		{
			if ($accountId !== 99)
			{
				throw new \LogicException('Defaults must use the requested account identity');
			}
			return 'target';
		}
	}

	class EmailLegacyPreferenceDefaultsTest extends TestCase
	{
		private mixed $originalSettings;

		protected function setUp(): void
		{
			$property = new \ReflectionProperty(Settings::class, 'instance');
			$property->setAccessible(true);
			$this->originalSettings = $property->getValue();
			$property->setValue(null, (new \ReflectionClass(Settings::class))->newInstanceWithoutConstructor());
		}

		protected function tearDown(): void
		{
			$property = new \ReflectionProperty(Settings::class, 'instance');
			$property->setAccessible(true);
			$property->setValue(null, $this->originalSettings);
		}

		private function preferences(): Preferences
		{
			$preferences = (new \ReflectionClass(Preferences::class))->newInstanceWithoutConstructor();
			$preferences->account_id = 12;
			$preferences->data = ['calendar' => ['unchanged' => true], 'email' => ['ex_accounts' => [
				2 => ['userid' => 'custom-user', 'address' => 'custom@example.test',
					'email_sig' => 'A&#039;s signature', 'passwd' => 'encrypted&#047;value',
					'use_trash_folder' => 'True', 'use_sent_folder' => 'True',
					'trash_folder_name' => ' ', 'default_sorting' => 'old_new'],
			]]];
			foreach ([
				'userSettings' => ['fullname' => 'Default &quot;Name&quot;', 'account_lid' => 'current'],
				'serverSettings' => ['mail_server_type' => 'imaps', 'mail_server' => 'imap.example.test',
					'imap_server_type' => 'Cyrus'],
			] as $name => $value)
			{
				$property = new \ReflectionProperty(Preferences::class, $name);
				$property->setAccessible(true);
				$property->setValue($preferences, $value);
			}
			return $preferences;
		}

		public function testLegacyExtraAccountUsesSharedDefaultsWithoutMutatingStoredData(): void
		{
			$preferences = $this->preferences();
			$stored = $preferences->data;
			$result = $preferences->create_email_preferences('', 2)['email'];
			$this->assertSame('Trash', $result['trash_folder_name']);
			$this->assertSame('Sent', $result['sent_folder_name']);
			$this->assertSame('Drafts', $result['draft_folder_name']);
			$this->assertSame('old_new', $result['default_sorting']);
			$this->assertSame('custom-user', $result['userid']);
			$this->assertSame('custom@example.test', $result['address']);
			$this->assertSame("A's signature", $result['email_sig']);
			$this->assertSame('encrypted/value', $result['passwd']);
			$this->assertSame('Default &quot;Name&quot;', $result['fullname']);
			$this->assertSame('imap.example.test', $result['mail_server']);
			$this->assertSame('True', $result['use_trash_folder']);
			$this->assertArrayNotHasKey('use_draft_folder', $result);
			$this->assertArrayNotHasKey('show_addresses', $result);
			$this->assertSame($stored, $preferences->data);
		}

		public function testPopAccountRetainsLegacyFolderSafetyCheck(): void
		{
			$preferences = $this->preferences();
			$preferences->data['email']['ex_accounts'][2]['mail_server_type'] = 'pop3';
			$result = $preferences->create_email_preferences(12, 2)['email'];
			$this->assertArrayNotHasKey('use_trash_folder', $result);
			$this->assertArrayNotHasKey('use_sent_folder', $result);
		}

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function testDefaultAccountUsesTargetIdentityAndDefaultScope(): void
		{
			class_alias(LegacyPreferenceAccountsDouble::class, \App\modules\phpgwapi\controllers\Accounts\Accounts::class);
			$preferences = $this->preferences();
			$preferences->account_id = 99;
			$preferences->data['email'] = [];
			$property = new \ReflectionProperty(Preferences::class, 'serverSettings');
			$property->setAccessible(true);
			$property->setValue($preferences, ['mail_server_type' => 'imap', 'mail_server' => 'imap.example.test',
				'mail_login_type' => 'ispman', 'mail_suffix' => 'example.test']);
			$result = $preferences->create_email_preferences(99)['email'];
			$this->assertSame('target_example_test', $result['userid']);
			$this->assertSame('target@example.test', $result['address']);
			$this->assertSame('Target "Name"', $result['fullname']);
			$this->assertSame('Trash', $result['trash_folder_name']);
			$this->assertSame('new_old', $result['default_sorting']);
			$this->assertSame('none', $result['show_addresses']);
			$this->assertArrayNotHasKey('use_draft_folder', $result);
			$this->assertArrayNotHasKey('passwd', $result);
			$this->assertSame([], $preferences->data['email']);
		}

		public function testReadingAnotherUserRestoresStateEvenWhenRepositoryReadFails(): void
		{
			$preferences = $this->preferences();
			$preferences->data = ['email' => ['account_name' => 'Original']];
			$database = new class
			{
				public function prepare(string $sql): never
				{
					throw new \RuntimeException('Repository read failed');
				}
			};
			$property = new \ReflectionProperty(Preferences::class, 'db');
			$property->setAccessible(true);
			$property->setValue($preferences, $database);
			try
			{
				$preferences->create_email_preferences(99, 2);
				$this->fail('Expected repository error');
			}
			catch (\RuntimeException $exception)
			{
				$this->assertSame('Repository read failed', $exception->getMessage());
			}
			$this->assertSame(12, $preferences->account_id);
			$this->assertSame(['email' => ['account_name' => 'Original']], $preferences->data);
		}
	}
}
