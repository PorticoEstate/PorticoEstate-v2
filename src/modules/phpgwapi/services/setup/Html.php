<?php

/**
 * Setup html
 * @author Tony Puglisi (Angles) <angles@phpgroupware.org>
 * @author Miles Lott <milosch@phpgroupware.org>
 * @copyright Portions Copyright (C) 2004 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.fsf.org/licenses/gpl.html GNU General Public License
 * @package phpgwapi
 * @subpackage application
 * @version $Id$
 */

namespace App\modules\phpgwapi\services\setup;

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\Twig as TwigService;
use Sanitizer;
use App\modules\phpgwapi\services\setup\Setup;
use App\helpers\Template;


/**
 * Setup html
 *
 * @package phpgwapi
 * @subpackage application
 */
class Html
{
	protected $setup_tpl;
	protected $crypto;
	protected $twig;
	protected $setup;

	function __construct($crypto = null)
	{
		$this->crypto = $crypto;
		$this->setup = new Setup();
		$this->twig = TwigService::getInstance();
		
		// // Initialize the legacy template system for backward compatibility
		// $tpl_dir = $this->setup_tpl_dir();
		// $this->setup_tpl = new Template($tpl_dir);
		// $this->setup_tpl->set_file(array(
		// 	'T_head'	=> 'head.tpl',
		// 	'T_login_main'	=> 'login_main.tpl',
		// 	'T_login_stage_header'	=> 'login_stage_header.tpl',
		// 	'T_footer'	=> 'footer.tpl',
		// 	'T_alert_msg'	=> 'msg_alert.tpl',
		// ));
		// $this->setup_tpl->set_block('T_login_stage_header', 'B_multi_domain', 'V_multi_domain');
		// $this->setup_tpl->set_block('T_login_stage_header', 'B_single_domain', 'V_single_domain');
	}

	function set_tpl($tpl)
	{
		$this->setup_tpl = $tpl;
	}
	
	/**
	 * generate header.inc.php file output - NOT a generic html header function
	 *
	 */
	function generate_header()
	{
		$tpl_root =	SRC_ROOT_PATH . '/../config';

		$setup_tpl = new Template($tpl_root);
		$setup_tpl->set_file(array('header' => 'header.inc.php.template'));
		$setup_tpl->set_block('header', 'domain', 'domain');
		$var = array();

		$deletedomain = Sanitizer::get_var('deletedomain', 'string', 'POST');
		$domains = Sanitizer::get_var('domains', 'string', 'POST');
		if (!is_array($domains))
		{
			$domains = array();
		}

		$setting = Sanitizer::get_var('setting', 'raw', 'POST');
		$settings = Sanitizer::get_var("settings", 'raw', 'POST');

		foreach ($domains as $k => $v)
		{
			if (isset($deletedomain[$k]))
			{
				continue;
			}
			$dom = $settings[$k];
			$setup_tpl->set_var('DB_DOMAIN', $v);

			if (empty($dom['db_port']))
			{
				if ($dom['db_type'] == 'postgres')
				{
					$dom['db_port'] = '5432';
				}
				else
				{
					$dom['db_port'] = '3306';
				}
			}

			foreach ($dom as $x => $y)
			{
				if (((isset($setting['enable_mcrypt']) && $setting['enable_mcrypt'] == 'True') || !empty($setting['enable_crypto'])) && ($x == 'db_pass' || $x == 'db_host' || $x == 'db_port' || $x == 'db_name' || $x == 'db_user' || $x == 'config_pass'))
				{
					$y = $this->crypto->encrypt($y);
				}
				$setup_tpl->set_var(strtoupper($x), $y);
			}
			$setup_tpl->parse('domains', 'domain', True);
		}

		$setup_tpl->set_var('domain', '');

		if (!empty($setting) && is_array($setting))
		{
			foreach ($setting as $k => $v)
			{
				if (((isset($setting['enable_mcrypt']) && $setting['enable_mcrypt'] == 'True')  || !empty($setting['enable_crypto'])) && $k == 'HEADER_ADMIN_PASSWORD')
				{
					$v = $this->crypto->encrypt($v);
				}

				if (
					in_array($k, array('server_root', 'include_root'))
					&& substr(PHP_OS, 0, 3) == 'WIN'
				)
				{
					$v = str_replace('\\', '/', $v);
				}
				$var[strtoupper($k)] = $v;
			}
		}
		$setup_tpl->set_var($var);
		return $setup_tpl->parse('out', 'header');
	}

	function setup_tpl_dir($app_name = 'setup')
	{
		/* hack to get tpl dir */
		if (is_dir(SRC_ROOT_PATH))
		{
			$srv_root = SRC_ROOT_PATH . "/modules/" . $app_name;
		}
		else
		{
			$srv_root = '';
		}

		return "{$srv_root}/templates/base";
	}

	function show_header($title = '', $nologoutbutton = False, $logoutfrom = 'config', $configdomain = '')
	{
		print $this->get_header($title, $nologoutbutton, $logoutfrom, $configdomain);
	}

	function get_header($title = '', $nologoutbutton = False, $logoutfrom = 'config', $configdomain = '')
	{
		$serverSettings = Settings::getInstance()->get('server');
		
		// Get logout button
		$btn_logout = '&nbsp;';
		if (!$nologoutbutton)
		{
			//detect the script path
			$script_path = Sanitizer::get_var('REDIRECT_URL', 'string', 'SERVER');
			//detect if we are in the setup
			$prefix = ($script_path && preg_match('/setup\//', $script_path)) ? '../' : '';
			$btn_logout = '<a href="' . $prefix . 'setup/logout?FormLogout=' . $logoutfrom . '" class="link">' . $this->setup->lang('Logout') . '</a>';
		}

		$api_version = isset($serverSettings['versions']['phpgwapi']) ? $serverSettings['versions']['phpgwapi'] : '';
		$version = isset($serverSettings['versions']['system']) ? $serverSettings['versions']['system'] : $api_version;
		
		// Build template data
		$templateData = [
			'title' => $title,
			'page_title' => $title,
			'css' => '',
			'lang_charset' => $this->setup->lang('charset'),
			'th_bg' => '#486591',
			'th_text' => '#FFFFFF',
			'row_on' => '#DDDDDD',
			'row_off' => '#EEEEEE',
			'banner_bg' => '#4865F1',
			'msg' => '#FF0000',
			'lang_version' => $this->setup->lang('version'),
			'lang_setup' => $this->setup->lang('setup'),
			'configdomain' => $configdomain ? ' - ' . $this->setup->lang('Domain') . ': ' . $configdomain : '',
			'pgw_ver' => $version,
			'logoutbutton' => $btn_logout,
		];
		
		// Render the header using Twig
		return $this->twig->render('head.html.twig', $templateData);
	}

	function get_footer()
	{
		// Render the footer using Twig
		return $this->twig->render('footer.html.twig', []);
	}
	
	function show_footer()
	{
		print $this->get_footer();
	}

	function show_alert_msg($alert_word = 'Setup alert', $alert_msg = 'setup alert (generic)')
	{
		// Render alert using Twig's renderBlock
		$alertData = [
			'V_alert_word' => $alert_word,
			'V_alert_msg' => $alert_msg
		];
		
		echo $this->twig->renderBlock('msg_alert_msg.html.twig', 'alert_msg', $alertData);
	}

	function make_frm_btn_simple($pre_frm_blurb = '', $frm_method = 'POST', $frm_action = '', $input_type = 'submit', $input_value = '', $post_frm_blurb = '')
	{
		// Since this is a simple form generator that's used in many places, keeping the direct HTML version
		$simple_form = $pre_frm_blurb  . "\n"
			. '<form method="' . $frm_method . '" action="' . $frm_action  . '">' . "\n"
			. '<input type="'  . $input_type . '" value="'  . $input_value . '">' . "\n"
			. '</form>' . "\n"
			. $post_frm_blurb . "\n";
		return $simple_form;
	}

	function make_href_link_simple($pre_link_blurb = '', $href_link = '', $href_text = 'default text', $post_link_blurb = '')
	{
		// Since this is a simple HTML generator that's used in many places, keeping the direct HTML version
		$simple_link = $pre_link_blurb
			. '<a href="' . $href_link . '">' . $href_text . '</a> '
			. $post_link_blurb . "\n";
		return $simple_link;
	}

	function login_form()
	{
		$setup_data = Settings::getInstance()->get('setup');
		
		// Prepare the data structure for the Twig template
		$loginData = [
			'ConfigLoginMSG' => isset($setup_data['ConfigLoginMSG']) ? $setup_data['ConfigLoginMSG'] : '&nbsp;',
			'HeaderLoginMSG' => isset($setup_data['HeaderLoginMSG']) ? $setup_data['HeaderLoginMSG'] : '&nbsp;',
		];
		
		// Add domain selection logic for stage header 10
		if ($setup_data['stage']['header'] == '10')
		{
			$loginData['lang_select'] = $this->lang_select();
			
			$settings = require SRC_ROOT_PATH . '/../config/header.inc.php';
			$phpgw_domain = $settings['phpgw_domain'];
			
			// Handle multi-domain vs single domain scenarios
			if (count($phpgw_domain) > 1)
			{
				// Create domains data for the multi_domain block
				$domainsHtml = '';
				foreach ($phpgw_domain as $domain => $data)
				{
					$selected = '';
					if ((isset($setup_data['LastDomain']) && $domain == $setup_data['LastDomain'])
						|| (!isset($setup_data['LastDomain']) && $domain == $_SERVER['SERVER_NAME']))
					{
						$selected = ' SELECTED';
					}
					$domainsHtml .= "<option value=\"$domain\"$selected>$domain</option>\n";
				}
				
				// Use renderBlock to render the multi_domain block
				$multiDomainHtml = $this->twig->renderBlock('login_stage_header.html.twig', 'B_multi_domain', [
					'domains' => $domainsHtml,
					'lang_select' => $loginData['lang_select']
				]);
				$loginData['V_login_stage_header'] = $multiDomainHtml;
			}
			else
			{
				reset($phpgw_domain);
				$default_domain = key($phpgw_domain);
				
				// Use renderBlock to render the single_domain block
				$singleDomainHtml = $this->twig->renderBlock('login_stage_header.html.twig', 'B_single_domain', [
					'default_domain_zero' => $default_domain
				]);
				$loginData['V_login_stage_header'] = $singleDomainHtml;
			}
		}
		else
		{
			$loginData['V_login_stage_header'] = '';
		}
		
		// Render the login form using Twig
		return $this->twig->render('login_main.html.twig', $loginData);
	}

	/**
	 * Generate a select box of available languages
	 *
	 * @param bool $onChange javascript to trigger when selection changes (optional)
	 * @returns string HTML snippet for select box
	 */
	function lang_select($onChange = '')
	{
		$ConfigLang = \Sanitizer::get_var('ConfigLang', 'string', 'POST');
		$select = '<select name="ConfigLang"' . ($onChange ? ' onChange="this.form.submit();"' : '') . '>' . "\n";
		$languages = $this->get_langs();
		foreach ($languages as $null => $data)
		{
			if ($data['available'] && !empty($data['lang']))
			{
				$selected = '';
				$short = substr($data['lang'], 0, 2);
				if ($short == $ConfigLang || empty($ConfigLang) && $short == substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2))
				{
					$selected = ' selected';
				}
				$select .= '<option value="' . $data['lang'] . '"' . $selected . '>' . $data['descr'] . '</option>' . "\n";
			}
		}
		$select .= '</select>' . "\n";

		return $select;
	}

	/**
	 * Get a list of supported languages
	 *
	 * @returns array supported language ['lang' => iso631_code, 'descr' => language_name, 'available' => bool_is_installed]
	 */
	function get_langs()
	{
		$f = fopen(SRC_ROOT_PATH . '/modules/setup/lang/languages', 'rb');
		while ($line = fgets($f, 200))
		{
			list($x, $y) = explode("\t", $line);
			$languages[$x]['lang']  = trim($x);
			$languages[$x]['descr'] = trim($y);
			$languages[$x]['available'] = False;
		}
		fclose($f);

		$d = dir(SRC_ROOT_PATH . '/modules/setup/lang');
		while ($entry = $d->read())
		{
			if (strpos($entry, 'phpgw_') === 0)
			{
				$z = substr($entry, 6, 2);
				$languages[$z]['available'] = True;
			}
		}
		$d->close();

		return $languages;
	}
	
	/**
	 * Prepare app row data for applications.html.twig
	 * 
	 * This method converts app data to the format expected by the Twig template
	 * 
	 * @param array $app Application data
	 * @param string $row_bg_class Background class for the row
	 * @return array Data ready for apps block in applications.html.twig
	 */
	function prepare_app_row_data($app, $row_bg_class)
	{
		$setup = new Setup();
		
		// Build the HTML for each action element (install, upgrade, remove checkboxes)
		$install_html = '';
		if (isset($app['install']) && $app['install'])
		{
			$install_html = '<input type="checkbox" name="install[]" value="' . $app['name'] . '">';
		}
		
		$upgrade_html = '';
		if (isset($app['upgrade']) && $app['upgrade'])
		{
			$upgrade_html = '<input type="checkbox" name="upgrade[]" value="' . $app['name'] . '">';
		}
		
		$remove_html = '';
		if (isset($app['remove']) && $app['remove'])
		{
			$remove_html = '<input type="checkbox" name="remove[]" value="' . $app['name'] . '">';
		}
		
		// Determine status icon and alt text
		$status_icon = 'completed.png';
		$status_alt = $setup->lang('completed');
		if (isset($app['status']) && $app['status'] != 'U')
		{
			$status_icon = 'incomplete.png';
			$status_alt = $setup->lang('not completed');
		}
		
		// Define row classes for the different actions
		$row_install = isset($app['install']) && $app['install'] ? 'row_install_on' : 'row_install_off';
		$row_upgrade = isset($app['upgrade']) && $app['upgrade'] ? 'row_upgrade_on' : 'row_upgrade_off';
		$row_remove = isset($app['remove']) && $app['remove'] ? 'row_remove_on' : 'row_remove_off';
		
		// Build the full app row data for Twig
		return [
			'appname' => isset($app['name']) ? $app['name'] : 'unknown',
			'bg_class' => $row_bg_class,
			'instimg' => $status_icon,
			'instalt' => $status_alt,
			'appinfo' => isset($app['status_text']) ? $app['status_text'] : '',
			'currentver' => isset($app['currentver']) ? $app['currentver'] : '',
			'version' => isset($app['version']) ? $app['version'] : '',
			'install' => $install_html,
			'row_install' => $row_install,
			'upgrade' => $upgrade_html,
			'row_upgrade' => $row_upgrade,
			'remove' => $remove_html,
			'row_remove' => $row_remove,
			'resolution' => isset($app['resolution']) ? $app['resolution'] : ''
		];
	}
	
	/**
	 * Render applications list with Twig
	 * 
	 * @param array $apps Array of app data
	 * @param array $header_data Header data for the template
	 * @return string Rendered HTML
	 */
	function render_applications_list($apps, $header_data)
	{
		$setup = new Setup();
		
		// Start with the header
		$output = $this->twig->renderBlock('applications.html.twig', 'header', [
			'description' => $header_data['description']
		]);
		
		// Add the table header
		$output .= $this->twig->renderBlock('applications.html.twig', 'app_header', [
			'app_info' => $setup->lang('Application'),
			'app_status' => $setup->lang('Status'),
			'app_currentver' => $setup->lang('Current Version'),
			'app_version' => $setup->lang('Available'),
			'app_install' => $setup->lang('Install'),
			'app_upgrade' => $setup->lang('Upgrade'),
			'app_resolve' => $setup->lang('Resolution'),
			'app_remove' => $setup->lang('Remove'),
			'check' => 'check.png',
			'install_all' => $setup->lang('Check all'),
			'upgrade_all' => $setup->lang('Check all'),
			'remove_all' => $setup->lang('Check all')
		]);
		
		// Add each app row
		$row_bg = 'row_on';
		foreach ($apps as $app)
		{
			$app_data = $this->prepare_app_row_data($app, $row_bg);
			$output .= $this->twig->renderBlock('applications.html.twig', 'apps', $app_data);
			$row_bg = ($row_bg == 'row_on') ? 'row_off' : 'row_on';
		}
		
		// Add the footer
		$output .= $this->twig->renderBlock('applications.html.twig', 'app_footer', [
			'debug' => '<input type="checkbox" name="debug" value="1">',
			'check' => 'check.png',
			'install_all' => $setup->lang('Check all'),
			'upgrade_all' => $setup->lang('Check all'),
			'remove_all' => $setup->lang('Check all'),
			'submit' => $setup->lang('Submit'),
			'cancel' => $setup->lang('Cancel')
		]);
		
		$output .= $this->twig->renderBlock('applications.html.twig', 'footer', [
			'footer_text' => $header_data['footer_text'] ?? ''
		]);
		
		return $output;
	}
}
