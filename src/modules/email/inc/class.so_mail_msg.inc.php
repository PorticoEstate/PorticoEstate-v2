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
	* @version $Id$
	* @internal Based on AngleMail http://www.anglemail.org/
	*/


	/**
	* E-Mail Message Data Storage for Data Caching
	*
	* INTERFACE FUNCTIONS AND/OR  WRAPPER FUNCTIONS
	* @package email
	*/
	class so_mail_msg
	{
		var $use_group_data=True;
		//var $use_group_data=False;
		var $data_group_array=array();
		var $data_group_done_filled=0;
		// when something with a folder triggers gathering of group data
		// store that something here so we know what triggered gathering group data
		// because if "folder_status_info" is the trigger, it may not contain the
		// folder that is actually what we need later on, because "show number new
		// in combobox" will cache that for ALL folders, so a request for that
		// status info may to fill the combobox and have nothing to do with the
		// folder in which we are later going to need the group data.
		var $data_group_last_trigger = '';

		/*!
		@function so_mail_msg
		@abstract Constructor
		*/
		function __construct()
		{
			return;
		}

		/*!
		@cabability appsession TEMPORARY DATA CACHING - data we generate
		@abstract Caching via the the api appsession cache occurs in 2 basic forms.
		(ONE) information we are responsible for generating in this mail_msg class, and
		(TWO) data that the mail server sends us. This discussion is about ONE.
		@discussion Data this class must generate includes preferences, mail server callstring, namespace, delimiter,
		(not a complete list). The first time a data element is generated, for example ->get_arg_value("mailsvr_namespace"),
		which is needed before we can login to the mailserver, the private function "get_mailsvr_namespace" determines this
		string, then places that value in what I refer to as the "Level1 cache" (L1 cache) which is simply a class variable that is filled
		with that value. Additionally, that data is saved in the appsession cache. The L1 cache only exists as long as the script
		is run, usually one pageview. The appsession cache exists as long as the user is logged in. When the user requests
		another page view, private function ""get_mailsvr_namespace" checks (always) the L1 cache for this value, if this
		is the first time this function has been called for this pageview, that L1 cache does not yet exist. Then the functions
		checks the appsession cache for this value. In this example, it will find it there, put that value in the L1 cache, then
		return the value and exit. For the rest of the pageview, any call to this function will return the L1 cache value, no
		logic in that function is actually encountered.
		*/

		/*!
		@cabability appsession TEMPORARY DATA CACHING - data from the mailserver
		@abstract Caching via the the api appsession cache occurs in 2 basic forms.
		(ONE) information we are responsible for generating in this mail_msg class, and
		(TWO) data that the mail server sends us. This discussion is about TWO
		@discussion CACHE FORM TWO is anything the mail server sends us that we want to cache. The IMAP rfc requires we cache as much
		as we can so we do not ask the server for the same information unnecessarily. Take function "get_msgball_list" as an example.
		This is a list of messages in a folder, the list we save is in the form of typed array "msgball" which means the list included
		message number, full folder name, and account number.
		BEGIN DIGRESSION Why is all this data cached? Traditionally, a mail message has a
		"ball and chain" association with a particular mail server, a particular account on that mail server, and a particular folder
		within that account. This is the traditional way to think of email. HOWEVER, this email app desires to completely seperate
		an individual mail message from any of those traditional associations. So what does a "msgball" list allow us to so? This way
		we can move messages between accounts without caring where that account is located, what type of server its on, or what
		folder its in. We can have exotic search results where the "msgball" list contains references to messages from different
		accounts on different servers of different types in different folders therein. Because every peice of data about the message
		we need is stored in the typed array "msgball", we have complete freedom of movement and manipulation of those
		messages.  END DIGRESSION.
		So the function "get_msgball_list", the first time it is called for any particular folder, asks the mail server for a list of
		message UIDs (Unique Message ID good for as long as that message is in that folder), and assembles the "msgball" list
		by adding the associated account number and folder name to that message number. This list is then stored in the
		appsession cache. Being in the appsession cache means this data will persist for as long as the user is logged in.
		The data becomes STALE if 1. new mail arrives in the folder, or 2. messages are moved out of the folder.
		So the next pageview the user requests for that folder calls "get_msgball_list" which attempts to find the
		data stored in the appsession cache. If it is found cached there, the data is checked for VALIDITY during
		function "read_session_cache_item" which calls function "get_folder_status_info" and checks for 2 things,
		1. that this "msgball" is in fact referring to the same server, account, and folder as the newly requested data,
		and (CRUCIAL ITEM) 2. checks for staleness using the data returned from "get_folder_status_info", especially
		"uidnext", "uidvalidity", and "number_all" to determine if the data is stale or not. MORE ON THIS LATER. If the
		data is not stale and correctly refers to the right account, the "msgball" list stored in the appsession cache is used
		as the return value of "get_msgball_list" and THE SERVER IS NOT ASKED FOR THE MESSAGE LIST
		UNNECESSARILY. This allows for folders with thousands of messages to reduce client - server xfers dramatically.
		HOWEVER - this is an area where additional speed could be achieved by NOT VALIDIATING EVERY TIME, meaning
		we could set X minutes where the "msgball" list is considered NOT STALE. This eliminates a server login just to
		get validity information via "get_folder_status_info". HOWEVER, even though we have the message list for that
		folder in cache, we still must request the envelope info (from, to, subject, date) in order to show the index page.
		THIS DATA COULD BE CACHED TOO. Conclusion - you have seen how a massage list is cached, validated, and
		reused. Additionally, we have discussed ways to gain further speed with X minutes of assumed "freshness" and
		by caching envelope data. *UPDATE* AngleMail has begun to cache message structure and message envelope
		data, but this is under development. THIS DOC NEEDS UPDATING. CACHING HAS EXPANDED
		DRAMITICALLY.
		*/

		/*!
		@function so_save_session_cache_item
		@abstract SO Data Access only, logic is in main class mail_msg.
		@access private
		@author Angles
		*/
		function so_save_session_cache_item($data_name='misc',$data,$acctnum='',$extra_keys='')
		{
			return \App\modules\email\services\MailMessage::getInstance()->save_session_cache_item($data_name, $data, $acctnum, $extra_keys);
		}


		/*!
		@function so_read_session_cache_item
		@abstract SO Data Access functions only, actual logic is in main class mail_msg
		@access private
		@author Angles
		*/
		function so_read_session_cache_item($data_name='misc', $acctnum='', $ex_folder='', $ex_msgnum='')
		{
			return \App\modules\email\services\MailMessage::getInstance()->read_session_cache_item($data_name, $acctnum, $ex_folder, $ex_msgnum);
		}

		/*!
		@function so_expire_session_cache_item
		@abstract SO Data Access functions only, actual logic is in main class mail_msg
		@discussion ?
		@author Angles
		@access private
		*/
		function so_expire_session_cache_item($data_name='misc',$acctnum='', $ex_folder='', $ex_msgnum='')
		{
			return \App\modules\email\services\MailMessage::getInstance()->expire_session_cache_item($data_name, $acctnum, $ex_folder, $ex_msgnum);
		}


		/*!
		@function prep_db_session_compat
		@abstract for DB sessions_db storage, backward compatibility where php4 sessions are not in use.
		@discussion Imitates the session array that php4 sessions create. ALSO used when caching to
		the anglemail DB table whether php4 sessions are in use or not.
		Many of the caching functions operate on that session data array that is created
		with php4 sessions in use, and evolved to using looping in that array to speed
		certain things. As caching evolved to also be able to cache to the sessions_db
		table or the anglemail table, those existing functions kept that array centric approach, and this
		function is used to create an imitation array like the one they expect. The difference is that
		the data is not actually stored in php4 session, but either in sessions_db, or in the
		anglemail table if it exists. Practially, once data is retrieved from the database, it
		is put in this array, and kept there so if we need it again it is already in memory.
		This is similar to but better than the using php4 sessions as a caching store because
		php4 sessions load ALL data into that session array for every script run, whether you
		need it or not. Using this hybrid method, we still have that array (imitated here)
		to work with but only needed data is put into it. Also, this is part of the reason
		anglemail can use any of 3 different storage methods for the cache, php4 sessions,
		sessions_db, or the dedicated anglemail table, because the functions using the
		data have that similar array approach. Note that when php4 sessions are in use
		AND the anglemail table is used for caching, this function preforms the crucial
		action of making the imitation session array while keeping the actual cached
		data AWAY from the real php4 session array. Anything in that php4 session
		array will automatically be stored as session data, but using the table means
		we do not want that php4 session storage, only a familiar looking array to work with.
		This is done by creating the imitation array and having $GLOBALS['phpgw']->msg->ref_SESSION be a
		pointer to it, instead of having it point to the real GLOBALS[session] array tree.
		@author Angles
		*/
		function prep_db_session_compat($called_by='not_specified')
		{
			return;
		}

		/*!
		@function expire_db_session_bulk_data
		@abstract for DB sessions_db ONLY, backward compatibility where php4 sessions are not in use,
		Also with the anglemail table but calls lower level functions in that case.
		@discussion Aggressive way to wipe cached data with the database caching
		methods. Called by the higher level caching functions, this
		does a blanket delete of ALL cached data, BUT it does have code to save
		a few items that are not strictly cache items, such as  the "mailsvr_callstr",
		"folder_list", and "mailsvr_namespace" for the email account(s).
		@access Private
		@author Angles
		*/
		function expire_db_session_bulk_data($called_by='not_specified', $wipe_absolutely_everything=False)
		{
			$retained = [];
			foreach ((array) Cache::session_get('email', 'mail_keys') as $key => $unused)
			{
				$parts = explode(';', (string) $key);
				if (!$wipe_absolutely_everything && in_array($parts[1] ?? '', ['mailsvr_callstr', 'folder_list', 'mailsvr_namespace', 'cached_prefs'], true))
				{
					$retained[$key] = true;
					continue;
				}
				Cache::session_set('email', (string) $key, null);
			}
			Cache::session_set('email', 'mail_keys', $retained);
			return \App\modules\email\services\MailMessage::getInstance()->resetSessionCache(!$wipe_absolutely_everything);
		}

		// ==BEGIN== TEMP DATA STORE COMMANDS
		/*!
		@function so_am_table_exists
		@abstract ?
		@author Angles
		*/
		function so_am_table_exists()
		{
			return false;
		}

		// these bext functions will go inti the future SO class
		/*!
		@function so_set_data
		@abstract ?
		*/
		function so_set_data($data_key, $content, $compression=False)
		{
			$keys = (array) Cache::session_get('email', 'mail_keys');
			$keys[(string) $data_key] = true;
			Cache::session_set('email', 'mail_keys', $keys);
			return Cache::session_set('email', (string) $data_key, $content);
		}

		/*!
		@function so_get_data
		@abstract ?
		*/
		function so_get_data($data_key, $compression=False)
		{
			return Cache::session_get('email', (string) $data_key) ?? false;
		}

		/*!
		@function so_delete_data
		@abstract ?
		*/
		function so_delete_data($data_key)
		{
			return Cache::session_set('email', (string) $data_key, null);
		}

		/*!
		@function so_clear_all_data_this_user
		@abstract ?
		*/
		function so_clear_all_data_this_user()
		{
			foreach ((array) Cache::session_get('email', 'mail_keys') as $key => $unused)
			{
				Cache::session_set('email', (string) $key, null);
			}
			Cache::session_set('email', 'mail_keys', []);
			return \App\modules\email\services\MailMessage::getInstance()->resetSessionCache();
		}

		/*!
		@function so_prop_use_group_data
		@abstract Delphi style property function for "use_group_data"
		@discussion This Delphi style property function replaces the typical "get" and
		"set" function. No arg passed will return the current value. Passing False
		or an empty string sets "use_group_data" to False,
		passing anything else as an arg sets "use_group_data" to true.
		@author Angles
		*/
		function so_prop_use_group_data($feed_value='##NOTHING##')
		{
			if ($feed_value !== '##NOTHING##') $this->use_group_data = (bool) $feed_value;
			return $this->use_group_data;
		}

		/*!
		@function so_fill_data_group
		@abstract ?
		*/
		function so_fill_data_group($data_key_partial='', $make_like=True)
		{
			return false;
		}

		/*!
		@function so_have_data_group
		@abstract ?
		*/
		function so_have_data_group()
		{
			return false;
		}

		/*!
		@function so_clear_data_group
		@abstract ?
		*/
		function so_clear_data_group($data_key='')
		{
			$this->data_group_array = [];
			$this->data_group_done_filled = 0;
			return true;
		}

		/*!
		@function so_lookup_data_group
		@abstract ?
		*/
		function so_lookup_data_group($data_key, $do_stripslashes='')
		{
			return $this->so_get_data($data_key);
		}


		/*!
		@function so_appsession_passthru
		@abstract this will ONLY use the ACTUAL REAL APPSESSION of phpgwapi
		@param $location (string) in phpgwapi session speak this is the "name" of the information aka the
		key in a key value pair
		@param $location (string) OPTIONAL the value in the key value pair. Empty will erase I THINK the
		apsession data stored for the "name" aka the "location".
		@discussion This is a SIMPLE PASSTHRU for the real phpgwapi session call. This function will
		never use the anglemail table, it is intended for stuff we REALLY want to last only for one session.
		@author Angles
		*/
		function so_appsession_passthru($location='',$data='##NOTHING##', $compression=False)
		{
			if (!\App\modules\email\services\MailMessage::getInstance()->session_cache_enabled) return false;
			if ($data === '##NOTHING##') return $this->so_get_data($location);
			return $this->so_set_data($location, $data);
		}
	}
