<?php

use App\modules\phpgwapi\services\Cache;

/**
 * EMail - Message Data Storage for Caching Functions
 *
 * @author Angelo (Angles) Puglisi <angles@aminvestments.com>
 * @copyright Copyright (C) 2001-2003 Angelo Tony Puglisi (Angles)
 * @copyright Copyright (C) 2003-2005 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @package email
 * @internal Based on AngleMail http://www.anglemail.org/
 */

/**
 * Session-backed storage adapter for legacy mail initialization and preferences.
 * Message cache operations are handled directly by the mail wrapper.
 */
class so_mail_msg
{
	var $use_group_data = True;

	function so_am_table_exists()
	{
		return false;
	}

	function so_set_data($data_key, $content, $compression = False)
	{
		$keys = (array) Cache::session_get('email', 'mail_keys');
		$keys[(string) $data_key] = true;
		Cache::session_set('email', 'mail_keys', $keys);
		return Cache::session_set('email', (string) $data_key, $content);
	}

	function so_get_data($data_key, $compression = False)
	{
		return Cache::session_get('email', (string) $data_key) ?? false;
	}

	function so_clear_all_data_this_user()
	{
		foreach ((array) Cache::session_get('email', 'mail_keys') as $key => $unused)
		{
			Cache::session_set('email', (string) $key, null);
		}
		Cache::session_set('email', 'mail_keys', []);
		return \App\modules\email\services\MailMessage::getInstance()->resetSessionCache();
	}

	/**
	 * Retained for the legacy cache-mode initialization contract.
	 */
	function so_prop_use_group_data($feed_value = '##NOTHING##')
	{
		if ($feed_value !== '##NOTHING##')
        {
        	$this->use_group_data = (bool) $feed_value;
        }
		return $this->use_group_data;
	}

	/**
	 * Reads or writes session data when mail session caching is enabled.
	 * The compression argument is retained for existing callers.
	 */
	function so_appsession_passthru($location = '', $data = '##NOTHING##', $compression = False)
	{
		if (!\App\modules\email\services\MailMessage::getInstance()->session_cache_enabled)
        {
        	return false;
        }
		if ($data === '##NOTHING##')
        {
        	return $this->so_get_data($location);
        }
		return $this->so_set_data($location, $data);
	}
}
