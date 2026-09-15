<?php
  /**************************************************************************\
  * phpGroupWare - Calendar                                                  *
  * http://www.phpgroupware.org                                              *
  * Based on Webcalendar by Craig Knudsen <cknudsen@radix.net>               *
  *          http://www.radix.net/~cknudsen                                  *
  * Modified by Mark Peters <skeeter@phpgroupware.org>                       *
  * --------------------------------------------                             *
  *  This program is free software; you can redistribute it and/or modify it *
  *  under the terms of the GNU General Public License as published by the   *
  *  Free Software Foundation; either version 2 of the License, or (at your  *
  *  option) any later version.                                              *
  \**************************************************************************/

  /* $Id$ */

  use App\modules\phpgwapi\services\Settings;

	/* I think this can go - skwashd Nov 2007
	if server calendar_type is mcal
		&& !extension_loaded('mcal') )
	{
		set server calendar_type to sql
	}
	else
	{
		set server calendar_type to sql
	}
	*/

	Settings::getInstance()->update('server', ['calendar_type' => 'sql']);

	phpgw::import_class('calendar.socalendar__');
	phpgw::import_class('calendar.socalendar_sql');
