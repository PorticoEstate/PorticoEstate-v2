<?php

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\Cache;
use App\helpers\Template;
use App\modules\phpgwapi\services\Translation;
use App\modules\bookingfrontend\helpers\UserHelper;

$serverSettings = Settings::getInstance()->get('server');
$flags = Settings::getInstance()->get('flags');
$userSettings = Settings::getInstance()->get('user');
$phpgwapi_common = new phpgwapi_common();
$translation = Translation::getInstance();



$serverSettings['no_jscombine'] = true;
phpgw::import_class('phpgwapi.jquery');
phpgw::import_class('phpgwapi.template_portico');

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

$config_frontend = CreateObject('phpgwapi.config', 'bookingfrontend')->read();
$config_backend	 = CreateObject('phpgwapi.config', 'booking')->read();

$tracker_id		 = !empty($config_frontend['tracker_id']) ? $config_frontend['tracker_id'] : '';
$tracker_code1	 = <<<JS
		var gaJsHost = (("https:" == document.location.protocol) ? "https://ssl." : "http://www.");
		document.write(unescape("%3Cscript src='" + gaJsHost + "google-analytics.com/ga.js' type='text/javascript'%3E%3C/script%3E"));
JS;
$tracker_code2	 = <<<JS
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

$stylesheets	 = array();
$stylesheets[]	 = "/phpgwapi/js/bootstrap5/vendor/twbs/bootstrap/dist/css/bootstrap.min.css";
$stylesheets[]	 = "/phpgwapi/templates/base/css/fontawesome/css/all.min.css";
$stylesheets[]	 = "/phpgwapi/templates/base/css/flag-icons.min.css";
$stylesheets[]	 = "/phpgwapi/templates/bookingfrontend/css/jquery.autocompleter.css";
$stylesheets[]	 = "/phpgwapi/templates/bookingfrontend/css/normalize.css";
$stylesheets[]	 = "/phpgwapi/templates/bookingfrontend/css/rubik-font.css";
$stylesheets[]	 = "/phpgwapi/js/select2/css/select2.min.css";
$stylesheets[]	 = "/phpgwapi/js/jquery/css/redmond/jquery-ui.min.css";
$stylesheets[]	 = "/phpgwapi/js/pecalendar/pecalendar.css";

foreach ($stylesheets as $stylesheet)
{
	if (file_exists(PHPGW_SERVER_ROOT . $stylesheet))
	{
		$template->set_var('stylesheet_uri', $webserver_url . $stylesheet . $cache_refresh_token);
		$template->parse('stylesheets', 'stylesheet', true);
	}
}

if (!empty($serverSettings['site_title']))
{

	$site_title = $serverSettings['site_title'];
}

$headlogopath = $webserver_url . "/phpgwapi/templates/bookingfrontend_2/styleguide/gfx";

$langmanual = lang('Manual');
$template->set_var('manual', $langmanual);

$privacy = lang('Privacy');
$template->set_var('privacy', $privacy);


$textaboutmunicipality = lang('About Aktive kommune');
$template->set_var('textaboutmunicipality', $textaboutmunicipality);

$sign_in = lang('login'); //lang('sign in');
$template->set_var('sign_in', $sign_in);

$contact = lang('contact'); //lang('sign in');
$template->set_var('contact', $contact);

$executiveofficer = lang('executiveofficer');
$template->set_var('executiveofficer', $executiveofficer);

$executiveofficer_url = phpgw::link("/", array('menuaction' => 'booking.uiapplication.index'), false, true, true);
$template->set_var('executiveofficer_url', $executiveofficer_url);

$municipality = $site_title;

$template->set_var('municipality', $municipality);

if (!empty($config_backend['support_address']))
{
	$support_email = $config_backend['support_address'];
}
else
{
	if (!empty($serverSettings['support_address']))
	{
		$support_email = $serverSettings['support_address'];
	}
	else
	{
		$support_email = 'support@aktivkommune.no';
	}
}
$template->set_var('support_email', $support_email);

if (!empty($config_frontend['url_uustatus']))
{
	$lang_uustatus = lang('uustatus');
	$url_uustatus = "<li><a target='_blank' class='link-text link-text-secondary normal' rel='noopener noreferrer'  href='{$config_frontend['url_uustatus']}'>{$lang_uustatus}</a></li>";
	$template->set_var('url_uustatus', $url_uustatus);
}

//loads jquery
phpgwapi_jquery::load_widget('core');

$javascripts	 = array();
$javascripts[]	 = "/phpgwapi/js/popper/popper.min.js";
$javascripts[]	 = "/phpgwapi/js/bootstrap5/vendor/twbs/bootstrap/dist/js/bootstrap.min.js";
$javascripts[]	 = "/phpgwapi/js/select2/js/select2.min.js";
$javascripts[]	 = "/phpgwapi/templates/bookingfrontend_2/js/knockout-min.js";
$javascripts[]	 = "/phpgwapi/templates/bookingfrontend_2/js/knockout.validation.js";
$javascripts[]	 = "/phpgwapi/templates/bookingfrontend_2/js/jquery.autocompleter.js";
$javascripts[]	 = "/phpgwapi/templates/bookingfrontend_2/js/common.js";
$javascripts[]	 = "/phpgwapi/templates/bookingfrontend_2/js/custom.js";
$javascripts[]	 = "/phpgwapi/templates/bookingfrontend_2/js/nb-NO.js";
$javascripts[]	 = "/phpgwapi/js/dateformat/dateformat.js";
$javascripts[]	 = "/phpgwapi/js/pecalendar/luxon.js";
$javascripts[]	 = "/phpgwapi/js/pecalendar/pecalendar.js";

foreach ($javascripts as $javascript)
{
	if (file_exists(PHPGW_SERVER_ROOT . $javascript))
	{
		$template->set_var('javascript_uri', $webserver_url . $javascript . $cache_refresh_token);
		$template->parse('javascripts', 'javascript', true);
	}
}

if (!empty($serverSettings['logo_url']))
{
	$footerlogoimg = $serverSettings['logo_url'];
	$template->set_var('footer_logo_img', $footerlogoimg);
}
else
{

	$footerlogoimg = $webserver_url . "/phpgwapi/templates/bookingfrontend_2/img/Aktiv-kommune-footer-logo.png";
	$template->set_var('logoimg', $footerlogoimg);
}

if (!empty($serverSettings['bakcground_image']))
{
	$footer_logo_url = $serverSettings['bakcground_image'];
	$template->set_var('footer_logo_url', $footer_logo_url);
}

$bodoc	 = CreateObject('booking.bodocumentation');
$manual	 = $bodoc->so->getFrontendDoc();

$menuaction	 = Sanitizer::get_var('menuaction', 'string', 'GET', '');
$id			 = Sanitizer::get_var('id', 'int', 'GET');
if (strpos($menuaction, 'organization'))
{
	$boorganization	 = CreateObject('booking.boorganization');
	$metainfo		 = $boorganization->so->get_metainfo($id);
	$description	 = preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($metainfo['description_json']['no'])));
	$keywords		 = $metainfo['name'] . "," . $metainfo['shortname'] . "," . $metainfo['district'] . "," . $metainfo['city'];
}
elseif (strpos($menuaction, 'group'))
{
	$bogroup	 = CreateObject('booking.bogroup');
	$metainfo	 = $bogroup->so->get_metainfo($id);
	$description = preg_replace('/\s+/', ' ', strip_tags($metainfo['description']));
	$keywords	 = $metainfo['name'] . "," . $metainfo['shortname'] . "," . $metainfo['organization'] . "," . $metainfo['district'] . "," . $metainfo['city'];
}
elseif (strpos($menuaction, 'building'))
{
	$bobuilding	 = CreateObject('booking.bobuilding');
	$metainfo	 = $bobuilding->so->get_metainfo($id);
	$description = preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($metainfo['description_json']['no'])));
	$keywords	 = $metainfo['name'] . "," . $metainfo['district'] . "," . $metainfo['city'];
}
elseif (strpos($menuaction, 'resource'))
{
	$boresource	 = CreateObject('booking.boresource');
	$metainfo	 = $boresource->so->get_metainfo($id);
	$description = preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($metainfo['description_json']['no'])));
	$keywords	 = $metainfo['name'] . "," . $metainfo['building'] . "," . $metainfo['district'] . "," . $metainfo['city'];
}
if ($keywords != '')
{
	$keywords = '<meta name="keywords" content="' . htmlspecialchars($keywords) . '">';
}
else
{
	$keywords = '<meta name="keywords" content="PorticoEstate">';
}
if (!empty($description))
{
	$description = '<meta name="description" content="' . htmlspecialchars($description) . '">';
}
else
{
	$description = '<meta name="description" content="PorticoEstate">';
}
if (!empty($config_frontend['metatag_author']))
{
	$author = '<meta name="author" content="' . $config_frontend['metatag_author'] . '">';
}
else
{
	$author = '<meta name="author" content="PorticoEstate https://github.com/PorticoEstate/PorticoEstate">';
}
if (!empty($config_frontend['metatag_robots']))
{
	$robots = '<meta name="robots" content="' . $config_frontend['metatag_robots'] . '">';
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

if (!empty($serverSettings['logo_title']))
{
	$logo_title = $serverSettings['logo_title'];
}
else
{
	$logo_title = 'Logo';
}

$site_base = $app == 'bookingfrontend' ? "/{$app}/" : '/index.php';

$site_url			 = phpgw::link($site_base, array());
$eventsearch_url	 = phpgw::link('/bookingfrontend/', array('menuaction' => 'bookingfrontend.uieventsearch.show'));
$placeholder_search	 = lang('Search');
$myorgs_text		 = lang('Show my events');

$userlang	 = $userSettings['preferences']['common']['lang'];
$flag_no	 = "{$webserver_url}/phpgwapi/templates/base/images/flag_no.gif";
$flag_en	 = "{$webserver_url}/phpgwapi/templates/base/images/flag_en.gif";

if (Sanitizer::get_var('lang', 'bool', 'GET'))
{
	$selected_lang = Sanitizer::get_var('lang', 'string', 'GET');
}
else
{
	$selected_lang = Sanitizer::get_var('selected_lang', 'string', 'COOKIE', $userlang);
}

switch ($selected_lang)
{
	case 'no':
	case 'nn':
		$selected_flag_class = 'fi-no';
		break;
	case 'en':
		$selected_flag_class = 'fi-gb';
		break;
	default:
		$selected_flag_class = "fi-{$key}";
		break;
}


$self_uri	 = $_SERVER['REQUEST_URI'];
$separator	 = strpos($self_uri, '?') ? '&' : '?';
$self_uri	 = str_replace(array("{$separator}lang=no", "{$separator}lang=en"), '', $self_uri);

// Initialize all selection states to empty
$selected_bookingfrontend = '';
$selected_bookingfrontend_2 = '';

// Determine which option should be selected
if ($userSettings['preferences']['common']['template_set'] === 'bookingfrontend') {
	$selected_bookingfrontend = ' checked';
} else if ($userSettings['preferences']['common']['template_set'] === 'bookingfrontend_2') {
	$selected_bookingfrontend_2 = ' checked';
}
$about	 = "https://www.aktiv-kommune.no/";
$faq	 = "https://www.aktiv-kommune.no/manual/";

if ($config_frontend['develope_mode'])
{
	$version_title = lang('version_choice');
	$version_ingress = lang('which_version_do_you_want');
	$version_old = lang('old');
	$version_new = lang('new');
	$template_selector = <<<HTML
              <div>
                <h3>{$version_title}</h3>
                <p>{$version_ingress}</p>
                <form class="d-flex flex-column">
                  <label class="choice mb-3">
                    <input type="radio" id="template_bookingfrontend" name="select_template" value="bookingfrontend" {$selected_bookingfrontend} />
                    {$version_old}
                    <span class="choice__radio"></span>
                  </label>
                  <label class="choice mb-5">
                    <input type="radio" id="template_bookingfrontend_2" name="select_template" value="bookingfrontend_2" {$selected_bookingfrontend_2} />
                    {$version_new}
                    <span class="choice__radio"></span>
                  </label>
                </form>
              </div>
HTML;
}
else
{
	$template_selector = '';
}

$installed_langs = $translation->get_installed_langs();
$langs = array();

$lang_selector = '';
foreach ($installed_langs as $key => $name)
{
	$trans = lang($name);
	$installed_langs[$key] = $trans;

	switch ($key)
	{
		case 'no':
		case 'nn':
			$flag_class = 'fi-no';
			break;
		case 'en':
			$flag_class = 'fi-gb';
			break;
		default:
			$flag_class = "fi-{$key}";
			break;
	}

	$_selected_lang = $selected_lang == $key ? 'checked' : '';
	$lang_selector .= <<<HTML
		  <label class="choice mb-3">
			<input type="radio" name="select_language" value="{$key}" {$_selected_lang} />
			<i class="fi {$flag_class}" title="{$key}"></i> {$trans}
			<span class="choice__radio"></span>
		  </label>
HTML;
}

$selected_lang_trans = $installed_langs[$selected_lang];

$choose_lang_trans = lang('choose language');
$choose_lang_trans2 = lang('Which language do you want?');


$version_trans = lang('version');
$what_is_aktiv_kommune = lang('what_is_aktiv_kommune');
$cart_header = lang('Application basket');


$nav = <<<HTML
<div class="border-top border-2 pt-5 pb-2r">
  <nav class="navbar">
    <a href="{$site_url}" class="navbar__logo">
      <img src="{$headlogopath}/logo_aktiv_kommune_horizontal.png" alt="Aktiv kommune logo" class="navbar__logo__img">
      <img src="{$headlogopath}/logo_aktiv_kommune.png" alt="Aktiv kommune logo" class="navbar__logo__img--desktop">
    </a>
    <div class="d-flex d-lg-none">
      <button class="pe-btn nav-toggler" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasLeft" aria-controls="offcanvasLeft" aria-label="Åpne hovedmeny">
        <span></span>
        <span></span>
        <span></span>
      </button>
    </div>
    <div class="navbar__section navbar__section--right d-none d-lg-flex">
      <!-- Button trigger modal -->
      <button type="button" class="pe-btn pe-btn--transparent navbar__section__language-selector" data-bs-toggle="modal" data-bs-target="#selectLanguage" aria-label="{$choose_lang_trans}">
		<i class="fi {$selected_flag_class}" title="{$selected_lang_trans}"></i>
        <i class="fas fa-chevron-down"></i>
      </button>

      <!-- Modal -->
      <div class="modal fade" id="selectLanguage" tabindex="-1" aria-labelledby="selectLanguage" aria-hidden="true">
        <div class="modal-dialog modal-sm">
          <div class="modal-content">
            <div class="modal-header border-0">
              <button type="button" class="btn-close text-grey-light" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body d-flex justify-content-center pt-0 pb-4">
              <div>
                <h3>{$choose_lang_trans}</h3>
                <p>{$choose_lang_trans2}</p>
                <form class="d-flex flex-column">
					{$lang_selector}
                </form>
              </div>
            </div>
          </div>
        </div>
      </div>
            <button type="button" class="pe-btn pe-btn--transparent navbar__section__language-selector" data-bs-toggle="modal" data-bs-target="#selectTemplate" aria-label="Velg template">
        {$version_trans}
        <i class="fas fa-chevron-down"></i>
      </button>
            <div class="modal fade" id="selectTemplate" tabindex="-1" aria-labelledby="selectTemplate" aria-hidden="true">
        <div class="modal-dialog modal-sm">
          <div class="modal-content">
            <div class="modal-header border-0">
              <button type="button" class="btn-close text-grey-light" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body d-flex justify-content-center pt-0 pb-4">
             {$template_selector}
            </div>
          </div>
        </div>
      </div>
      <ul class="list-unstyled navbar__section__links d-flex align-items-center">
        <li><a href="{$about}">{$what_is_aktiv_kommune}</a></li>
        <li><a href="{$faq}">FAQ</a></li>
        <li>
      <div class="js-dropdown menu position-relative " id="application-cart-container">
        <shopping-basket params="applicationCartItems: applicationCartItems, deleteItem: (a) => deleteItem(a)"></shopping-basket>
    </div>
    </li>
      </ul>
      <!--button type="button" class="pe-btn pe-btn-primary py-3">Logg inn</button-->
    </div>
  </nav>
</div>
        <div class="offcanvas offcanvas-start main-menu" tabindex="-1" id="offcanvasLeft" aria-labelledby="offcanvasLeftLabel">
          <div class="offcanvas-header justify-content-end">
            <button type="button" class="pe-btn pe-btn--transparent text-xl" data-bs-dismiss="offcanvas" aria-label="Close">
              <i class="fas fa-times"></i>
            </button>
          </div>
          <div class="offcanvas-body">
               <div>
                <h3>{$choose_lang_trans}</h3>
                <p>$choose_lang_trans2</p>
                <form class="d-flex flex-column">
					{$lang_selector}
                </form>
              </div>
              <div>
<ul class="list-unstyled">
        <li><a href="{$about}">{$what_is_aktiv_kommune}</a></li>
        <li><a href="{$faq}">FAQ</a></li>
      </ul>
      </div>
      	{$template_selector}
          </div>
        </div>
HTML;


$tpl_vars = array(
	'site_title'			 => $site_title,
	'css'					 => $phpgwapi_common->get_css($cache_refresh_token),
	'javascript'			 => $phpgwapi_common->get_javascript($cache_refresh_token),
	'img_icon'				 => $phpgwapi_common->find_image('phpgwapi', 'favicon.ico'),
	'str_base_url'			 => phpgw::link('/', array(), true, false, true),
	'dateformat_backend'	 => $userSettings['preferences']['common']['dateformat'],
	'site_url'				 => phpgw::link($site_base, array()),
	'eventsearch_url'		 => phpgw::link('/bookingfrontend/', array('menuaction' => 'bookingfrontend.uieventsearch.show')),
	'webserver_url'			 => $webserver_url,
	'metainfo_author'		 => $author,
	'userlang'				 => $userlang,
	'metainfo_keywords'		 => $keywords,
	'metainfo_description'	 => $description,
	'metainfo_robots'		 => $robots,
	'lbl_search'			 => lang('Search'),
	'header_search_class'	 => 'hidden', //(isset($_GET['menuaction']) && $_GET['menuaction'] == 'bookingfrontend.uisearch.index' ? 'hidden' : '')
	'nav'					 => empty($flags['noframework']) ? $nav : ''
);

$bouser	 = new UserHelper();

/**
 * Might be set wrong in the ui-class
 */
$xslt_app = !empty($flags['xslt_app']) ? true : false;
$org	 = CreateObject('booking.soorganization');
$flags['xslt_app'] = $xslt_app;
Settings::getInstance()->update('flags', ['xslt_app' => $xslt_app]);

$user_url = phpgw::link("/{$app}/", array('menuaction' => 'bookingfrontend.uiuser.show'));
$lang_user = lang('My page');
$tpl_vars['user_info_view'] = "<li><a class='link-text link-text-secondary normal' href='{$user_url}'><i class='fas fa-user'></i>{$lang_user}</a></li>";

$user_data = Cache::session_get($bouser->get_module(), $bouser::USERARRAY_SESSION_KEY);
if ($bouser->is_logged_in())
{

	if ($bouser->orgname == '000000000')
	{
		$tpl_vars['login_text_org']	 = lang('SSN not registred');
		$tpl_vars['login_text']		 = lang('Logout');
		$tpl_vars['org_url']		 = '#';
	}
	else
	{
		$org_url = phpgw::link("/{$app}/", array(
			'menuaction' => 'bookingfrontend.uiorganization.show',
			'id' => $org->get_orgid($bouser->orgnr, $bouser->ssn)
		));

		$lang_organization = lang('Organization');
		$tpl_vars['org_info_view'] = "<li><a class='link-text link-text-secondary normal' href='{$org_url}'><i class='fas fa-sign-in-alt' title='{$lang_organization}'></i>{$bouser->orgname}</a></li>";
		$tpl_vars['login_text_org']	 = $bouser->orgname;
		$tpl_vars['login_text']		 = lang('Logout');
	}
	$tpl_vars['login_text']	 = $bouser->orgnr . ' :: ' . lang('Logout');
	$tpl_vars['login_url']	 = 'logout';
}
else if (!empty($user_data['ssn']))
{
	$tpl_vars['login_text_org']	 = '';
	$tpl_vars['login_text']		 = "{$user_data['first_name']} {$user_data['last_name']} :: " . lang('Logout');
	$tpl_vars['org_url']		 = '#';
	$tpl_vars['login_url']	 = 'logout';
}
else
{
	$tpl_vars['login_text_org']	 = '';
	$tpl_vars['org_url']		 = '#';
	$tpl_vars['login_text']		 = lang('Organization');
	$tpl_vars['login_url']		 = 'login/?after=' . urlencode($_SERVER['QUERY_STRING']);
	$login_parameter			 = !empty($config_frontend['login_parameter']) ? $config_frontend['login_parameter'] : '';
	$custom_login_url			 = !empty($config_frontend['custom_login_url']) ? $config_frontend['custom_login_url'] : '';
	if ($login_parameter)
	{
		$login_parameter		 = ltrim($login_parameter, '&');
		$tpl_vars['login_url']	 .= "&{$login_parameter}";
	}
	if ($custom_login_url)
	{
		$tpl_vars['login_url'] = $custom_login_url;
	}
}


$template->set_var($tpl_vars);

$template->pfp('out', 'head');

unset($tpl_vars);
