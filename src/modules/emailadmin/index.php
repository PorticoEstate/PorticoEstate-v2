<?php
	use App\modules\phpgwapi\services\Settings;

	/**************************************************************************\
	* EGroupWare - EMailAdmin                                                  *
	* http://www.egroupware.org                                                *
	* Written by Lars Kneschke [lkneschke@egroupware.org]                      *
	* -----------------------------------------------                          *
	*  This program is free software; you can redistribute it and/or modify it *
	*  under the terms of the GNU General Public License as published by the   *
	*  Free Software Foundation; either version 2 of the License, or (at your  *
	*  option) any later version.                                              *
	\**************************************************************************/
	/* $Id$ */

	Settings::getInstance()->set('flags', array(
		'currentapp' => 'emailadmin',
		'noheader' => True,
		'nonavbar' => True,
	));
	include('../header.inc.php');

	phpgw::redirect_link('/emailadmin/view/profiles');