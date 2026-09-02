<?php
/*	 * ************************************************************************\
	 * phpGroupWare - Messenger                                                 *
	 * http://www.phpgroupware.org                                              *
	 * This application written by Joseph Engo <jengo@phpgroupware.org>         *
	 * --------------------------------------------                             *
	 * Funding for this program was provided by http://www.checkwithmom.com     *
	 * --------------------------------------------                             *
	 *  This program is free software; you can redistribute it and/or modify it *
	 *  under the terms of the GNU General Public License as published by the   *
	 *  Free Software Foundation; either version 2 of the License, or (at your  *
	 *  option) any later version.                                              *
	  \************************************************************************* */

/* $Id$ */

use App\modules\phpgwapi\services\Settings;
use App\Database\Db;

$db = Db::getInstance();
$userSettings = Settings::getInstance()->get('user');
$flags = Settings::getInstance()->get('flags');

if (
	$flags['currentapp'] != 'messenger'
	&& $flags['currentapp'] != 'welcome'
	&& !in_array($userSettings['preferences']['common']['template_set'], array('bootstrap', 'digdir'))
)
{
	$db->query("SELECT COUNT(*) AS msg_cnt FROM phpgw_messenger_messages WHERE message_owner='"
		. (int)$userSettings['account_id'] . "' and message_status='N'", __LINE__, __FILE__);

	if ($db->next_record() && $db->f('msg_cnt'))
	{
		echo '<div class="msg"><a href="' . phpgw::link('/messenger/view/inbox')
			. '">' . lang('You have %1 new message' . ($db->f('msg_cnt') > 1 ? 's' : ''), $db->f('msg_cnt')) . '</a>'
			. '</div>';
	}
}
