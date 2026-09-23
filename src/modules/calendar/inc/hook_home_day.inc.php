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

$date = sprintf('%04d%02d%02d', $GLOBALS['g_year'], $GLOBALS['g_month'], $GLOBALS['g_day']);
$dayUrl = phpgw::link('/calendar/view/day', ['date' => $date]);
$monthUrl = phpgw::link('/calendar/view/month', ['date' => $date]);
$GLOBALS['extra_data'] = $GLOBALS['css'] . "\n" . '<td><table border="0" width="100%"><tr><td align="center">'
  . '<a href="' . $monthUrl . '">' . lang((new \phpgwapi_common())->show_date($time, 'F')) . ' '
  . $GLOBALS['g_day'] . ', ' . $GLOBALS['g_year'] . '</a></td></tr><tr><td align="center">'
  . '<a href="' . $dayUrl . '">' . lang('Open day view') . '</a></td></tr></table></td>\n';
