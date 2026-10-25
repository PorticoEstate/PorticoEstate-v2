<?php

namespace Tests\Services;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/modules/email/inc/class.bopreferences.inc.php';
require_once __DIR__ . '/../../src/modules/email/inc/class.mail_msg_base.inc.php';
require_once __DIR__ . '/../../src/modules/email/inc/class.mail_msg_wrappers.inc.php';
require_once __DIR__ . '/../../src/modules/email/inc/class.so_mail_msg.inc.php';

use PHPUnit\Framework\TestCase;

class EmailLegacyPreferencesTest extends TestCase
{
	private function adapter(array $post): \email_bopreferences
	{
		$adapter = (new \ReflectionClass(\email_bopreferences::class))->newInstanceWithoutConstructor();
		$adapter->msg = (object) ['ref_POST' => $post, 'extra_accounts' => []];
		$adapter->std_prefs = [
			['id' => 'shared', 'accts_usage' => 'default,extra_accounts'],
			['id' => 'default_only', 'accts_usage' => 'default'],
		];
		$adapter->cust_prefs = [
			['id' => 'extra_only', 'accts_usage' => 'extra_accounts'],
			['id' => 'custom', 'accts_usage' => 'default,extra_accounts'],
		];
		return $adapter;
	}

	public function testCollectsOnlySubmittedDefaultFieldsWithoutInvalidatingCaches(): void
	{
		$adapter = $this->adapter(['submit_prefs' => 'save', 'shared' => '',
			'custom' => 'value', 'extra_only' => 'ignored', 'unknown' => 'ignored']);
		$adapter->grab_set_prefs_args_gpc();
		$this->assertSame(['submit_prefs' => 'save', 'shared' => '', 'custom' => 'value'], $adapter->args);
	}

	public function testCollectsOnlySelectedExtraAccountFields(): void
	{
		$adapter = $this->adapter(['submit_prefs_extra_accounts' => 'save',
			2 => ['shared' => 'second', 'custom' => 'custom', 'extra_only' => 'extra', 'default_only' => 'ignored'],
			3 => ['shared' => 'ignored']]);
		$adapter->acctnum = 2;
		$adapter->grab_set_prefs_args_gpc();
		$this->assertSame(['submit_prefs_extra_accounts' => 'save',
			2 => ['shared' => 'second', 'extra_only' => 'extra', 'custom' => 'custom']], $adapter->args);
	}

	public function testDoesNotCollectFieldsWithoutSubmitToken(): void
	{
		$adapter = $this->adapter(['shared' => 'ignored']);
		$adapter->grab_set_prefs_args_gpc();
		$this->assertSame([], $adapter->args);
	}

	public function testLegacyAllocationUsesSharedAllocator(): void
	{
		$adapter = $this->adapter([]);
		$adapter->msg->extra_accounts = [
			['acctnum' => 3, 'status' => 'enabled'], ['acctnum' => 1, 'status' => 'disabled'],
		];
		$this->assertSame(2, $adapter->get_first_empty_ex_acctnum());
	}

	public function testInvalidatesAccountCachesAndBulkPreferences(): void
	{
		$mail = $this->getMockBuilder(\mail_msg_wrappers::class)->disableOriginalConstructor()
			->onlyMethods(['expire_session_cache_item'])->getMock();
		$expired = [];
		$mail->method('expire_session_cache_item')->willReturnCallback(static function ($key, $account) use (&$expired): void
		{
			$expired[] = [$key, $account];
		});
		$mail->so = $this->getMockBuilder(\so_mail_msg::class)->disableOriginalConstructor()
			->onlyMethods(['so_appsession_passthru'])->getMock();
		$mail->so->expects($this->once())->method('so_appsession_passthru')->with('0;cached_prefs', ' ');
		$mail->invalidatePreferenceCache(0);
		$this->assertSame([
			['mailsvr_callstr', 0], ['mailsvr_namespace', 0], ['mailsvr_delimiter', 0],
			['folder_list', 0], ['verified_trash_folder_long', 0],
		], $expired);
	}

	public function testNewAccountTokenAllocatesButMalformedInputDoesNot(): void
	{
		$adapter = $this->adapter(['ex_acctnum' => 'add_new']);
		$this->assertSame(1, $adapter->obtain_ex_acctnum());
		$adapter->msg->ref_POST['ex_acctnum'] = '2invalid';
		$this->expectException(\InvalidArgumentException::class);
		$adapter->obtain_ex_acctnum();
	}

	public function testExistingAccountAndNextFreeSlotCanBeEdited(): void
	{
		$adapter = $this->adapter(['ex_acctnum' => '3']);
		$adapter->msg->unprocessed_prefs = ['email' => ['ex_accounts' => [3 => []]]];
		$this->assertSame(3, $adapter->obtain_ex_acctnum());
		$adapter->msg->ref_POST['ex_acctnum'] = '1';
		$this->assertSame(1, $adapter->obtain_ex_acctnum());
	}

	public function testDeletionNeverAllocatesAnAccountAndCleansUpOnInvalidInput(): void
	{
		$adapter = $this->adapter(['ex_acctnum' => 'add_new']);
		$adapter->msg = new class
		{
			public array $ref_POST = ['ex_acctnum' => 'add_new'];
			public int $closed = 0;
			public function end_request(): void { $this->closed++; }
		};
		try
		{
			$adapter->ex_accounts_delete('', true);
			$this->fail('Expected invalid deletion to fail');
		}
		catch (\InvalidArgumentException $exception)
		{
			$this->assertSame('Invalid email account', $exception->getMessage());
		}
		$this->assertSame(1, $adapter->msg->closed);
	}

	/**
	 * @dataProvider saveEntryPoints
	 */
	public function testSaveFailureAlwaysEndsRequest(string $method): void
	{
		$adapter = $this->getMockBuilder(\email_bopreferences::class)->disableOriginalConstructor()
			->onlyMethods(['grab_set_prefs'])->getMock();
		$adapter->msg = new class
		{
			public array $ref_POST = [];
			public array $extra_accounts = [];
			public int $closed = 0;
			public function end_request(): void { $this->closed++; }
		};
		$adapter->method('grab_set_prefs')->willThrowException(new \RuntimeException('Request failed'));
		try
		{
			if ($method === 'preferences')
			{
				$adapter->preferences(true);
			}
			else
			{
				$adapter->ex_accounts_edit('', true);
			}
			$this->fail('Expected request failure');
		}
		catch (\RuntimeException $exception)
		{
			$this->assertSame('Request failed', $exception->getMessage());
		}
		$this->assertSame(1, $adapter->msg->closed);
	}

	public static function saveEntryPoints(): array
	{
		return [['preferences'], ['ex_accounts_edit']];
	}

	public function testXmlRpcFailsExplicitly(): void
	{
		$adapter = $this->adapter([]);
		$this->expectException(\BadMethodCallException::class);
		$adapter->grab_set_prefs_args_xmlrpc();
	}
}
