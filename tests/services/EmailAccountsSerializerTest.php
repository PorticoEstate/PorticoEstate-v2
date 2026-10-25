<?php

namespace Tests\Services;

require_once __DIR__ . '/../../vendor/autoload.php';

use App\modules\email\services\EmailAccountsSerializer;
use PHPUnit\Framework\TestCase;

class EmailAccountsSerializerTest extends TestCase
{
	public function testSharedAccountNameFallbackPreservesEncodedInputForCaller(): void
	{
		$this->assertSame('Work &amp; Mail', EmailAccountsSerializer::accountName([
			'account_name' => ' Work &amp; Mail ', 'fullname' => 'User',
		]));
		$this->assertSame('User', EmailAccountsSerializer::accountName(['account_name' => ' ', 'fullname' => 'User']));
		$this->assertSame('Unknown account', EmailAccountsSerializer::accountName([]));
	}

	public function testSerializesEnabledAndDisabledAccountsAndOmitsEmptySlots(): void
	{
		$this->assertSame([
			['id' => 1, 'name' => '[1] Work & Mail', 'status' => 'enabled'],
			['id' => 3, 'name' => '[3] (disabled) backup@example.test', 'status' => 'disabled'],
		], EmailAccountsSerializer::serialize([
			1 => ['acctnum' => 1, 'status' => 'enabled', 'account_name' => 'Work &amp; Mail', 'fullname' => 'User'],
			2 => ['acctnum' => 2, 'status' => 'empty'],
			3 => ['acctnum' => 3, 'status' => 'disabled', 'fullname' => 'backup@example.test'],
		]));
	}
}