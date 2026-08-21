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

	$template = new Template(PHPGW_TEMPLATE_DIR);
	$template->set_file('navbar', 'navbar.tpl');

	$navbar = execMethod('phpgwapi.menu.get', 'navbar');

	$navigation = array();
	prepare_navbar($navbar);

	if (true)
	{
		//$bookmarks = Cache::user_get('phpgwapi', "bookmark_menu", $userSettings['id']);
		$lang_bookmarks = lang('bookmarks');

		$navigation = execMethod('phpgwapi.menu.get', 'navigation');
		$treemenu = '';
		foreach ($navbar as $app => $app_data)
		{
			if ($app == $flags['currentapp'])
			{
				$submenu = isset($navigation[$app]) ? render_submenu($app, $navigation[$app], array()) : '';
				//		$treemenu .= render_item($app_data, "navbar::{$app}", $submenu, $bookmarks);
			}
		}
		$var['treemenu'] = <<<HTML

			<ul id="menutree">
			{$submenu}
			</ul>
HTML;
	}

	$template->set_var($var);
	$template->pfp('out', 'navbar');

	$hooks = new Hooks();
	$hooks->process('after_navbar');
	$phpgwapi_common = new \phpgwapi_common();
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

	$current_class = '';

	if ($id == "navbar::{$flags['menu_selection']}")
	{
		$current_class = 'Selected';
		$item['selected'] = true;
	}

	$bookmark = '';
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

	$footer_info = Cache::session_get('phpgwapi', 'footer_info');
	$var = array(
		'footer_info'	=> $footer_info, //'Bergen kommune | R&aring;dhusgt 10 | Postboks 7700 | 5020 Bergen',
		'powered_by'	=> lang('Powered by Portico version %1', $serverSettings['versions']['phpgwapi']),
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
