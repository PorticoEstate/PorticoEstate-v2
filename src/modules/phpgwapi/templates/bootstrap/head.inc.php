<?php

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\Cache;
use App\helpers\twig\TwigTemplate;

phpgw::import_class('phpgwapi.template_portico');
phpgw::import_class('phpgwapi.common');

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

$templateDir = PHPGW_TEMPLATE_DIR;
$twigDir = PHPGW_TEMPLATE_DIR . '/twig';

$serverSettings['no_jscombine'] = false;
Settings::getInstance()->set('server', $serverSettings);

phpgw::import_class('phpgwapi.jquery');
phpgwapi_jquery::load_widget('core');
phpgwapi_jquery::load_widget('jqtree');

$javascripts = array();
$javascripts[]	 = "/phpgwapi/js/popper/popper2.min.js";
$javascripts[]	 = "/phpgwapi/js/bootstrap5/vendor/twbs/bootstrap/dist/js/bootstrap.min.js";
//$javascripts[]	 = "/phpgwapi/templates/bootstrap/js/keep_alive.js";

$userSettings['preferences']['common']['sidecontent'] = 'ajax_menu'; //ajax_menu|jsmenu
if (empty($flags['noframework']) && empty($flags['nonavbar']))
{
	phpgwapi_jquery::load_widget('contextMenu');
	$javascripts[] = "/phpgwapi/templates/bootstrap/js/sidenav.js";
}

// Prepare JavaScript files
$jsUris = [];

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
	
	// For Twig
	$jsUris[] = "{$webserver_url}/phpgwapi/inc/combine.php?cachedir={$cachedir}&type=javascript&files={$jsfiles}";
	
	unset($jsfiles);
	unset($_jsfiles);
}
else
{
	foreach ($javascripts as $javascript)
	{
		if (file_exists(PHPGW_SERVER_ROOT . $javascript))
		{
			// For Twig
			$jsUris[] = $webserver_url . $javascript . $cache_refresh_token;
		}
	}
}

// Prepare stylesheets
$stylesheets = array();
$stylesheets[] = "/phpgwapi/templates/pure/css/global.css";
$stylesheets[] = "/phpgwapi/templates/pure/css/version_3/pure-min.css";
$stylesheets[] = "/phpgwapi/templates/pure/css/pure-extension.css";
$stylesheets[] = "/phpgwapi/templates/pure/css/version_3/grids-responsive-min.css";

$stylesheets[]	 = "/phpgwapi/js/bootstrap5/vendor/twbs/bootstrap/dist/css/bootstrap.min.css";

$stylesheets[] = "/phpgwapi/templates/base/css/fontawesome/css/all.min.css";
$stylesheets[] = "/phpgwapi/templates/base/css/flag-icons.min.css";

if ($app != 'frontend')
{
	$stylesheets[] = "/phpgwapi/templates/bootstrap/css/base.css";
	$stylesheets[] = "/phpgwapi/templates/bootstrap/css/sidebar.css";

	if ($userSettings['preferences']['common']['sidecontent'] == 'ajax_menu')
	{
		$stylesheets[] = "/phpgwapi/templates/bootstrap/css/navbar_jqtree.css";
	}
	else
	{
		$stylesheets[] = "/phpgwapi/templates/bootstrap/css/navbar_bootstrap.css";
	}
}

if (isset($userSettings['preferences']['common']['theme']))
{
	$stylesheets[] = "/phpgwapi/templates/bootstrap/css/{$userSettings['preferences']['common']['theme']}.css";
}

$stylesheets[] = "/{$app}/templates/bootstrap/css/base.css";
if (isset($userSettings['preferences']['common']['theme']))
{
	$stylesheets[] = "/{$app}/templates/bootstrap/css/{$userSettings['preferences']['common']['theme']}.css";
}

$cssUris = [];

foreach ($stylesheets as $stylesheet)
{
	if (file_exists(PHPGW_SERVER_ROOT . $stylesheet))
	{
		// For Twig
		$cssUris[] = $webserver_url . $stylesheet . $cache_refresh_token;
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

$_navbar_config = json_encode($navbar_config);
$concent_script = '';
$privacy_url = !empty($serverSettings['privacy_url']) ? $serverSettings['privacy_url'] : ''; //https://www.bergen.kommune.no/omkommunen/personvern';

if ($privacy_url)
{
	$privacy_message	= !empty($serverSettings['privacy_message']) ? $serverSettings['privacy_message'] : 'Personvern ved bruk av elektroniske skjema.';
	$lang_decline		= lang('decline');
	$lang_approve		= lang('approve');
	$lang_read_more		= lang('read more');
	$lang_privacy_policy = lang('privacy policy');

	$concent_script = <<<JS
		<link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/cookieconsent@3/build/cookieconsent.min.css" />
		<script src="https://cdn.jsdelivr.net/npm/cookieconsent@3/build/cookieconsent.min.js" data-cfasync="false"></script>
		<script>

			window.addEventListener("load", function ()
			{
				window.cookieconsent.initialise({
					type: 'opt-out',
					"palette": {
						"popup": {
							"background": "#000"
						},
						"button": {
							"background": "#f1d600"
						}
					},
					"showLink": true,
					content: {
							header: 'Cookies used on the website!',
							message: '{$privacy_message}',
							dismiss: 'Got it!',
							allow: '{$lang_approve}',
							deny: '{$lang_decline}',
							link: '{$lang_read_more}',
							href: '{$privacy_url}',
							close: '&#x274c;',
							policy: '{$lang_privacy_policy}',
							target: '_blank',
					},
					position: "top",
					cookie: {
						name: 'cookieconsent_backend'
					},
					law: {
					 regionalLaw: true,
					},
					revokable:false,
					onStatusChange: function(status) {
						if(!this.hasConsented())
						{
							document.cookie = "cookieconsent_backend=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
							window.location.replace(phpGWLink('/logout_ui'));
						}
					 }
				})
			});

		</script>
JS;
}

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
	'noheader'			 => isset($flags['noheader_xsl']) && $flags['noheader_xsl'] ? 'true' : 'false',
	'nofooter'			 => isset($flags['nofooter']) && $flags['nofooter'] ? 'true' : 'false',
	'css'				 => $phpgwapi_common->get_css($cache_refresh_token),
	'javascript'		 => $phpgwapi_common->get_javascript($cache_refresh_token),
	'img_icon'			 => $phpgwapi_common->find_image('phpgwapi', 'favicon.ico'),
	'site_title'		 => "{$serverSettings['site_title']}",
	'str_base_url'		 => phpgw::link('/', array(), true, false, true),
	'webserver_url'		 => $webserver_url,
	'userlang'			 => $userSettings['preferences']['common']['lang'],
	'win_on_events'		 => $phpgwapi_common->get_on_events(),
	'navbar_config'		 => $_navbar_config,
	'menu_selection'	 => "navbar::{$menu_selection}",
	'lang_collapse_all'	 => lang('collapse all'),
	'lang_expand_all'	 => lang('expand all'),
	'concent_script'	 => $concent_script,
	'sessionid'			 => $userSettings['sessionid'],
	
	// Variables for Twig templates
	'javascripts'       => $jsUris,
	'stylesheets'       => $cssUris
);

// Use Twig to render the template
if (file_exists($twigDir . '/head.twig')) {
	$twig = new \Twig\Environment(new \Twig\Loader\FilesystemLoader([$twigDir]), [
		'debug' => true,
		'cache' => $serverSettings['temp_dir'] . '/twig_cache',
		'auto_reload' => true,
	]);
	
	echo $twig->render('head.twig', $tpl_vars);
} else {
	// Fallback message if the Twig template doesn't exist
	echo "Error: Twig template not found at {$twigDir}/head.twig";
}

unset($tpl_vars);

flush();

if (isset($flags['noframework']))
{
	echo '<body class="container-fluid">';
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

			<div class="modal fade" id="popupModal" tabindex="-1" role="dialog" aria-hidden="true">
				<div class="modal-dialog modal-lg modal-dialog-centered" role="document">
					<div class="modal-content">
						<div class="modal-header bg-dark">
							<button class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
						</div>
						<div class="modal-body">
							<iframe id="iframepopupModal" src="about:blank" width="100%" height="380" frameborder="0" sandbox="allow-same-origin allow-scripts allow-popups allow-forms allow-top-navigation"
									allowtransparency="true"></iframe>
						</div>
					</div>
					<!-- /.modal-content -->
				</div>
				<!-- /.modal-dialog -->
			</div>
			<!-- /.modal -->
		</body>
		{$javascript_end}
	</html>
HTML;
	echo $footer;
}
