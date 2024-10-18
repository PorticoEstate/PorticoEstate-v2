<?php

/**
 * Template header
 * @copyright Copyright (C) 2003-2005 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @package phpgwapi
 * @subpackage gui
 * @version $Id$
 */

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\Translation;

$serverSettings = Settings::getInstance()->get('server');
$flags = Settings::getInstance()->get('flags');
$apps = Settings::getInstance()->get('apps');
$userSettings = Settings::getInstance()->get('user');
$phpgwapi_common = new phpgwapi_common();
$translation = Translation::getInstance();

$webserver_url = isset($serverSettings['webserver_url']) ? $serverSettings['webserver_url'] . PHPGW_MODULES_PATH : PHPGW_MODULES_PATH;

if (!isset($serverSettings['site_title']))
{
	$serverSettings['site_title'] = lang('please set a site name in admin &gt; siteconfig');
}

// we hack the template root here as this is the template set of last resort
$tpl = CreateObject('phpgwapi.template', dirname(__FILE__), "remove");
$tpl->set_file(array('head' => 'head.tpl'));
$tpl->set_block('head', 'theme_stylesheet', 'theme_stylesheets');

$app = $flags['currentapp'];

$stylesheets = array();
$stylesheets[] = "/phpgwapi/templates/pure/css/global.css";
$stylesheets[] = "/phpgwapi/templates/pure/css/version_3/pure-min.css";
$stylesheets[] = "/phpgwapi/templates/pure/css/pure-extension.css";
$stylesheets[] = "/phpgwapi/templates/pure/css/version_3/grids-responsive-min.css";
$stylesheets[] = "/phpgwapi/js/DataTables2/datatables.min.css";
if (!isset($flags['noframework']))
{

	$javascripts = array(
		"/phpgwapi/templates/portico/js/base.js"
	);

	$stylesheets[] = "/phpgwapi/templates/simple/css/base.css";
}

if (file_exists(PHPGW_SERVER_ROOT . '/phpgwapi/templates/simple/css/' . $userSettings['preferences']['common']['theme'] . '.css'))
{
	$stylesheets[] = "/phpgwapi/templates/simple/css/{$userSettings['preferences']['common']['theme']}.css";
}
else
{
	$stylesheets[] = "/phpgwapi/templates/simple/css/simple.css";
	$userSettings['preferences']['common']['theme'] = 'simple';
}

if (file_exists(PHPGW_SERVER_ROOT . "/{$app}/templates/base/css/base.css"))
{
	$stylesheets[] = "/{$app}/templates/base/css/base.css";
}

if (file_exists(PHPGW_SERVER_ROOT . "/{$app}/templates/simple/css/base.css"))
{
	$stylesheets[] = "/{$app}/templates/simple/css/base.css";
}

if (file_exists(PHPGW_SERVER_ROOT . "/{$app}/templates/simple/css/{$userSettings['preferences']['common']['theme']}.css"))
{
	$stylesheets[] = "/{$app}/templates/simple/css/{$userSettings['preferences']['common']['theme']}.css";
}

foreach ($stylesheets as $style)
{
	$tpl->set_var('theme_style', $webserver_url . $style);
	$tpl->parse('theme_stylesheets', 'theme_stylesheet', true);
}

$app = $app ? ' [' . (isset($apps[$app]) ? $apps[$app]['title'] : lang($app)) . ']' : '';

$tpl->set_var(array(
	'css'			=> $phpgwapi_common->get_css(),
	'javascript'	=> $phpgwapi_common->get_javascript(),
	'img_icon'      => PHPGW_IMAGES_DIR . '/favicon.ico',
	'img_shortcut'  => PHPGW_IMAGES_DIR . '/favicon.ico',
	'str_base_url'	=> phpgw::link('/', array(), true, false, true),
	'userlang'		=> $userSettings['preferences']['common']['lang'],
	'website_title'	=> $serverSettings['site_title'] . $app,
	'win_on_events'	=> $phpgwapi_common->get_on_events(),
));

$tpl->pfp('out', 'head');
unset($tpl);
