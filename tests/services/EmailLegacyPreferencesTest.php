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
}
