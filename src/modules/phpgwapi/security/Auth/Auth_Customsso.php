<?php

/**
 * Authentication based on SQL table
 * @author Dan Kuykendall <seek3r@phpgroupware.org>
 * @author Joseph Engo <jengo@phpgroupware.org>
 * @author Sigurd Nes <sigurdne@online.no>
 * @copyright Copyright (C) 2013 Free Software Foundation, Inc. http://www.fsf.org/
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

use App\modules\phpgwapi\services\Cache;
use PDO;
use Sanitizer;

/**
 * Authentication based on SQL table
 *
 * @package phpgwapi
 * @subpackage accounts
 */
class Auth extends Auth_
{

	private $db;

	public function __construct()
	{
		parent::__construct();
		$this->db = \App\Database\Db::getInstance();
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
		$sql = 'SELECT account_id FROM phpgw_accounts WHERE account_lid = :username AND account_status = :status';
		$stmt = $this->db->prepare($sql);

		$stmt->execute([
			':username' => $username,
			':status' => 'A'
		]);

		$authenticated = !!$stmt->fetch();

		return $authenticated;
	}
	/* php ping function
		*/
	private function ping($host)
	{
		exec(sprintf('ping -c 1 -W 5 %s', escapeshellarg($host)), $res, $rval);
		return $rval === 0;
	}


	/**
	 * Direct account lookup supports a full email matching either the full
	 * account_lid or its local part (before @), preferring full-value matches.
	 * A short incoming username does not reverse-match a stored full email.
	 * Header username lookup applies only without SSN and OpenID Connect.
	 * OpenID Connect callback emails are checked before existing SSN and
	 * external database fallbacks; supplied SSNs use the existing SSN path.
	 */
	public function get_username(): string
	{
		$headers = array_change_key_case(getallheaders(), CASE_LOWER);
		$ssn = !empty($headers['uid']) ? $headers['uid'] : false;
		$ssn = !empty($_SERVER['HTTP_UID']) ? $_SERVER['HTTP_UID'] : $ssn;
		$ssn = !empty($_SERVER['HTTP_X_OIDC_CLAIM_PID']) ? $_SERVER['HTTP_X_OIDC_CLAIM_PID'] : $ssn;

		//ID-porten, Bergen kommune, portalen
		$oidc_claim_pid = !empty($headers['x-oidc-claim-pid']) ? $headers['x-oidc-claim-pid'] : false;

		if ($oidc_claim_pid)
		{
			$ssn = $oidc_claim_pid;
		}

		$upn = !empty($headers['upn']) ? $headers['upn'] : false;

		$remote_user = !empty($headers['remote_user']) ? $headers['remote_user'] : $upn;
		$username = $remote_user ? explode('@', $remote_user, 2)[0] : '';

		$location_obj = new \App\modules\phpgwapi\controllers\Locations();
		$location_id	= $location_obj->get_id('admin', 'openid_connect');
		if ($location_id)
		{
			$config_openid = (new \App\modules\phpgwapi\services\ConfigLocation($location_id))->read();
		}

		$use_openid = false;
		$epost = null;
		/**
		 * OpenID Connect
		 */
		if (!$ssn && !empty($config_openid['common']['method_backend']))
		{
			$use_openid = true;

			$type = Sanitizer::get_var('type', 'string', 'GET', $config_openid['common']['method_backend'][0]);
			$OpenIDConnect = \App\modules\phpgwapi\controllers\OpenIDConnect::getInstance($type, $config_openid);

			$get_username_callback = Sanitizer::get_var('callback', 'string', 'GET', false);
			if ($get_username_callback)
			{
				$epost = $OpenIDConnect->get_username();
			}
			else
			{
				$OpenIDConnect->authenticate();
				exit;
			}
		}


		/**
		 * Shibboleth from inside firewall
		 */
		if (!$use_openid && $username && !$ssn)
		{
			//force to use login.php
			//get me the route path from the http-request
			//			$routePath = $_SERVER['REQUEST_URI'];
			//			if (!preg_match('/\/login.php/', $routePath))
			//			{
			//				return '';
			//			}

			return $this->findAccountLid($this->getUsernameCandidates($remote_user)) ?: $username;
		}

		if ($epost)
		{
			$account_lid = $this->findAccountLid($this->getUsernameCandidates($epost));
			if ($account_lid)
			{
				return $account_lid;
			}
		}

		/**
		 * Shibboleth from outside firewall
		 */
		if (!$ssn && !$use_openid)
		{
			return '';
		}

		$hash_safe = "{SHA}" . base64_encode(self::hex2bin(sha1($ssn)));

		$sql = "SELECT account_lid FROM phpgw_accounts"
			. " JOIN phpgw_accounts_data ON phpgw_accounts.account_id = phpgw_accounts_data.account_id"
			. " WHERE account_data->>'ssn_hash' = :hash_safe";
		$stmt = $this->db->prepare($sql);
		$stmt->execute([':hash_safe' => $hash_safe]);

		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		$username = $row['account_lid'];

		if ($username)
		{
			return $username;
		}

		// Alternative config
		$location_id = $location_obj->get_id('property', '.admin');

		$config = (new \App\modules\phpgwapi\services\ConfigLocation($location_id))->read();

		try
		{
			if ($config['fellesdata']['host'])
			{
				if (!$this->ping($config['fellesdata']['host']))
				{
					$message = "Database server {$config['fellesdata']['host']} is not accessible";
					\App\modules\phpgwapi\services\Cache::message_set($message, 'error');
				}

				$dsn = "oci:dbname={$config['fellesdata']['host']}:{$config['fellesdata']['port']}/{$config['fellesdata']['db_name']}";
				$db = new PDO($dsn, $config['fellesdata']['user'], $config['fellesdata']['password']);
			}
			else
			{
				$config = (new \App\modules\phpgwapi\services\Config('rental'))->read();

				if (!$config['external_db_host'] || !$this->ping($config['external_db_host']))
				{
					$message = "Database server {$config['external_db_host']} is not accessible";
					\App\modules\phpgwapi\services\Cache::message_set($message, 'error');
				}

				$dsn = "{$config['external_db_type']}:host={$config['external_db_host']};port={$config['external_db_port']};dbname={$config['external_db_name']}";
				$db = new PDO($dsn, $config['external_db_user'], $config['external_db_password']);
			}
		}
		catch (\PDOException $e)
		{
			$message = lang('unable_to_connect_to_database');
			\App\modules\phpgwapi\services\Cache::message_set($message, 'error');
			return false;
		}

		if ($ssn)
		{
			$sql = "SELECT * FROM V_AD_BRUKERE WHERE FODSELSNR = :ssn";
			$stmt = $db->prepare($sql);
			$stmt->execute([':ssn' => $ssn]);
		}
		else if ($epost)
		{
			$sql = "SELECT BRUKERNAVN FROM V_AD_BRUKERE WHERE EPOST = :epost";
			$stmt = $db->prepare($sql);
			$stmt->execute([':epost' => $epost]);
		}
		else
		{
			return '';
		}

		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		if ($row)
		{
			$username = $row['BRUKERNAVN'];
			return $this->findAccountLid($this->getUsernameCandidates($username)) ?: $username;
		}
		else
		{
			return '';
		}
	}

	private function getUsernameCandidates($username): array
	{
		$username = trim((string) $username);
		if ($username === '')
		{
			return [];
		}

		return array_values(array_unique([$username, explode('@', $username, 2)[0]]));
	}

	private function findAccountLid(array $usernames): string
	{
		$sql = 'SELECT account_lid FROM phpgw_accounts'
			. " WHERE account_lid = :account_lid AND account_type = 'u'"
			. " AND account_status = 'A'";
		$stmt = $this->db->prepare($sql);

		foreach ($usernames as $username)
		{
			$stmt->execute([':account_lid' => $username]);
			$account_lid = $stmt->fetchColumn();
			if ($account_lid !== false)
			{
				return (string) $account_lid;
			}
		}

		return '';
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
		if (!$account_id)
		{
			$account_id = $userSettings['account_id'];
		}

		if ($flags['currentapp'] == 'login')
		{
			if (!$this->authenticate($accounts->id2lid($account_id), $old_passwd))
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
		$result = $stmt->execute([
			':hash_safe' => $hash_safe,
			':now' => $now,
			':account_id' => $account_id
		]);

		if ($result)
		{
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
		$account_id = (int) $account_id;
		$now = time();

		$sql = 'UPDATE phpgw_accounts SET account_lastloginfrom = :ip, account_lastlogin = :now WHERE account_id = :account_id';
		$stmt = $this->db->prepare($sql);

		$stmt->execute([
			':ip' => $ip,
			':now' => $now,
			':account_id' => $account_id
		]);
	}
}
