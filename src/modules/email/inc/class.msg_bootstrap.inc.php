<?php

/**
 * EMail - Bootstrap the mail_msg object
 *
 * @author Angelo (Angles) Puglisi <angles@aminvestments.com>
 * @copyright Copyright (C) 2002 Angelo Tony Puglisi (Angles)
 * @copyright Copyright (C) 2003-2005 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @package email
 * @version $Id$
 * @internal Based on AngleMail http://www.anglemail.org/
 */

/**
 * Starts the shared MailMessage request with the configured login policy.
 *
 * Repeated calls reuse the initialized request. Account selection is handled
 * by MailMessage::begin_request() using request data. API login failures throw
 * EmailMailConfigurationException; browser failures use the mail error renderer.
 * The constructor registers legacy BS_LOGIN_* aliases for existing callers.
 * @package email
 */
class email_msg_bootstrap
{
	public const LOGIN_NOT_SPECIFIED = 0;
	public const LOGIN_NEVER = 1;
	public const LOGIN_ONLY_IF_NEEDED = 2;
	public const LOGIN_YES = 3;

	/**
	 * @var boolean $do_login Defaults to True, most often you do not need to change this.
	 */
	var $do_login = True;
	//var $do_login = False;
	var $do_login_ex = 0;

	var $debug_level = 0;

	function __construct()
	{
		$loginModes = array(
			'BS_LOGIN_NOT_SPECIFIED' => self::LOGIN_NOT_SPECIFIED,
			'BS_LOGIN_NEVER' => self::LOGIN_NEVER,
			'BS_LOGIN_ONLY_IF_NEEDED' => self::LOGIN_ONLY_IF_NEEDED,
			'BS_LOGIN_YES' => self::LOGIN_YES,
		);
		foreach ($loginModes as $name => $value)
		{
			if (!defined($name)) define($name, $value);
		}
	}

	/*!
		@function set_do_login REIMPLEMENTATION
		@abstract whether to try to login to the mail server or not during a call to "ensure_mail_msg_exists". 
		OPTIONAL, default is True. Behavior depends on caching method.
		@param $do_login (boolean) 
		@author Angles
		@result (boolean) whatever the value var $this->do_login has on exiting the function.
		@discussion OPTONAL, default of True works for most situations. This do_login value is 
		used in this objects function "ensure_mail_msg_exists" where it is passed to the mail_msg class. 
		Again, this is OPTONAL, default of True works for most situations, such as 
		(1a) If session_cache_extreme is True, and do_login=True, this will _allow_ a server login, 
		if needed, only if the app needs to get data that is not already cached.
		(1b) If session_cache_extreme is False, and do_login=True, this will _always_ try to establish 
		a mail server stream at the beginning of every script run. 
		(2a and 2b) Setting do_login to False is useful in certain limited situations, such as the email settings page, 
		or the preferences page. There you want to set or get email preference data but you do NOT 
		require a login, or when there may be no preference data set yet, such as the first time a user 
		sets the preferences, so a login is not even possible. The preference data will be handled by the 
		mail_msg class as usual. Setting do_login to False for these occasions is OK no matter if 
		session_cache_extreme is True or False. 
		@access public
		*/
	function set_do_login($do_login = '##NOTHING##', $called_by = 'not_provided')
	{
		// backward compat, when this was only true or false
		if (is_bool($do_login))
		{
			if ($do_login)
			{
				$this->do_login_ex = self::LOGIN_ONLY_IF_NEEDED;
			}
			else
			{
				$this->do_login_ex = self::LOGIN_NEVER;
			}

			$this->do_login = $do_login;

			// LEAVING HERE
			return $this->do_login;
		}
		elseif (is_int($do_login))
		{
			$validModes = [self::LOGIN_NEVER, self::LOGIN_ONLY_IF_NEEDED, self::LOGIN_YES];
			$this->do_login_ex = in_array($do_login, $validModes, true)
				? $do_login
				: self::LOGIN_ONLY_IF_NEEDED;
			$this->do_login = $this->do_login_ex !== self::LOGIN_NEVER;
		}
		else
		{
			$this->do_login = true;
			$this->do_login_ex = self::LOGIN_ONLY_IF_NEEDED;
		}
		return $this->do_login_ex;
	}

	/*!
		@function get_do_login
		@abstract get the value of var $this->do_login
		@result (boolean) the value var $this->do_login 
		@author Angles
		@discussion ?
		@access public
		*/
	function get_do_login()
	{
		return $this->do_login;
	}

	/*!
		@function get_do_login_ex
		@abstract ?
		@result (defined integer) the value var $this->do_login 
		@author Angles
		@discussion ?
		@access public
		*/
	function get_do_login_ex()
	{
		return $this->do_login_ex;
	}

	/*!
		@function login
		@abstract If you do not care to do complicated things with email, create this object and call this login function.
		@author Angles
		@discussion Alias to "ensure_mail_msg_exists", if you want debugging output capability then use that 
		function. If you just want to get email working quickly, use this function.
		@example 
			## email quickstart:
			$GLOBALS["phpgw"]->msg_bootstrap = CreateObject("email.msg_bootstrap");
			$GLOBALS['phpgw']->msg_bootstrap->login();
		*/
	function login()
	{
		return $this->ensure_mail_msg_exists('whatever called msg_bootstrap->login', 0);
	}

	/**
	 * Initializes the shared request unless it has already started.
	 *
	 * @param string $called_by Caller context included in browser login errors.
	 * @param int $debug_level Legacy diagnostic level, not an account number.
	 */
	function ensure_mail_msg_exists($called_by = 'not_provided', $debug_level = 0)
	{
		if ($debug_level > $this->debug_level)
		{
			$this->debug_level = $debug_level;
		}

		// make sure do_login has been set
		if ($this->get_do_login_ex() == self::LOGIN_NOT_SPECIFIED)
		{
			// this gives us a good general default value
			$tmp_prev_value = $this->get_do_login();
			$this->set_do_login($tmp_prev_value);
		}


		$mail = \App\modules\email\services\MailMessage::getInstance();

		if ($mail->get_isset_arg('already_grab_class_args_gpc'))
		{
			// mail_msg had already run thru "begin_request", do not call it again
			return true;
		}

		$args_array = array(
			'do_login'		=> $this->do_login,
			'do_login_ex'	=> $this->do_login_ex
		);
		$isApiRequest = preg_match('~/email/api(?:/|$)~', (string) ($_SERVER['REQUEST_URI'] ?? '')) === 1;
		$args_array['api_request'] = $isApiRequest;

		// "start your engines"
		$some_stream = $mail->begin_request($args_array);
		// error if login failed
		if ($args_array['do_login']  && !$some_stream)
		{
			if ($isApiRequest)
			{
				throw new \App\modules\email\services\EmailMailConfigurationException(
					$mail->hasTransport($mail->get_acctnum())
						? $mail->connection_failure_reason($mail->get_acctnum())
						: lang('Configure your email account username and password before using email.')
				);
			}
			$mail->login_error($_SERVER['PHP_SELF'] . ', msg_bootstrap: ensure_mail_msg_exists(), called_by: ' . $called_by);
		}
		// login error will halt this script execution
		// else all is good to go and script continues... 
	}


}
