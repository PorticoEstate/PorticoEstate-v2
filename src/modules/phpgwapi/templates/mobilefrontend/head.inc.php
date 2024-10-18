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

$template->set_root(PHPGW_TEMPLATE_DIR);
$template->set_unknowns('remove');
$template->set_file('head', 'head.tpl');
$template->set_block('head', 'stylesheet', 'stylesheets');
$template->set_block('head', 'javascript', 'javascripts');

$serverSettings['no_jscombine'] = false;

$javascripts = array();
$stylesheets = array();

phpgw::import_class('phpgwapi.jquery');
phpgwapi_jquery::load_widget('core');
phpgwapi_jquery::load_widget('ui');

$javascripts[]	 = "/phpgwapi/js/popper/popper2.min.js";
$javascripts[]	 = "/phpgwapi/js/bootstrap5/vendor/twbs/bootstrap/dist/js/bootstrap.min.js";
$javascripts[] = "/phpgwapi/templates/mobilefrontend/js/keep_alive.js";

$stylesheets[] = "/phpgwapi/templates/pure/css/global.css";
$stylesheets[] = "/phpgwapi/templates/pure/css/version_3/pure-min.css";
$stylesheets[] = "/phpgwapi/templates/pure/css/pure-extension.css";
$stylesheets[] = "/phpgwapi/templates/pure/css/version_3/grids-responsive-min.css";
$stylesheets[] = "/phpgwapi/js/DataTables2/datatables.min.css";

$stylesheets[]	 = "/phpgwapi/js/bootstrap5/vendor/twbs/bootstrap/dist/css/bootstrap.min.css";

//	$stylesheets[] = "/{$app}/templates/base/css/base.css";
$stylesheets[] = "/{$app}/templates/mobilefrontend/css/base.css";
//	$stylesheets[] = "/{$app}/templates/mobilefrontend/css/{$userSettings['preferences']['common']['theme']}.css";
$stylesheets[] = "/phpgwapi/templates/mobilefrontend/css/base.css";
//	$stylesheets[] = "/phpgwapi/templates/bookingfrontend/css/fontawesome.all.css";
$stylesheets[] = "/phpgwapi/templates/base/css/fontawesome/css/all.min.css";

foreach ($stylesheets as $stylesheet)
{
	if (file_exists(PHPGW_SERVER_ROOT . $stylesheet))
	{
		$template->set_var('stylesheet_uri', $webserver_url . $stylesheet . $cache_refresh_token);
		$template->parse('stylesheets', 'stylesheet', true);
	}
}

if (!$serverSettings['no_jscombine'])
{
	$_jsfiles = array();
	foreach ($javascripts as $javascript)
	{
		if (file_exists(PHPGW_SERVER_ROOT . $javascript))
		{
			// Add file path to array and replace path separator with "--" for URL-friendlyness
			$_jsfiles[] = str_replace('/', '--', ltrim($javascript, '/'));
		}
	}

	$cachedir	 = urlencode("{$serverSettings['temp_dir']}/combine_cache");
	$jsfiles	 = implode(',', $_jsfiles);
	$template->set_var('javascript_uri', "{$webserver_url}/phpgwapi/inc/combine.php?cachedir={$cachedir}&type=javascript&files={$jsfiles}");
	$template->parse('javascripts', 'javascript', true);
	unset($jsfiles);
	unset($_jsfiles);
}
else
{
	foreach ($javascripts as $javascript)
	{
		if (file_exists(PHPGW_SERVER_ROOT . $javascript))
		{
			$template->set_var('javascript_uri', $webserver_url . $javascript . $cache_refresh_token);
			$template->parse('javascripts', 'javascript', true);
		}
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

$_navbar_config			= json_encode($navbar_config);

$app = lang($app);
$phpgwapi_common = new phpgwapi_common();

$tpl_vars = array(
	'noheader'		=> isset($flags['noheader_xsl']) && $flags['noheader_xsl'] ? 'true' : 'false',
	'nofooter'		=> isset($flags['nofooter']) && $flags['nofooter'] ? 'true' : 'false',
	'css'			=> $phpgwapi_common->get_css($cache_refresh_token),
	'javascript'	=> $phpgwapi_common->get_javascript($cache_refresh_token),
	'img_icon'      => $phpgwapi_common->find_image('phpgwapi', 'favicon.ico'),
	'site_title'	=> "{$serverSettings['site_title']}",
	'site_url'		=> phpgw::link('/home/', array()),
	'str_base_url'		 => phpgw::link('/', array(), true, false, false),
	'userlang'		=> $userSettings['preferences']['common']['lang'],
	'webserver_url'	=> $webserver_url,
	'win_on_events'	=> $phpgwapi_common->get_on_events(),
	'navbar_config' => $_navbar_config,
);

$template->set_var($tpl_vars);

$template->pfp('out', 'head');
unset($tpl_vars);
