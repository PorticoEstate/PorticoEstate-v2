<?php
	/**
	* phpGroupWare Setup - http://phpGroupWare.prg
	* @author Joseph Engo<jengo@phpgroupware.org>
	* @author Dan Kuykendall<seek3r@phpgroupware.org>
	* @author Mark Peters<skeeter@phpgroupware.org>
	* @author Miles Lott<milosch@phpgroupware.org>
	* @copyright Portions Copyright (C) 2001-2004 Free Software Foundation, Inc. http://www.fsf.org/
	* @license http://www.fsf.org/licenses/gpl.html GNU General Public License
	* @package phpgwapi
	* @subpackage application
	* @version $Id$
	*/

    namespace App\modules\phpgwapi\services\setup;
	use App\modules\phpgwapi\services\setup\Detection;
	use App\modules\phpgwapi\services\setup\Process;
	use App\modules\phpgwapi\services\setup\Html;
	use App\modules\phpgwapi\services\Hooks;
	use App\modules\phpgwapi\services\Crypto;
	use App\modules\phpgwapi\services\Settings;
	use App\modules\phpgwapi\services\setup\SetupTranslation;
	use App\modules\phpgwapi\controllers\Locations;

	use App\Database\Db;
	use PDO;
	use Sanitizer;

	/**
	* Setup
	*
	* @package phpgwapi
	* @subpackage application
	*/
	class Setup
	{
		var $db;
		var $oProc;
		var $hooks;

		var $detection = '';
		var $process = '';
		var $lang = '';
		var $html = '';
		var $appreg = '';

		/* table name vars */
		var $tbl_apps;
		var $tbl_config;
		var $tbl_hooks;

		private $hack_file_name;
		private $serverSettings;
		private $setup_data;
		private $crypto;
		private $translation;
		private $locations;
		private $_key=null, $_iv=null;
		

		public $setup_info;


		/*
		varianter av $Globals
		$GLOBALS['phpgw_info']['apps']
		$GLOBALS['phpgw_info']['server']
		$GLOBALS['phpgw_info']['setup']
		$GLOBALS['setup_info']


		*/
		
		public function __construct($html = False, $translation = False)
		{
            $this->serverSettings = Settings::getInstance()->get('server');
            $this->setup_data = Settings::getInstance()->get('setup');
			$this->serverSettings['default_lang'] = !empty($this->serverSettings['default_lang']) ? $this->serverSettings['default_lang'] : 'en';
			Settings::getInstance()->set('server', $this->serverSettings);

			$this->setup_info = Settings::getInstance()->get('setup_info');
			$this->translation = new SetupTranslation();

			$this->tbl_hooks   = $this->get_hooks_table_name();

			$temp_dir = sys_get_temp_dir();
			$this->hack_file_name = "$temp_dir/setup_login_hack_prevention.json";
			$this->hooks = new Hooks();
			
			$this->_key = isset($this->serverSettings['encryptkey']) ? $this->serverSettings['encryptkey'] : '';
			$this->_iv  = isset($this->serverSettings['mcrypt_iv']) ? $this->serverSettings['mcrypt_iv'] : '';

			$this->crypto = Crypto::getInstance(array($this->_key, $this->_iv));
            $this->db = Db::getInstance();
			$this->locations = new Locations();

		}

		function lang($key,$m1='',$m2='',$m3='',$m4='',$m5='',$m6='',$m7='',$m8='',$m9='',$m10='')
		{
			if(is_array($m1))
			{
				$vars = $m1;
			}
			else
			{
				$vars = array($m1,$m2,$m3,$m4,$m5,$m6,$m7,$m8,$m9,$m10);
			}
	
			// Support DOMNodes from XSL templates
			foreach($vars as &$var)
			{
				if (is_object($var) && $var instanceof \DOMNode)
				{
					$var = $var->nodeValue;
				}
			}
	
	
			return $this->translation->translate($key, $vars);
		}
	
		/**
		 * include api db class for the ConfigDomain and connect to the db
		*
		 */
		function loaddb()
		{

//			$db = Db::getInstance();
 //           $this->db = $db;
		}

		private function _store_login_attempts( $data )
		{
			$fp	= fopen($this->hack_file_name, 'w');
			fputs($fp, json_encode($data));
			fclose($fp);
		}

		private function _get_login_attempts( )
		{
			if(is_file($this->hack_file_name))
			{
				$data = (array)json_decode(file_get_contents($this->hack_file_name), true);
			}
			else
			{
				$data = array();
			}

			return $data;
		}

		/**
		 * authenticate the setup user
		*
		 * @param	$auth_type	???
		 */
		function auth($auth_type='Config')
		{
			$cookie_config = [
				'expires' => 0,//session cookie
				'path' => '/',
			];

			$this->setup_data = Settings::getInstance()->get('setup'); //refresh the setup data

			if (file_exists(SRC_ROOT_PATH . '/../config/header.inc.php')) {
				$phpgw_settings = require(SRC_ROOT_PATH . '/../config/header.inc.php');
				$phpgw_domain = $phpgw_settings['phpgw_domain'];
			}
			else
			{
				$phpgw_domain = array();
			}


			$remoteip     = $_SERVER['REMOTE_ADDR'];

			$FormLogout   = \Sanitizer::get_var('FormLogout');
			$ConfigLogin  = \Sanitizer::get_var('ConfigLogin',	'string', 'POST');
			$HeaderLogin  = \Sanitizer::get_var('HeaderLogin',	'string', 'POST');
			$logindomain   = \Sanitizer::get_var('FormDomain',	'string', 'POST');
			$FormPW       = \Sanitizer::get_var('FormPW',		'string', 'POST');
			$ConfigDomain = \Sanitizer::get_var('ConfigDomain');
			$ConfigPW     = \Sanitizer::get_var('ConfigPW');
			$HeaderPW     = \Sanitizer::get_var('HeaderPW');
			$ConfigLang   = \Sanitizer::get_var('ConfigLang');
		

			// In case the cookies are not included in $_REQUEST
			$FormLogout   = $FormLogout ? $FormLogout : \Sanitizer::get_var('FormLogout',	'string', 'COOKIE');
			$ConfigDomain = $logindomain ? $logindomain: \Sanitizer::get_var('ConfigDomain',	'string', 'COOKIE');
			$ConfigPW     = $ConfigPW ? $ConfigPW : \Sanitizer::get_var('ConfigPW',	'string', 'COOKIE');
			$HeaderPW     = $HeaderPW ? $HeaderPW : \Sanitizer::get_var('HeaderPW',	'string', 'COOKIE');
			$ConfigLang   = $ConfigLang ? $ConfigLang : \Sanitizer::get_var('ConfigLang',	'string', 'COOKIE', 'en');


			/* 6 cases:
				1. Logging into header admin
				2. Logging into config admin
				3. Logging out of config admin
				4. Logging out of header admin
				5. Return visit to config OR header
				6. None of the above
			*/

			$expire = time() + 1200; /* Expire login if idle for 20 minutes. */

			/**
			 * Block more than 4 failed login attempts within one hour
			 */
			$hack_prevention = $this->_get_login_attempts();

			$ip = Sanitizer::get_ip_address();

			if(!$ip)
			{
				return false;
			}

			$now = date('Y-m-d:H');

			if(isset($hack_prevention[$ip]['denied'][$now]) && $hack_prevention[$ip]['denied'][$now] > 3)
			{
				$this->setup_data['HeaderLoginMSG'] = $auth_type == 'Header' ? 'To many failed attempts' : '';
				$this->setup_data['ConfigLoginMSG'] = $auth_type == 'Config' ? 'To many failed attempts' : '';
				Settings::getInstance()->set('setup', $this->setup_data);
				return False;
			}
			if(!empty($HeaderLogin) && $auth_type == 'Header')
			{
				/* header admin login */
				if($FormPW == $this->crypto->decrypt($this->serverSettings['header_admin_password']))
				{
					$hash = password_hash($FormPW, PASSWORD_BCRYPT);
					setcookie('HeaderPW',$hash,	$cookie_config);
					setcookie('ConfigLang',$ConfigLang, $cookie_config);
					if(isset($hack_prevention[$ip]['accepted'][$now]))
					{
						$hack_prevention[$ip]['accepted'][$now] +=1;
					}
					else
					{
						$hack_prevention[$ip]['accepted'][$now] =1;
					}

					$this->_store_login_attempts($hack_prevention);

					return True;
				}
				else
				{
					$this->setup_data['HeaderLoginMSG'] = $this->lang('Invalid password');
					$this->setup_data['ConfigLoginMSG'] = '';
					if(isset($hack_prevention[$ip]['denied'][$now]))
					{
						$hack_prevention[$ip]['denied'][$now] +=1;
					}
					else
					{
						$hack_prevention[$ip]['denied'][$now] =1;
					}

					$this->setup_data['HeaderLoginMSG'] .= " ({$hack_prevention[$ip]['denied'][$now]})";
					Settings::getInstance()->set('setup', $this->setup_data);

					$this->_store_login_attempts($hack_prevention);

					return False;
				}
			}
			elseif(!empty($ConfigLogin) && $auth_type == 'Config')
			{

				/* config login */
				if($FormPW == $this->crypto->decrypt($phpgw_domain[$logindomain]['config_passwd']))
				{
					$hash = password_hash($FormPW, PASSWORD_BCRYPT);
					setcookie('ConfigPW', $hash, $cookie_config);
					setcookie('ConfigDomain', $logindomain, $cookie_config);
					setcookie('ConfigLang', $ConfigLang, $cookie_config);
					if(isset($hack_prevention[$ip]['accepted'][$now]))
					{
						$hack_prevention[$ip]['accepted'][$now] +=1;
					}
					else
					{
						$hack_prevention[$ip]['accepted'][$now] =1;
					}

					$this->_store_login_attempts($hack_prevention);

					return True;
				}
				else
				{
					$this->setup_data['ConfigLoginMSG'] = $this->lang('Invalid password');
					$this->setup_data['HeaderLoginMSG'] = '';
					if(isset($hack_prevention[$ip]['denied'][$now]))
					{
						$hack_prevention[$ip]['denied'][$now] +=1;
					}
					else
					{
						$hack_prevention[$ip]['denied'][$now] =1;
					}

					$this->setup_data['ConfigLoginMSG'] .= " ({$hack_prevention[$ip]['denied'][$now]})";
					Settings::getInstance()->set('setup', $this->setup_data);

					$this->_store_login_attempts($hack_prevention);

					return False;
				}
			}
			elseif(!empty($FormLogout))
			{
				/* logout */
				if($FormLogout == 'config')
				{
					/* config logout */
					setcookie('ConfigPW','', $cookie_config);
					$this->setup_data['LastDomain'] = isset($_COOKIE['ConfigDomain']) ? $_COOKIE['ConfigDomain'] : '';
					setcookie('ConfigDomain','', $cookie_config);
					$this->setup_data['ConfigLoginMSG'] = $this->lang('You have successfully logged out');
					setcookie('ConfigLang','', $cookie_config);
					$this->setup_data['HeaderLoginMSG'] = '';
					Settings::getInstance()->set('setup', $this->setup_data);

					return False;
				}
				elseif($FormLogout == 'header')
				{
					/* header admin logout */
					setcookie('HeaderPW','', $cookie_config);
					$this->setup_data['HeaderLoginMSG'] = $this->lang('You have successfully logged out');
					setcookie('ConfigLang','', $cookie_config);
					$this->setup_data['ConfigLoginMSG'] = '';
					Settings::getInstance()->set('setup', $this->setup_data);

					return False;
				}
			}
			elseif(!empty($ConfigPW) && $auth_type == 'Config')
			{
				/* Returning after login to config */
				$config_passwd = $this->crypto->decrypt($phpgw_domain[$ConfigDomain]['config_passwd']);
				if(password_verify($config_passwd, $ConfigPW))
				{
					setcookie('ConfigPW', $ConfigPW,  $cookie_config);
					setcookie('ConfigDomain', $ConfigDomain, $cookie_config);
					setcookie('ConfigLang', $ConfigLang, $cookie_config);
					return True;
				}
				else
				{
					$this->setup_data['ConfigLoginMSG'] = $this->lang('Invalid password');
					$this->setup_data['HeaderLoginMSG'] = '';
					Settings::getInstance()->set('setup', $this->setup_data);
					return False;
				}
			}
			elseif(!empty($HeaderPW) && $auth_type == 'Header')
			{
				/* Returning after login to header admin */
				$header_admin_password = $this->crypto->decrypt($this->serverSettings['header_admin_password']);
				if(password_verify($header_admin_password, $HeaderPW))
				{
					setcookie('HeaderPW', $HeaderPW , $cookie_config);
					setcookie('ConfigLang', $ConfigLang, $cookie_config);
					return True;
				}
				else if(password_verify(stripslashes($this->serverSettings['header_admin_password']), $HeaderPW))
				{
					setcookie('HeaderPW', $HeaderPW , $cookie_config);
					setcookie('ConfigLang', $ConfigLang, $cookie_config);
					return True;
				}
				else
				{
					$this->setup_data['HeaderLoginMSG'] = $this->lang('Invalid password');
					$this->setup_data['ConfigLoginMSG'] = '';
					Settings::getInstance()->set('setup', $this->setup_data);
					return False;
				}
			}
			else
			{
				$this->setup_data['HeaderLoginMSG'] = '';
				$this->setup_data['ConfigLoginMSG'] = '';
				Settings::getInstance()->set('setup', $this->setup_data);
				return False;
			}
		}

		function checkip($remoteip='')
		{
			$allowed_ips = explode(',',$this->serverSettings['setup_acl']);
			if(is_array($allowed_ips))
			{
				$foundip = False;
				//while(list(,$value) = @each($allowed_ips))
				foreach($allowed_ips as $key => $value)
				{
					$test = preg_split("/\./",$value);
					if(count($test) < 3)
					{
						$value .= ".0.0";
						$tmp = preg_split("/\./",$remoteip);
						$tmp[2] = 0;
						$tmp[3] = 0;
						$testremoteip = join('.',$tmp);
					}
					elseif(count($test) < 4)
					{
						$value .= ".0";
						$tmp = preg_split("/\./",$remoteip);
						$tmp[3] = 0;
						$testremoteip = join('.',$tmp);
					}
					elseif(count($test) == 4 &&
						intval($test[3]) == 0)
					{
						$tmp = preg_split("/\./",$remoteip);
						$tmp[3] = 0;
						$testremoteip = join('.',$tmp);
					}
					else
					{
						$testremoteip = $remoteip;
					}

					//echo '<br>testing: ' . $testremoteip . ' compared to ' . $value;

					if($testremoteip == $value)
					{
						//echo ' - PASSED!';
						$foundip = True;
					}
				}
				if(!$foundip)
				{
					$this->setup_data['HeaderLoginMSG'] = '';
					$this->setup_data['ConfigLoginMSG'] = $this->lang('Invalid IP address');
					Settings::getInstance()->set('setup', $this->setup_data);
					return False;
				}
			}
			return True;
		}

		/**
		 * Return X.X.X major version from X.X.X.X versionstring
		*
		 * @param	$
		 */
		function get_major($versionstring)
		{
			if(!$versionstring)
			{
				return False;
			}

			$version = str_replace('pre','.',$versionstring);
			$varray  = explode('.',$version);
			$major   = implode('.',array($varray[0],$varray[1],$varray[2]));

			return $major;
		}

		/**
		 * Clear system/user level cache so as to have it rebuilt with the next access
		*
		 * @param	None
		 */
		function clear_session_cache()
		{
			$tables = Array();
			$tablenames = $this->db->table_names();
			foreach($tablenames as $key => $val)
			{
				$tables[] = $val;
			}
			if(in_array('phpgw_app_sessions',$tables))
			{
				$stmt = $this->db->prepare("DELETE FROM phpgw_app_sessions WHERE sessionid = '0' and loginid = '0' and app = 'phpgwapi' and location = 'config'");
				$stmt->execute();

				$stmt = $this->db->prepare("DELETE FROM phpgw_app_sessions WHERE app = 'phpgwapi' and location = 'phpgw_info_cache'");
				$stmt->execute();			}
		}

		/**
		 * Add an application to the phpgw_applications table
		*
		 * @param	$appname	Application 'name' with a matching $setup_info[$appname] array slice
		 * @param	$enable		 * optional, set to True/False to override setup.inc.php setting
		 */
		function register_app($appname,$enable=99)
		{
			$setup_info = Settings::getInstance()->get('setup_info');
			

			if(!$appname)
			{
				return False;
			}

			if ( $enable == 99 )
			{
				$enable = (int) $setup_info[$appname]['enable'];
			}
			else
			{
				$enable = 0;
			}

			if($GLOBALS['DEBUG'])
			{
				echo '<br>register_app(): ' . $appname . ', version: ' . $setup_info[$appname]['version'] . ', table: phpgw_applications<br>';
			}

			$tables = '';
			if($setup_info[$appname]['version'])
			{
				if (isset($setup_info[$appname]['tables']) && is_array($setup_info[$appname]['tables'])) {
					$tables = implode(',', $setup_info[$appname]['tables']);
				}
				if (isset($setup_info[$appname]['tables_use_prefix']) && $setup_info[$appname]['tables_use_prefix']) {
					echo $setup_info[$appname]['name'] . ' uses tables_use_prefix, storing '
						. $setup_info[$appname]['tables_prefix']
						. ' as prefix for ' . $setup_info[$appname]['name'] . " tables\n";

					$stmt = $this->db->prepare("INSERT INTO phpgw_config (config_app, config_name, config_value) VALUES (:name, :prefix, :tables_prefix)");
					$stmt->execute([
						':name' => $setup_info[$appname]['name'],
						':prefix' => $appname . "_tables_prefix",
						':tables_prefix' => $setup_info[$appname]['tables_prefix']
					]);
				}
				$stmt = $this->db->prepare('INSERT INTO phpgw_applications (app_name, app_enabled, app_order, app_tables, app_version) VALUES (:name, :enable, :order, :tables, :version)');
				$stmt->execute([
					':name' => $setup_info[$appname]['name'],
					':enable' => $enable,
					':order' => intval($setup_info[$appname]['app_order']),
					':tables' => $tables,
					':version' => $setup_info[$appname]['version']
				]);
				
			}
			$this->locations->add('run', "Automatically added on install - run {$appname}", $appname, false);
			$this->locations->add('admin', "Allow app admins - {$appname}", $appname, false);
		}

		/**
		 * Check if an application has info in the db
		*
		 * @param	$appname	Application 'name' with a matching $setup_info[$appname] array slice
		 */
		function app_registered($appname)
		{
			$setup_info = Settings::getInstance()->get('setup_info');

			$tablenames = $this->db->table_names();
			if(!in_array('phpgw_applications',$tablenames))
			{
				return False;
			}


			if(!$appname)
			{
				return False;
			}

			if ( isset($GLOBALS['DEBUG']) && $GLOBALS['DEBUG'] )
			{
				echo '<br>app_registered(): checking ' . $appname;
				// _debug_array($setup_info[$appname]);
			}

			$stmt = $this->db->prepare("SELECT COUNT(app_name) as cnt FROM phpgw_applications WHERE app_name=:appname");
			$stmt->execute([':appname' => $appname]);

			$result = $stmt->fetch(PDO::FETCH_ASSOC);

			if($result['cnt'])
			{
				if (isset($GLOBALS['DEBUG']) && $GLOBALS['DEBUG'])
				{
					echo '... app previously registered.';
				}
				return true;
			}
			if ( isset($GLOBALS['DEBUG']) && $GLOBALS['DEBUG'] )
			{
				echo '... app not registered';
			}
			return False;
		}

		/**
		 * Update application info in the db
		*
		 * @param	$appname	Application 'name' with a matching $setup_info[$appname] array slice
		 */
		function update_app($appname)
		{
			$setup_info = Settings::getInstance()->get('setup_info');

			if(!$appname)
			{
				return False;
			}

			if($GLOBALS['DEBUG'])
			{
				echo '<br>update_app(): ' . $appname . ', version: ' . $setup_info[$appname]['currentver'] . ', table: phpgw_applications<br>';
//				 _debug_array($setup_info);
			}

			$stmt = $this->db->prepare("SELECT COUNT(app_name) as cnt FROM phpgw_applications WHERE app_name=:appname");
			$stmt->execute([':appname' => $appname]);

			$result = $stmt->fetch(PDO::FETCH_ASSOC);

			if(!$result['cnt'])
			{
				return False;
			}
			if($setup_info[$appname]['version'])
			{
				//echo '<br>' . $setup_info[$appname]['version'];
				$tables = '';
				if (isset($setup_info[$appname]['tables']) && is_array($setup_info[$appname]['tables'])) {
					$tables = implode(',', $setup_info[$appname]['tables']);
				}

				$stmt = $this->db->prepare("UPDATE phpgw_applications SET app_name=:name, app_enabled=:enabled, app_order=:order, app_tables=:tables, app_version=:version WHERE app_name=:appname");
				$stmt->execute([
					':name' => $setup_info[$appname]['name'],
					':enabled' => intval($setup_info[$appname]['enable']),
					':order' => intval($setup_info[$appname]['app_order']),
					':tables' => $tables,
					':version' => $setup_info[$appname]['currentver'],
					':appname' => $appname
				]);
			}
		}

		/**
		 * Update application version in applications table, post upgrade
		*
		 * @param	$setup_info		 * Array of application information (multiple apps or single)
		 * @param	$appname		 * Application 'name' with a matching $setup_info[$appname] array slice
		 * @param	$tableschanged	???
		 */
		function update_app_version($setup_info, $appname, $tableschanged = True)
		{
			if(!$appname)
			{
				return False;
			}


			if($tableschanged == True)
			{
				$this->setup_data['tableschanged'] = True;
				Settings::getInstance()->set('setup', $this->setup_data);
			}
			if($setup_info[$appname]['currentver'])
			{
				$stmt = $this->db->prepare("UPDATE phpgw_applications SET app_version=:currentver WHERE app_name=:appname");
				$stmt->execute([':currentver' => $setup_info[$appname]['currentver'], ':appname' => $appname]);
			}
			return $setup_info;
		}

		/**
		 * de-Register an application
		 *
		 * @param string $appname Application 'name' with a matching $setup_info[$appname] array slice
		 */
		function deregister_app($appname)
		{
			if(!$appname)
			{
				return false;
			}
			$appname = $this->db->db_addslashes($appname);

			// Clean up locations, custom fields and ACL
			$stmt = $this->db->prepare("SELECT app_id FROM phpgw_applications WHERE app_name = :appname");
			$stmt->execute([':appname' => $appname]);
			$app_id = (int)$stmt->fetchColumn();

			$stmt = $this->db->prepare("SELECT location_id FROM phpgw_locations WHERE app_id = :app_id");
			$stmt->execute([':app_id' => $app_id]);

			$locations = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

			if(count($locations))
			{
				$inQuery_locations = implode(',', array_fill(0, count($locations), '?'));
				$stmt = $this->db->prepare("DELETE FROM phpgw_cust_choice WHERE location_id IN ($inQuery_locations)");
				$stmt->execute($locations);
				$stmt = $this->db->prepare("DELETE FROM phpgw_cust_attribute WHERE location_id IN ($inQuery_locations)");
				$stmt->execute($locations);
				$stmt = $this->db->prepare("DELETE FROM phpgw_acl WHERE location_id IN ($inQuery_locations)");
				$stmt->execute($locations);

				$stmt = $this->db->prepare("SELECT id FROM phpgw_config2_section WHERE location_id IN ($inQuery_locations)");
				$stmt->execute($locations);
				$sections = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

				if($sections)
				{
					$inQuery_sections = implode(',', array_fill(0, count($sections), '?'));
					$stmt = $this->db->prepare("DELETE FROM phpgw_config2_value WHERE section_id IN ($inQuery_sections)");
					$stmt->execute($sections);
					$stmt = $this->db->prepare("DELETE FROM phpgw_config2_choice WHERE section_id IN ($inQuery_sections)");
					$stmt->execute($sections);
					$stmt = $this->db->prepare("DELETE FROM phpgw_config2_attrib WHERE section_id IN ($inQuery_sections)");
					$stmt->execute($sections);
					$stmt = $this->db->prepare("DELETE FROM phpgw_config2_section WHERE location_id IN ($inQuery_locations)");
					$stmt->execute($locations);
				}
			}

			$stmt = $this->db->prepare("DELETE FROM phpgw_locations WHERE app_id = :app_id");
			$stmt->execute([':app_id' => $app_id]);
			$stmt = $this->db->prepare("DELETE FROM phpgw_config WHERE config_app=:appname");
			$stmt->execute([':appname' => $appname]);
			$stmt = $this->db->prepare("DELETE FROM phpgw_applications WHERE app_name=:appname");
			$stmt->execute([':appname' => $appname]);
//			$this->clear_session_cache();
		}


		/**
		 * Register an application's hooks
		*
		 * @param	$appname	Application 'name' with a matching $setup_info[$appname] array slice
		 */
		function register_hooks($appname)
		{
			$setup_info = Settings::getInstance()->get('setup_info');

			if( !$appname
				|| !isset($setup_info[$appname]['hooks']) )
			{
				return False;
			}

			if ( !isset($this->hooks) || !is_object($this->hooks))
			{
				$this->hooks = new Hooks($this->db);
			}
			$this->hooks->register_hooks($appname,$setup_info[$appname]['hooks']);
			return true; //i suppose
		}

		/**
		 * Update an application's hooks
		*
		 * @param	$appname	Application 'name' with a matching $setup_info[$appname] array slice
		 */
		function update_hooks($appname)
		{
			$this->register_hooks($appname);
		}

		/**
		 * de-Register an application's hooks
		*
		 * @param	$appname	Application 'name' with a matching $setup_info[$appname] array slice
		 */
		function deregister_hooks($appname)
		{
			$setup_info = Settings::getInstance()->get('setup_info');
			if(isset($setup_info['phpgwapi']['currentver']) && $this->alessthanb($setup_info['phpgwapi']['currentver'],'0.9.8pre5'))
			{
				/* No phpgw_hooks table yet. */
				return False;
			}

			if(!$appname)
			{
				return False;
			}

			//echo "DELETING hooks for: " . $setup_info[$appname]['name'];
			if (!is_object($this->hooks))
			{
				$this->hooks = new Hooks($this->db);
			}
			$this->hooks->register_hooks($appname);
		}

		/**
		  * call the hooks for a single application
		 *
		  * @param $location hook location - required
		  * @param $appname application name - optional
		 */
		function hook($location, $appname='')
		{
			if (!is_object($this->hooks))
			{
				$this->hooks = new Hooks($this->db);
			}
			return $this->hooks->single($location,$appname,True,True);
		}

		/**
		* phpgw version checking, is param 1 < param 2 in phpgw versionspeak?
		* @param string $a phpgw version number to check if less than $b
		* @param string $b phpgw version number to check $a against
		* @return bool True if $a < $b
		*/
		function alessthanb($a, $b, $debug = false)
		{
			if ( isset($GLOBALS['DEBUG']) && $GLOBALS['DEBUG'] )
			{
				$debug = true;
			}
			$num = array('1st','2nd','3rd','4th');

			if($debug)
			{
				echo'<br>Input values: '
					. 'A="'.$a.'", B="'.$b.'"';
			}
			$newa = str_replace('pre','.',$a);
			$newb = str_replace('pre','.',$b);
			$testa = explode('.',$newa);
			if(empty($testa[1]))
			{
				$testa[1] = 0;
			}
			if(empty($testa[2]))
			{
				$testa[2] = 0;
			}
			if(empty($testa[3]))
			{
				$testa[3] = 0;
			}
			$testb = explode('.',$newb);
			if(empty($testb[1]))
			{
				$testb[1] = 0;
			}
			if(empty($testb[2]))
			{
				$testb[2] = 0;
			}
			if(empty($testb[3]))
			{
				$testb[3] = 0;
			}
			$less = 0;

			for($i=0;$i<count($testa);$i++)
			{
				if($debug)
				{
					echo'<br>Checking if '. intval($testa[$i]) . ' is less than ' . intval($testb[$i]) . ' ...';
				}
				if(intval($testa[$i]) < intval($testb[$i]))
				{
					if ($debug)
					{
						echo ' yes.';
					}
					$less++;
					if($i<3)
					{
						/* Ensure that this is definitely smaller */
						if($debug)
						{
							echo"  This is the $num[$i] octet, so A is definitely less than B.";
						}
						$less = 5;
						break;
					}
				}
				elseif(intval($testa[$i]) > intval($testb[$i]))
				{
					if($debug)
					{
						echo ' no.';
					}

					$less--;

					if($i<2)
					{
						/* Ensure that this is definitely greater */
						if($debug)
						{
							echo"  This is the $num[$i] octet, so A is definitely greater than B.";
						}
						$less = -5;
						break;
					}
				}
				else
				{
					if($debug)
					{
						echo ' no, they are equal.';
					}
					$less = 0;
				}
			}
			if($debug)
			{
				echo '<br>Check value is: "'.$less.'"';
			}
			if($less>0)
			{
				if($debug)
				{
					echo '<br>A is less than B';
				}
				return True;
			}
			elseif($less<0)
			{
				if($debug)
				{
					echo '<br>A is greater than B';
				}
				return False;
			}
			else
			{
				if($debug)
				{
					echo '<br>A is equal to B';
				}
				return False;
			}
		}

		/**
		 * phpgw version checking, is param 1 > param 2 in phpgw versionspeak?
		*
		 * @param	$a	phpgw version number to check if more than $b
		 * @param	$b	phpgw version number to check $a against
		 * #return	True if $a < $b
		 */
		function amorethanb($a,$b,$debug=False)
		{
			$num = array('1st','2nd','3rd','4th');

			if($debug)
			{
				echo'<br>Input values: '
					. 'A="'.$a.'", B="'.$b.'"';
			}
			$newa = str_replace('pre','.',$a);
			$newb = str_replace('pre','.',$b);
			$testa = explode('.',$newa);
			if( !isset($testa[3]) || $testa[3] == '')
			{
				$testa[3] = 0;
			}
			$testb = explode('.',$newb);
			if( !isset($testb[3]) || $testb[3] == '')
			{
				$testb[3] = 0;
			}
			$less = 0;

			for($i=0;$i<count($testa);$i++)
			{
				if($debug)
				{
					echo'<br>Checking if '. intval($testa[$i]) . ' is more than ' . intval($testb[$i]) . ' ...';
				}

				if ( isset($testa[$i]) &&  isset($testb[$i])
					&& (int)$testa[$i] > (int)$testb[$i] )
				{
					if($debug) { echo ' yes.'; }
					$less++;
					if($i<3)
					{
						/* Ensure that this is definitely greater */
						if($debug) { echo"  This is the $num[$i] octet, so A is definitely greater than B."; }
						$less = 5;
						break;
					}
				}
				else if ( isset($testa[$i]) &&  isset($testb[$i])
					&& (int)$testa[$i] < (int)$testb[$i] )
				{
					if($debug) { echo ' no.'; }
					$less--;
					if($i<2)
					{
						/* Ensure that this is definitely smaller */
						if($debug) { echo"  This is the $num[$i] octet, so A is definitely less than B."; }
						$less = -5;
						break;
					}
				}
				else
				{
					if($debug) { echo ' no, they are equal.'; }
					$less = 0;
				}
			}
			if($debug) { echo '<br>Check value is: "'.$less.'"'; }
			if($less>0)
			{
				if($debug) { echo '<br>A is greater than B'; }
				return True;
			}
			elseif($less<0)
			{
				if($debug) { echo '<br>A is less than B'; }
				return False;
			}
			else
			{
				if($debug) { echo '<br>A is equal to B'; }
				return False;
			}
		}

		function get_hooks_table_name()
		{
			$setup_info = Settings::getInstance()->get('setup_info');


			if ( isset($setup_info['phpgwapi']['currentver'])
				&& $this->alessthanb($setup_info['phpgwapi']['currentver'], '0.9.8pre5')
				&& ($setup_info['phpgwapi']['currentver'] != ''))
			{
				/* No phpgw_hooks table yet. */
				return False;
			}
			return 'phpgw_hooks';
		}
}

