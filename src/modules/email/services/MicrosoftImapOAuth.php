<?php

namespace App\modules\email\services;

use App\modules\phpgwapi\services\Cache;
use App\modules\phpgwapi\services\Settings;

final class MicrosoftImapOAuth
{
	private const STATE_PREFIX = 'microsoft_imap_oauth_';
	private const SCOPE = 'https://outlook.office.com/IMAP.AccessAsUser.All offline_access openid profile';

	public static function isConfigured(array $profile, array $accountPreferences = []): bool
	{
		$config = self::configuration($profile, $accountPreferences);
		return $config['client_id'] !== '' && $config['client_secret'] !== '' && $config['redirect_uri'] !== '';
	}

	public static function authorizationUrl(int $account, string $loginHint, array $profile, array $accountPreferences = []): string
	{
		$config = self::configuration($profile, $accountPreferences);
		if (!self::isConfigured($profile, $accountPreferences))
        {
        	throw new \RuntimeException('Microsoft OAuth client credentials are not configured on this account or its emailadmin profile');
        }

		$state = bin2hex(random_bytes(32));
		$verifier = self::base64UrlEncode(random_bytes(48));
		Cache::session_set('email', self::STATE_PREFIX . $state, [
			'account' => $account,
			'profile_id' => (int) ($profile['profileID'] ?? 0),
			'verifier' => $verifier,
			'created' => time(),
		]);

		$parameters = [
			'client_id' => $config['client_id'],
			'response_type' => 'code',
			'redirect_uri' => $config['redirect_uri'],
			'response_mode' => 'query',
			'scope' => self::SCOPE,
			'state' => $state,
			'code_challenge' => self::base64UrlEncode(hash('sha256', $verifier, true)),
			'code_challenge_method' => 'S256',
		];
		if ($loginHint !== '')
        {
        	$parameters['login_hint'] = $loginHint;
        }
		return self::authority($config['tenant']) . '/oauth2/v2.0/authorize?' . http_build_query($parameters);
	}

	public static function completeAuthorization(string $code, string $state, array $profile, ?array $accountPreferences = null): array
	{
		if ($code === '' || !preg_match('/^[a-f0-9]{64}$/i', $state))
        {
        	throw new \InvalidArgumentException('Invalid Microsoft OAuth callback');
        }

		$stateKey = self::STATE_PREFIX . $state;
		$stateData = Cache::session_get('email', $stateKey);
		Cache::session_set('email', $stateKey, null);
		if (!is_array($stateData) || time() - (int) ($stateData['created'] ?? 0) > 600)
        {
        	throw new \RuntimeException('Microsoft OAuth state is missing or expired');
        }
		if ((int) ($stateData['profile_id'] ?? 0) !== (int) ($profile['profileID'] ?? 0))
        {
        	throw new \RuntimeException('The assigned email profile changed during Microsoft OAuth');
        }
		$accountPreferences ??= self::accountPreferences((int) ($stateData['account'] ?? 0));
		$config = self::configuration($profile, $accountPreferences);
		if (!self::isConfigured($profile, $accountPreferences))
        {
        	throw new \RuntimeException('Microsoft OAuth client credentials are not configured on this account or its emailadmin profile');
        }

		$tokens = self::requestTokens([
			'grant_type' => 'authorization_code',
			'code' => $code,
			'redirect_uri' => $config['redirect_uri'],
			'code_verifier' => (string) ($stateData['verifier'] ?? ''),
		], $config);
		if (empty($tokens['refresh_token']) || empty($tokens['access_token']))
        {
        	throw new \RuntimeException('Microsoft did not return the required IMAP OAuth tokens');
        }
		return ['account' => (int) ($stateData['account'] ?? 0)] + $tokens;
	}

	public static function refreshAccessToken(string $refreshToken, array $profile, array $accountPreferences = []): array
	{
		if ($refreshToken === '')
        {
        	throw new \RuntimeException('Connect this email account to Microsoft before using OAuth');
        }
		$config = self::configuration($profile, $accountPreferences);
		if ($config['client_id'] === '' || $config['client_secret'] === '')
        {
        	throw new \RuntimeException('Microsoft OAuth client credentials are not configured on this account or its emailadmin profile');
        }
		$tokens = self::requestTokens([
			'grant_type' => 'refresh_token',
			'refresh_token' => $refreshToken,
		], $config);
		if (empty($tokens['access_token']))
        {
        	throw new \RuntimeException('Microsoft did not return an IMAP access token');
        }
		$tokens['refresh_token'] = (string) ($tokens['refresh_token'] ?? $refreshToken);
		return $tokens;
	}

	private static function requestTokens(array $parameters, array $config): array
	{
		$parameters['client_id'] = $config['client_id'];
		$parameters['client_secret'] = MailMessage::getInstance()->decrypt_email_passwd($config['client_secret']);
		$parameters['scope'] = self::SCOPE;
		$handle = curl_init(self::authority($config['tenant']) . '/oauth2/v2.0/token');
		curl_setopt_array($handle, self::withHttpProxy([
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => http_build_query($parameters),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT => 20,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
		]));
		$body = curl_exec($handle);
		$status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
		$error = curl_error($handle);
		curl_close($handle);
		if ($body === false || $status < 200 || $status >= 300)
        {
        	throw new \RuntimeException('Microsoft OAuth token request failed' . ($error !== '' ? ': ' . $error : ''));
        }
		$tokens = json_decode((string) $body, true);
		if (!is_array($tokens) || isset($tokens['error']))
        {
        	throw new \RuntimeException('Microsoft OAuth token response was invalid');
        }
		return $tokens;
	}

	private static function withHttpProxy(array $options): array
	{
		$server = (array) Settings::getInstance()->get('server');
		$proxy = trim((string) ($server['httpproxy_server'] ?? ''));
		$port = filter_var($server['httpproxy_port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
		if ($proxy === '' || $port === false)
        {
        	return $options;
        }
		$options[CURLOPT_PROXY] = $proxy;
		$options[CURLOPT_PROXYPORT] = $port;
		$options[CURLOPT_PROXYTYPE] = CURLPROXY_HTTP;
		$options[CURLOPT_HTTPPROXYTUNNEL] = true;
		return $options;
	}

	private static function configuration(array $profile, array $accountPreferences = []): array
	{
		$clientId = trim((string) ($accountPreferences['microsoft_client_id'] ?? ''));
		$clientSecret = trim((string) ($accountPreferences['microsoft_client_secret'] ?? ''));
		$tenant = trim((string) ($accountPreferences['microsoft_tenant_id'] ?? ''));
		return [
			'tenant' => $tenant !== '' ? $tenant : (trim((string) ($profile['microsoft_tenant_id'] ?? '')) ?: 'common'),
			'client_id' => $clientId !== '' ? $clientId : trim((string) ($profile['microsoft_client_id'] ?? '')),
			'client_secret' => $clientSecret !== '' ? $clientSecret : (string) ($profile['microsoft_client_secret'] ?? ''),
			'redirect_uri' => trim((string) ($profile['microsoft_redirect_uri'] ?? '')),
		];
	}

	private static function accountPreferences(int $account): array
	{
		$emailPreferences = (array) (\App\modules\phpgwapi\services\Preferences::getInstance()->read()['email'] ?? []);
		return $account === 0 ? $emailPreferences : (array) ($emailPreferences['ex_accounts'][$account] ?? []);
	}

	private static function authority(string $tenant): string
	{
		if (!preg_match('/^[A-Za-z0-9.-]+$/', $tenant))
        {
        	throw new \RuntimeException('Invalid Microsoft tenant configuration');
        }
		return 'https://login.microsoftonline.com/' . $tenant;
	}

	private static function base64UrlEncode(string $value): string
	{
		return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
	}
}
