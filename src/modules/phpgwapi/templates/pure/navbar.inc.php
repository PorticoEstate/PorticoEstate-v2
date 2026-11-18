<?php

require __DIR__ . '/../digdir/navbar.inc.php';

function render_pure_menu(array $navbar, array $bookmarks, string $selection, string $preferences_url): string
{
	$navigation = execMethod('phpgwapi.menu.get', 'navigation');
	$html = '<nav id="menu" aria-label="' . htmlspecialchars(lang('menu')) . '"><ul id="menutree">';
	foreach ($navbar as $app => $item)
	{
		if (!in_array($app, ['logout', 'about', 'preferences'], true))
		{
			$item['children'] = $navigation[$app] ?? [];
			$html .= render_pure_menu_item($item, "navbar::{$app}", $bookmarks, $selection);
		}
	}
	if ($preferences_url)
	{
		$html .= '<li><a href="' . htmlspecialchars($preferences_url) . '">' . htmlspecialchars(lang('preferences')) . '</a></li>';
	}
	return $html . '</ul></nav>';
}

function render_pure_menu_item(array $item, string $id, array $bookmarks, string $selection): string
{
	$is_current = $id === "navbar::{$selection}";
	$selected = $is_current ? ' class="Selected"' : '';
	$current = $is_current ? ' aria-current="page"' : '';
	$href = !empty($item['local_files']) ? 'file:///' . str_replace(':', '|', $item['url']) : $item['url'];
	$target = isset($item['target']) ? ' target="' . htmlspecialchars($item['target']) . '"' : '';
	$icon = htmlspecialchars($item['icon'] ?? '');
	$key = htmlspecialchars($id);
	$text = htmlspecialchars($item['text']);
	$checked = isset($bookmarks[$id]) ? ' checked' : '';
	$label = htmlspecialchars(lang('bookmarks') . ': ' . $item['text']);
	$html = "<li{$selected}><a href=\"" . htmlspecialchars($href) . "\" id=\"{$key}\" data-icon=\"{$icon}\"{$target}{$current}>"
		. "<input type=\"checkbox\" name=\"update_bookmark_menu\" value=\"{$key}\" aria-label=\"{$label}\"{$checked}> {$text}</a>";

	if (!empty($item['children']))
	{
		$html .= '<ul>';
		foreach ($item['children'] as $name => $child)
		{
			$html .= render_pure_menu_item($child, "{$id}::{$name}", $bookmarks, $selection);
		}
		$html .= '</ul>';
	}
	return $html . '</li>';
}
