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
 * this class manage trivial mapping between REMOTE_USER variable (user SSO) and 
 * phpGroupware account using unique ID
 * using with Single Sign-On(Shibboleth,CAS,...)
 * Account repository using LDAP
 */

namespace App\modules\phpgwapi\security\Sso;

class Mapping extends Mapping_
{

	/**
	 * constructor, sets up variables
	 *
	 **/
	function __construct($auth_info = '')
	{
		parent::__construct($auth_info);
	}

	/**
	 * mapping_uniqueid
	 * function private
	 * this function find a mapping between REMOTE_USER variable and phpgw account using unique ID
	 * @param string $ext_user the REMOTE_USER of user SSO
	 * @return string account_lid if mapping success otherwise ''
	 */
	function mapping_uniqueid($ext_user)
	{
		if (!isset($this->serverSettings['mapping_field']) || $this->serverSettings['mapping_field'] == '')
		{
			$this->serverSettings['mapping_field'] = 'uid';
		}
		$phpgwapi_common = new \phpgwapi_common();
		$ds = $phpgwapi_common->ldapConnect();
		$user_context  = $this->serverSettings['ldap_context'];
		if (!$ds) return '';
		$sri = ldap_search($ds, $user_context, '(' . $this->serverSettings['mapping_field'] . '=' . $ext_user . ')');
		if (!$sri) return '';
		$ldap_test = ldap_get_entries($ds, $sri);
		if ($ldap_test["count"] == 0) return '';
		return $ldap_test[0]['uid'][0];
	}

	/**
	 * valid_user
	 * function public
	 * this function valid an user using login and password.
	 * @param string uid 
	 * @param string password
	 * @return true if login and password is correct otherwise false
	 */
	function valid_user($uid, $password)
	{
		$auth_type = $this->serverSettings['auth_type'];

		$this->serverSettings['auth_type'] = 'ldap';
		\App\modules\phpgwapi\services\Settings::getInstance()->set('server', $this->serverSettings);

		$Auth = new \App\modules\phpgwapi\security\Auth\Aauth();
		$ret = $Auth->authenticate($uid, $password);

		$this->serverSettings['auth_type'] = $auth_type;
		\App\modules\phpgwapi\services\Settings::getInstance()->set('server', $this->serverSettings);

		return $ret;
	}
}
