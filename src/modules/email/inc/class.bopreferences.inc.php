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
 * Legacy preference request adapter.
 *
 * Schema, save orchestration, account allocation, and cache invalidation are
 * shared with the API. Legacy request parsing, redirects, and debug behavior
 * remain here.
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
	public object $msg;
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
		$this->msg = \App\modules\email\services\MailMessage::getInstance();
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

		$this->msg->invalidatePreferenceCache((int) $acctnum);
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
		$post = $this->msg->ref_POST;
		if (isset($post[$this->submit_token]))
		{
			$token = $this->submit_token;
			$scope = 'default';
			$values = $post;
		}
		elseif (isset($post[$this->submit_token_extra_accounts]))
		{
			if (!isset($this->acctnum) || (string) $this->acctnum === '')
			{
				$this->acctnum = $this->obtain_ex_acctnum();
			}
			$token = $this->submit_token_extra_accounts;
			$scope = 'extra_accounts';
			$values = $post[$this->acctnum] ?? array();
		}
		else
		{
			return;
		}

		$this->args[$token] = $post[$token];
		foreach (array_merge($this->std_prefs, $this->cust_prefs) as $field)
		{
			$id = $field['id'];
			if (stripos($field['accts_usage'], $scope) === false || !isset($values[$id]))
			{
				continue;
			}
			if ($scope === 'default')
			{
				$this->args[$id] = $values[$id];
			}
			else
			{
				$this->args[$this->acctnum][$id] = $values[$id];
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
		Unsupported; throws explicitly rather than ignoring updates.
		@author Angles
		@access Private
		*/
	function grab_set_prefs_args_xmlrpc()
	{
		throw new \BadMethodCallException('XML-RPC email preference updates are not supported');
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
		try
		{
			$this->grab_set_prefs();
			$submitted = isset($this->args[$this->submit_token]);
			if ($submitted)
			{
				(new \App\modules\email\services\EmailPreferencesWriter())->saveDefault(
					$this->std_prefs, $this->cust_prefs, $this->args,
					\App\modules\phpgwapi\services\Preferences::getInstance(), $this->msg,
					true, $this->debug_set_prefs <= 1
				);
			}
		}
		finally
		{
			$this->msg->end_request();
		}
		if ($submitted && $api_mode)
		{
			return True;
		}
		if ($submitted && $this->debug_set_prefs <= 1)
		{
			Header('Location: ' . phpgw::link('/preferences/index.php'));
		}
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
		try
		{
			$accounts = (array) ($this->msg->unprocessed_prefs['email']['ex_accounts'] ?? []);
			$value = $acctnum !== '' && $acctnum !== null ? $acctnum
				: ($this->msg->ref_POST['ex_acctnum'] ?? $this->msg->ref_GET['ex_acctnum'] ?? $this->acctnum);
			$this->acctnum = \App\modules\email\services\EmailPreferencesWriter::existingAccountNumber($value, $accounts);
			(new \App\modules\email\services\EmailPreferencesWriter())->deleteAccount(
				$this->acctnum, $accounts, \App\modules\phpgwapi\services\Preferences::getInstance(),
				$this->msg, $this->debug_set_prefs <= 2
			);
		}
		finally
		{
			$this->msg->end_request();
		}
		if ($api_mode)
		{
			return True;
		}
		if ($this->debug_set_prefs <= 2)
		{
			Header('Location: ' . phpgw::link('/email/view/accounts'));
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
		try
		{
			$this->account_group = 'extra_accounts';
			if ($acctnum === '' || $acctnum === null)
			{
				$this->acctnum = $this->obtain_ex_acctnum();
			}
			else
			{
				$this->acctnum = \App\modules\email\services\EmailPreferencesWriter::existingAccountNumber(
					$acctnum, (array) ($this->msg->unprocessed_prefs['email']['ex_accounts'] ?? [])
						+ [$this->get_first_empty_ex_acctnum() => []]
				);
			}
			$this->grab_set_prefs();
			$actually_did_something = isset($this->args[$this->submit_token_extra_accounts]);
			if ($actually_did_something)
			{
				(new \App\modules\email\services\EmailPreferencesWriter())->saveExtraAccount(
					(int) $this->acctnum, $this->std_prefs, $this->cust_prefs,
					$this->args[$this->acctnum] ?? array(),
					\App\modules\phpgwapi\services\Preferences::getInstance(), $this->msg,
					true, $this->debug_set_prefs <= 1
				);
			}
		}
		finally
		{
			$this->msg->end_request();
		}
		if ($api_mode) return $actually_did_something;

		// redirect user back to main preferences page
		$take_me_to_url = phpgw::link('/email/view/accounts');

		if ($this->debug_set_prefs <= 1)
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

		$extra_accounts = $this->msg->extra_accounts;
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

				$namePreferences = $this_status === 'disabled'
					? ($this->msg->unprocessed_prefs['email']['ex_accounts'][$this_acctnum] ?? array())
					: [
						'account_name' => $this->msg->get_pref_value('account_name', $this_acctnum),
						'fullname' => $this->msg->get_pref_value('fullname', $this_acctnum),
					];
				if ($this_status === 'disabled'
					&& trim((string) ($namePreferences['account_name'] ?? '')) === ''
					&& trim((string) ($namePreferences['fullname'] ?? '')) === '')
				{
					$namePreferences['account_name'] = $this->msg->get_pref_value('account_name', $this_acctnum);
				}
				$accountname = \App\modules\email\services\EmailAccountsSerializer::accountName($namePreferences);
				if ($this_status == 'disabled')
				{
					$accountname = '(disabled) ' . $accountname;

					// we can click "go" to not read mail of a disabled account
					$return_list[$next_pos]['go_there_url'] = '';
					$return_list[$next_pos]['go_there_href'] = '&nbsp;';
				}
				else
				{
					$return_list[$next_pos]['go_there_url'] = phpgw::link('/email/view/inbox', array(
						'fldball[folder]' => 'INBOX',
						'fldball[acctnum]' => $this_acctnum
					));
					$return_list[$next_pos]['go_there_href'] = '<a href="' . $return_list[$next_pos]['go_there_url'] . '">' . lang('go') . '</a>';
				}
				// NEXT: html encode the acctname string
				// html encode entities on the fullname so it's safe to display in the browser, and prefix with the acctnum
				$accountname = $this->msg->htmlspecialchars_decode($accountname);
				$accountname = $this->msg->htmlspecialchars_encode($accountname);
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
		return \App\modules\email\services\EmailPreferencesWriter::nextAccountNumber($this->msg->extra_accounts);
	}

	/*!
		@function obtain_ex_acctnum
		@abstract Resolve an edit/create account ID from POST or GET; missing or add_new allocates a slot.
		@throws \InvalidArgumentException for malformed IDs
		@throws \OutOfBoundsException for IDs that are neither stored nor the next available slot
		@author Angles
		@access Public
		*/
	function obtain_ex_acctnum()
	{
		$value = $this->msg->ref_POST['ex_acctnum'] ?? $this->msg->ref_GET['ex_acctnum'] ?? null;
		if ($value === null || $value === '' || $value === $this->add_new_account_token)
		{
			return $this->get_first_empty_ex_acctnum();
		}
		return \App\modules\email\services\EmailPreferencesWriter::existingAccountNumber(
			$value, (array) ($this->msg->unprocessed_prefs['email']['ex_accounts'] ?? [])
				+ [$this->get_first_empty_ex_acctnum() => []]
		);
	}
}
