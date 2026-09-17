<?php

/**************************************************************************\
 * phpGroupWare - Calendar                                                  *
 * http://www.phpgroupware.org                                              *
 * Written by Mark Peters <skeeter@phpgroupware.org>                        *
 * --------------------------------------------                             *
 *  This program is free software; you can redistribute it and/or modify it *
 *  under the terms of the GNU General Public License as published by the   *
 *  Free Software Foundation; either version 2 of the License, or (at your  *
 *  option) any later version.                                              *
  \**************************************************************************/

/* $Id$ */

$preferences = \App\modules\phpgwapi\services\Preferences::getInstance();
$preferences->add("calendar", "weekstarts", "Monday");
$preferences->add("calendar", "workdaystarts", "9");
$preferences->add("calendar", "workdayends", "17");
$preferences->add("calendar", "defaultcalendar", "month.php");
$preferences->add("calendar", "defaultfilter", "all");
$preferences->add("calendar", "mainscreen_showevents", "Y");
