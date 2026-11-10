<?php

namespace Tests\Services;

require_once __DIR__ . '/../../vendor/autoload.php';

use App\modules\email\services\MicrosoftImapOAuth;
use App\modules\phpgwapi\services\Cache;
use App\modules\phpgwapi\services\Settings;
use PHPUnit\Framework\TestCase;

class MicrosoftImapOAuthTest extends TestCase
{
	private mixed $originalSession;
	private mixed $originalSettings;

	protected function setUp(): void
	{
		$this->originalSession = $_SESSION ?? null;
		$_SESSION = [];
		$settingsProperty = new \ReflectionProperty(Settings::class, 'instance');
		$settingsProperty->setAccessible(true);
		$this->originalSettings = $settingsProperty->getValue();
		$settingsProperty->setValue(null, new class
		{
			public function get(string $key): array
			{
				return $key === 'server' ? [
					'install_id' => 'microsoft-oauth-test', 'encryptkey' => '', 'mcrypt_iv' => '',
					'httpproxy_server' => 'http://proxy.example.test:1080', 'httpproxy_port' => 8080,
				] : [];
			}
		});
	}

	protected function tearDown(): void
	{
		if ($this->originalSession === null)
		{
			unset($_SESSION);
		}
		else
		{
			$_SESSION = $this->originalSession;
		}
		$settingsProperty = new \ReflectionProperty(Settings::class, 'instance');
		$settingsProperty->setAccessible(true);
		$settingsProperty->setValue(null, $this->originalSettings);
	}

	public function testMicrosoftTokenRequestsUseConfiguredProxyPort(): void
	{
		$method = new \ReflectionMethod(MicrosoftImapOAuth::class, 'withHttpProxy');
		$method->setAccessible(true);
		$options = $method->invoke(null, [CURLOPT_TIMEOUT => 20]);
		$this->assertSame('http://proxy.example.test:1080', $options[CURLOPT_PROXY]);
		$this->assertSame(8080, $options[CURLOPT_PROXYPORT]);
		$this->assertSame(CURLPROXY_HTTP, $options[CURLOPT_PROXYTYPE]);
		$this->assertTrue($options[CURLOPT_HTTPPROXYTUNNEL]);
		$this->assertSame(20, $options[CURLOPT_TIMEOUT]);
	}

	public function testAccountCredentialsOverrideProfileAndBlankFieldsInherit(): void
	{
		$method = new \ReflectionMethod(MicrosoftImapOAuth::class, 'configuration');
		$method->setAccessible(true);
		$profile = [
			'microsoft_client_id' => 'admin-client',
			'microsoft_client_secret' => 'admin-secret',
			'microsoft_tenant_id' => 'organization-tenant',
		];
		$account = ['microsoft_client_id' => 'personal-client', 'microsoft_client_secret' => 'personal-secret', 'microsoft_tenant_id' => 'consumers'];
		$config = $method->invoke(null, $profile, $account);
		$this->assertSame('personal-client', $config['client_id']);
		$this->assertSame('personal-secret', $config['client_secret']);
		$this->assertSame('consumers', $config['tenant']);
		$this->assertSame('', $config['redirect_uri']);
		$this->assertSame($method->invoke(null, $profile), $method->invoke(null, $profile, array_fill_keys(array_keys($account), '')));
	}

	public function testPersonalExtraAccountUsesOwnClientAndConsumersAuthority(): void
	{
		$profile = [
			'microsoft_tenant_id' => 'organization-tenant',
			'microsoft_client_id' => 'admin-client',
			'microsoft_client_secret' => 'admin-secret',
			'microsoft_redirect_uri' => 'https://example.test/portico/email/oauth/microsoft/callback',
		];
		$accountPreferences = [
			'microsoft_tenant_id' => 'consumers',
			'microsoft_client_id' => 'personal-client',
			'microsoft_client_secret' => 'personal-secret',
		];
		$url = MicrosoftImapOAuth::authorizationUrl(3, 'personal@outlook.com', $profile, $accountPreferences);
		parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
		$this->assertSame('/consumers/oauth2/v2.0/authorize', parse_url($url, PHP_URL_PATH));
		$this->assertSame('personal-client', $query['client_id']);
		$this->assertSame(3, Cache::session_get('email', 'microsoft_imap_oauth_' . $query['state'])['account']);
		$this->assertTrue(MicrosoftImapOAuth::isConfigured(['microsoft_redirect_uri' => $profile['microsoft_redirect_uri']], $accountPreferences));
	}

	public function testAuthorizationUrlUsesOutlookImapScopeAndPkce(): void
	{
		$profile = [
			'profileID' => 17,
			'microsoft_tenant_id' => 'common',
			'microsoft_client_id' => 'test-client',
			'microsoft_client_secret' => 'encrypted-client-secret',
			'microsoft_redirect_uri' => 'https://example.test/portico/email/oauth/microsoft/callback',
		];
		$url = MicrosoftImapOAuth::authorizationUrl(3, 'outlook@example.test', $profile);
		parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

		$this->assertSame('login.microsoftonline.com', parse_url($url, PHP_URL_HOST));
		$this->assertSame('test-client', $query['client_id']);
		$this->assertSame('https://example.test/portico/email/oauth/microsoft/callback', $query['redirect_uri']);
		$this->assertSame('outlook@example.test', $query['login_hint']);
		$this->assertStringContainsString('https://outlook.office.com/IMAP.AccessAsUser.All', $query['scope']);
		$this->assertStringContainsString('offline_access', $query['scope']);
		$this->assertSame('S256', $query['code_challenge_method']);
		$this->assertTrue(MicrosoftImapOAuth::isConfigured($profile));

		$state = Cache::session_get('email', 'microsoft_imap_oauth_' . $query['state']);
		$this->assertSame(3, $state['account']);
		$this->assertSame(17, $state['profile_id']);
		$this->assertSame(
			rtrim(strtr(base64_encode(hash('sha256', $state['verifier'], true)), '+/', '-_'), '='),
			$query['code_challenge']
		);
	}
}