<?php

/**
 * phpGroupWare
 *
 * phpgroupware base
 * @author Quang Vu DANG <quang_vu.dang@int-evry.fr>
 * @copyright Copyright (C) 2000-2005 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @package phpgroupware
 * @version $Id$
 */

/**
 * The script provides an interface for creating the new account
 * if phpGroupware allows users to create the accounts
 *
 * Using with Signle Sign-On (Shibboleth, CAS, ...)
 *
 */

namespace App\modules\phpgwapi\security\Sso;

use App\modules\phpgwapi\security\Sso\Mapping;
use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\controllers\Accounts\Accounts;
use App\modules\phpgwapi\controllers\Accounts\phpgwapi_user;
use App\modules\phpgwapi\helpers\LoginUi;
use App\modules\phpgwapi\controllers\OpenIDConnect;

use Exception;
use Sanitizer;

class CreateAccount
{

	private $login;
	private $mapping;
	private $serverSettings;

	public function __construct()
	{
		$phpgw_map_location = isset($_SERVER['HTTP_SHIB_ORIGIN_SITE']) ? $_SERVER['HTTP_SHIB_ORIGIN_SITE'] : 'local';
		$phpgw_map_authtype = isset($_SERVER['HTTP_SHIB_ORIGIN_SITE']) ? 'shibboleth' : 'remoteuser';

		$this->mapping = new Mapping(array('auth_type' => $phpgw_map_authtype, 'location' => $phpgw_map_location));

		$this->serverSettings = Settings::getInstance()->get('server');

		if (!isset($this->serverSettings['auto_create_acct']) || $this->serverSettings['auto_create_acct'] != True)
		{
			throw new Exception(lang('Access denied'));
		}
		if (!is_object($this->mapping))
		{
			throw new Exception(lang('Access denied'));
		}

		$Auth = new \App\modules\phpgwapi\security\Auth\Auth();

		$this->login = $Auth->get_username(true);

		if (isset($_SERVER["OIDC_groups"]))
		{
			$OIDC_groups = mb_convert_encoding(mb_convert_encoding($_SERVER["OIDC_groups"], 'ISO-8859-1', 'UTF-8'), 'UTF-8', 'ISO-8859-1');
			$ad_groups	= explode(",", $OIDC_groups);
			$default_group_lid	 = !empty($this->serverSettings['default_group_lid']) ? $this->serverSettings['default_group_lid'] : 'default';
			if (!in_array(strtolower($default_group_lid), array_map('strtolower', $ad_groups)))
			{
				throw new Exception(lang('missing membership: "%1" is not in the list', $default_group_lid));
			}
		}
		else if (OpenIDConnect::getInstance()->isAuthenticated())
		{
			$ad_groups	= OpenIDConnect::getInstance()->get_groups();
			$default_group_lid	 = !empty($this->serverSettings['default_group_lid']) ? $this->serverSettings['default_group_lid'] : 'default';
			if (!in_array(strtolower($default_group_lid), array_map('strtolower', $ad_groups)))
			{
				throw new Exception(lang('missing membership: "%1" is not in the list', $default_group_lid));
			}
		}
		else if (!\Sanitizer::get_var('OIDC_pid', 'bool', 'SERVER'))
		{
			throw new Exception(lang('Access denied'));
		}

		if (empty($this->login))
		{
			//reserve fallback
			if (\Sanitizer::get_var('OIDC_pid', 'bool', 'SERVER') || Settings::getInstance()->get('flags')['openid_connect']['OIDC_pid'])
			{
				\phpgw::redirect_link('login_ui/', array('skip_remote' => true));
			}
			//fallback failed
			throw new Exception(lang('Did not find any username'));
		}
		else
		{
			if ($this->mapping->get_mapping($this->login) != '')
			{
				throw new Exception(lang('Username already taken'));
			}
			if (($account = $this->mapping->exist_mapping($this->login)) != '')
			{
				\phpgw::redirect_link('/login_ui', array('create_mapping' => true, 'cd' => '21', 'phpgw_account' => $account));
			}
		}
	}

	public function display_create()
	{
		$login = $this->login;

		$firstname	 = '';
		$lastname	 = '';
		if (isset($_SERVER["HTTP_SHIB_GIVENNAME"]))
		{
			$firstname = $_SERVER["HTTP_SHIB_GIVENNAME"];
		}
		if (isset($_SERVER["HTTP_SHIB_SURNAME"]))
		{
			$lastname = $_SERVER["HTTP_SHIB_SURNAME"];
		}

		if (isset($_SERVER["OIDC_given_name"]))
		{
			$firstname = \Sanitizer::get_var('OIDC_given_name', 'string', 'SERVER');
		}
		if (isset($_SERVER["OIDC_family_name"]))
		{
			$lastname = \Sanitizer::get_var('OIDC_family_name', 'string', 'SERVER');
		}

		$email	 = \Sanitizer::get_var('OIDC_email', 'string', 'SERVER');
		$cellphone = '';

		if (OpenIDConnect::getInstance()->isAuthenticated())
		{
			$userinfo = OpenIDConnect::getInstance()->get_userinfo();
			$email = isset($userinfo->email) ? $userinfo->email : $userinfo->upn;
			$firstname = $userinfo->given_name;
			$lastname = $userinfo->family_name;
		}

		if ($_SERVER['REQUEST_METHOD'] == 'POST' && \Sanitizer::get_var('submitit', 'bool', 'POST'))
		{
			$submit = \Sanitizer::get_var('submitit', 'bool', 'POST');
			if (!$this->serverSettings['mapping'] == 'id') // using REMOTE_USER for account_lid
			{
				$login = \Sanitizer::get_var('login', 'string', 'POST');
			}
			$firstname	 = \Sanitizer::get_var('firstname', 'string', 'POST');
			$lastname	 = \Sanitizer::get_var('lastname', 'string', 'POST');
			$password1	 = !empty($_POST['passwd']) ? html_entity_decode(\Sanitizer::get_var('passwd', 'string', 'POST')) : '';
			$password2	 = !empty($_POST['passwd_confirm']) ? html_entity_decode(\Sanitizer::get_var('passwd_confirm', 'string', 'POST')) : '';
			$email		 = \Sanitizer::get_var('email', 'email', 'POST');
			$cellphone	 = \Sanitizer::get_var('cellphone', 'string', 'POST');
		}

		$error = array();
		if (isset($submit) && $submit)
		{
			if (!$login)
			{
				$error[] = lang('You have to choose a login');
			}

			if (!preg_match("/^[0-9_a-z\-\.@]+$/i", $login))
			{
				$error[] = lang('Please submit only letters, numbers, and basic punctuation (.-@_) for your login');
			}
			if (!$password1)
			{
				$error[] = lang('You have to choose a password');
			}

			if ($password1 != $password2)
			{
				$error[] = lang('Please, check your password');
			}

			$Accounts = new Accounts();

			$account = new phpgwapi_user();

			try
			{
				$account->validate_password($password1);
			}
			catch (Exception $e)
			{
				$error[] = $e->getMessage();
			}

			if ($Accounts->exists($login))
			{
				$error[] = lang("user %1 already exists, please try another login", $login);
			}

			if (!is_array($error) || count($error) == 0)
			{
				if (!$firstname)
				{
					$firstname = $login;
				}
				if (!$lastname)
				{
					$lastname = $login;
				}

				$account_id = $Accounts->auto_add($login, $password1, $firstname, $lastname);

				if ($this->serverSettings['mapping'] == 'table') // using only mapping by table
				{
					$this->mapping->add_mapping($_SERVER['REMOTE_USER'], $login);
				}
				else if ($this->serverSettings['mapping'] == 'all' && $login != $_SERVER['REMOTE_USER'])
				{
					$this->mapping->add_mapping($_SERVER['REMOTE_USER'], $login);
				}

				if ($account_id)
				{
					if (!empty($email))
					{
						$title	 = lang('User access');
						$message = lang('account has been created');
						$from	 = "noreply<noreply@{$this->serverSettings['hostname']}>";
						$send = new \App\modules\phpgwapi\services\Send();

						try
						{
							$send->msg('email', $email, $title, stripslashes(nl2br($message)), '', '', '', $from, 'System message', 'html', '', array(), false);
						}
						catch (Exception $ex)
						{
						}
					}
					$preferences = new \App\modules\phpgwapi\services\Preferences($account_id);

					$preferences->add('common', 'email', $email);
					if ($cellphone)
					{
						$preferences->add('common', 'cellphone', $cellphone);
					}

					$preferences->save_repository();

					$Log = new \App\modules\phpgwapi\services\Log();
					$Log->write(array(
						'text'	 => 'I-Notification, user created %1',
						'p1'	 => $login
					));
				}
				\phpgw::redirect_link('/home/', array('cd' => 'yes'));
			}
		}

		$uilogin = new LoginUi(false);

		$variables = array();
		if ($this->serverSettings['mapping'] == 'id') // using REMOTE_USER for account_lid
		{
			$variables['login_read_only'] = true;
		}
		$variables['lang_message'] = lang('your account doesn\'t exist, please fill in infos !');

		if (count($error))
		{
			$phpgwapi_common = new \phpgwapi_common();
			$variables['lang_message'] .= $phpgwapi_common->error_list($error);
		}
		$variables['lang_login']			 = lang('new account and login');
		$variables['login']					 = $login;
		$variables['lang_firstname']		 = lang('firstname');
		$variables['lang_lastname']			 = lang('lastname');
		$variables['lang_email']			 = lang('email');
		$variables['lang_cellphone']		 = lang('cellphone');
		$variables['firstname']				 = $firstname;
		$variables['lastname']				 = $lastname;
		$variables['email']					 = $email;
		$variables['cellphone']				 = $cellphone;
		$variables['lang_confirm_password']	 = lang('confirm password');
		$variables['partial_url']			 = 'login_ui';
		$variables['extra_vars']			 = array('create_account' => true);
		if (!($this->serverSettings['mapping'] == 'id'))
		{
			$variables['lang_additional_url']	 = lang('new mapping');
			$variables['additional_url']		 = \phpgw::link('/login_ui', array('create_mapping' => true));
		}

		return $uilogin->phpgw_display_login($variables);
	}
}
