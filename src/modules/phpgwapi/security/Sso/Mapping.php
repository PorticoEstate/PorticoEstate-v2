<?php

/**
 * Mapping REMOTE_USER to account_lid
 * @author DANG Quang Vu <quang_vu.dang@int-evry.fr>
 * @copyright Copyright (C) 2000-2004 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @package phpgwapi
 * @subpackage mapping
 * @version $Id$
 */

/**
 * With SSO service(Shibboleth,CAS,...) we want phpGroupware to take part in a federation of identity 
 * which provides several sources of identities.	Once a user has authenticated to SSO service 
 * we need to determine its account. So we add a mapping phase which will realise a mapping
 * between REMOTE_USER variable (user SSO) and phpGroupware account. There are two mapping types
 * Trivial mapping(mapping by unique id) and mapping by table
 * In the case there would be a successful match for trivial mapping for most users, 
 * but only a small number of failing cases, then a "sequential" mapping mechanism could be activated, 
 * In such cases, both mechanisms would then be applied sequentially : 
 * trivial mapping first, then mapping by table if no success.
 */

/**
 * this class manage mapping between REMOTE_USER variable (user SSO) and phpGroupware account 
 * using for Single Sign-On(Shibboleth,CAS,...)
 * Find a mapping for an user SSO
 * Add, Delete a mapping for a account
 * Allow, Deny a mapping
 * 
 */

namespace App\modules\phpgwapi\security\Sso;

use App\modules\phpgwapi\services\Settings;
use PDO;

$serverSettings = Settings::getInstance()->get('server');

switch ($serverSettings['account_repository'])
{
	case 'ldap':
		include_once SRC_ROOT_PATH . '/modules/phpgwapi/security/Sso/MappingLdap.php';
		break;
	default:
		include_once SRC_ROOT_PATH . '/modules/phpgwapi/security/Sso/MappingSql.php';
}


abstract class Mapping_
{
	/**
	 * @var string $location the location source authentication(name IdP in Shibboleth)
	 */
	var $location;
	/**
	 * @var string $auth_type the type authentication (shibboleth, remoteuser, ...)
	 */
	var $auth_type;

	protected $db;
	protected $serverSettings;


	/**
	 * constructor, sets up variables
	 * @param array $auth_info the information sur source authentication: location, auth_type
	 */
	function __construct($auth_info)
	{
		$this->location = $auth_info['location'];
		$this->auth_type = $auth_info['auth_type'];
		$this->db	= \App\Database\Db::getInstance();
		$this->serverSettings = Settings::getInstance()->get('server');
	}

	/**
	 * mapping_table
	 * function private
	 * this function find a mapping between REMOTE_USER variable and  account_lid variable 
	 * using phpgw_mapping table
	 * @param string $ext_user the REMOTE_USER of user SSO
	 * @return string account_lid if mapping success otherwise ''
	 */
	function mapping_table($ext_user)
	{
		$location = $this->location;
		$auth_type = $this->auth_type;

		$stmt = $this->db->prepare(
			"SELECT COALESCE(a.account_lid, m.account_lid) AS account_lid"
			. " FROM phpgw_mapping m"
			. " LEFT JOIN phpgw_accounts a ON a.account_id = m.account_id"
			. " WHERE m.ext_user = :ext_user AND m.status = 'A'"
			. " AND m.location = :location AND m.auth_type = :auth_type"
		);
		$stmt->execute([':ext_user' => $ext_user, ':location' => $location, ':auth_type' => $auth_type]);

		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		if ($row && isset($row['account_lid']))
		{
			return $row['account_lid'];
		}
		else
		{
			return '';
		}
	}

	abstract function mapping_uniqueid($ext_user);

	/**
	 * get_mapping
	 * function public
	 * this function find a mapping between REMOTE_USER variable and account_lid variable 
	 * using unique ID or phpgw_mapping table
	 * @param string $ext_user the REMOTE_USER of user SSO
	 * @return string account_lid if  mapping success otherwise ''
	 */
	function get_mapping($ext_user)
	{
		$account_lid = '';
		$mapping_type = $this->serverSettings['mapping'];
		if ($mapping_type == 'all' || $mapping_type == 'id') // using mapping by unique ID
		{
			$account_lid = $this->mapping_uniqueid($ext_user);
			if ($account_lid != '')
			{
				return $account_lid;
			}
		}
		// not use mapping by unique ID or mapping by unique ID is failed
		// using mapping by table 
		if ($mapping_type == 'all' || $mapping_type == 'table')
		{
			$account_lid = $this->mapping_table($ext_user);
			if ($account_lid != '')
			{
				return $account_lid;
			}
		}
		return '';
	}

	/**
	 * get_list
	 * function public
	 * this function get mapping list of an phpgw account using with phpgw_mapping table
	 * @param string $account_lid 
	 * @return array Mapping list of account_lid
	 */
	function get_list($account_lid)
	{

		$stmt = $this->db->prepare("SELECT * FROM phpgw_mapping WHERE account_lid = :account_lid");
		$stmt->execute([':account_lid' => $account_lid]);

		$data = array();
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
		{
			$data[] = array(
				'ext_user' => $row['ext_user'],
				'location' => $row['location'],
				'auth_type' => $row['auth_type'],
				'status' => $row['status']
			);
		}
		return $data;
	}

	/**
	 * add_mapping 
	 * function public
	 * this function add a mapping between REMOTE_USER variable and phpgw account 
	 * using with phpgw_mapping table 
	 * @param string $ext_user the REMOTE_USER of user SSO
	 * @param string $account_lid the id of existing account
	 */
	function add_mapping($ext_user, $account_lid)
	{

		$location = &$this->location;
		$auth_type = &$this->auth_type;
		$account_stmt = $this->db->prepare('SELECT account_id FROM phpgw_accounts WHERE account_lid = :account_lid');
		$account_stmt->execute([':account_lid' => $account_lid]);
		$account_id = $account_stmt->fetchColumn();

		$stmt = $this->db->prepare("SELECT account_lid FROM phpgw_mapping WHERE account_lid = :account_lid AND ext_user = :ext_user AND location = :location AND auth_type = :auth_type");
		$stmt->execute([':account_lid' => $account_lid, ':ext_user' => $ext_user, ':location' => $location, ':auth_type' => $auth_type]);

		if ($stmt->fetch())
		{
			$stmt = $this->db->prepare("UPDATE phpgw_mapping SET status = 'A', account_id = :account_id WHERE account_lid = :account_lid AND ext_user = :ext_user AND location = :location AND auth_type = :auth_type");
			$stmt->execute([':account_id' => $account_id ?: null, ':account_lid' => $account_lid, ':ext_user' => $ext_user, ':location' => $location, ':auth_type' => $auth_type]);
		}
		else
		{
			$stmt = $this->db->prepare("INSERT INTO phpgw_mapping (ext_user, account_lid, account_id, status, location, auth_type) VALUES (:ext_user, :account_lid, :account_id, 'A', :location, :auth_type)");
			$stmt->execute([':ext_user' => $ext_user, ':account_lid' => $account_lid, ':account_id' => $account_id ?: null, ':location' => $location, ':auth_type' => $auth_type]);
		}
	}

	/**
	 * exist_mapping
	 * function public
	 * this function check exist mapping of remoteuser in phpgw_mapping table using with mapping by table
	 * @param string $remoteuser the REMOTE_USER of user SSO
	 * @return string account_lid if remoteuser have mapping in phpgw_mapping to account_lid otherwise ''
	 */
	function exist_mapping($remoteuser)
	{

		$ext_user = $remoteuser;
		$location = &$this->location;
		$auth_type = &$this->auth_type;

		$stmt = $this->db->prepare(
			"SELECT COALESCE(a.account_lid, m.account_lid) AS account_lid"
			. " FROM phpgw_mapping m"
			. " LEFT JOIN phpgw_accounts a ON a.account_id = m.account_id"
			. " WHERE m.ext_user = :ext_user AND m.location = :location AND m.auth_type = :auth_type"
		);
		$stmt->execute([':ext_user' => $ext_user, ':location' => $location, ':auth_type' => $auth_type]);

		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		if ($row && isset($row['account_lid']))
		{
			return $row['account_lid'];
		}
		return '';
	}

	/**
	 * delete_mapping 
	 * function public
	 * this function delete mapping in phpgw_mapping table
	 * @param $mapping_info the information of a mapping
	 * account_lid for delete all mapping of account_lid
	 * account_lid,ext_user,location,auth_type for delete a mapping of account_lid
	 * @return boolean true if delete success otherwise false
	 */
	function delete_mapping($mapping_info)
	{

		$account_lid = &$mapping_info['account_lid'];
		$params = [':account_lid' => $account_lid];

		$sql = "DELETE FROM phpgw_mapping WHERE account_lid = :account_lid";
		if (isset($mapping_info['ext_user']))
		{
			$ext_user = &$mapping_info['ext_user'];
			$location = &$mapping_info['location'];
			$auth_type = &$mapping_info['auth_type'];

			$stmt = $this->db->prepare("SELECT account_lid FROM  phpgw_mapping WHERE account_lid = :account_lid AND ext_user = :ext_user AND location = :location AND auth_type = :auth_type");
			$stmt->execute([':account_lid' => $account_lid, ':ext_user' => $ext_user, ':location' => $location, ':auth_type' => $auth_type]);

			if (!$stmt->fetch()) // mapping does not exist
			{
				return false;
			}

			$sql .= " AND ext_user = :ext_user AND location = :location AND auth_type = :auth_type";
			$params[':ext_user'] = $ext_user;
			$params[':location'] = $location;
			$params[':auth_type'] = $auth_type;
		}

		$stmt = $this->db->prepare($sql);
		$stmt->execute($params);

		return true;
	}

	/**
	 * update_status
	 * function public
	 * this function change mapping status of a mapping using with phpgw_mapping table
	 * mapping status A(Allow), D(Deny)
	 * @param array $mapping_info the information of a mapping :account_lid,ext_user,location,auth_type,status
	 * @return boolean true if update success otherwise false
	 */
	function update_status($mapping_info)
	{

		$location = &$mapping_info['location'];
		$auth_type = &$mapping_info['auth_type'];
		$ext_user = &$mapping_info['ext_user'];
		$account_lid = &$mapping_info['account_lid'];
		$status = &$mapping_info['status'];

		$stmt = $this->db->prepare("SELECT account_lid FROM  phpgw_mapping WHERE account_lid = :account_lid AND ext_user = :ext_user AND location = :location AND auth_type = :auth_type");
		$stmt->execute([':account_lid' => $account_lid, ':ext_user' => $ext_user, ':location' => $location, ':auth_type' => $auth_type]);

		if (!$stmt->fetchColumn()) // mapping does not exist
		{
			return false;
		}

		$stmt = $this->db->prepare("UPDATE phpgw_mapping set status = :status WHERE account_lid = :account_lid AND ext_user = :ext_user AND location = :location AND auth_type = :auth_type");
		$stmt->execute([':status' => $status, ':account_lid' => $account_lid, ':ext_user' => $ext_user, ':location' => $location, ':auth_type' => $auth_type]);

		return true;
	}
}
