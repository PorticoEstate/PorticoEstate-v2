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

	function __construct($crypto = null)
	{
		$this->crypto = $crypto;
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
		$setup = new Setup();
		$serverSettings = Settings::getInstance()->get('server');
		$this->setup_tpl->set_var('lang_charset', $setup->lang('charset'));
		$style = array(
			'th_bg'		=> '#486591',
			'th_text'	=> '#FFFFFF',
			'row_on'	=> '#DDDDDD',
			'row_off'	=> '#EEEEEE',
			'banner_bg'	=> '#4865F1',
			'msg'		=> '#FF0000',
		);
		$this->setup_tpl->set_var($style);
		if ($nologoutbutton)
		{
			$btn_logout = '&nbsp;';
		}
		else
		{
			//detect the schript path
			$script_path = Sanitizer::get_var('REDIRECT_URL', 'string', 'SERVER');
			//detect if we are in the setup
			if ($script_path && preg_match('/setup\//', $script_path))
			{
				$prefix = '../';
			}
			else
			{
				$prefix = '';
			}

			$btn_logout = '<a href="' . $prefix . 'setup/logout?FormLogout=' . $logoutfrom . '" class="link">' . $setup->lang('Logout') . '</a>';
		}

		$this->setup_tpl->set_var('lang_version', $setup->lang('version'));
		$this->setup_tpl->set_var('lang_setup', $setup->lang('setup'));
		$this->setup_tpl->set_var('page_title', $title);
		if ($configdomain == '')
		{
			$this->setup_tpl->set_var('configdomain', '');
		}
		else
		{
			$this->setup_tpl->set_var('configdomain', ' - ' . $setup->lang('Domain') . ': ' . $configdomain);
		}

		$api_version = isset($serverSettings['versions']['phpgwapi']) ? $serverSettings['versions']['phpgwapi'] : '';

		$version = isset($serverSettings['versions']['system']) ? $serverSettings['versions']['system'] : $api_version;

		$this->setup_tpl->set_var('pgw_ver', $version);
		$this->setup_tpl->set_var('logoutbutton', $btn_logout);
		return $this->setup_tpl->fp('out', 'T_head');
		/* $setup_tpl->set_var('T_head',''); */
	}

	function get_footer()
	{
		$footer = $this->setup_tpl->fp('out', 'T_footer');
		return $footer;
	}
	function show_footer()
	{
		print $this->get_footer();
	}

	function show_alert_msg($alert_word = 'Setup alert', $alert_msg = 'setup alert (generic)')
	{
		$this->setup_tpl->set_var('V_alert_word', $alert_word);
		$this->setup_tpl->set_var('V_alert_msg', $alert_msg);
		$this->setup_tpl->pparse('out', 'T_alert_msg');
	}

	function make_frm_btn_simple($pre_frm_blurb = '', $frm_method = 'POST', $frm_action = '', $input_type = 'submit', $input_value = '', $post_frm_blurb = '')
	{
		/* a simple form has simple components */
		$simple_form = $pre_frm_blurb  . "\n"
			. '<form method="' . $frm_method . '" action="' . $frm_action  . '">' . "\n"
			. '<input type="'  . $input_type . '" value="'  . $input_value . '">' . "\n"
			. '</form>' . "\n"
			. $post_frm_blurb . "\n";
		return $simple_form;
	}

	function make_href_link_simple($pre_link_blurb = '', $href_link = '', $href_text = 'default text', $post_link_blurb = '')
	{
		/* a simple href link has simple components */
		$simple_link = $pre_link_blurb
			. '<a href="' . $href_link . '">' . $href_text . '</a> '
			. $post_link_blurb . "\n";
		return $simple_link;
	}

	function login_form()
	{
		$setup_data = Settings::getInstance()->get('setup');

		/* begin use TEMPLATE login_main.tpl */

		$this->setup_tpl->set_var(
			'ConfigLoginMSG',
			isset($setup_data['ConfigLoginMSG'])
				? $setup_data['ConfigLoginMSG'] : '&nbsp;'
		);

		$this->setup_tpl->set_var(
			'HeaderLoginMSG',
			isset($setup_data['HeaderLoginMSG'])
				? $setup_data['HeaderLoginMSG'] : '&nbsp;'
		);

		if ($setup_data['stage']['header'] == '10')
		{
			/*
				 Begin use SUB-TEMPLATE login_stage_header,
				 fills V_login_stage_header used inside of login_main.tpl
				*/
			$this->setup_tpl->set_var('lang_select', $this->lang_select());

			$settings = require SRC_ROOT_PATH . '/../config/header.inc.php';
			$phpgw_domain = $settings['phpgw_domain'];

			if (count($phpgw_domain) > 1)
			{
				$domains = '';
				foreach ($phpgw_domain as $domain => $data)
				{
					$domains .= "<option value=\"$domain\" ";
					if (isset($setup_data['LastDomain']) && $domain == $setup_data['LastDomain'])
					{
						$domains .= ' SELECTED';
					}
					elseif ($domain == $_SERVER['SERVER_NAME'])
					{
						$domains .= ' SELECTED';
					}
					$domains .= ">$domain</option>\n";
				}
				$this->setup_tpl->set_var('domains', $domains);

				// use BLOCK B_multi_domain inside of login_stage_header
				$this->setup_tpl->parse('V_multi_domain', 'B_multi_domain');
				// in this case, the single domain block needs to be nothing
				$this->setup_tpl->set_var('V_single_domain', '');
			}
			else
			{
				reset($phpgw_domain);
				//$default_domain = each($phpgw_domain);
				$default_domain = key($phpgw_domain);
				$this->setup_tpl->set_var('default_domain_zero', $default_domain);

				/* Use BLOCK B_single_domain inside of login_stage_header */
				$this->setup_tpl->parse('V_single_domain', 'B_single_domain');
				/* in this case, the multi domain block needs to be nothing */
				$this->setup_tpl->set_var('V_multi_domain', '');
			}
			/*
				 End use SUB-TEMPLATE login_stage_header
				 put all this into V_login_stage_header for use inside login_main
				*/
			$this->setup_tpl->parse('V_login_stage_header', 'T_login_stage_header');
		}
		else
		{
			/* begin SKIP SUB-TEMPLATE login_stage_header */
			$this->setup_tpl->set_var('V_multi_domain', '');
			$this->setup_tpl->set_var('V_single_domain', '');
			$this->setup_tpl->set_var('V_login_stage_header', '');
		}
		/*
			 end use TEMPLATE login_main.tpl
			 now out the login_main template
			*/
		return $this->setup_tpl->fp('out', 'T_login_main');
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
		//while(list($null,$data) = each($languages))
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

		//		print_r($languages);
		return $languages;
	}
}
