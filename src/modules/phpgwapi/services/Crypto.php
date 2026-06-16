<?php

/**
 * Handles encrypting strings based on various encryption schemes
 * @author Joseph Engo <jengo@phpgroupware.org>
 * @copyright Copyright (C) 2000-2004 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @package phpgwapi
 * @subpackage network
 * @version $Id$
 */

namespace App\modules\phpgwapi\services;
	
 $serverSettings = \App\modules\phpgwapi\services\Settings::getInstance()->get('server');

if (isset($serverSettings['enable_crypto']) && $serverSettings['enable_crypto'] == 'libsodium') {
	require_once SRC_ROOT_PATH . '/modules/phpgwapi/services/CryptoLibsodium.php';
}
else
{
	//Fall back
	class Crypto extends Crypto_
	{
       /**
         * @var Crypto reference to singleton instance
         */
        private static $instance;

        /**
         * Constructor
         */
        private function __construct()
        {
           parent::__construct();
        }

        /**
         * Gets the instance via lazy initialization (created on first usage)
         */
        public static function getInstance(): Crypto
        {
            if (null === static::$instance) {
                static::$instance = new static();
            }

            return static::$instance;
        }

        /**
         * Prevent the instance from being cloned (which would create a second instance of it)
         */
        private function __clone()
        {
        }

		/**
		 * Prevent from being unserialized (which would create a second instance of it)
		 */
		public function __wakeup()
		{
			// throw an exception to prevent unserialization
			throw new \Exception("Cannot unserialize a singleton.");
		}

	}
}

/**
 * Handles encrypting strings based on various encryption schemes
 *
 * @package phpgwapi
 * @subpackage network
 */
class Crypto_
{
	var $enabled = false;
	var $debug = false;
	var $algo;
	var $mode;
	var $td; /* Handle for mcrypt */
	var $iv = '';
	var $key = '';

	function __construct($vars = '')
	{
		if (is_array($vars)) {
			$this->init($vars);
		}
		register_shutdown_function(array(&$this, 'cleanup'));
	}

	function init($vars)
	{
	}

	function cleanup()
	{
	}

	function hex2bin($data)
	{
		$len = strlen($data);
		return pack('H' . $len, $data);
	}

	function encrypt($data, $bypass = false)
	{

		if ($data === '' || is_null($data)) {
			// no point in encrypting an empty string
			return $data;
		}

		return serialize($data);
	}

	function decrypt($encrypteddata, $bypass = false)
	{
		if ($this->debug) {
			echo '<br>' . time() . ' crypto->decrypt() crypted data: ---->>>>' . $encrypteddata;
		}

		if ($encrypteddata === '' || is_null($encrypteddata)) {
			// an empty string is always a usless empty string
			return $encrypteddata;
		}

		$data = $encrypteddata;

		if (is_array($data)) {
			// no need for unserialize
			return $data;
		}


		$newdata = @unserialize($data);
		if ($newdata || is_array($newdata)) // Check for empty array
		{
			return $newdata;
		} else {
			return $data;
		}
	}
}
