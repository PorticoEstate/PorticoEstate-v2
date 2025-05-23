<?php
/**
 * Preferences - settings hook
 *
 * @copyright Copyright (C) 2000-2005 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @package preferences
 * @version $Id$
 */

use App\modules\preferences\helpers\PreferenceHelper;
use App\modules\phpgwapi\services\Preferences as Prefs;
use App\modules\phpgwapi\services\Settings;

$userSettings = Settings::getInstance()->get('user');

phpgw::import_class('phpgwapi.country');
//phpgw::import_class('phpgwapi.common');

$preferenceHelper = PreferenceHelper::getInstance();

$_templates = array();
foreach (phpgwapi_common::list_templates() as $key => $value)
{
	$_templates[$key] = $value['title'];
}

$template_set = '';

$account_id = Sanitizer::get_var('account_id', 'int');

if ($account_id)
{
	$orig_account_id = Settings::getInstance()->get('user')['account_id'];
	$prefs = Prefs::getInstance();
	$prefs->setAccountId($account_id);
	$user_pref = $prefs->get('preferences');
	$template_set = $user_pref['common']['template_set'];
	$prefs->setAccountId($orig_account_id);
}

$_themes = array();
foreach (phpgwapi_common::list_themes($template_set) as $theme)
{
	$_themes[$theme] = $theme;
}


$preferenceHelper->create_input_box(
	'Max matches per page',
	'maxmatchs',
	'Any listing in phpGW will show you this number of entries or lines per page.<br>To many slow down the page display, to less will cost you the overview.',
	'',
	3
);
$preferenceHelper->create_select_box(
	'Interface/Template Selection',
	'template_set',
	$_templates,
	'A template defines the layout of phpGroupWare and it contains icons for each application.'
);
$preferenceHelper->create_select_box(
	'Theme (colors/fonts) Selection',
	'theme',
	$_themes,
	'A theme defines the colors and fonts used by the template.'
);


/*
	$format = $userSettings['preferences']['common']['dateformat'];
	$format = ($format ? $format : 'Y/m/d') . ', ';
	if ($userSettings['preferences']['common']['timeformat'] == '12')
	{
		$format .= 'h:i a';
	}
	else
	{
		$format .= 'H:i';
	}
	for ($i = -23; $i<24; $i++)
	{
		$t = time() + $i * 60*60;
		$tz_offset[$i] = $i . ' ' . lang('hours').': ' . date($format,$t);
	}
	create_select_box('Time zone offset','tz_offset',$tz_offset,
		'How many hours are you in front or after the timezone of the server.<br>If you are in the same time zone as the server select 0 hours, else select your locale date and time.');

*/
$timezone_identifiers = DateTimeZone::listIdentifiers();

$timezone = array();
foreach ($timezone_identifiers as $identifier)
{
	$timezone[$identifier] = $identifier;
}
$preferenceHelper->create_select_box(
	'Time zone',
	'timezone',
	$timezone,
	'A time zone is a region of the earth that has uniform standard time, usually referred to as the local time. By convention, time zones compute their local time as an offset from UTC'
);

$date_formats = array(
	'm/d/Y' => 'm/d/Y',
	'm-d-Y' => 'm-d-Y',
	'm.d.Y' => 'm.d.Y',
	'Y/d/m' => 'Y/d/m',
	'Y-d-m' => 'Y-d-m',
	'Y.d.m' => 'Y.d.m',
	'Y/m/d' => 'Y/m/d',
	'Y-m-d' => 'Y-m-d',
	'Y.m.d' => 'Y.m.d',
	'd/m-Y' => 'd/m-Y',
	'd/m/Y' => 'd/m/Y',
	'd-m-Y' => 'd-m-Y',
	'd.m.Y' => 'd.m.Y'
);
$preferenceHelper->create_select_box(
	'Date format',
	'dateformat',
	$date_formats,
	'How should phpGroupWare display dates for you.'
);

$time_formats = array(
	'12' => lang('12 hour'),
	'24' => lang('24 hour')
);
$preferenceHelper->create_select_box(
	'Time format',
	'timeformat',
	$time_formats,
	'Do you prefer a 24 hour time format, or a 12 hour one with am/pm attached.'
);

$preferenceHelper->create_select_box(
	'Country',
	'country',
	phpgwapi_country::get_translated_list(),
	'In which country are you. This is used to set certain defaults for you.'
);

$langs = \App\modules\phpgwapi\services\Translation::getInstance()->get_installed_langs();
foreach ($langs as $key => $name)	// if we have a translation use it
{
	$trans = lang($name);
	if ($trans != $name . '*')
	{
		$langs[$key] = $trans;
	}
}
$preferenceHelper->create_select_box(
	'Language',
	'lang',
	$langs,
	'Select the language of texts and messages within phpGroupWare.<br>Some languages may not contain all messages, in that case you will see an english message.'
);

// preference.php handles this function
if ($preferenceHelper->is_admin())
{
	$preferenceHelper->create_check_box(
		'Show number of current users',
		'show_currentusers',
		'Should the number of active sessions be displayed for you all the time.'
	);
}

//reset($userSettings['apps']);
//while (list($app) = each($userSettings['apps']))
if (is_array($userSettings['apps']))
{
	$apps = Settings::getInstance()->get('apps');
	foreach ($userSettings['apps'] as $app => $value)
	{
		if ($apps[$app]['status'] != 2 && $app)
		{
			$user_apps[$app] = $apps[$app]['title'] ? $apps[$app]['title'] : lang($app);
		}
	}
}
$preferenceHelper->create_select_box(
	'Default application',
	'default_app',
	$user_apps,
	"The default application will be started when you enter phpGroupWare or click on the homepage icon.<br>You can also have more than one application showing up on the homepage, if you don't choose a specific application here (has to be configured in the preferences of each application)."
);

$preferenceHelper->create_input_box(
	'Currency',
	'currency',
	'Which currency symbol or name should be used in phpGroupWare.'
);

$account_sels = array(
	'selectbox' => lang('Selectbox'),
	'popup'     => lang('Popup with search')
);
$preferenceHelper->create_select_box(
	'How do you like to select accounts',
	'account_selection',
	$account_sels,
	'The selectbox shows all available users (can be very slow on big installs with many users). The popup can search users by name or group.'
);

$account_display = array(
	'firstname' => lang('Firstname') . ' ' . lang('Lastname'),
	'lastname'  => lang('Lastname') . ', ' . lang('Firstname'),
);
$preferenceHelper->create_select_box(
	'How do you like to display accounts',
	'account_display',
	$account_display,
	'Set this to your convenience. For security reasons, you might not want to show your Loginname in public.'
);


$rteditors = array(
	'none'		=> lang('none'),
	'ckeditor'	=> 'CKEditor',
	'summernote'	=> 'Summernote',
	'quill'	=> 'quill'
);
$preferenceHelper->create_select_box(
	'Rich text (WYSIWYG) editor',
	'rteditor',
	$rteditors,
	'Which editor would you like to use for editing html and other rich content?'
);

$preferenceHelper->create_check_box(
	'CSV download button',
	'csv_download',
	'Do you want av CSV download button for main tables?'
);

$preferenceHelper->create_check_box(
	'Show helpmessages by default',
	'show_help',
	'Should this help messages shown up always, when you enter the preferences or only on request.'
);

$menu_formats = array(
	'sidebox' => lang('Sidebox'),
	'jsmenu' => lang('JS-menu'),
	'ajax_menu' => lang('ajax menu'),
	'no_sidecontent' => lang('No SideContent')
);
$preferenceHelper->create_select_box(
	'SideContent',
	'sidecontent',
	$menu_formats,
	'Do you want your menues as sidecontent'
);
$preferenceHelper->create_check_box(
	'Show breadcrumbs',
	'show_breadcrumbs',
	'Should history navigation urls as breadcrumbs'
);
$preferenceHelper->create_check_box(
	'activate nowrap in YUI-tables',
	'yui_table_nowrap',
	'activate nowrap in YUI-tables'
);

$preferenceHelper->create_select_box('Tabel export format', 'export_format', array(
	'excel' => 'Excel',
	'csv' => 'CSV', 'ods' => 'ODS'
), 'Choose which format to export from the system for tables');

$preferenceHelper->create_input_box('Your Cellphone', 'cellphone');
$preferenceHelper->create_input_box('Your Email', 'email', 'Insert your email address');
$preferenceHelper->create_input_box('Your archive user id', 'archive_user_id', 'Insert your archive user id');
$preferenceHelper->create_input_box('Your job title', 'job_title', 'Insert job title');
