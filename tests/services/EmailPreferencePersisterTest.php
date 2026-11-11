<?php

namespace Tests\Services;

require_once __DIR__ . '/../../vendor/autoload.php';

use App\modules\email\services\EmailPreferencePersister;
use PHPUnit\Framework\TestCase;

class EmailPreferencePersisterTest extends TestCase
{
	public function testPreservesLegacyDefaultPreferenceEncoding(): void
	{
		$preferences = $this->createPreferencesDouble();
		$mail = $this->createMailDouble();
		$persister = new EmailPreferencePersister();

		$persister->writeFields(
			[
				['id' => 'signature', 'type' => 'user_string', 'accts_usage' => 'default'],
				['id' => 'password', 'type' => 'user_string', 'write_props' => 'encrypted', 'accts_usage' => 'default'],
				['id' => 'server_port', 'type' => 'user_string', 'write_props' => 'no_db_defang', 'accts_usage' => 'default'],
			],
			['signature' => 'signature', 'password' => 'password', 'server_port' => '1143'],
			$preferences,
			$mail,
			null,
			true,
			false
		);

		$this->assertContains(['add', 'email', 'signature', 'encoded:stripped:signature'], $preferences->writes);
		$this->assertContains(['add', 'email', 'password', 'encoded:encrypted:stripped:password'], $preferences->writes);
		$this->assertContains(['add', 'email', 'server_port', '1143'], $preferences->writes);
	}

	public function testPreservesLegacyExtraAccountPasswordEncoding(): void
	{
		$preferences = $this->createPreferencesDouble();
		$mail = $this->createMailDouble();

		(new EmailPreferencePersister())->writeFields(
			[['id' => 'password', 'type' => 'user_string', 'write_props' => 'encrypted', 'accts_usage' => 'extra_accounts']],
			['password' => 'password'],
			$preferences,
			$mail,
			2,
			true,
			false
		);

		$this->assertContains([
			'add_struct',
			'email',
			'["ex_accounts"][2]["password"]',
			'encrypted:stripped:password',
		], $preferences->writes);
	}

	private function createPreferencesDouble(): object
	{
		return new class
		{
			public array $writes = [];

			public function delete($app, $id): void { $this->writes[] = ['delete', $app, $id]; }
			public function add($app, $id, $value): void { $this->writes[] = ['add', $app, $id, $value]; }
			public function delete_struct($app, $path): void { $this->writes[] = ['delete_struct', $app, $path]; }
			public function add_struct($app, $path, $value): void { $this->writes[] = ['add_struct', $app, $path, $value]; }
		};
	}

	private function createMailDouble(): object
	{
		return new class
		{
			public function stripslashes_gpc($value): string { return 'stripped:' . $value; }
			public function encrypt_email_passwd($value): string { return 'encrypted:' . $value; }
			public function html_quotes_encode($value): string { return 'encoded:' . $value; }
		};
	}
}
