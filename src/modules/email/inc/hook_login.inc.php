<?php
	/**
	* EMail - Login hook
	*
	* @copyright Copyright (C) 2003-2005 Free Software Foundation, Inc. http://www.fsf.org/
	* @license http://www.gnu.org/licenses/gpl.html GNU General Public License
	* @package email
	* @subpackage hooks
	* @version $Id$
	*/

foreach ((array) \App\modules\phpgwapi\services\Cache::session_get('email', 'mail_keys') as $key => $unused)
{
	\App\modules\phpgwapi\services\Cache::session_set('email', (string) $key, null);
}
	\App\modules\phpgwapi\services\Cache::session_set('email', 'mail_keys', []);
	\App\modules\phpgwapi\services\Cache::session_set('email', 'mail_state', []);
	\App\modules\phpgwapi\services\Cache::session_set('email', '0;cached_prefs', null);
	\App\modules\phpgwapi\services\Cache::session_set('email', 'notification_uid', null);
