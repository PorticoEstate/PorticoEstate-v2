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

// Enable error logging for this script
ini_set('log_errors', 'On');
ini_set('error_log', '/var/log/apache2/error.log');

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
		return $create_account->display_create();
	}

	public function create_mapping()
	{
		$CreateAccount = new \App\modules\phpgwapi\security\Sso\CreateMapping();
		return $CreateAccount->create_mapping();
	}

	public function get_cd()
	{
		return $this->sessions->cd_reason;
	}

	public function login()
	{
		// Handle passkey authentication when selected
		$login_type = \Sanitizer::get_var('type', 'string', 'GET');
		if ($login_type === 'passkey')
		{
			return $this->auth_passkey();
		}

		if ($this->serverSettings['auth_type'] == 'http' && isset($_SERVER['PHP_AUTH_USER']))
		{
			$login	 = $_SERVER['PHP_AUTH_USER'];
			$passwd	 = $_SERVER['PHP_AUTH_PW'];

			if (strstr($login, '#') === false && $this->logindomain)
			{
				$login .= "#{$this->logindomain}";
			}
			$this->_sessionid = $this->sessions->create($login, '');
			return array('session_id' => $this->_sessionid);
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
			return array('session_id' => $this->_sessionid);
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
			return array('session_id' => $this->_sessionid);
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
			return array('session_id' => $this->_sessionid);
		}

		/**
		 * OpenID Connect
		 */
		else if (in_array($this->serverSettings['auth_type'],  array('remoteuser', 'azure')) && empty($_REQUEST['skip_remote']))
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

				$groups = $Auth->get_groups();

				/**
				 * One last check...
				 */
				if (!\Sanitizer::get_var('OIDC_pid', 'string', 'SERVER'))
				{
					$default_group_lid	 = !empty($this->serverSettings['default_group_lid']) ? $this->serverSettings['default_group_lid'] : 'Default';
					$default_group_lid = strtolower($default_group_lid);

					if (!in_array(strtolower($default_group_lid), $groups))
					{
						throw new \Exception(lang('missing membership: "%1" is not in the list', $default_group_lid));
					}
				}

				$this->_sessionid = $this->sessions->create($login, '');
			}

			if (!$login || empty($this->_sessionid))
			{
				if (!empty($this->serverSettings['auto_create_acct']))
				{

					if ($this->serverSettings['mapping'] == 'id')
					{
						// Redirection to create the new account :
						return array('html' => $this->create_account());
					}
					else if ($this->serverSettings['mapping'] == 'table' || $this->serverSettings['mapping'] == 'all')
					{
						// Redirection to create a new mapping :
						return array('html' => $this->create_mapping());
					}
				}
			}

			return array('session_id' => $this->_sessionid);
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
			return array('session_id' => $this->_sessionid);
		}
	}

	/**
	 * Authenticate via passkey (FIDO2/WebAuthn)
	 * 
	 * @return array|null Authentication result
	 */
	protected function auth_passkey(): ?array
	{
		$db = \App\Database\Db::getInstance(); // Initialize database connection

		if (empty($_POST['data']))
		{
			// Simple WebAuthn form that doesn't rely on server-side challenge generation
			$html = <<<HTML
<!DOCTYPE html>
<html>
<head>
	<title>Simplified Passkey Login</title>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
	<style>
		body {
			background-color: #f8f9fa;
			padding-top: 40px;
		}
		.login-container {
			max-width: 400px;
			margin: 0 auto;
			padding: 15px;
			background: white;
			border-radius: 8px;
			box-shadow: 0 2px 10px rgba(0,0,0,0.1);
		}
		.login-header {
			text-align: center;
			margin-bottom: 30px;
		}
		#status-message {
			margin-top: 20px;
		}
		.loading-spinner {
			display: none;
			text-align: center;
			margin: 20px 0;
		}
		.back-button {
			margin-top: 20px;
			text-align: center;
		}
	</style>
</head>
<body>
	<div class="container">
		<div class="login-container">
			<div class="login-header">
				<h3>Login with Passkey</h3>
				<p>Use your device security (fingerprint, face recognition, or PIN) to login.</p>
			</div>

			<div class="text-center">
				<button id="passkey-button" class="btn btn-primary btn-lg">
					Continue with Passkey
				</button>
			</div>

			<div id="loading-spinner" class="loading-spinner">
				<div class="spinner-border text-primary" role="status">
					<span class="visually-hidden">Loading...</span>
				</div>
				<p>Waiting for your verification...</p>
			</div>

			<div id="status-message" class="alert d-none"></div>

			<div class="back-button">
				<a href="login.php" class="btn btn-link">Back to login options</a>
			</div>
		</div>
	</div>

	<script>
	document.addEventListener('DOMContentLoaded', function() {
		const passkeyButton = document.getElementById('passkey-button');
		const loadingSpinner = document.getElementById('loading-spinner');
		const statusMessage = document.getElementById('status-message');
		
		// Function to show status messages
		function showStatus(message, type) {
			statusMessage.textContent = message;
			statusMessage.classList.remove('d-none', 'alert-success', 'alert-danger', 'alert-info');
			statusMessage.classList.add('alert-' + type);
		}

		// Check if WebAuthn is supported by the browser
		if (!window.PublicKeyCredential) {
			showStatus('Your browser does not support passkeys. Please use a modern browser.', 'danger');
			passkeyButton.disabled = true;
			return;
		}

		// Helper function: Convert a base64url string to an ArrayBuffer
		function base64UrlToBuffer(base64url) {
			const base64 = base64url.replace(/-/g, '+').replace(/_/g, '/');
			const padLen = (4 - (base64.length % 4)) % 4;
			const padded = base64 + '='.repeat(padLen);
			const binary = atob(padded);
			const buffer = new ArrayBuffer(binary.length);
			const bytes = new Uint8Array(buffer);
			for (let i = 0; i < binary.length; i++) {
				bytes[i] = binary.charCodeAt(i);
			}
			return buffer;
		}

		// Helper function: Convert an ArrayBuffer to a base64url string
		function bufferToBase64Url(buffer) {
			const bytes = new Uint8Array(buffer);
			let binary = '';
			for (let i = 0; i < bytes.byteLength; i++) {
				binary += String.fromCharCode(bytes[i]);
			}
			const base64 = btoa(binary);
			return base64.replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
		}

		// Handle the button click to start authentication
		passkeyButton.addEventListener('click', async function() {
			try {
				passkeyButton.disabled = true;
				loadingSpinner.style.display = 'block';
				showStatus('Starting authentication...', 'info');
				
				// Generate a random challenge on the client side
				const challenge = new Uint8Array(32);
				window.crypto.getRandomValues(challenge);
				
				// Create a simplified publicKey request
				const publicKeyOptions = {
					challenge: challenge.buffer,
					timeout: 60000,
					userVerification: 'preferred',
					rpId: window.location.hostname,
				};
				
				console.log('Starting WebAuthn authentication with client-side challenge');
				
				// Start authentication request
				const credential = await navigator.credentials.get({
					publicKey: publicKeyOptions,
					mediation: 'optional'
				});
				
				showStatus('Processing authentication...', 'info');
				
				// Prepare response to send to server
				const response = {
					id: credential.id,
					rawId: bufferToBase64Url(credential.rawId),
					response: {
						clientDataJSON: bufferToBase64Url(credential.response.clientDataJSON),
						authenticatorData: bufferToBase64Url(credential.response.authenticatorData),
						signature: bufferToBase64Url(credential.response.signature),
						userHandle: credential.response.userHandle ? bufferToBase64Url(credential.response.userHandle) : null
					},
					type: credential.type,
					clientChallenge: bufferToBase64Url(challenge.buffer)
				};
				
				// Submit the response
				const form = document.createElement('form');
				form.method = 'POST';
				form.action = 'login.php?type=passkey';
				
				const dataInput = document.createElement('input');
				dataInput.type = 'hidden';
				dataInput.name = 'data';
				dataInput.value = JSON.stringify(response);
				
				form.appendChild(dataInput);
				document.body.appendChild(form);
				form.submit();
			} catch (error) {
				console.error('Authentication error:', error);
				loadingSpinner.style.display = 'none';
				passkeyButton.disabled = false;
				showStatus(error.message || 'Authentication failed. Please try again.', 'danger');
			}
		});
	});
	</script>
</body>
</html>
HTML;

			return [
				'html' => $html
			];
		}
		else
		{
			// Process passkey authentication from client
			try
			{
				// Parse credential data from POST
				$response = json_decode($_POST['data'], true);

				if (!$response || !isset($response['id']) || !isset($response['response']))
				{
					return null; // Invalid data
				}

				// Initialize the Auth_Passkeys class
				$auth_passkeys = new \App\modules\phpgwapi\security\Auth\Auth_Passkeys();

				// Get credential details from the response
				$clientDataJSON = $response['response']['clientDataJSON'];
				$authenticatorData = $response['response']['authenticatorData'];
				$signature = $response['response']['signature'];
				$credentialId = $response['rawId'];
				$userHandle = $response['response']['userHandle'] ?? null;

				// Skip using the session for challenge - instead set it directly from the client
				if (isset($response['clientChallenge']))
				{
					// Decode the client challenge
					$clientChallenge = \App\modules\phpgwapi\security\Auth\Auth_Passkeys::base64url_decode($response['clientChallenge']);
					// Store it in the session for the Auth_Passkeys class to use
					$_SESSION['webauthn_challenge'] = $clientChallenge;
				}

				try
				{
					// Try to authenticate with the provided credentials
					$username = $auth_passkeys->processAuthentication(
						$clientDataJSON,
						$authenticatorData,
						$signature,
						$credentialId,
						$userHandle
					);

					if (!empty($username))
					{
						// Authentication successful - look up user account
						$sql = "SELECT account_id, account_lid FROM phpgw_accounts WHERE account_lid = :username AND account_status = 'A'";
						$stmt = $db->prepare($sql);
						$stmt->execute([':username' => $username]);
						$account = $stmt->fetch(\PDO::FETCH_ASSOC);

						if ($account)
						{
							// Set up session

							$result = $this->sessions->create($account['account_lid'], '', true);

							if ($result)
							{
								// Success! Return session ID
								return [
									'session_id' => $this->sessions->get_session_id()
								];
							}
						}

						// If we get here, there was a problem with the account or session creation
						throw new \Exception('Account validation failed or session could not be created.');
					}
					else
					{
						throw new \Exception('Passkey authentication failed.');
					}
				}
				catch (\Exception $e)
				{
					// Authentication failed - show detailed error
					$debug_info = isset($GLOBALS['webauthn_debug']) ? $GLOBALS['webauthn_debug'] : [];

					$html = <<<HTML
<!DOCTYPE html>
<html>
<head>
	<title>Authentication Error</title>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
</head>
<body>
	<div class="container mt-5">
		<div class="card">
			<div class="card-header bg-danger text-white">
				<h4>Authentication Failed</h4>
			</div>
			<div class="card-body">
				<p>Credential verification error: {$e->getMessage()}</p>
				
				<div class="bg-light p-3 mb-3">
					<h5>Debug Information:</h5>
					<pre>
Authentication Debug Info:
-------------------------
<?php echo implode("\n", $debug_info); ?>
					</pre>
				</div>
				
				<div class="mt-4">
					<a href="login.php?type=passkey" class="btn btn-primary">Try Again</a>
					<a href="login.php" class="btn btn-secondary ms-2">Back to Login</a>
				</div>
			</div>
		</div>
	</div>
</body>
</html>
HTML;

					return [
						'html' => $html
					];
				}
			}
			catch (\Exception $e)
			{
				// General error handling
				$html = <<<HTML
<!DOCTYPE html>
<html>
<head>
	<title>Authentication Error</title>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
</head>
<body>
	<div class="container mt-5">
		<div class="card">
			<div class="card-header bg-danger text-white">
				<h4>Authentication Failed</h4>
			</div>
			<div class="card-body">
				<p>Error: {$e->getMessage()}</p>
				
				<div class="mt-4">
					<a href="login.php?type=passkey" class="btn btn-primary">Try Again</a>
					<a href="login.php" class="btn btn-secondary ms-2">Back to Login</a>
				</div>
			</div>
		</div>
	</div>
</body>
</html>
HTML;

				return [
					'html' => $html
				];
			}
		}
	}
}
