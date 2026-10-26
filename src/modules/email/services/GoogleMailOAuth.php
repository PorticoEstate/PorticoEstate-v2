<?php

namespace App\modules\email\services;

use App\modules\phpgwapi\services\Cache;
use App\modules\phpgwapi\services\Settings;

final class GoogleMailOAuth
{
	private const STATE_PREFIX = 'google_mail_oauth_';
	private const SCOPE = 'openid email https://mail.google.com/';
	private const AUTHORIZATION_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
	private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
	private const USERINFO_ENDPOINT = 'https://openidconnect.googleapis.com/v1/userinfo';

	public static function isConfigured(array $profile, array $accountPreferences = []): bool
	{
		$config = self::configuration($profile, $accountPreferences);
		return $config['client_id'] !== '' && $config['client_secret'] !== '' && $config['redirect_uri'] !== '';
	}

	public static function authorizationUrl(int $account, string $emailAddress, array $profile, array $accountPreferences = []): string
	{
		$config = self::configuration($profile, $accountPreferences);
		if (!self::isConfigured($profile, $accountPreferences)) throw new \RuntimeException('Google OAuth client credentials and redirect URI are not configured');
		if (!filter_var($emailAddress, FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Enter the full Gmail address before connecting');

		$state = bin2hex(random_bytes(32));
		$verifier = self::base64UrlEncode(random_bytes(48));
		Cache::session_set('email', self::STATE_PREFIX . $state, [
			'account' => $account,
			'email' => strtolower($emailAddress),
			'profile_id' => (int) ($profile['profileID'] ?? 0),
			'verifier' => $verifier,
			'created' => time(),
		]);

		return self::AUTHORIZATION_ENDPOINT . '?' . http_build_query([
			'client_id' => $config['client_id'],
			'redirect_uri' => $config['redirect_uri'],
			'response_type' => 'code',
			'scope' => self::SCOPE,
			'state' => $state,
			'code_challenge' => self::base64UrlEncode(hash('sha256', $verifier, true)),
			'code_challenge_method' => 'S256',
			'access_type' => 'offline',
			'prompt' => 'consent',
			'include_granted_scopes' => 'true',
			'login_hint' => $emailAddress,
		]);
	}

	public static function completeAuthorization(string $code, string $state, array $profile, ?array $accountPreferences = null): array
	{
		if ($code === '' || !preg_match('/^[a-f0-9]{64}$/i', $state)) throw new \InvalidArgumentException('Invalid Google OAuth callback');

		$stateKey = self::STATE_PREFIX . $state;
		$stateData = Cache::session_get('email', $stateKey);
		Cache::session_set('email', $stateKey, null);
		if (!is_array($stateData) || time() - (int) ($stateData['created'] ?? 0) > 600) throw new \RuntimeException('Google OAuth state is missing or expired');
		if ((int) ($stateData['profile_id'] ?? 0) !== (int) ($profile['profileID'] ?? 0)) throw new \RuntimeException('The assigned email profile changed during Google OAuth');
		$accountPreferences ??= self::accountPreferences((int) ($stateData['account'] ?? 0));
		$config = self::configuration($profile, $accountPreferences);
		if (!self::isConfigured($profile, $accountPreferences)) throw new \RuntimeException('Google OAuth client credentials and redirect URI are not configured');

		$tokens = self::requestTokens([
			'grant_type' => 'authorization_code',
			'code' => $code,
			'redirect_uri' => $config['redirect_uri'],
			'code_verifier' => (string) ($stateData['verifier'] ?? ''),
		], $config);
		if (empty($tokens['refresh_token']) || empty($tokens['access_token'])) throw new \RuntimeException('Google did not return the required Gmail OAuth tokens');

		$identity = self::requestUserInfo((string) $tokens['access_token']);
		$authorizedEmail = strtolower(trim((string) ($identity['email'] ?? '')));
		if (empty($identity['email_verified']) || $authorizedEmail === '') throw new \RuntimeException('Google did not verify the connected email address');
		if (!hash_equals((string) ($stateData['email'] ?? ''), $authorizedEmail)) throw new \RuntimeException('The Google account does not match the selected email account');

		return [
			'account' => (int) ($stateData['account'] ?? 0),
			'email' => $authorizedEmail,
			'access_token' => (string) $tokens['access_token'],
			'refresh_token' => (string) $tokens['refresh_token'],
		];
	}

	public static function refreshAccessToken(string $refreshToken, array $profile, array $accountPreferences = []): array
	{
		if ($refreshToken === '') throw new \RuntimeException('Connect this Gmail account with Google before using OAuth');
		$config = self::configuration($profile, $accountPreferences);
		if (!self::isConfigured($profile, $accountPreferences)) throw new \RuntimeException('Google OAuth client credentials and redirect URI are not configured');
		$tokens = self::requestTokens([
			'grant_type' => 'refresh_token',
			'refresh_token' => $refreshToken,
		], $config);
		if (empty($tokens['access_token'])) throw new \RuntimeException('Google did not return a Gmail access token');
		$tokens['refresh_token'] = (string) ($tokens['refresh_token'] ?? $refreshToken);
		return $tokens;
	}

	private static function requestTokens(array $parameters, array $config): array
	{
		$parameters['client_id'] = $config['client_id'];
		$parameters['client_secret'] = MailMessage::getInstance()->decrypt_email_passwd($config['client_secret']);
		$handle = curl_init(self::TOKEN_ENDPOINT);
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
		if ($body === false || $status < 200 || $status >= 300) throw new \RuntimeException('Google OAuth token request failed' . ($error !== '' ? ': ' . $error : ''));
		$tokens = json_decode((string) $body, true);
		if (!is_array($tokens) || isset($tokens['error'])) throw new \RuntimeException('Google OAuth token response was invalid');
		return $tokens;
	}

	private static function requestUserInfo(string $accessToken): array
	{
		$handle = curl_init(self::USERINFO_ENDPOINT);
		curl_setopt_array($handle, self::withHttpProxy([
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken, 'Accept: application/json'],
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT => 20,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
		]));
		$body = curl_exec($handle);
		$status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
		$error = curl_error($handle);
		curl_close($handle);
		if ($body === false || $status < 200 || $status >= 300) throw new \RuntimeException('Google account verification failed' . ($error !== '' ? ': ' . $error : ''));
		$identity = json_decode((string) $body, true);
		if (!is_array($identity)) throw new \RuntimeException('Google account verification response was invalid');
		return $identity;
	}

	private static function configuration(array $profile, array $accountPreferences = []): array
	{
		$accountClientId = trim((string) ($accountPreferences['google_client_id'] ?? ''));
		$accountClientSecret = trim((string) ($accountPreferences['google_client_secret'] ?? ''));
		return [
			'client_id' => $accountClientId !== '' ? $accountClientId : trim((string) ($profile['google_client_id'] ?? '')),
			'client_secret' => $accountClientSecret !== '' ? $accountClientSecret : (string) ($profile['google_client_secret'] ?? ''),
			'redirect_uri' => trim((string) ($profile['google_redirect_uri'] ?? '')),
		];
	}

	private static function accountPreferences(int $account): array
	{
		$user = (array) Settings::getInstance()->get('user');
		$emailPreferences = (array) ($user['preferences']['email'] ?? []);
		return $account === 0 ? $emailPreferences : (array) ($emailPreferences['ex_accounts'][$account] ?? []);
	}

	private static function withHttpProxy(array $options): array
	{
		$server = (array) Settings::getInstance()->get('server');
		$proxy = trim((string) ($server['httpproxy_server'] ?? ''));
		$port = filter_var($server['httpproxy_port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
		if ($proxy === '' || $port === false) return $options;

		if (preg_match('~^https?://~i', $proxy))
		{
			$options[CURLOPT_PROXY] = $proxy;
		}
		else
		{
			$options[CURLOPT_PROXY] = $proxy;
			$options[CURLOPT_PROXYPORT] = $port;
		}
		$options[CURLOPT_PROXYTYPE] = CURLPROXY_HTTP;
		$options[CURLOPT_HTTPPROXYTUNNEL] = true;
		return $options;
	}

	private static function base64UrlEncode(string $value): string
	{
		return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
	}
}