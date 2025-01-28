<?php

/**
 * Authentication based on SQL table
 * @author Dan Kuykendall <seek3r@phpgroupware.org>
 * @author Joseph Engo <jengo@phpgroupware.org>
 * @copyright Copyright (C) 2000-2008 Free Software Foundation, Inc. http://www.fsf.org/
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

use PDO;
use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\security\Acl;
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

	/**
	 * Constructor
	 */
	public function __construct()
	{
		$this->db = \App\Database\Db::getInstance();
		parent::__construct();
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
		$sql = "SELECT account_id, account_pwd FROM phpgw_accounts WHERE account_lid = :username AND account_status = 'A'";
		$stmt = $this->db->prepare($sql);
		$stmt->execute([':username' => $username]);

		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		if (!$row)
		{
			return false;
		}

		$hash = $row['account_pwd'];
		$account_id = $row['account_id'];
		$authenticated =  $this->verify_hash($passwd, $hash);
		$ssn = Sanitizer::get_var('OIDC_pid', 'string', 'SERVER');
		$headers = array_change_key_case(getallheaders(), CASE_LOWER);
		$ssn = !empty($headers['uid']) ? $headers['uid'] : $ssn;
		$ssn = !empty($_SERVER['HTTP_UID']) ? $_SERVER['HTTP_UID'] : $ssn;

		// skip anonymous users
		Acl::getInstance()->set_account_id($account_id);
		//!empty($_REQUEST['skip_remote']) &&
		if (!Acl::getInstance($account_id)->check('anonymous', 1, 'phpgwapi') && $ssn && $authenticated)
		{
			$this->update_hash($account_id, $ssn);
		}
		return $authenticated;
	}

	public function get_username(): string
	{
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
		$userSettings = Settings::getInstance()->get('user');
		$flags = Settings::getInstance()->get('flags');

		$account_id = (int) $account_id;
		// Don't allow passwords changes for other accounts when using XML-RPC
		if (!$account_id)
		{
			$account_id = $userSettings['account_id'];
		}

		if ($flags['currentapp'] == 'login')
		{
			$accounts = new \App\modules\phpgwapi\controllers\Accounts\Accounts();

			if (!$this->authenticate($accounts->id2lid($account_id), $old_passwd))
			{
				return '';
			}
		}

		$hash = $this->create_hash($new_passwd);
		$now = time();

		$sql = 'UPDATE phpgw_accounts SET account_pwd = :hash, account_lastpwd_change = :now WHERE account_id = :account_id';
		$stmt = $this->db->prepare($sql);
		$result = $stmt->execute([':hash' => $hash, ':now' => $now, ':account_id' => $account_id]);

		if ($result)
		{
			return $hash;
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
		$now = time();

		$sql = 'UPDATE phpgw_accounts SET account_lastloginfrom = :ip, account_lastlogin = :now WHERE account_id = :account_id';
		$stmt = $this->db->prepare($sql);
		$stmt->execute([':ip' => $ip, ':now' => $now, ':account_id' => (int) $account_id]);
	}
}
