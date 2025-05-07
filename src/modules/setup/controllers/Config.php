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
use App\helpers\Template2;
use App\modules\phpgwapi\services\setup\SetupTranslation;
use App\modules\phpgwapi\services\Sanitizer;
use App\modules\phpgwapi\services\Twig;
use App\helpers\DateHelper;
use PDO;

class Config
{
	/**
	 * @var object
	 */
	private $db;
	private $detection;
	private $process;
	private $html;
	private $setup;
	private $setup_tpl;
	private $translation;
	private $twig;

	public function __construct()
	{

		//setup_info
		Settings::getInstance()->set('setup_info', []); //$GLOBALS['setup_info']
		//setup_data
		Settings::getInstance()->set('setup', []); //$setup_data
		//current_config
		Settings::getInstance()->set('current_config', []); //$current_config

		$this->db = Db::getInstance();
		$this->detection = new Detection();
		$this->process = new Process();
		$this->html = new Html();
		$this->setup = new Setup();
		$this->translation = new SetupTranslation();

		$flags = array(
			'noheader' 		=> True,
			'nonavbar'		=> True,
			'currentapp'	=> 'setup',
			'noapi'			=> True,
			'nocachecontrol' => True
		);
		Settings::getInstance()->set('flags', $flags);
		$this->twig = Twig::getInstance();


		// Check header and authentication
		if (!$this->setup->auth('Config'))
		{
			Header('Location: ../setup');
			exit;
		}

		// We still need to initialize the legacy template system for backwards compatibility
		$tpl_root = $this->html->setup_tpl_dir('setup');
		$this->setup_tpl = new Template2($tpl_root);
		$this->setup_tpl->set_unknowns('loose');

		$this->html->set_tpl($this->setup_tpl);
	}

	/**
	 * Test if $path lies within the webservers document-root
	 * 
	 * @param string $path File/directory path
	 * @return boolean True when path is within webservers document-root; otherwise false
	 */
	function in_docroot($path)
	{
		$docroots = array(SRC_ROOT_PATH, $_SERVER['DOCUMENT_ROOT']);

		foreach ($docroots as $docroot)
		{
			$len = strlen($docroot);

			if ($docroot == substr($path, 0, $len))
			{
				$rest = substr($path, $len);

				if (!strlen($rest) || $rest[0] == '/')
				{
					return true;
				}
			}
		}
		return false;
	}

	public function index()
	{
		if (\Sanitizer::get_var('cancel', 'bool', 'POST'))
		{
			Header('Location: ../setup');
			exit;
		}

		// Following to ensure windows file paths are saved correctly
		//set_magic_quotes_runtime(0);

		$current_config = Settings::getInstance()->get('current_config');
		// Guessing default values.
		$current_config['hostname']  = $_SERVER['HTTP_HOST'];
		// files-dir is not longer allowed in document root, for security reasons !!!
		$current_config['files_dir'] = '/outside/webserver/docroot';

		if (@is_dir('/tmp'))
		{
			$current_config['temp_dir'] = '/tmp';
		}
		elseif (@is_dir('C:\\TEMP'))
		{
			$current_config['temp_dir'] = 'C:\\TEMP';
		}
		else
		{
			$current_config['temp_dir'] = '/path/to/temp/dir';
		}
		// guessing the phpGW url
		$parts = explode('/', $_SERVER['REDIRECT_URL']);
		unset($parts[count($parts) - 1]); // config
		unset($parts[count($parts) - 1]); // setup
		$current_config['webserver_url'] = implode('/', $parts);
		// Add some sane defaults for accounts
		$current_config['account_min_id'] = 1000;
		$current_config['account_max_id'] = 65535;
		$current_config['group_min_id'] = 500;
		$current_config['group_max_id'] = 999;
		$current_config['ldap_account_home'] = '/noexistant';
		$current_config['ldap_account_shell'] = '/bin/false';
		$current_config['ldap_host'] = 'localhost';

		$current_config['encryptkey'] = md5(time() . $_SERVER['HTTP_HOST']); // random enough

		$setup_info = $this->detection->get_db_versions();
		$newsettings = \Sanitizer::get_var('newsettings', 'string', 'POST');

		$files_in_docroot = (isset($newsettings['files_dir'])) ? $this->in_docroot($newsettings['files_dir']) : false;
		
		if (\Sanitizer::get_var('submit', 'string', 'POST') && is_array($newsettings) && !$files_in_docroot)
		{
			switch (intval($newsettings['daytime_port']))
			{
				case 13:
					$newsettings['tz_offset'] = DateHelper::getntpoffset();
					break;
				case 80:
					$newsettings['tz_offset'] = DateHelper::gethttpoffset();
					break;
				default:
					$newsettings['tz_offset'] = DateHelper::getbestguess();
					break;
			}

			$this->db->transaction_begin();

			foreach ($newsettings as $setting => $value)
			{
				//	echo '<br />Updating: ' . $setting . '=' . $value;

				$setting = $this->db->db_addslashes($setting);

				/* Don't erase passwords, since we also do not print them below */
				if (
					$value
					|| (!preg_match('/passwd/', $setting) && !preg_match('/password/', $setting) && !preg_match('/root_pw/', $setting))
				)
				{
					$stmt = $this->db->prepare("DELETE FROM phpgw_config WHERE config_name=:setting");
					$stmt->execute([':setting' => $setting]);
				}
				/* cookie_domain has to allow an empty value*/
				if ($value || $setting == 'cookie_domain')
				{
					$value = $this->db->db_addslashes($value);
					$stmt = $this->db->prepare("INSERT INTO phpgw_config (config_app, config_name, config_value) VALUES (:config_app, :config_name, :config_value)");
					$stmt->execute([':config_app' => 'phpgwapi', ':config_name' => $setting, ':config_value' => $value]);
				}
			}
			$this->db->transaction_commit();

			// Add cleaning of app_sessions per skeeter, but with a check for the table being there, just in case
			$tables = array();
			foreach ((array) $this->db->table_names() as $key => $val)
			{
				$tables[] = $val;
			}
			if (in_array('phpgw_app_sessions', $tables))
			{
				$this->db->transaction_begin();
				$stmt = $this->db->prepare("DELETE FROM phpgw_app_sessions WHERE sessionid = '0' and loginid = '0' and app = 'phpgwapi' and location = 'config'");
				$stmt->execute();

				$stmt = $this->db->prepare("DELETE FROM phpgw_app_sessions WHERE app = 'phpgwapi' and location = 'phpgw_info_cache'");
				$stmt->execute();
				$this->db->transaction_commit();
			}

			if ($newsettings['auth_type'] == 'ldap')
			{
				Header('Location:/setup/ldap');
				exit;
			}
			else
			{
				Header('Location:/setup');
				exit;
			}

			//exit;
		}

		$db_config = $this->db->get_config();
		$header = '';

		if (!isset($newsettings['auth_type']) || $newsettings['auth_type'] != 'ldap')
		{
			$header = $this->html->get_header($this->setup->lang('Configuration'), False, 'config', $this->db->get_domain() . '(' .  $db_config["db_type"] . ')');
		}

		$stmt = $this->db->prepare("SELECT * FROM phpgw_config");
		$stmt->execute();

		while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
		{
			$current_config[$row['config_name']] = $row['config_value'];
		}

		// are we here because of an error: files-dir in docroot
		if (isset($_POST['newsettings']) && is_array($_POST['newsettings']) && $files_in_docroot)
		{
			echo '<p class="err">' . $this->setup->lang('Path to user and group files HAS TO BE OUTSIDE of the webservers document-root!!!') . "</strong></p>\n";

			foreach ($_POST['newsettings'] as $key => $val)
			{
				$current_config[$key] = $val;
			}
		}

		if (isset($GLOBALS['error']) && $GLOBALS['error'] == 'badldapconnection')
		{
			// Please check the number and dial again :)
			$this->html->show_alert_msg(
				'Error',
				$this->setup->lang('There was a problem trying to connect to your LDAP server. <br />'
					. 'please check your LDAP server configuration') . '.'
			);
		}

		// Load CSS if available
		$css = '';
		if (is_file(dirname(__DIR__, 1) . "/phpgwapi/templates/pure/css/version_3/pure-min.css"))
		{
			$css = file_get_contents(dirname(__DIR__, 1) . "/phpgwapi/templates/pure/css/version_3/pure-min.css");
		}

		// Prepare data for templates
		$templateVars = [
			'css' => $css,
			'lang_cookies_must_be_enabled' => $this->setup->lang('<b>NOTE:</b> You must have cookies enabled to use setup and header admin!')
		];

		// Render the pre-script part using Twig
		$config_pre_script = $this->twig->renderBlock('config_pre_script.html.twig', 'config_pre_script', $templateVars);
		
		// Process configuration variables
		$vars = [];
		

		$templateContent = file_get_contents(dirname(__DIR__, 1) . "/phpgwapi/templates/base/config.html.twig");
		preg_match_all('/{{\s*([a-zA-Z0-9_]+)(?:\s*\|[^}]*)?\s*}}/', $templateContent, $matches);
		$vars = $matches[1];

		$this->setup->hook('config', 'setup');

		// Prepare the template variables for Twig
		$configVars = [];
		
		if (is_array($vars))
		{
			foreach ($vars as $value)
			{
				$valarray = explode('_', $value);
				$var_type = $valarray[0];
				unset($valarray[0]);
				$newval = implode(' ', $valarray);
				
				switch ($var_type)
				{
					case 'lang':
						$configVars[$value] = $this->setup->lang($newval);
						break;
					case 'value':
						$newval = str_replace(' ', '_', $newval);
						if (preg_match('/(passwd|password|root_pw)/i', $value))
						{
							$configVars[$value] = '';
						}
						else
						{
							$configVars[$value] = isset($current_config[$newval]) ? $current_config[$newval] : '';
						}
						break;
					case 'selected':
						$configs = array();
						$config  = '';
						$newvals = explode(' ', $newval);
						$setting = end($newvals);
						for ($i = 0; $i < (count($newvals) - 1); ++$i)
						{
							$configs[] = $newvals[$i];
						}
						$config = implode('_', $configs);
						if (isset($current_config[$config]) && $current_config[$config] == $setting)
						{
							$configVars[$value] = ' selected';
						}
						else
						{
							$configVars[$value] = '';
						}
						break;
					case 'hook':
						$newval = str_replace(' ', '_', $newval);
						$configVars[$value] = $newval($current_config);
						break;
					default:
						$configVars[$value] = '';
						break;
				}
			}
		}
		
		// Add additional variables
		$configVars['more_configs'] = $this->setup->lang('Please login to phpgroupware and run the admin application for additional site configuration') . '.';
		$configVars['lang_submit'] = $this->setup->lang('Save');
		$configVars['lang_cancel'] = $this->setup->lang('Cancel');
		
		// Merge with main template vars
		$templateVars = array_merge($templateVars, $configVars);
		
		// Render the main template using Twig
		$body = $this->twig->render('config.html.twig', $templateVars);
		
		// Render the post-script part using Twig
		$post_script = $this->twig->renderBlock('config_post_script.html.twig', 'config_post_script', $templateVars);

		$footer = $this->html->get_footer();

		return $header . $config_pre_script . $body . $post_script . $footer;
	}
}
