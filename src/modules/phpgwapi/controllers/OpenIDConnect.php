<?php

namespace App\modules\phpgwapi\controllers;

use Jumbojett\OpenIDConnectClient;
use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\Cache;
use App\modules\phpgwapi\controllers\Locations;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use App\modules\phpgwapi\security\Sessions;

class OpenIDConnect
{
	protected $serverSettings;
	private $config;
	private $oidc;
	private static $idToken;
	private static $type;
	private $debug;
	private $provider_type;

	private $simulate = false;

	private static $instance = null;

	private const SESSION_KEY = 'openid_connect_userinfo';
	private const SESSION_TIMESTAMP = 'openid_connect_timestamp';
	private const CACHE_LIFETIME = 300; // 5 minutes in seconds
	private const SESSION_AUTH_STATE = 'openid_connect_authenticated';


	function __construct($type = 'local', $config = [])
	{
        // Ensure session is started via Sessions singleton
		Sessions::getInstance();

		$this->debug = false;
		self::$type = $type;

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

		if ($this->simulate)
		{
			return;
		}

		if (empty($this->config))
		{
			return;
			throw new \Exception('Configuration for the specified type is missing.');
		}

		$this->debug = $this->config['debug'] ?? false;

		$provider_url = rtrim($this->config['provider_url'], '/');

		$this->oidc = new OpenIDConnectClient(
			$provider_url,
			$this->config['client_id'],
			$this->config['client_secret']
		);

		$this->provider_type = $this->getProviderType();

		if ($this->provider_type == 'idporten')
		{
			$this->oidc->setTokenEndpointAuthMethodsSupported(['client_secret_post']);
			// Enable PKCE with S256 method
			$this->oidc->setCodeChallengeMethod('S256');
		}
		
		if (!empty($this->config['scopes']))
		{
			$this->oidc->addScope(explode(' ', $this->config['scopes']));
		}

		// _debug_array($this->config);
		$this->oidc->setRedirectURL($this->config['redirect_uri'] ?? '');
	}

	// Prevent cloning
	private function __clone()
	{
	}

	// Prevent unserialization
	public function __wakeup()
	{
		throw new \Exception("Cannot unserialize singleton");
	}

	// Add getInstance method
	public static function getInstance($type = 'local', $config = []): self
	{
		if (self::$instance === null)
		{
			self::$instance = new self($type, $config);
		}
		return self::$instance;
	}
 
	public function authenticate()
	{
		Cache::session_set('openid_connect', self::SESSION_AUTH_STATE, true);

		if ($this->simulate)
		{
			//redirect to the callback url $this->config['redirect_uri'] with header()
			header('Location: ' . $this->config['redirect_uri']);
			exit;

		}

		$this->oidc->authenticate();
	}

	
	private function get_simulated_userinfo()
	{
		$userInfo = new \stdClass();
		//pid
		$userInfo->pid = '31015514496';//generert tilfeldig gyldig fødselsnummer
		$userInfo->upn = 'john.doe@example.com';
		$userInfo->groups = ['group1', 'default', 'Aktiv kommune brukere'];
		$userInfo->email = '';
		$userInfo->given_name = 'John';
		$userInfo->family_name = 'Doe';
		$userInfo->name = 'John Doe';
		$userInfo->preferred_username = 'johndoe';
		$userInfo->sub = '1234567890';
		$userInfo->locale = 'en-US';
		$userInfo->picture = 'https://example.com/johndoe.jpg';
		$userInfo->updated_at = 1234567890;
		$userInfo->email_verified = true;
		$userInfo->phone_number = '+1234567890';
		$userInfo->phone_number_verified = true;
		$userInfo->address = new \stdClass();
		$userInfo->address->street_address = '123 Main St';
		$userInfo->address->locality = 'Anytown';
		$userInfo->address->region = 'NY';
		$userInfo->address->postal_code = '12345';
		$userInfo->address->country = 'US';
		$userInfo->address->formatted = '123 Main St, Anytown, NY 12345, US';
		$userInfo->zoneinfo = 'America/New_York';
		$userInfo->birthdate = '1970-01-01';
		$userInfo->onpremisessamaccountname = 'johndoe';

		return $userInfo;

	}
	
	public function get_userinfo()
	{
		// Try to get from session first
		//$this->clearStoredUserInfo();
		$cachedInfo = $this->getStoredUserInfo();

		if ($cachedInfo)
		{
			return $cachedInfo;
		}

		static $userInfo = null;

		if ($userInfo)
		{
			return $userInfo;
		}

		if ($this->simulate)
		{
			$userInfo = $this->get_simulated_userinfo();
			Cache::session_set('openid_connect', self::SESSION_AUTH_STATE, true);
			$this->storeUserInfo($userInfo);
			return $userInfo;
		}

		if ($this->debug)
		{
			echo "Provider type: " . $this->provider_type . "<br>";
			if ($this->provider_type === 'idporten')
			{
				echo "Set token endpoint auth methods supported ['client_secret_post']<br>";
				echo "Set code challenge method to 'S256'<br>";
			}
		}

		$this->oidc->authenticate();
		self::$idToken = $this->oidc->getIdToken();
		Cache::session_set('openid_connect', 'idToken', self::$idToken);
		//store the type in the session
		Cache::session_set('openid_connect', 'type', self::$type);
		// Store the idToken in the settings for later use

		Settings::getInstance()->update('flags', ['openid_connect' => ['idToken' => self::$idToken, 'type' => self::$type]]);
		$decodedToken = null;

		if ($this->provider_type === 'azure')
		{

			// 1. Get the public keys from Azure AD's JWKS endpoint.  Jumbojett *might* handle this, but it's safer to do it explicitly:
			$issuer = $this->oidc->getIssuer();
			$jwksUri = rtrim($issuer, '/') . "/discovery/v2.0/keys"; // Construct JWKS URI
			$jwks = json_decode(file_get_contents($jwksUri), true);
			// Extract the kid from the JWT header
			$jwtHeader = json_decode(base64_decode(explode('.', self::$idToken)[0]), true);
			$kid = $jwtHeader['kid'];
			if ($this->debug)
			{
				echo "JWKS URI: $jwksUri<br>";
				echo "JWKS:<br>";
				_debug_array($jwks);
				echo "kid: $kid<br>";
			}
			// Find the correct key (usually only one for Azure AD)
			$publicKey = null;
			foreach ($jwks['keys'] as $key)
			{
				if ($key['kid'] === $kid && $key['kty'] === 'RSA')
				{
					// Assuming RSA key, which is common
					$publicKey = $this->generate_rsa_public_key($key['n'], $key['e']);
					break;
				}
			}
			if ($this->debug)
			{
				echo "PublicKey:<br>";
				_debug_array($publicKey);
				echo "idToken:<br>";
				_debug_array(self::$idToken);
			}
			// 2. Decode and validate the ID token
			try
			{
				$decodedToken = JWT::decode(self::$idToken, new Key($publicKey, 'RS256')); // RS256 is a common algorithm
			}
			catch (\Exception $e)
			{
				echo "Error decoding or validating token: " . $e->getMessage();
			}
		}
		// You can still use $this->oidc->requestUserInfo() if you need claims specifically from the /userinfo endpoint
		$userInfo = $this->oidc->requestUserInfo();
		$userInfo = $decodedToken ? $decodedToken : $userInfo;

		// Store in session before returning
		$this->storeUserInfo($userInfo);

		return $userInfo;
	}


	private function base64url_decode($data)
	{
		$padding = 4 - (strlen($data) % 4);
		if ($padding < 4)
		{
			$data .= str_repeat('=', $padding);
		}
		return base64_decode(strtr($data, '-_', '+/'));
	}

	private function generate_rsa_public_key($n_base64, $e_base64)
	{
		$n = $this->base64url_decode($n_base64);
		$e = $this->base64url_decode($e_base64);

		// Convert n and e to binary format
		$modulus = "\x00" . $n;  // Ensure the modulus is positive
		$exponent = $e;

		// DER encoding structure for RSA public key
		$modulus = pack('Ca*a*', 0x02, $this->encode_length(strlen($modulus)), $modulus);
		$exponent = pack('Ca*a*', 0x02, $this->encode_length(strlen($exponent)), $exponent);
		$rsa_key = pack('Ca*a*a*', 0x30, $this->encode_length(strlen($modulus . $exponent)), $modulus, $exponent);

		// Wrap in SubjectPublicKeyInfo structure
		$rsa_oid = pack('H*', '300d06092a864886f70d0101010500'); // ASN.1 encoding for rsaEncryption
		$public_key_info = pack('Ca*a*', 0x30, $this->encode_length(strlen($rsa_oid . "\x03" . $this->encode_length(strlen($rsa_key) + 1) . "\x00" . $rsa_key)), $rsa_oid . "\x03" . $this->encode_length(strlen($rsa_key) + 1) . "\x00" . $rsa_key);

		// Encode to PEM format
		$pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($public_key_info), 64, "\n") . "-----END PUBLIC KEY-----\n";

		return $pem;
	}

	private function encode_length($length)
	{
		if ($length < 128)
		{
			return chr($length);
		}
		$len = ltrim(pack('N', $length), "\x00");
		return chr(0x80 | strlen($len)) . $len;
	}

	private function getStoredUserInfo()
	{
		$stored = Cache::session_get('openid_connect', self::SESSION_KEY);
		$timestamp = Cache::session_get('openid_connect', self::SESSION_TIMESTAMP);

		if (!$stored || !$timestamp)
		{
			return null;
		}

		// Check if cache has expired
		if ((time() - $timestamp) > self::CACHE_LIFETIME)
		{
			$this->clearStoredUserInfo();
			return null;
		}

		return $stored;
	}

	private function storeUserInfo($userInfo): void
	{
		Cache::session_set('openid_connect', self::SESSION_KEY, $userInfo);
		Cache::session_set('openid_connect', self::SESSION_TIMESTAMP, time());
	}

	private function clearStoredUserInfo(): void
	{
		Cache::session_clear('openid_connect', self::SESSION_KEY);
		Cache::session_clear('openid_connect', self::SESSION_TIMESTAMP);
	}

	public function get_username(): string
	{
		$userInfo = $this->get_userinfo();
		if ($this->debug)
		{
			_debug_array($userInfo);
			die();
		}

		$response_variable = $this->config['response_variable'] ?? 'upn';
		return $userInfo->$response_variable;
	}

	public function get_groups(): array
	{
		$userInfo = $this->get_userinfo();
		$groups = $userInfo->groups ?? [];
		if (!$groups && !empty($this->config['groups']))
		{
			$groups = $this->config['groups'];
		}		
		return $groups;
	}

	public function get_type(): ?string
	{
		return Cache::session_get('openid_connect', 'type');
	}

	public function logout($idToken = null): void
	{
		if ($idToken === null)
		{
			$idToken = Cache::session_get('openid_connect', 'idToken');
		}
		if (!$idToken)
		{
			return;
		}
		$postLogoutRedirectUri = $this->config['redirect_logout_uri'] ?? null;
		Cache::session_clear('openid_connect', self::SESSION_AUTH_STATE);
		Cache::session_clear('openid_connect', 'idToken');
		$this->clearStoredUserInfo();
		$this->oidc->signOut($idToken, $postLogoutRedirectUri);
		self::$idToken = null;
	}

	public function isAuthenticated(): bool
	{
		// Check session first
		$auth_state = Cache::session_get('openid_connect', self::SESSION_AUTH_STATE);
		if ($auth_state)
		{
			return true;
		}
		return !empty(self::$idToken);
	}


	private function getProviderType()
	{
		$provider_url = rtrim($this->config['provider_url'], '/');
		$well_known_url = $provider_url . '/.well-known/openid-configuration';

		if ($this->debug)
		{
			error_log("Fetching provider configuration from: " . $well_known_url);
		}

		try
		{
			$configuration = json_decode(file_get_contents($well_known_url), true);

			if ($this->debug)
			{
				error_log("Provider configuration: " . print_r($configuration, true));
			}

			// Check for Azure AD specific indicators
			if (
				(strpos($configuration['issuer'], 'microsoftonline.com') !== false) ||
				(strpos($configuration['token_endpoint'], 'microsoftonline.com') !== false)
			)
			{
				return 'azure';
			}

			// Check for other common providers
			if (strpos($configuration['issuer'], 'accounts.google.com') !== false)
			{
				return 'google';
			}
			// Check for other common providers
			if (strpos($configuration['issuer'], 'idporten.no') !== false)
			{
				return 'idporten';
			}

			if (strpos($configuration['issuer'], 'auth0.com') !== false)
			{
				return 'auth0';
			}

			if (strpos($configuration['issuer'], 'okta.com') !== false)
			{
				return 'okta';
			}

			// Default to generic OpenID Connect provider
			return 'generic';
		}
		catch (\Exception $e)
		{
			if ($this->debug)
			{
				error_log("Error fetching provider configuration: " . $e->getMessage());
			}
			return 'unknown';
		}
	}
}
