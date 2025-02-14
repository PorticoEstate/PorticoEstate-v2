<?php

namespace App\modules\phpgwapi\controllers;

use Jumbojett\OpenIDConnectClient;
use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\Cache;
use App\modules\phpgwapi\controllers\Locations;


class OpenIDConnect
{
	protected $serverSettings;
	private $config;
	private $oidc;
	private $idToken;
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
		$this->idToken = $this->oidc->getIdToken();
		Cache::session_set('openid_connect', 'idToken', $this->idToken);
		$userInfo = $this->oidc->requestUserInfo();
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
		$this->idToken = null;
	}

	public function isAuthenticated(): bool
	{
		return !empty($this->idToken);
	}
}
