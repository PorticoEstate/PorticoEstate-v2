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
			public array $invalidated = [];
			public function invalidatePreferenceCache(int $account): void { $this->invalidated[] = $account; }
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
		$this->assertSame([0], $mail->invalidated);
	}

	public function testAllocatesTheFirstUnusedAccountNumber(): void
	{
		$this->assertSame(1, EmailPreferencesWriter::nextAccountNumber([]));
		$complete = ['fullname' => 'Account', 'email_sig' => '', 'layout' => '2'];
		$this->assertSame(2, EmailPreferencesWriter::nextAccountNumber([1 => $complete, 3 => $complete]));
		$this->assertSame(2, EmailPreferencesWriter::nextAccountNumber([1 => $complete, 2 => ['fullname' => 'Incomplete']]));
		$this->assertSame(3, EmailPreferencesWriter::nextAccountNumber([1 => $complete, 2 => $complete]));
		$this->assertSame(3, EmailPreferencesWriter::nextAccountNumber([2 => $complete, 1 => $complete]));
		$this->assertSame(2, EmailPreferencesWriter::nextAccountNumber([
			['acctnum' => 3, 'status' => 'disabled'],
			['acctnum' => 1, 'status' => 'enabled'],
			['acctnum' => 2, 'status' => 'empty'],
		]));
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
			public array $invalidated = [];
			public function invalidatePreferenceCache(int $account): void { $this->invalidated[] = $account; }
			public function html_quotes_encode($value): string { return 'encoded:' . $value; }
			public function encrypt_email_passwd($value): string { return 'encrypted:' . $value; }
		};
		$standard = [
			['id' => 'userid', 'type' => 'user_string', 'widget' => 'textbox', 'accts_usage' => 'extra_accounts', 'write_props' => ''],
			['id' => 'password', 'type' => 'user_string', 'widget' => 'passwordbox', 'accts_usage' => 'extra_accounts', 'write_props' => 'encrypted'],
			['id' => 'email_sig', 'type' => 'user_string', 'widget' => 'textarea', 'accts_usage' => 'extra_accounts', 'write_props' => 'empty_string_ok'],
		];
		$custom = [
			['id' => 'use_custom_settings', 'type' => 'exists', 'widget' => 'checkbox', 'accts_usage' => 'default', 'write_props' => ''],
			['id' => 'mail_server', 'type' => 'user_string', 'widget' => 'textbox', 'accts_usage' => 'extra_accounts', 'write_props' => ''],
			['id' => 'microsoft_client_secret', 'type' => 'user_string', 'widget' => 'passwordbox', 'accts_usage' => 'extra_accounts', 'write_props' => 'password, hidden, encrypted, empty_no_delete'],
		];
		(new EmailPreferencesWriter())->saveExtraAccount(2, $standard, $custom, [
			'userid' => 'user@example.test', 'password' => 'secret', 'email_sig' => '', 'use_custom_settings' => true, 'mail_server' => 'imap.example.test',
			'microsoft_client_secret' => 'personal-client-secret',
		], $preferences, $mail);
		$this->assertContains(['add', 'email', '["ex_accounts"][2]["userid"]', 'encoded:user@example.test'], $preferences->writes);
		$this->assertContains(['add', 'email', '["ex_accounts"][2]["password"]', 'encrypted:secret'], $preferences->writes);
		$this->assertContains(['add', 'email', '["ex_accounts"][2]["email_sig"]', ''], $preferences->writes);
		$this->assertContains(['delete', 'email', '["ex_accounts"][2]["use_custom_settings"]'], $preferences->writes);
		$this->assertNotContains(['add', 'email', '["ex_accounts"][2]["use_custom_settings"]', 'True'], $preferences->writes);
		$this->assertContains(['add', 'email', '["ex_accounts"][2]["mail_server"]', 'encoded:imap.example.test'], $preferences->writes);
		$this->assertContains(['sort', 'email', '["ex_accounts"]'], $preferences->writes);
		$this->assertSame(['save'], end($preferences->writes));
		$this->assertSame([2], $mail->invalidated);
		$this->assertContains(['add', 'email', '["ex_accounts"][2]["microsoft_client_secret"]', 'encrypted:personal-client-secret'], $preferences->writes);
		$preferences->writes = [];
		(new EmailPreferencesWriter())->saveExtraAccount(2, $standard, $custom, [
			'microsoft_client_secret' => '',
		], $preferences, $mail);
		$this->assertNotContains(['delete', 'email', '["ex_accounts"][2]["microsoft_client_secret"]'], $preferences->writes);
		$this->assertSame([], array_values(array_filter($preferences->writes, static fn($write) => ($write[2] ?? '') === '["ex_accounts"][2]["microsoft_client_secret"]')));
	}

	/**
	 * @dataProvider legacySaveModes
	 */
	public function testLegacySavePreservesEncodingAndDebugMode(?int $account, bool $save): void
	{
		$preferences = new class
		{
			public array $writes = [];
			public function delete($app, $id): void { $this->writes[] = ['delete', $id]; }
			public function add($app, $id, $value): void { $this->writes[] = ['add', $id, $value]; }
			public function delete_struct($app, $path): void { $this->writes[] = ['delete', $path]; }
			public function add_struct($app, $path, $value): void { $this->writes[] = ['add', $path, $value]; }
			public function sort_struct($app, $path): void { $this->writes[] = ['sort', $path]; }
			public function save_repository(): void { $this->writes[] = ['save']; }
		};
		$mail = new class
		{
			public array $invalidated = [];
			public function invalidatePreferenceCache(int $account): void { $this->invalidated[] = $account; }
			public function stripslashes_gpc($value): string { return 'stripped:' . $value; }
			public function html_quotes_encode($value): string { return 'encoded:' . $value; }
			public function encrypt_email_passwd($value): string { return 'encrypted:' . $value; }
		};
		$scope = $account === null ? 'default' : 'extra_accounts';
		$standard = [['id' => 'enabled', 'widget' => 'checkbox', 'type' => 'exists', 'accts_usage' => $scope]];
		$custom = [['id' => 'passwd', 'widget' => 'passwordbox', 'type' => 'user_string',
			'accts_usage' => $scope, 'write_props' => 'encrypted,empty_no_delete']];
		$values = ['enabled' => 'on', 'use_custom_settings' => 'on', 'passwd' => 'secret'];
		$writer = new EmailPreferencesWriter();
		if ($account === null)
		{
			$writer->saveDefault($standard, $custom, $values, $preferences, $mail, true, $save);
		}
		else
		{
			$writer->saveExtraAccount($account, $standard, $custom, $values, $preferences, $mail, true, $save);
		}
		$path = $account === null ? '' : '["ex_accounts"][' . $account . ']';
		$this->assertContains(['add', $account === null ? 'enabled' : $path . '["enabled"]', 'on'], $preferences->writes);
		$this->assertContains(['add', $account === null ? 'passwd' : $path . '["passwd"]',
			($account === null ? 'encoded:' : '') . 'encrypted:stripped:secret'], $preferences->writes);
		$this->assertSame($save ? 1 : 0, count(array_filter($preferences->writes, static fn($write) => $write === ['save'])));
		$this->assertSame($save ? [$account ?? 0] : [], $mail->invalidated);
	}

	public static function legacySaveModes(): array
	{
		return [[null, true], [null, false], [2, true], [2, false]];
	}

	/**
	 * @dataProvider invalidAccountNumbers
	 */
	public function testRejectsInvalidExistingAccountNumbers(mixed $value): void
	{
		$this->expectException(\InvalidArgumentException::class);
		EmailPreferencesWriter::existingAccountNumber($value, [1 => []]);
	}

	public static function invalidAccountNumbers(): array
	{
		return [[0], [-1], ['add_new'], ['1abc'], [1.5], [true], [[]], [null], ['']];
	}

	public function testRejectsUnknownExistingAccount(): void
	{
		$this->expectException(\OutOfBoundsException::class);
		EmailPreferencesWriter::existingAccountNumber(2, [1 => []]);
	}

	public function testDeletionRejectsUnknownAccountBeforeAnyWrite(): void
	{
		$this->expectException(\OutOfBoundsException::class);
		(new EmailPreferencesWriter())->deleteAccount(2, [1 => []], new \stdClass(), new \stdClass());
	}

	/**
	 * @dataProvider deletionModes
	 */
	public function testSharedDeletionPreservesOtherAccountsAndDebugMode(bool $save): void
	{
		$preferences = new class
		{
			public array $deleted = [];
			public int $saved = 0;
			public function delete_struct($app, $path): void { $this->deleted[] = [$app, $path]; }
			public function save_repository(): void { $this->saved++; }
		};
		$mail = new class
		{
			public array $invalidated = [];
			public function invalidatePreferenceCache(int $account): void { $this->invalidated[] = $account; }
		};
		(new EmailPreferencesWriter())->deleteAccount(2, [1 => [], 2 => []], $preferences, $mail, $save);
		$this->assertSame([['email', '["ex_accounts"][2]']], $preferences->deleted);
		$this->assertSame($save ? 1 : 0, $preferences->saved);
		$this->assertSame($save ? [2] : [], $mail->invalidated);
	}

	public static function deletionModes(): array
	{
		return [[true], [false]];
	}
}