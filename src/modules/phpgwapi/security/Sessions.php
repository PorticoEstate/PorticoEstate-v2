<?php

namespace App\modules\phpgwapi\security;
use Psr\Http\Message\ResponseInterface as Response;
use App\modules\phpgwapi\security\Auth\Auth;
use App\Database\Db;
//use PDO;
use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\Preferences;
use App\modules\phpgwapi\services\Cache;
use App\modules\phpgwapi\controllers\Accounts\phpgwapi_account;
use App\modules\phpgwapi\controllers\Accounts\Accounts;
use App\modules\phpgwapi\services\Crypto;
use App\modules\phpgwapi\services\Log;
use App\modules\phpgwapi\services\Translation;
use ReflectionClass;
use ReflectionProperty;


$serverSettings = Settings::getInstance()->get('server');

$flags = Settings::getInstance()->get('flags');

/**
 * Set the session name to something unique for phpgw
 */
if (isset($flags['session_name']) && $flags['session_name'])
{
	session_name($flags['session_name']);
}
else
{
	$session_identifier = 'phpgw';
	session_name("session{$session_identifier}sessid");
}

/*
 * Include the db session handler if required
 */
if (isset($serverSettings['sessions_type']) && $serverSettings['sessions_type'] == 'db')
{
	require_once SRC_ROOT_PATH . '/security/SessionHandlerDb.php';
}


class Sessions
{
	protected $container;
	private $db;
	private $_account_lid;
	private $_account_domain;
	private $_sessionid;
	private $_use_cookies;
	private $_cookie_domain;
	private $config;
	private $routePath;
	private $_session_flags;
	private $_data;
	private $_account_id;
	private $_key;
	private $_iv;
	private $_verified;
	public $cd_reason;
	public $reason;
	private $_login;
	private $_passwd;
	private $serverSettings;
	private $Auth;
	private $Crypto;
	private $Log;
	private $_history_id;

	private static $instance;
	private static $sort_by;
	private static $order_by;

	private function __construct()
	{
		$this->db = Db::getInstance();

		$this->serverSettings = Settings::getInstance()->get('server');

		$this->Log = new Log();

		$this->Auth = new Auth($this->db);

		$this->_use_cookies = false;

		$this->_phpgw_set_cookie_params();

		if (
			!empty($this->serverSettings['usecookies'])  && !\Sanitizer::get_var('api_mode', 'bool')
		)
		{
			$this->_use_cookies = true;
			$this->_sessionid	= \Sanitizer::get_var(session_name(), 'string', 'COOKIE');
		}

		if(!$this->_sessionid)
		{
			if (!empty($_GET[session_name()]))
			{
				$this->_sessionid = \Sanitizer::get_var(session_name(), 'string', 'GET');
			}
			else
			{
				$this->_sessionid = \Sanitizer::get_var(session_name(), 'string', 'POST');
			}
		}

		//respect the config option for cookies
		ini_set('session.use_cookies', $this->_use_cookies);

		//don't rewrite URL, as we have to do it in link - why? cos it is buggy otherwise
		ini_set('url_rewriter.tags', '');
		ini_set("session.gc_maxlifetime", isset($this->serverSettings['sessions_timeout']) ? $this->serverSettings['sessions_timeout'] : 0);
	}

	public static function getInstance()
	{
		if (null === self::$instance)
		{
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __debugInfo()
	{
		$reflectionClass = new ReflectionClass($this);
		$publicAndProtectedProperties = $reflectionClass->getProperties(ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED);
		$privateProperties = $reflectionClass->getProperties(ReflectionProperty::IS_PRIVATE);

		$propertyValues = [];
		foreach ($publicAndProtectedProperties as $property) {
			$property->setAccessible(true);
			$propertyValues[$property->getName()] = $property->getValue($this);
		}

		foreach ($privateProperties as $property) {
			$propertyValues[$property->getName()] = 'private';
		}

		return $propertyValues;
	}
	
	public function get_session_id()
	{
		return $this->_sessionid;
	}

	public function set_session_id($sessionid)
	{
		$this->_sessionid = $sessionid;
	}

	/**
	 * Configure cookies to be used properly for this session
	 *
	 * @return string domain
	 */
	protected function _phpgw_set_cookie_params()
	{
		if (!is_null($this->_cookie_domain))
		{
			return $this->_cookie_domain;
		}

		if (!empty($this->serverSettings['cookie_domain']))
		{
			$this->_cookie_domain = $this->serverSettings['cookie_domain'];
		}
		else
		{
			$parts = explode(':', \Sanitizer::get_var('HTTP_HOST', 'string', 'SERVER')); // strip portnumber if it exists in url (as in 'http://127.0.0.1:8080/')
			$this->_cookie_domain = $parts[0];
		}

		if ($this->_cookie_domain == 'localhost')
		{
			$this->_cookie_domain = '';
		}
		/**
		 * Test if the cookie make it through a reverse proxy where the request switch from https to http
		 */
		//			$secure = \Sanitizer::get_var('HTTPS', 'bool', 'SERVER');
		$secure = false;

		if (!empty($this->serverSettings['webserver_url']))
		{
			$webserver_url = $this->serverSettings['webserver_url'] . '/';
		}
		else
		{
			$webserver_url = '/';
		}

		/*
			 * Temporary hack
			 */
		$webserver_url = '/';

		session_set_cookie_params(
			array(
				'lifetime' => isset($this->serverSettings['sessions_timeout']) ? $this->serverSettings['sessions_timeout'] : 0,
				'path' => parse_url($webserver_url, PHP_URL_PATH),
				'domain' => $this->_cookie_domain,
				'secure' => $secure,
				'httponly' => false,
				'samesite' => 'Lax'
			)
		);

		return $this->_cookie_domain;
	}


	/**
	 * Create a new session
	 *
	 * @param string  $login     user login
	 * @param string  $passwd    user password
	 * @param boolean $skip_auth create a sesison without authenticating the user?
	 *
	 * @return string session id
	 */
	public function create($login, $passwd = '', $skip_auth = false)
	{
		$accounts = new Accounts();

		if (is_array($login))
		{
			$this->_login	= $login['login'];
			$this->_passwd	= $login['passwd'];
			$login			= $this->_login;
		}
		else
		{
			$this->_login	= $login;
			$this->_passwd	= $passwd;
		}

		$now = time();

		$this->_set_login($login);
		$user_ip	= $this->_get_user_ip();

		if ($this->_login_blocked($login, $this->_get_user_ip()))
		{
			$this->reason		= 'blocked, too many attempts';
			$this->cd_reason	= 99;

			// log unsuccessfull login
			$this->log_access($this->reason, $login, $user_ip, 0);

			return false;
		}

		if (
			\App\modules\phpgwapi\security\GloballyDenied::user($this->_account_lid)

			|| !$accounts->name2id($this->_account_lid)
			|| (!$skip_auth && !$this->Auth->authenticate($this->_account_lid, $this->_passwd))
			|| get_class($accounts->get($accounts->name2id($this->_account_lid)))
			== phpgwapi_account::CLASS_TYPE_GROUP
		)
		{
			$this->reason		= 'bad login or password';
			$this->cd_reason	= 5;

			// log unsuccessfull login
			$this->log_access($this->reason, $login, $user_ip, 0);
			return false;
		}


		$this->_account_id = $accounts->name2id($this->_account_lid);

		Settings::getInstance()->setAccountId($this->_account_id);
		$accounts->set_account($this->_account_id);

		session_start();
		$this->_sessionid = session_id();

		if (!empty($this->serverSettings['usecookies']))
		{
			$this->phpgw_setcookie('domain', $this->_account_domain);
		}

		$Acl = Acl::getInstance();

		if ($Acl->check('anonymous', 1, 'phpgwapi'))
		{
			$session_flags = 'A';
		}
		else
		{
			$session_flags = 'N';
		}

		if ($session_flags == 'N' && (!empty($this->serverSettings['usecookies'])
			|| isset($_COOKIE['last_loginid'])))
		{
			// Create a cookie which expires in 14 days
			$cookie_expires = $now + (60 * 60 * 24 * 14);
			$this->phpgw_setcookie('last_loginid', $this->_account_lid, $cookie_expires);
			$this->phpgw_setcookie('last_domain', $this->_account_domain, $cookie_expires);
		}
		/* we kill this for security reasons */
		//		unset($this->serverSettings['default_domain']);

		/* init the crypto object */
		$this->_key = md5($this->_sessionid . $this->serverSettings['encryptkey']);
		$this->_iv  = $this->serverSettings['mcrypt_iv'];

		$this->Crypto = Crypto::getInstance(array($this->_key, $this->_iv));

		$this->read_repositories(true);
		//\App\helpers\DebugArray::debug($this->_data);die();

		if ($this->_data['expires'] != -1 && $this->_data['expires'] < time())
		{
			if (is_object($this->Log))
			{
				$this->Log->message(array(
					'text' => 'W-LoginFailure, account loginid %1 is expired',
					'p1'   => $this->_account_lid,
					'line' => __LINE__,
					'file' => __FILE__
				));
				$this->Log->commit();
			}

			$this->cd_reason = 2;
			return false;
		}

		Cache::session_set('phpgwapi', 'password', base64_encode($this->_passwd));

		$flags = Settings::getInstance()->get('flags');
		if(!empty($flags['openid_connect']['type']))
		{
			Cache::session_set('openid_connect', 'type', $flags['openid_connect']['type']);
		}

		$this->db->transaction_begin();
		$this->register_session($login, $user_ip, $now, $session_flags);
		$this->log_access($this->_sessionid, $login, $user_ip, $this->_account_id);
		$this->Auth->update_lastlogin($this->_account_id, $user_ip);
		$this->db->transaction_commit();

		$this->_verified = true;
		return $this->_sessionid;
	}

	/**
	 * Update the last active timestamp for this session
	 *
	 * This prevents sessions timing out - not really needed anymore
	 *
	 * @return boolean was the timestamp updated?
	 */
	public function update_dla()
	{
		if ($this->_sessionid != session_id())
		{
			throw new \Exception("sessions::sessionid is tampered");
		}

		if ($this->routePath)
		{
			$action = $this->routePath;
		}
		else
		{
			$action = $_SERVER['REQUEST_URI'];
		}

		$_SESSION['phpgw_session']['session_dla'] = time();
		$_SESSION['phpgw_session']['session_action'] = $action;

		return true;
	}

	/**
	 * Check to see if a session is still current and valid
	 *
	 * @param string $sessionid session id to be verfied
	 *
	 * @return bool is the session valid?
	 */
	public function verify($sessionid = '')
	{
		if (empty($sessionid) || !$sessionid)
		{
			$sessionid = $this->get_session_id();
		}

		if (!$sessionid)
		{
			return false;
		}

		$this->_sessionid = $sessionid;

		$session = $this->read_session($sessionid);

		if (!$session)
		{
			return false;
		}
		$this->_session_flags = $session['session_flags'];

		$lid_data = explode('#', $session['session_lid']);
		$this->_account_lid = $lid_data[0];

		if (!in_array($this->serverSettings['auth_type'], array('ntlm', 'customsso'))) //Timeout make no sense for SSO
		{
			$timeout = time() - $this->serverSettings['sessions_timeout'];
			if (
				!isset($session['session_dla'])
				|| $session['session_dla'] <= $timeout
			)
			{
				if (isset($session['session_dla']))
				{
					if (is_object($this->Log))
					{
						$this->Log->message(array(
							'text' => 'W-VerifySession, session for %1 is expired by %2 sec, inactive for %3 sec',
							'p1'   => $this->_account_lid,
							'p2'   => ($timeout - $session['session_dla']),
							'p3'   => (time() - $session['session_dla']),
							'line' => __LINE__,
							'file' => __FILE__
						));
						$this->Log->commit();
					}
					if (is_object($this->Crypto))
					{
						$this->Crypto->cleanup();
						unset($this->Crypto);
					}

					$this->cd_reason = 10;
				}
				return false;
			}
		}

		if (isset($lid_data[1]))
		{
			$this->_account_domain = $lid_data[1];
		}
		else
		{
			$this->_account_domain = $this->serverSettings['default_domain'];
		}

		if (!empty($this->serverSettings['usecookies']))
		{
			$this->phpgw_setcookie('domain', $this->_account_domain);
		}

		unset($lid_data);

		$this->update_dla();

		$accounts = new Accounts();
		$this->_account_id = $accounts->name2id($this->_account_lid);


		if (!$this->_account_id)
		{
			$this->cd_reason = 5;
			return false;
		}

		Settings::getInstance()->setAccountId($this->_account_id);

		/* init the crypto object before appsession call below */
		//$this->_key = md5($this->_sessionid . $this->serverSettings['encryptkey']); //Sigurd: not good for permanent data
		$this->_key = $this->serverSettings['encryptkey'];
		$this->_iv  = $this->serverSettings['mcrypt_iv'];

		$this->Crypto = Crypto::getInstance(array($this->_key, $this->_iv));

		$use_cache = false;
		if (isset($this->serverSettings['cache_phpgw_info']))
		{
			$use_cache = !!$this->serverSettings['cache_phpgw_info'];
		}

		$this->read_repositories($use_cache);

		if ($this->_data['expires'] != -1 && $this->_data['expires'] < time())
		{
			if (is_object($this->Log))
			{
				$this->Log->message(array(
					'text' => 'W-VerifySession, account loginid %1 is expired',
					'p1'   => $this->_account_lid,
					'line' => __LINE__,
					'file' => __FILE__
				));
				$this->Log->commit();
			}
			if (is_object($this->Crypto))
			{
				$this->Crypto->cleanup();
				unset($this->Crypto);
			}
			$this->cd_reason = 2;
			return false;
		}


		$user_info  = Settings::getInstance()->get('user');

		$user_info['session_ip'] = $session['session_ip'];
		$user_info['passwd']     = Cache::session_get('phpgwapi', 'password');
		$user_info['sessionid']  = $this->_sessionid;

		if (\Sanitizer::get_var('domain', 'string', 'REQUEST', false))
		{
			// on "normal" pageview
			if (!$user_info['domain'] = \Sanitizer::get_var('domain', 'string', 'GET', false))
			{
				if (!$user_info['domain'] = \Sanitizer::get_var('domain', 'string', 'POST', false))
				{
					$user_info['domain'] = \Sanitizer::get_var('domain', 'string', 'COOKIE', false);
				}
			}
		}
		else
		{
			//FIX ME: this is a hack to get the domain from the cookie
			$user_info['domain'] = \Sanitizer::get_var('last_domain', 'string', 'COOKIE', 'default');
		}

		Settings::getInstance()->set('user', $user_info);

		if ($this->_account_domain != $user_info['domain'])
		{
			if (is_object($this->Log))
			{
				$this->Log->message(array(
					'text' => 'W-VerifySession, the domains %1 and %2 don\'t match',
					'p1'   => $this->_account_domain,
					'p2'   => $user_info['domain'],
					'line' => __LINE__,
					'file' => __FILE__
				));
				$this->Log->commit();
			}
			if (is_object($this->Crypto))
			{
				$this->Crypto->cleanup();
				unset($this->Crypto);
			}
			$this->cd_reason = 5;
			return false;
		}

		// verify the user agent in an attempt to stop session hijacking
		if ($_SESSION['phpgw_session']['user_agent'] != md5(\Sanitizer::get_var('HTTP_USER_AGENT', 'string', 'SERVER')))
		{
			if (is_object($this->Log))
			{
				// This needs some better wording
				$this->Log->message(array(
					'text' => 'W-VerifySession, User agent hash %1 doesn\'t match user agent hash %2 in session',
					'p1'   => $_SESSION['phpgw_session']['user_agent'],
					'p2'   => md5(\Sanitizer::get_var('HTTP_USER_AGENT', 'string', 'SERVER')),
					'line' => __LINE__,
					'file' => __FILE__
				));
				$this->Log->commit();
			}
			if (is_object($this->Crypto))
			{
				$this->Crypto->cleanup();
				unset($this->Crypto);
			}
			// generic session can't be verified error - don't be specific about the problem
			$this->cd_reason = 2;
			return false;
		}

		$check_ip = false;
		if (isset($this->serverSettings['sessions_checkip']))
		{
			$check_ip = !!$this->serverSettings['sessions_checkip'];
		}

		if ($check_ip)
		{
			if (
				PHP_OS != 'Windows' &&
				(!$user_info['session_ip']
					|| $user_info['session_ip'] != $this->_get_user_ip())
			)
			{
				if (is_object($this->Log))
				{
					// This needs some better wording
					$this->Log->message(array(
						'text' => 'W-VerifySession, IP %1 doesn\'t match IP %2 in session',
						'p1'   => $this->_get_user_ip(),
						'p2'   => $user_info['session_ip'],
						'line' => __LINE__,
						'file' => __FILE__
					));
					$this->Log->commit();
				}
				if (is_object($this->Crypto))
				{
					$this->Crypto->cleanup();
					unset($this->Crypto);
				}
				$this->cd_reason = 2;
				return false;
			}
		}

		Translation::getInstance()->populate_cache();

		if (!$this->_account_lid)
		{
			if (is_object($this->Log))
			{
				// This needs some better wording
				$this->Log->message(array(
					'text' => 'W-VerifySession, account_id is empty',
					'line' => __LINE__,
					'file' => __FILE__
				));
				$this->Log->commit();
			}
			if (is_object($this->Crypto))
			{
				$this->Crypto->cleanup();
				unset($this->Crypto);
			}
			return false;
		}
		$this->_verified = true;
		return true;
	}

	/**
	 * Read a session
	 *
	 * @param string $sessionid the session id
	 *
	 * @return array session data - empty array when not found
	 */
	public function read_session($sessionid)
	{
		if ($sessionid)
		{
			session_id($sessionid);
		}

		session_start();

		if (!session_id() == $sessionid)
		{
			return array();
		}

		if (isset($_SESSION['phpgw_session']) && is_array($_SESSION['phpgw_session']))
		{
			return $_SESSION['phpgw_session'];
		}
		return array();
	}

	/**
	 * Set a cookie
	 *
	 * @param string  $cookiename  name of cookie to be set
	 * @param string  $cookievalue value to be used, if unset cookie is cleared (optional)
	 * @param integer $cookietime  when cookie should expire, 0 for session only (optional)
	 *
	 * @return void
	 */
	public function phpgw_setcookie($cookiename, $cookievalue = '', $cookietime = 0)
	{
		$cookie_params = session_get_cookie_params();

		setcookie($cookiename, $cookievalue, [
			'expires'	 => $cookietime,
			'path'		 => $cookie_params['path'],
			'domain'	 => $cookie_params['domain'],
			'secure'	 => !!$cookie_params['secure'],
			'httponly'	 => !!$cookie_params['httponly'],
			'samesite'	 => $cookie_params['samesite'],
		]);
	}

	/**
	 * Set the current user id
	 *
	 * @param int $account_id the account id - 0 = current user's id
	 */
	public function set_account_id($account_id = 0)
	{
		$this->_account_id = $account_id;
		$this->_account_lid = (new Accounts())->id2lid($this->_account_id);
	}

	/**
	 * Terminate a session
	 *
	 * @param string $sessionid the session to terminate
	 *
	 * @return boolean was the session terminated?
	 */
	public function destroy($sessionid)
	{
		if (!$sessionid)
		{
			return false;
		}

		$this->log_access($this->_sessionid);	// log logout-time
		$user_info  = Settings::getInstance()->get('user');

		$location_obj = new \App\modules\phpgwapi\controllers\Locations();
		$location_id	= $location_obj->get_id('admin', 'openid_connect');
		if ($location_id)
		{
			$config_openid = (new \App\modules\phpgwapi\services\ConfigLocation($location_id))->read();
		}

		$type = Cache::session_get('openid_connect', 'type');
		if ($type && !empty($config_openid['common']['method_backend']))
		{
			$OpenIDConnect = new \App\modules\phpgwapi\controllers\OpenIDConnect($type, $config_openid);
			$OpenIDConnect->logout();
		}

		// Only do the following, if we are working with the current user
		if ($sessionid == $user_info['sessionid'])
		{
			session_unset();
			session_destroy();
			$this->phpgw_setcookie(session_name());
		}
		else if ($this->serverSettings['sessions_type'] == 'php')
		{
			$sessions = $this->list_sessions(0, '', '', true);

			if (isset($sessions[$sessionid]))
			{
				unlink($sessions[$sessionid]['session_file']);
			}
		}
		else
		{
			$sessionHandler = new \SessionHandlerDb();
			$sessionHandler->destroy($sessionid);
		}

		return true;
	}
	/**
	 * Write or update (for logout) the access_log
	 *
	 * @param string  $sessionid  id of session or 0 for unsuccessful logins
	 * @param string  $login      account_lid (evtl. with domain) or '' for settion the logout-time
	 * @param string  $user_ip    ip to log
	 * @param integer $account_id the user's account_id
	 *
	 * @return void
	 */
	public function log_access($sessionid, $login = '', $user_ip = '', $account_id = '')
	{
		$now        = time();
		$account_id = (int) $account_id;

		if ($login != '')
		{
			$sql = 'INSERT INTO phpgw_access_log(sessionid,loginid,ip,li,lo,account_id)'
				. " VALUES (:sessionid, :login, :user_ip, :now, 0, :account_id)";
			$stmt = $this->db->prepare($sql);
			$stmt->execute([
				':sessionid' => $sessionid,
				':login' => $login,
				':user_ip' => $user_ip,
				':now' => $now,
				':account_id' => $account_id
			]);
		}
		else
		{
			$sql = "UPDATE phpgw_access_log SET lo = :now WHERE sessionid = :sessionid";
			$stmt = $this->db->prepare($sql);
			$stmt->execute([
				':now' => $now,
				':sessionid' => $sessionid
			]);
		}
		if ($this->serverSettings['max_access_log_age'])
		{
			$max_age = $now - ($this->serverSettings['max_access_log_age'] * 24 * 60 * 60);

			$stmt = $this->db->prepare("DELETE FROM phpgw_access_log WHERE li < :max_age");
			$stmt->execute([':max_age' => $max_age]);
		}
	}

	/**
	 * Read the repositories for the current user
	 * @param int $account_id
	 * @param string $currentApp
	 * @param bool $use_cache
	 */
	public function read_repositories($use_cache = false)
	{
		if ($use_cache)
		{
			$phpgw_info = null;
		}
		else
		{
			$phpgw_info = Cache::session_get('phpgwapi', 'phpgw_info');
		}

		if (!empty($phpgw_info['user']) && is_array($phpgw_info['user']))
		{
			$this->_data = $phpgw_info['user'];
			Settings::getInstance()->set('user', $phpgw_info['user']);
			Settings::getInstance()->set('account_id', $this->_account_id);
			Settings::getInstance()->set('apps',  $phpgw_info['apps']);
			return;
		}

		$this->_data = [];
		Settings::getInstance()->set('account_id', $this->_account_id);
		$preferences = Preferences::getInstance($this->_account_id)->get('preferences');

		$apps = (new \App\modules\phpgwapi\controllers\Applications($this->_account_id))->read();

		$accounts = new \App\modules\phpgwapi\controllers\Accounts\Accounts($this->_account_id);

		$this->_data               	= $accounts->read()->toArray();
		$this->_data['fullname']	= $accounts->read()->__toString();
		$this->_data['preferences']	= $preferences;
		$this->_data['apps']       	= $apps;
		//		$this->_data['expires']      	= -1;

		//$phpgw_info['user' => [], 'server' => [], 'apps' =>[], 'flags' =>[]];
		$this->_data['session_ip']  = $this->_get_user_ip();
		$this->_data['session_lid'] = $this->_account_lid . '#' . $this->_account_domain;
		$this->_data['account_id']  = $this->_account_id;
		$this->_data['account_lid']  = $this->_account_lid;

		Settings::getInstance()->set('user', $this->_data);
		Cache::session_set('phpgwapi', 'phpgw_info', array(
			'user' => $this->_data,
			'apps' => Settings::getInstance()->get('apps'),
			'flags' => Settings::getInstance()->get('flags'),
			'server' => Settings::getInstance()->get('server')
		));
	}

	/**
	 * Get the ip address of current users
	 *
	 * @return string ip address
	 */
	protected function _get_user_ip()
	{
		return \Sanitizer::get_var(
			'HTTP_X_FORWARDED_FOR',
			'ip',
			'SERVER',
			\Sanitizer::get_var('REMOTE_ADDR', 'ip', 'SERVER')
		);
	}


	/**
	 * Store user specific data in the session array
	 *
	 * @param string  $login         the user's login id
	 * @param string  $user_ip       the IP address the user connected from
	 * @param integer $now           current unix timestamp
	 * @param string  $session_flags the flags associated with the session
	 *
	 * @return void
	 */
	public function register_session($login, $user_ip, $now, $session_flags)
	{
		if ($this->_sessionid != session_id())
		{
			throw new \Exception("sessions::sessionid is tampered");
		}

		if (!strlen(session_id()))
		{
			throw new \Exception("sessions::register_session() - No value for session_id()");
		}

		$_SESSION['phpgw_session'] = array(
			'session_id'		=> $this->_sessionid,
			'session_lid'		=> $login,
			'session_ip'		=> $user_ip,
			'session_logintime'	=> $now,
			'session_dla'		=> $now,
			'session_action'	=> $_SERVER['REDIRECT_URL'],
			'session_flags'		=> $session_flags,
			'user_agent'		=> md5(\Sanitizer::get_var('HTTP_USER_AGENT', 'string', 'SERVER')),
			// we need the install-id to differ between serveral installs shareing one tmp-dir
			'session_install_id'	=> $this->serverSettings['install_id']
		);
	}

	/**
	 * Set the user's login details
	 *
	 * @param string $login the user login to parse
	 *
	 * @return void
	 */
	protected function _set_login($login)
	{
		$m = array();
		if (preg_match('/(.*)#(.*)/', $login, $m))
		{
			$this->_account_lid = $m[1];
			$this->_account_domain = $m[2];
			return;
		}

		$this->_account_lid = $login;
		//      \App\helpers\DebugArray::debug($this->serverSettings);
		//      die();
		$this->_account_domain = $this->serverSettings['default_domain'];
	}

	/**
	 * Protect against brute force attacks, block login if too many unsuccessful login attmepts
	 *
	 * @param string $login account_lid (evtl. with domain)
	 * @param string $ip    the ip that made the request
	 *
	 * @return boolean login blocked?
	 */
	protected function _login_blocked($login, $ip)
	{
		$blocked = false;
		$block_time = time() - $this->serverSettings['block_time'] * 60;
		$ip = $this->db->db_addslashes($ip);

		if (isset($this->serverSettings['sessions_checkip']) && $this->serverSettings['sessions_checkip'])
		{
			$sql = 'SELECT COUNT(*) AS cnt FROM phpgw_access_log'
				. " WHERE account_id = 0 AND ip = :ip AND li > :block_time";

			$stmt = $this->db->prepare($sql);
			$stmt->execute([':ip' => $ip, ':block_time' => $block_time]);

			$false_ip = $stmt->fetchColumn();
			if ($false_ip > $this->serverSettings['num_unsuccessful_ip'])
			{
				$blocked = true;
			}
		}

		$login = $this->db->db_addslashes($login);
		$sql = 'SELECT COUNT(*) AS cnt FROM phpgw_access_log'
			. " WHERE account_id = 0 AND (loginid=:login OR loginid LIKE :loginLike)"
			. " AND li > :block_time";

		$stmt = $this->db->prepare($sql);
		$stmt->execute([
			':login' => $login,
			':loginLike' => "$login#%",
			':block_time' => $block_time
		]);

		$false_id = $stmt->fetchColumn();

		if ($false_id > $this->serverSettings['num_unsuccessful_id'])
		{
			$blocked = true;
		}

		if (
			$blocked && isset($this->serverSettings['admin_mails'])
			&& $this->serverSettings['admin_mails']
			// max. one mail each 5mins
			&& $this->serverSettings['login_blocked_mail_time'] < (time() - (5 * 60))
		)
		{
			// notify admin(s) via email

			$from_name = !empty($this->serverSettings['site_title']) ? $this->serverSettings['site_title'] : $this->serverSettings['system_name'];
			$from    = str_replace(" ", "_", $from_name) . "@{$this->serverSettings['email_domain']}";
			$subject = lang("%1: login blocked for user '%2', ip %3", $from_name, $login, $ip);
			$body    = lang('Too many unsuccessful attempts to login: '
				. "%1 for the user '%2', %3 for the IP %4", $false_id, $login, $false_ip, $ip);

			$send = new \App\modules\phpgwapi\services\Send();

			$subject = $send->encode_subject($subject);
			$admin_mails = explode(',', $this->serverSettings['admin_mails']);
			foreach ($admin_mails as $to)
			{
				$send->msg(
					'email',
					$to,
					$subject,
					$body,
					'',
					'',
					'',
					$from,
					$from
				);
			}
			// save time of mail, to not send to many mails
			$config = new \App\modules\phpgwapi\services\Config('phpgwapi');
			$config->read_repository();
			$config->value('login_blocked_mail_time', time());
			$config->save_repository();
		}
		return $blocked;
	}

	/**
	 * get list of normal / non-anonymous sessions
	 *
	 * The data form the session-files get cached in the app_session phpgwapi/php4_session_cache
	 *
	 * @param integer $start       the record to start at
	 * @param string  $order       the "field" to sort by
	 * @param string  $sort        the direction to sort the data
	 * @param boolean $all_no_sort get all records unsorted?
	 *
	 * @return array the list of session records
	 */
	public function list_sessions($start, $order, $sort, $all_no_sort = false)
	{
		$preferences = Settings::getInstance()->get('user')['preferences'];
		// We cache the data for 5mins system wide as this is an expensive operation
		$last_updated = 0; //Cache::system_get('phpgwapi', 'session_list_saved');

		if (
			is_null($last_updated)
			|| $last_updated < 60 * 5
		)
		{
			$data = array();
			switch ($this->serverSettings['sessions_type'])
			{
				case 'db':
					$data = \SessionHandlerDb::get_list();
					break;

				case 'php':
				default:
					$data = self::_get_list();
			}
			Cache::system_set('phpgwapi', 'session_list', $data);
			Cache::system_set('phpgwapi', 'session_list_saved', time());
		}
		else
		{
			$data = Cache::system_get('phpgwapi', 'session_list');
		}

		if ($all_no_sort)
		{
			return $data;
		}


		self::$sort_by = $sort;
		self::$order_by = $order;

		uasort($data, array($this, 'session_sort'));

		$maxmatches = 25;
		if (
			isset($preferences['common']['maxmatchs'])
			&& (int) $preferences['common']['maxmatchs']
		)
		{
			$maxmatches = (int) $preferences['common']['maxmatchs'];
		}

		return array_slice($data, $start, $maxmatches);
	}
	/**
	 * Get userinfo to pass into Settings for ['user'] for asyncservice
	 *
	 * @return array user
	 */
	public function get_user()
	{
		return $this->_data;
	}

	protected function _get_list()
	{
		$values = array();

		/*
			   Yes recursive - from the manual
			   There is an optional N argument to this [session.save_path] that determines
			   the number of directory levels your session files will be spread around in.
			 */
		$path = session_save_path();

		// debian/ubuntu set the perms to /var/lib/php and so the sessions can't be read
		if (!is_readable($path))
		{
			// FIXME we really should throw an exception here
			$values[] = array(
				'id'		=> 'Unable to read sessions',
				'lid'		=> 'invalid',
				'ip'		=> '0.0.0.0',
				'action'	=> 'Access denied by underlying filesystem',
				'dla'		=> 0,
				'logints'	=> 0
			);
			return $values;
		}

		$dir = new \RecursiveDirectoryIterator($path);
		foreach ($dir as $file)
		{
			$filename = $file->getFilename();
			// only try php session files
			if (!preg_match('/^sess_([a-z0-9]+)$/', $filename))
			{
				continue;
			}

			$rawdata = file_get_contents("{$path}/{$filename}");

			//taken from http://no.php.net/manual/en/function.session-decode.php#79244
			$vars = preg_split(
				'/([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff^|]*)\|/',
				$rawdata,
				-1,
				PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE
			);
			$data = array();

			/*		for($i=0; $vars[$i]; $i++)
				{
					$data[$vars[$i++]]=unserialize($vars[$i]);
				}
		*/
			if (isset($vars[3]))
			{
				$data[$vars[0]] = unserialize($vars[1]);
				$data[$vars[2]] = unserialize($vars[3]);
			}

			// skip invalid or anonymous sessions
			if (
				!isset($data['phpgw_session'])
				|| $data['phpgw_session']['session_install_id'] != $this->serverSettings['install_id']
				|| !isset($data['phpgw_session']['session_flags'])
				|| $data['phpgw_session']['session_flags'] == 'A'
			)
			{
				continue;
			}

			$values[$data['phpgw_session']['session_id']] = array(
				'id'				=> $data['phpgw_session']['session_id'],
				'lid'				=> $data['phpgw_session']['session_lid'],
				'ip'				=> $data['phpgw_session']['session_ip'],
				'action'			=> $data['phpgw_session']['session_action'],
				'dla'				=> $data['phpgw_session']['session_dla'],
				'logints'			=> $data['phpgw_session']['session_logintime'],
				'session_file'		=> "{$path}/{$filename}"
			);
		}
		return $values;
	}

	/**
	 * Sort 2 session entries
	 *
	 * @param array $a the first session entry
	 * @param array $b the second session entry
	 *
	 * @return integer comparison result based on strcasecmp
	 * @see strcasecmp
	 */
	public static function session_sort($a, $b)
	{
		$sort_by = self::$sort_by;
		$sign = strcasecmp(self::$order_by, 'ASC') ? 1 : -1;

		return strcasecmp($a[$sort_by], $b[$sort_by]) * $sign;
	}

	/**
	 * get number of normal / non-anonymous sessions
	 *
	 * @return integer the total number of sessions
	 */
	public function total()
	{
		return count($this->list_sessions(0, '', '', true));
	}

	/**
	 * Get the list of session variables used for non cookie based sessions
	 *
	 * @return array the variables which are specific to this session type
	 */
	public function _get_session_vars()
	{
		return array(
			'domain'		=> $this->_account_domain,
			session_name()	=> $this->_verified ? $this->_sessionid : null
		);
	}


	/**
	 * Additional tracking of user actions - prevents reposts/use of back button
	 *
	 * @return string current history id
	 */
	public function generate_click_history()
	{
		if (!isset($this->_history_id))
		{
			$this->_history_id = md5($this->_login . time());
			$history = (array)Cache::session_get('phpgwapi', 'history');

			if (count($history) >= $this->serverSettings['max_history'])
			{
				array_shift($history);
				Cache::session_set('phpgwapi', 'history', $history);
			}
		}
		return $this->_history_id;
	}
	/**
	 * commit the sessiondata to the session handler
	 *
	 * @return bool
	 */
	function commit_session()
	{
		session_write_close();
		return true;
	}

	/**
	 * Send an error response
	 *
	 * @param array $error
	 * @param int   $statusCode
	 *
	 * @return Response
	 */
	private function sendErrorResponse($error, $statusCode = 401): Response
	{
		$response = new Response();
		$response->getBody()->write(json_encode($error));
		return $response->withHeader('Content-Type', 'application/json')->withStatus($statusCode);
	}
}
