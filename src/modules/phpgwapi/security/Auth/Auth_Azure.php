<?php
	/**
	* Authentication based on Azure AD
	* @author Sigurd Nes <sigurdne@online.no>
	* @copyright Copyright (C) 2018 Free Software Foundation, Inc. http://www.fsf.org/
	* @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
	* @package phpgwapi
	* @subpackage accounts
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
	   GNU General Public License for more details.

	   You should have received a copy of the GNU Lesser General Public License
	   along with this program.  If not, see <http://www.gnu.org/licenses/>.
	 */

	namespace App\modules\phpgwapi\security\Auth;
	use App\modules\phpgwapi\security\Sso\Mapping;
	use App\modules\phpgwapi\services\Cache;
	use App\modules\phpgwapi\services\Settings;
	use PDO;
	use Sanitizer;

	/**
	* Authentication based on Azure AD
	*
	* @package phpgwapi
	* @subpackage accounts
	*/
	class Auth extends Auth_
	{

		private $db;
		private $mapping;

		public function __construct()
		{
			parent::__construct();
			$this->db = \App\Database\Db::getInstance();

			$phpgw_map_location = isset($_SERVER['HTTP_SHIB_ORIGIN_SITE']) ? $_SERVER['HTTP_SHIB_ORIGIN_SITE'] : 'local';
			$phpgw_map_authtype = isset($_SERVER['HTTP_SHIB_ORIGIN_SITE']) ? 'shibboleth' : 'remoteuser';

			$this->mapping = new Mapping(array('auth_type' => $phpgw_map_authtype, 'location' => $phpgw_map_location));
		}
		/**
		* Authenticate a user
		*
		* @param string $username the login to authenticate
		* @param string $passwd the password supplied by the user
		* @return bool did the user sucessfully authenticate
		*/
		public function authenticate($username, $passwd)
		{

			$sql = 'SELECT account_id FROM phpgw_accounts'
				. " WHERE account_lid = :username"
				. " AND account_status = 'A'";

			$stmt = $this->db->prepare($sql);
			$stmt->execute([':username' => $username]);

			$account_id = (int)$stmt->fetchColumn();
			$authenticated = $account_id !== 0;

			$ssn = Sanitizer::get_var('OIDC_pid', 'string', 'SERVER');
			if(!empty(Settings::getInstance()->get('flags')['openid_connect']['OIDC_pid']))
			{
				$ssn = Settings::getInstance()->get('flags')['openid_connect']['OIDC_pid'];
			}

			//get cookie
			$cookie_name = 'OIDC_pid';
			if(!empty($_COOKIE[$cookie_name]))
			{
				$ssn = $_COOKIE[$cookie_name];
				//delete cookie
				setcookie($cookie_name, '', time() - 3600, '/');				
			}

		// skip anonymous users
			$Acl = \App\modules\phpgwapi\security\Acl::getInstance($account_id);
			if (!$Acl->check('anonymous', 1, 'phpgwapi') && $ssn && $authenticated)
			{
				$this->update_hash($account_id, $ssn);
			}

			return $authenticated;

		}


		/**
		 * Ask azure for credential - and return the username
		 * @return string $usernamer
		 */
		public function get_username($primary = false): string
		{
			$remote_user_1 = explode('@', \Sanitizer::get_var('OIDC_upn', 'string', 'SERVER'));
			$remote_user_2 = \Sanitizer::get_var('OIDC_onpremisessamaccountname', 'string', 'SERVER');

			$_remote_user = $remote_user_2 ? $remote_user_2 : $remote_user_1[0];

			$ssn = Sanitizer::get_var('OIDC_pid', 'string', 'SERVER');

			$location_obj = new \App\modules\phpgwapi\controllers\Locations();
			$location_id	= $location_obj->get_id('admin', 'openid_connect');
			if ($location_id)
			{
				$config_openid = (new \App\modules\phpgwapi\services\ConfigLocation($location_id))->read();
			}

			/**
			 * OpenID Connect
			 */
			if (!empty($config_openid['common']['method_backend']))
			{

				$type = Sanitizer::get_var('type', 'string', 'GET', $config_openid['common']['method_backend'][0]);

				$OpenIDConnect = \App\modules\phpgwapi\controllers\OpenIDConnect::getInstance($type, $config_openid);

				/**
				 * If the user is authenticated, we can get the username
				 */
				$get_username_callback = Sanitizer::get_var('callback', 'string', 'GET', false);
				if ($OpenIDConnect->isAuthenticated() || $get_username_callback)
				{
					if($type == 'remote' || $OpenIDConnect->get_type() == 'remote')
					{
						$ssn = $OpenIDConnect->get_username();
						Cache::session_set('openid_connect', 'ssn', $ssn);
						Settings::getInstance()->update('flags', ['openid_connect' => ['OIDC_pid' => $ssn]]);
						//set cookie
						$cookie_name = 'OIDC_pid';
						$cookie_value = $ssn;
						setcookie($cookie_name, $cookie_value, time() +  180, "/"); // 180 seconds

					}
					else
					{
						$remote_user_3 = $OpenIDConnect->get_username();
						$remote_user_4 = explode('@', $remote_user_3);
						//The AD username directly, or the first part of the email address.
						$_remote_user = !empty($remote_user_4[1]) ? $remote_user_4[0] : $remote_user_3;
					}
				}
				else
				{
					$OpenIDConnect->authenticate();
					exit;
				}
			}


			if($primary)
			{
				return $_remote_user;
			}

			$username = $this->mapping->get_mapping($_remote_user);

			if(!$username)
			{
				$username = $this->mapping->get_mapping($_SERVER['REMOTE_USER']);
			}


			/**
			 * Azure from inside firewall
			 */
			if($username)
			{
				return $username;
			}

			/**
			 * ID-porten from outside firewall
			 */
			if(!$ssn)
			{
				return '';
			}

			$ssn_hash = "{SHA}" . base64_encode(self::hex2bin(sha1($ssn)));


			$sql = "SELECT account_lid FROM phpgw_accounts"
				. " JOIN phpgw_accounts_data ON phpgw_accounts.account_id = phpgw_accounts_data.account_id"
				. " WHERE account_data->>'ssn_hash' = :ssn_hash";
			$stmt = $this->db->prepare($sql);
			$stmt->execute([':ssn_hash' => $ssn_hash]);

			$row = $stmt->fetch(PDO::FETCH_ASSOC);
			$username = (string)$this->db->unmarshal($row['account_lid'], 'string');

			return $username;
		}

		public function get_groups()
		{
			$groups = array();
			if (!empty($_SERVER["OIDC_groups"]))
			{
				$OIDC_groups = mb_convert_encoding(mb_convert_encoding($_SERVER["OIDC_groups"], 'ISO-8859-1', 'UTF-8'), 'UTF-8', 'ISO-8859-1');
				$groups = explode(",", $OIDC_groups);
			}

			$location_obj = new \App\modules\phpgwapi\controllers\Locations();
			$location_id	= $location_obj->get_id('admin', 'openid_connect');
			if ($location_id)
			{
				$config_openid = (new \App\modules\phpgwapi\services\ConfigLocation($location_id))->read();
			}

			/**
			 * OpenID Connect
			 */
			if (!empty($config_openid['common']['method_backend']))
			{

				$type = Sanitizer::get_var('type', 'string', 'GET', $config_openid['common']['method_backend'][0]);
				$OpenIDConnect = \App\modules\phpgwapi\controllers\OpenIDConnect::getInstance($type, $config_openid);
				$groups = $OpenIDConnect->get_groups();
			}

			$groups = array_map('strtolower', $groups);

			return $groups;
		}
	
		/**
		* Set the user's password to a new value
		*
		* @param string $old_passwd the user's old password
		* @param string $new_passwd the user's new password
		* @param int $account_id the account to change the password for - defaults to current user
		* @return string the new encrypted hash, or an empty string on failure
		*/
		public function change_password($old_passwd, $new_passwd, $account_id = 0)
		{
			$userSettings = \App\modules\phpgwapi\services\Settings::getInstance()->get('user');
			$flags = \App\modules\phpgwapi\services\Settings::getInstance()->get('flags');
			$accounts = new \App\modules\phpgwapi\controllers\Accounts\Accounts();

			$account_id = (int) $account_id;
			// Don't allow passwords changes for other accounts when using XML-RPC
			if ( !$account_id )
			{
				$account_id = $userSettings['account_id'];
			}

			if ( $flags['currentapp'] == 'login')
			{
				if ( !$this->authenticate($accounts->id2lid($account_id), $old_passwd) )
				{
					return '';
				}
			}

			$hash_safe = $this->create_hash($new_passwd);
			
			$now = time();

			$sql = 'UPDATE phpgw_accounts'
				. " SET account_pwd = :hash_safe, account_lastpwd_change = :now"
				. " WHERE account_id = :account_id";

			$stmt = $this->db->prepare($sql);
			$stmt->bindParam(':hash_safe', $hash_safe);
			$stmt->bindParam(':now', $now);
			$stmt->bindParam(':account_id', $account_id);
			if ($stmt->execute()) {
				return $hash_safe;
			}
			return '';
		}

		/**
		* Update when the user last logged in
		*
		* @param int $account_id the user's account id
		* @param string $ip the source IP adddress for the request
		*/
		public function update_lastlogin($account_id, $ip)
		{
			$ip = $this->db->db_addslashes($ip);
			$account_id = (int) $account_id;
			$now = time();

			$sql = 'UPDATE phpgw_accounts'
				. " SET account_lastloginfrom = :ip,"
				. " account_lastlogin = :now"
				. " WHERE account_id = :account_id";

			$stmt = $this->db->prepare($sql);
			$stmt->bindParam(':ip', $ip);
			$stmt->bindParam(':now', $now);
			$stmt->bindParam(':account_id', $account_id);
			$stmt->execute();
		}
	}
