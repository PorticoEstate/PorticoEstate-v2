<?php

	namespace App\modules\phpgwapi\helpers;

	use Psr\Http\Message\ServerRequestInterface as Request;
	use Psr\Http\Message\ResponseInterface as Response;
	use App\modules\phpgwapi\security\Login;
	use App\modules\phpgwapi\services\Settings;
//use App\modules\phpgwapi\services\Hooks;
//use App\modules\phpgwapi\services\Cache;
	use App\modules\phpgwapi\services\Translation;
	use App\modules\phpgwapi\services\Preferences;
//use App\modules\phpgwapi\controllers\Applications;
	use App\helpers\Template;
	use App\modules\phpgwapi\security\Acl;
	use App\modules\phpgwapi\security\Sessions;
	use Sanitizer;
	use phpgw;

	class LoginUi
	{

		private $serverSettings;
		private $userSettings;
		private $translations;
		//private $hooks;
		private $phpgwapi_common;
		private $apps;
		var $tmpl	 = null;
		var $msg_only = false;

		public function __construct( $msg_only = false )
		{
			$this->serverSettings = Settings::getInstance()->get('server');
			$this->userSettings	  = Settings::getInstance()->get('user');
			$this->translations	  = Translation::getInstance();

			$this->serverSettings['template_set'] = Settings::getInstance()->get('login_template_set');
			$this->serverSettings['template_dir'] = PHPGW_SERVER_ROOT
				. "/phpgwapi/templates/{$this->serverSettings['template_set']}";

			Settings::getInstance()->set('server', $this->serverSettings);

			$tmpl = new Template($this->serverSettings['template_dir']);

			// This is used for system downtime, to prevent new logins.
			if (
				isset($this->serverSettings['deny_all_logins']) && $this->serverSettings['deny_all_logins']
			)
			{
				$tmpl->set_file(
					array(
						'login_form' => 'login_denylogin.tpl'
					)
				);
				$tmpl->pfp('loginout', 'login_form');
				exit;
			}
			$this->tmpl		= $tmpl;
			$this->msg_only = $msg_only;
		}

		/**
		 * Check logout error code
		 *
		 * @param integer $code Error code
		 * @return string Error message
		 */
		function check_logoutcode( $code )
		{
			$sessions = Sessions::getInstance();
	
			switch ($code)
			{
				case 1:
					return lang('You have been successfully logged out');
				case 2:
					return lang('Sorry, your login has expired');
				case 5:
					return lang('Bad login or password');
				case 20:
					return lang('Cannot find the mapping ! (please advice your adminstrator)');
				case 21:
					return lang('you had inactive mapping to %1 account', Sanitizer::get_var('phpgw_account', 'string', 'GET', ''));
				case 22:
					$sessions->phpgw_setcookie('kp3');
					$sessions->phpgw_setcookie('domain');
					return lang('you seemed to have an active session elsewhere for the domain "%1", now set to expired - please try again', Sanitizer::get_var('domain', 'string', 'COOKIE'));
				case 99:
					return lang('Blocked, too many attempts');
				case 10:
					$sessions->phpgw_setcookie('kp3');
					$sessions->phpgw_setcookie('domain');
					return lang('sorry, your session has expired');
				default:
					return '&nbsp;';
			}
		}

		/**
		 * Check languages
		 */
		function check_langs()
		{
			// echo "<h1>check_langs()</h1>\n";
			if (
				isset($this->serverSettings['lang_ctimes']) && !is_array($this->serverSettings['lang_ctimes'])
			)
			{
				$this->serverSettings['lang_ctimes'] = unserialize($this->serverSettings['lang_ctimes']);
			}
			elseif (!isset($this->serverSettings['lang_ctimes']))
			{
				$this->serverSettings['lang_ctimes'] = array();
			}
			Settings::getInstance()->set('server', $this->serverSettings);

			// _debug_array($this->serverSettings['lang_ctimes']);

			$lang			  = $this->userSettings['preferences']['common']['lang'];
			$apps			  = (array)$this->userSettings['apps'];
			$apps['phpgwapi'] = true; // check the api too
			foreach (array_keys($apps) as $app)
			{
				$fname = PHPGW_SERVER_ROOT . "/$app/setup/phpgw_$lang.lang";

				if (file_exists($fname))
				{
					$ctime = filectime($fname);
					$ltime = isset($this->serverSettings['lang_ctimes'][$lang]) &&
						isset($this->serverSettings['lang_ctimes'][$lang][$app]) ?
						(int)$this->serverSettings['lang_ctimes'][$lang][$app] : 0;
					//echo "checking lang='$lang', app='$app', ctime='$ctime', ltime='$ltime'<br>\n";

					if ($ctime != $ltime)
					{
						$this->update_langs();  // update all langs
						break;
					}
				}
			}
		}

		/**
		 * Update languages
		 */
		function update_langs()
		{
			$langs = $this->translations->get_installed_langs();
			foreach (array_keys($langs) as $lang)
			{
				$langs[$lang] = $lang;
			}
			$this->translations->update_db($langs, 'dumpold');
		}

		function phpgw_display_login( $variables, $cd = 0 )
		{
			$settings	  = require SRC_ROOT_PATH . '/../config/header.inc.php';
			$phpgw_domain = $settings['phpgw_domain'];

			// If the lastloginid cookies isn't set, we will default to default_lang - then to english.
			// Change this if you need.
			$lightbox = isset($_REQUEST['lightbox']) && $_REQUEST['lightbox'] ? true : false;

			$this->userSettings['preferences']['common']['lang'] = !empty($this->serverSettings['default_lang']) ? $this->serverSettings['default_lang'] : 'en';
			if (isset($_COOKIE['last_loginid']))
			{
				$accounts = new \App\modules\phpgwapi\controllers\Accounts\Accounts();

				$prefs = Preferences::getInstance();
				$prefs->setAccountId($accounts->name2id($_COOKIE['last_loginid']));

				if ($prefs->account_id)
				{
					$this->userSettings['preferences'] = $prefs->read();
				}
			}
			$selected_lang = Sanitizer::get_var('lang', 'string', 'GET', '');
			if ($selected_lang)
			{
				$this->userSettings['preferences']['common']['lang'] = $selected_lang;
			}
			Settings::getInstance()->set('user', $this->userSettings);

			$this->translations->set_userlang($this->userSettings['preferences']['common']['lang'], $reset = true);

			$lang = array(
				'domain'   => lang('domain'),
				'username' => lang('username'),
				'password' => lang('password')
			);

			$this->tmpl->set_file(array('login_form' => 'login.tpl'));

			$this->tmpl->set_block('login_form', 'header_block', 'header_blocks');
			$this->tmpl->set_block('login_form', 'instruction_block', 'instruction_blocks');

			$this->tmpl->set_block('login_form', 'message_block', 'message_blocks');
			$this->tmpl->set_block('login_form', 'domain_option', 'domain_options');
			$this->tmpl->set_block('login_form', 'domain_select', 'domain_selects');
			$this->tmpl->set_block('login_form', 'login_additional_info', 'login_additional_infos');
			$this->tmpl->set_block('login_form', 'login_check_passwd', 'login_check_passwds');
			$this->tmpl->set_block('login_form', 'domain_from_host', 'domain_from_hosts');
			$this->tmpl->set_block('login_form', 'password_block', 'password_blocks');
			$this->tmpl->set_block('login_form', 'loging_block', 'loging_blocks');
			$this->tmpl->set_block('login_form', 'forgotten_password_block', 'forgotten_password_blocks');
			$this->tmpl->set_block('login_form', 'info_block', 'info_blocks');
			$this->tmpl->set_block('login_form', 'button_block', 'button_blocks');
			$this->tmpl->set_block('login_form', 'footer_block', 'footer_blocks');

			if (
				$this->serverSettings['domain_from_host'] && !$this->serverSettings['show_domain_selectbox']
			)
			{
				$this->tmpl->set_var(
					array(
						'domain_selects' => '',
						'logindomain'	 => $_SERVER['SERVER_NAME']
					)
				);
				$this->tmpl->parse('domain_from_hosts', 'domain_from_host');
			}
			elseif ($this->serverSettings['show_domain_selectbox'])
			{

				foreach ($phpgw_domain as $domain_name => $domain_vars)
				{
					$this->tmpl->set_var('domain_name', $domain_name);
					$this->tmpl->set_var('domain_display_name', str_replace('_', ' ', $domain_name));

					if (isset($_COOKIE['last_domain']) && $_COOKIE['last_domain'] == $domain_name)
					{
						$this->tmpl->set_var('domain_selected', 'selected="selected"');
					}
					else
					{
						$this->tmpl->set_var('domain_selected', '');
					}

					$this->tmpl->parse('domain_options', 'domain_option', true);
				}
				$this->tmpl->parse('domain_selects', 'domain_select');
				$this->tmpl->set_var(
					array(
						'domain_from_hosts' => '',
						'lang_domain'		=> $lang['domain']
					)
				);
			}
			else
			{
				$this->tmpl->set_var(
					array(
						'domain_selects'	=> '',
						'domain_from_hosts' => ''
					)
				);
			}

			$this->translations->add_app('login');
			$this->translations->add_app('loginscreen');
			if (($login_msg = lang('loginscreen_message')) != '!loginscreen_message')
			{
				$this->tmpl->set_var('lang_message', stripslashes($login_msg));
			}
			else
			{
				if (isset($variables['lang_message']))
				{
					$this->tmpl->set_var('lang_message', $variables['lang_message']);
				}
				else
				{
					$this->tmpl->set_var('lang_message', '&nbsp;');
				}
			}

			if ((!isset($this->serverSettings['usecookies']) || !$this->serverSettings['usecookies']) && (isset($_COOKIE) && is_array($_COOKIE))
			)
			{
				if (isset($_COOKIE['last_loginid']))
				{
					unset($_COOKIE['last_loginid']);
				}

				if (isset($_COOKIE['last_domain']))
				{
					unset($_COOKIE['last_domain']);
				}
			}

			$last_loginid = isset($_COOKIE['last_loginid']) ? $_COOKIE['last_loginid'] : '';

			if ($last_loginid)
			{
				$accounts->name2id($_COOKIE['last_loginid']);

				$acl = Acl::getInstance();
				$acl->set_account_id($accounts->name2id($_COOKIE['last_loginid']));
				if ($acl->check('anonymous', 1, 'phpgwapi'))
				{
					$last_loginid = '';
				}
			}

			if ($this->serverSettings['show_domain_selectbox'] && $last_loginid !== '')
			{
				$_phpgw_domains = array_keys($phpgw_domain);
				$default_domain = $_phpgw_domains[0];

				if ($_COOKIE['last_domain'] != $default_domain && !empty($_COOKIE['last_domain']) && !$this->serverSettings['show_domain_selectbox'])
				{
					$last_loginid .= '#' . $_COOKIE['last_domain'];
				}
			}

			if (isset($variables['lang_firstname']) && isset($variables['lang_lastname']) && isset($variables['lang_confirm_password']))
			{
				//We first put the login in it
				if (isset($variables['login']))
				{
					$last_loginid = $variables['login'];
				}

				//then first / last name
				$this->tmpl->set_var('lang_firstname', $variables['lang_firstname']);
				$this->tmpl->set_var('lang_lastname', $variables['lang_lastname']);
				$this->tmpl->set_var('lang_email', $variables['lang_email']);
				$this->tmpl->set_var('lang_cellphone', $variables['lang_cellphone']);

				if (isset($variables['firstname']))
				{
					$this->tmpl->set_var('firstname', $variables['firstname']);
				}
				if (isset($variables['lastname']))
				{
					$this->tmpl->set_var('lastname', $variables['lastname']);
				}
				if (isset($variables['email']))
				{
					$this->tmpl->set_var('email', $variables['email']);
				}
				if (isset($variables['cellphone']))
				{
					$this->tmpl->set_var('cellphone', $variables['cellphone']);
				}
				//parsing the block
				$this->tmpl->parse('login_additional_infos', 'login_additional_info');
				$this->tmpl->set_var('login_additional_info', '');

				//then the passwd confirm
				$this->tmpl->set_var('lang_confirm_password', $variables['lang_confirm_password']);
				//parsing the block
				$this->tmpl->parse('login_check_passwds', 'login_check_passwd');

				if (isset($variables['login_read_only']) && $variables['login_read_only'])
				{
					$this->tmpl->set_var('login_read_only', ' readonly="readonly"');
				}
			}
			else
			{
				$this->tmpl->set_var(
					array(
						'login_additional_info' => '',
						'login_check_psswd'		=> ''
					)
				);
			}

			//FIXME switch to an array
			$extra_vars = array();
			foreach ($_GET as $name => $value)
			{
				if (preg_match('/phpgw_/', $name))
				{
					$extra_vars[$name] = urlencode($value);
				}
			}

			if (isset($variables['extra_vars']) && is_array($variables['extra_vars']))
			{
				$extra_vars = array_merge($extra_vars, $variables['extra_vars']);
			}

			$system_name = isset($this->serverSettings['system_name']) ? $this->serverSettings['system_name'] : 'Portico Estate';

			if ($variables['lang_frontend'])
			{
				$system_name .= "::{$variables['lang_frontend']}";
			}

			$webserver_url_short = rtrim($this->serverSettings['webserver_url'], '/');
			$webserver_url = $webserver_url_short . PHPGW_MODULES_PATH;
			$partial_url   = ltrim($variables['partial_url'], '/');

			//	$this->tmpl->set_var('login_url', $webserver_url . '/'.$partial_url.'?' . http_build_query($extra_vars) );
			$this->tmpl->set_var('login_url', phpgw::link("/{$partial_url}", $extra_vars));

			$this->tmpl->set_var('registration_url', $webserver_url_short . '/registration/');
			$this->tmpl->set_var('system', $system_name);
			$this->tmpl->set_var('version', isset($this->serverSettings['versions']['system']) ? $this->serverSettings['versions']['system'] : $this->serverSettings['versions']['phpgwapi']);
			$this->tmpl->set_var('instruction', lang('use a valid username and password to gain access to %1', $system_name));

			$this->tmpl->set_var('cd', $this->check_logoutcode($cd));
			$this->tmpl->set_var('last_loginid', $last_loginid);
			if (isset($_REQUEST['skip_remote']) && $_REQUEST['skip_remote'])
			{
				$this->tmpl->set_var('skip_remote', true);
			}
			if (isset($_REQUEST['lightbox']) && $_REQUEST['lightbox'])
			{
				$this->tmpl->set_var('lightbox', true);
			}

			if (isset($_REQUEST['hide_lightbox']) && $_REQUEST['hide_lightbox'])
			{
				$onload = <<<JS
					<script language="javascript" type="text/javascript">
						if(typeof(parent.lightbox_login) != 'undefined')
						{
						parent.lightbox_login.hide();
						}
						else
						{
							parent.TINY.box.hide();
						}
					</script>
JS;
			}
			else
			{
				$onload = <<<JS
				<script language="javascript" type="text/javascript">
					window.onload = function()
					{
						document.login.login.select();
						document.login.login.focus();
					}
				</script>
JS;
			}
			$this->tmpl->set_var('onload', $onload);

			$this->tmpl->set_var('lang_username', $lang['username']);
			$this->tmpl->set_var('lang_password', $lang['password']);
			if (isset($variables['lang_login']))
			{
				$this->tmpl->set_var('lang_login', $variables['lang_login']);
			}

			//		$this->tmpl->set_var('lang_testjs', lang('Your browser does not support javascript and/or css, please use a modern standards compliant browser.  If you have disabled either of these features please enable them for this site.') );

			if (isset($variables['lang_additional_url']) && isset($variables['additional_url']))
			{
				$this->tmpl->set_var('lang_return_sso_login', $variables['lang_additional_url']);
				$this->tmpl->set_var('return_sso_login_url', $variables['additional_url']);
			}
			if (empty($variables['extra_vars']['create_mapping']))
			{
				$this->tmpl->set_var('lang_new_user', lang('new user'));
				$this->tmpl->set_var('lang_forgotten_password', lang('forgotten password'));
			}

			if (isset($this->serverSettings['new_user_url']) && $this->serverSettings['new_user_url'])
			{
				$url_new_user	 = $this->serverSettings['new_user_url'];
				$action_new_user = $url_new_user;
			}
			else
			{
				$url_new_user	 = "{$webserver_url_short}/registration/";
				$action_new_user = 'javascript:new_user();';
			}
			$this->tmpl->set_var('url_new_user', $url_new_user);

			if (isset($this->serverSettings['lost_password_url']) && $this->serverSettings['lost_password_url'])
			{
				$url_lost_password	  = $this->serverSettings['lost_password_url'];
				$action_lost_password = $url_lost_password;
			}
			else
			{
				$url_lost_password	  = "{$webserver_url_short}/registration/?" . http_build_query(
						array(
							'menuaction' => 'registration.uireg.lostpw1'
						)
				);
				$action_lost_password = 'javascript:lost_password();';
			}

			$this->tmpl->set_var('url_lost_password', $url_lost_password);
			$this->tmpl->set_var('action_new_user', $action_new_user);
			$this->tmpl->set_var('action_lost_password', $action_lost_password);

			$this->tmpl->set_var(
				'website_title',
				isset($this->serverSettings['site_title']) ? $this->serverSettings['site_title'] : 'PorticoEstate'
			);

			$this->tmpl->set_var('template_set', Settings::getInstance()->get('login_template_set'));

			$responsive_css		 = "{$webserver_url}/phpgwapi/templates/pure/css/version_3/pure-min.css";
			$responsive_grid_css = "{$webserver_url}/phpgwapi/templates/pure/css/version_3/grids-responsive-min.css";
			$font_awesome		 = "{$webserver_url}/phpgwapi/templates/base/css/fontawesome/css/all.min.css";

			if (is_file("{$this->serverSettings['template_dir']}/css/base.css"))
			{
				$base_css = "{$webserver_url}/phpgwapi/templates/{$this->serverSettings['template_set']}/css/base.css";
			}
			else
			{
				$base_css = "{$webserver_url}/phpgwapi/templates/base/css/base.css";
			}

			$system_css = "{$webserver_url}/phpgwapi/templates/base/css/system.css";

			if (is_file("{$this->serverSettings['template_dir']}/css/login.css"))
			{
				$login_css = "{$webserver_url}/phpgwapi/templates/{$this->serverSettings['template_set']}/css/login.css";
			}
			else
			{
				$login_css = "{$webserver_url}/phpgwapi/templates/base/css/login.css";
			}

			$rounded_css = "{$webserver_url}/phpgwapi/templates/base/css/rounded.css";

			$flag_no = "{$webserver_url}/phpgwapi/templates/base/images/flag_no.gif";
			$flag_en = "{$webserver_url}/phpgwapi/templates/base/images/flag_en.gif";

			$this->tmpl->set_var('responsive_css', $responsive_css);
			$this->tmpl->set_var('responsive_grid_css', $responsive_grid_css);
			$this->tmpl->set_var('system_css', $system_css);
			$this->tmpl->set_var('base_css', $base_css);
			$this->tmpl->set_var('login_css', $login_css);
			$this->tmpl->set_var('font_awesome', $font_awesome);

			if (empty($variables['lang_firstname']))
			{
				$this->tmpl->set_var('grid_css', 'pure-u-md-1-2');
			}
			$this->tmpl->set_var('rounded_css', $rounded_css);
			$this->tmpl->set_var('flag_no', $flag_no);
			$this->tmpl->set_var('flag_en', $flag_en);
			$this->tmpl->set_var('lightbox', $lightbox);

			if ($lightbox)
			{
				$lightbox_css = <<<HTML

		<style id='lightbox-login-css' type='text/css' scoped='scoped'>
			.content-wrapper {
				top: 0px;
			}
		</style>

HTML;
				$this->tmpl->set_var('lightbox_css', $lightbox_css);
			}
			else
			{
				$this->tmpl->set_var('login_left_message', Settings::getInstance()->get('login_left_message'));
				$this->tmpl->set_var('login_right_message', Settings::getInstance()->get('login_right_message'));
				$this->tmpl->parse('header_blocks', 'header_block');
				$this->tmpl->parse('instruction_blocks', 'instruction_block');
				if (empty($variables['lang_firstname']))
				{
					$this->tmpl->parse('forgotten_password_blocks', 'forgotten_password_block');
					$this->tmpl->parse('info_blocks', 'info_block');
					$this->tmpl->parse('footer_blocks', 'footer_block');
				}
			}

			$autocomplete = '';
			if (
				isset($this->serverSettings['autocomplete_login']) && $this->serverSettings['autocomplete_login']
			)
			{
				$autocomplete = 'autocomplete="off"';
			}
			$this->tmpl->set_var('autocomplete', $autocomplete);
			unset($autocomplete);

			if ($cd)
			{
				if ($cd == 1)
				{
					$this->tmpl->set_var('message_class', 'message');
					$this->tmpl->set_var('message_class_item', 'message message fade');
				}
				else
				{
					$this->tmpl->set_var('message_class', 'error');
					$this->tmpl->set_var('message_class_item', 'error message fade');
				}
				$this->tmpl->parse('message_blocks', 'message_block');
			}

			if (isset($variables['lang_message']))
			{
				$this->tmpl->parse('message_blocks', 'message_block');
			}

			if (!$this->msg_only)
			{
				$this->tmpl->parse('loging_blocks', 'loging_block');
				$this->tmpl->parse('password_blocks', 'password_block');
				$this->tmpl->parse('button_blocks', 'button_block');
			}
			
			return $this->tmpl->fp('loginout', 'login_form');
		}
	}