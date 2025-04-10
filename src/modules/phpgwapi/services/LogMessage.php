<?php

/**
 * Log message
 * @author ?
 * @copyright Copyright (C) ? ?
 * @copyright Portions Copyright (C) 2004 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.fsf.org/licenses/gpl.html GNU General Public License
 * @package phpgwapi
 * @subpackage application
 * @version $Id$
 */

namespace App\modules\phpgwapi\services;

use App\modules\phpgwapi\services\Settings;

/**
 * Log message
 * 
 * @package phpgwapi
 * @subpackage application
 */
class LogMessage
{
	/***************************\
	 *	Instance Variables...   *
		\***************************/
	var $severity = 'E';
	var $msg  = 'Unknown error';
	var $timestamp;
	var $fname = '';
	var $line = 0;
	var $app = '';

	var $public_functions = array();

	function __construct($parms)
	{
		if ($parms == '')
		{
			return;
		}
		$etext = $parms['text'];
		$parray = array();
		for ($counter = 1; $counter <= 10; $counter++)
		{
			// This used to support p_1, etc, but it was not used anywhere.
			// More efficient to standardize on one way.
			$str = 'p' . $counter;
			if (isset($parms[$str]) && !empty($parms[$str]))
			{
				$parray[$counter] = $parms[$str];
			}
		}

		// This code is left in for backward compatibility with the 
		// old log code.  Consider it deprecated.
		if (!isset($parms['severity']) && preg_match('/([DIWEF])-([[:alnum:]]*)\, (.*)/i', $etext, $match))
		{
			$this->severity = strtoupper($match[1]);
			$this->msg      = trim($match[3]);
		}
		else
		{
			$this->severity = $parms['severity'];
			$this->msg = trim($etext);
		}

		$this->severity = $this->severity ? $this->severity : 'E';

		foreach ($parray as $key => $val)
		{
			$val = print_r($val, true);
			$this->msg = preg_replace("/%$key/", "'" . $val . "'", $this->msg);
		}

		$this->timestamp = time();

		if (isset($parms['line']))
		{
			$this->line  = $parms['line'];
		}
		if (isset($parms['file']))
		{
			$this->fname = str_replace(SRC_ROOT_PATH, 'path/to/portico', $parms['file']);
		}

		$flags = Settings::getInstance()->get('flags');

		if (isset($flags['currentapp']))
		{
			$this->app = $flags['currentapp'];
		}
	}
}
