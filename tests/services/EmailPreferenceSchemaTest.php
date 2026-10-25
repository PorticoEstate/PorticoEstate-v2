<?php

namespace
{
	if (!function_exists('lang'))	{ function lang(string $value): string { return $value; } }
}

namespace Tests\Services
{
	require_once __DIR__ . '/../../vendor/autoload.php';
	require_once __DIR__ . '/../../src/modules/email/inc/class.bopreferences.inc.php';

	use App\modules\email\services\EmailPreferenceSchema;
	use PHPUnit\Framework\TestCase;

	class EmailPreferenceSchemaTest extends TestCase
	{
		public function testProvidesStableDefaultAndExtraAccountFieldContracts(): void
		{
			$schema = (new EmailPreferenceSchema())->fields();
			$standard = array_column($schema['standard'], null, 'id');
			$custom = array_column($schema['custom'], null, 'id');
			$this->assertArrayHasKey('default_sorting', $standard);
			$this->assertSame('combobox', $standard['default_sorting']['widget']);
			$this->assertSame(['old_new', 'new_old'], array_keys($standard['default_sorting']['values']));
			$this->assertArrayHasKey('email_sig', $standard);
			$this->assertStringContainsString('empty_string_ok', $standard['email_sig']['write_props']);
			$this->assertArrayHasKey('use_custom_settings', $custom);
			$this->assertArrayHasKey('passwd', $custom);
			$this->assertStringContainsString('encrypted', $custom['passwd']['write_props']);
			$this->assertArrayHasKey('mail_server', $custom);
			$this->assertSame('default, extra_accounts', $custom['mail_server_port']['accts_usage']);
			$this->assertSame('string,', $custom['mail_server_port']['init_default']);
			$this->assertArrayHasKey('mail_folder', $custom);
		}

		public function testExtractedCatalogMatchesLegacySchemaExactly(): void
		{
			$legacy = (new \ReflectionClass('email_bopreferences'))->newInstanceWithoutConstructor();
			$legacy->debug_set_prefs = 0;
			$legacy->init_available_prefs();
			$schema = (new EmailPreferenceSchema())->fields();
			$this->assertSame($legacy->std_prefs, $schema['standard']);
			$this->assertSame($legacy->cust_prefs, $schema['custom']);
		}
	}
}