<?php

namespace Tests\Services;

require_once __DIR__ . '/../../vendor/autoload.php';

use App\modules\email\services\GoogleMailOAuth;
use App\modules\phpgwapi\services\Cache;
use PHPUnit\Framework\TestCase;

class GoogleMailOAuthTest extends TestCase
{
	private mixed $originalSession;
	private mixed $originalSettings;

	protected function setUp(): void
	{
		$this->originalSession = $_SESSION ?? null;
		$_SESSION = [];
		$settingsProperty = new \ReflectionProperty(\App\modules\phpgwapi\services\Settings::class, 'instance');
		$settingsProperty->setAccessible(true);
		$this->originalSettings = $settingsProperty->getValue();
		$settingsProperty->setValue(null, new class
		{
			public function get(string $key): array
			{
				return $key === 'server' ? [
					'install_id' => 'google-oauth-test',
					'encryptkey' => '',
					'mcrypt_iv' => '',
					'httpproxy_server' => 'proxy.example.test',
					'httpproxy_port' => 8080,
				] : [];
			}
		});
	}

	protected function tearDown(): void
	{
		if ($this->originalSession === null) unset($_SESSION);
		else $_SESSION = $this->originalSession;
		$settingsProperty = new \ReflectionProperty(\App\modules\phpgwapi\services\Settings::class, 'instance');
		$settingsProperty->setAccessible(true);
		$settingsProperty->setValue(null, $this->originalSettings);
	}

	public function testAuthorizationUrlUsesGmailScopeOfflineConsentAndMailboxBoundPkceState(): void
	{
		$profile = [
			'profileID' => 23,
			'google_client_id' => 'google-client.apps.example',
			'google_client_secret' => 'encrypted-secret',
			'google_redirect_uri' => 'https://example.test/portico/email/oauth/google/callback',
		];
		$url = GoogleMailOAuth::authorizationUrl(4, 'user@gmail.example', $profile);
		parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

		$this->assertSame('accounts.google.com', parse_url($url, PHP_URL_HOST));
		$this->assertSame('google-client.apps.example', $query['client_id']);
		$this->assertSame($profile['google_redirect_uri'], $query['redirect_uri']);
		$this->assertSame('user@gmail.example', $query['login_hint']);
		$this->assertStringContainsString('https://mail.google.com/', $query['scope']);
		$this->assertSame('offline', $query['access_type']);
		$this->assertSame('consent', $query['prompt']);
		$this->assertSame('S256', $query['code_challenge_method']);
		$this->assertTrue(GoogleMailOAuth::isConfigured($profile));

		$state = Cache::session_get('email', 'google_mail_oauth_' . $query['state']);
		$this->assertSame(4, $state['account']);
		$this->assertSame(23, $state['profile_id']);
		$this->assertSame('user@gmail.example', $state['email']);
		$this->assertSame(
			rtrim(strtr(base64_encode(hash('sha256', $state['verifier'], true)), '+/', '-_'), '='),
			$query['code_challenge']
		);
	}

	public function testAuthorizationRejectsNonEmailMailboxNames(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		GoogleMailOAuth::authorizationUrl(0, 'not-an-email', [
			'google_client_id' => 'client',
			'google_client_secret' => 'secret',
			'google_redirect_uri' => 'https://example.test/callback',
		]);
	}

	public function testPersonalAccountClientCredentialsOverrideProfileCredentials(): void
	{
		$profile = [
			'profileID' => 23,
			'google_redirect_uri' => 'https://example.test/portico/email/oauth/google/callback',
		];
		$accountPreferences = [
			'google_client_id' => 'personal-client.apps.example',
			'google_client_secret' => 'encrypted-personal-secret',
		];
		$url = GoogleMailOAuth::authorizationUrl(4, 'user@gmail.example', $profile, $accountPreferences);
		parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

		$this->assertTrue(GoogleMailOAuth::isConfigured($profile, $accountPreferences));
		$this->assertSame('personal-client.apps.example', $query['client_id']);
		$this->assertSame($profile['google_redirect_uri'], $query['redirect_uri']);
	}

	public function testGoogleHttpRequestsUseConfiguredServerProxy(): void
	{
		$method = new \ReflectionMethod(GoogleMailOAuth::class, 'withHttpProxy');
		$method->setAccessible(true);
		$options = $method->invoke(null, [CURLOPT_TIMEOUT => 12]);

		$this->assertSame('proxy.example.test', $options[CURLOPT_PROXY]);
		$this->assertSame(8080, $options[CURLOPT_PROXYPORT]);
		$this->assertSame(CURLPROXY_HTTP, $options[CURLOPT_PROXYTYPE]);
		$this->assertTrue($options[CURLOPT_HTTPPROXYTUNNEL]);
		$this->assertSame(12, $options[CURLOPT_TIMEOUT]);
	}
}