<?php
require_once __DIR__ . '/imap_config.php';
/**
 * EMail - Data Communications Core Functions
 *
 * This file designed to work as part of a drop in email module for phpgroupware
 * @author Angelo (Angles) Puglisi <angles@aminvestments.com>
 * @copyright Copyright (C) 2001-2002 Angelo Tony Puglisi (Angles)
 * @copyright Copyright (C) 2003-2005 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @package email
 * @version $Id$
 * @internal Based on AngleMail http://www.anglemail.org/
 */

use App\modules\phpgwapi\services\Settings;

/**
 * E-Mail Data Communications API
 *
 * Part of a group of files which together comprise the "Data Communications"
 * API for AngleMail. Certain files are loaded if IMAP is compiled into php, 
 * while other files are loaded if the sockets replacement functions are needed. 
 * @package email
 */
class mail_dcom_base
{
	var $msg_struct;
	var $err = array("code", "msg", "desc");
	var $msg_info = array(array());

	var $tempfile;
	var $folder_list_changed = False;
	var $enable_utf7 = False;
	var $imap_builtin = True;
	var $force_msg_uids = False;
	//var $att_files_dir;
	var $force_check;

	var $boundary,
		$got_structure;

	/*!
		@function mail_dcom_base
		@abstract *constructor*
		@author Angles
		@access private
		*/
	function __construct()
	{
		$phpgwapi_common = new \phpgwapi_common();
		$serverSettings = Settings::getInstance()->get('server');
		$this->err["code"] = " ";
		$this->err["msg"]  = " ";
		$this->err["desc"] = " ";
		$this->tempfile = "{$serverSettings['temp_dir']}/" . $phpgwapi_common->randomstring() . '.mhd';
		$this->force_check = false;
		$this->got_structure = false;
	}

	/*!
		@function utf7_encode
		@abstract ?
		@author Angles
		@access private
		*/
	function utf7_encode($data, $called_by = 'not_provided')
	{
		// handle utf7 encoding of folder names, if necessary
		if (($this->enable_utf7 == False)
			|| (function_exists('imap_utf7_encode') == False)
			|| (!isset($data))
		)
		{
			return $data;
		}

		// data to and from the server can be either array or string
		if (gettype($data) == 'array')
		{
			// array data
			$return_array = array();
			for ($i = 0; $i < count($data); $i++)
			{
				$return_array[$i] = $this->utf7_encode_string($data[$i]);
			}
			return $return_array;
		}
		elseif (gettype($data) == 'string')
		{
			// string data
			$return_string = $this->utf7_encode_string($data);
			return $return_string;
		}
		else
		{
			// ERROR
			return $data;
		}
	}

	/*!
		@function utf7_encode_string
		@abstract ?
		@author Angles
		@access private
		*/
	function utf7_encode_string($data_str)
	{
		$name = array();
		$name['folder_before'] = '';
		$name['folder_after'] = '';
		$name['translated'] = '';

		if (strstr($data_str, '}'))
		{
			// folder name at this stage is  {SERVER_NAME:PORT}FOLDERNAME
			// get everything to the right of the bracket "}", INCLUDES the bracket itself
			$name['folder_before'] = strstr($data_str, '}');
			// get rid of that 'needle' "}"
			$name['folder_before'] = substr($name['folder_before'], 1);
			// translate
			if (function_exists('recode_string') == False)
			{
				$name['folder_after'] = IMAPManager::imap_utf7_encode($name['folder_before']);
			}
			else
			{
				// Modif UTF-8 by Sam Przyswa so now compatible with MS-Outlook and Netscape accentued folder name
				$name_tmp = iconv("ISO-8859-1", "UTF-7", $name['folder_before']);
				$name['folder_after'] = str_replace("+", "&", $name_tmp);
			}
			// replace old folder name with new folder name
			$name['translated'] = str_replace($name['folder_before'], $name['folder_after'], $data_str);
		}
		else
		{
			// folder name at this stage is  FOLDERNAME
			// there is NO {SERVER} part in this name, this is OK some commands do not require it (mail_move same acct)
			$name['folder_before'] = $data_str;
			// translate
			$name['folder_after'] = IMAPManager::imap_utf7_encode($name['folder_before']);
			$name['translated'] = $name['folder_after'];
		}
		return $name['translated'];
	}

	/*!
		@function utf7_decode
		@abstract ?
		@author Angles
		@access private
		*/
	function utf7_decode($data)
	{
		// handle utf7 decoding of folder names, if necessary
		if (($this->enable_utf7 == False)
			|| (function_exists('imap_utf7_decode') == False)
			|| (!isset($data))
		)
		{
			return $data;
		}

		// data to and from the server can be either array or string
		if (gettype($data) == 'array')
		{
			// array data
			$return_array = array();
			for ($i = 0; $i < count($data); $i++)
			{
				$return_array[$i] = $this->utf7_decode_string($data[$i]);
			}
			return $return_array;
		}
		elseif (gettype($data) == 'string')
		{
			// string data
			$return_string = $this->utf7_decode_string($data);
			return $return_string;
		}
		else
		{
			// ERROR
			return $data;
		}
	}

	/*!
		@function utf7_decode_string
		@abstract ?
		@author Angles
		@access private
		*/
	/*
		function utf7_decode_string($data_str)
		{
			$name = Array();
			$name['folder_before'] = '';
			$name['folder_after'] = '';
			$name['translated'] = '';
			
			// folder name at this stage is  {SERVER_NAME:PORT}FOLDERNAME
			// get everything to the right of the bracket "}", INCLUDES the bracket itself
			$name['folder_before'] = strstr($data_str,'}');
			// get rid of that 'needle' "}"
			$name['folder_before'] = substr($name['folder_before'], 1);
			// translate
			$name['folder_after'] = IMAPManager::imap_utf7_decode($name['folder_before']);
			// "imap_utf7_decode" returns False if no translation occured
			if ($name['folder_after'] == False)
			{
				// no translation occured
				return $data_str;
			}
			else
			{
				// replace old folder name with new folder name
				$name['translated'] = str_replace($name['folder_before'], $name['folder_after'], $data_str);
				return $name['translated'];
			}
		}
		*/
	function utf7_decode_string($data_str)
	{
		$name = array();
		$name['folder_before'] = '';
		$name['folder_after'] = '';
		$name['translated'] = '';

		if (strstr($data_str, '}'))
		{
			// folder name at this stage is  {SERVER_NAME:PORT}FOLDERNAME
			// get everything to the right of the bracket "}", INCLUDES the bracket itself
			$name['folder_before'] = strstr($data_str, '}');
			// get rid of that 'needle' "}"
			$name['folder_before'] = substr($name['folder_before'], 1);
			// translate
			if (function_exists('recode_string') == False)
			{
				$name['folder_after'] = IMAPManager::imap_utf7_decode($name['folder_before']);
			}
			else
			{
				// Modif UTF-8 by Sam Przyswa so now compatible with MS-Outlook and Netscape accentued folder name
				$name_tmp = str_replace("&", "+", $name['folder_before']);
				$name['folder_after'] = iconv("UTF-7", "ISO-8859-1", $name_tmp);
			}
			// "imap_utf7_decode" returns False if no translation occured (supposed to, can return identical string too)
			if (($name['folder_after'] == False)
				|| ($name['folder_before'] == $name['folder_after'])
			)
			{
				// no translation occured
				return $data_str;
			}
			else
			{
				// replace old folder name with new folder name
				$name['translated'] = str_replace($name['folder_before'], $name['folder_after'], $data_str);
				return $name['translated'];
			}
		}
		else
		{
			// folder name at this stage is  FOLDERNAME
			// there is NO {SERVER} part in this name, 
			// DOES THIS EVER HAPPEN comming *from* the server? I DO NOT THINK SO, but just in case
			// translate
			$name['translated'] = IMAPManager::imap_utf7_decode($data_str);
			// "imap_utf7_decode" returns False if no translation occured
			if (($name['translated'] == False)
				|| ($name['folder_before'] == $data_str)
			)
			{
				// no translation occured
				return $data_str;
			}
			else
			{
				return $name['translated'];
			}
		}
	}

	/*!
		@function folder_list_did_change
		@abstract if folder is created, deleted, or renamed this this function handles cleanup of stale data in main msg object. 
		@author Angles
		*/
	function folder_list_did_change()
	{
		// NOTE THIS FLAG "folder_list_changed" IS NOW OBSOLETED SINCE THIS 
		// CALLBACK FUNCTION IS PROVEN TO WORK
		$this->folder_list_changed = True;
		if (\App\modules\email\services\MailMessage::hasInstance())
		{
			// call that classes "callback" function designed to handle cleaning stale folder_list there
			$sucess = \App\modules\email\services\MailMessage::getInstance()->folder_list_change_callback();
			// if it was handled correctly, then reset the "folder_list_changed" because we did our job
			if ($sucess)
			{
				$this->folder_list_changed = False;
			}
		}
	}

} // end of class mail_dcom
