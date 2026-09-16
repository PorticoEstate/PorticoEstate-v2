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

	$d1 = strtolower(substr(PHPGW_APP_INC,0,3));
	if($d1 == 'htt' || $d1 == 'ftp' )
	{
		echo 'Failed attempt to break in via an old Security Hole!<br />'."\n";
    (new \phpgwapi_common())->phpgw_exit();
	}
	unset($d1);

  $url = phpgw::link('/calendar/view/year', ['date' => sprintf('%04d%02d%02d', $GLOBALS['g_year'], $GLOBALS['g_month'], $GLOBALS['g_day'])]);
  $GLOBALS['extra_data'] = $GLOBALS['css'] . "\n" . '<td align="center"><a href="' . $url . '">'
    . lang('Open year view') . '</a></td>\n';
