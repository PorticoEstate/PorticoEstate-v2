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
use App\modules\phpgwapi\services\Twig;
use App\helpers\Template;


/**
 * Setup html
 *
 * @package phpgwapi
 * @subpackage application
 */
class Html
{
	protected $crypto;
	protected $twig;

	function __construct($crypto = null)
	{
		$this->crypto = $crypto;
		$this->twig = Twig::getInstance();
	}

	// No set_tpl needed; all rendering is via Twig

	/**
	 * Render the setup header using Twig
	 */
	function get_header($title = '', $nologoutbutton = false, $logoutfrom = 'config', $configdomain = '')
	{
		$setup = new Setup();
		$serverSettings = Settings::getInstance()->get('server');
		$flags = Settings::getInstance()->get('flags');
		$style = [
			'th_bg'     => '#486591',
			'th_text'   => '#FFFFFF',
			'row_on'    => '#DDDDDD',
			'row_off'   => '#EEEEEE',
			'banner_bg' => '#4865F1',
			'msg'       => '#FF0000',
		];
		if ($nologoutbutton)
		{
			$btn_logout = '&nbsp;';
		}
		else
		{
			$script_path = Sanitizer::get_var('REDIRECT_URL', 'string', 'SERVER');
			$prefix = ($script_path && preg_match('/setup\//', $script_path)) ? '../' : '';
			$btn_logout = '<a href="' . $prefix . 'setup/logout?FormLogout=' . $logoutfrom . '" class="link">' . $setup->lang('Logout') . '</a>';
		}
		$api_version = isset($serverSettings['versions']['phpgwapi']) ? $serverSettings['versions']['phpgwapi'] : '';
		$version = isset($serverSettings['versions']['system']) ? $serverSettings['versions']['system'] : $api_version;
		$vars = [
			'lang_charset' => $setup->lang('charset'),
			'lang_version' => $setup->lang('version'),
			'lang_setup' => $setup->lang('setup'),
			'page_title' => $title,
			'configdomain' => $configdomain ? ' - ' . $setup->lang('Domain') . ': ' . $configdomain : '',
			'pgw_ver' => $version,
			'logoutbutton' => $btn_logout,
			'title' => $title,
			'style' => $style,
			'lang_cookies_must_be_enabled' => $setup->lang('Cookies must be enabled'),
		];
		return $this->twig->render('head.html.twig', $vars);
	}

	function show_header($title = '', $nologoutbutton = false, $logoutfrom = 'config', $configdomain = '')
	{
		print $this->get_header($title, $nologoutbutton, $logoutfrom, $configdomain);
	}

	function get_footer()
	{
		return $this->twig->render('footer.html.twig');
	}
	function show_footer()
	{
		print $this->get_footer();
	}

	function show_alert_msg($alert_word = 'Setup alert', $alert_msg = 'setup alert (generic)')
	{
		echo $this->twig->render('msg_alert_msg.html.twig', [
			'alert_word' => $alert_word,
			'alert_msg' => $alert_msg
		]);
	}

	function make_frm_btn_simple($pre_frm_blurb = '', $frm_method = 'POST', $frm_action = '', $input_type = 'submit', $input_value = '', $post_frm_blurb = '')
	{
		$simple_form = $pre_frm_blurb  . "\n"
			. '<form method="' . $frm_method . '" action="' . $frm_action  . '">' . "\n"
			. '<input class="button" type="'  . $input_type . '" value="'  . $input_value . '">' . "\n"
			. '</form>' . "\n"
			. $post_frm_blurb . "\n";
		return $simple_form;
	}

	function make_href_link_simple($pre_link_blurb = '', $href_link = '', $href_text = 'default text', $post_link_blurb = '')
	{
		$simple_link = $pre_link_blurb
			. '<a href="' . $href_link . '">' . $href_text . '</a> '
			. $post_link_blurb . "\n";
		return $simple_link;
	}

	function login_form()
	{
		$setup_data = Settings::getInstance()->get('setup');
		$vars = [
			'ConfigLoginMSG' => $setup_data['ConfigLoginMSG'] ?? '&nbsp;',
			'HeaderLoginMSG' => $setup_data['HeaderLoginMSG'] ?? '&nbsp;',
			'HeaderLoginWarning' => $setup_data['HeaderLoginWarning'] ?? '',
			'domains' => '',
			'lang_select' => $this->lang_select(),
			'title' => 'Login',
		];
		if ($setup_data['stage']['header'] == '10')
		{
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
					elseif ($domain == ($_SERVER['SERVER_NAME'] ?? ''))
					{
						$domains .= ' SELECTED';
					}
					$domains .= ">$domain</option>\n";
				}
				$vars['domains'] = $domains;
			}
			else
			{
				reset($phpgw_domain);
				$default_domain = key($phpgw_domain);
				$vars['default_domain_zero'] = $default_domain;
			}
		}
		return $this->twig->render('login_main.html.twig', $vars);
	}

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
				if ($short == $ConfigLang || (empty($ConfigLang) && $short == substr($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '', 0, 2)))
				{
					$selected = ' selected';
				}
				$select .= '<option value="' . $data['lang'] . '"' . $selected . '>' . $data['descr'] . '</option>' . "\n";
			}
		}
		$select .= '</select>' . "\n";
		return $select;
	}

	function get_langs()
	{
		$languages = [];
		$f = fopen(SRC_ROOT_PATH . '/modules/setup/lang/languages', 'rb');
		while ($line = fgets($f, 200))
		{
			list($x, $y) = explode("\t", $line);
			$languages[$x]['lang']  = trim($x);
			$languages[$x]['descr'] = trim($y);
			$languages[$x]['available'] = false;
		}
		fclose($f);
		$d = dir(SRC_ROOT_PATH . '/modules/setup/lang');
		while ($entry = $d->read())
		{
			if (strpos($entry, 'phpgw_') === 0)
			{
				$z = substr($entry, 6, 2);
				$languages[$z]['available'] = true;
			}
		}
		$d->close();
		return $languages;
	}
}
