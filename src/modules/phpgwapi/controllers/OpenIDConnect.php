<?php

namespace App\modules\phpgwapi\controllers;

use Jumbojett\OpenIDConnectClient;
use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\Cache;
use App\modules\phpgwapi\controllers\Locations;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class OpenIDConnect
{
	protected $serverSettings;
	private $config;
	private $oidc;
	private static $idToken;
	private static $type;
	private $debug;

	function __construct($type = 'local', $config = [])
	{
		$this->debug = false;

		if (!$config)
		{
			$location_obj = new Locations();
			$location_id		= $location_obj->get_id('admin', 'openid_connect');
			$config = (new \App\modules\phpgwapi\services\ConfigLocation($location_id))->read();
		}

		if (!empty($config[$type]))
		{
			$this->config = $config[$type];
		}

		if (empty($this->config))
		{
			throw new \Exception('Configuration for the specified type is missing.');
		}

		$this->debug = $this->config['debug'] ?? false;
		self::$type = $type;

		$this->oidc = new OpenIDConnectClient(
			$this->config['provider_url'],
			$this->config['client_id'],
			$this->config['client_secret']
		);
	}

	public function authenticate()
	{
		$this->oidc->setRedirectURL($this->config['redirect_uri']);
		$this->oidc->addScope(explode(' ', $this->config['scopes']));
		$this->oidc->authenticate();
	}

	public function get_userinfo()
	{
		$this->oidc->setRedirectURL($this->config['redirect_uri']);
		$this->oidc->addScope(explode(' ', $this->config['scopes']));
		$this->oidc->authenticate();
		self::$idToken = $this->oidc->getIdToken();

		Settings::getInstance()->update('flags', ['openid_connect' => ['idToken' => self::$idToken, 'type' => self::$type]]);
		$decodedToken = null;
		if ($this->debug)
		{
			// 1. Get the public keys from Azure AD's JWKS endpoint.  Jumbojett *might* handle this, but it's safer to do it explicitly:
			$jwksUri = $this->oidc->getIssuer() . "/discovery/v2.0/keys"; // Construct JWKS URI
			$jwks = json_decode(file_get_contents($jwksUri), true);
			// Find the correct key (usually only one for Azure AD)
			$publicKey = null;
			foreach ($jwks['keys'] as $key)
			{
				if ($key['kty'] === 'RSA')
				{ // Assuming RSA key, which is common
					$publicKey = "-----BEGIN PUBLIC KEY-----\n" . chunk_split($key['n'], 64, "\n") . "\n-----END PUBLIC KEY-----";
					break;
				}
			}
			if ($publicKey === null)
			{
				die("Public key not found in JWKS.");
			}
			// 2. Decode and validate the ID token
			try
			{
				$decodedToken = JWT::decode(self::$idToken, new Key($publicKey, 'RS256')); // RS256 is a common algorithm
				// $decodedToken = JWT::decode($idToken, $publicKey, array('RS256')); // older versions of firebase/php-jwt
				// Now $decodedToken contains the claims as an object.
				// Access them like this:
				//	echo "UPN: " . $decodedToken->upn . "<br>"; // Example: User Principal Name
				//	echo "Given Name: " . $decodedToken->given_name . "<br>";
				//	echo "Family Name: " . $decodedToken->family_name . "<br>";
				// ... access other claims as needed
				// You can also convert it to an array if you prefer:
				//	$tokenClaimsArray = (array) $decodedToken;
				//	print_r($tokenClaimsArray);
			}
			catch (\Exception $e)
			{
				echo "Error decoding or validating token: " . $e->getMessage();
			}
		}
		// You can still use $this->oidc->requestUserInfo() if you need claims specifically from the /userinfo endpoint
		$userInfo = $this->oidc->requestUserInfo();
		$userInfo = $decodedToken ? $decodedToken : $userInfo;

		return $userInfo;
	}

	public function get_username(): string
	{
		$userInfo = $this->get_userinfo();
		if ($this->debug)
		{
			_debug_array($userInfo);
			die();
		}

		$response_variable = $this->config['response_variable'] ?? 'email';
		return $userInfo->$response_variable;
	}

	public function get_user_email(): string
	{
		$userInfo = $this->get_userinfo();
		return $userInfo->email;
	}

	public function logout(): void
	{
		$idToken = Cache::session_get('openid_connect', 'idToken');
		$postLogoutRedirectUri = null;
		$this->oidc->signOut($idToken, $postLogoutRedirectUri);
		self::$idToken = null;
	}

	public function isAuthenticated(): bool
	{
		return !empty(self::$idToken);
	}

	public function getIdToken(): ?string
	{
		return self::$idToken;
	}

	public function refreshIdToken(): void
	{
		$userInfo = $this->get_userinfo();
		self::$idToken = $userInfo->id_token ?? null;
		Cache::session_set('openid_connect', 'idToken', self::$idToken);
	}
}
