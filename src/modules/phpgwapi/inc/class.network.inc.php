<?php

/**
 * Handles opening network socket connections, taking proxy into account
 * @author Mark Peters <skeeter@phpgroupware.org>
 * @copyright Copyright (C) 2000-2002 Mark Peters
 * @copyright Portions Copyright (C) 2003,2004 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.fsf.org/licenses/lgpl.html GNU Lesser General Public License
 * @package phpgwapi
 * @subpackage network
 * @version $Id$
 */

use App\modules\phpgwapi\services\Settings;


/**
 * Handles opening network socket connections, taking proxy into account
 * 
 * @package phpgwapi
 * @subpackage network
 */
class phpgwapi_network
{
	var $socket;
	var $addcrlf = TRUE;
	var $error;
	var $errorset = 0;
	var $serverSettings;

	function __construct($addcrlf = true)
	{
		$this->serverSettings = Settings::getInstance()->get('server');
		$this->errorset = 0;
		$this->set_addcrlf($addcrlf);
	}

	function set_addcrlf($value)
	{
		$this->addcrlf = $value;
	}

	function add_crlf($str)
	{
		if ($this->addcrlf)
		{
			$str .= "\r\n";
		}
		return $str;
	}

	function set_error($code, $msg, $desc)
	{
		$this->error = array('code', 'msg', 'desc');
		$this->error['code'] = $code;
		$this->error['msg'] = $msg;
		$this->error['desc'] = $desc;
		//		$this->close_port();
		$this->errorset = 1;
		return 0;
	}

	function open_port($server, $port, $timeout = 15)
	{
		switch ($port)
		{
			case 80:
			case 443:
				if ((isset($this->serverSettings['httpproxy_server']) && $this->serverSettings['httpproxy_server']) &&
					(isset($this->serverSettings['httpproxy_port']) && $this->serverSettings['httpproxy_port'])
				)
				{
					$server = $this->serverSettings['httpproxy_server'];
					$port   = (int)$this->serverSettings['httpproxy_port'];
				}
				break;
		}
		$this->socket = @fsockopen($server, $port, $errcode, $errmsg, $timeout);
		if ($this->socket)
		{
			stream_set_timeout($this->socket, $timeout, 0);
		}
		if (!$this->socket)
		{
			return $this->set_error('Error', $errcode . ':' . $errmsg, 'Connection to ' . $server . ':' . $port . ' failed - could not open socket.');
		}
		else
		{
			return 1;
		}
	}

	function close_port()
	{
		$socket = $this->socket;
		$this->socket = null;
		return is_resource($socket) ? fclose($socket) : true;
	}

	function read_port()
	{
		if (!is_resource($this->socket))
		{
			$this->set_error('Error', 'Connection Lost', 'Cannot read from a closed socket.');
			return false;
		}
		$line = fgets($this->socket);
		return $this->check_read_result($line);
	}

	function bs_read_port($bytes)
	{
		if (!is_resource($this->socket))
		{
			$this->set_error('Error', 'Connection Lost', 'Cannot read from a closed socket.');
			return false;
		}
		return $this->check_read_result(fread($this->socket, $bytes));
	}

	private function check_read_result($data)
	{
		$metadata = stream_get_meta_data($this->socket);
		if (!empty($metadata['timed_out']))
		{
			$this->set_error('Error', 'Read Timeout', 'Timed out while reading from server.');
			return false;
		}
		if ($data === false || $data === '')
		{
			$this->set_error('Error', !empty($metadata['eof']) ? 'End Of Stream' : 'Read Failed',
				'Unable to read more data from server.');
			return false;
		}
		return $data;
	}

	function write_port($str)
	{
		return $this->write_data($this->add_crlf($str));
	}

	function bs_write_port($str, $bytes = 0)
	{
		$data = $this->add_crlf($str);
		return $this->write_data($bytes ? substr($data, 0, $bytes) : $data);
	}

	private function write_data($data)
	{
		if (!is_resource($this->socket))
		{
			return $this->set_error('Error', 'Connection Lost', 'lost connection to server');
		}
		$length = strlen($data);
		if ($length === 0)
		{
			return $this->set_error('Error', 'Write Failed', 'No data supplied to write.');
		}
		$offset = 0;
		while ($offset < $length)
		{
			$written = fwrite($this->socket, substr($data, $offset));
			if ($written === false || $written === 0)
			{
				return $this->set_error('Error', 'Connection Lost', 'lost connection to server');
			}
			$offset += $written;
		}
		return 1;
	}

	function msg2socket($str, $expected_response, &$response)
	{
		if (!is_resource($this->socket))
		{
			return $this->set_error(
				'521',
				'socket does not exist',
				'The required socked does not exist.  The settings for your mail server may be wrong.'
			);
		}
		if (!$this->write_port($str))
		{
			if (substr($expected_response, 1, 1) == '+')
			{
				return $this->set_error('420', 'lost connection', 'Lost connection to pop server.');
			}
			else
			{
				return 0;
			}
		}
		$response = $this->read_port();
		if ($response === false)
		{
			return 0;
		}
		if (!preg_match("/$expected_response/i", $response))
		{
			if (substr($expected_response, 1, 1) == '+')
			{
				return $this->set_error('550', '', '');
			}
			$pos = strpos($response, ' ');
			return $this->set_error(
				$pos === false ? trim($response) : substr($response, 0, $pos),
				'invalid response(' . $expected_response . ')',
				$pos === false ? '' : substr($response, $pos + 1)
			);
		}
		else
		{
			return 1;
		}
	}

	// return contents of a web url as an array or false if timeout
	function gethttpsocketfile($file, $user = '', $passwd = '')
	{
		$server = str_replace(array('http://', 'https://'), '', $file);
		$file = strstr($server, '/');
		$server = str_replace($file, '', $server);
		$lines = array();

		//allows for access to http-auth pages - added by Dave Hall <dave.hall@mbox.com.au>
		if (!((empty($user)) && (empty($passwd))))
		{
			$auth = 'Authorization: Basic ' . base64_encode("$user:$passwd") . "\n";
		}
		else
		{
			$auth = '';
		}

		if (isset($this->serverSettings['httpproxy_server']) && $this->serverSettings['httpproxy_server'])
		{
			if ($this->open_port($server, 80, 15))
			{
				if (! $this->write_port('GET https://' . $server . $file . ' HTTP/1.0' . "\n" . $auth . "\r\n\r\n"))
				{
					return False;
				}
				$i = 0;
				while ($line = $this->read_port())
				{
					if (feof($this->socket))
					{
						break;
					}
					$lines[] = $line;
					$i++;
				}
				$this->close_port();
				return $lines;
			}
			else
			{
				return False;
			}
		}
		else
		{
			if ($this->open_port($server, 80, 15))
			{
				if (!$this->write_port('GET ' . $file . ' HTTP/1.0' . "\n" . 'Host: ' . $server . "\n" . $auth . "\r\n\r\n"))
				{
					return 0;
				}
				while ($line = $this->read_port())
				{
					$lines[] = $line;
				}
				$this->close_port();
				return $lines;
			}
			else
			{
				return 0;
			}
		}
	}
}
