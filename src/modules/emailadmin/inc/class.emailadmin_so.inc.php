<?php

use App\Database\Db;
use App\modules\phpgwapi\services\Settings;

/***************************************************************************\
 * EGroupWare - EMailAdmin                                                   *
 * http://www.egroupware.org                                                 *
 * Written by : Lars Kneschke [lkneschke@egroupware.org]                     *
 * -------------------------------------------------                         *
 * This program is free software; you can redistribute it and/or modify it   *
 * under the terms of the GNU General Public License as published by the     *
 * Free Software Foundation; either version 2 of the License, or (at your    *
 * option) any later version.                                                *
	\***************************************************************************/
/* $Id$ */

class emailadmin_so
{
	var $phpgwapi_common;
	var $db;
	var $table = 'egw_emailadmin';
	var $db_cols = array(
		'ea_profile_id'			=> 'profileID',
		'ea_smtp_server'		=> 'smtpServer',
		'ea_smtp_type'			=> 'smtpType',
		'ea_smtp_port'			=> 'smtpPort',
		'ea_smtp_auth'			=> 'smtpAuth',
		'ea_editforwardingaddress'	=> 'editforwardingaddress',
		'ea_smtp_ldap_server'		=> 'smtpLDAPServer',
		'ea_smtp_ldap_basedn'		=> 'smtpLDAPBaseDN',
		'ea_smtp_ldap_admindn'		=> 'smtpLDAPAdminDN',
		'ea_smtp_ldap_adminpw'		=> 'smtpLDAPAdminPW',
		'ea_smtp_ldap_use_default'	=> 'smtpLDAPUseDefault',
		'ea_imap_server'		=> 'imapServer',
		'ea_imap_type'			=> 'imapType',
		'ea_imap_port'			=> 'imapPort',
		'ea_imap_login_type'		=> 'imapLoginType',
		'ea_imap_auth_username'			=> 'imapAuthUsername',
		'ea_imap_auth_password'		=> 'imapAuthPassword',
		'microsoft_tenant_id'		=> 'microsoft_tenant_id',
		'microsoft_client_id'		=> 'microsoft_client_id',
		'microsoft_client_secret'		=> 'microsoft_client_secret',
		'microsoft_redirect_uri'		=> 'microsoft_redirect_uri',
		'ea_imap_tsl_auth'		=> 'imapTLSAuthentication',
		'ea_imap_tsl_encryption'	=> 'imapTLSEncryption',
		'ea_imap_enable_cyrus'		=> 'imapEnableCyrusAdmin',
		'ea_imap_admin_user'		=> 'imapAdminUsername',
		'ea_imap_admin_pw'		=> 'imapAdminPW',
		'ea_imap_enable_sieve'		=> 'imapEnableSieve',
		'ea_imap_sieve_server'		=> 'imapSieveServer',
		'ea_imap_sieve_port'		=> 'imapSievePort',
		'ea_description'		=> 'description',
		'ea_default_domain'		=> 'defaultDomain',
		'ea_organisation_name'		=> 'organisationName',
		'ea_user_defined_identities'  => 'userDefinedIdentities',
		'ea_user_defined_accounts'	=> 'userDefinedAccounts',
		'ea_imapoldcclient'		=> 'imapoldcclient',
		'ea_order'			=> 'ea_order',
		'ea_active'			=> 'ea_active',
		'ea_group'			=> 'ea_group',
		'ea_user'          => 'ea_user',
		'ea_appname'			=> 'ea_appname',
		'ea_smtp_auth_username'		=> 'ea_smtp_auth_username',
		'ea_smtp_auth_password'		=> 'ea_smtp_auth_password',
		'ea_user_defined_signatures'	=> 'ea_user_defined_signatures',
		'ea_default_signature'		=> 'ea_default_signature',
	);

	function __construct()
	{
		$this->phpgwapi_common = new \phpgwapi_common();
		$this->db = Db::getInstance();
	}

	/**
	 * Convert array with internal values/names to db-column-names
	 *
	 * @param array $vals
	 * @return array
	 */
	function vals2db($vals)
	{
		$cols = array();
		foreach ($vals as $key => $val)
		{
			if (($k = array_search($key, $this->db_cols)) === false) $k = $key;

			$cols[$k] = $val;
		}
		return $cols;
	}

	/**
	 * Convert array with db-columns/-values to internal names
	 *
	 * @param array $vals
	 * @return array
	 */
	function db2vals($cols)
	{
		$vals = array();
		foreach ($cols as $key => $val)
		{
			if (isset($this->db_cols[$key])) $key = $this->db_cols[$key];

			$vals[$key] = $val;
		}
		return $vals;
	}

	private function mapDbColumns(array $values): array
	{
		$mapped = $this->vals2db($values);
		foreach (array_keys($mapped) as $column)
		{
			if (!array_key_exists($column, $this->db_cols))
			{
				unset($mapped[$column]);
			}
		}
		return $mapped;
	}

	private function fetchProfileId(string $where, array $parameters): ?int
	{
		$statement = $this->db->prepare(
			"SELECT ea_profile_id FROM {$this->table} WHERE {$where} ORDER BY ea_order"
		);
		$statement->execute($parameters);
		$id = $statement->fetchColumn();
		return $id === false ? null : (int) $id;
	}

	function updateProfile($_globalSettings, $_smtpSettings = array(), $_imapSettings = array())
	{
		$profileID = (int) $_globalSettings['profileID'];
		unset($_globalSettings['profileID']);
		$values = $this->mapDbColumns($_smtpSettings + $_globalSettings + $_imapSettings);
		if (!$values) return $profileID;

		$parameters = [];
		if ($profileID)
		{
			$assignments = [];
			foreach ($values as $column => $value)
			{
				$placeholder = ':value' . count($parameters);
				$assignments[] = $column . ' = ' . $placeholder;
				$parameters[$placeholder] = $value;
			}
			$parameters[':profile_id'] = $profileID;
			$statement = $this->db->prepare(
				"UPDATE {$this->table} SET " . implode(', ', $assignments) . ' WHERE ea_profile_id = :profile_id'
			);
			$statement->execute($parameters);
			return $profileID;
		}

		$columns = array_keys($values);
		$placeholders = [];
		foreach ($values as $value)
		{
			$placeholder = ':value' . count($parameters);
			$placeholders[] = $placeholder;
			$parameters[$placeholder] = $value;
		}
		$statement = $this->db->prepare(
			"INSERT INTO {$this->table} (" . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')'
		);
		$statement->execute($parameters);
		return (int) $this->db->get_last_insert_id($this->table, 'ea_profile_id');
	}

	function addProfile($_globalSettings, $_smtpSettings, $_imapSettings)
	{
		unset($_globalSettings['profileID']);	// just in case

		return $this->updateProfile($_globalSettings, $_smtpSettings, $_imapSettings);
	}

	function deleteProfile($_profileID)
	{
		$statement = $this->db->prepare("DELETE FROM {$this->table} WHERE ea_profile_id = :profile_id");
		$statement->execute([':profile_id' => (int) $_profileID]);
	}

	function getProfile($_profileID, $_fieldNames)
	{
		$columns = array_keys($this->mapDbColumns(array_fill_keys((array) $_fieldNames, null)));
		if (!$columns) return false;
		$statement = $this->db->prepare(
			"SELECT " . implode(', ', $columns) . " FROM {$this->table} WHERE ea_profile_id = :profile_id"
		);
		$statement->execute([':profile_id' => (int) $_profileID]);
		$data = $statement->fetch(\PDO::FETCH_ASSOC);
		return $data ? $this->db2vals($data) : false;
	}

	function getUserProfile($_appName, $_groups, $_user = NULL)
	{
		if (empty($_appName) || !is_array($_groups))
		{
			return false;
		}
		$groupIds = array_values(array_map('intval', $_groups));
		if (!$groupIds) $groupIds = [0];
		$groupPlaceholders = [];
		$parameters = [':app_name' => $_appName];
		foreach ($groupIds as $index => $groupId)
		{
			$placeholder = ':group_' . $index;
			$groupPlaceholders[] = $placeholder;
			$parameters[$placeholder] = (string) $groupId;
		}
		$where = '(ea_appname = :app_name OR ea_appname IS NULL OR ea_appname = \'\')'
			. ' AND (ea_group IN (' . implode(', ', $groupPlaceholders) . ') OR ea_group IS NULL OR ea_group = \'\')';
		if (!empty($_user))
		{
			$where .= ' AND (ea_user = :user_id OR ea_user IS NULL OR ea_user = \'0\' OR ea_user = \'\')';
			$parameters[':user_id'] = (string) (int) $_user;
		}
		$anyValues = 0;
		$globalDefaults = [];
		// retrieve the Global/Overall Settings
		$profileId = $this->fetchProfileId($where, $parameters);
		if ($profileId)
		{
			$globalDefaults = $this->getProfile($profileId, $this->db_cols);
			$anyValues++;
		}
		// retrieve application settings if set
		if (strlen($_appName) > 0)
		{
			$appId = $this->fetchProfileId('ea_appname = :app_name AND ea_active = 1', [':app_name' => $_appName]);
			if ($appId)
			{
				$appDefaults = $this->getProfile($appId, $this->db_cols);
				$globalDefaults = self::mergeProfileData($globalDefaults, $appDefaults);
				$anyValues++;
			}
		}
		// retrieve primary-group settings if set
		$primaryGroup = Settings::getInstance()->get('user')['account_primary_group'] ?? null;
		if ($primaryGroup && isset($_groups[1]) && (int) $_groups[1] === (int) $primaryGroup)
		{
			$groupId = $this->fetchProfileId('ea_group = :group_id AND ea_active = 1', [':group_id' => (string) (int) $_groups[1]]);
			if ($groupId)
			{
				$groupDefaults = $this->getProfile($groupId, $this->db_cols);
				$globalDefaults = self::mergeProfileData($globalDefaults, $groupDefaults);
				$anyValues++;
			}
		}
		// retrieve usersettings if set
		if (!empty($_user) && $_user != 0)
		{
			$userId = $this->fetchProfileId('ea_user = :user_id AND ea_active = 1', [':user_id' => (string) (int) $_user]);
			if ($userId)
			{
				$userDefaults = $this->getProfile($userId, $this->db_cols);
				$globalDefaults = self::mergeProfileData($globalDefaults, $userDefaults);
				$anyValues++;
			}
		}
		if ($anyValues)
		{
			return $globalDefaults;
		}
		else
		{
			return false;
		}
	}

	/*
		* merge profile data.
		* for each key of the mergeInTo Array check if there is a value set in the toMerge Array and replace it.
		*/
	static function mergeProfileData($mergeInTo, $toMerge)
	{
		if (is_array($toMerge) && count($toMerge) > 0)
		{
			$allkeys = array_unique(array_keys($mergeInTo) + array_keys($toMerge));
			foreach ($allkeys as $i => $key)
			{
				if (!array_key_exists($key, $mergeInTo) && array_key_exists($key, $toMerge) && !empty($toMerge[$key]))
				{
					$mergeInTo[$key] = $toMerge[$key];
				}
				else
				{
					if (array_key_exists($key, $toMerge) && !empty($toMerge[$key]))
					{
						switch ($key)
						{
							case 'ea_imap_server':
							case 'ea_imap_type':
							case 'ea_imap_port':
							case 'ea_imap_tsl_encryption':
							case 'ea_imap_tsl_auth':
								if (strlen($toMerge['ea_imap_server']) > 0) $mergeInTo[$key] = $toMerge[$key];
								break;
							case 'ea_smtp_port':
							case 'ea_smtp_type':
							case 'ea_smtp_server':
								if (strlen($toMerge['ea_smtp_server']) > 0) $mergeInTo[$key] = $toMerge[$key];
								break;
							case 'ea_default_signature':
								$userSettings = Settings::getInstance()->get('user') ?? [];
								$allowExternalImages = !empty($userSettings['preferences']['email']['allowExternalIMGs']);
								$mergeInTo[$key] = \Sanitizer::clean_email_signature((string) $toMerge[$key], $allowExternalImages);
								break;
							default:
								$mergeInTo[$key] = $toMerge[$key];
						}
					}
				}
			}
		}
		return $mergeInTo;
	}

	function getProfileList($_profileID = 0, $_defaultProfile = false)
	{
		$where = '';
		$parameters = [];
		if ((int) $_profileID)
		{
			$where = 'ea_profile_id = :profile_id';
			$parameters[':profile_id'] = (int) $_profileID;
		}
		elseif ($_defaultProfile)
		{
			$where = "ea_appname = :app_name AND ea_group = '0' AND ea_user = '0'";
			$parameters[':app_name'] = '';
		}
		$sql = "SELECT * FROM {$this->table}" . ($where !== '' ? ' WHERE ' . $where : '')
			. ((int) $_profileID ? '' : ' ORDER BY ea_order');
		$statement = $this->db->prepare($sql);
		$statement->execute($parameters);
		$rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
		if (!$rows) return false;
		$serverList = [];
		foreach ($rows as $row)
		{
			$serverList[] = $this->db2vals($row);
		}
		return $serverList;
	}

	function getUserData($_accountID)
	{
		$ldap = $this->phpgwapi_common->ldapConnect();

		if (($sri = @ldap_search($ldap, Settings::getInstance()->get('server')['ldap_context'], "(uidnumber=$_accountID)")))
		{
			$allValues = ldap_get_entries($ldap, $sri);
			if ($allValues['count'] > 0)
			{
				#print "found something<br>";
				$userData["mailLocalAddress"]		= $allValues[0]["mail"][0];
				$userData["mailAlternateAddress"]	= $allValues[0]["mailalternateaddress"];
				$userData["accountStatus"]		= $allValues[0]["accountstatus"][0];
				$userData["mailRoutingAddress"]		= $allValues[0]["mailforwardingaddress"];
				$userData["qmailDotMode"]		= $allValues[0]["qmaildotmode"][0];
				$userData["deliveryProgramPath"]	= $allValues[0]["deliveryprogrampath"][0];
				$userData["deliveryMode"]		= $allValues[0]["deliverymode"][0];

				unset($userData["mailAlternateAddress"]["count"]);
				unset($userData["mailRoutingAddress"]["count"]);

				return $userData;
			}
		}

		// if we did not return before, return false
		return false;
	}

	function saveUserData($_accountID, $_accountData)
	{
		$ldap = $this->phpgwapi_common->ldapConnect();
		// need to be fixed
		if (is_numeric($_accountID))
		{
			$filter = "uidnumber=$_accountID";
		}
		else
		{
			$filter = "uid=$_accountID";
		}

		$sri = @ldap_search($ldap, Settings::getInstance()->get('server')['ldap_context'], $filter);
		if ($sri)
		{
			$allValues 	= ldap_get_entries($ldap, $sri);
			$accountDN 	= $allValues[0]['dn'];
			$uid	   	= $allValues[0]['uid'][0];
			$homedirectory	= $allValues[0]['homedirectory'][0];
			$objectClasses	= $allValues[0]['objectclass'];

			unset($objectClasses['count']);
		}
		else
		{
			return false;
		}

		if (empty($homedirectory))
		{
			$homedirectory = "/home/" . $uid;
		}

		// the old code for qmail ldap
		$newData = array(
			'mail'			=> $_accountData["mailLocalAddress"],
			'mailAlternateAddress'	=> $_accountData["mailAlternateAddress"],
			'mailRoutingAddress'	=> $_accountData["mailRoutingAddress"],
			'homedirectory'		=> $homedirectory,
			'mailMessageStore'	=> $homedirectory . "/Maildir/",
			'gidnumber'		=> '1000',
			'qmailDotMode'		=> $_accountData["qmailDotMode"],
			'deliveryProgramPath'	=> $_accountData["deliveryProgramPath"]
		);

		if (
			!in_array('qmailUser', $objectClasses) &&
			!in_array('qmailuser', $objectClasses)
		)
		{
			$objectClasses[]	= 'qmailuser';
		}

		// the new code for postfix+cyrus+ldap
		$newData = array(
			'mail'			=> $_accountData["mailLocalAddress"],
			'accountStatus'		=> $_accountData["accountStatus"],
			'objectclass'		=> $objectClasses
		);

		if (is_array($_accountData["mailAlternateAddress"]))
		{
			$newData['mailAlternateAddress'] = $_accountData["mailAlternateAddress"];
		}
		else
		{
			$newData['mailAlternateAddress'] = array();
		}

		if ($_accountData["accountStatus"] == 'active')
		{
			$newData['accountStatus'] = 'active';
		}
		else
		{
			$newData['accountStatus'] = 'disabled';
		}

		if (!empty($_accountData["deliveryMode"]))
		{
			$newData['deliveryMode'] = $_accountData["deliveryMode"];
		}
		else
		{
			$newData['deliveryMode'] = array();
		}


		if (is_array($_accountData["mailRoutingAddress"]))
		{
			$newData['mailForwardingAddress'] = $_accountData["mailRoutingAddress"];
		}
		else
		{
			$newData['mailForwardingAddress'] = array();
		}

		#print "DN: $accountDN<br>";
		ldap_mod_replace($ldap, $accountDN, $newData);
		#print ldap_error($ldap);

		// also update the account_email field in egw_accounts
		// when using sql account storage
		if (Settings::getInstance()->get('server')['account_repository'] == 'sql')
		{
			$statement = $this->db->prepare('UPDATE egw_accounts SET account_email = :email WHERE account_id = :account_id');
			$statement->execute([
				':email' => $_accountData['mailLocalAddress'],
				':account_id' => (int) $_accountID,
			]);
		}
		return true;
	}

	function setOrder($_order)
	{
		$statement = $this->db->prepare("UPDATE {$this->table} SET ea_order = :order WHERE ea_profile_id = :profile_id");
		foreach ($_order as $order => $profileID)
		{
			$statement->execute([
				':order' => (int) $order,
				':profile_id' => (int) $profileID,
			]);
		}
	}
}
