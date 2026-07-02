<?php

/**
 * Todo - admin hook
 *
 * @copyright Copyright (C) 2002,2005 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @package todo
 * @subpackage hooks
 * @version $Id$
 */

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\controllers\Applications;


$userSettings = Settings::getInstance()->get('user');

$prefs = (array) ($userSettings['preferences']['todo'] ?? []);
$showOnMain = in_array(strtolower((string) ($prefs['mainscreen_showevents'] ?? '0')), ['1', 'true', 'yes'], true);

if ($showOnMain)
{
	$flags = Settings::getInstance()->get('flags');
	$saveApp = (string) ($flags['currentapp'] ?? '');
	$flags['currentapp'] = 'todo';
	Settings::getInstance()->set('flags', $flags);

	$maxmatches = (int) ($userSettings['preferences']['common']['maxmatchs'] ?? 0);
	$userSettings['preferences']['common']['maxmatchs'] = 5;
	Settings::getInstance()->set('user', $userSettings);

	$botodo = CreateObject('todo.botodo', True);
	$todo_items = $botodo->_list(0, 5, '', '', 'todo_startdate', 'ASC', 0, 'all');

	$content = '';
	if (is_array($todo_items) && count($todo_items))
	{
		$content .= '<ul class="todo-home-list">';
		foreach ($todo_items as $item)
		{
			$title = phpgw::strip_html((string) ($item['title'] ?? ''));
			if (!$title)
			{
				$words = explode(' ', phpgw::strip_html((string) ($item['descr'] ?? '')));
				$title = implode(' ', array_slice($words, 0, 4)) . ' ...';
			}
			$title = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

			$url = phpgw::link('/todo/view/todos/' . (int) $item['id']);

			$content .= '<li><a href="' . $url . '">' . $title . '</a></li>';
		}
		$content .= '</ul>';
	}
	else
	{
		$content .= '<p>' . lang('No entries') . '</p>';
	}

	$content .= '<p><a href="' . phpgw::link('/todo/view/todos')
		. '">' . lang('Show all') . '</a></p>';

	$extra_data = '<td>' . "\n" . $content . '</td>' . "\n";

	$applications = new Applications();
	$app_id = $applications->name2id('todo');
	if (!isset($GLOBALS['portal_order']) || !is_array($GLOBALS['portal_order']))
	{
		$GLOBALS['portal_order'] = [];
	}
	if (!in_array($app_id, $GLOBALS['portal_order'], true))
	{
		$GLOBALS['portal_order'][] = $app_id;
	}

	$theme = Settings::getInstance()->get('theme');
	$portalbox = CreateObject('phpgwapi.listbox', array(
		'app_id' => $app_id,
		'title' => lang('todo'),
		'primary' => $theme['navbar_bg'] ?? '',
		'secondary' => $theme['navbar_bg'] ?? '',
		'tertiary' => $theme['navbar_bg'] ?? '',
		'width' => '100%',
		'outerborderwidth' => '0',
		'header_background_image' => (new \phpgwapi_common())->image('phpgwapi', 'bg_filler', '.png', False),
	));
	$portalbox->draw($extra_data);

	$flags['currentapp'] = $saveApp;
	Settings::getInstance()->set('flags', $flags);

	if ($maxmatches > 0)
	{
		$userSettings['preferences']['common']['maxmatchs'] = $maxmatches;
		Settings::getInstance()->set('user', $userSettings);
	}
}
