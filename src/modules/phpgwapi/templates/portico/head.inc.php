<?php

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\Cache;
use App\helpers\Template;

phpgw::import_class('phpgwapi.template_portico');

$serverSettings = Settings::getInstance()->get('server');
$flags = Settings::getInstance()->get('flags');
$userSettings = Settings::getInstance()->get('user');

if (!isset($serverSettings['site_title']))
{
	$serverSettings['site_title'] = lang('please set a site name in admin &gt; siteconfig');
}

$webserver_url = isset($serverSettings['webserver_url']) ? $serverSettings['webserver_url'] . PHPGW_MODULES_PATH : PHPGW_MODULES_PATH;

$app = $flags['currentapp'];

$cache_refresh_token = '';
if (!empty($serverSettings['cache_refresh_token']))
{
	$cache_refresh_token = "?n={$serverSettings['cache_refresh_token']}";
}

$template = new Template(PHPGW_TEMPLATE_DIR);
$template->set_unknowns('remove');
$template->set_file('head', 'head.tpl');
$template->set_block('head', 'stylesheet', 'stylesheets');
$template->set_block('head', 'javascript', 'javascripts');

$serverSettings['no_jscombine'] = false;

$javascripts = array();

$stylesheets = array();

phpgw::import_class('phpgwapi.jquery');
phpgwapi_jquery::load_widget('core');

if (!isset($flags['noframework']))
{
	//		$javascripts[] = "/phpgwapi/templates/portico/js/base.js";
	//https://medium.com/@fbnlsr/how-to-get-rid-of-the-flash-of-unstyled-content-d6b79bf5d75f
	phpgwapi_js::getInstance()->add_external_file("/phpgwapi/templates/portico/js/base.js", $end_of_page = true, array('combine' => true));
}

if (!$flags['noframework'] && !$flags['nonavbar'])
{
	phpgwapi_jquery::load_widget('layout');
	phpgwapi_jquery::load_widget('jqtree');
	phpgwapi_jquery::load_widget('contextMenu');

	$userSettings['preferences']['common']['sidecontent'] = 'ajax_menu'; //ajax_menu|jsmenu
	if (isset($userSettings['preferences']['common']['sidecontent']) && $userSettings['preferences']['common']['sidecontent'] == 'ajax_menu')
	{
		$javascripts[] = "/phpgwapi/templates/portico/js/jqtree_jsmenu.js";
	}
}

$javascripts[] = "/phpgwapi/templates/portico/js/keep_alive.js";

$stylesheets = array();
$stylesheets[] = "/phpgwapi/templates/pure/css/global.css";
$stylesheets[] = "/phpgwapi/templates/pure/css/version_3/pure-min.css";
$stylesheets[] = "/phpgwapi/templates/pure/css/pure-extension.css";
$stylesheets[] = "/phpgwapi/templates/pure/css/version_3/grids-responsive-min.css";

$stylesheets[] = "/phpgwapi/js/DataTables2/datatables.min.css";
$stylesheets[] = "/phpgwapi/templates/base/css/fontawesome/css/all.min.css";

$stylesheets[] = "/phpgwapi/templates/base/css/base.css";
$stylesheets[] = "/phpgwapi/templates/portico/css/base.css";


if (isset($userSettings['preferences']['common']['theme']))
{
	$stylesheets[] = "/phpgwapi/templates/portico/css/{$userSettings['preferences']['common']['theme']}.css";
}
$stylesheets[] = "/{$app}/templates/base/css/base.css";
$stylesheets[] = "/{$app}/templates/portico/css/base.css";
if (isset($userSettings['preferences']['common']['theme']))
{
	$stylesheets[] = "/{$app}/templates/portico/css/{$userSettings['preferences']['common']['theme']}.css";
}

//	if(isset($userSettings['preferences']['common']['yui_table_nowrap']) && $userSettings['preferences']['common']['yui_table_nowrap'])
//	{
//		$stylesheets[] = "/phpgwapi/templates/base/css/yui_table_nowrap.css";
//	}

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

// Construct navbar_config by taking into account the current selected menu
// The only problem with this loop is that leafnodes will be included
$navbar_config = execMethod('phpgwapi.template_portico.retrieve_local', 'navbar_config');

if (isset($flags['menu_selection']))
{
	if (!isset($navbar_config))
	{
		$navbar_config = array();
	}

	$current_selection = $flags['menu_selection'];

	while ($current_selection)
	{
		$navbar_config["navbar::$current_selection"] = true;
		$current_selection = implode("::", explode("::", $current_selection, -1));
	}

	phpgwapi_template_portico::store_local('navbar_config', $navbar_config);
}

$_border_layout_config	= execMethod('phpgwapi.template_portico.retrieve_local', 'border_layout_config');

if (isset($flags['nonavbar']) && $flags['nonavbar'])
{
	//FIXME This one removes the sidepanels - but the previous settings are forgotten
	$_border_layout_config = true;
}

$_border_layout_config = json_encode($_border_layout_config);

$_navbar_config			= json_encode($navbar_config);

if (Sanitizer::get_var('phpgw_return_as') == 'json')
{
	$menu_selection = Cache::session_get('navbar', 'menu_selection');
}
else
{
	$menu_selection = $flags['menu_selection'];
}
$phpgwapi_common = new phpgwapi_common();

$tpl_vars = array(
	'noheader'		=> isset($flags['noheader_xsl']) && $flags['noheader_xsl'] ? 'true' : 'false',
	'nofooter'		=> isset($flags['nofooter']) && $flags['nofooter'] ? 'true' : 'false',
	'css'			=> $phpgwapi_common->get_css($cache_refresh_token),
	'javascript'	=> $phpgwapi_common->get_javascript($cache_refresh_token),
	'img_icon'  => $phpgwapi_common->find_image('phpgwapi', 'favicon.ico'),
	'site_title'	=> "{$serverSettings['site_title']}",
	'str_base_url'			 => phpgw::link('/', array(), true, false, true),
	'webserver_url'	=> $webserver_url,
	'userlang'		=> $userSettings['preferences']['common']['lang'],
	'win_on_events'	=> $phpgwapi_common->get_on_events(),
	'border_layout_config' => $_border_layout_config,
	'navbar_config' => $_navbar_config,
	'menu_selection'	 => "navbar::{$menu_selection}",
	'sessionid'			 => $userSettings['sessionid']
);

$template->set_var($tpl_vars);

$template->pfp('out', 'head');
unset($tpl_vars);

flush();


if (isset($flags['noframework']))
{
	echo '<body>';
	register_shutdown_function('parse_footer_end_noframe');
}

function parse_footer_end_noframe()
{
	$phpgwapi_common = new phpgwapi_common();
	$serverSettings = Settings::getInstance()->get('server');

	$cache_refresh_token = '';
	if (!empty($serverSettings['cache_refresh_token']))
	{
		$cache_refresh_token = "?n={$serverSettings['cache_refresh_token']}";
	}
	$javascript_end = $phpgwapi_common->get_javascript_end($cache_refresh_token);

	$footer = <<<HTML
		</body>
		{$javascript_end}
	</html>
HTML;
	echo $footer;
}
