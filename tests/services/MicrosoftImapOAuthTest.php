<?php

namespace Tests\Services;

require_once __DIR__ . '/../../vendor/autoload.php';

use App\modules\email\services\MicrosoftImapOAuth;
use App\modules\phpgwapi\services\Cache;
use App\modules\phpgwapi\services\Settings;
use PHPUnit\Framework\TestCase;

class MicrosoftImapOAuthTest extends TestCase
{
	private array $originalEnvironment = [];
	private mixed $originalSession;
	private mixed $originalSettings;

	protected function setUp(): void
	{
		foreach (['EMAIL_MICROSOFT_TENANT_ID', 'EMAIL_MICROSOFT_CLIENT_ID', 'EMAIL_MICROSOFT_CLIENT_SECRET', 'EMAIL_MICROSOFT_REDIRECT_URI'] as $name)
		{
			$this->originalEnvironment[$name] = getenv($name);
		}
		$this->originalSession = $_SESSION ?? null;
		$_SESSION = [];
		$settingsProperty = new \ReflectionProperty(Settings::class, 'instance');
		$settingsProperty->setAccessible(true);
		$this->originalSettings = $settingsProperty->getValue();
		$settingsProperty->setValue(null, new class
		{
			public function get(string $key): array
			{
				return $key === 'server' ? ['install_id' => 'microsoft-oauth-test', 'encryptkey' => '', 'mcrypt_iv' => ''] : [];
			}
		});
		putenv('EMAIL_MICROSOFT_TENANT_ID=common');
		putenv('EMAIL_MICROSOFT_CLIENT_ID=test-client');
		putenv('EMAIL_MICROSOFT_CLIENT_SECRET=test-secret');
		putenv('EMAIL_MICROSOFT_REDIRECT_URI=https://example.test/portico/email/oauth/microsoft/callback');
	}

	protected function tearDown(): void
	{
		foreach ($this->originalEnvironment as $name => $value)
		{
			$value === false ? putenv($name) : putenv($name . '=' . $value);
		}
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

	public function testAuthorizationUrlUsesOutlookImapScopeAndPkce(): void
	{
		$url = MicrosoftImapOAuth::authorizationUrl(3, 'outlook@example.test');
		parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

		$this->assertSame('login.microsoftonline.com', parse_url($url, PHP_URL_HOST));
		$this->assertSame('test-client', $query['client_id']);
		$this->assertSame('https://example.test/portico/email/oauth/microsoft/callback', $query['redirect_uri']);
		$this->assertSame('outlook@example.test', $query['login_hint']);
		$this->assertStringContainsString('https://outlook.office.com/IMAP.AccessAsUser.All', $query['scope']);
		$this->assertStringContainsString('offline_access', $query['scope']);
		$this->assertSame('S256', $query['code_challenge_method']);

		$state = Cache::session_get('email', 'microsoft_imap_oauth_' . $query['state']);
		$this->assertSame(3, $state['account']);
		$this->assertSame(
			rtrim(strtr(base64_encode(hash('sha256', $state['verifier'], true)), '+/', '-_'), '='),
			$query['code_challenge']
		);
	}
}