<?php

namespace Tests\Services;

require_once __DIR__ . '/../../vendor/autoload.php';

use App\modules\email\services\EmailAccountDirectory;
use PHPUnit\Framework\TestCase;

class EmailAccountDirectoryTest extends TestCase
{
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