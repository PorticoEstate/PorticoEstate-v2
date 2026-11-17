<?php

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\Cache;
use App\helpers\Template;

$javascripts = array();
$stylesheets = array();

$serverSettings = Settings::getInstance()->get('server');
$flags = Settings::getInstance()->get('flags');
$userSettings = Settings::getInstance()->get('user');


$webserver_url = isset($serverSettings['webserver_url']) ? $serverSettings['webserver_url'] . PHPGW_MODULES_PATH : PHPGW_MODULES_PATH;

$cache_refresh_token = '';
if (!empty($serverSettings['cache_refresh_token']))
{
	$cache_refresh_token = "?n={$serverSettings['cache_refresh_token']}";
}

phpgw::import_class('phpgwapi.jquery');
phpgwapi_jquery::load_widget('core');

$serverSettings['no_jscombine'] = true;
if (!$flags['noframework'] && !$flags['nonavbar'])
{
	$javascripts[] = "/phpgwapi/js/jquery/mmenu/core/js/jquery.mmenu.min.all.js";
	$javascripts[] = "/phpgwapi/templates/pure/js/mmenu.js";

	$stylesheets[] = "/phpgwapi/js/jquery/mmenu/core/css/jquery.mmenu.all.css";

	$menu_stylesheet_widescreen = '';

	/*
		$menu_stylesheet_widescreen = <<<HTML

		<link href="{$webserver_url}/phpgwapi/js/jquery/mmenu/extensions/css/jquery.mmenu.widescreen.css" type="text/css" rel="stylesheet" media="all and (min-width: 1430px)" />
HTML;
*/
}
else
{
	$menu_stylesheet_widescreen = '';
}

if (!isset($serverSettings['site_title']))
{
	$serverSettings['site_title'] = lang('please set a site name in admin &gt; siteconfig');
}

$app = $flags['currentapp'];

$template = new Template(PHPGW_TEMPLATE_DIR);
$template->set_unknowns('remove');
$template->set_file('head', 'head.tpl');
$template->set_block('head', 'stylesheet', 'stylesheets');
$template->set_block('head', 'javascript', 'javascripts');

$stylesheets[] = "/phpgwapi/templates/pure/css/global.css";
$stylesheets[] = "/phpgwapi/templates/pure/css/action-bar.css";
$stylesheets[] = "/phpgwapi/templates/pure/css/demo_mmenu.css";
//	$stylesheets[] = "/phpgwapi/templates/pure/css/pure-min.css";
//	$stylesheets[] = "/phpgwapi/templates/pure/css/pure-extension.css";
//	$stylesheets[] = "/phpgwapi/templates/pure/css/grids-responsive-min.css";
$stylesheets[] = "/phpgwapi/templates/pure/css/version_3/pure-min.css";
$stylesheets[] = "/phpgwapi/templates/pure/css/pure-extension.css";
$stylesheets[] = "/phpgwapi/templates/pure/css/version_3/grids-responsive-min.css";
$stylesheets[] = "/phpgwapi/js/DataTables3/vendor/datatables.net/datatables.net-dt/css/dataTables.dataTables.min.css";
$stylesheets[] = "/phpgwapi/templates/base/css/fontawesome/css/all.min.css";
$stylesheets[] = "/phpgwapi/templates/pure/css/base.css";


if (isset($userSettings['preferences']['common']['theme']))
{
	$stylesheets[] = "/phpgwapi/templates/pure/themes/{$userSettings['preferences']['common']['theme']}.css";
}
$stylesheets[] = "/{$app}/templates/base/css/base.css";
//$stylesheets[] = "/{$app}/templates/portico/css/base.css";
if (isset($userSettings['preferences']['common']['theme']))
{
	$stylesheets[] = "/{$app}/templates/pure/themes/{$userSettings['preferences']['common']['theme']}.css";
}

foreach ($stylesheets as $stylesheet)
{
	if (file_exists(PHPGW_SERVER_ROOT . $stylesheet))
	{
		$template->set_var('stylesheet_uri', $webserver_url . $stylesheet . $cache_refresh_token);
		$template->parse('stylesheets', 'stylesheet', true);
	}
}

foreach ($javascripts as $javascript)
{
	if (file_exists(PHPGW_SERVER_ROOT . $javascript))
	{
		$template->set_var('javascript_uri', $webserver_url . $javascript . $cache_refresh_token);
		$template->parse('javascripts', 'javascript', true);
	}
}

$phpgwapi_common = new phpgwapi_common();

$tpl_vars = array(
	'noheader'		=> isset($flags['noheader_xsl']) && $flags['noheader_xsl'] ? 'true' : 'false',
	'nofooter'		=> isset($flags['nofooter']) && $flags['nofooter'] ? 'true' : 'false',
	'css'			=> $phpgwapi_common->get_css($cache_refresh_token),
	'javascript'	=> $phpgwapi_common->get_javascript($cache_refresh_token),
	'img_icon'      => $phpgwapi_common->find_image('phpgwapi', 'favicon.ico'),
	'site_title'	=> "{$serverSettings['site_title']}",
	'str_base_url'			 => phpgw::link('/', array(), true, false, true),
	'webserver_url'	=> $webserver_url,
	'userlang'		=> $userSettings['preferences']['common']['lang'],
	'win_on_events'	=> $phpgwapi_common->get_on_events(),
	'menu_stylesheet_widescreen' => $menu_stylesheet_widescreen
);

$template->set_var($tpl_vars);

$template->pfp('out', 'head');
unset($tpl_vars);

flush();

echo "\t<body>";

if (isset($flags['noframework']))
{
	//		echo '<div align = "left">';
	register_shutdown_function('parse_footer_end_noframe');
}

function parse_footer_end_noframe()
{
	$serverSettings = Settings::getInstance()->get('server');
	$cache_refresh_token = '';
	if (!empty($serverSettings['cache_refresh_token']))
	{
		$cache_refresh_token = "?n={$serverSettings['cache_refresh_token']}";
	}

	$phpgwapi_common = new phpgwapi_common();

	$javascript_end = $phpgwapi_common->get_javascript_end($cache_refresh_token);
	$footer = <<<HTML
		</body>
		{$javascript_end}
	</html>
HTML;
	echo $footer;
}
