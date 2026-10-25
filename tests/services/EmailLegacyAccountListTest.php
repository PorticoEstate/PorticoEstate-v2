<?php

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
	require_once __DIR__ . '/../../src/modules/email/inc/class.bopreferences.inc.php';
	require_once __DIR__ . '/../../src/modules/email/inc/class.mail_msg_base.inc.php';
	require_once __DIR__ . '/../../src/modules/email/inc/class.mail_msg_wrappers.inc.php';

	use PHPUnit\Framework\TestCase;

	class LegacyAccountListLinks
	{
		public static function link(string $path, array $parameters = []): string
		{
			return $path . '?' . http_build_query($parameters);
		}
	}

	class EmailLegacyAccountListTest extends TestCase
	{
		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function testListPreservesOrderLinksDisabledFallbacksAndLegacyEscaping(): void
		{
			class_alias(LegacyAccountListLinks::class, 'phpgw');
			$adapter = (new \ReflectionClass(\email_bopreferences::class))->newInstanceWithoutConstructor();
			$mail = $this->getMockBuilder(\mail_msg_wrappers::class)->disableOriginalConstructor()
				->onlyMethods(['get_pref_value'])->getMock();
			$mail->extra_accounts = [
				['acctnum' => 4, 'status' => 'enabled'],
				['acctnum' => 2, 'status' => 'empty'],
				['acctnum' => 3, 'status' => 'disabled'],
				['acctnum' => 5, 'status' => 'disabled'],
			];
			$mail->unprocessed_prefs = ['email' => ['ex_accounts' => [
				3 => ['fullname' => ' Backup &amp; Mail '],
				5 => ['account_name' => ' ', 'fullname' => ''],
			]]];
			$calls = [];
			$mail->method('get_pref_value')->willReturnCallback(static function ($key, $account) use (&$calls): string
			{
				$calls[] = [$key, $account];
				return match ([$key, $account]) {
					['account_name', 4] => 'Work &amp; &lt;script&gt; {mail}',
					['fullname', 4] => 'Ignored',
					['account_name', 5] => 'Fallback',
					default => throw new \LogicException('Unexpected preference lookup'),
				};
			});
			$adapter->msg = $mail;
			$result = $adapter->ex_accounts_list();
			$this->assertSame([0, 1, 2], array_keys($result));
			$this->assertSame([4, 3, 5], array_column($result, 'acctnum'));
			$this->assertSame('[4] Work &amp; &lt;script&gt; &#123;mail&#125;', $result[0]['display_string']);
			$this->assertSame('[3] (disabled) Backup &amp; Mail', $result[1]['display_string']);
			$this->assertSame('[5] (disabled) Fallback', $result[2]['display_string']);
			foreach ($result as $row)
			{
				$this->assertSame(['acctnum', 'status', 'go_there_url', 'go_there_href',
					'display_string', 'edit_url', 'edit_href'], array_keys($row));
				$this->assertSame('/email/view/account?ex_acctnum=' . $row['acctnum'], $row['edit_url']);
				$this->assertSame('<a href="' . $row['edit_url'] . '">Edit</a>', $row['edit_href']);
				if ($row['status'] === 'disabled')
				{
					$this->assertSame('', $row['go_there_url']);
					$this->assertSame('&nbsp;', $row['go_there_href']);
				}
				else
				{
					$this->assertSame('/email/view/inbox?' . http_build_query([
						'fldball[folder]' => 'INBOX', 'fldball[acctnum]' => 4,
					]), $row['go_there_url']);
					$this->assertSame('<a href="' . $row['go_there_url'] . '">go</a>', $row['go_there_href']);
				}
			}
			$this->assertSame([['account_name', 4], ['fullname', 4], ['account_name', 5]], $calls);
		}

		public function testEmptySlotsProduceAnEmptyListWithoutMailLookups(): void
		{
			$adapter = (new \ReflectionClass(\email_bopreferences::class))->newInstanceWithoutConstructor();
			$adapter->msg = (object) ['extra_accounts' => [['acctnum' => 2, 'status' => 'empty']]];
			$this->assertSame([], $adapter->ex_accounts_list());
		}
	}
}
