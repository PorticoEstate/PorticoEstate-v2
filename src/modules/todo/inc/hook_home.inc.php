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

if (
	isset($userSettings['preferences']['todo']['mainscreen_showevents'])
	&& $userSettings['preferences']['todo']['mainscreen_showevents'] == True
)
{
	$botodo = CreateObject('todo.botodo', True);
	$todo_items = $botodo->_list(0, 5, '', '', 'todo_startdate', 'ASC', 0, 'all');

	$content = '';
	if (is_array($todo_items) && count($todo_items))
	{
		$content .= '<ul class="todo-home-list">';
		foreach ($todo_items as $item)
		{
			$title = phpgw::strip_html($item['title']);
			if (!$title)
			{
				$words = explode(' ', phpgw::strip_html($item['descr']));
				$title = implode(' ', array_slice($words, 0, 4)) . ' ...';
			}

			$url = phpgw::link('/index.php', array(
				'menuaction' => 'todo.uitodo.view',
				'todo_id' => (int) $item['id']
			));

			$content .= '<li><a href="' . $url . '">' . $title . '</a></li>';
		}
		$content .= '</ul>';
	}
	else
	{
		$content .= '<p>' . lang('No entries') . '</p>';
	}

	$content .= '<p><a href="' . phpgw::link('/index.php', array('menuaction' => 'todo.uitodo.show_list'))
		. '">' . lang('Show all') . '</a></p>';

	$extra_data = '<td>' . "\n" . $content . '</td>' . "\n";

	$applications = new Applications();
	$app_id = $applications->name2id('todo');
	$GLOBALS['portal_order'][] = $app_id;

	$portalbox = CreateObject('phpgwapi.portalbox');
	$portalbox->set_params(array(
		'app_id'	=> $app_id,
		'title'	=> lang('todo')
	));
	$portalbox->draw($extra_data);
}
