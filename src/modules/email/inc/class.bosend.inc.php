<?php

/**
 * EMail - Send non-SMTP functions
 * 
 * @author Angelo (Angles) Puglisi <angles@phpgroupware.org>
 * @author Dave Hall <skwashd@phpgroupware.org>
 * @copyright Copyright (C) 2001-2002 Angelo Tony Puglisi (Angles)
 * @copyright Copyright (C) 2003-2005 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @package email
 * @version $Id$
 * @internal Based on AngleMail http://www.anglemail.org/
 * @internal Server side attachment storage technique borrowed from Squirrelmail
 */

/**
 * Send non-SMTP functions
 *
 * Assembling messages for sending
 * @package email
 */
class bosend
{
	var $public_functions = array(
		'sendorspell'	=> True,
		'spellcheck'	=> True,
		'send'		=> True,
		'save_draft'	=> True,
	);
	var $mail_spell;
	var $msg_bootstrap;
	var $nextmatchs;
	var $not_set = '-1';
	var $mail_out = array();
	var $smtp;
	var $api_mode = false;
	var $xi;

	var $debug_send = 0;

	var $company_disclaimer = '';

	function __construct()
	{

		// May 9, 2003 Ryan Bonham adds company disclaimer code
		// This Disclaimer will be added to any out going mail
		//var $company_disclaimer = "\r\n\r\n-- \r\n This message was sent using Forester GroupWare. Visit the Forest City Regional website at http://www.forestcityschool.org.\r\nThis message does not necessarily reflect the views of the Forest City Regional School District, nor has it been approved or sanctioned by it. \r\n";

		$this->msg_bootstrap = CreateObject("email.msg_bootstrap");
		$this->msg_bootstrap->ensure_mail_msg_exists('email.bosend.constructor', $this->debug_send);

		$this->not_set = \App\modules\email\services\MailMessage::getInstance()->not_set;
	}

	/**
	 * Get the IP address of the client sending this message
	 *
	 * Gets the value for the "X-Originating-IP" header, which is used by hotmail, 
	 * for example, it looked like a "good thing" so it here too. 
	 * Even if the IP private (such as on a LAN), this can still be useful for the admin.
	 * @author Angles
	 * @returns string client IP address
	 */
	function get_originating_ip()
	{
		$got_ip = Sanitizer::get_var('HTTP_X_FORWARDED_FOR', 'ip', 'SERVER', Sanitizer::get_var('REMOTE_ADDR', 'ip', 'SERVER'));
		/*
			if (is_object($GLOBALS['phpgw']->session))
			{
				$got_ip = $GLOBALS['phpgw']->session->getuser_ip();
			}
			elseif (isset($_SERVER['REMOTE_ADDR']))
			{
				$got_ip = $_SERVER['REMOTE_ADDR'];
			}
		*/
		// did we get anything useful ?
		if (trim((string)$got_ip) == '')
		{
			$got_ip = 'not available';
		}
		return $got_ip;
	}

	/** 
	 * Put a message in "Sent" Folder, if Applicable. This MUST be a message that has been sent already!
	 * 
	 * If a message has already been sent, and IF the user has set the pref enabling the use of the sent folder, 
	 * only then should this function be used. If a message has not actually been sent, it should NOT be copied to the "Sent"
	 * folder because that misrepresents to the user the history of the message. Mostly this is an issue with automated 
	 * messages sent from other apps. My .02 cents is that if a user did not send a message by pressing the "Send" button, 
	 * then the message does not belong in the Sent messages folder. Other people may have a different opinion, so 
	 * this function will not zap your keyboard if you think differently. Nonetheless, if the user has not enabled 
	 * the preference "Sent mail copied to Sent Folder", then noting gets copied there no matter what. Note that we 
	 * obtain these preference settings as shown in the example for this function. If the folder does not already exist, 
	 * class mail_msg has code to make every reasonable attempt to create the folder automatically. Some servers 
	 * just do things differently enough (unusual namespaces, sub folder trees) that the auto create may not work, 
	 * but it is nost likly that it can be created, and even more likely that it already exists. NOTE: this particular class 
	 * should be made availabllle to public use without the brain damage that is the current learning curve for this 
	 * code. BUT for now, this is a private function unless you really know what you are doing. Even then, code 
	 * in this class is subject to change.
	 *
	 * @author Angles
	 * @access private
	 * @internal NEEDS TO BE MADE AVAILABLE FOR PUBLIC USE
	 * @returns bool was the message saved?
	 */
	function copy_to_sent_folder($message)
	{
		/*!
			@capability (FUTURE CODE) append to sent folder without a pre-existing mailsvr_stream.
			@discussion FUTURE CODE what follows is untested but should work to accomplish that. 
			While we do need to login to the mail server, we can just select the INBOX because the IMAP 
			APPEND command does not require you have "selected" the folder that is the target of the append.
			We should be able to simply bootstrap the msg objext and call login, because during initialization 
			the msg object gathers all the data it can find on what account number we are dealing with here, 
			it handles that for us automatically. We do not want to append to the sent folder of the wrong account. 
			@example ## this should work if a stream does not already exist (UNTESTED)
			$this->msg_bootstrap = CreateObject("email.msg_bootstrap");
			$this->msg_bootstrap->ensure_mail_msg_exists('email.bosend.copy_to_sent_folder', $this->debug_send);
			## now run the rest of the function as usual.
			*/

		if (\App\modules\email\services\MailMessage::getInstance()->get_isset_pref('use_sent_folder') == False)
		{
			// ERROR, THIS ACCT DOES NOT WANT SENT FOLDER USED
			return False;
		}

		return $this->save_message($message, \App\modules\email\services\MailMessage::getInstance()->get_pref_value('sent_folder_name'));
	}

	/**
	 * Save a message to the nominated folder
	 *
	 * @author skwashd
	 * @param string $msg the mail message
	 * @param string $folder the folder the message is to be saved in
	 * @param string $extra_flags any additional flags for message?
	 * @return bool was the message saved?
	 */
	function save_message($msg, $folder, $extra_flags = '')
	{
		if (! ($msg && $folder))
		{
			return False; //invalid args
		}

		/*
			NOTES:	"append" will CHECK  to make sure this folder exists, and try to create it if it does not
				make sure there is a \r\n CRLF empty last line sequence so Cyrus will be happy
			*/
		return \App\modules\email\services\MailMessage::getInstance()->phpgw_append(
			$folder,
			$msg . "\r\n",
			"\\Seen {$extra_flags}"
		);
	}

	/**
	 * Marks a message as being replied to - like most other MUAs do
	 *
	 * @param array $msgball the message info
	 */
	function mark_as_replied($msgball)
	{
		\App\modules\email\services\MailMessage::getInstance()->phpgw_set_flag($msgball, "\\Answered");
	}

	/**
	 * This is called just before leaving this page, to clear / unset variables / objects
	 */
	function send_message_cleanup()
	{
		//echo 'send_message cleanup';
		\App\modules\email\services\MailMessage::getInstance()->end_request();

		$upload_dir = \App\modules\email\services\MailMessage::getInstance()->att_files_dir;
		if (file_exists($upload_dir))
		{
			$dh = dir($upload_dir);
			while (($file = $dh->read()) !== false)
			{
				if ($file == '.' || $file == '..')
				{
					continue;
				}
				unlink("{$upload_dir}/{$file}");
			}
			$dh->close();
			rmdir($upload_dir);
		}
	}

	/**
	 * Detects whether the compose page was submitted as a send or spellcheck, and acts accordingly
	 * 
	 * Compose form submit action target is bosend, naturally, however the spell check button submit is identical
	 * EXCEPT "btn_spellcheck" POST var will be set, which requires we handoff the handling to the spell class.
	 * @author Angles
	 */
	function sendorspell()
	{

		if ((isset(\App\modules\email\services\MailMessage::getInstance()->ref_POST['draft']))
			&& (\App\modules\email\services\MailMessage::getInstance()->ref_POST['draft'] == 'save')
		)
		{
			$this->save_draft();
		}

		if ((isset(\App\modules\email\services\MailMessage::getInstance()->ref_POST['btn_spellcheck']))
			&& (\App\modules\email\services\MailMessage::getInstance()->ref_POST['btn_spellcheck'] != '')
		)
		{
			$this->spellcheck();
		}
		elseif ((isset(\App\modules\email\services\MailMessage::getInstance()->ref_POST['btn_send']))
			&& (\App\modules\email\services\MailMessage::getInstance()->ref_POST['btn_send'] != '')
		)
		{
			$this->send();
		}
		else
		{
			$this->send();
		}
	}

	/*!
		@function spellcheck
		@abstract if the compose page was submitted as a pellcheck, this function is called, it then calls the emai.spell class
		@params none, uses GET and POST vars
		@discussion If needed, put the body through stripslashes_gpc() before handing it off to the mail_spell object.
		This function simply gathers the required information and hands it off to the mail_spell class,
		*/
	function spellcheck()
	{

		// we may strip slashes, but that is all we should do before handing the body to the spell class
		//$my_body = $GLOBALS['phpgw']->msg->stripslashes_gpc(trim($GLOBALS['phpgw']->msg->get_arg_value('body')));
		//$this->mail_spell->set_body_orig($my_body);

		$this->mail_spell = CreateObject("email.spell");
		// preserve these vars
		$this->mail_spell->set_preserve_var('action', \App\modules\email\services\MailMessage::getInstance()->get_arg_value('action'));
		// experimental, should this go here? is not this already in the URI or something?
		//$this->mail_spell->set_preserve_var('orig_action', $GLOBALS['phpgw']->msg->recall_desired_action());
		$this->mail_spell->set_preserve_var('from', \App\modules\email\services\MailMessage::getInstance()->get_arg_value('from'));
		$this->mail_spell->set_preserve_var('sender', \App\modules\email\services\MailMessage::getInstance()->get_arg_value('sender'));
		$this->mail_spell->set_preserve_var('to', \App\modules\email\services\MailMessage::getInstance()->stripslashes_gpc(\App\modules\email\services\MailMessage::getInstance()->get_arg_value('to')));
		$this->mail_spell->set_preserve_var('cc', \App\modules\email\services\MailMessage::getInstance()->stripslashes_gpc(\App\modules\email\services\MailMessage::getInstance()->get_arg_value('cc')));
		$this->mail_spell->set_preserve_var('bcc', \App\modules\email\services\MailMessage::getInstance()->stripslashes_gpc(\App\modules\email\services\MailMessage::getInstance()->get_arg_value('bcc')));
		$this->mail_spell->set_preserve_var('msgtype', \App\modules\email\services\MailMessage::getInstance()->get_arg_value('msgtype'));

		$this->mail_spell->set_subject(\App\modules\email\services\MailMessage::getInstance()->stripslashes_gpc(\App\modules\email\services\MailMessage::getInstance()->get_arg_value('subject')));
		$this->mail_spell->set_body_orig(\App\modules\email\services\MailMessage::getInstance()->stripslashes_gpc(trim(\App\modules\email\services\MailMessage::getInstance()->get_arg_value('body'))));

		// oops, do not forget about these, "attach_sig" and "req_notify"
		if ((\App\modules\email\services\MailMessage::getInstance()->get_isset_arg('attach_sig'))
			&& (\App\modules\email\services\MailMessage::getInstance()->get_arg_value('attach_sig') != '')
		)
		{
			$this->mail_spell->set_preserve_var('attach_sig', \App\modules\email\services\MailMessage::getInstance()->get_arg_value('attach_sig'));
		}
		if ((\App\modules\email\services\MailMessage::getInstance()->get_isset_arg('req_notify'))
			&& (\App\modules\email\services\MailMessage::getInstance()->get_arg_value('req_notify') != '')
		)
		{
			$this->mail_spell->set_preserve_var('req_notify', \App\modules\email\services\MailMessage::getInstance()->get_arg_value('req_notify'));
		}

		//$this->mail_spell->basic_spcheck();
		$this->mail_spell->spell_review();
	}

	/**
	 * Send a mail message. 
	 *
	 * @internal a lot of this functionality is now in prepare_message
	 * @author Angles
	 */
	function send()
	{

		$this->smtp = createObject('phpgwapi.mailer_smtp');

		$this->prepare_message();

		$returnccode = $this->smtp->Send();
		$returncode = (bool) $returnccode;

		/*
			// ===== DEBUG =====	
			echo '<br />';
			echo 'retain_copy: '.serialize($GLOBALS['phpgw']->mail_send->retain_copy);
			echo '<br />=== POST SEND ===<br />';
			echo '<pre>'.$GLOBALS['phpgw']->msg->htmlspecialchars_encode($GLOBALS['phpgw']->mail_send->assembled_copy).'</pre>';
			echo '<br />';
			// ===== DEBUG ===== 
			*/

		if ($returnccode && \App\modules\email\services\MailMessage::getInstance()->get_isset_pref('use_sent_folder'))
		{
			$success = $this->copy_to_sent_folder($this->smtp->getHeader() . "\n" . $this->smtp->getBody());
		}

		$msgball = (array) \App\modules\email\services\MailMessage::getInstance()->get_arg_value('msgball');
		$is_reply = in_array(\App\modules\email\services\MailMessage::getInstance()->recall_desired_action(), ['reply', 'replyall'], true);
		if ($is_reply && $returncode)
		{
			$this->mark_as_replied($msgball);
		}

		$return_to_folder_href = $this->get_return_to();
		if ($this->api_mode)
		{
			$sent = (bool) $returnccode;
			$error = $sent ? '' : (string) ($this->smtp->ErrorInfo ?? '');
			$this->send_message_cleanup();
			if (\App\modules\email\services\MailMessage::hasInstance())
			{
				\App\modules\email\services\MailMessage::releaseInstance();
			}
			return ['sent' => $sent, 'error' => $error];
		}

		if ($returnccode)
		{
			// Success
			/*
				if ($GLOBALS['phpgw']->mail_send->trace_flag > 0)
				{
					// for debugging
					echo '<html><body>'."\r\n";
					echo '<h2>Here is the communication from the MUA(phpgw) <--> MTA(smtp server) trace data dump</h2>'."\r\n";
					echo '<h3>trace data flag set to ['.(string)$GLOBALS['phpgw']->mail_send->trace_flag.']</h3>'."\r\n";
					echo '<pre>'."\r\n";
					$this->smtp->ErrorInfo;
					echo '</pre>'."\r\n";
					echo '<p>&nbsp;<br /></p>'."\r\n";
					echo '<p>To go back to the msg list, click <a href="'. phpgw::link('/index.php',$return_to_folder_href).'">here</a></p><br />';
					echo '</body></html>';
					$this->send_message_cleanup();
				}
				else*/
			{
				// unset some vars (is this necessary?)
				$this->send_message_cleanup();
				// redirect the browser to the index page for the appropriate folder
				//header('Location: '.$return_to_folder_href);
				phpgw::redirect_link('/index.php', $return_to_folder_href);
				// kill the rest of this script
				if (\App\modules\email\services\MailMessage::hasInstance())
				{
					// close down ALL mailserver streams
					\App\modules\email\services\MailMessage::getInstance()->end_request();
					// destroy the object
					\App\modules\email\services\MailMessage::releaseInstance();
				}
				// shut down this transaction
				(new \phpgwapi_common())->phpgw_exit(False);
			}
		}
		else
		{
			// ERROR - mail NOT sent
			$return_to_folder_url = phpgw::link('/index.php', $return_to_folder_href);
			echo '<html><body>' . "\r\n";
			echo '<h2>Your message could <b>not</b> be sent!</h2>' . "\r\n";
			echo '<h3>The mail server returned:</h3>' . "\r\n";
			echo '<pre>';
			$this->smtp->ErrorInfo;
			echo '</pre>' . "\r\n";
			echo '<p>To go back to the msg list, click <a href="' . $return_to_folder_url . '">here</a> </p>' . "\r\n";
			echo '</body></html>';
			$this->send_message_cleanup();
		}
	}

	/**
	 * Prepare a RFC 2822 Message
	 *
	 * @author skwashd
	 * @internal remnants of Angles' code remains
	 * @internal the old send code has been rewritten to use phpmailer.sf.net - which makes our life so much easier
	 */
	function prepare_message()
	{

		if ($this->debug_send > 2)
		{

			return;
		}

		// ---- BEGIN BO SEND LOGIC

		$not_set = \App\modules\email\services\MailMessage::getInstance()->not_set;
		$msgball = \App\modules\email\services\MailMessage::getInstance()->get_arg_value('msgball');

		//  -------  Init Array Structure For Outgoing Mail  -----------
		$this->mail_out = array();
		$this->mail_out['whitespace'] = chr(9);
		$this->mail_out['is_forward'] = False;
		$this->mail_out['fwd_proc'] = '';

		$is_reply = False;

		$this->smtp->CharSet = (lang('charset') != 'charset*') ? lang('charset') : 'US-ASCII';

		$this->smtp->Sender = (\App\modules\email\services\MailMessage::getInstance()->get_isset_arg('sender')
			? \App\modules\email\services\MailMessage::getInstance()->get_arg_value('sender')
			: '');

		$this->smtp->FromName = \App\modules\email\services\MailMessage::getInstance()->get_pref_value('fullname');

		$this->smtp->From = \App\modules\email\services\MailMessage::getInstance()->get_pref_value('address');

		$this->smtp->Subject = \App\modules\email\services\MailMessage::getInstance()->stripslashes_gpc(\App\modules\email\services\MailMessage::getInstance()->get_arg_value('subject'));

		$this->smtp->Helo = trim($GLOBALS['phpgw_info']['server']['hostname']);

		$this->smtp->Body = \App\modules\email\services\MailMessage::getInstance()->stripslashes_gpc(trim(\App\modules\email\services\MailMessage::getInstance()->get_arg_value('body')));

		$this->smtp->AddCustomHeader('X-Originating-IP: ' . $this->get_originating_ip());

		// ----  Forwarding Detection  -----
		if ((\App\modules\email\services\MailMessage::getInstance()->get_isset_arg('action'))
			&& (\App\modules\email\services\MailMessage::getInstance()->get_arg_value('action') == 'forward')
		)
		{
			// fill mail_out[] structure information
			$this->mail_out['is_forward'] = True;
			// after this, ONLY USE $this->mail_out[] structure for this
		}
		if ((\App\modules\email\services\MailMessage::getInstance()->get_isset_arg('fwd_proc'))
			&& (\App\modules\email\services\MailMessage::getInstance()->get_arg_value('fwd_proc') != '')
		)
		{
			// convert script GPC args into useful mail_out[] structure information
			$this->mail_out['fwd_proc'] = \App\modules\email\services\MailMessage::getInstance()->get_arg_value('fwd_proc');
			// after this, ONLY USE $this->mail_out[] structure for this
		}

		//  ------  get rid of the escape \ that magic_quotes (if enabled) HTTP POST will add, " becomes \" and  '  becomes  \'
		// convert script GPC args into useful mail_out structure information
		$to = \App\modules\email\services\MailMessage::getInstance()->stripslashes_gpc(\App\modules\email\services\MailMessage::getInstance()->get_arg_value('to'));
		$cc = \App\modules\email\services\MailMessage::getInstance()->stripslashes_gpc(\App\modules\email\services\MailMessage::getInstance()->get_arg_value('cc'));
		$bcc = \App\modules\email\services\MailMessage::getInstance()->stripslashes_gpc(\App\modules\email\services\MailMessage::getInstance()->get_arg_value('bcc'));
		// after this,  do NOT use ->msg->get_arg_value() for these anymore

		// since arg "body" *may* be huge (and is now in local var $body), lets clear it now
		\App\modules\email\services\MailMessage::getInstance()->set_arg_value('body', '');

		/* ----  DE-code HTML SpecialChars in the body   -----
			THIS NEEDS TO BE CHANGED WHEN MULTIPLE PART FORWARDS ARE ENABLED
			BECAUSE WE CAN ONLY ALTER THE 1ST PART, I.E. THE PART THE USER JUST TYPED IN
			email needs to be sent out as if it were PLAIN text (at least the part we are handling here)
			i.e. with NO ENCODED HTML ENTITIES, so use > instead of $rt; and " instead of &quot; . etc...
			it's up to the endusers MUA to handle any htmlspecialchars, whether to encode them or leave as it, the MUA should decide 
			*/
		$this->smtp->Body = \App\modules\email\services\MailMessage::getInstance()->htmlspecialchars_decode($this->smtp->Body);

		// ----  Add Email Sig to Body   -----
		if ((\App\modules\email\services\MailMessage::getInstance()->get_isset_pref('email_sig'))
			&& (\App\modules\email\services\MailMessage::getInstance()->get_pref_value('email_sig') != '')
			&& (\App\modules\email\services\MailMessage::getInstance()->get_isset_arg('attach_sig'))
			&& (\App\modules\email\services\MailMessage::getInstance()->get_arg_value('attach_sig') != '')
			// ONLY ADD SIG IF USER PUTS TEXT IN THE BODY
			//&& (strlen(trim($body)) > 3))
			&& ($this->mail_out['is_forward'] == False)
		)
		{
			$user_sig = \App\modules\email\services\MailMessage::getInstance()->get_pref_value('email_sig');
			// html_quotes_decode may be obsoleted someday:  workaround for a preferences database issue (<=pgpgw ver 0.9.13)
			$user_sig = \App\modules\email\services\MailMessage::getInstance()->html_quotes_decode($user_sig);
			$this->smtp->Body = $this->smtp->Body . "\r\n"
				. "\r\n"
				. '-- ' . "\r\n"
				. $user_sig . "\r\n";
		}

		if ($this->company_disclaimer)
		{
			$this->smtp->Body = $this->smtp->Body . $this->company_disclaimer;
		}

		/*
			LINE LENGTH for "new" and our text of a forwarded text are 78 chars, 
			which is SHORTER than for reply quoted bodies that have ">" chars 
			this is only for text WE have written, not any other part of the body
			html textbox no longer adds hard wrap on submit, so we handle it here now
			NOTE reply bodies have already been handled as to length when we quoted the text
			*/
		if ((\App\modules\email\services\MailMessage::getInstance()->recall_desired_action() == 'new')
			|| (\App\modules\email\services\MailMessage::getInstance()->recall_desired_action() == 'forward')
		)
		{
			// WRAP BODY to lines of 78 chars then CRLF
			// IS THIS TOO SHORT? what about code snippets and stuff?or long URLs
			$this->smtp->Body = \App\modules\email\services\MailMessage::getInstance()->body_hard_wrap($this->smtp->Body, 78);
		}
		elseif ((\App\modules\email\services\MailMessage::getInstance()->recall_desired_action() == 'reply')
			|| (\App\modules\email\services\MailMessage::getInstance()->recall_desired_action() == 'replyall')
		)
		{
			$is_reply = True;
			//echo 'entering recall_desired_action == reply line length handling'."\r\n";
			// ok we have already quoted the text of the message we are replying to
			// BUT we have yet to standardize line length for the text WE just typed
			// in this message, our own text, 
			// BUT we really should skip doing linebreaking it _again_ for the quoted text, though
			$body_array = array();
			$body_array = explode("\r\n", $this->smtp->Body);
			// we do not use this again till we put $new_body into it, so clear the memory
			$this->smtp->Body = '';
			// process only our unquoted text
			$body_array_count = count($body_array);
			$in_unquoted_block = False;
			$unquoted_text = '';
			$new_body = '';
			for ($bodyidx = 0; $bodyidx < $body_array_count; ++$bodyidx)
			{
				// skip text that starts with the ">" so called "quoting" char to the original body text
				// because it has already been line length normalized in bocompose
				$this_line = $body_array[$bodyidx];
				if ((strlen($this_line) > 1)
					&& ($this_line[0] == \App\modules\email\services\MailMessage::getInstance()->reply_prefix[0])
				)
				{
					// ... this line starts with the quoting char
					if ($in_unquoted_block == True)
					{
						//echo 'line ength handling: processing MY text block'."\r\n";
						// TOGGLE - we are exiting block of our text
						// process the preceeding block of unquoted text, if any
						$unquoted_text = \App\modules\email\services\MailMessage::getInstance()->body_hard_wrap($unquoted_text, 78);
						// now pass it into the new body var
						$new_body .= $unquoted_text;
						// clear this var
						$unquoted_text = '';
						// toggle this flag
						$in_unquoted_block = False;
						// for THIS line, it is the first in a quoted block, so pass straight to new body var
						//   I _think_ the CRLF is needed before this line because hard_wrap may not 
						//   put one at the end of the last line of the unquoted text block ?
						//$new_body .=  "\r\n" . $this_line . "\r\n";	
						$new_body .= $this_line . "\r\n";
					}
					else
					{
						// we are in a block of QUOTED text, simply pass it into the new body var
						$new_body .= $this_line . "\r\n";
					}
				}
				elseif (($body_array_count - $bodyidx) == 1)
				{
					// this is the last line, and it is NOT quoted, so if we were in an unquoted block (of our text) process it now
					// even if this is the only single line of unquoted text in the message, process it now
					// otherwise we may leave off the end of the message, if it is our text
					$unquoted_text .= $this_line;
					$unquoted_text = \App\modules\email\services\MailMessage::getInstance()->body_hard_wrap($unquoted_text, 78);
					$new_body .= $unquoted_text;
					$unquoted_text = '';
					// this really is not needed, but so it anyway
					$in_unquoted_block = False;
				}
				else
				{
					// ... this line does NOT start with the quoting char, i.e. it is text we typed in
					// make sure flag is correct
					if ($in_unquoted_block == False)
					{
						// toggle this flag
						$in_unquoted_block = True;
						// there is just no real special action of a change into this block of our text, 
						// the real action is when switching out of a block or our (unqouted) text 
					}
					// compile this block of unquoted text, our text, in a var for later processing
					$unquoted_text .= $this_line . "\r\n";
				}
			}

			//My new body :)
			$this->smtp->Body = $new_body;

			// cleanup
			$body_array = array();
			$new_body = '';
			$unquoted_text = '';
			// end reply body line length landling block
		}

		// Step One Addition - fixed by skwashd :P
		// ---- Request Delivery Notification in Headers ----
		if ((\App\modules\email\services\MailMessage::getInstance()->get_isset_arg('req_notify'))
			&& (\App\modules\email\services\MailMessage::getInstance()->get_arg_value('req_notify') != '')
		)
		{
			$this->smtp->ConfirmReadingTo = $this->smtp->Sender;
		}

		// ----  Ensure To: and CC:  and BCC: are properly formatted   -----
		if ($to)
		{
			// mail_out[to] is an array of addresses, each has properties [plain] and [personal]
			$to_array = \App\modules\email\services\MailMessage::getInstance()->make_rfc_addy_array($to);
			if ($to_array && is_array($to_array))
			{
				foreach ($to_array as $recip)
				{
					$this->smtp->AddAddress($recip['plain'], $recip['personal']);
				}
			}
			unset($to_array);
		}
		if ($cc)
		{
			$cc_array = \App\modules\email\services\MailMessage::getInstance()->make_rfc_addy_array($cc);
			if ($cc_array && is_array($cc_array))
			{
				foreach ($cc_array as $recip)
				{
					$this->smtp->AddCC($recip['plain'], $recip['personal']);
				}
			}
			unset($cc_array);
		}
		if ($bcc)
		{
			$bcc_array = \App\modules\email\services\MailMessage::getInstance()->make_rfc_addy_array($bcc);
			if ($bcc_array && is_array($bcc_array))
			{
				foreach ($bcc_array as $recip)
				{
					$this->smtp->AddCC($recip['plain'], $recip['personal']);
				}
			}
			unset($bcc_array);
		}

		// ----  Attachment Detection  -----
		// some of this attachment uploading and handling code is from squirrelmail (www.squirrelmail.org)
		$upload_dir = \App\modules\email\services\MailMessage::getInstance()->att_files_dir;
		if (file_exists($upload_dir))
		{
			$dh = dir($upload_dir);
			while (($file = $dh->read()) !== false)
			{
				if (($file != '.')
					&& ($file != '..')
					&& (strpos($file, '.info'))
				)
				{
					$meta_data = explode("\n", file_get_contents("{$upload_dir}/{$file}"));
					$real_file = substr($file, 0, strpos($file, '.info'));
					$this->smtp->AddAttachment(
						"{$upload_dir}/{$real_file}",
						trim($meta_data[1]),
						'base64',
						trim($meta_data[0])
					);
				}
			}
			$dh->close();
		}

		if ($this->mail_out['is_forward'] == True)
		{
			// DUMP the original message verbatim into this part's "body" - i.e. encapsulate the original mail
			$fwd_this['sub_header'] = trim(\App\modules\email\services\MailMessage::getInstance()->phpgw_fetchheader());
			$fwd_this['sub_header'] = \App\modules\email\services\MailMessage::getInstance()->normalize_crlf($fwd_this['sub_header']);

			// CLENSE headers of offensive artifacts that can confuse dumb MUAs
			$fwd_this['sub_header'] = preg_replace("/^[>]{0,1}From\s.{1,}\r\n/i", "", $fwd_this['sub_header']);
			$fwd_this['sub_header'] = preg_replace("/Received:\s(.{1,}\r\n\s){0,6}.{1,}\r\n(?!\s)/m", "", $fwd_this['sub_header']);
			$fwd_this['sub_header'] = preg_replace("/.{0,3}Return-Path.*\r\n/m", "", $fwd_this['sub_header']);
			$fwd_this['sub_header'] = trim($fwd_this['sub_header']);

			// get the body
			$fwd_this['sub_body'] = trim(\App\modules\email\services\MailMessage::getInstance()->phpgw_body());
			//$fwd_this['sub_body'] = $GLOBALS['phpgw']->msg->normalize_crlf($fwd_this['sub_body']);

			// Make Sure ALL INLINE BOUNDARY strings actually have CRLF CRLF preceeding them
			// because some lame MUA's don't properly format the message with CRLF CRLF BOUNDARY
			// in which case when we encapsulate such a malformed message, it *may* not be understood correctly 
			// by the receiving MUA, so we attempt to correct such a malformed message before we encapsulate it
			// ---- not yet complete ----
			$char_quot = '"';
			preg_match("/boundary=[$char_quot]{0,1}.*[$char_quot]{0,1}\r\n/", $fwd_this['sub_header'], $fwd_this['matches']);
			if (stristr($fwd_this['matches'][0], 'boundary='))
			{
				$fwd_this['boundaries'] = trim($fwd_this['matches'][0]);
				$fwd_this['boundaries'] = str_replace('boundary=', '', $fwd_this['boundaries']);
				$fwd_this['boundaries'] = str_replace('"', '', $fwd_this['boundaries']);
				$this_boundary = $fwd_this['boundaries'];
				$fwd_this['sub_body'] = preg_replace("/(?<!(\r\n\r\n))[-]{2}" . $this_boundary . "/m", "\r\n\r\n" . '--' . $this_boundary, $fwd_this['sub_body']);
				$dash_dash = '--';
				$fwd_this['sub_body'] = preg_replace("/(?<!(\r\n\r\n))[-]{2}$dash_dash$this_boundary$dash_dash/", "\r\n\r\n" . '--' . $this_boundary . '--', $fwd_this['sub_body']);
				$fwd_this['sub_body'] = trim($fwd_this['sub_body']);
			}

			// assemble it and add the blank line that seperates the headers from the body
			$fwd_this['processed'] = $fwd_this['sub_header'] . "\r\n" . "\r\n" . $fwd_this['sub_body'];

			$this->smtp->AddStringAttachment($fwd_this['processed'], lang('forwarded_message'), '7bit', 'message/rfc822');
			// clear this no longer needed var
			$fwd_this = '';
			unset($fwd_this);
		}

		/*
			// ===== DEBUG =====	
			echo '<br />';
			echo '<br />=== mail_out ===<br />';
			$dubug_info = serialize($this->mail_out);
			$dubug_info = $GLOBALS['phpgw']->msg->htmlspecialchars_encode($dubug_info);
			echo $dubug_info;
			echo '<br />';
				
			$GLOBALS['phpgw']->common->phpgw_footer();
			exit;
			// ===== DEBUG ===== 
			*/

		/*
			// ===== DEBUG =====	
			echo '<br />';
			echo '<br />=== mail_out ===<br />';
			$dubug_info = serialize($this->mail_out);
			$dubug_info = $GLOBALS['phpgw']->msg->htmlspecialchars_encode($dubug_info);
			echo $dubug_info;
			echo '<br />';
			// ===== DEBUG ===== 
			*/
	}

	function get_return_to()
	{
		// ----  Redirect on Success, else show Error Report   -----
		// what folder to go back to (the one we came from)
		// Personally, I think people should go back to the INBOX after sending an email
		// HOWEVER, we will go back to the folder this message came from (if available)
		if ((\App\modules\email\services\MailMessage::getInstance()->get_isset_arg('["msgball"]["folder"]'))
			&& (\App\modules\email\services\MailMessage::getInstance()->get_isset_arg('["msgball"]["acctnum"]'))
		)
		{
			$fldball_candidate['folder'] = \App\modules\email\services\MailMessage::getInstance()->get_arg_value('["msgball"]["folder"]');
			$fldball_candidate['acctnum'] = (int)\App\modules\email\services\MailMessage::getInstance()->get_arg_value('["msgball"]["acctnum"]');
		}
		elseif ((\App\modules\email\services\MailMessage::getInstance()->get_isset_arg('["fldball"]["folder"]'))
			&& (\App\modules\email\services\MailMessage::getInstance()->get_isset_arg('["fldball"]["acctnum"]'))
		)
		{
			$fldball_candidate['folder'] = \App\modules\email\services\MailMessage::getInstance()->get_arg_value('["fldball"]["folder"]');
			$fldball_candidate['acctnum'] = (int)\App\modules\email\services\MailMessage::getInstance()->get_arg_value('["fldball"]["acctnum"]');
		}
		// did we get useful data
		if ((isset($fldball_candidate))
			&& ($fldball_candidate['folder'] != '')
		)
		{
			$fldball_candidate['folder'] = \App\modules\email\services\MailMessage::getInstance()->prep_folder_out($fldball_candidate['folder']);
		}
		else
		{
			$fldball_candidate['folder'] = \App\modules\email\services\MailMessage::getInstance()->prep_folder_out('INBOX');
			$fldball_candidate['acctnum'] = (int)\App\modules\email\services\MailMessage::getInstance()->get_acctnum();
		}
		/*			return phpgw::link(
						'/index.php',array(
						'menuaction'=>'email.uiindex.index',
						'fldball[folder]'=>$fldball_candidate['folder'],
						'fldball[acctnum]'=>$fldball_candidate['acctnum'],
						'sort'=>$GLOBALS['phpgw']->msg->get_arg_value('sort'),
						'order'=>$GLOBALS['phpgw']->msg->get_arg_value('order'),
						'start'=>$GLOBALS['phpgw']->msg->get_arg_value('start')));

*/
		return array(
			'menuaction' => 'email.uiindex.index',
			'fldball[folder]' => $fldball_candidate['folder'],
			'fldball[acctnum]' => $fldball_candidate['acctnum'],
			'sort' => \App\modules\email\services\MailMessage::getInstance()->get_arg_value('sort'),
			'order' => \App\modules\email\services\MailMessage::getInstance()->get_arg_value('order'),
			'start' => \App\modules\email\services\MailMessage::getInstance()->get_arg_value('start')
		);
	}

	function save_draft()
	{
		$this->prepare_message();
		$this->smtp->Mailer = 'draft-dodger'; //a needed hack - skwashd
		$this->smtp->Send();
		$this->save_message(
			$this->smtp->getHeader() . "\n" . $this->smtp->getBody() . "\r\n",
			'INBOX.Drafts',
			"\\Draft"
		);

		phpgw::redirect_link('/index.php', array('menuaction' => 'email.uiindex.index'));
	}
}
