<?php

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\Cache;
use App\helpers\Template;
use App\modules\phpgwapi\security\Acl;
use App\modules\phpgwapi\services\Hooks;
use App\modules\phpgwapi\services\Translation;
use App\modules\phpgwapi\controllers\Accounts\Accounts;

function parse_navbar($force = False)
{
	$serverSettings = Settings::getInstance()->get('server');
	$flags = Settings::getInstance()->get('flags');
	$userSettings = Settings::getInstance()->get('user');
	$phpgwapi_common = new phpgwapi_common();


	$nonavbar = false;
	if (isset($flags['nonavbar']) && $flags['nonavbar'])
	{
		$nonavbar	= true;
	}

	$navbar = array();
	if (!isset($flags['nonavbar']) || !$flags['nonavbar'])
	{
		$navbar = execMethod('phpgwapi.menu.get', 'navbar');
	}

	$user = (new Accounts())->get($userSettings['id']);


	$var = array(
		'webserver_url'	=> $serverSettings['webserver_url']
	);

	$extra_vars = array();
	foreach ($_GET as $name => $value)
	{
		$extra_vars[$name] = Sanitizer::clean_value($value);
	}

	$print_url = "?" . http_build_query(array_merge($extra_vars, array('phpgw_return_as' => 'noframes')));
	$user_fullname	= $user->__toString();
	$print_text		= lang('print');
	$home_url		= phpgw::link('/home/');
	$home_text		= lang('home');
	$home_icon		= 'icon icon-home';
	$about_url	= phpgw::link('/about.php', array('app' => $flags['currentapp']));
	$about_text	= lang('about');
	$logout_url	= phpgw::link('/logout.php');
	$logout_text	= lang('logout');
	$var['user_fullname'] = $user_fullname;
	$preferences_url = phpgw::link('/preferences/index.php');
	$preferences_text = lang('preferences');


	$var['topmenu'] = <<<HTML
		<div class="pure-menu pure-menu-horizontal">
			<ul id="std-menu-items" class="pure-menu-list">
				<li class="pure-menu-item pure-menu-has-children pure-menu-allow-hover">
					<a class="pure-menu-link" href="#">{$user_fullname}</a>
					<ul class="pure-menu-children">
						<li class="pure-menu-item">
							<a href="{$preferences_url}" class="pure-menu-link">{$preferences_text}</a>
						</li>
						<li class="pure-menu-item">
							<a href="{$logout_url}" class="pure-menu-link">{$logout_text}</a>
						</li>
					</ul>
				</li>
				<li class="pure-menu-item">
					<a href="{$print_url}"  target="_blank" class="pure-menu-link">{$print_text}</a>
				</li>
				<li class="pure-menu-item">
					<a href="{$home_url}" class="pure-menu-link">{$home_text}</a>
				</li>
				<li class="pure-menu-item">
					<a href="{$about_url}" class="pure-menu-link">{$about_text}</a>
				</li>
HTML;

	if (isset($userSettings['apps']['manual']))
	{
		$apps = Settings::getInstance()->get('apps');
		$help_url = "javascript:openwindow('"
			. phpgw::link('/index.php', array(
				'menuaction' => 'manual.uimanual.help',
				'app' => $flags['currentapp'],
				'section' => isset($apps['manual']['section']) ? $apps['manual']['section'] : '',
				'referer' => Sanitizer::get_var('menuaction')
			)) . "','700','600')";

		$help_text = lang('help');
		$var['topmenu'] .= <<<HTML
			<li class="pure-menu-item">
				<a href="{$help_url}" class="pure-menu-link">{$help_text}</a>
			</li>
HTML;
	}


	if (isset($serverSettings['support_address']) && $serverSettings['support_address'])
	{
		$support_url = "javascript:openwindow('"
			. phpgw::link('/index.php', array(
				'menuaction' => 'manual.uisupport.send',
				'app' => $flags['currentapp'],
			)) . "','700','600')";

		$support_text = lang('support');

		$var['topmenu'] .= <<<HTML
			<li class="pure-menu-item">
				<a href="{$support_url}" class="pure-menu-link">{$support_text}</a>
			</li>
HTML;
	}

	if (isset($userSettings['apps']['admin']))
	{
		$debug_url = "javascript:openwindow('"
			. phpgw::link('/index.php', array(
				'menuaction' => 'phpgwapi.uidebug_json.index',
				'app'		=> $flags['currentapp']
			)) . "','','')";

		$debug_text = lang('debug');
		$var['topmenu'] .= <<<HTML
			<li class="pure-menu-item">
				<a href="{$debug_url}" class="pure-menu-link">{$debug_text}</a>
			</li>
HTML;
	}

	$template = new Template(PHPGW_TEMPLATE_DIR);
	$template->set_file('navbar', 'navbar.tpl');

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

	$navigation = array();
	if (!isset($userSettings['preferences']['property']['nonavbar']) || $userSettings['preferences']['property']['nonavbar'] != 'yes')
	{
		prepare_navbar($navbar);
	}
	else
	{
		foreach ($navbar as &$app_tmp)
		{
			$app_tmp['text'] = ' ...';
		}
	}

	if (!$nonavbar)
	{
		$bookmarks = Cache::user_get('phpgwapi', "bookmark_menu", $userSettings['id']);
		$lang_bookmarks = lang('bookmarks');

		$navigation = execMethod('phpgwapi.menu.get', 'navigation');
		$treemenu = '';
		foreach ($navbar as $app => $app_data)
		{
			if (!in_array($app, array('logout', 'about', 'preferences')))
			{
				$submenu = isset($navigation[$app]) ? render_submenu($app, $navigation[$app], $bookmarks) : '';
				$treemenu .= render_item($app_data, "navbar::{$app}", $submenu, $bookmarks);
			}
		}
		$var['treemenu'] = <<<HTML

			<ul id="menutree">
HTML;
		
		if (Acl::getInstance()->check('run', ACL_READ, 'preferences'))
		{
			$var['treemenu'] .= <<<HTML

				<li>
					<a href="{$preferences_url}">{$preferences_text}</a>
				</li>
HTML;
		}
		$var['treemenu'] .= <<<HTML
			{$treemenu}
			</ul>
HTML;


		$collected_bm = set_get_bookmarks();
		if ($collected_bm)
		{
			$var['topmenu'] .= <<<HTML

				<li class="pure-menu-item pure-menu-has-children pure-menu-allow-hover">
					<a href="#" class="pure-menu-link">{$lang_bookmarks}</a>
					<ul class="pure-menu-children">
HTML;

			foreach ($collected_bm as $entry)
			{
				$seleced_bm = 'class="pure-menu-item"';
				if (isset($entry['selected']) && $entry['selected'])
				{
					$seleced_bm = 'class="pure-menu-item pure-menu-selected"';
					$entry['text'] = "<b>[ {$entry['text']} ]</b>";
				}

				$var['topmenu'] .= <<<HTML

						<li {$seleced_bm}>
							<a href="{$entry['url']}" class="pure-menu-link">{$entry['text']}</a>
						</li>

HTML;
			}
			$var['topmenu'] .= '</ul>';
		}
		else
		{
			$var['topmenu'] .= <<<HTML

				<li class="pure-menu-item pure-menu-disabled">
					<a href="#" class="pure-menu-link">{$lang_bookmarks}</a>
HTML;
		}
		$var['topmenu'] .= <<<HTML
				
			</li>
		</ul>
	</div>
HTML;
	}


	$template->set_var($var);
	$template->pfp('out', 'navbar');

	if (Sanitizer::get_var('phpgw_return_as') != 'json' && $global_message = Cache::system_get('phpgwapi', 'phpgw_global_message'))
	{
		echo "<div class='msg_good'>";
		echo nl2br($global_message);
		echo '</div>';
	}
	if (Sanitizer::get_var('phpgw_return_as') != 'json' && $breadcrumbs) // && isset($userSettings['preferences']['common']['show_breadcrumbs']) && $userSettings['preferences']['common']['show_breadcrumbs'])
	{
		$history_url = array();
		foreach ($breadcrumbs as $breadcrumb)
		{
			$history_url[] = "<a href='{$breadcrumb['url']}'>{$breadcrumb['name']}</a>";
		}
		$breadcrumbs = '<div class="breadcrumbs"><h4>' . implode(' >> ', $history_url) . '</h4></div>';
		//			echo $breadcrumbs;
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

	if (Sanitizer::get_var('phpgw_return_as') != 'json')
	{
		$apiPrefix = rtrim($serverSettings['webserver_url'] ?? '', '/');
		$apiPrefixJson = json_encode($apiPrefix, JSON_UNESCAPED_SLASHES);
		echo "<script>window.__phpgwApiPrefix = {$apiPrefixJson};</script>";
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

function render_item($item, $id = '', $children = '', $bookmarks = array())
{
	$flags = Settings::getInstance()->get('flags');
	static $checkbox_id = 1;
	$current_class = '';

	if ($id == "navbar::{$flags['menu_selection']}")
	{
		$current_class = 'Selected';
		$item['selected'] = true;
	}

	$bookmark = '';
	if (preg_match("/(^navbar::)/i", $id)) // bookmarks
	{
		$_bookmark_checked = '';
		if (is_array($bookmarks) && isset($bookmarks[$id]))
		{
			$_bookmark_checked = "checked = 'checked'";
			set_get_bookmarks($item);
		}

		$bookmark = "<input type='checkbox' name='update_bookmark_menu' id='{$checkbox_id}' value='{$id}' {$_bookmark_checked}/>";
		$checkbox_id++;
	}

	if (preg_match("/(^{$id})/i", "navbar::{$flags['menu_selection']}"))
	{
		$item['text'] = "<b>[ {$item['text']} ]</b>";
	}

	$link_class = $current_class ? "class=\"{$current_class}\"" : '';

	$out = <<<HTML
				<li {$link_class}>
HTML;
	$target = '';
	if (isset($item['target']))
	{
		$target = "target = '{$item['target']}'";
	}
	if (isset($item['local_files']) && $item['local_files'])
	{
		$item['url'] = 'file:///' . str_replace(':', '|', $item['url']);
	}

	return <<<HTML
$out
					<a href="{$item['url']}" id="{$id}" {$target}>{$bookmark} {$item['text']}</a>
{$children}
				</li>

HTML;
}

function render_submenu($parent, $menu, $bookmarks = array())
{
	$out = '';
	foreach ($menu as $key => $item)
	{
		$children = isset($item['children']) ? render_submenu("{$parent}::{$key}", $item['children'], $bookmarks) : '';
		$out .= render_item($item, "navbar::{$parent}::{$key}", $children, $bookmarks);
		//$debug .= "{$parent}::{$key}<br>";
	}

	$out = <<<HTML
			<ul>
{$out}
			</ul>

HTML;
	return $out;
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

/**
 * Callback for usort($navbar)
 *
 * @param array $item1 the first item to compare
 * @param array $item2 the second item to compare
 * @return int result of comparision
 */
function sort_navbar($item1, $item2)
{
	$a = &$item1['order'];
	$b = &$item2['order'];

	if ($a == $b)
	{
		return strcmp($item1['text'], $item2['text']);
	}
	return ($a < $b) ? -1 : 1;
}

/**
 * Organise the navbar properly
 *
 * @param array $navbar the navbar items
 * @return array the organised navbar
 */
function prepare_navbar(&$navbar)
{
	if (isset($navbar['admin']) && is_array($navbar['admin']))
	{
		$navbar['admin']['children'] = execMethod('phpgwapi.menu.get', 'admin');
	}
	uasort($navbar, 'sort_navbar');
}

/**
 * Cheat function to collect bookmarks
 * @staticvar array $bookmarks
 * @param array $item
 * @return array bookmarks
 */
function set_get_bookmarks($item = array())
{
	static $bookmarks = array();
	if ($item)
	{
		$bookmarks[] = $item;
	}
	return $bookmarks;
}
