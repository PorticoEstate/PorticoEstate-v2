<?php

/**************************************************************************\
 * phpGroupWare - Calendar                                                  *
 * http://www.phpgroupware.org                                              *
 * Based on Webcalendar by Craig Knudsen <cknudsen@radix.net>               *
 *          http://www.radix.net/~cknudsen                                  *
 * Written by Mark Peters <skeeter@phpgroupware.org>                        *
 * --------------------------------------------                             *
 *  This program is free software; you can redistribute it and/or modify it *
 *  under the terms of the GNU General Public License as published by the   *
 *  Free Software Foundation; either version 2 of the License, or (at your  *
 *  option) any later version.                                              *
  \**************************************************************************/

/* $Id$ */

global $calendar_id;

$d1 = strtolower(substr(\App\modules\phpgwapi\services\Settings::getInstance()->get('server')['app_inc'], 0, 3));
if ($d1 == 'htt' || $d1 == 'ftp')
{
	echo 'Failed attempt to break in via an old Security Hole!<br />' . "\n";
	(new \phpgwapi_common())->phpgw_exit();
}
unset($d1);

if ($calendar_id)
{
	\App\modules\phpgwapi\services\Translation::getInstance()->add_app('calendar');
	$calendar = CreateObject('calendar.bocalendar', 1);
	if ($event = $calendar->read_entry($calendar_id))
	{
		$escape = static function ($value): string
		{
			return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
		};
		$start = $calendar->maketime($event['start']);
		$end = $calendar->maketime($event['end']);
		$eventUrl = phpgw::link('/calendar/view/event/' . (int)$calendar_id);
		$responseUrl = phpgw::link('/calendar/events/' . (int)$calendar_id . '/response');
		$participants = '';
		foreach ((array)($event['participants'] ?? []) as $participantId => $status)
		{
			$participants .= '<li>' . $escape($calendar->contacts->get_name_of_person_id($participantId))
				. ' (' . $escape($calendar->get_long_status($status)) . ')</li>';
		}
		$actions = '';
		foreach (array(ACCEPTED => 'Accept', TENTATIVE => 'Tentative', REJECTED => 'Reject') as $status => $label)
		{
			$actions .= '<a href="' . $escape($responseUrl . '&status=' . (int)$status) . '">' . lang($label) . '</a> ';
		}
		echo '<table cellpadding="5" cellspacing="0" border="0"><tr><td>'
			. '<strong>' . $escape($event['title'] ?? '') . '</strong><br>'
			. $escape(date('Y-m-d H:i', $start)) . ' - ' . $escape(date('Y-m-d H:i', $end)) . '<br>'
			. $escape($event['location'] ?? '') . '</td></tr><tr><td>'
			. nl2br($escape($event['description'] ?? '')) . '</td></tr><tr><td>'
			. '<strong>' . lang('Participants') . '</strong><ul>' . $participants . '</ul>'
			. '</td></tr><tr><td>' . $actions . '<br><a href="' . $escape($eventUrl) . '">'
			. lang('View event') . '</a></td></tr></table>';
	}
}
