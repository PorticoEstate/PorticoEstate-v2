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

	function __construct($type = 'local', $config = [])
	{

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

		//	_debug_array($this->config);
		//		die();

		$this->oidc = new OpenIDConnectClient(
			$this->config['authority'],
			$this->config['client_id'],
			$this->config['client_secret']
		);
	}

	public function authenticate()
	{
		$this->oidc->setRedirectURL($this->config['redirect_uri']);
		$this->oidc->addScope(explode(' ', $this->config['scopes']));
		$this->oidc->authenticate();
		$this->idToken = $this->oidc->getIdToken();
	}

	public function get_userinfo()
	{
		$this->oidc->setRedirectURL($this->config['redirect_uri']);
		$this->oidc->addScope(explode(' ', $this->config['scopes']));
		$this->oidc->authenticate();

		return $this->oidc->requestUserInfo();
	}

	public function get_username(): string
	{
		$userInfo = $this->get_userinfo();
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
