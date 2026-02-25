<?php

/**
 * Setup
 *
 * @copyright Copyright (C) 2000-2005 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @package setup
 * @version $Id$
 */

namespace App\modules\setup\controllers;

use App\Database\Db;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\setup\Setup;
use App\modules\phpgwapi\services\setup\Detection;
use App\modules\phpgwapi\services\setup\Process;
use App\modules\phpgwapi\services\setup\Html;
use App\modules\phpgwapi\services\setup\SetupTranslation;
use App\modules\phpgwapi\services\Sanitizer;
use App\helpers\DateHelper;
use App\modules\phpgwapi\services\Twig;
use PDO;
use App\modules\phpgwapi\controllers\Accounts\phpgwapi_group;
use App\modules\phpgwapi\controllers\Accounts\phpgwapi_user;
use App\modules\phpgwapi\controllers\Accounts\phpgwapi_account;
use \App\modules\phpgwapi\security\GloballyDenied;
use Exception;

class Accounts
{
	/**
	 * @var object
	 */
	private $db;
	private $detection;
	private $process;
	private $html;
	private $setup;
	private $translation;
	private $serverSettings;
	private $accounts;
	private $twig;

	public function __construct()
	{
		//setup_info
		Settings::getInstance()->set('setup_info', []); //$GLOBALS['setup_info']
		//setup_data
		Settings::getInstance()->set('setup', []); //$setup_data
		//current_config
		Settings::getInstance()->set('current_config', []); //$current_config
		$this->serverSettings = Settings::getInstance()->get('server');

		$this->db = Db::getInstance();
		$this->detection = new Detection();
		$this->process = new Process();
		$this->html = new Html();
		$this->setup = new Setup();
		$this->translation = new SetupTranslation();
		$this->accounts = new \App\modules\phpgwapi\controllers\Accounts\Accounts();
		$this->twig = Twig::getInstance();

		$flags = array(
			'noheader' 		=> True,
			'nonavbar'		=> True,
			'currentapp'	=> 'setup',
			'noapi'			=> True,
			'nocachecontrol' => True
		);
		Settings::getInstance()->set('flags', $flags);

		// Check header and authentication
		if (!$this->setup->auth('Config'))
		{
			Header('Location: ../setup');
			exit;
		}

	}

	/**
	 * Add account
	 *
	 * @param array  $acct    Account name and other information to use
	 * @param string $type    Account type: u = user | g = group
	 * @param array  $groups  Groups to add account to
	 * @param array  $modules Modules to grant account access to
	 * @param array  $acls    ACLs to set for account
	 *
	 * @return integer Account ID
	 */
	function add_account($acct, $type, $groups = array(), $modules = array(), $acls = array())
	{
		$person_id = 0;
		if ($type == 'u')
		{
			$account			= new phpgwapi_user();
			$account->lid		= $acct['username'];
			$account->firstname	= $acct['firstname'];
			$account->lastname	= $acct['lastname'];
			$account->passwd	= $acct['password'];
			$account->enabled	= true;
			$account->expires	= -1;
		}
		else
		{
			$account			= new phpgwapi_group();
			$account->lid		= $acct['username'];
			$account->firstname = ucfirst($acct['username']);
		}

		return $this->accounts->create($account, $groups, $acls, $modules);
	}

	/**
	 * Insert system default preferences
	 *
	 * @param integer $defaultgroup the id of the "default" group
	 *
	 * @return void
	 */
	function insert_default_prefs($defaultgroup)
	{
		$accountid = -2;
		$defaultprefs = array(
			'common' => array(
				'maxmatchs'		=> 10,
				'template_set'	=> 'bootstrap',
				'theme'			=> '',
				'tz_offset'		=> 0,
				'dateformat'	=> 'Y/m/d',
				'lang'			=> substr(\Sanitizer::get_var('ConfigLang'), 0, 2),
				'timeformat'	=> 24,
				'default_app'	=> '',
				'currency'		=> '$',
				'show_help'		=> 0,
				'account_display' => 'lastname',
				'rteditor'		=> 'ckeditor',
				'export_format'		=> 'excel',
			),

			'addressbook' => array(),

			'calendar' => array(
				'workdaystarts'				=> 9,
				'workdayends'				=> 17,
				'weekdaystarts'				=> 'Monday',
				'defaultcalendar'			=> 'month',
				'planner_start_with_group'	=> $defaultgroup
			)
		);

		foreach ($defaultprefs as $app => $prefs)
		{
			$prefs = json_encode($prefs);
			$sql = 'INSERT INTO phpgw_preferences(preference_owner, preference_app, preference_json)'
				. " VALUES({$accountid}, '{$app}', '{$prefs}')";
			$this->db->query($sql, __LINE__, __FILE__);
		}
	}

	/**
	 * Validate the data for the admin user account
	 *
	 * @param string &$username the login id for the admin user -
	 * @param string $passwd    the password for the new user
	 * @param string $passwd2   the verification password for the new user
	 * @param string $fname     the first name of the administrator
	 * @param string $lname     the lastname of the administrator
	 *
	 * @return array list of errors - empty array if valid
	 *
	 * @internal we pass the username by ref so it can be unset if invalid
	 */
	function validate_admin(&$username, $passwd, &$passwd2, $fname, $lname)
	{

		$errors = array();

		if ($passwd != $passwd2)
		{
			$errors[] = $this->setup->lang('Passwords did not match, please re-enter');
		}
		else
		{
			$account	= new phpgwapi_user();
			try
			{
				$account->validate_password($passwd);
			}
			catch (Exception $e)
			{
				$errors[] = $e->getMessage();
			}
		}

		if (!$username)
		{
			$errors[] = $this->setup->lang('You must enter a username for the admin');
		}
		else if (GloballyDenied::user($username))
		{
			$errors[] = $this->setup->lang('You can not use %1 as the admin username, please try again with another username', $username);
			$username = '';
		}

		return $errors;
	}


	public function index()
	{

		if (\Sanitizer::get_var('cancel', 'bool', 'POST'))
		{
			Header('Location: ../setup');
			exit;
		}
		// set some sane default values
		$passwd		= '';
		$passwd2	= $passwd;
		$username	= 'sysadmin';
		$fname		= 'System';
		$lname		= 'Administrator';

		$errors = array();
		if (\Sanitizer::get_var('submit', 'string', 'POST'))
		{
			// set some sane defaults
			$this->serverSettings['ldap_host']				= '';
			$this->serverSettings['ldap_context']			= '';
			$this->serverSettings['ldap_group_context']		= '';
			$this->serverSettings['ldap_root_dn']			= '';
			$this->serverSettings['ldap_root_pw']			= '';
			$this->serverSettings['ldap_extra_attributes']	= false;
			$this->serverSettings['ldap_account_home']		= '/dev/null';
			$this->serverSettings['ldap_account_shell']		= '/bin/false';
			$this->serverSettings['ldap_encryption_type']	= 'ssha';
			$this->serverSettings['account_repository']		= 'sql';
			$this->serverSettings['auth_type']				= 'sql';
			$this->serverSettings['encryption_type']			= 'ssha';
			$this->serverSettings['password_level']         = 'NONALPHA';
			$this->serverSettings['account_min_id']			= 1000;
			$this->serverSettings['account_max_id']			= 65535;
			$this->serverSettings['group_min_id']			= 500;
			$this->serverSettings['group_max_id']			= 999;

			// Load up the real config values
			$sql = 'SELECT config_name,config_value FROM phpgw_config'
				. " WHERE config_name LIKE 'ldap%' OR config_name LIKE '%_id'"
				. " OR config_name = 'account_repository'"
				. " OR config_name = 'auth_type'"
				. " OR config_name = 'encryption_type'"
				. " OR config_name = 'encryptkey'"
				. " OR config_name = 'password_level'"
				. " OR config_name = 'webserver_url'";

			$stmt = $this->db->prepare($sql);
			$stmt->execute();

			$this->serverSettings = array();
			while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
			{
				$this->serverSettings[$this->db->unmarshal($row['config_name'], 'string')] = $this->db->unmarshal($row['config_value'], 'string');
			}
			Settings::getInstance()->set('server', $this->serverSettings);

			/* Posted admin data */
			// We need to reverse the entities or the password can be mangled
			$passwd			= html_entity_decode(\Sanitizer::get_var('passwd', 'string', 'POST'));
			$passwd2		= html_entity_decode(\Sanitizer::get_var('passwd2', 'string', 'POST'));
			$username		= \Sanitizer::get_var('username', 'string', 'POST');
			$fname			= \Sanitizer::get_var('fname', 'string', 'POST');
			$lname			= \Sanitizer::get_var('lname', 'string', 'POST');

			if (($this->serverSettings['account_repository'] == 'ldap')
				&& !$this->accounts->connected
			)
			{
				echo "<strong>Error: Error connecting to LDAP server {$this->serverSettings['ldap_host']}</strong><br>";
				exit;
			}

			$errors = $this->validate_admin($username, $passwd, $passwd2, $fname, $lname);

			if (in_array($username, array('admins', 'default')))
			{
				$errors[] = $this->setup->lang('That loginid has already been taken');
			}

			if (!count($errors))
			{
				$admin_acct = array(
					'username'	=> $username,
					'firstname'	=> $fname,
					'lastname'	=> $lname,
					'password'	=> $passwd
				);

				// Begin transaction for acl, etc
				// FIXME: Conflicting transactions - there are transactions in phpgwapi_accounts_::create() and acl::save_repository()
				//$this->db->transaction_begin();

				// Now, clear out existing tables
				$contacts_to_delete = $this->accounts->get_account_with_contact();
				$this->db->exec('DELETE FROM phpgw_accounts');
				$this->db->exec('DELETE FROM phpgw_preferences');
				$this->db->exec('DELETE FROM phpgw_acl');
				$this->db->exec('DELETE FROM phpgw_mapping');
				$this->db->exec('DELETE FROM phpgw_group_map');
				$this->db->exec("DELETE FROM phpgw_nextid WHERE appname = 'groups' OR appname = 'accounts'");
				$this->db->exec('DELETE FROM phpgw_contact');
				$this->db->exec('DELETE FROM phpgw_contact_person');
				$this->db->exec('DELETE FROM phpgw_contact_org');
				// Clean out LDAP
				if ($this->serverSettings['account_repository'] == 'ldap' || $this->serverSettings['account_repository'] = 'sqlldap')
				{
					$accounts = $this->accounts->get_list('accounts', -1, '', '', '', -1);

					foreach ($accounts as $account)
					{
						$this->accounts->delete($account->id);
					}
					$accounts = $this->accounts->get_list('groups', -1, '', '', '', -1);
					foreach ($accounts as $account)
					{
						$this->accounts->delete($account->id);
					}
				}

				unset($contacts_to_delete);

				/* Create the groups */
				// Group perms for the default group
				$modules = array(
					'addressbook',
					'calendar',
					'email',
					'filemanager',
					'manual',
					'preferences',
					'notes',
					'todo'
				);

				$acls[] = array(
					'appname'	=> 'preferences',
					'location'	=> 'changepassword',
					'rights'	=> 1
				);

				$group = array('username' => 'default');
				$defaultgroupid = $this->add_account($group, 'g', array(), $modules);

				$group = array('username' => 'admins');
				$admingroupid   = $this->add_account($group, 'g', array(), array('admin'));

				$this->insert_default_prefs($defaultgroupid);	// set some default prefs

				$groups = array($defaultgroupid, $admingroupid);

				$accountid = $this->add_account($admin_acct, 'u', $groups, array('admin'), $acls);
				Header('Location: ../setup');
				exit;
			}
		}

		/* detect whether anything will be deleted before alerting */
		$stmt = $this->db->prepare('SELECT config_value FROM phpgw_config WHERE config_name = :config_name');
		$stmt->execute(['config_name' => 'account_repository']);
		$account_repository = $stmt->fetchColumn();

		$account_creation_notice = $this->setup->lang("This will create an admininstrator account");
		if ($account_repository == 'sql')
		{
			$stmt = $this->db->prepare('SELECT COUNT(*) AS cnt FROM phpgw_accounts');
			$stmt->execute();
			$number_of_accounts = $stmt->fetchColumn();
			if ($number_of_accounts)
			{
				$account_creation_notice .= "\n"
					. $this->setup->lang('<b>!!!THIS WILL DELETE ALL EXISTING ACCOUNTS!!!</b><br>');
			}
		}

		$error_msg = '';
		if (count($errors))
		{
			$error_msg = '<div class="msg">' . implode("<br>\n", $errors) . '</div>';
		}

		// Prepare the variables for the Twig template
		$templateVars = [
			'errors'           => $error_msg,
			'description'      => $account_creation_notice,
			'title'            => $this->setup->lang('create accounts'),
			'detailadmin'      => $this->setup->lang('Details for admininstrator account'),
			'adminusername'    => $this->setup->lang('Admin username'),
			'adminfirstname'   => $this->setup->lang('Admin first name'),
			'adminlastname'    => $this->setup->lang('Admin last name'),
			'adminpassword'    => $this->setup->lang('Admin password'),
			'adminpassword2'   => $this->setup->lang('Re-enter password'),
			'lang_submit'      => $this->setup->lang('Save'),
			'lang_cancel'      => $this->setup->lang('Cancel'),
			'val_username'     => $username,
			'val_fname'        => $fname,
			'val_lname'        => $lname,
			'action_url'       => 'accounts'
		];

		// Get the header and footer
		$header = $this->html->get_header($this->setup->lang('Demo Server Setup'));
		
		// Render the accounts template using Twig
		$content = $this->twig->renderBlock('accounts.html.twig', 'setup_demo', $templateVars);
		
		// Get the footer
		$footer = $this->html->get_footer();
		
		// Return the complete page
		return $header . $content . $footer;
	}
}
