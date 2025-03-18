<?php

use App\modules\phpgwapi\services\Log;

class Sanitizer
{

	/**
	 * Clean the inputted HTML to make sure it is free of any nasties
	 *
	 * @param string $html     the HTML to clean
	 * @param string $base_url the base URL for all links - currently not used
	 *
	 * @return string the cleaned html
	 *
	 * @internal uses HTMLPurifier a whitelist based html sanitiser and tidier
	 */
	public static function clean_html($html, $base_url = '')
	{
		$serverSettings = \App\modules\phpgwapi\services\Settings::getInstance()->get('server');
		$flags = \App\modules\phpgwapi\services\Settings::getInstance()->get('flags');

		if (!$base_url)
		{
			$base_url = $serverSettings['webserver_url'];
		}

		//require_once PHPGW_SERVER_ROOT . '/phpgwapi/inc/htmlpurifier/vendor/ezyang/htmlpurifier/library/HTMLPurifier.auto.php';

		$config = HTMLPurifier_Config::createDefault();
		$config->set('Core', 'DefinitionCache', null);
		$config->set('HTML.Doctype', 'HTML 4.01 Transitional');
		$config->set('HTML.Allowed', 'u,p,b,i,span[style],p,strong,em,li,ul,ol,div[align],br,img');
		$config->set('HTML.AllowedAttributes', 'class, src, height, width, alt, id, target, href, colspan');
		if (!empty($flags['allow_html_iframe']))
		{
			$config->set('HTML.SafeIframe', true);
			//	$config->set('URI.SafeIframeRegexp', '/^https:\/\/(www.youtube.com\/embed\/|player.vimeo.com\/video\/|use\.mazemap\.com\/)');
			//	$config->set('URI.SafeIframeRegexp', '%^https://(www.youtube.com/embed/|player.vimeo.com/video/|use.mazemap.com/)%');
			$config->set('URI.SafeIframeRegexp', '%^(https?:)?//(www\.youtube\.com/embed/|player\.vimeo\.com/video/|use\.mazemap\.com/)%');
		}

		$config->set('Attr.AllowedFrameTargets', array('_blank', '_self', '_parent', '_top'));

		//			$config->set('Core', 'CollectErrors', true);
		if (!empty($flags['allow_html_image']))
		{
			$config->set('URI.DisableExternalResources', false);
			$config->set('URI.DisableResources', false);
			$config->set(
				'URI.AllowedSchemes',
				array(
					'data'	 => true,
					'http'	 => true,
					'https'	 => true,
					'mailto' => true,
					'ftp'	 => true,
					'nntp'	 => true,
					'news'	 => true,
					'tel'	 => true
				)
			);
		}

		$purifier = new HTMLPurifier($config);

		$clean_html = $purifier->purify($html);

		//			if($html && ! $clean_html)
		//			{
		//				return $purifier->context->get('ErrorCollector')->getHTMLFormatted($config);
		//			}


		return $clean_html;
	}

	public static function sanitize($input)
	{
		if (is_array($input))
		{
			foreach ($input as $key => $value)
			{
				$input[$key] = self::sanitize($value);
			}
		}
		else
		{
			$input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
		}
		return $input;
	}

	/**
	 * Get the value of a variable
	 *
	 * @param string $var_name the name of the variable sought
	 * @param string $value_type the expected data type
	 * @param string $var_type the variable type sought
	 * @param mixed $default the default value
	 * @return mixed the sanitised variable requested
	 */
	public static function get_var($var_name, $value_type = 'string', $var_type = 'REQUEST', $default = null)
	{
		$value = null;
		switch (strtoupper($var_type))
		{
			case 'COOKIE':
				$value = $_COOKIE[$var_name] ?? null;
				break;

			case 'GET':
				$value = $_GET[$var_name] ?? null;
				break;

			case 'POST':
				// Try to get the value from $_POST first
				$value = $_POST[$var_name] ?? null;
				// If null, attempt to decode JSON from raw input
				if (is_null($value))
				{
					// Verify Content-Type header
					if (isset($_SERVER['CONTENT_TYPE']) && $_SERVER['CONTENT_TYPE'] === 'application/json')
					{
						$json_input = file_get_contents('php://input');
						$data = json_decode($json_input, true);

						// Validate and sanitize JSON data (implement your validation logic here)
						if (is_array($data) && isset($data[$var_name]))
						{
							$value = self::clean_value($data[$var_name], 'string', $default);
						}
						else
						{
							// Handle error - invalid JSON or missing key
						}
					}
					else
					{
						// Handle error - unexpected Content-Type
					}
				}
				break;

			case 'SERVER':
				$value = $_SERVER[$var_name] ?? null;
				break;

			case 'SESSION':
				$value = $_SESSION[$var_name] ?? null;
				break;

			case 'REQUEST':
			default:
				$value = $_REQUEST[$var_name] ?? null;
				break;
		}

		// Return default if value is null or false and default is provided
		if (is_null($value) && !is_null($default))
		{
			return $default;
		}

		// Return encoded JSON if requested
		if ($value_type === 'json')
		{
			return json_encode(self::clean_value($value, 'string', $default));
		}

		// Clean and return the value
		return self::clean_value($value, $value_type, $default);
	}

	public static function get_ip_address_fallback()
	{
		$ip_keys = array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED');
		foreach ($ip_keys as $key)
		{
			if (array_key_exists($key, $_SERVER) === true)
			{
				foreach (explode(',', $_SERVER[$key]) as $ip)
				{
					// trim for safety measures
					$ip = trim($ip);
					// attempt to validate IP
					if (self::validate_ip($ip, false))
					{
						return $ip;
					}
				}
			}
		}
	}


	public static function get_ip_address($strict = false)
	{
		$remote_addr = false;

		// Most reliable source - directly from server
		if (!empty($_SERVER['REMOTE_ADDR']) && self::validate_ip($_SERVER['REMOTE_ADDR'], $strict))
		{
			$remote_addr = $_SERVER['REMOTE_ADDR'];

			// Check for trusted proxy configuration
			$settings = \App\modules\phpgwapi\services\Settings::getInstance()->get('server');
			$trusted_proxies = isset($settings['trusted_proxies']) ?
				array_map('trim', explode(',', $settings['trusted_proxies'])) : [];
			// Only process proxy headers if the direct client is a trusted proxy
			if (self::is_trusted_proxy($remote_addr, $trusted_proxies) && !empty($_SERVER['HTTP_X_FORWARDED_FOR']))
			{
				// Get the IP chain from X-Forwarded-For
				$ip_chain = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));

				// Get the leftmost IP that isn't a trusted proxy (the original client)
				foreach ($ip_chain as $ip)
				{
					if (self::validate_ip($ip, $strict) && !self::is_trusted_proxy($ip, $trusted_proxies))
					{
						return $ip;
					}
				}
			}

		}

		// Fallback to other methods
		if (!$remote_addr)
		{
			$remote_addr = self::get_ip_address_fallback();
		}
		return $remote_addr;

		// Support both IPv4 and IPv6
	}
	/**
	 * Check if an IP address is in the trusted proxy list
	 * 
	 * @param string $ip IP address to check
	 * @param array $trusted_proxies List of trusted proxy IPs/CIDRs
	 * @return bool True if IP is a trusted proxy
	 */
	public static function is_trusted_proxy($ip, array $trusted_proxies)
	{
		if (empty($trusted_proxies))
		{
			return false;
		}

		// Direct IP match
		if (in_array($ip, $trusted_proxies))
		{
			return true;
		}

		// Check CIDR ranges
		foreach ($trusted_proxies as $proxy)
		{
			// Check if entry is in CIDR format (contains /)
			if (strpos($proxy, '/') !== false)
			{
				if (self::ip_in_cidr_range($ip, $proxy))
				{
					return true;
				}
			}
		}

		return false;
	}
	/**
	 * Check if an IP is within a CIDR range
	 * 
	 * @param string $ip IP address to check
	 * @param string $cidr CIDR range (e.g. 192.168.1.0/24)
	 * @return bool True if IP is in range
	 */
	public static function ip_in_cidr_range($ip, $cidr)
	{
		list($subnet, $mask) = explode('/', $cidr);

		// Convert IP addresses to binary strings
		$ip_binary = inet_pton($ip);
		$subnet_binary = inet_pton($subnet);

		if ($ip_binary === false || $subnet_binary === false)
		{
			return false;
		}

		// Get length in bits of IP address
		$ip_bits = 8 * strlen($ip_binary);

		// Verify mask is valid
		if ($mask < 0 || $mask > $ip_bits)
		{
			return false;
		}

		// Calculate how many bytes to compare (full bytes only)
		$bytes_to_compare = floor($mask / 8);

		// Compare full bytes
		if ($bytes_to_compare > 0)
		{
			$result = substr_compare($ip_binary, $subnet_binary, 0, $bytes_to_compare);
			if ($result !== 0)
			{
				return false;
			}
		}

		// Check remaining bits if mask is not divisible by 8
		$bits_to_compare = $mask % 8;
		if ($bits_to_compare > 0 && $bytes_to_compare < strlen($ip_binary))
		{
			// Get the bits from the first non-matching byte
			$ip_byte = ord(substr($ip_binary, $bytes_to_compare, 1));
			$subnet_byte = ord(substr($subnet_binary, $bytes_to_compare, 1));

			// Create a mask for the partial byte: e.g. for 3 bits, use 0xE0 (11100000)
			$mask_byte = -1 << (8 - $bits_to_compare);

			return ($ip_byte & $mask_byte) === ($subnet_byte & $mask_byte);
		}

		return true;
	}

	public static function validate_ip($ip, $strict = true)
	{
		// For production - filter private ranges
		if ($strict)
		{
			return filter_var(
				$ip,
				FILTER_VALIDATE_IP,
				FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
			) !== false;
		}

		// For development - accept all valid IPs including private ranges
		return filter_var($ip, FILTER_VALIDATE_IP) !== false;
	}
	
	/**
	 * Test (and sanitise) the value of a variable
	 *
	 * @param mixed $value the value to test
	 * @param string $value_type the expected type of the variable
	 * @return mixed the sanitised variable
	 */
	public static function clean_value($value, $value_type = 'string', $default = null)
	{
		if (is_array($value))
		{
			foreach ($value as &$val)
			{
				$val = self::clean_value($val, $value_type, $default);
			}
			return $value;
		}

		// Trim whitespace so it doesn't trip us up
		if (is_null($value))
		{
			return $default;
		}
		else
		{
			$value = trim($value);
		}

		if (preg_match('/\'$/', $value))
		{
			$error =  'SQL-injection spottet.';
			$error .= " <br/> Your IP is logged";
			$ip_address = self::get_ip_address();
			if ($_POST) //$_POST: it "could" be a valid userinput...
			{
				/*
				* Log entry - just in case..
				*/
				$log = new Log();
				$log->error(array(
					'text'	=> 'Possible SQL-injection spottet from IP: %1. Error: %2',
					'p1'	=> $ip_address,
					'p2'	=> 'input value ending with apos',
					'line'	=> __LINE__,
					'file'	=> __FILE__
				));
			}
			else
			{
				//						echo $error;
				//						$GLOBALS['phpgw_info']['flags']['xslt_app'] = false;
				//						trigger_error("$error: {$ip_address}", E_USER_ERROR);
				//						$GLOBALS['phpgw']->common->phpgw_exit();
			}
		}

		switch ($value_type)
		{
			case 'string':
			default:
				$value = self::clean_string($value);
				break;

			case 'boolean':
			case 'bool':
				if (preg_match('/^[false|0|no]$/', $value))
				{
					$value = false;
				}
				return !!$value;

			case 'float':
			case 'double':
				$value = str_replace(array(' ', ','), array('', '.'), $value);
				if ((float) $value == $value)
				{
					return (float) $value;
				}
				return (float) $default;

			case 'int':
			case 'integer':
			case 'number':
				if ((int) $value == $value)
				{
					return (int) $value;
				}
				return (int) $default;

				/* Specific string types */
			case 'color':
				$regex = array('options' => array('regexp' => '/^#([a-f0-9]{3}){1,2}$/i'));
				$filtered =  strtolower(filter_var($value, FILTER_VALIDATE_REGEXP, $regex));
				if ($filtered == strtolower($value))
				{
					return $filtered;
				}
				return (string) $default;

			case 'email':
				$filtered = filter_var($value, FILTER_VALIDATE_EMAIL);
				if ($filtered == $value)
				{
					if ($filtered)
					{
						return $filtered;
					}
					else
					{
						return $value;
					}
				}
				return (string)$default;

			case 'filename':
				if ($value != '.' || $value != '..')
				{
					$regex = array('options' => array('regexp' => '/^[a-z0-9_]+$/i'));
					$filtered =  filter_var($value, FILTER_VALIDATE_REGEXP, $regex);
					if ($filtered == $value)
					{
						return $filtered;
					}
				}
				return (string) $default;

			case 'ip':
				$filtered = filter_var($value, FILTER_VALIDATE_IP);
				if ($filtered == $value)
				{
					return $filtered;
				}

				// make the default sane
				if (!$default)
				{
					$default = '0.0.0.0';
				}

				return (string) $default;

			case 'location':
				$regex = array('options' => array('regexp' => '/^([a-z0-9_]+\.){2}[a-z0-9_]+$/i'));
				$filtered =  filter_var($value, FILTER_VALIDATE_REGEXP, $regex);
				if ($filtered == $value)
				{
					return $filtered;
				}
				return (string) $default;

			case 'url':
				$filtered = filter_var($value, FILTER_VALIDATE_URL);
				if ($filtered == $value)
				{
					if ($filtered)
					{
						return $filtered;
					}
					else
					{
						return $value;
					}
				}
				return (string) $default;

				/* only use this if you really know what you are doing */
			case 'raw':
				$value = filter_var($value, FILTER_UNSAFE_RAW);
				break;

			case 'html':
				$value = self::clean_html($value);
				break;
			case 'date':
				$value = \App\helpers\DateHelper::date_to_timestamp($value);
				if ($value)
				{
					$value -= \App\helpers\DateHelper::user_timezone();
				}
				break;
			case 'csv':
				if ($value)
				{
					$value = explode(',', $value);
					if (is_array($value))
					{
						foreach ($value as &$val)
						{
							$val = self::clean_string($val);
						}
					}
				}
				break;
		}
		return $value;
	}


	// prevent SQL-injection
	private static function clean_string($value = '')
	{
		$value = str_replace(array(';', '(', ')', '=', '--'), array('&#59;', '&#40;', '&#41;', '&#61;', '&#8722;&#8722;'), $value);
		$value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8', true);
		return $value;
	}
}
