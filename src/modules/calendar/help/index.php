<?php

/**************************************************************************\
 * phpGroupWare - User manual                                               *
 * http://www.phpgroupware.org                                              *
 * --------------------------------------------                             *
 *  This program is free software; you can redistribute it and/or modify it *
 *  under the terms of the GNU General Public License as published by the   *
 *  Free Software Foundation; either version 2 of the License, or (at your  *
 *  option) any later version.                                              *
  \**************************************************************************/

/* $Id$ */

use App\modules\phpgwapi\services\Settings;


Settings::getInstance()->update('flags', ['currentapp' => 'manual']);
include('../../header.inc.php');
$appname = 'calendar';
include(PHPGW_SERVER_ROOT . '/' . $appname . '/setup/setup.inc.php');

$navbar_gif = (new phpgwapi_common)->image($appname, 'navbar.gif');
$email_link = phpgw::link('email/view/compose', ['to' => 'skeeter@phpgroupware.org']);
$html = <<<HTML
<img src="$navbar_gif" border="0">
<p />
<font face="$phpgw_info[theme][font]" size="2">
  Version: <b>$setup_info[$appname][version]</b>
  <p />
  This app was based on <a href="https://sourceforge.net/projects/webcalendar/" target="_new">WebCalendar</a><br />
  <p />
  Transformed by <a href="$email_link" target="_new">Mark A Peters (Skeeter)</a><br />
</font>
HTML;

echo $html;
(new phpgwapi_common)->phpgw_footer(); 
