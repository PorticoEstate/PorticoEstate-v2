<?php

/**
 * phpGroupWare - Login
 * @author Dan Kuykendall <seek3r@phpgroupware.org>
 * @author Joseph Engo <jengo@phpgroupware.org>
 * @author Sigurd Nes <sigurdne@online.no>
 * @copyright Copyright (C) 2000-2013 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License v2 or later
 * @package phpgwapi
 * @subpackage login
 * @version $Id$
 */

/*
		This program is free software: you can redistribute it and/or modify
		it under the terms of the GNU Lesser General Public License as published by
		the Free Software Foundation, either version 2 of the License, or
		(at your option) any later version.

		This program is distributed in the hope that it will be useful,
		but WITHOUT ANY WARRANTY; without even the implied warranty of
		MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
		GNU Lesser General Public License for more details.

		You should have received a copy of the GNU Lesser General Public License
		along with this program.  If not, see <http://www.gnu.org/licenses/>.
	 */

namespace App\modules\phpgwapi\security;

use App\modules\phpgwapi\security\Sessions;
use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\Hooks;


/**
 * Login - enables common handling of the login process from different part of the system
 *
 * @package phpgwapi
 * @subpackage login
 */
class Login
{
	private $flags;
	private $serverSettings;
	private $sessions;
	private $_sessionid = null;
	private $logindomain;

	public function __construct($settings = [])
	{
		$this->flags = Settings::getInstance()->get('flags');
		$this->serverSettings = Settings::getInstance()->get('server');
		$this->logindomain = \Sanitizer::get_var('domain', 'string', 'GET');

		/*
			 * Generic include for login.php like pages
			 */
		if (!empty($this->flags['session_name']))
		{
			$session_name = $this->flags['session_name'];
		}

		$this->flags = array_merge($this->flags, array(
			'disable_template_class' => true,
			'login'                  => true,
			'currentapp'             => 'login',
			'noheader'               => true
		));
		//		Settings::getInstance()->update('flags', ['noheader' => true, 'login' => true, 'currentapp' => 'login', 'disable_template_class' => true]);
		if (!empty($session_name))
		{
			$this->flags['session_name'] = $session_name;
		}
		//_debug_array($this->flags);die();
		/**
		 * check for emailaddress as username
		 */
		if (isset($_POST['login']) && $_POST['login'] != '')
		{
			if (!filter_var($_POST['login'], FILTER_VALIDATE_EMAIL))
			{
				$_POST['login'] = str_replace('@', '#', $_POST['login']);
			}
		}

		//		$_POST['submitit'] = true;
		$phpgw_remote_user_fallback	 = 'sql';
		$section = \Sanitizer::get_var('section', 'string', 'POST');

		if (isset($settings['session_name'][$section]))
		{
			$this->flags['session_name'] = $settings['session_name'][$section];
		}

		if (empty($_GET['create_account']) && !empty($_POST['login']) && in_array($this->serverSettings['auth_type'],  array('remoteuser', 'azure', 'customsso')))
		{
			$this->serverSettings['auth_type'] = $phpgw_remote_user_fallback;
		}

		if (!empty($_REQUEST['skip_remote'])) // In case a user failed logged in via SSO - get another try
		{
			$this->serverSettings['auth_type'] = $phpgw_remote_user_fallback;
		}
		Settings::getInstance()->set('flags', $this->flags);
		Settings::getInstance()->set('server', $this->serverSettings);

		$this->sessions = Sessions::getInstance();
	}

	public function create_account()
	{
		$create_account = new \App\modules\phpgwapi\security\Sso\CreateAccount();
		$create_account->display_create();
	}

	public function create_mapping()
	{
		$CreateAccount = new \App\modules\phpgwapi\security\Sso\CreateMapping();
		$CreateAccount->create_mapping();
	}

	public function get_cd()
	{
		return $this->sessions->cd_reason;
	}

	public function login()
	{
		if ($this->serverSettings['auth_type'] == 'http' && isset($_SERVER['PHP_AUTH_USER']))
		{
			$login	 = $_SERVER['PHP_AUTH_USER'];
			$passwd	 = $_SERVER['PHP_AUTH_PW'];

			if (strstr($login, '#') === false && $this->logindomain)
			{
				$login .= "#{$this->logindomain}";
			}
			$this->_sessionid = $this->sessions->create($login, '');
			return $this->_sessionid;
		}

		if ($this->serverSettings['auth_type'] == 'ntlm' && isset($_SERVER['REMOTE_USER']) && empty($_REQUEST['skip_remote']))
		{
			$remote_user = explode('@', $_SERVER['REMOTE_USER']);
			$login   = $remote_user[0]; //$_SERVER['REMOTE_USER'];
			$passwd	 = '';


			Settings::getInstance()->set('hook_values', array('account_lid' => $login));
			//------------------Start login ntlm


			if (strstr($login, '#') === false && $this->logindomain)
			{
				$login .= "#{$this->logindomain}";
			}

			$this->_sessionid = $this->sessions->create($login, $passwd);

			//----------------- End login ntlm
			return $this->_sessionid;
		}

		# Apache + mod_ssl style SSL certificate authentication
		# Certificate (chain) verification occurs inside mod_ssl
		if ($this->serverSettings['auth_type'] == 'sqlssl' && isset($_SERVER['SSL_CLIENT_S_DN']) && !isset($_GET['cd']))
		{
			# an X.509 subject looks like:
			# /CN=john.doe/OU=Department/O=Company/C=xx/Email=john@comapy.tld/L=City/
			# the username is deliberately lowercase, to ease LDAP integration
			$sslattribs	 = explode('/', $_SERVER['SSL_CLIENT_S_DN']);
			# skip the part in front of the first '/' (nothing)
			while ($sslattrib	 = next($sslattribs))
			{
				list($key, $val) = explode('=', $sslattrib);
				$sslattributes[$key] = $val;
			}

			if (isset($sslattributes['Email']))
			{

				# login will be set here if the user logged out and uses a different username with
				# the same SSL-certificate.
				if (!isset($_POST['login']) && isset($sslattributes['Email']))
				{
					$login	 = $sslattributes['Email'];
					# not checked against the database, but delivered to authentication module
					$passwd	 = $_SERVER['SSL_CLIENT_S_DN'];
				}

				if (strstr($login, '#') === false && $this->logindomain)
				{
					$login .= "#{$this->logindomain}";
				}

				$this->_sessionid = $this->sessions->create($login, $passwd);
			}
			unset($key);
			unset($val);
			unset($sslattributes);
			return $this->_sessionid;
		}

		if ($this->serverSettings['auth_type'] == 'customsso' &&  empty($_REQUEST['skip_remote']))
		{
			//Reset auth object
			$Auth = new \App\modules\phpgwapi\security\Auth\Auth();
			$login = $Auth->get_username();


			if ($login)
			{
				Settings::getInstance()->set('hook_values', array('account_lid' => $login));
				$hooks = new Hooks();
				$hooks->process('auto_addaccount', array('frontend', 'helpdesk'));
				if (strstr($login, '#') === false && $this->logindomain)
				{
					$login .= "#{$this->logindomain}";
				}

				$this->_sessionid = $this->sessions->create($login, '');
			}
			return $this->_sessionid;
		}

		/**
		 * OpenID Connect
		 */
		else if (
			in_array($this->serverSettings['auth_type'],  array('remoteuser', 'azure'))
			&& (isset($_SERVER['OIDC_upn']) || isset($_SERVER['REMOTE_USER']) || isset($_SERVER['OIDC_pid']))
			&& empty($_REQUEST['skip_remote'])
		)
		{
			//	print_r($this->serverSettings);

			$Auth = new \App\modules\phpgwapi\security\Auth\Auth();
			$login = $Auth->get_username();


			if ($login)
			{
				if (strstr($login, '#') === false && $this->logindomain)
				{
					$login .= "#{$this->logindomain}";
				}

				/**
				 * One last check...
				 */
				if (!\Sanitizer::get_var('OIDC_pid', 'string', 'SERVER'))
				{
					$ad_groups = array();
					if (!empty($_SERVER["OIDC_groups"]))
					{
						$OIDC_groups = mb_convert_encoding(mb_convert_encoding($_SERVER["OIDC_groups"], 'ISO-8859-1', 'UTF-8'), 'UTF-8', 'ISO-8859-1');
						$ad_groups	= explode(",", $OIDC_groups);
					}
					$default_group_lid	 = !empty($this->serverSettings['default_group_lid']) ? $this->serverSettings['default_group_lid'] : 'Default';
					$default_group_lid = strtolower($default_group_lid);
					$ad_groups = array_map('strtolower', $ad_groups);

					if (!in_array($default_group_lid, $ad_groups))
					{
						throw new \Exception(lang('missing membership: "%1" is not in the list', $default_group_lid));
					}
				}

				$this->_sessionid = $this->sessions->create($login, '');
			}
			else if (!$login || empty($this->_sessionid))
			{
				if (!empty($this->serverSettings['auto_create_acct']))
				{

					if ($this->serverSettings['mapping'] == 'id')
					{
						// Redirection to create the new account :
						return $this->create_account();
					}
					else if ($this->serverSettings['mapping'] == 'table' || $this->serverSettings['mapping'] == 'all')
					{
						// Redirection to create a new mapping :
						return $this->create_mapping();
					}
				}
			}

			return $this->_sessionid;
		}

		if (isset($_POST['login']) && $this->serverSettings['auth_type'] == 'sql')
		{

			$login	 = \Sanitizer::get_var('login', 'string', 'POST');
			// remove entities to stop mangling
			$passwd	 = html_entity_decode(\Sanitizer::get_var('passwd', 'string', 'POST'));

			$this->logindomain = \Sanitizer::get_var('logindomain', 'string', 'POST');
			if (strstr($login, '#') === false && $this->logindomain)
			{
				$login .= "#{$this->logindomain}";
			}

			$receipt = array();
			if (
				isset($this->serverSettings['usecookies'])
				&& $this->serverSettings['usecookies']
			)
			{
				if (isset($_COOKIE['domain']) && $_COOKIE['domain'] != $this->logindomain)
				{
					$this->sessions->phpgw_setcookie('domain');

					$receipt[] = lang('Info: you have changed domain from "%1" to "%2"', $_COOKIE['domain'], $this->logindomain);
				}
			}

			$this->_sessionid = $this->sessions->create($login, $passwd);

			if ($receipt)
			{
				\App\modules\phpgwapi\services\Cache::message_set($receipt, 'message');
			}
			return $this->_sessionid;
		}
	}
}
