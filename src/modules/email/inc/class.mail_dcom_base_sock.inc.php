<?php

/**
 * EMail - Handles general functionality for mail/mail structures
 *
 * @author Angelo (Angles) Puglisi <angles@aminvestments.com>
 * @author Mark Peters <skeeter@phpgroupware.org>
 * @copyright Copyright (C) 2001-2002 Angelo Tony Puglisi (Angles)
 * @copyright Copyright (C) 2001-2005 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lessser General Public License
 * @package email
 * @version $Id$
 * @internal Based on AngleMail http://www.anglemail.org/
 */

/**
 * Import the the network class which we extend
 */
phpgw::import_class('phpgwapi.network');

/**
 * Set status->messages to the number of messages in the mailbox
 */
define('SA_MESSAGES', 1);
/**
 * Set status->recent to the number of recent messages in the mailbox
 */
define('SA_RECENT', 2);
/**
 * Set status->unseen to the number of unseen (new) messages in the mailbox
 */
define('SA_UNSEEN', 4);
/**
 * Set status->uidnext to the next uid to be used in the mailbox
 */
define('SA_UIDNEXT', 8);
/**
 * Set status->uidvalidity to a constant that changes when uids for the mailbox may no longer be valid
 */
define('SA_UIDVALIDITY', 16);
/**
 * Set all of the above
 */
define('SA_ALL', 31);

define('SORTDATE', 0);
define('SORTARRIVAL', 1);
define('SORTFROM', 2);
define('SORTSUBJECT', 3);
define('SORTTO', 4);
define('SORTCC', 5);
define('SORTSIZE', 6);

define('TYPETEXT', 0);
define('TYPEMULTIPART', 1);
define('TYPEMESSAGE', 2);
define('TYPEAPPLICATION', 3);
define('TYPEAUDIO', 4);
define('TYPEIMAGE', 5);
define('TYPEVIDEO', 6);
// what is defined as 7 ? , not typemodel
define('TYPEOTHER', 8);
//  define ('TYPEMODEL',
define('ENC7BIT', 0);
define('ENC8BIT', 1);
define('ENCBINARY', 2);
define('ENCBASE64', 3);
define('ENCQUOTEDPRINTABLE', 4);
define('ENCOTHER', 5);
//  ENCUU not defined in php 4, but we may use it
define('ENCUU', 6);

define('FT_UID', 1);	// the msgnum is a UID
define('FT_PEEK', 2);	// do not set the \Seen flag if not already set
define('FT_NOT', 4);	// do not fetch header lines (with IMAP_BODY)
define('FT_INTERNAL', 8); // server will not attempt to standardize CRLFs
define('FT_PREFETCHTEXT', 16); // grab the header AND its associated RFC822.TEXT

define('SE_UID', 1); // used with IMAP_SORT, IMAP_SEARCH,
define('SE_FREE', 2); // Return the search program to free storage after finishing NOT used by PHP
define('SE_NOPREFETCH', 4); // used with IMAP_SORT , don't really understand it though

// PHP 8.4+ removed constants - define PorticoEstate IMAP compatibility layer
defined('PE_IMAP_TYPETEXT') || define('PE_IMAP_TYPETEXT', 0);
defined('PE_IMAP_TYPEMULTIPART') || define('PE_IMAP_TYPEMULTIPART', 1);
defined('PE_IMAP_TYPEMESSAGE') || define('PE_IMAP_TYPEMESSAGE', 2);
defined('PE_IMAP_TYPEAPPLICATION') || define('PE_IMAP_TYPEAPPLICATION', 3);
defined('PE_IMAP_TYPEAUDIO') || define('PE_IMAP_TYPEAUDIO', 4);
defined('PE_IMAP_TYPEIMAGE') || define('PE_IMAP_TYPEIMAGE', 5);
defined('PE_IMAP_TYPEVIDEO') || define('PE_IMAP_TYPEVIDEO', 6);
defined('PE_IMAP_TYPEOTHER') || define('PE_IMAP_TYPEOTHER', 8);
defined('PE_IMAP_ENC7BIT') || define('PE_IMAP_ENC7BIT', 0);
defined('PE_IMAP_ENC8BIT') || define('PE_IMAP_ENC8BIT', 1);
defined('PE_IMAP_ENCBINARY') || define('PE_IMAP_ENCBINARY', 2);
defined('PE_IMAP_ENCBASE64') || define('PE_IMAP_ENCBASE64', 3);
defined('PE_IMAP_ENCQUOTEDPRINTABLE') || define('PE_IMAP_ENCQUOTEDPRINTABLE', 4);
defined('PE_IMAP_ENCOTHER') || define('PE_IMAP_ENCOTHER', 5);
//SE_UID	Return UIDs instead of sequence numbers
//SE_NOPREFETCH	Don't prefetch searched messages.

// This may need to be a reference to the different months in native tongue....
$GLOBALS['month_array'] = array(
	'jan' => 1,
	'feb' => 2,
	'mar' => 3,
	'apr' => 4,
	'may' => 5,
	'jun' => 6,
	'jul' => 7,
	'aug' => 8,
	'sep' => 9,
	'oct' => 10,
	'nov' => 11,
	'dec' => 12
);

/**
 * Part of mail Data Communications class
 *
 * Returns status information on a mailbox other than the current one.
 * Can be used to obtain data on a folder that is NOT currently selected (opened)
 * by the client. For pop3 this difference means nothing, for imap this means the 
 * client need NOT select (i.e. open) the target folder before requesting status 
 * data. Still, IMAP_STATUS can be used on any folder wheter it is currently 
 * selected (opened) or not.
 * Don't returns size data.
 * @package email
 */
class mailbox_status
{
	var $messages = '';
	var $recent = '';
	var $unseen = '';
	var $uidnext = '';
	var $uidvalidity = '';
	// quota and quota_all not in php builtin
	var $quota = '';
	var $quota_all = '';
}

/**
 * Get information about the current mailbox
 *
 * Only used for the folder that the client is currently logged into,
 * for pop3 this is always "INBOX", for imap this is the currently 
 * selected (opened) folder. Therefor, with imap the target folder must 
 * already be selected (via IMAP_OPEN or IMAP_REOPEN)
 * Returns size data.
 * This size data adds all the sizes of the messages in that folder 
 * together to get the total folder size.
 * Some IMAP servers can take alot of time and CPU cycles to get this total,
 * particularly with MAILDIR type imap servers such as Courier-imap, while 
 * other imap servers seem to return this size data with little difficulty.
 * @package email
 */
class mailbox_msg_info
{
	/**
	 * Date of last change
	 */
	var $Date = '';
	/**
	 * Driver
	 */
	var $Driver = '';
	/**
	 * Name of the mailbox
	 */
	var $Mailbox = '';
	/**
	 * Number of messages
	 */
	var $Nmsgs = '';
	/**
	 * Number of recent messages
	 */
	var $Recent = '';
	/**
	 * Number of unread messages
	 */
	var $Unread = '';
	/**
	 * Mailbox size
	 */
	var $Size = '';
}

/**
 * Read the structure of a particular message
 *
 * @package email
 */
class msg_structure
{
	/**
	 * Primary body type
	 */
	var $type = '';
	/**
	 * Body transfer encoding
	 */
	var $encoding = '';
	/**
	 * TRUE if there is a subtype string
	 */
	var $ifsubtype = False;
	/**
	 * MIME subtype
	 */
	var $subtype = '';
	/**
	 * TRUE if there is a description string
	 */
	var $ifdescription = False;
	/**
	 * Content description string
	 */
	var $description = '';
	/**
	 * TRUE if there is an identification string
	 */
	var $ifid = False;
	/**
	 * Identification string
	 */
	var $id = '';
	/**
	 * Number of lines
	 */
	var $lines = '';
	/**
	 * Number of bytes
	 */
	var $bytes = '';
	/**
	 * TRUE if there is a disposition string
	 */
	var $ifdisposition = False;
	/**
	 * Disposition string
	 */
	var $disposition = '';
	/**
	 * TRUE if the dparameters array exists
	 */
	var $ifdparameters = False;
	/**
	 * Disposition parameter array
	 */
	var $dparameters = array();
	/**
	 * TRUE if the parameters array exists
	 */
	var $ifparameters = False;
	/**
	 * MIME parameters array
	 */
	var $parameters = array();
	/*
		 * Custom phpgw data to aid in building this structure
		 */
	var $custom = array();
	/**
	 * Array of objects describing each message part
	 */
	var $parts = array();
}
	
	// Gonna have to decide on one of the next two

/**
 * Message parameters
 *
 * @package email
 */
class msg_params
{
	var $attribute;
	var $value;

	function __construct($attrib, $val)
	{
		$this->attribute = $attrib;
		$this->value     = $val;
	}
}

/**
 * Attribute Parameter
 *
 * @package email
 */
class att_parameter
{
	var $attribute;
	var $value;
}

/**
 * Address
 *
 * @package email
 */
class address
{
	var $personal;
	var $mailbox;
	var $host;
	var $adl;
}

/**
 * Read an overview of the information in the headers of the given message.
 *
 * @internal NOT CURRENTLY IMPLEMENTED
 * @package email
 */
class msg_overview
{
	/**
	 * The messages subject
	 */
	var $subject;
	/**
	 * who sent it
	 */
	var $from;
	/**
	 * when was it sent
	 */
	var $date;
	/**
	 * Message-ID
	 */
	var $message_id;
	/**
	 * Is a reference to this message id
	 */
	var $references;
	/**
	 * size in bytes
	 */
	var $size;
	/**
	 * UID the message has in the mailbox
	 */
	var $uid;
	/**
	 * message sequence number in the maibox
	 */
	var $msgno;
	/**
	 * this message is flagged as recent
	 */
	var $recent;
	/**
	 * this message is flagged
	 */
	var $flagged;
	/**
	 * this message is flagged as answered
	 */
	var $answered;
	/**
	 * this message is flagged for deletion
	 */
	var $deleted;
	/**
	 * this message is flagged as already read
	 */
	var $seen;
	/**
	 * this message is flagged as being a draft
	 */
	var $draft;
}

/**
 * Read the header of the message
 *
 * @package email
 */
class hdr_info_envelope
{
	// --- Various Header Data ---
	var $remail = '';
	var $date = '';
	var $subject = '';
	var $Subject = ''; // is this needed?
	var $in_reply_to = '';
	var $message_id = '';
	var $newsgroups = '';
	var $followup_to = '';
	var $references = '';
	// --- Message Flags ---
	var $Recent = '';		//  'R' if recent and seen, 'N' if recent and not seen, ' ' if not recent
	var $Unseen = '';		//  'U' if not seen AND not recent, ' ' if seen OR not seen and recent
	var $Answered = '';	//  'A' if answered, ' ' if unanswered
	var $Deleted = '';		//  'D' if deleted, ' ' if not deleted
	var $Draft = '';		//  'X' if draft, ' ' if not draft
	var $Flagged = '';		//  'F' if flagged, ' ' if not flagged
	// --- To, From, etc... Data ---
	var $toaddress = '';	// up to 1024 characters of the To: line
	var $to;			// array of these objects from the To line, containing:
	//	to->personal ; to->adl ; to->mailbox ; to->host
	// 	this applies to From, Cc, Bcc, etc... below
	var $fromaddress = '';	// up to 1024 characters of the From: line
	var $from;
	var $ccaddress = '';	// up to 1024 characters of the Cc: line
	var $cc;
	var $bccaddress = '';	// up to 1024 characters of the Bcc: line
	var $bcc;
	var $reply_toaddress = '';	// up to 1024 characters of the Reply_To: line
	var $reply_to;
	var $senderaddress = '';	// up to 1024 characters of the Sender: line
	var $sender;
	var $return_pathaddress = '';	// up to 1024 characters of the Return-Path: line
	var $return_path;
	var $udate = '';		// mail message date in unix time
	// --- Specially Formatted Data ---
	var $fetchfrom = '';	// from line formatted to fit arg "fromlength" characters
	var $fetchsubject = '';	// subject line formatted to fit arg "subjectlength" characters
	var $lines = '';
	var $size = '';
	var $Size = '';
}

/**
 * After mail_dcom_base is loaded, a top level class mail_dcom is created 
 * specifically for the necessary propocol, either POP3, IMAP, or NNTP
 *
 * @author Angles
 * @author Skeeter
 * @author Itzchak Rehberg
 * @author Joseph Engo
 * @package email
 * @ignore
 */
class mail_dcom_base_sock extends phpgwapi_network
{
	// Cached Data
	// raw message data from the server, some raw data, some exploded into a string list
	var $header_glob = '';
	var $header_glob_msgnum = '';
	var $header_array = array();
	var $header_array_msgnum = '';
	//var $body_glob = '';
	//var $body_glob_msgnum = '';
	var $body_array = array();
	var $body_array_msgnum = '';
	// structural information from our processing functions
	//var $mailbox_msg_info = '';  // caching this with POP3 is OK but will cause HAVOC with IMAP or NNTP

	// USED in IMAP SOCKETS class to cache an internally needed item, the total number messages in a folder NEEDED by sort
	// so sort does not have to call status again for data already recieved.
	var $mailbox_status = '';

	public ?msg_structure $msg_structure = null;
	var $msg_structure_msgnum = '';
	var $hdr_info_envelope;
	// server error strings *should* get stored here
	var $server_last_error_str = '';
	// server data returned to the calling function does NOT include the final OK line
	// because this line is not actually data, so put this line here for inspection if necessary
	var $server_last_ok_response = '';

	// future use
	var $refto_msg_parent;
	var $mailbox;
	var $numparts;
	var $sparts;
	var $hsub = array();
	var $bsub = array();
	var $folder = '';

	var $imap_builtin = False;
	var $force_msg_uids = False;

	// DEBUG FLAG: Debug Levels are 0=none, 1=basic, 2=detailed, 3=more detailed
	var $debug_dcom = 0;

	var $serverSettings;
	var $phpgwapi_common;
	var $userSettings;
	var $errorset;
	var $tempfile;
	var $att_files_dir;

	function __construct()
	{
		parent::__construct(true);
		$this->userSettings = \App\modules\phpgwapi\services\Settings::getInstance()->get('user');
		$this->phpgwapi_common = new \phpgwapi_common();

		$this->tempfile = "{$this->serverSettings['temp_dir']}/" . $this->phpgwapi_common->randomstring() . '.mhd';
		$this->att_files_dir = "{$this->serverSettings['temp_dir']}/{$this->userSettings['sessionid']}";
	}

	/*!
		@function distill_fq_folder
		@abstract break down a php fully qualified folder name into its seperate barts
		@param $fq_folder : string : {SERVER_NAME:PORT/OPTIONS}FOLDERNAME
		@result array structure as shown in the syntax
		@syntax this is the array return struture
		result['folder'] : string is the folder (a.k.a. mailbox) name WITHOUT the bracketed {server:port/options}
		result['svr_and_port'] string : for Internal Use
		result['server'] : string is the IP or NAME of the server
		result['port_with_junk'] string : for Internal Use
		result['port'] string is the port number
		@discussion $fq_folder name arrives as:
		{SERVER_NAME:PORT}FOLDERNAME
		OR some variation like this:
		{SERVER_NAME:PORT/pop3}FOLDERNAME
		{SERVER_NAME:PORT/imap/ssl/novalidate-cert}FOLDERNAME
		this is how php passes around this data in its builtin IMAP extensions
		this function breaks down that string into it's parts
		@author Angles
		@access private
		*/
	function distill_fq_folder($fq_folder)
	{
		// initialize return structure array
		$svr_data = array();
		$svr_data['folder'] = '';
		$svr_data['svr_and_port'] = '';
		$svr_data['server'] = '';
		$svr_data['port_with_junk'] = '';
		$svr_data['port'] = '';

		// see if we have any data to work with
		if ((!isset($fq_folder))
			|| ((trim($fq_folder) == ''))
		)
		{
			// no data, return the reliable default of INBOX
			$svr_data['folder'] = 'INBOX';
			return $svr_data;
			// we're out'a here
		}

		// see if this is indeed a fully qualified folder name
		if (strstr($fq_folder, '}') == False)
		{
			// all we have is a _simple_ folder name, no server or port info included
			$svr_data['folder'] = $fq_folder;
			return $svr_data;
			// we're out'a here
		}

		// -- (1) -- get the folder name stripped of the server string
		// folder name at this stage is  {SERVER_NAME:PORT}FOLDERNAME
		// ORsome variation like this:
		// {SERVER_NAME:PORT/pop3}FOLDERNAME
		// {SERVER_NAME:PORT/imap/ssl/novalidate-cert}FOLDERNAME
		// get everything to the right of the bracket "}", INCLUDES the bracket itself
		$svr_data['folder'] = strstr($fq_folder, '}');
		// get rid of that 'needle' "}"
		$svr_data['folder'] = substr($svr_data['folder'], 1);
		// -- (2) -- get the {SERVER_NAME:PORT} part and strip the brackets
		$svr_callstr_len = strlen($fq_folder) - strlen($svr_data['folder']);
		// start copying at position 1 skipping the opening bracket
		// and stop copying at length of {SERVER_NAME:PORT} - 2 to skip the closing beacket
		$svr_data['svr_and_port'] = substr($fq_folder, 1, $svr_callstr_len - 2);
		// -- (3)-- get the port number INCLUDING any junk that may come after it, like "/pop3/ssl/novalidate-cert"
		// "svr_and_port" at this stage is  SERVER_NAME:PORT , or SERVER_NAME:PORT/pop3  , etc...
		// get everything to the right of the colon ":", INCLUDES the colon itself
		$svr_data['port_with_junk'] = strstr($svr_data['svr_and_port'], ':');
		// get rid of that 'needle' ":"
		$svr_data['port_with_junk'] = substr($svr_data['port_with_junk'], 1);
		// -- (4)-- get the server name 
		// port_with_junk + 1 means the port number with the added 1 char length of the colon we got rid of just above
		$svr_only_len = strlen($svr_data['svr_and_port']) - strlen($svr_data['port_with_junk']);
		// $svr_only_len - 1 means leave out the 1 char length of the colon we stripped deom "port_with_junk" above
		$svr_data['server'] = substr($svr_data['svr_and_port'], 0, $svr_only_len - 1);
		// -- (5)-- get the port number , stripping any junk that _may_ be with it
		//  get everything to the right of the forst slash "/", INCLUDES the slash itself, else returns FALSE
		$port_junk = strstr($svr_data['port_with_junk'], '/');
		// test
		//$svr_data['port'] = $port_junk;
		if ($port_junk)
		{
			$port_only_len = strlen($svr_data['port_with_junk']) - strlen($port_junk);
			$svr_data['port'] = substr($svr_data['port_with_junk'], 0, $port_only_len);
		}
		else
		{
			$svr_data['port'] = trim($svr_data['port_with_junk']);
		}
		return $svr_data;
	}

	/*!
		@function read_port_glob
		@abstract used with POP3, reads data from port until we encounted param $end
		@param $end (string) is the flag to look for that tells us when to stop reading the port's data
		@param $unstuff_dots (boolean) remove POP3's extra leading dot after checking the terminator
		@result raw string of data (glob) as received from the server
		@throws \RuntimeException if the response ends before its terminator.
		@discussion POP3 servers data typically ends with special charactor(s),
		usually an empty line, i.e. a lone CRLF pair, or line that is a period "." followed br a CRLF pair
		thus we can direct this function to read the server's data until such special end flag is reached
		@author Angles, skeeter
		@access private
		*/
	function read_port_glob($end = '.', $unstuff_dots = false)
	{
		$glob_response = '';
		while ($line = $this->read_port())
		{
			if (rtrim($line, "\r\n") === $end)
			{
				return $glob_response;
			}
			$glob_response .= $unstuff_dots && str_starts_with($line, '..') ? substr($line, 1) : $line;
		}
		$this->incomplete_response();
	}

	private function incomplete_response()
	{
		if (!$this->errorset)
		{
			$this->set_error('Error', 'Incomplete Response', 'Server response ended before its terminator.');
		}
		throw new \RuntimeException('Mail server response ended before its terminator.');
	}

	/*!
		@function glob_to_array
		@abstract used with POP3, converts raw string server data into an array
		@param $data string
		@param $keep_blank_lines boolean
		@param $cut_from_here string
		@param $keep_received_lines  boolean
		@param $idx_offset (integer) where to start the returned array at (if not zero)
		@result  array
		@discussion ?
		@author Angles
		@access	private
		*/
	function glob_to_array($data, $keep_blank_lines = True, $cut_from_here = '', $keep_received_lines = True, $idx_offset = 0)
	{
		$data_array = explode("\r\n", $data);
		$return_array = array();
		for ($i = 0; $i < count($data_array); $i++)
		{
			$new_str = $data_array[$i];
			if ($cut_from_here != '')
			{
				$cut_here = strpos($new_str, $cut_from_here);
				if ($cut_here > 0)
				{
					$new_str = substr($new_str, 0, $cut_here);
				}
				else
				{
					$new_str = '';
				}
			}
			if (($keep_blank_lines == False)
				&& (trim($new_str) == '')
			)
			{
				// do noting
			}
			elseif (($keep_received_lines == False)
				&& (stristr($new_str, 'received:'))
				&& (strpos(strtolower($new_str), 'received:') == 0)
			)
			{
				// do noting
			}
			else
			{
				$new_idx = count($return_array) + $idx_offset;
				$return_array[$new_idx] = $new_str;
			}
		}
		return $return_array;
	}

	/*!
		@function convert_date_array
		@abstract ?
		@author unknown, maybe Skeeter ? Rehberg ? Engo ?
		*/
	function convert_date_array($field_list)
	{
		$new_list = array();
		//while(list($key,$value) = each($field_list))
		if (is_array($field_list))
		{
			foreach ($field_list as $key => $value)
			{
				$new_list[$key] = $this->make_udate($value);
			}
		}
		return $new_list;
	}

	/*!
		@function make_udate
		@abstract ?
		@author unknown, maybe Skeeter ? Rehberg ? Engo ?
		*/
	function make_udate($msg_date)
	{
		$pos = strpos($msg_date, ',');
		if ($pos)
		{
			$msg_date = trim(substr($msg_date, $pos + 1));
		}
		$pos = strpos($msg_date, ' ');
		$day = substr($msg_date, 0, $pos);
		$msg_date = trim(substr($msg_date, $pos));
		$month = $GLOBALS['month_array'][strtolower(substr($msg_date, 0, 3))];
		$msg_date = trim(substr($msg_date, 3));
		$pos  = strpos($msg_date, ' ');
		$year = trim(substr($msg_date, 0, $pos));
		$msg_date = trim(substr($msg_date, $pos));
		$hour = substr($msg_date, 0, 2);
		$minute = substr($msg_date, 3, 2);
		$second = substr($msg_date, 6, 2);
		$pos = strrpos($msg_date, ' ');
		$tzoff = trim(substr($msg_date, $pos));
		if (strlen($tzoff) == 5)
		{
			$diffh = substr($tzoff, 1, 2);
			$diffm = substr($tzoff, 3);
			if ((substr($tzoff, 0, 1) == '+') && is_int($diffh))
			{
				$hour -= $diffh;
				$minute -= $diffm;
			}
			else
			{
				$hour += $diffh;
				$minute += $diffm;
			}
		}
		$utime = mktime($hour, $minute, $second, $month, $day, $year);
		return $utime;
	}

	/*!
		@function ssort_prep
		@abstract ?
		@author unknown, maybe Skeeter ? Rehberg ? Engo ?
		*/
	function ssort_prep($a)
	{
		$a = strtoupper($a);
		if (strpos(' ' . $a, 'FW: ') == 1 || strpos(' ' . $a, 'RE: ') == 1)
		{
			$a_mod = substr($a, 4);
		}
		elseif (strpos(' ' . $a, 'FWD: ') == 1)
		{
			$a_mod = substr($a, 5);
		}
		else
		{
			$a_mod = $a;
		}

		while (substr($a_mod, 0, 1) == ' ')
		{
			$a_mod = substr($a_mod, 1);
		}

		//if(strpos(' '.$a_mod,'[') == 1)
		//{
		//	$a_mod = substr($a_mod,1);
		//}
		return $a_mod;
	}

	/*!
		@function ssort_ascending
		@abstract ?
		@author unknown, maybe Skeeter ? Rehberg ? Engo ?
		*/
	function ssort_ascending($a, $b)
	{
		$a_mod = $this->ssort_prep($a);
		$b_mod = $this->ssort_prep($b);
		if ($a_mod == $b_mod)
		{
			return 0;
		}
		return ($a_mod < $b_mod) ? -1 : 1;
	}

	/*!
		@function ssort_decending
		@abstract 
		*/
	function ssort_decending($a, $b)
	{
		$a_mod = $this->ssort_prep($a);
		$b_mod = $this->ssort_prep($b);
		if ($a_mod == $b_mod)
		{
			return 0;
		}
		return ($a_mod > $b_mod) ? -1 : 1;
	}

	/**
	 * Decodes Q-encoded header text, where underscores represent spaces.
	 */
	function phpGW_quoted_printable_decode($string)
	{
		$string = str_replace('_', ' ', $string);
		return quoted_printable_decode($string);
	}

	/**
	 * Retains legacy soft-line-break removal for socket header callers.
	 */
	function phpGW_quoted_printable_decode2($string)
	{
		$string = $this->phpGW_quoted_printable_decode($string);
		return preg_replace("/\=\n/", '', $string);
	}

	/**
	 * Decodes B-encoded words without changing surrounding header text.
	 */
	function decode_base64($string)
	{
		return preg_replace_callback(
			"/\=\?(.*?)\?b\?(.*?)\?\=/i",
			static function ($matches)
			{
				return base64_decode($matches[2]);
			},
			$string
		);
	}

	/**
	 * Decodes Q-encoded words without changing surrounding header text.
	 */
	function decode_qp($string)
	{
		return preg_replace_callback(
			"/\=\?(.*?)\?q\?(.*?)\?\=/i",
			function ($matches)
			{
				return $this->phpGW_quoted_printable_decode2($matches[2]);
			},
			$string
		);
	}

	/*!
		@function decode_header
		@abstract ?
		@author unknown, maybe Skeeter ? Rehberg ? Engo ?
		@param $string (string)
		@discussion Decodes encoded words for the socket address parser.
		*/
	function decode_header($string)
	{
		/* Decode from qp or base64 form */
		if (preg_match("/\=\?(.*?)\?b\?/i", $string))
		{
			return $this->decode_base64($string);
		}
		if (preg_match("/\=\?(.*?)\?q\?/i", $string))
		{
			return $this->decode_qp($string);
		}
		return $string;
	}

	/*!
		@function decode_author
		@abstract ?
		@author unknown, maybe Skeeter ? Rehberg ? Engo ?
		@discussion Extracts an address and display name from a decoded socket header.
		*/
	function decode_author($author, &$email, &$name)
	{
		/* Decode from qp or base64 form */
		$author = $this->decode_header($author);
		/* Extract real name and e-mail address */
		/* According to RFC1036 the From field can have one of three formats:
				1. Real Name <name@domain.name>
				2. name@domain.name (Real Name)
				3. name@domain.name
			*/
		/* 1st case */
		//if (eregi("(.*) <([-a-z0-9\_\$\+\.]+\@[-a-z0-9\_\.]+[-a-z0-9\_]+)>",
		if (preg_match("/(.*) <([-a-z0-9_$+.]+@[-a-z0-9_.]+[-a-z0-9_]+)>/i", $author, $regs))
		{
			$email = $regs[2];
			$name = $regs[1];
		}
		/* 2nd case */
		elseif (preg_match("/([-a-z0-9_$+.]+@[-a-z0-9_.]+[-a-z0-9_]+) ((.*))/i", $author, $regs))
		{
			//if (eregi("([-a-z0-9\_\$\+\.]+\@[-a-z0-9\_\.]+[-a-z0-9\_]+) \((.*)\)",$author, $regs))
			$email = $regs[1];
			$name = $regs[2];
		}
		/* 3rd case */
		else
		{
			$email = $author;
		}
		if ($name == '')
		{
			$name = $email;
		}
		$name = preg_replace('/^"(.*)"$/', '$1', $name);
		$name = preg_replace('/^\((.*)\)$/', '$1', $name);
	}

}
