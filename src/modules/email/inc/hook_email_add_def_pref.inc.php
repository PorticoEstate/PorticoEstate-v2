<?php

/**
 * EMail - Preferences hook
 *
 * @copyright Copyright (C) 2003-2005 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @package email
 * @subpackage hooks
 * @version $Id$
 */


$preferences = \App\modules\phpgwapi\services\Preferences::getInstance();
$preferences->add("email", "mainscreen_showmail", "True");
$preferences->add("email", "use_trash_folder", "False");
$preferences->add("email", "default_sorting", "old_new");
$preferences->add("email", "show_addresses", "from");
$preferences->add("email", "email_sig", "");

