<?php

namespace Tests\Services;

require_once __DIR__ . '/../../vendor/autoload.php';

use App\modules\email\services\EmailPreferencesWriter;
use PHPUnit\Framework\TestCase;

class EmailPreferencesWriterTest extends TestCase
{
	public function testWritesValuesWithLegacyEmptyAndEncodingRules(): void
	{
		$preferences = new class
		{
			public array $writes = [];
			public function delete($app, $id): void { $this->writes[] = ['delete', $app, $id]; }
			public function add($app, $id, $value): void { $this->writes[] = ['add', $app, $id, $value]; }
			public function save_repository(): void { $this->writes[] = ['save']; }
		};
		$mail = new class
		{
			public function html_quotes_encode($value): string { return 'encoded:' . $value; }
			public function encrypt_email_passwd($value): string { return 'encrypted:' . $value; }
		};
		$standardSchema = [
			['id' => 'signature', 'type' => 'user_string', 'widget' => 'textbox', 'accts_usage' => 'default', 'write_props' => 'empty_string_ok'],
			['id' => 'keep_password', 'type' => 'user_string', 'widget' => 'passwordbox', 'accts_usage' => 'default', 'write_props' => 'empty_no_delete, encrypted'],
			['id' => 'password', 'type' => 'user_string', 'widget' => 'passwordbox', 'accts_usage' => 'default', 'write_props' => 'encrypted'],
			['id' => 'enabled', 'type' => 'exists', 'widget' => 'checkbox', 'accts_usage' => 'default', 'write_props' => ''],
			['id' => 'extra_only', 'type' => 'user_string', 'widget' => 'textbox', 'accts_usage' => 'extra_accounts', 'write_props' => ''],
		];
		$customSchema = [
			['id' => 'use_custom_settings', 'type' => 'exists', 'widget' => 'checkbox', 'accts_usage' => 'default', 'write_props' => ''],
			['id' => 'custom_value', 'type' => 'user_string', 'widget' => 'textbox', 'accts_usage' => 'default', 'write_props' => ''],
		];
		(new EmailPreferencesWriter())->saveDefault($standardSchema, $customSchema, [
			'signature' => '', 'keep_password' => '', 'password' => 'secret', 'enabled' => true,
			'use_custom_settings' => false, 'custom_value' => 'ignored',
		], $preferences, $mail);
		$this->assertContains(['add', 'email', 'signature', ''], $preferences->writes);
		$this->assertNotContains(['delete', 'email', 'keep_password'], $preferences->writes);
		$this->assertContains(['add', 'email', 'password', 'encoded:encrypted:secret'], $preferences->writes);
		$this->assertContains(['add', 'email', 'enabled', 'True'], $preferences->writes);
		$this->assertContains(['delete', 'email', 'custom_value'], $preferences->writes);
		$this->assertNotContains(['delete', 'email', 'extra_only'], $preferences->writes);
		$this->assertSame(['save'], end($preferences->writes));
	}

	public function testAllocatesTheFirstUnusedAccountNumber(): void
	{
		$this->assertSame(1, EmailPreferencesWriter::nextAccountNumber([]));
		$this->assertSame(2, EmailPreferencesWriter::nextAccountNumber([1 => [], 3 => []]));
		$this->assertSame(3, EmailPreferencesWriter::nextAccountNumber([1 => [], 2 => []]));
	}

	public function testWritesExtraAccountValuesToNestedPreferencePaths(): void
	{
		$preferences = new class
		{
			public array $writes = [];
			public function delete_struct($app, $path): void { $this->writes[] = ['delete', $app, $path]; }
			public function add_struct($app, $path, $value): void { $this->writes[] = ['add', $app, $path, $value]; }
			public function sort_struct($app, $path): void { $this->writes[] = ['sort', $app, $path]; }
			public function save_repository(): void { $this->writes[] = ['save']; }
		};
		$mail = new class
		{
			public function html_quotes_encode($value): string { return 'encoded:' . $value; }
			public function encrypt_email_passwd($value): string { return 'encrypted:' . $value; }
		};
		$standard = [
			['id' => 'userid', 'type' => 'user_string', 'widget' => 'textbox', 'accts_usage' => 'extra_accounts', 'write_props' => ''],
			['id' => 'password', 'type' => 'user_string', 'widget' => 'passwordbox', 'accts_usage' => 'extra_accounts', 'write_props' => 'encrypted'],
			['id' => 'email_sig', 'type' => 'user_string', 'widget' => 'textarea', 'accts_usage' => 'extra_accounts', 'write_props' => 'empty_string_ok'],
		];
		$custom = [
			['id' => 'use_custom_settings', 'type' => 'exists', 'widget' => 'checkbox', 'accts_usage' => 'extra_accounts', 'write_props' => ''],
			['id' => 'mail_server', 'type' => 'user_string', 'widget' => 'textbox', 'accts_usage' => 'extra_accounts', 'write_props' => ''],
		];
		(new EmailPreferencesWriter())->saveExtraAccount(2, $standard, $custom, [
			'userid' => 'user@example.test', 'password' => 'secret', 'email_sig' => '', 'use_custom_settings' => true, 'mail_server' => 'imap.example.test',
		], $preferences, $mail);
		$this->assertContains(['add', 'email', '["ex_accounts"][2]["userid"]', 'encoded:user@example.test'], $preferences->writes);
		$this->assertContains(['add', 'email', '["ex_accounts"][2]["password"]', 'encrypted:secret'], $preferences->writes);
		$this->assertContains(['add', 'email', '["ex_accounts"][2]["email_sig"]', ''], $preferences->writes);
		$this->assertContains(['add', 'email', '["ex_accounts"][2]["use_custom_settings"]', 'True'], $preferences->writes);
		$this->assertContains(['add', 'email', '["ex_accounts"][2]["mail_server"]', 'encoded:imap.example.test'], $preferences->writes);
		$this->assertContains(['sort', 'email', '["ex_accounts"]'], $preferences->writes);
		$this->assertSame(['save'], end($preferences->writes));
	}
}