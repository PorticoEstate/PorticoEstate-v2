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
	require_once __DIR__ . '/../../src/modules/email/inc/class.mail_msg_base.inc.php';
	require_once __DIR__ . '/../../src/modules/email/inc/class.mail_msg_wrappers.inc.php';

	use App\modules\email\services\EmailPreferenceDefaults;
	use PHPUnit\Framework\TestCase;

	class EmailPreferenceDefaultsTest extends TestCase
	{
		public function testResolvesLiteralDefaultsWithoutEnablingFeaturesOrFillingPasswords(): void
		{
			$values = (new EmailPreferenceDefaults())->resolve([], 0);
			$this->assertSame('Trash', $values['trash_folder_name']);
			$this->assertSame('Sent', $values['sent_folder_name']);
			$this->assertSame('Drafts', $values['draft_folder_name']);
			$this->assertSame('new_old', $values['default_sorting']);
			$this->assertSame('2', $values['layout']);
			$this->assertSame('password', $values['imap_auth_type']);
			$this->assertSame('', $values['mail_server_port']);
			$this->assertArrayNotHasKey('use_trash_folder', $values);
			$this->assertArrayNotHasKey('passwd', $values);
			$this->assertArrayNotHasKey('font_size_offset', $values);
		}

		public function testPreservesExplicitValuesAndUsesDefaultsForBlankLiteralOptions(): void
		{
			$stored = ['use_trash_folder' => false, 'use_sent_folder' => 'False',
				'default_sorting' => 'old_new', 'trash_folder_name' => 'Deleted',
				'sent_folder_name' => ' ', 'email_sig' => '', 'mail_folder' => '',
				'passwd' => 'encrypted-value', 'mail_server_port' => '1993'];
			$values = (new EmailPreferenceDefaults())->resolve($stored, 0, [], ['mail_folder' => 'mail']);
			$this->assertSame('Sent', $values['sent_folder_name']);
			unset($stored['sent_folder_name']);
			foreach ($stored as $id => $value)
			{
				$this->assertSame($value, $values[$id]);
			}
		}

		public function testResolvesUserAndServerDefaultsAndAccountScope(): void
		{
			$resolver = new EmailPreferenceDefaults();
			$user = ['fullname' => 'Test User', 'account_lid' => 'tester'];
			$server = ['mail_server' => 'imap.example.test', 'mail_server_type' => 'imaps',
				'imap_server_type' => 'Cyrus', 'mail_folder' => 'mail',
				'mail_suffix' => 'example.test', 'mail_login_type' => 'vmailmgr'];
			$values = $resolver->resolve([], 1, $user, $server);
			$this->assertSame('Test User', $values['fullname']);
			$this->assertSame('tester@example.test', $values['userid']);
			$this->assertSame('tester@example.test', $values['address']);
			$this->assertSame('imap.example.test', $values['mail_server']);
			$this->assertSame('imaps', $values['mail_server_type']);
			$this->assertSame('Cyrus', $values['imap_server_type']);
			$this->assertSame('mail', $values['mail_folder']);
			$this->assertSame('evo', $values['icon_theme']);
			$this->assertArrayNotHasKey('use_custom_settings', $values);
			$server['mail_login_type'] = 'ispman';
			$this->assertSame('tester_example_test', $resolver->resolve([], 1, $user, $server)['userid']);
		}

		public function testRuntimeGettersAndPresenceChecksUseDefaultsForEachAccount(): void
		{
			$mail = (new \ReflectionClass(\mail_msg_wrappers::class))->newInstanceWithoutConstructor();
			$mail->set_pref_array(['default_sorting' => 'old_new'], 0);
			$mail->set_pref_array(['trash_folder_name' => 'Deleted'], 1);
			$this->assertSame('Sent', $mail->get_pref_value('sent_folder_name', 0));
			$this->assertSame('old_new', $mail->get_pref_value('default_sorting', 0));
			$this->assertSame('new_old', $mail->get_pref_value('default_sorting', 1));
			$this->assertTrue($mail->get_isset_pref('default_sorting', 1));
			$this->assertFalse($mail->get_isset_pref('use_trash_folder', 0));
			$this->assertSame('Deleted', $mail->get_all_prefs(1)['trash_folder_name']);
			$this->assertNull($mail->get_pref_value('passwd', 0));
		}

		public function testUnknownDynamicDefaultFailsExplicitly(): void
		{
			$resolver = new EmailPreferenceDefaults(['standard' => [[
				'id' => 'unknown', 'accts_usage' => 'default', 'type' => 'user_string',
				'init_default' => 'varEVAL,arbitrary PHP',
			]], 'custom' => []]);
			$this->expectException(\LogicException::class);
			$this->expectExceptionMessage('Unsupported email preference default: unknown');
			$resolver->resolve([], 0);
		}
	}
}
