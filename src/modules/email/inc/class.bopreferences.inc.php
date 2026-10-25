<?php

/**
 * EMail - Preferences
 *
 * @author Angelo (Angles) Puglisi <angles@aminvestments.com>
 * @author Mark Peters <skeeter@phpgroupware.org>
 * @copyright Copyright (C) 2001-2002 Angelo Tony Puglisi (Angles)
 * @copyright Copyright (C) 2003-2005 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @package email
 * @version $Id$
 * @internal Based on AngleMail http://www.anglemail.org/
 */

/**
 * Preferences
 *
 * If prefs are submitted that alter anything relative to a cached data item, 
 * the cached item MUST BE EXPIRED, for example
 * GLOBALS[phpgw]->msg->expire_session_cache_item("mailserver_callstr")
 * @package email
 */
class email_bopreferences
{
	var $public_functions = array(
		'preferences' => True,
		'init_available_prefs' => True,
		'grab_set_prefs' => True,
		'ex_accounts_edit' => True,
		'ex_accounts_list' => True,
		'ex_accounts_delete' => True
	);
	var $msg_bootstrap;
	// convience reference to the msg object
	var $msg = '##NOTHING##';
	var $not_set = '-1';
	var $std_prefs = array();
	var $cust_prefs = array();
	var $submit_token = 'submit_prefs';
	var $submit_token_extra_accounts = 'submit_prefs_extra_accounts';
	var $submit_token_delete_ex_account = 'submit_prefs_delete_ex_account';
	var $add_new_account_token = 'add_new';

	// possible values: "default" or "extra_accounts"
	var $account_group = 'default';
	var $acctnum = '';

	// were we called from phpgroupware ("phpgw")or externally via xml-rpc ("xmlrpc")
	var $caller = 'phpgw';
	var $pref_errors = '';
	var $args = array();
	var $debug_set_prefs = 0;

	public function __construct()
	{
		/*!
			@capability initialize class mail_msg object but do not login
			@abstract we need functions in class mail_msg but we not want a login 
			@author Angles
			@discussion we need mail_msg fully initialized to set prefs, but we
			do not need class_dcom, nor do we need to login, this is how to do it:
			@example $GLOBALS["phpgw"]->msg_bootstrap = CreateObject("email.msg_bootstrap");
			$GLOBALS["phpgw"]->msg_bootstrap->set_do_login(False);
			$GLOBALS["phpgw"]->msg_bootstrap->ensure_mail_msg_exists("name of this calling function", $this->debug_set_prefs);
			*/
		$this->msg_bootstrap = CreateObject("email.msg_bootstrap");
		//$this->msg_bootstrap->set_do_login(False);
		// USE NEW login instructions, defined in bootstrap class
		$this->msg_bootstrap->set_do_login(BS_LOGIN_NEVER);
		$this->msg_bootstrap->ensure_mail_msg_exists('email.bopreferences. *constructor*', $this->debug_set_prefs);
		// make the convience reference
		if ($this->msg == '##NOTHING##')
		{
			$this->msg = \App\modules\email\services\MailMessage::getInstance();
		}
		//return;
	}

	/*!
		@function init_available_prefs
		@abstract Loads available email preferences into $this->std_prefs[] and $this->cust_prefs[]
		@result none, this function populates the class vars from EmailPreferenceSchema
		@param $this->std_prefs[] class array holds all Standard Preferences available for email, 
		@param $this->cust_prefs[] class array holds all Custom Preferences available for email
		@author Angles
		@discussion This legacy entry point delegates preference definitions to EmailPreferenceSchema.
		Preferences are stored in a dynamic database, so their schema is defined in the service rather
		than at the database level.
		Also, $this->std_prefs[] and $this->cust_prefs[] arrays can be used to build a UI for managing and 
		showing these prefs, and those arrays can be looped through for the setting and storing of these preferences. 
		@access public
		@example ## sample usages of the "init_default" property 
			[init_default] comma seperated, first word is an instructional token
			--possible tokens are--
			string		[any_string]  ex. 'string,new_old'
			set_or_not	[set|not_set]  ex.  'set_or_not,not_set'
			function	[string_will_be_eval'd] ex. 'function,$this->sub_default_userid($accountid)'
			init_no_fill	we will not fill this item during initialization (ex. a password)
			varEVAL	[string_to_eval] ex. "$GLOBALS['phpgw_info']['server']['mail_server']"
		*/
	function init_available_prefs()
	{
		$schema = (new \App\modules\email\services\EmailPreferenceSchema())->fields();
		$this->std_prefs = $schema['standard'];
		$this->cust_prefs = $schema['custom'];

		if ($this->debug_set_prefs > 3)
		{
			$this->debug_dump_prefs();
		}
	}

	function debug_dump_prefs()
	{
		// DEBUG begin
		//Header('Location: ' . phpgw::link('/preferences/index.php'));
		//return;
		// DEBUG end
	}

	/*!
		@function expire_related_cached_items
		@abstract change in any preferences may require Expiring Cached Data for several things
		@param (int) acctnum 
		@discussion EXPIRE ANY CACHED ITEM THAT WAS DERIVED FROM A CHANGED PREF ITEM. 
		We should be precise and only expire if necessary, but for now just expire any cached item that could 
		be effected by a change in preferences. NOTE: we locate this after we have obtained a reliable 
		acctnum which these prefs apply to.  Currently expires these things, mailsvr_callstr, mailsvr_namespace, 
		mailsvr_delimiter, and folder_list. Note that "folder_list" requires expiration here because the elements that 
		make up all the folder long, or fully qualified, folder name probably have changed too. 
		@author Angles
		*/
	function expire_related_cached_items($acctnum = '')
	{
		if ((!isset($acctnum))
			|| ((string)$acctnum == '')
		)
		{
			$acctnum = $this->acctnum;
		}

		\App\modules\email\services\MailMessage::getInstance()->expire_session_cache_item('mailsvr_callstr', $acctnum);
		\App\modules\email\services\MailMessage::getInstance()->expire_session_cache_item('mailsvr_namespace', $acctnum);
		\App\modules\email\services\MailMessage::getInstance()->expire_session_cache_item('mailsvr_delimiter', $acctnum);
		// DAAA! the folder list probably also changes if the components that make up the Fully Qualified folder name have changed
		\App\modules\email\services\MailMessage::getInstance()->expire_session_cache_item('folder_list', $acctnum);
		// NEW: now we cache all the prefs in bulk cache in the appsession
		$my_location = '0;cached_prefs';
		\App\modules\email\services\MailMessage::getInstance()->so->so_appsession_passthru($my_location, ' ');
	}

	/*!
		@function grab_set_prefs_args
		@abstract calls either (a) grab_set_prefs_args_gpc or (b) grab_set_prefs_args_xmlrpc depending
		on if this class was called from within phogw or via external XMP-RPC. If neither,
		we should produce an error.
		@param (none) However, function uses class var ->caller (string) with expected values being "phpgw" and "xmlrpc".
		@author Angles
		@access Public
		*/
	function grab_set_prefs()
	{
		// better make sure we have created the available prefs schema
		$this->init_available_prefs();

		if ($this->caller == 'phpgw')
		{
			$this->grab_set_prefs_args_gpc();
		}
		elseif ($this->caller == 'xmlrpc')
		{
			$this->grab_set_prefs_args_xmlrpc();
		}
		else
		{
			$this->pref_errors .= 'email: bopreferences: grab_set_prefs: unsupported "caller" variable<br />';
		}
	}

	/*!
		@function grab_set_prefs_args_gpc
		@abstract Called By "grab_set_prefs", only handles GPC vars that are involved in setting email 
		preferences. Grabs data from $GLOBALS['phpgw']->msg->ref_POST and $GLOBALS['phpgw']->msg->ref_GET
		as necessaey, and fills various class arg variables with the available data. HOWEVER, does 
		not attempt to grab data if the "submit_prefs" GPC submit_token variable is not present.
		@param none
		@result none, this is an object call
		@discussion  For abstraction from phpgw UI and from PHP's GPC data, put the submitted GPC data
		into a class var $this->args[] array. This array is then used to represent the submitted data, 
		instead of $GLOBALS['phpgw']->msg->ref_POST.  
		This serves to further seperate the mail functionality from php itself, this function will perform
		the variable handling of the traditional php page view Get Post Cookie (no cookie data used here though)
		The same data could be grabbed from any source, XML-RPC for example, insttead of php's GPC vars,
		so this function could (should) have an equivalent XML-RPC "to handle filling these class variables
		from an alternative source. These class vars are only relevant to setting email prefs.
		@author Angles
		@access Private
		*/
	function grab_set_prefs_args_gpc()
	{
		// ----  HANDLE GRABBING PREFERENCE GPC HTTP_POST_VARS ARGS  -------
		// for abstraction from phpgw UI and from PHP's GPC data, put the submitted GPC data
		// into a class var $this->args[] array. This array is then used to represent the submitted
		// data, instead of $GLOBALS['phpgw']->msg->ref_POST. 
		// HOWEVER, do not attempt to grab data if the "submit_prefs" GPC submit_token variable is not present

		// ----  DEFAULT EMAIL ACCOUNT  ----
		if (isset(\App\modules\email\services\MailMessage::getInstance()->ref_POST[$this->submit_token]))
		{

			// EXPIRE stuff that may get stale by changing prefs
			$this->expire_related_cached_items(0);

			//$this->args['submit_prefs'] = $GLOBALS['phpgw']->msg->ref_POST['submit_prefs'];
			$this->args[$this->submit_token] = \App\modules\email\services\MailMessage::getInstance()->ref_POST[$this->submit_token];
			// standard prefs
			$loops = count($this->std_prefs);
			for ($i = 0; $i < $loops; $i++)
			{
				// ----  skip this item logic  ----
				// we are ONLY concerned with items that apply to the default email account
				// existence of $this->submit_token indicates this data is intended for the default email account
				if (!stristr($this->std_prefs[$i]['accts_usage'], 'default'))
				{
				}
				else
				{
					// ok, we have a pref item that applies to the default email account
					$this_pref_name = $this->std_prefs[$i]['id'];
					if (isset(\App\modules\email\services\MailMessage::getInstance()->ref_POST[$this_pref_name]))
					{
						$this->args[$this_pref_name] = \App\modules\email\services\MailMessage::getInstance()->ref_POST[$this_pref_name];
					}
				}
			}
			// custom prefs
			$loops = count($this->cust_prefs);
			for ($i = 0; $i < $loops; $i++)
			{
				// ----  skip this item logic  ----
				// we are ONLY concerned with items that apply to the default email account
				// existence of $this->submit_token indicates this data is intended for the default email account
				if (!stristr($this->cust_prefs[$i]['accts_usage'], 'default'))
				{
				}
				else
				{
					// ok, we have a pref item that applies to the default email account
					$this_pref_name = $this->cust_prefs[$i]['id'];
					if (isset(\App\modules\email\services\MailMessage::getInstance()->ref_POST[$this_pref_name]))
					{
						$this->args[$this_pref_name] = \App\modules\email\services\MailMessage::getInstance()->ref_POST[$this_pref_name];
					}
				}
			}
		}
		// ----  EXTRA EMAIL ACCOUNTS  ----
		elseif (isset(\App\modules\email\services\MailMessage::getInstance()->ref_POST[$this->submit_token_extra_accounts]))
		{

			//$this->args['submit_prefs'] = $GLOBALS['phpgw']->msg->ref_POST['submit_prefs'];
			$this->args[$this->submit_token_extra_accounts] = \App\modules\email\services\MailMessage::getInstance()->ref_POST[$this->submit_token_extra_accounts];

			// ==== ACCTNUM ====
			if ((!isset($this->acctnum))
				|| ((string)$this->acctnum == '')
			)
			{
				$this->acctnum = $this->obtain_ex_acctnum();
			}

			// EXPIRE stuff that may get stale by changing prefs
			$this->expire_related_cached_items($this->acctnum);

			// standard prefs
			$loops = count($this->std_prefs);
			for ($i = 0; $i < $loops; $i++)
			{
				// ----  skip this item logic  ----
				// we are ONLY concerned with items that apply to EXTRA email accounts
				// existence of "$this->submit_token_extra_accounts" indicates this data is intended for 
				// extra email accounts
				if (!stristr($this->std_prefs[$i]['accts_usage'], 'extra_accounts'))
				{
				}
				else
				{
					// ok, we have a pref item that applies to the default email account
					$this_pref_name = $this->std_prefs[$i]['id'];
					if (isset(\App\modules\email\services\MailMessage::getInstance()->ref_POST[$this->acctnum][$this_pref_name]))
					{
						$this->args[$this->acctnum][$this_pref_name] = \App\modules\email\services\MailMessage::getInstance()->ref_POST[$this->acctnum][$this_pref_name];
					}
				}
			}
			// custom prefs
			$loops = count($this->cust_prefs);
			for ($i = 0; $i < $loops; $i++)
			{
				// ----  skip this item logic  ----
				// we are ONLY concerned with items that apply to EXTRA email accounts
				// existence of "$this->submit_token_extra_accounts" indicates this data is intended for 
				// extra email accounts
				if (!stristr($this->cust_prefs[$i]['accts_usage'], 'extra_accounts'))
				{
				}
				else
				{
					// ok, we have a pref item that applies to extra email accounts
					$this_pref_name = $this->cust_prefs[$i]['id'];
					if (isset(\App\modules\email\services\MailMessage::getInstance()->ref_POST[$this->acctnum][$this_pref_name]))
					{
						$this->args[$this->acctnum][$this_pref_name] = \App\modules\email\services\MailMessage::getInstance()->ref_POST[$this->acctnum][$this_pref_name];
					}
				}
			}
		}
	}

	/*!
		@function grab_set_prefs_args_xmlrpc
		@abstract Called By "grab_set_prefs", Grabs data an XML-RPC call and fills various class arg variables 
		with the available data relevant to setting email preferences.
		@param none
		@result none, this is an object call
		@discussion functional relative to function "grab_set_prefs_args_gpc()", except this function grabs the
		data from an alternative, non-php-GPC, source
		NOT YET IMPLEMENTED
		@author Angles
		@access Private
		*/
	function grab_set_prefs_args_xmlrpc()
	{
		// STUB, for future use
	}

	/*!
		@function process_submitted_prefs
		@abstract Process incoming submitted prefs, process the data, and save to repository 
		if needed. Currently used for processing email preferences, both standard and custom
		@param $pref_set (array) structured pref data as defined and supplied in "this->init_available_prefs()"
		@result boolean False if no $pref_set was supplied, True otherwise
		@discussion Delegates field persistence to EmailPreferencePersister.
		@author Angles
		@access Private
		*/
	function process_submitted_prefs($prefs_set = '')
	{
		if (!$prefs_set)
		{
			$prefs_set = array();
		}
		if (count($prefs_set) == 0)
		{
			return False;
		}

		(new \App\modules\email\services\EmailPreferencePersister())->writeFields(
			$prefs_set,
			$this->args,
			\App\modules\phpgwapi\services\Preferences::getInstance(),
			\App\modules\email\services\MailMessage::getInstance(),
			null,
			true,
			false
		);
		return True;
	}

	/*!
		@function preferences
		@abstract Call this function to process submitted prefs. It makes use of other class functions
		some of which should not be called directly.
		@author skeeter, Angles
		@access Public
		*/
	function preferences($api_mode = False)
	{
		// establish all available prefs for email
		$this->init_available_prefs();

		// this will fill $this->args[] array with any submitted prefs args
		$this->grab_set_prefs();

		// ----  HANDLE SETING PREFERENCE   -------
		if (isset($this->args[$this->submit_token]))
		{
			// is set_magic_quotes_runtime(0) done here or somewhere else
			//set_magic_quotes_runtime(0);

			// constructor will initialize $GLOBALS['phpgw']->msg

			// ---  Process Standard Prefs  ---
			$this->process_submitted_prefs($this->std_prefs);

			// ---  Process Custom Prefs  ---
			if (isset($this->args['use_custom_settings']))
			{
				// custom settings are in use, process them
				$this->process_submitted_prefs($this->cust_prefs);
			}
			else
			{
				// custom settings are NOT being used, DELETE them from the repository
				$c_prefs = count($this->cust_prefs);
				for ($i = 0; $i < $c_prefs; $i++)
				{
					\App\modules\phpgwapi\services\Preferences::getInstance()->delete('email', $this->cust_prefs[$i]['id']);
				}
			}

			// DONE processing prefs, SAVE to the Repository
			if ($this->debug_set_prefs > 1)
			{
			}
			else
			{
				\App\modules\phpgwapi\services\Preferences::getInstance()->save_repository();
			}
			// end the email session
			\App\modules\email\services\MailMessage::getInstance()->end_request();
			if ($api_mode) return True;

			// redirect user back to main preferences page
			$take_me_to_url = phpgw::link(
				'/preferences/index.php'
			);

			if ($this->debug_set_prefs > 1)
			{
			}
			else
			{
				Header('Location: ' . $take_me_to_url);
			}
		}

		// DEPRECIATED CODE follows, but do not delete yet, it has useful comments.
		/*
				// these are the standard (non-custom) email options
				// that do NOT hold user-entered strings as their values
				$prefs = array(
					'default_sorting',
					'layout',
					'show_addresses',
					'mainscreen_showmail',
					'use_sent_folder',
					'use_trash_folder',
					'enable_utf7'
				);
				$c_prefs = count($prefs);
				for($i=0;$i<$c_prefs;$i++)
				{
					\App\modules\phpgwapi\services\Preferences::getInstance()->delete('email',$prefs[$i]);
					if (isset($this->args[$prefs[$i]])
						&& $this->args[$prefs[$i]] != '')
					{
						\App\modules\phpgwapi\services\Preferences::getInstance()->add('email',$prefs[$i],$this->args[$prefs[$i]]);
					}
				}
				// these are the standard (non-custom) email options
				// that each DO hold a user-entered strings as their value
				$prefs = array(
					'email_sig',
					'trash_folder_name',
					'sent_folder_name'
				);
				$c_prefs = count($prefs);
				for($i=0;$i<$c_prefs;$i++)
				{
					\App\modules\phpgwapi\services\Preferences::getInstance()->delete('email',$prefs[$i]);
					if(isset($this->args[$prefs[$i]]))
					{
						$temp_var = $email_base->stripslashes_gpc($this->args[$prefs[$i]]);
						if($i == 0)
						{
							$temp_var = $email_base->html_quotes_encode($temp_var);
						}
						\App\modules\phpgwapi\services\Preferences::getInstance()->add('email',$prefs[$i],$temp_var);
					}
					else
					{
						switch($i)
						{
							case 1:
								$temp_var = 'Trash';
								break;
							case 2:
								$temp_var = 'Sent';
								break;
						}
						\App\modules\phpgwapi\services\Preferences::getInstance()->add('email',$prefs[$i],$temp_var);
					}
				}
				// these are the "custom" email options, here handle both user-entered strings
				// and non user-entered string options in the same proc
				// also, the password is handled seperately below
				$prefs = array(
					'use_custom_settings',
					'userid',
					'address',
					'mail_server',
					'mail_server_type',
					'imap_server_type',
					'mail_folder'
				);
				// NOTE: it is possible that a user-entered string, particularly the "mail_folder" pref
				// may contain certain chars, such as slashes, quotes, etc..., which (a)  may need to be
				// run through "stripslashes_gpc" and or (b) may be database-unfriendly chars
				// which *may* need to be encoded IF these bad chars are not escaped or otherwise de-fanged
				// at the preference class level or the database class level.
				// UNKNOWN at present (11-30-2001) if this is still an issue (it was in 0.9.12) ed: Angles
				$c_prefs = count($prefs);
				\App\modules\phpgwapi\services\Preferences::getInstance()->delete('email',$prefs[0]);
				if (!isset($this->args[$prefs[0]]))
				{
					// use is NOT using custom settings, so delete them all from the repository
					for($i=1;$i<$c_prefs;$i++)
					{
						\App\modules\phpgwapi\services\Preferences::getInstance()->delete('email',$prefs[$i]);
					}
					// and also the passwd, which is not in that array above because it gets special handling
					\App\modules\phpgwapi\services\Preferences::getInstance()->delete('email','passwd');
				}
				else
				{
					// custom prefs ARE in use
					\App\modules\phpgwapi\services\Preferences::getInstance()->add('email',$prefs[0],$this->args[$prefs[0]]);
					for($i=1;$i<$c_prefs;$i++)
					{
						// if ((isset($email_base->args[$check_array[$i]])) && ($email_base->args[$check_array[$i]] != ''))
						if ((isset($this->args[$prefs[$i]]))
						&& ($this->args[$prefs[$i]] != ''))
						{
							// user has specified a value for this particular email custom option
							\App\modules\phpgwapi\services\Preferences::getInstance()->add('email',$prefs[$i],$this->args[$prefs[$i]]);
						}
						else
						{
							// user did not supply a value for this particular custom option,
							// so the user wants to use the phpgwapi supplied value instead, 
							// We accomplished by entirely removing (no key, no value) this pref
							// from the repository, so next time function "create_email_preferences"
							// is called, it knows by the lack of the existence of a custom particular
							// custom option to use the server supplied default instead for that item
							\App\modules\phpgwapi\services\Preferences::getInstance()->delete('email',$prefs[$i]);
						}
					}
					if (isset($this->args['passwd'])
					&& $this->args['passwd'] != '')
					{
						//@capability: set and unset a custom email password preference
						//@discussion:  an email password is NEVER sent to the client UI from the server
						//so this option shows up as en empty value in the UI
						//These senarios are possible here:
						//(1) user submits an empty passwd pref AND user already has a custom passwd set
						//then the previous, existing users custom passwd is left UNMOLESTED, as-is, untouched.
						//(2) user does submit a password, then this gets "encrypted" (depends on existence of mcrypt or not)
						//and put in the repository.
						//This minimizes passwd from traveling thru the ether.
						//(3) user wants to delete an existing custom passwd from the repository,
						//the user must (a) uncheck "use custom preferences", and (b) submit that page,
						//which clears ALL custom options. Now if the user leter checks "use custom preferences"
						//but does NOT fill in a custom passwd, the user's phpgw login password will be used
						//as the email server password, following the concept that unfilled custom options
						//get a phpgw system default value.
						\App\modules\phpgwapi\services\Preferences::getInstance()->delete('email','passwd');
						\App\modules\phpgwapi\services\Preferences::getInstance()->add('email','passwd',$email_base->encrypt_email_passwd($email_base->stripslashes_gpc($this->args['passwd'])));
					}
				}
				if ($this->debug_set_prefs > 1) 
				{
					echo 'email.bopreferences: *debug* skipping save_repository<br />';
				}
				else
				{
					\App\modules\phpgwapi\services\Preferences::getInstance()->save_repository();
				}
				$email_base->end_request();
			}
			if ($this->debug_set_prefs > 1) 
			{
				echo 'email.bopreferences: *debug* skipping Header redirection<br />';
			}
			else
			{
				Header('Location: ' . phpgw::link('/preferences/index.php'));
			}
			*/
	}

	/*!
		@function process_ex_account_submitted_prefs
		@abstract Extra Email Accounts Process incoming submitted prefs, process the data, and save to repository 
		@author Angles
		@access Private
		*/
	function process_ex_accounts_submitted_prefs($prefs_set = '')
	{
		if (!$prefs_set)
		{
			$prefs_set = array();
		}
		if (count($prefs_set) == 0)
		{
			return False;
		}

		if ((!isset($this->acctnum)) || ((string)$this->acctnum == ''))
		{
			$this->acctnum = $this->obtain_ex_acctnum();
		}

		$wroteValue = (new \App\modules\email\services\EmailPreferencePersister())->writeFields(
			$prefs_set,
			$this->args[$this->acctnum] ?? array(),
			\App\modules\phpgwapi\services\Preferences::getInstance(),
			\App\modules\email\services\MailMessage::getInstance(),
			(int) $this->acctnum,
			true,
			false
		);
		if ($wroteValue)
		{
			\App\modules\phpgwapi\services\Preferences::getInstance()->sort_struct('email', '["ex_accounts"]');
		}
		return True;
	}

	/*!
		@function ex_accounts_delete
		@abstract delete an extra email account
		@param $acctnum(int) the account number of the account to delete
		@author Angles
		@discussion ?
		@access private
		*/
	function ex_accounts_delete($acctnum = '', $api_mode = False)
	{

		$this->account_group = 'extra_accounts';

		if ((isset($acctnum))
			|| ((string)$acctnum != '')
		)
		{
			$this->acctnum = $acctnum;
		}

		if ((!isset($this->acctnum))
			|| ((string)$this->acctnum == '')
		)
		{
			$acctnum = $this->obtain_ex_acctnum();
			$this->acctnum = $acctnum;
		}

		$actually_did_something = False;

		if ((isset($this->acctnum))
			&& ((string)$this->acctnum != '')
		)
		{

			// delete the extra account pref item
			$pref_struct_str = '["ex_accounts"][' . $this->acctnum . ']';
			\App\modules\phpgwapi\services\Preferences::getInstance()->delete_struct('email', $pref_struct_str);

			// let the code below this block know we actually did something that requires saving the repository
			$actually_did_something = True;
		}

		// DONE with delete pref, SAVE to the Repository
		if (!$actually_did_something)
		{
			// nothing happened above that requires saving the repository
		}
		elseif ($this->debug_set_prefs > 2)
		{
			// we actually did something that requires saving repository, but are we in debug mode
		}
		else
		{
			// we actually did something that requires saving repository, and we have the go-ahead
			\App\modules\phpgwapi\services\Preferences::getInstance()->save_repository();
		}
		// end the email session
		if (\App\modules\email\services\MailMessage::hasInstance())
		{
			\App\modules\email\services\MailMessage::getInstance()->end_request();
		}
		if ($api_mode) return $actually_did_something;
		// redirect user back to main preferences page
		if ($this->debug_set_prefs > 2)
		{
		}
		else
		{
			$take_me_to_url = phpgw::link('/email/view/accounts');

			Header('Location: ' . $take_me_to_url);
		}
	}

	/*!
		@function ex_accounts_edit
		@abstract Extra Email Account Data process submitted prefs. It makes use of other class functions
		some of which should not be called directly, call this function in menuaction.
		@author Angles
		@access Public
		*/
	function ex_accounts_edit($acctnum = '', $api_mode = False)
	{

		// ==== ACCTNUM ====
		// this tells people that we are dealing with the extra email accounts
		$this->account_group = 'extra_accounts';
		if ((!isset($acctnum))
			|| ((string)$acctnum == '')
		)
		{
			$acctnum = $this->obtain_ex_acctnum();
			$this->acctnum = $acctnum;
		}
		else
		{
			$this->acctnum = $acctnum;
		}

		$actually_did_something = False;

		// --- Add/Modify Email Extra Account Prefs? ----

		// establish all available prefs for email
		$this->init_available_prefs();

		// this will fill $this->args[] array with any submitted prefs args
		$this->grab_set_prefs();

		// ----  HANDLE SETING PREFERENCE   -------
		if (isset($this->args[$this->submit_token_extra_accounts]))
		{
			// let the code below this block know we actually did something that requires saving the repository
			$actually_did_something = True;

			// is set_magic_quotes_runtime(0) done here or somewhere else
			//set_magic_quotes_runtime(0);

			// constructor will (has taken care of) initialize $GLOBALS['phpgw']->msg

			// ---  Process Standard Prefs  ---
			$this->process_ex_accounts_submitted_prefs($this->std_prefs);

			// ---  Process Custom Prefs  ---
			// CUSTOM PREFS ARE MANDATORY! FOR EXTRA ACCOUNTS
			// first, delete whatever value was there for "use custom settings" (during pre-release, at times this actually was an option, make sure it's gone grom the db)
			$pref_struct_str = '["ex_accounts"][' . $this->acctnum . ']["' . $this->cust_prefs[0]['id'] . '"]';
			\App\modules\phpgwapi\services\Preferences::getInstance()->delete_struct('email', $pref_struct_str);

			$this->process_ex_accounts_submitted_prefs($this->cust_prefs);

			/*
				// ---  Process Custom Prefs  ---
				// if they were not mandatory, but that does not work
				if ($this->debug_set_prefs > 1) { echo 'email.bopreferences.ex_accounts_edit(): about to process Custom Prefs<br />'; }
				if (isset($this->args['use_custom_settings']))
				{
					// custom settings are in use, process them
					if ($this->debug_set_prefs > 1) { echo 'email.bopreferences.ex_accounts_edit(): custom prefs are in use<br />'; }
					$this->process_ex_accounts_submitted_prefs($this->cust_prefs);
				}
				else
				{
					// custom settings are NOT being used, DELETE them from the repository
					$c_prefs = count($this->cust_prefs);			
					if ($this->debug_set_prefs > 1) { echo 'email.bopreferences.ex_accounts_edit(): custom prefs NOT in use, deleting them<br />'; }
					for($i=0;$i<$c_prefs;$i++)
					{
						$pref_struct_str = '["ex_accounts"]['.$this->acctnum.']["'.$this->cust_prefs[$i]['id'].'"]';
						if ($this->debug_set_prefs > 1) { echo ' ** (looping) email: bopreferences.ex_accounts_edit(): using preferences->delete_struct("email", $pref_struct_str) which will eval $pref_struct_str='.$pref_struct_str.'<br />'; }
						\App\modules\phpgwapi\services\Preferences::getInstance()->delete_struct('email',$pref_struct_str);
					}
				}
				*/
		}

		// DONE processing prefs, SAVE to the Repository
		if (!$actually_did_something)
		{
			// nothing happened above that requires saving the repository
		}
		elseif ($this->debug_set_prefs > 1)
		{
			// we actually did something that requires saving repository, but are we in debug mode
		}
		else
		{
			// we actually did something that requires saving repository, and we have the go-ahead
			\App\modules\phpgwapi\services\Preferences::getInstance()->save_repository();
		}

		// end the email session
		if (\App\modules\email\services\MailMessage::hasInstance())
		{
			\App\modules\email\services\MailMessage::getInstance()->end_request();
		}
		if ($api_mode) return $actually_did_something;

		// redirect user back to main preferences page
		$take_me_to_url = phpgw::link('/email/view/accounts');

		if ($this->debug_set_prefs > 1)
		{
		}
		else
		{
			Header('Location: ' . $take_me_to_url);
		}
	}

	/*!
		@function ex_accounts_list
		@abstract list Extra Email Accounts with links to edit and or delete them.
		@author Angles
		@access Public
		*/
	function ex_accounts_list()
	{

		$extra_accounts = \App\modules\email\services\MailMessage::getInstance()->extra_accounts;
		// list accounts, except "empty" ones (show "enabled" and "disabled"
		$return_list = array();
		foreach ($extra_accounts as $extra_account)
		{
			$this_acctnum = $extra_account['acctnum'];
			$this_status = $extra_account['status'];

			if ($this_status == 'empty')
			{
			}
			else
			{
				$next_pos = count($return_list);
				//$next_pos = $this_acctnum - 1;
				$return_list[$next_pos]['acctnum'] = $this_acctnum;
				$return_list[$next_pos]['status'] = $this_status;

				// FIRST get a usable "accountname" string to show the user and make "go_there_url" and "go_there_href"
				/*!
					@capability boprefs ex_accounts_list what is the string name of this account
					@abstract HOW TO IDENTIFY THIS ACCOUNT to the user
					@discussion We recently added a pref for "account_name" which is seperate from 
					"fullname". At this moment 021019 this "account_name" is not guarantee to be filled. 
					So we fallback here to the "fullname" if necessary. Disabled accounts, at this moment 
					that really is not coded at all, but disabled accounts may not have their pref data 
					available via msg->get_pref_value. 
					*/
				if ($this_status == 'disabled')
				{
					// "disabled" accounts will not return a fullname because they were not initialized during "begin_request"
					// try to directly obtain it from RAW prefs data
					//$fullname = '(disabled) '.$GLOBALS['phpgw']->msg->unprocessed_prefs['email']['ex_accounts'][$this_acctnum]['fullname'];
					$accountname = \App\modules\email\services\MailMessage::getInstance()->unprocessed_prefs['email']['ex_accounts'][$this_acctnum]['account_name'];
					if ((isset($accountname) == False)
						|| (trim($accountname) == '')
					)
					{
						$accountname = \App\modules\email\services\MailMessage::getInstance()->unprocessed_prefs['email']['ex_accounts'][$this_acctnum]['fullname'];
					}
					// test again
					if ((isset($accountname) == False)
						|| (trim($accountname) == '')
					)
					{
						$accountname = \App\modules\email\services\MailMessage::getInstance()->get_pref_value('account_name', $this_acctnum);
					}
					// FIX ME test again, take care of this in the prefs reading like it should be, not here
					if ((isset($accountname) == False)
						|| (trim($accountname) == '')
					)
					{
						$accountname = 'unknown accountname on (' . __LINE__ . ')';
					}
					$accountname = '(disabled) ' . $accountname;

					// we can click "go" to not read mail of a disabled account
					$return_list[$next_pos]['go_there_url'] = '';
					$return_list[$next_pos]['go_there_href'] = '&nbsp;';
				}
				else
				{
					$accountname = \App\modules\email\services\MailMessage::getInstance()->get_pref_value('account_name', $this_acctnum);
					if ((isset($accountname) == False)
						|| (trim($accountname) == '')
					)
					{
						$accountname = \App\modules\email\services\MailMessage::getInstance()->get_pref_value('fullname', $this_acctnum);
					}
					// FIX ME test again, should we take care of this in the prefs reading, not here?
					if ((isset($accountname) == False)
						|| (trim($accountname) == '')
					)
					{
						$accountname = 'unknown accountname on (' . __LINE__ . ')';
					}

					$return_list[$next_pos]['go_there_url'] = phpgw::link('/email/view/inbox', array(
						'fldball[folder]' => 'INBOX',
						'fldball[acctnum]' => $this_acctnum
					));
					$return_list[$next_pos]['go_there_href'] = '<a href="' . $return_list[$next_pos]['go_there_url'] . '">' . lang('go') . '</a>';
				}
				// NEXT: html encode the acctname string
				// html encode entities on the fullname so it's safe to display in the browser, and prefix with the acctnum
				$accountname = \App\modules\email\services\MailMessage::getInstance()->htmlspecialchars_decode($accountname);
				$accountname = \App\modules\email\services\MailMessage::getInstance()->htmlspecialchars_encode($accountname);
				// FINALLY we have a string we are going to display to the user that is the name of the account
				$return_list[$next_pos]['display_string'] = '[' . $this_acctnum . '] ' . $accountname;

				// NEXT: control action links
				$return_list[$next_pos]['edit_url'] = phpgw::link('/email/view/account', array(
					'ex_acctnum' => $this_acctnum
				));
				$return_list[$next_pos]['edit_href'] = '<a href="' . $return_list[$next_pos]['edit_url'] . '">' . lang('Edit') . '</a>';

			}
		}

		return $return_list;
	}

	/*!
		@function get_first_empty_ex_acctnum
		@abstract Used in adding a new extra account, obtains a free acctnum
		@author Angles
		@access Public
		*/
	function get_first_empty_ex_acctnum()
	{
		$loops = count(\App\modules\email\services\MailMessage::getInstance()->extra_accounts);
		if ($loops == 0)
		{
			$first_empty_ex_acctnum = 1;
		}
		else
		{
			$did_get_acctnum = False;
			for ($i = 0; $i < $loops; $i++)
			{
				$this_acctnum = \App\modules\email\services\MailMessage::getInstance()->extra_accounts[$i]['acctnum'];
				$this_status = \App\modules\email\services\MailMessage::getInstance()->extra_accounts[$i]['status'];
				// loop =0 *would* = acctnum 1 *if* acctnum slots are filled in order, they'd always be 1 apart
				if ($this_status == 'empty')
				{
					$first_empty_ex_acctnum = (int)$this_acctnum;
					$did_get_acctnum = True;
					break;
				}
				elseif ((int)($i + 1) != (int)$this_acctnum)
				{
					$first_empty_ex_acctnum = (int)($i + 1);
					$did_get_acctnum = True;
					break;
				}
			}
			if ($did_get_acctnum == False)
			{
				// all slots taken, add +1 to last filled acctnum
				$first_empty_ex_acctnum = count(\App\modules\email\services\MailMessage::getInstance()->extra_accounts);
				// since extra accounts are not zero based, add one to that count to get real next available
				$first_empty_ex_acctnum++;
			}
		}
		return $first_empty_ex_acctnum;
	}

	/*!
		@function obtain_ex_acctnum
		@abstract Preferences handlers pass around the acctnum as POST or GET var "ex_acctnum".
		@author Angles
		@access Public
		*/
	function obtain_ex_acctnum()
	{
		// get fromPOST or GET
		$prelim_acctnum = '##NOTHING##';
		if ((isset(\App\modules\email\services\MailMessage::getInstance()->ref_POST['ex_acctnum'])
			&& ((string)\App\modules\email\services\MailMessage::getInstance()->ref_POST['ex_acctnum'] != '')))
		{
			$prelim_acctnum = (int)\App\modules\email\services\MailMessage::getInstance()->ref_POST['ex_acctnum'];
		}
		elseif ((isset(\App\modules\email\services\MailMessage::getInstance()->ref_GET['ex_acctnum'])
			&& ((string)\App\modules\email\services\MailMessage::getInstance()->ref_GET['ex_acctnum'] != '')))
		{
			$prelim_acctnum = (int)\App\modules\email\services\MailMessage::getInstance()->ref_GET['ex_acctnum'];
		}
		// in all these cases we don't have a valid acct num (or we are asked to make a new one)
		// so any of these requires a new, blank acctnum
		// NOTE: EXTRA ACCOUNTS CAN NEVER HAVE ACCNUM 0
		if ((!isset($prelim_acctnum))
			|| ($prelim_acctnum == $this->add_new_account_token)
			|| ($prelim_acctnum == '##NOTHING##')
			|| ((string)$prelim_acctnum == '')
			|| ((string)$prelim_acctnum == '0')
		)
		{
			// get the next blank acctnum
			$final_acctnum = $this->get_first_empty_ex_acctnum();
		}
		else
		{
			$final_acctnum = $prelim_acctnum;
		}
		return $final_acctnum;
	}
}
