<?php

use App\helpers\Template;
use App\modules\phpgwapi\controllers\Accounts\Accounts;
use App\modules\phpgwapi\security\Acl;
use App\modules\phpgwapi\services\Cache;
use App\modules\phpgwapi\services\Hooks;
use App\modules\phpgwapi\services\Settings;

function parse_navbar($force = False)
{
	$serverSettings = Settings::getInstance()->get('server');
	$flags = Settings::getInstance()->get('flags');
	$apps = Settings::getInstance()->get('apps');
	$userSettings = Settings::getInstance()->get('user');
	$phpgwapi_common = new phpgwapi_common();

	$navbar = array();
	//		if(!isset($flags['nonavbar']) || !$flags['nonavbar'])
	{
		$navbar = execMethod('phpgwapi.menu.get', 'navbar');
	}

	$user = (new Accounts())->get($userSettings['id']);

	$extra_vars = array();
	foreach ($_GET as $name => $value)
	{
		$extra_vars[$name] = Sanitizer::clean_value($value);
	}

	switch ($userSettings['preferences']['common']['template_set'])
	{
		case 'portico':
			$selecte_portico = ' selected = "selected"';
			$selecte_pure = '';
			break;
		case 'bootstrap':
			$selecte_portico = '';
			$selecte_bootstrap = ' selected = "selected"';
			break;
	}

	$template_selector = <<<HTML

	   <select id = "template_selector">
		<option value="bootstrap"{$selecte_bootstrap}>Bootstrap</option>
		<option value="portico"{$selecte_portico}>Portico</option>
	   </select>
	HTML;

	$var = array(
		'print_url'		=> "?" . http_build_query(array_merge($extra_vars, array('phpgw_return_as' => 'noframes'))),
		'print_text'	=> lang('print'),
		'home_url'		=> phpgw::link('/home/'),
		'home_text'		=> lang('home'),
		'home_icon'		=> 'icon icon-home',
		'about_url'		=> phpgw::link('/about.php', array('app' => $flags['currentapp'])),
		'about_text'	=> lang('about'),
		'logout_url'	=> phpgw::link('/logout_ui'),
		'logout_text'	=> lang('logout'),
		'site_title'	=> "{$serverSettings['site_title']}",
		'user_fullname' => $user->__toString(),
		'top_level_menu_url' => phpgw::link('/index.php', array('menuaction' => 'phpgwapi.menu.get_local_menu_ajax', 'node' => 'top_level', 'phpgw_return_as' => 'json')),
		'template_selector'	=> $template_selector,
		'lang_collapse_all'	=> lang('collapse all'),
		'lang_expand_all'	=> lang('expand all'),

	);

	if (Acl::getInstance()->check('run', ACL_READ, 'preferences'))
	{
		$var['preferences_url'] = phpgw::link('/preferences/index.php');
		$var['preferences_text'] = lang('preferences');
	}

	if (isset($userSettings['apps']['manual']))
	{
		$var['help_url'] = "javascript:openwindow('"
			. phpgw::link('/index.php', array(
				'menuaction' => 'manual.uimanual.help',
				'app' => $flags['currentapp'],
				'section' => isset($apps['manual']['section']) ? $apps['manual']['section'] : '',
				'referer' => Sanitizer::get_var('menuaction')
			)) . "','700','600')";

		$var['help_text'] = lang('help');
		$var['help_icon'] = 'icon icon-help';
	}


	if (isset($serverSettings['support_address']) && $serverSettings['support_address'])
	{
		$support_js = <<<JS

			support_request = function()
			{
				var oArgs = {menuaction:'manual.uisupport.send',app:'{$flags['currentapp']}'};
				var strURL = phpGWLink('index.php', oArgs);
				TINY.box.show({iframe:strURL, boxid:"frameless",width:700,height:400,fixed:false,maskid:"darkmask",maskopacity:40, mask:true, animate:true, close: true});
			}
JS;


		$var['support_request'] = $support_js;
		$var['support_url'] = "javascript:support_request();";
		$var['support_text'] = lang('support');
		$var['support_icon'] = 'icon icon-help';
	}

	if (isset($userSettings['apps']['admin']))
	{
		$var['debug_url'] = "javascript:openwindow('"
			. phpgw::link('/index.php', array(
				'menuaction' => 'phpgwapi.uidebug_json.index',
				'app'		=> $flags['currentapp']
			)) . "','','')";

		$var['debug_text'] = lang('debug');
		$var['debug_icon'] = 'icon icon-debug';
	}
	$template = new Template(PHPGW_TEMPLATE_DIR);

	$template->set_file('navbar', 'navbar.tpl');

	$flags = &$flags;
	$var['current_app_title'] = isset($flags['app_header']) ? $flags['app_header'] : lang($flags['currentapp']);
	$flags['menu_selection'] = isset($flags['menu_selection']) ? $flags['menu_selection'] : '';
	$breadcrumb_selection = !empty($flags['breadcrumb_selection']) ? $flags['breadcrumb_selection'] : $flags['menu_selection'];

	// breadcrumbs
	$current_url = array(
		'id'	=> $flags['menu_selection'],
		'url'	=> 	"?" . http_build_query($extra_vars),
		'name'	=> $var['current_app_title']
	);
	$breadcrumbs = Cache::session_get('phpgwapi', 'breadcrumbs');
	$breadcrumbs = $breadcrumbs ? $breadcrumbs : array(); // first one
	if (empty($breadcrumbs) || (isset($breadcrumbs[0]['id']) && $breadcrumbs[0]['id'] != $breadcrumb_selection))
	{
		array_unshift($breadcrumbs, $current_url);
	}
	if (count($breadcrumbs) >= 5)
	{
		array_pop($breadcrumbs);
	}
	Cache::session_set('phpgwapi', 'breadcrumbs', $breadcrumbs);
	$breadcrumbs = array_reverse($breadcrumbs);

	Cache::session_set('navbar', 'menu_selection', $flags['menu_selection']);

	//		$menu_organizer = createObject('phpgwapi.menu_jqtree');
	//		$treemenu = $menu_organizer->get_menu();

	//		$var['treemenu_data'] = json_encode($treemenu);
	//		$var['current_node_id'] =  $menu_organizer->get_current_node_id();

	$template->set_var($var);
	$template->pfp('out', 'navbar');

	if (Sanitizer::get_var('phpgw_return_as') != 'json' && $global_message = Cache::system_get('phpgwapi', 'phpgw_global_message'))
	{
		echo "<div class='msg_good'>";
		echo nl2br($global_message);
		echo '</div>';
	}
	if (Sanitizer::get_var('phpgw_return_as') != 'json' && $breadcrumbs && isset($userSettings['preferences']['common']['show_breadcrumbs']) && $userSettings['preferences']['common']['show_breadcrumbs'])
	{
		$history_url = array();
		foreach ($breadcrumbs as $breadcrumb)
		{
			$history_url[] = "<a href='{$breadcrumb['url']}'>{$breadcrumb['name']}</a>";
		}
		$breadcrumbs = '<div class="breadcrumbs"><h4>' . implode(' >> ', $history_url) . '</h4></div>';
		echo $breadcrumbs;
	}


	if (Sanitizer::get_var('phpgw_return_as') != 'json' && $receipt = Cache::session_get('phpgwapi', 'phpgw_messages'))
	{
		Cache::session_clear('phpgwapi', 'phpgw_messages');
		$msgbox_data = $phpgwapi_common->msgbox_data($receipt);
		$msgbox_data = $phpgwapi_common->msgbox($msgbox_data);
		foreach ($msgbox_data as &$message)
		{
			echo "<div class='{$message['msgbox_class']}'>";
			echo $message['msgbox_text'];
			echo '</div>';
		}
	}

	(new Hooks())->process('after_navbar');
	register_shutdown_function('parse_footer_end');
}

function item_expanded($id)
{
	static $navbar_state;
	if (!isset($navbar_state))
	{
		$navbar_state = execMethod('phpgwapi.template_portico.retrieve_local', 'navbar_config');
	}
	return isset($navbar_state[$id]);
}

function parse_footer_end()
{
	$serverSettings = Settings::getInstance()->get('server');
	$phpgwapi_common = new phpgwapi_common();

	// Stop the register_shutdown_function causing the footer to be included twice - skwashd dec07
	static $footer_included = false;
	if ($footer_included)
	{
		return true;
	}
	$template = new Template(PHPGW_TEMPLATE_DIR);

	$template->set_file('footer', 'footer.tpl');

	$version = isset($serverSettings['versions']['system']) ? $serverSettings['versions']['system'] : $serverSettings['versions']['phpgwapi'];

	if (isset($serverSettings['system_name']))
	{
		$powered_by = $serverSettings['system_name'] . ' ' . lang('version') . ' ' . $version;
	}
	else
	{
		$powered_by = lang('Powered by phpGroupWare version %1', $version);
	}

	$var = array(
		'powered_by'	=> $powered_by,
		'lang_login'	=> lang('login'),
		'javascript_end' => $phpgwapi_common->get_javascript_end()
	);

	$template->set_var($var);

	$template->pfp('out', 'footer');

	$footer_included = true;
}
