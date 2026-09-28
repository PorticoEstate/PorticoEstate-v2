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

	Settings::getInstance()->set('flags', array
	(
		'currentapp'	=> 'calendar',
		'noheader'		=> true,
		'nonavbar'		=> true,
		'noappheader'	=> true,
		'noappfooter'	=> true,
		'nofooter'		=> true
	));
	include('../header.inc.php');

	phpgw::import_class('phpgwapi.datetime');
	
	phpgw::redirect_link('/calendar/view/month', array('date' => date('Ymd', phpgwapi_datetime::user_localtime())));
