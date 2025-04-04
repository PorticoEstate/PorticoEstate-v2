<?php

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\Cache;
use App\helpers\Template;
use App\modules\phpgwapi\controllers\Accounts\Accounts;

Settings::getInstance()->update('server', ['no_jscombine' => true]);
$serverSettings = Settings::getInstance()->get('server');
$flags = Settings::getInstance()->get('flags');
$userSettings = Settings::getInstance()->get('user');
$phpgwapi_common = new \phpgwapi_common();
$accounts_obj = new Accounts();


phpgw::import_class('phpgwapi.jquery');
phpgw::import_class('phpgwapi.template_portico');

$webserver_url = isset($serverSettings['webserver_url']) ? $serverSettings['webserver_url'] . PHPGW_MODULES_PATH : PHPGW_MODULES_PATH;

if (!isset($serverSettings['site_title']))
{
	$serverSettings['site_title'] = lang('please set a site name in admin &gt; siteconfig');
}

$app = $flags['currentapp'];

$config_frontend	= CreateObject('phpgwapi.config', $app)->read();

$tracker_id = !empty($config_frontend['tracker_id']) ? $config_frontend['tracker_id'] : '';
$tracker_code1 = <<<JS
		var gaJsHost = (("https:" == document.location.protocol) ? "https://ssl." : "http://www.");
		document.write(unescape("%3Cscript src='" + gaJsHost + "google-analytics.com/ga.js' type='text/javascript'%3E%3C/script%3E"));
JS;
$tracker_code2 = <<<JS
		try 
		{
			var pageTracker = _gat._getTracker("{$tracker_id}");
			pageTracker._trackPageview();
		}
		catch(err)
		{
//			alert(err);
		}
JS;

if ($tracker_id)
{
	phpgwapi_js::getInstance()->add_code('', $tracker_code1);
	phpgwapi_js::getInstance()->add_code('', $tracker_code2);
}

$template = new Template(PHPGW_TEMPLATE_DIR);

$template->set_unknowns('remove');
$template->set_file('head', 'head.tpl');
$template->set_block('head', 'stylesheet', 'stylesheets');
$template->set_block('head', 'javascript', 'javascripts');


phpgwapi_jquery::load_widget('core');


$old_ie = false;
if (preg_match('/MSIE (6|7|8)/i', $_SERVER['HTTP_USER_AGENT']))
{
	$old_ie = true;
	$message = lang('outdated browser: %1', $_SERVER['HTTP_USER_AGENT']);
	Cache::message_set($message, 'error');
}

$stylesheets = array();
//	$stylesheets[] = "/phpgwapi/templates/pure/css/pure-min.css";
//	$stylesheets[] = "/phpgwapi/templates/pure/css/pure-extension.css";
//	if ($old_ie)
//	{
//		$stylesheets[] = "/phpgwapi/templates/pure/css/grids-responsive-old-ie-min.css";
//
//	}
//	else
//	{
//		$stylesheets[] = "/phpgwapi/templates/pure/css/grids-responsive-min.css";
//	}

$stylesheets[] = "/phpgwapi/templates/pure/css/version_3/pure-min.css";
$stylesheets[] = "/phpgwapi/templates/pure/css/pure-extension.css";
$stylesheets[] = "/phpgwapi/templates/pure/css/version_3/grids-responsive-min.css";
$stylesheets[] = "/phpgwapi/js/DataTables2/datatables.min.css";


$stylesheets[] = "/{$app}/templates/base/css/base.css";
//	$stylesheets[] = "/{$app}/css/frontend.css";
$stylesheets[] = "/phpgwapi/templates/frontend/css/frontend.css";
$stylesheets[] = "/phpgwapi/js/jquery/mmenu/core/css/jquery.mmenu.all.css";

if (!preg_match('/Firefox/i', $_SERVER['HTTP_USER_AGENT']))
{
	$stylesheets[] = "/phpgwapi/templates/frontend/css/ie.css";
}

if (isset($userSettings['preferences']['common']['theme']))
{
	$stylesheets[] = "/phpgwapi/templates/frontend/themes/{$userSettings['preferences']['common']['theme']}.css";
	$stylesheets[] = "/{$app}/templates/frontend/themes/{$userSettings['preferences']['common']['theme']}.css";
}



foreach ($stylesheets as $stylesheet)
{
	if (file_exists(PHPGW_SERVER_ROOT . $stylesheet))
	{
		$template->set_var('stylesheet_uri', $webserver_url . $stylesheet);
		$template->parse('stylesheets', 'stylesheet', true);
	}
}

$javascripts = array();
$javascripts[] = "/phpgwapi/js/jquery/mmenu/core/js/jquery.mmenu.min.all.js";
$javascripts[] = "/phpgwapi/templates/frontend/js/mmenu.js";


//FIXME: To consider...
/*
	$javascripts[] = "/phpgwapi/templates/stavanger/js/minid.js";
*/
//	$javascripts[] = "/phpgwapi/templates/bookingfrontend/js/headroom.min.js";
//	$javascripts[] = "/phpgwapi/templates/bookingfrontend/js/jQuery.headroom.js";

foreach ($javascripts as $javascript)
{
	if (file_exists(PHPGW_SERVER_ROOT . $javascript))
	{
		$template->set_var('javascript_uri', $webserver_url . $javascript);
		$template->parse('javascripts', 'javascript', true);
	}
}

$config	= CreateObject('phpgwapi.config', $app)->read();
$logofile_frontend = !empty($config['logopath_frontend']) ? $config['logopath_frontend'] : '';
$keywords = !empty($config['metatag_keywords']) ? $config['metatag_keywords'] : '';
$description = !empty($config['metatag_description']) ? $config['metatag_description'] : '';

if ($keywords)
{
	$keywords = '<meta name="keywords" content="' . $keywords . '">';
}
else
{
	$keywords = '<meta name="keywords" content="phpGroupWare">';
}
if ($description)
{
	$description = '<meta name="description" content="' . $description . '">';
}
else
{
	$description = '<meta name="description" content="phpGroupWare">';
}
if (!empty($config['metatag_author']))
{
	$author = '<meta name="author" content="' . $config['metatag_author'] . '">';
}
else
{
	$author = '<meta name="author" content="phpGroupWare http://www.phpgroupware.org">';
}
if (!empty($config['metatag_robots']))
{
	$robots = '<meta name="robots" content="' . $config['metatag_robots'] . '">';
}
else
{
	$robots = '<meta name="robots" content="none">';
}
if (!empty($config_frontend['site_title']))
{
	$site_title = $config_frontend['site_title'];
}
else
{
	$site_title = $serverSettings['site_title'];
}

if (! $footer_info = $config_frontend['footer_info'])
{
	$footer_info = "footer info settes i {$app} config";
}

Cache::session_set('phpgwapi', 'footer_info', $footer_info);

$test = $phpgwapi_common->get_on_events();
$test = str_replace('window.onload = function()', '$(document).ready(function()', $test);
$test = str_replace("\n}\n", "\n})\n", $test);
$site_url	= phpgw::link("/{$app}/", array());
$home_text		= lang('home');
$manual_text = lang('manual');

$script_path = Sanitizer::get_var('REDIRECT_URL', 'string', 'SERVER');

if ($script_path && preg_match('/\/home/', $script_path))
{
	$home_text	= '';
}

$user = $accounts_obj->get($userSettings['id']);

if ($user && isset($_SESSION['phpgw_session']['session_flags']) && $_SESSION['phpgw_session']['session_flags'] == 'N')
{
	$login_text = $user->__toString() . ' :: ' . lang('Logout');
	$login_url = "/{$app}/logout";
}
else
{
	$login_text_org = '';
	$login_text = lang('Login');
	$login_url = "/{$app}/logout?login=1&after=" . $_GET['menuaction'];
	$login_parameter = !empty($config_frontend['login_parameter']) ? $config_frontend['login_parameter'] : '';
	$custom_login_url = !empty($config_frontend['custom_login_url']) ? $config_frontend['custom_login_url'] : '';
	if ($login_parameter)
	{
		$login_parameter = ltrim($login_parameter, '&');
		$login_url .= "&{$login_parameter}";
	}
	if ($custom_login_url)
	{
		$login_url = $custom_login_url;
	}
}


$header = <<<HTML
		<div class="home-menu custom-menu-wrapper">
			<div class="home-menu pure-menu pure-menu-horizontal pure-menu-fixed">
				<a href="{$site_url}" class="pure-menu-heading">{$site_title}</a>
				<ul class="pure-menu-list">
					<li class="pure-menu-item pure-menu-selected"><a href="{$site_url}" class="pure-menu-link">{$home_text}</a></li>
HTML;
if (!empty($manual))
{
	$header .= <<<HTML
					<li class="pure-menu-item pure-menu-selected"><a href="{$manual}" class="pure-menu-link">{$manual_text}</a></li>
HTML;
}
$header .= <<<HTML
					<li class="pure-menu-item pure-menu-selected"><a href="{$login_url}" class="pure-menu-link">{$login_text}</a></li>
				</ul>
			</div>
		</div>
HTML;

if (!empty($flags['noframework']))
{
	$header = '';
}

$tpl_vars = array(
	'header'		=> $header,
	'login_text_org' =>	$login_text_org,
	'login_text'	=> $login_text,
	'login_url'		=> $login_url,
	'css'			=> $phpgwapi_common->get_css(),
	'javascript'	=> $phpgwapi_common->get_javascript(),
	'img_icon'      => $phpgwapi_common->find_image('phpgwapi', 'favicon.ico'),
	'site_title'	=> $site_title,
	'home_text'		=> $home_text,
	'str_base_url'	 => phpgw::link('/', array(), true, false, true),
	'site_url'	=> $site_url,
	'userlang'				 => $userSettings['preferences']['common']['lang'],
	'webserver_url'	=> $webserver_url,
	'win_on_events'	=> $test,
	'metainfo_author' => $author,
	'metainfo_keywords' => $keywords,
	'metainfo_description' => $description,
	'metainfo_robots' => $robots,
	'lbl_search'   	=> lang('Search'),
	'logofile'		=> $logofile_frontend,
	'header_search_class'	=> 'hidden' //(isset($_GET['menuaction']) && $_GET['menuaction'] == 'bookingfrontend.uisearch.index' ? 'hidden' : '')
);


if ($user && isset($_SESSION['phpgw_session']['session_flags']) && $_SESSION['phpgw_session']['session_flags'] == 'N')
{
	$tpl_vars['login_text'] = $user->__toString() . ' :: ' . lang('Logout');
	$tpl_vars['login_url'] = 'logout.php';
}
else
{
	$tpl_vars['login_text_org'] = '';
	$tpl_vars['login_text'] = lang('Login');
	$tpl_vars['login_url'] = 'logout.php?login=1&after=' . $_GET['menuaction'];
	$login_parameter = !empty($config_frontend['login_parameter']) ? $config_frontend['login_parameter'] : '';
	$custom_login_url = !empty($config_frontend['custom_login_url']) ? $config_frontend['custom_login_url'] : '';
	if ($login_parameter)
	{
		$login_parameter = ltrim($login_parameter, '&');
		$tpl_vars['login_url'] .= "&{$login_parameter}";
	}
	if ($custom_login_url)
	{
		$tpl_vars['login_url'] = $custom_login_url;
	}
}
$template->set_var($tpl_vars);

$template->pfp('out', 'head');
unset($tpl_vars);
