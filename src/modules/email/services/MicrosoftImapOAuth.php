<?php

namespace App\modules\email\services;

use App\modules\phpgwapi\services\Cache;

final class MicrosoftImapOAuth
{
	private const STATE_PREFIX = 'microsoft_imap_oauth_';
	private const SCOPE = 'https://outlook.office.com/IMAP.AccessAsUser.All offline_access openid profile';

	public static function isConfigured(): bool
	{
		$config = self::configuration();
		return $config['client_id'] !== '' && $config['client_secret'] !== '' && $config['redirect_uri'] !== '';
	}

	public static function authorizationUrl(int $account, string $loginHint = ''): string
	{
		$config = self::configuration();
		if (!self::isConfigured()) throw new \RuntimeException('Microsoft OAuth is not configured on this server');

		$state = bin2hex(random_bytes(32));
		$verifier = self::base64UrlEncode(random_bytes(48));
		Cache::session_set('email', self::STATE_PREFIX . $state, [
			'account' => $account,
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
		if ($loginHint !== '') $parameters['login_hint'] = $loginHint;
		return self::authority($config['tenant']) . '/oauth2/v2.0/authorize?' . http_build_query($parameters);
	}

	public static function completeAuthorization(string $code, string $state): array
	{
		$config = self::configuration();
		if (!self::isConfigured()) throw new \RuntimeException('Microsoft OAuth is not configured on this server');
		if ($code === '' || !preg_match('/^[a-f0-9]{64}$/i', $state)) throw new \InvalidArgumentException('Invalid Microsoft OAuth callback');

		$stateKey = self::STATE_PREFIX . $state;
		$stateData = Cache::session_get('email', $stateKey);
		Cache::session_set('email', $stateKey, null);
		if (!is_array($stateData) || time() - (int) ($stateData['created'] ?? 0) > 600) throw new \RuntimeException('Microsoft OAuth state is missing or expired');

		$tokens = self::requestTokens([
			'grant_type' => 'authorization_code',
			'code' => $code,
			'redirect_uri' => $config['redirect_uri'],
			'code_verifier' => (string) ($stateData['verifier'] ?? ''),
		]);
		if (empty($tokens['refresh_token']) || empty($tokens['access_token'])) throw new \RuntimeException('Microsoft did not return the required IMAP OAuth tokens');
		return ['account' => (int) ($stateData['account'] ?? 0)] + $tokens;
	}

	public static function refreshAccessToken(string $refreshToken): array
	{
		if ($refreshToken === '') throw new \RuntimeException('Connect this email account to Microsoft before using OAuth');
		$tokens = self::requestTokens([
			'grant_type' => 'refresh_token',
			'refresh_token' => $refreshToken,
		]);
		if (empty($tokens['access_token'])) throw new \RuntimeException('Microsoft did not return an IMAP access token');
		$tokens['refresh_token'] = (string) ($tokens['refresh_token'] ?? $refreshToken);
		return $tokens;
	}

	private static function requestTokens(array $parameters): array
	{
		$config = self::configuration();
		$parameters['client_id'] = $config['client_id'];
		$parameters['client_secret'] = $config['client_secret'];
		$parameters['scope'] = self::SCOPE;
		$handle = curl_init(self::authority($config['tenant']) . '/oauth2/v2.0/token');
		curl_setopt_array($handle, [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => http_build_query($parameters),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT => 20,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
		]);
		$body = curl_exec($handle);
		$status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
		$error = curl_error($handle);
		curl_close($handle);
		if ($body === false || $status < 200 || $status >= 300) throw new \RuntimeException('Microsoft OAuth token request failed' . ($error !== '' ? ': ' . $error : ''));
		$tokens = json_decode((string) $body, true);
		if (!is_array($tokens) || isset($tokens['error'])) throw new \RuntimeException('Microsoft OAuth token response was invalid');
		return $tokens;
	}

	private static function configuration(): array
	{
		return [
			'tenant' => trim((string) (getenv('EMAIL_MICROSOFT_TENANT_ID') ?: 'common')),
			'client_id' => trim((string) getenv('EMAIL_MICROSOFT_CLIENT_ID')),
			'client_secret' => (string) getenv('EMAIL_MICROSOFT_CLIENT_SECRET'),
			'redirect_uri' => trim((string) getenv('EMAIL_MICROSOFT_REDIRECT_URI')),
		];
	}

	private static function authority(string $tenant): string
	{
		if (!preg_match('/^[A-Za-z0-9.-]+$/', $tenant)) throw new \RuntimeException('Invalid Microsoft tenant configuration');
		return 'https://login.microsoftonline.com/' . $tenant;
	}

	private static function base64UrlEncode(string $value): string
	{
		return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
	}
}
