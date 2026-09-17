<?php

namespace App\modules\preferences\helpers;

use App\modules\phpgwapi\services\Settings;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use App\helpers\Template;
use App\modules\phpgwapi\security\Acl;
use App\modules\phpgwapi\services\Cache;
use App\modules\phpgwapi\controllers\Accounts\phpgwapi_user;
use App\modules\phpgwapi\services\Translation;
use App\modules\phpgwapi\services\Hooks;
use App\modules\phpgwapi\services\Preferences as Prefs;
use Sanitizer;
use Exception;


class PreferenceHelper
{
	private $serverSettings, $userSettings, $hooks, $preferences, $prefs,$type, $show_help, $has_help, $prefix, $notifys,$list_shown;
	private $flags, $template, $acl, $phpgwapi_common, $translation, $nextmatchs;
	/**
     * @var Template reference to singleton instance
     */
	private static $instance = null;


	private function __construct()
	{
		$this->serverSettings = Settings::getInstance()->get('server');
		$this->userSettings = Settings::getInstance()->get('user');
		$this->flags = Settings::getInstance()->get('flags');

		$this->flags['currentapp'] = isset($_GET['appname']) ? htmlspecialchars($_GET['appname']) : 'preferences';

		$this->flags['noheader'] = true; //Wait for it
		Settings::getInstance()->set('flags', $this->flags);
		require_once SRC_ROOT_PATH . '/helpers/LegacyObjectHandler.php';

		$this->acl = Acl::getInstance();
		$this->phpgwapi_common = new \phpgwapi_common();
		$this->template = Template::getInstance(PHPGW_APP_TPL);
		$this->translation = Translation::getInstance();
		$this->hooks = new Hooks();
//		\_debug_array($this->userSettings['account_id']);
		$this->preferences = Prefs::getInstance($this->userSettings['account_id']);
		$this->nextmatchs	= CreateObject('phpgwapi.nextmatchs');
	}

	public static function getInstance()
	{
		if (self::$instance === null)
		{
			self::$instance = new PreferenceHelper();
		}
		return self::$instance;
	}

	public function changepassword(Request $request, Response $response, array $args)
	{

		$n_passwd   = isset($_POST['n_passwd']) && $_POST['n_passwd'] ? html_entity_decode(Sanitizer::get_var('n_passwd', 'string', 'POST')) : '';
		$n_passwd_2 = isset($_POST['n_passwd_2']) && $_POST['n_passwd_2'] ? html_entity_decode(Sanitizer::get_var('n_passwd_2', 'string', 'POST')) : '';

		if (!$this->acl->check('changepassword', 1, 'preferences') || (isset($_POST['cancel']) && $_POST['cancel']))
		{
			\phpgw::redirect_link('/preferences/index.php');
			$this->phpgwapi_common->phpgw_exit();
		}

		$this->template->set_file(array(
			'form' => 'changepassword.tpl'
		));
		$this->template->set_var('lang_enter_password', lang('Enter your new password'));
		$this->template->set_var('lang_reenter_password', lang('Re-enter your password'));
		$this->template->set_var('lang_change', lang('Change'));
		$this->template->set_var('lang_cancel', lang('Cancel'));
		$this->template->set_var('form_action', \phpgw::link('/preferences/changepassword'));

		if ($this->serverSettings['auth_type'] != 'ldap')
		{
			$this->template->set_var('sql_message', lang('note: This feature does *not* change your email password. This will '
			. 'need to be done manually.'));
		}

		if (isset($_POST['change']) && $_POST['change'])
		{
			$errors = array();

			if ($n_passwd != $n_passwd_2)
			{
				$errors[] = lang('The two passwords are not the same');
			}
			else
			{
				$account	= new phpgwapi_user();
				try
				{
					$account->validate_password($n_passwd);
				}
				catch (Exception $e)
				{
					$errors[] = $e->getMessage();
					//	trigger_error($e->getMessage(), E_USER_WARNING);
				}
			}

			if (!$n_passwd)
			{
				$errors[] = lang('You must enter a password');
			}


			if (count($errors))
			{
				$this->phpgwapi_common->phpgw_header();
				echo parse_navbar();
				$this->template->set_var('messages', $this->phpgwapi_common->error_list($errors));
				$this->template->pfp('out', 'form');
				$this->phpgwapi_common->phpgw_exit(True);
			}

			$o_passwd = $this->userSettings['passwd'];
			$Auth = new \App\modules\phpgwapi\security\Auth\Auth();

			$passwd_changed = $Auth->change_password($o_passwd, $n_passwd);
			if (!$passwd_changed)
			{
				// This need to be changed to show a different message based on the result
				\phpgw::redirect_link('/preferences/index.php', array('cd' => 38));
			}
			else
			{
				$this->userSettings['passwd'] = $Auth->change_password($o_passwd, $n_passwd);
				Settings::getInstance()->set('user', $this->userSettings);
				$hook_values = array();
				$hook_values['account_id'] = $this->userSettings['account_id'];
				$hook_values['old_passwd'] = $o_passwd;
				$hook_values['new_passwd'] = $n_passwd;

				Settings::getInstance()->set('hook_values', $hook_values);

				$this->hooks->process('changepassword');
				\phpgw::redirect_link('/preferences/index.php', array('cd' => 18));
			}
		}
		else
		{
			$this->flags['app_header'] = lang('Change your password');
			Settings::getInstance()->set('flags', $this->flags);
			$this->phpgwapi_common->phpgw_header();
			echo parse_navbar();

			$this->template->pfp('out', 'form');
			$this->phpgwapi_common->phpgw_footer();
		}

//		$text = '';
		$response = $response->withHeader('Content-Type', 'text/plain');
//		$response->getBody()->write($text);
		return $response;
	}

	public function init_display_settings()
	{
		$t = $this->template;
		$t->set_root($this->phpgwapi_common->get_tpl_dir('preferences'));
		$t->set_file('preferences', 'preferences.tpl');
		$t->set_block('preferences', 'list', 'lists');
		$t->set_block('preferences', 'row', 'rowhandle');
		$t->set_block('preferences', 'help_row', 'help_rowhandle');
		$t->set_var(array('rowhandle' => '', 'help_rowhandle' => '', 'messages' => ''));
		return $t;
	}

	public function section(Request $request, Response $response, array $args)
	{
		$appname = Sanitizer::get_var('appname', 'string', 'GET', 'preferences');

		if (Sanitizer::get_var('cancel', 'bool', 'POST'))
		{
			\phpgw::redirect_link('/preferences/index.php');
		}

		$t = $this->init_display_settings();
		$user	 = Sanitizer::get_var('user', 'string', 'POST');
		$forced	 = Sanitizer::get_var('forced', 'string', 'POST');
		$default = Sanitizer::get_var('default', 'string', 'POST');


		if ($appname != 'preferences')
		{
			$this->translation->add_app('preferences'); // we need the prefs translations too
		}
		$this->translation->add_app($appname);

		/* Only check this once */
		if ($this->acl->check('run', 1, 'admin') || $this->acl->check('admin', Acl::ADD, $this->check_app()))
		{
			define('HAS_ADMIN_RIGHTS', 1);
		}
		else
		{
			define('HAS_ADMIN_RIGHTS', 0);
		}

		$session_data = Cache::session_get('preferences', 'session_data');

		$this->prefix = Sanitizer::get_var('prefix', 'string', 'GET');
		if (!$this->prefix && (isset($session_data['appname']) && $session_data['appname'] == Sanitizer::get_var('appname', 'string', 'GET')))
		{
			$this->prefix = $session_data['prefix'];
		}

		if ($this->is_admin())
		{
			/* This is where we will keep track of our postion. */
			/* Developers won't have to pass around a variable then */

			$this->type = Sanitizer::get_var('type', 'string', 'REQUEST', $session_data['type']);

			if (empty($this->type))
			{
				$this->type = 'user';
			}
		}
		else
		{
			$this->type = 'user';
		}

		$this->show_help = false;
		if (isset($session_data['show_help']) && $session_data['show_help'] != '' && $session_data['appname'] == $appname)
		{
			$this->show_help = $session_data['show_help'];
		}
		else if (isset($this->userSettings['preferences']['common']['show_help']))
		{
			$this->show_help = !!$this->userSettings['preferences']['common']['show_help'];
		}

		$toggle_help = Sanitizer::get_var('toggle_help', 'bool', 'POST');
		if ($toggle_help)
		{
			$this->show_help = !$this->show_help;
		}
		$this->has_help = 0;

		$error = '';
		if (Sanitizer::get_var('submit', 'bool', 'POST'))
		{
			//_debug_array($_POST);die();
			if (!isset($session_data['notifys']))
			{
				$session_data['notifys'] = array();
			}

			$account_id = Sanitizer::get_var('account_id', 'int', 'POST', $this->userSettings['account_id']);
			if ($this->is_admin() && $account_id)
			{
				$this->preferences->setAccountId($account_id, true);
			}

			/* Don't use a switch here, we need to check some permissions durring the ifs */
			if ($this->type == 'user' || !($this->type))
			{
				$error = $this->process_array($this->preferences->user, $user, $session_data['notifys'], $this->prefix);
			}

			if ($this->type == 'default' && $this->is_admin())
			{
				$error = $this->process_array($this->preferences->default, $default, $session_data['notifys']);
			}

			if ($this->type == 'forced' && $this->is_admin())
			{
				$error = $this->process_array($this->preferences->forced, $forced, $session_data['notifys']);
			}

			if (Sanitizer::get_var('phpgw_return_as') == 'json')
			{
				if ($error)
				{
					echo json_encode(array('status' => 'error'));
				}
				else
				{
					echo json_encode(array('status' => 'ok'));
				}
				$this->phpgwapi_common->phpgw_exit();
			}

			if ($this->is_admin() && $account_id)
			{
				$this->preferences->setAccountId($this->userSettings['account_id'], true);
			}

			if (!$this->is_admin() || $error)
			{
				\phpgw::redirect_link('/preferences/index.php');
			}

			if ($this->type == 'user' && $appname == 'preferences' && (isset($user['show_help']) && $user['show_help'] != ''))
			{
				$this->show_help = $user['show_help']; // use it, if admin changes his help-prefs
			}
		}

		Cache::session_set('preferences', 'session_data',
		 array(
			'type'		 => $this->type, // save our state in the app-session
			'show_help'	 => $this->show_help,
			'prefix'	 => $this->prefix,
			'appname'	 => $appname  // we use this to reset prefix on appname-change
		));
		// changes for the admin itself, should have immediate feedback ==> redirect
		if (!$error && (Sanitizer::get_var('submit', 'bool', 'POST')) && $this->type == 'user' && $appname == 'preferences')
		{
			\phpgw::redirect_link('/preferences/section', array(
				'appname'	 => $appname,
				'account_id' => $account_id
			));
		}
		if ($this->is_admin())
		{
			$account_id = Sanitizer::get_var('account_id', 'int');
			if ($account_id)
			{
				$this->preferences->setAccountId($account_id, true);
			}
		}

		$this->flags['app_header'] = $appname == 'preferences' ?
		lang('Preferences') : lang('%1 - Preferences', lang($appname));
		Settings::getInstance()->set('flags', $this->flags);
		//	$this->phpgwapi_common->phpgw_header(true);

		$t->set_var('messages', $error);
		$t->set_var('action_url', \phpgw::link('/preferences/section', array(
			'appname'	 => $appname, 'type'		 => $this->type
		)));

		switch ($this->type) // set up some globals to be used by the hooks
		{
			case 'forced':
				$this->prefs	 = &$this->preferences->forced[$this->check_app()];
				break;
			case 'default':
				$this->prefs	 = &$this->preferences->default[$this->check_app()];
				break;
			default:
				$this->prefs	 = &$this->preferences->user[$this->check_app()];
				// use prefix if given in the url, used for email extra-accounts
				if ($this->prefix != '')
				{
					$prefix_arr = explode('/', $this->prefix);
					foreach ($prefix_arr as $pre)
					{
						$this->prefs = &$this->prefs[$pre];
					}
				}
		}
		//echo "prefs=<pre>"; print_r($this->prefs); echo "</pre>\n";

		$this->notifys = array();
		if (!$this->hooks->single('settings', $appname))
		{
			$t->set_block('preferences', 'form', 'formhandle'); // skip the form
			$t->set_var('formhandle', '');

			$t->set_var('messages', lang(
				'Error: There was a problem finding the preference file for %1 in %2',
				lang($appname),
				"/path/to/phpgroupware/{$appname}/inc/hook_settings.inc.php"
			));
		}

		if (count($this->notifys)) // there have been notifys in the hook, we need to save in the session
		{
			Cache::session_get('preferences', 'session_data', array(
				'type'		 => $this->type, // save our state in the app-session
				'show_help'	 => $this->show_help,
				'prefix'	 => $this->prefix,
				'appname'	 => $appname, // we use this to reset prefix on appname-change
				'notifys'	 => $this->notifys
			));
			//echo "notifys:<pre>"; print_r($this->notifys); echo "</pre>\n";
		}

		$tabs = array();

		$tabs['user'] = array(
			'label'	 => lang('Your preferences'),
			'link'	 => \phpgw::link('/preferences/section', array(
				'appname'	 => $appname,
				'type'		 => 'user'
			))
		);

		if ($this->is_admin())
		{
			$tabs['default'] = array(
				'label'	 => lang('Default preferences'),
				'link'	 => \phpgw::link('/preferences/section', array(
					'appname'	 => $appname,
					'type'		 => 'default'
				))
			);
			$tabs['forced']	 = array(
				'label'	 => lang('Forced preferences'),
				'link'	 => \phpgw::link('/preferences/section', array(
					'appname'	 => $appname,
					'type'		 => 'forced'
				))
			);

			switch ($this->type)
			{
				case 'user':
					$accounts	 = array();
					$account_id	 = Sanitizer::get_var('account_id', 'int', 'REQUEST', 0);

					$__account	 = (new \App\modules\phpgwapi\controllers\Accounts\Accounts())->get($account_id);
					if ($__account->enabled)
					{
						$accounts[]	 = array(
							'id'	 => $__account->id,
							'name'	 => $__account->__toString()
						);
					}

					\phpgw::import_class('phpgwapi.jquery');
					\phpgwapi_jquery::load_widget('select2');

					$account_list	 = "<div><form class='pure-form' method='POST' action=''>";
					$account_list	 .= '<select name="account_id" id="account_id" onChange="this.form.submit();" style="width:50%;">';
					$account_list	 .= "<option value=''>" . lang('select user') . '</option>';
					foreach ($accounts as $account)
					{
						$account_list .= "<option value='{$account['id']}'";
						if ($account['id'] == $account_id)
						{
							$account_list .= ' selected';
						}
						$account_list .= "> {$account['name']}</option>\n";
					}
					$account_list	 .= '</select>';
					$account_list	 .= '<noscript><input type="submit" name="user" value="Select"></noscript>';
					$account_list	 .= '</form></div>';

					$lan_user = lang('Search for a user');
					$account_list	 .= <<<HTML
					<script>
						var oArgs = {menuaction: 'preferences.boadmin_acl.get_users'};
						var strURL = phpGWLink('index.php', oArgs, true);

						$("#account_id").select2({
						  ajax: {
							url: strURL,
							dataType: 'json',
							delay: 250,
							data: function (params) {
							  return {
								query: params.term, // search term
								page: params.page || 1
							  };
							},
							cache: true
						  },
						  width: '50%',
						  placeholder: '{$lan_user}',
						  minimumInputLength: 2,
						  language: "no",
						  allowClear: true
						});

						$('#account_id').on('select2:open', function (e) {

							$(".select2-search__field").each(function()
							{
								if ($(this).attr("aria-controls") == 'select2-account_id-results')
								{
									$(this)[0].focus();
								}
							});
						});


					</script>
HTML;

					$t->set_var('select_user', $account_list);

					if ($account_id)
					{
						$t->set_var('account_id', "<input type='hidden' name='account_id' value='{$account_id}'>");
					}

					$pre_div	 = '<div id="user">';
					$post_div	 = '</div><div id="default"></div><div id="forced"></div>';
					break;
				case 'default':
					$pre_div	 = '<div id="user"></div><div id="default">';
					$post_div	 = '</div><div id="forced"></div>';
					break;
				case 'forced';
					$pre_div	 = '<div id="user"></div><div id="default"></div><div id="forced">';
					$post_div	 = '</div>';
					break;
			}
		}
		else
		{
			$pre_div	 = '<div id="user">';
			$post_div	 = '</div><div id="default"></div><div id="forced"></div>';
		}
		$t->set_var('pre_div', $pre_div);
		$t->set_var('post_div', $post_div);

		$t->set_var('tabs', $this->phpgwapi_common->create_tabs($tabs, $this->type));
		$t->set_var('lang_submit', lang('save'));
		$t->set_var('lang_cancel', lang('cancel'));
		$t->set_var('show_help', intval($this->show_help));
		$t->set_var('help_button', $this->has_help ? '<input type="submit" name="toggle_help" value="' .
		($this->show_help ? lang('help off') : lang('help')) . '" />' : '');

		if (!isset($this->list_shown) || !$this->list_shown)
		{
			$this->show_list();
		}

		$this->phpgwapi_common->phpgw_header(true);
		//preferences/templates/base/css/base.css

		$css = <<<CSS
		<style type="text/css" scoped="scoped">
		.pure-control-group {
			border-bottom: 1px solid;
		}
		.pure-control-group label {
			text-align: left;
			width: 35em;
		}
		</style>

CSS;
		echo $css;
		$t->pfp('phpgw_body', 'preferences');

		//echo '<pre style="text-align: left;">'; print_r($this->preferences->data); echo "</pre>\n";

		$this->phpgwapi_common->phpgw_footer(true);

		$response = $response->withHeader('Content-Type', 'text/plain');
//		$response->getBody()->write($text);
		return $response;
	}

	/**
	 * Get application name
	 *
	 * @return string Application name
	 */
	function check_app()
	{
		$app = Sanitizer::get_var('appname', 'string', 'GET', '');
		if (!$app || $app == 'preferences')
		{
			return 'common';
		}
		return $app;
	}

	/* Make things a little easier to follow */
	/* Some places we will need to change this if there in common */

	/**
	 * Is the current value forced
	 *
	 * @param $_appname
	 * @param $preference_name
	 * @return boolean
	 */
	function is_forced_value($_appname, $preference_name)
	{
		if (isset($this->preferences->forced[$_appname][$preference_name]) && $this->type != 'forced')
		{
			return True;
		}
		else
		{
			return False;
		}
	}

	/**
	 * Create password box
	 *
	 * @param string $label_name
	 * @param string $preference_name
	 * @param string $help
	 * @param $size
	 * @param $max_size
	 * @return boolean
	 */
	public function create_password_box($label_name, $preference_name, $help = '', $size = '', $max_size = '')
	{

		$_appname = $this->check_app();
		if ($this->is_forced_value($_appname, $preference_name))
		{
			return True;
		}
		$this->create_input_box($label_name, $preference_name . '][pw', $help, '', $size, $max_size, 'password');
	}

	/**
	 * Create input box
	 *
	 * @param string $label
	 * @param string $name
	 * @param string $help
	 * @param string $default
	 * @param $size
	 * @param $max_size
	 * @param $type
	 * @return boolean
	 */
	public function create_input_box($label, $name, $help = '', $default = '', $size = '', $maxsize = '', $type = '', $run_lang = true)
	{

		$t = $this->template;

		$def_text	 = '';
		$_appname	 = $this->check_app();

		if ($this->is_forced_value($_appname, $name))
		{
			return true;
		}

		$options = '';
		if ($type) // used to specify password
		{
			$options = " type=\"$type\"";
		}
		if ($size)
		{
			$options .= " size=\"$size\"";
		}
		if ($maxsize)
		{
			$options .= " maxsize=\"$maxsize\"";
		}

		$default = '';
		if (isset($this->prefs[$name]) || $this->type != 'user')
		{
			$default = isset($this->prefs[$name]) && $this->prefs[$name] ? $this->prefs[$name] : '';
		}

		if ($this->type == 'user')
		{
			$def_text = (!isset($this->preferences->user[$_appname][$name]) || !$this->preferences->user[$_appname][$name]) ?
				(isset($this->preferences->data[$_appname][$name]) ? $this->preferences->data[$_appname][$name] : '') : (isset($this->preferences->default[$_appname][$name]) ? $this->preferences->default[$_appname][$name] : '');

			if (isset($this->notifys[$name])) // translate the substitution names
			{
				$def_text = $this->preferences->lang_notify($def_text, $this->notifys[$name]);
			}
			$def_text = ($def_text != '') ? lang('default') . ": $def_text" : '';
		}
		$t->set_var('row_value', "<input class=\"pure-input-1-2\" name=\"{$this->type}[$name]\" value=\"" . htmlentities($default, ENT_COMPAT, 'UTF-8') . "\"$options />$def_text");
		$t->set_var('row_name', lang($label));
		$this->nextmatchs->template_alternate_row_class($t);

		$t->fp('rows', $this->process_help($help, $run_lang) ? 'help_row' : 'row', True);
	}

	/**
	 *
	 *
	 * @param $help
	 * @param boolean $run_lang
	 * @return boolean
	 */
	function process_help($help, $run_lang = True)
	{
		$t = $this->template;

		if (!empty($help))
		{
			$this->has_help = True;

			if ($this->show_help)
			{
				$t->set_var('help_value', $run_lang ? lang($help) : $help);

				return True;
			}
		}
		return False;
	}

	/**
	 * Create checkbox
	 *
	 * @param string $label
	 * @param string $name
	 * @param string $help
	 * @param $default
	 */
	public function create_check_box($label, $name, $help = '', $default = '')
	{
		// checkboxes itself can't be use as they return nothing if uncheckt !!!

		if ($this->type != 'user')
		{
			$default = ''; // no defaults for default or forced prefs
		}
		if (isset($this->prefs[$name]))
		{
			$this->prefs[$name] = intval(!!$this->prefs[$name]); // to care for '' and 'True'
		}

		return $this->create_select_box($label, $name, array(
			'0'	 => lang('No'),
			'1'	 => lang('Yes')
		), $help, $default);
	}

	/**
	 * Create option
	 *
	 * @param string $selected
	 * @param string $values
	 * @return string String with HTML option
	 */
	public function create_option_string($selected, $values)
	{
		$s = '';
		if (!is_array($values))
		{
			return '';
		}

		foreach ($values as $var => $value)
		{
			$s .= '<option value="' . $var . '"';
			if ("$var" == "$selected") // the "'s are necessary to force a string-compare
			{
				$s .= ' selected';
			}
			$s .= '>' . $value . '</option>';
		}
		return $s;
	}

	/**
	 * Create selectbox
	 *
	 * @param string $label
	 * @param string $name
	 * @param $values
	 * @param string $help
	 * @param string $default
	 */
	public function create_select_box($label, $name, $values, $help = '', $default = '')
	{
		$t = $this->template;

		$_appname = $this->check_app();
		if ($this->is_forced_value($_appname, $name))
		{
			return True;
		}

		if (isset($this->prefs[$name]) || $this->type != 'user')
		{
			$default = (isset($this->prefs[$name]) ? $this->prefs[$name] : '');
		}

		switch ($this->type)
		{
			case 'user':
				$s	 = '<option value="">' . lang('Use default') . '</option>';
				break;
			case 'default':
				$s	 = '<option value="">' . lang('No default') . '</option>';
				break;
			case 'forced':
				$s	 = '<option value="**NULL**">' . lang('Users choice') . '</option>';
				break;
		}
		$s			 .= $this->create_option_string($default, $values);
		$def_text	 = '';
		if ($this->type == 'user' && isset($this->preferences->default[$_appname][$name]))
		{
			$def_text	 = $this->preferences->default[$_appname][$name];
			$def_text	 = $def_text != '' ? ' <i>' . lang('default') . ':&nbsp;' . (isset($values[$def_text]) ? $values[$def_text] : '') . '</i>' : '';
		}
		$t->set_var('row_value', "<select class=\"pure-input-1-2\" name=\"{$this->type}[$name]\">$s</select>$def_text");
		$t->set_var('row_name', lang($label));
		$this->nextmatchs->template_alternate_row_class($t);

		$t->fp('rows', $this->process_help($help) ? 'help_row' : 'row', True);
	}

	/**
	 * Create text-area or inputfield with subtitution-variables
	 *
	 * @param string $label Untranslated label
	 * @param string $name Name of the preference
	 * @param $rows Row of the textarea or input-box ($rows==1)
	 * @param $cols Column of the textarea or input-box
	 * @param string $help Untranslated help-text
	 * @param string $default Default-value
	 * @param $vars2 array with extra substitution-variables of the form key => help-text
	 * @param boolean $subst_help
	 */
	public function create_notify($label, $name, $rows, $cols, $help = '', $default = '', $vars2 = '', $subst_help = True)
	{
		$t = $this->template;


		$vars = $this->preferences->get_vars();
		if (is_array($vars2))
		{
			$vars = array_merge($vars, $vars2);
		}
		$this->prefs[$name] = $this->preferences->lang_notify($this->prefs[$name], $vars);

		$this->notifys[$name] = $vars; // this gets saved in the app_session for re-translation

		$help = $help ? lang($help) : '';
		if ($subst_help)
		{
			$help .= '<p><b>' . lang('Substitutions and their meanings:') . '</b>';
			foreach ($vars as $var => $var_help)
			{
				$lname	 = ($lname	 = lang($var)) == $var . '*' ? $var : $lname;
				$help	 .= "<br />\n" . '<b>$$' . $lname . '$$</b>: ' . $var_help;
			}
			$help .= "</p>\n";
		}
		if ($rows == 1)
		{
			$this->create_input_box($label, $name, $help, $default, $cols, '', '', False);
		}
		else
		{
			$this->create_text_area($label, $name, $rows, $cols, $help, $default, False);
		}
	}

	/**
	 * Create textarea
	 *
	 * @param string $label
	 * @param string $name
	 * @param $rows
	 * @param $cols
	 * @param string $help
	 * @param string $default
	 * @param boolean $run_lang
	 * @return boolean
	 */
	public function create_text_area($label, $name, $rows, $cols, $help = '', $default = '', $run_lang = True)
	{
		$t = $this->template;

		$_appname = $this->check_app();
		if ($this->is_forced_value($_appname, $name))
		{
			return True;
		}

		if (isset($this->prefs[$name]) || $this->type != 'user')
		{
			$default = $this->prefs[$name];
		}

		if ($this->type == 'user')
		{
			$def_text = !isset($this->preferences->user[$_appname][$name]) || !$this->preferences->user[$_appname][$name] ? (isset($this->preferences->data[$_appname][$name]) ? $this->preferences->data[$_appname][$name] : '') : (isset($this->preferences->default[$_appname][$name]) ? $this->preferences->default[$_appname][$name] : '');

			if (isset($this->notifys[$name])) // translate the substitution names
			{
				$def_text = $this->preferences->lang_notify($def_text, $this->notifys[$name]);
			}
			$def_text = $def_text != '' ? '<br><i><font size="-1"><b>' . lang('default') . '</b>:<br>' . nl2br($def_text) . '</font></i>' : '';
		}
		$t->set_var('row_value', "<textarea class=\"pure-input-1-2 pure-custom\" rows=\"$rows\" cols=\"$cols\" name=\"{$this->type}[$name]\">" . htmlentities($default, ENT_QUOTES, isset($this->serverSettings['charset']) && $this->serverSettings['charset'] ? $this->serverSettings['charset'] : 'UTF-8') . "</textarea>$def_text");
		$t->set_var('row_name', lang($label));
		$this->nextmatchs->template_alternate_row_class($t);

		$t->fp('rows', $this->process_help($help, $run_lang) ? 'help_row' : 'row', True);
	}

	/**
	 *
	 *
	 * @param $repository
	 * @param $array
	 * @param $notifys
	 * @param $prefix
	 * @return boolean
	 */
	function process_array(&$repository, $array, $notifys, $prefix = '')
	{
		$_appname = $this->check_app();

		$this->prefs = &$repository[$_appname];

		if ($prefix != '')
		{
			$prefix_arr = explode('/', $prefix);
			foreach ($prefix_arr as $pre)
			{
				$this->prefs = &$this->prefs[$pre];
			}
		}
		unset($this->prefs['']);
		//echo "array:<pre>"; print_r($array); echo "</pre>\n";
		//while (is_array($array) && list($var,$value) = each($array))
		if (is_array($array))
		{
			foreach ($array as $var => $value)
			{
				if (isset($value) && $value != '' && $value != '**NULL**')
				{
					if (is_array($value))
					{
						$value = $value['pw'];
						if (empty($value))
						{
							continue; // dont write empty password-fields
						}
					}
					$this->prefs[$var] = stripslashes($value);

					if (isset($notifys[$var]) && $notifys[$var]) // need to translate the key-words back
					{
						$this->prefs[$var] = $this->preferences->lang_notify($this->prefs[$var], $notifys[$var], True);
					}
				}
				else
				{
					unset($this->prefs[$var]);
				}
			}
		}
		//echo "prefix='$prefix', prefs=<pre>"; print_r($repository[$_appname]); echo "</pre>\n";
		// the following hook can be used to verify the prefs
		// if you return something else than False, it is treated as an error-msg and
		// displayed to the user (the prefs get not saved !!!)
		//
		if ($error = $this->hooks->single(array(
			'location'	 => 'verify_settings',
			'prefs'		 => $repository[$_appname],
			'prefix'	 => $prefix,
			'type'		 => $this->type
		), $_GET['appname']))
		{
			return $error;
		}

		$this->preferences->save_repository(True, $this->type);

		return False;
	}
	/* Makes the ifs a little nicer, plus ... this will change once the ACL manager is in place */
	/* and is able to create less powerfull admins.  This will handle the ACL checks for that (jengo) */

	/**
	 * Test if user is admin
	 *
	 * @return boolean True when user is admin otherwise false
	 */
	function is_admin()
	{

		if (HAS_ADMIN_RIGHTS == 1 && empty($this->prefix)) // tabs only without prefix
		{
			return True;
		}
		else
		{
			return False;
		}
	}

	/**
	 *
	 *
	 * @param string $header
	 */
	function show_list($header = '&nbsp;')
	{
		$t = $this->template;

		$tab_id = $this->type;
		$t->set_var('tab_id', $tab_id);
		$t->set_var('list_header', $header);
		$t->parse('lists', 'list', $this->list_shown);

		$t->set_var('rows', '');
		$this->list_shown = True;
	}

	
	public function index(Request $request, Response $response, array $args)
	{

		$templates = array(
			'pref' => 'index.tpl'
		);

		$this->template->set_file($templates);

		$this->template->set_block('pref', 'list');
		$this->template->set_block('pref', 'app_row');
		$this->template->set_block('pref', 'app_row_noicon');
		$this->template->set_block('pref', 'link_row');
		$this->template->set_block('pref', 'spacer_row');

		if (!$this->acl->check('run', 1, 'preferences'))
		{
//			\_debug_array($this->acl);
			die(lang('You do not have access to preferences'));
		}

		// This is where we will keep track of our position.
		// Developers won't have to pass around a variable then
		$session_data = Cache::session_get('preferences', 'session_data');

		if (!is_array($session_data))
		{
			$session_data = array('type' => 'user');
			Cache::session_set('preferences', 'session_data', $session_data);
		}

		$type = Sanitizer::get_var('type', 'string', 'GET');

		if (!$type)
		{
			$type = $session_data['type'];
		}
		else
		{
			$session_data = array('type' => $type);
			Cache::session_set('preferences', 'session_data', $session_data);
		}

		$is_admin = false;
		if ($this->acl->check('run', 1, 'admin'))
		{
			$is_admin = true;
		}

		$tabs = array();
		$tabs['user'] = array(
			'label' => lang('Your preferences'),
			'link'  => \phpgw::link('/preferences/index.php', array('type' => 'user')),
			'disable' => 0
		);
		$tabs['default'] = array(
			'label' => lang('Default preferences'),
			'link'  =>  $is_admin ? \phpgw::link('/preferences/index.php', array('type' => 'default')) : "#default",
			'disable' => $is_admin ? 0 : 1
		);
		$tabs['forced'] = array(
			'label' => lang('Forced preferences'),
			'link'  =>  $is_admin ? \phpgw::link('/preferences/index.php', array('type' => 'forced')) : "#forced",
			'disable' => $is_admin ? 0 : 1
		);
		$this->template->set_var('tabs', $this->phpgwapi_common->create_tabs($tabs, $type));
		$this->template->set_var('tab_id', $type);

		$this->phpgwapi_common->phpgw_header(true);


		$menus = execMethod('phpgwapi.menu.get');
		foreach ($this->userSettings['apps'] as $app => $app_info)
		{
			if (isset($menus['preferences'][$app]))
			{
				$this->display_section($menus['navbar'][$app], $menus['preferences'][$app]);
			}
		}

		$this->template->pfp('out', 'list');
		$this->phpgwapi_common->phpgw_footer();


		$text = '';
		$response = $response->withHeader('Content-Type', 'text/plain');
//		$response->getBody()->write($text);
		return $response;
	}
	/**
	 * Dump a row header
	 * 
	 * @param $appname=''
	 * @param $icon
	 */
	function section_start($appname = '', $icon = '')
	{
		$this->template->set_var('a_name', str_replace(" ", "_", $appname));
		$this->template->set_var('app_name', $appname);
		$this->template->set_var('app_icon', $icon);
		if ($icon)
		{
			$this->template->parse('rows', 'app_row', true);
		}
		else
		{
			$this->template->parse('rows', 'app_row_noicon', true);
		}
	}

	/**
	 * 
	 * 
	 * @param string $pref_link
	 * @param string $pref_text
	 */
	function section_item($pref_link = '', $pref_text = '')
	{
		$this->template->set_var('pref_link', $pref_link);

		if (strtolower($pref_text) == 'grant access' && isset($this->serverSettings['deny_user_grants_access']) && $this->serverSettings['deny_user_grants_access'])
		{
			return False;
		}
		else
		{
			$this->template->set_var('pref_text', $pref_text);
		}

		$this->template->parse('rows', 'link_row', true);
	}

	/**
	 * 
	 */
	function section_end()
	{
		$this->template->parse('rows', 'spacer_row', true);
	}

	/**
	 * 
	 * 
	 * @param $appname
	 * @param $file
	 * @param $file2
	 */
	function display_section($nav, $items)
	{
		$this->section_start($nav['text'], $this->phpgwapi_common->image($nav['image'][0], $nav['image'][1]));
		foreach ($items as $item)
		{
			$this->section_item($item['url'], $item['text']);
		}
		$this->section_end();
	}
}
