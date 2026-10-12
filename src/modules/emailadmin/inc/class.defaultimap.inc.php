<?php
require_once __DIR__ . '/../../email/inc/imap_config.php';

use App\modules\phpgwapi\services\Cache;
use Webklex\PHPIMAP\ClientManager;

/***************************************************************************\
 * EGroupWare - EMailAdmin                                                   *
 * http://www.egroupware.org                                                 *
 * Written by : Lars Kneschke [lkneschke@linux-at-work.de]                   *
 * -------------------------------------------------                         *
 * This program is free software; you can redistribute it and/or modify it   *
 * under the terms of the GNU General Public License as published by the     *
 * Free Software Foundation; either version 2 of the License, or (at your    *
 * option) any later version.                                                *
	\***************************************************************************/
/* $Id$ */

define('IMAP_NAMESPACE_PERSONAL', 'personal');
define('IMAP_NAMESPACE_OTHERS', 'others');
define('IMAP_NAMESPACE_SHARED', 'shared');
define('IMAP_NAMESPACE_ALL', 'all');

/**
 * This class holds all information about the imap connection.
 * This is the base class for all other imap classes.
 *
 */

class defaultimap
{
	var $phpgwapi_common;
	var $clientManager;
	var $client;
	var $_connected = false;
	var $adminPassword;
	var $adminUsername;
	var $encryption;
	var $host;
	var $password;
	var $port = 143;
	var $username;
	var $domainname = false;
	var $validatecert;
	var $mailboxDelimiter = '/';

	/**
	 * the mailbox prefix. maybe used by uw-imap only?
	 *
	 * @var string
	 */
	var $mailboxPrefix = '~/mail';

	/**
	 * is the mbstring extension available
	 *
	 * @var unknown_type
	 */
	var $mbAvailable;

	/**
	 * Mailboxes which get automatic created for new accounts (INBOX == '')
	 *
	 * @var array
	 */
	var $imapLoginType;
	var $defaultDomain;


	/**
	 * disable internal conversion from/to UTF7
	 *
	 * @var array
	 */
	var $_useUTF_7 = false;

	/**
	 * a debug switch
	 */
	var $debug = false;

	var $_isConnected, $sessionData, $loginType, $domainName, $isAdminConnection, $loginName, $_connectionErrorObject;

	/**
	 * the construtor
	 *
	 * @return void
	 */
	function __construct()
	{
		$this->phpgwapi_common = new \phpgwapi_common();
		$this->clientManager = new ClientManager();
		if (function_exists('mb_convert_encoding'))
		{
			$this->mbAvailable = TRUE;
		}

		$this->restoreSessionData();
	}

	/**
	 * adds a account on the imap server
	 *
	 * @param array $_hookValues
	 * @return bool true on success, false on failure
	 */
	function addAccount($_hookValues)
	{
		return true;
	}

	/**
	 * updates a account on the imap server
	 *
	 * @param array $_hookValues
	 * @return bool true on success, false on failure
	 */
	function updateAccount($_hookValues)
	{
		return true;
	}

	/**
	 * deletes a account on the imap server
	 *
	 * @param array $_hookValues
	 * @return bool true on success, false on failure
	 */
	function deleteAccount($_hookValues)
	{
		return true;
	}

	function disconnect()
	{
		if ($this->client)
		{
			try
			{
				$this->client->disconnect();
			}
			catch (\Throwable $exception)
			{
				$this->_connectionErrorObject = $exception;
			}
		}
		$this->_connected = false;
		$this->_isConnected = false;
	}

	/**
	 * converts a UTF-8 foldername to IMAP UTF7
	 *
	 * @param string $_folderName
	 * @return string the encoded foldername
	 */
	function encodeFolderName($_folderName)
	{
		if ($this->mbAvailable)
		{
			return mb_convert_encoding($_folderName, 'UTF7-IMAP', 'UTF-8');
		}

		// if not
		// we can encode only from ISO 8859-1
		return IMAPManager::imap_utf7_encode($_folderName);
	}

	private function protocol()
	{
		if (!$this->client)
		{
			throw new \RuntimeException('IMAP connection has not been opened');
		}
		return $this->client->getConnection();
	}

	function hasCapability($_capability)
	{
		$capabilities = $this->getCapabilities();
		foreach ((array) $capabilities as $capability)
		{
			if (strcasecmp((string) $capability, (string) $_capability) === 0) return true;
		}
		return false;
	}

	/**
	 * returns the supported capabilities of the imap server
	 * return false if the imap server does not support capabilities
	 * 
	 * @return array the supported capabilites
	 */
	function getCapabilities()
	{
		try
		{
			if (!isset($this->sessionData['capabilities'][$this->host]))
			{
				$this->sessionData['capabilities'][$this->host] = $this->protocol()->getCapabilities()->validatedData();
			}
			return $this->sessionData['capabilities'][$this->host];
		}
		catch (\Throwable $exception)
		{
			$this->_connectionErrorObject = $exception;
			return false;
		}
	}

	/**
	 * return the delimiter used by the current imap server
	 *
	 * @return string the delimimiter
	 */
	function getDelimiter()
	{
		return isset($this->sessionData['delimiter'][$this->host]) ? $this->sessionData['delimiter'][$this->host] : $this->mailboxDelimiter;
	}

	/**
	 * Create transport string
	 *
	 * @return string the transportstring
	 */
	function _getTransportString()
	{
		if ($this->encryption == 2)
		{
			$connectionString = "tls://" . $this->host;
		}
		elseif ($this->encryption == 3)
		{
			$connectionString = "ssl://" . $this->host;
		}
		else
		{
			// no tls
			$connectionString = $this->host;
		}

		return $connectionString;
	}

	/**
	 * Create the options array for SSL/TLS connections
	 *
	 * @return string the transportstring
	 */
	function _getTransportOptions()
	{
		if ($this->validatecert === false)
		{
			if ($this->encryption == 2)
			{
				return array(
					'tls' => array(
						'verify_peer' => false,
						'allow_self_signed' => true,
					)
				);
			}
			elseif ($this->encryption == 3)
			{
				return array(
					'ssl' => array(
						'verify_peer' => false,
						'allow_self_signed' => true,
					)
				);
			}
		}
		else
		{
			if ($this->encryption == 2)
			{
				return array(
					'tls' => array(
						'verify_peer' => true,
						'allow_self_signed' => false,
					)
				);
			}
			elseif ($this->encryption == 3)
			{
				return array(
					'ssl' => array(
						'verify_peer' => true,
						'allow_self_signed' => false,
					)
				);
			}
		}

		return null;
	}

	/**
	 * Create mailbox string from given mailbox-name and user-name
	 *
	 * @param string $_folderName='' 
	 * @return string utf-7 encoded (done in getMailboxName)
	 */
	function getUserMailboxString($_username, $_folderName = '')
	{
		$nameSpaces = $this->getNameSpaces();

		if (!isset($nameSpaces['others']))
		{
			return false;
		}

		$username = $_username;

		if ($this->loginType == 'vmailmgr')
		{
			$username .= '@' . $this->domainName;
		}

		$mailboxString = $nameSpaces['others'][0]['name'] . $username . (!empty($_folderName) ? $nameSpaces['others'][0]['delimiter'] . $_folderName : '');

		return $mailboxString;
	}
	/**
	 * get list of namespaces
	 *
	 * @return array array containing information about namespace
	 */
	function getNameSpaces()
	{
		if (!$this->_isConnected) return false;

		$result = [];
		try
		{
			$response = $this->protocol()->requestAndResponse('NAMESPACE', [], true);
			$namespaceLine = '';
			foreach ($response->getResponse() as $line)
			{
				if (stripos((string) $line, '* NAMESPACE ') === 0)
				{
					$namespaceLine = trim(substr((string) $line, strlen('* NAMESPACE ')));
					break;
				}
			}
			$groups = [];
			$position = 0;
			$length = strlen($namespaceLine);
			for ($groupIndex = 0; $groupIndex < 3 && $position < $length; $groupIndex++)
			{
				while ($position < $length && ctype_space($namespaceLine[$position])) $position++;
				if (strtoupper(substr($namespaceLine, $position, 3)) === 'NIL')
				{
					$groups[] = null;
					$position += 3;
					continue;
				}
				if ($position >= $length || $namespaceLine[$position] !== '(') break;

				$start = $position;
				$depth = 0;
				$quoted = false;
				$escaped = false;
				for (; $position < $length; $position++)
				{
					$character = $namespaceLine[$position];
					if ($quoted)
					{
						if ($escaped) $escaped = false;
						elseif ($character === '\\') $escaped = true;
						elseif ($character === '"') $quoted = false;
						continue;
					}
					if ($character === '"') $quoted = true;
					elseif ($character === '(') $depth++;
					elseif ($character === ')' && --$depth === 0)
					{
						$position++;
						break;
					}
				}
				$groups[] = substr($namespaceLine, $start, $position - $start);
			}
			$keys = ['personal', 'others', 'shared'];
			foreach ($groups as $index => $group)
			{
				if (strtoupper(trim($group)) === 'NIL') continue;
				if (preg_match_all('/\("((?:\\\\.|[^"\\\\])*)"\s+"((?:\\\\.|[^"\\\\])*)"/', $group, $matches, PREG_SET_ORDER))
				{
					foreach ($matches as $match)
					{
						$result[$keys[$index]][] = [
							'name' => stripcslashes($match[1]),
							'delimiter' => stripcslashes($match[2]),
						];
					}
				}
			}
		}
		catch (\Throwable $exception)
		{
			$this->_connectionErrorObject = $exception;
		}

		$result['personal'] = $result['personal'] ?? [['name' => '', 'delimiter' => $this->mailboxDelimiter ?: '/']];

		return $result;
	}

	function getMailboxes($_reference = '', $_pattern = '*', $_return = false)
	{
		try
		{
			return $this->protocol()->folders((string) $_reference, (string) $_pattern)->validatedData();
		}
		catch (\Throwable $exception)
		{
			$this->_connectionErrorObject = $exception;
			return false;
		}
	}

	function createMailbox($_mailboxName)
	{
		try
		{
			return (bool) $this->client->createFolder((string) $_mailboxName, false);
		}
		catch (\Throwable $exception)
		{
			$this->_connectionErrorObject = $exception;
			return false;
		}
	}

	function deleteMailbox($_mailboxName)
	{
		try
		{
			$this->client->deleteFolder((string) $_mailboxName, false);
			return true;
		}
		catch (\Throwable $exception)
		{
			$this->_connectionErrorObject = $exception;
			return false;
		}
	}

	function setACL($_mailboxName, $_identifier, $_rights)
	{
		try
		{
			$protocol = $this->protocol();
			$response = $protocol->requestAndResponse('SETACL', [
				$protocol->escapeString((string) $_mailboxName),
				$protocol->escapeString((string) $_identifier),
				$protocol->escapeString((string) $_rights),
			], true);
			return $response->successful();
		}
		catch (\Throwable $exception)
		{
			$this->_connectionErrorObject = $exception;
			return false;
		}
	}

	function getStorageQuota($_quotaRoot)
	{
		try
		{
			$response = $this->protocol()->getQuotaRoot((string) $_quotaRoot);
			foreach ($response->data() as $line)
			{
				if (is_array($line) && isset($line[1]) && strtoupper((string) $line[1]) === 'QUOTA')
				{
					foreach ((array) ($line[3] ?? []) as $quota)
					{
						if (is_array($quota) && strtoupper((string) ($quota[0] ?? '')) === 'STORAGE')
						{
							return ['QUSED' => (int) ($quota[1] ?? 0), 'QMAX' => (int) ($quota[2] ?? 0)];
						}
					}
				}
			}
			return false;
		}
		catch (\Throwable $exception)
		{
			$this->_connectionErrorObject = $exception;
			return false;
		}
	}

	function setStorageQuota($_quotaRoot, $_quota)
	{
		try
		{
			$protocol = $this->protocol();
			$quota = (int) $_quota < 0 ? '()' : '(STORAGE ' . (int) $_quota . ')';
			$response = $protocol->requestAndResponse('SETQUOTA', [
				$protocol->escapeString((string) $_quotaRoot),
				$quota,
			], true);
			return $response->successful();
		}
		catch (\Throwable $exception)
		{
			$this->_connectionErrorObject = $exception;
			return false;
		}
	}

	/**
	 * returns the quota for given foldername
	 * gets quota for the current user only
	 *
	 * @param string $_folderName
	 * @return string the current quota for this folder
	 */
	#	function getQuota($_folderName) 
	#	{
	#		if(!is_resource($this->mbox)) {
	#			$this->openConnection();
	#		}
	#		
	#		if(function_exists('imap_get_quotaroot') && $this->supportsCapability('QUOTA')) {
	#			$quota = @imap_get_quotaroot($this->mbox, $this->encodeFolderName($_folderName));
	#			if(is_array($quota) && isset($quota['STORAGE'])) {
	#				return $quota['STORAGE'];
	#			}
	#		} 
	#
	#		return false;
	#	}

	/**
	 * return the quota for another user
	 * used by admin connections only
	 *
	 * @param string $_username
	 * @return string the quota for specified user
	 */
	function getQuotaByUser($_username)
	{
		$mailboxName = $this->getUserMailboxString($_username);
		$storageQuota = $this->getStorageQuota($mailboxName);

		if (is_array($storageQuota) && isset($storageQuota['QMAX']))
		{
			return (int)$storageQuota['QMAX'];
		}

		return false;
	}

	/**
	 * returns information about a user
	 * currently only supported information is the current quota
	 *
	 * @param string $_username
	 * @return array userdata
	 */
	function getUserData($_username)
	{
		if (!$this->openConnection(true))
		{
			return false;
		}

		$userData = array();

		if ($quota = $this->getQuotaByUser($_username))
		{
			$userData['quotaLimit'] = $quota / 1024;
		}

		$this->disconnect();

		return $userData;
	}

	/**
	 * opens a connection to a imap server
	 *
	 * @param bool $_adminConnection create admin connection if true
	 *
	 * @return resource the imap connection
	 */
	function openConnection($_adminConnection = false)
	{
		unset($this->_connectionErrorObject);
		$this->disconnect();
		$this->isAdminConnection = (bool) $_adminConnection;
		$username = $_adminConnection ? $this->adminUsername : ($this->loginName ?: $this->username);
		$password = $_adminConnection ? $this->adminPassword : $this->password;
		$encryption = match ((int) $this->encryption)
		{
			1 => 'starttls',
			2 => 'tls',
			3 => 'ssl',
			default => 'none',
		};

		try
		{
			$this->client = $this->clientManager->make([
				'host' => (string) $this->host,
				'port' => (int) $this->port,
				'protocol' => 'imap',
				'encryption' => $encryption,
				'validate_cert' => $this->validatecert !== false,
				'username' => (string) $username,
				'password' => (string) $password,
				'authentication' => 'login',
				'timeout' => 20,
			]);
			$this->client->connect();
			$this->_connected = true;
			$this->_isConnected = true;
			return true;
		}
		catch (\Throwable $exception)
		{
			$this->_connectionErrorObject = $exception;
			$this->client = null;
			$this->_connected = false;
			$this->_isConnected = false;
			return false;
		}
	}

	/**
	 * restore session variable
	 *
	 */
	function restoreSessionData()
	{
		$this->sessionData = Cache::session_get('emailadmin', 'imap_session_data');
	}

	/**
	 * save session variable
	 *
	 */
	function saveSessionData()
	{
		Cache::session_set('emailadmin', 'imap_session_data', $this->sessionData);
	}

	/**
	 * set userdata
	 *
	 * @param string $_username username of the user
	 * @param int $_quota quota in bytes
	 * @return bool true on success, false on failure
	 */
	function setUserData($_username, $_quota)
	{
		return true;
	}

	/**
	 * check if imap server supports given capability
	 *
	 * @param string $_capability the capability to check for
	 * @return bool true if capability is supported, false if not
	 */
	function supportsCapability($_capability)
	{
		return $this->hasCapability($_capability);
	}
}
