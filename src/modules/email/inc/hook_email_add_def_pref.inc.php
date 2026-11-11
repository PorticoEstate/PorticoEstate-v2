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
$defaults = (new \App\modules\email\services\EmailPreferenceDefaults())->resolve([], 0);
$preferences->add("email", "default_sorting", $defaults['default_sorting']);
$preferences->add("email", "show_addresses", $defaults['show_addresses']);
$preferences->add("email", "email_sig", "");
