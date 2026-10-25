<?php

namespace Tests\Services;

require_once __DIR__ . '/../../vendor/autoload.php';

use App\modules\email\services\EmailAccountDirectory;
use PHPUnit\Framework\TestCase;

class EmailAccountDirectoryTest extends TestCase
{
	/**
	 * @dataProvider enabledAccountCases
	 */
	public function testEnabledAccountLookupPreservesExistingMembershipRules(array $accounts, int $account, bool $expected): void
	{
		$this->assertSame($expected, EmailAccountDirectory::hasEnabledAccount($accounts, $account));
		$previous = array_map('intval', array_column(array_filter($accounts,
			static fn($item) => ($item['status'] ?? '') === 'enabled'), 'acctnum'));
		$this->assertSame(in_array($account, $previous, true),
			EmailAccountDirectory::hasEnabledAccount($accounts, $account));
	}

	public static function enabledAccountCases(): array
	{
		return [
			[[], 0, false],
			[[['acctnum' => 0, 'status' => 'enabled']], 0, true],
			[[['acctnum' => '3', 'status' => 'enabled']], 3, true],
			[[['acctnum' => 3, 'status' => 'disabled']], 3, false],
			[[['acctnum' => 3, 'status' => 'empty']], 3, false],
			[[['acctnum' => 3]], 3, false],
			[[['status' => 'enabled']], 0, false],
			[[['acctnum' => null, 'status' => 'enabled']], 0, true],
			[[['acctnum' => 0, 'status' => 'enabled'], ['acctnum' => 3, 'status' => 'enabled']], 4, false],
			[[['acctnum' => 3, 'status' => 'disabled'], ['acctnum' => 3, 'status' => 'enabled']], 3, true],
		];
	}

	public function testSerializesEnabledAndDisabledAccountsAndSkipsEmptySlots(): void
	{
		$result = EmailAccountDirectory::serialize([
			['acctnum' => 1, 'status' => 'enabled'],
			['acctnum' => 2, 'status' => 'disabled'],
			['acctnum' => 3, 'status' => 'empty'],
		], [
			1 => ['account_name' => 'Work', 'fullname' => 'Work User'],
			2 => ['fullname' => 'Archive User'],
		]);
		$this->assertSame([
			['id' => 1, 'name' => '[1] Work', 'status' => 'enabled'],
			['id' => 2, 'name' => '[2] (disabled) Archive User', 'status' => 'disabled'],
		], $result);
	}

	public function testFindsFirstReusableAccountNumber(): void
	{
		$this->assertSame(1, EmailAccountDirectory::nextAccountNumber([]));
		$this->assertSame(2, EmailAccountDirectory::nextAccountNumber([
			['acctnum' => 1, 'status' => 'enabled'], ['acctnum' => 2, 'status' => 'empty'],
		]));
		$this->assertSame(2, EmailAccountDirectory::nextAccountNumber([
			['acctnum' => 1, 'status' => 'enabled'], ['acctnum' => 3, 'status' => 'enabled'],
		]));
		$this->assertSame(3, EmailAccountDirectory::nextAccountNumber([
			['acctnum' => 1, 'status' => 'enabled'], ['acctnum' => 2, 'status' => 'disabled'],
		]));
	}
}