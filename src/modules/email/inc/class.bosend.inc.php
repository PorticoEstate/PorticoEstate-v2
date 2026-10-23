<?php

use App\modules\phpgwapi\services\MailerSmtp;

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
		'send'		=> True,
	);
	var $msg_bootstrap;
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
			return false;
		}
		return \App\modules\email\services\MailMessage::getInstance()->phpgw_append(
			$folder,
			$msg . "\r\n",
			"\\Seen {$extra_flags}"
		);
	}

	function mark_as_replied($msgball)
	{
		\App\modules\email\services\MailMessage::getInstance()->phpgw_set_flag($msgball, "\\Answered");
	}

	function send_message_cleanup()
	{
		\App\modules\email\services\MailMessage::getInstance()->end_request();
		$uploadDirectory = \App\modules\email\services\MailMessage::getInstance()->att_files_dir;
		if (!is_dir($uploadDirectory)) return;
		foreach (scandir($uploadDirectory) ?: [] as $file)
		{
			if ($file === '.' || $file === '..') continue;
			$path = $uploadDirectory . '/' . $file;
			if (is_file($path) && !is_link($path)) unlink($path);
		}
		rmdir($uploadDirectory);
	}

	/**
	 * Send a mail message. 
	 *
	 * @internal a lot of this functionality is now in prepare_message
	 * @author Angles
	 */
	function send()
	{
		$this->smtp = new MailerSmtp();
		$this->prepare_message();
		$returnccode = $this->smtp->Send();
		$returncode = (bool) $returnccode;
		if ($returnccode && \App\modules\email\services\MailMessage::getInstance()->get_isset_pref('use_sent_folder'))
		{
			$this->copy_to_sent_folder($this->smtp->getSentMIMEMessage());
		}
		$msgball = (array) \App\modules\email\services\MailMessage::getInstance()->get_arg_value('msgball');
		$isReply = in_array(\App\modules\email\services\MailMessage::getInstance()->recall_desired_action(), ['reply', 'replyall'], true);
		if ($isReply && $returncode) $this->mark_as_replied($msgball);
		$return_to_folder_href = $this->get_return_to();
		if ($this->api_mode)
		{
			$sent = (bool) $returnccode;
			$error = $sent ? '' : (string) ($this->smtp->ErrorInfo ?? '');
			$this->send_message_cleanup();
			if (\App\modules\email\services\MailMessage::hasInstance()) \App\modules\email\services\MailMessage::releaseInstance();
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
					echo '<p>To go back to the msg list, click <a href="'. phpgw::link('/email/view/inbox', $return_to_folder_href, true).'">here</a></p><br />';
					echo '</body></html>';
					$this->send_message_cleanup();
				}
				else*/
			{
				// unset some vars (is this necessary?)
				$this->send_message_cleanup();
				// redirect the browser to the index page for the appropriate folder
				//header('Location: '.$return_to_folder_href);
				phpgw::redirect_link('/email/view/inbox', $return_to_folder_href);
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
		} else
		{
			$return_to_folder_url = phpgw::link('/email/view/inbox', $return_to_folder_href, true);
			echo '<html><body>' . "\r\n";
			echo '<h2>Your message could <b>not</b> be sent!</h2>' . "\r\n";
			echo '<h3>The mail server returned:</h3>' . "\r\n";
			echo '<pre>' . htmlspecialchars((string) ($this->smtp->ErrorInfo ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>' . "\r\n";
			echo '<p>To go back to the mail list, click <a href="' . htmlspecialchars($return_to_folder_url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">here</a></p>' . "\r\n";
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
		if ($this->debug_send > 2) return;

		$not_set = \App\modules\email\services\MailMessage::getInstance()->not_set;
		$msgball = \App\modules\email\services\MailMessage::getInstance()->get_arg_value('msgball');
		$this->mail_out = ['whitespace' => chr(9), 'is_forward' => false, 'fwd_proc' => ''];
		$is_reply = false;
		$this->smtp->CharSet = lang('charset') !== 'charset*' ? lang('charset') : 'US-ASCII';
		$this->smtp->Sender = \App\modules\email\services\MailMessage::getInstance()->get_isset_arg('sender')
			? \App\modules\email\services\MailMessage::getInstance()->get_arg_value('sender') : '';
		$this->smtp->FromName = \App\modules\email\services\MailMessage::getInstance()->get_pref_value('fullname');
		$this->smtp->From = \App\modules\email\services\MailMessage::getInstance()->get_pref_value('address');
		$this->smtp->Subject = \App\modules\email\services\MailMessage::getInstance()->stripslashes_gpc(
			\App\modules\email\services\MailMessage::getInstance()->get_arg_value('subject'));
		$this->smtp->Helo = trim((string) ($GLOBALS['phpgw_info']['server']['hostname'] ?? ''));
		$this->smtp->Body = \App\modules\email\services\MailMessage::getInstance()->stripslashes_gpc(
			trim((string) \App\modules\email\services\MailMessage::getInstance()->get_arg_value('body')));
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

		$action = \App\modules\email\services\MailMessage::getInstance()->recall_desired_action();
		if (in_array($action, ['new', 'forward'], true))
		{
			$this->smtp->Body = \App\modules\email\services\MailMessage::getInstance()->body_hard_wrap($this->smtp->Body, 78);
		}
		elseif (in_array($action, ['reply', 'replyall'], true))
		{
			$is_reply = true;
			$body_array = explode("\r\n", $this->smtp->Body);
			$body_array_count = count($body_array);
			$in_unquoted_block = false;
			$unquoted_text = '';
			$new_body = '';
			for ($bodyidx = 0; $bodyidx < $body_array_count; ++$bodyidx)
			{
				$this_line = $body_array[$bodyidx];
				$is_quoted_line = strlen($this_line) > 1
					&& $this_line[0] == \App\modules\email\services\MailMessage::getInstance()->reply_prefix[0];
				if ($is_quoted_line)
				{
					if ($in_unquoted_block)
					{
						$new_body .= \App\modules\email\services\MailMessage::getInstance()->body_hard_wrap($unquoted_text, 78);
						$unquoted_text = '';
					}
					$new_body .= $this_line . "\r\n";
					$in_unquoted_block = false;
				}
				elseif (($body_array_count - $bodyidx) == 1)
				{
					$unquoted_text .= $this_line;
					$new_body .= \App\modules\email\services\MailMessage::getInstance()->body_hard_wrap($unquoted_text, 78);
					$unquoted_text = '';
				}
				else
				{
					$in_unquoted_block = true;
					$unquoted_text .= $this_line . "\r\n";
				}
			}
			$this->smtp->Body = $new_body;
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
					$this->smtp->AddBCC($recip['plain'], $recip['personal']);
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
			'fldball[folder]' => $fldball_candidate['folder'],
			'fldball[acctnum]' => $fldball_candidate['acctnum'],
			'sort' => \App\modules\email\services\MailMessage::getInstance()->get_arg_value('sort'),
			'order' => \App\modules\email\services\MailMessage::getInstance()->get_arg_value('order'),
			'start' => \App\modules\email\services\MailMessage::getInstance()->get_arg_value('start')
		);
	}
}
