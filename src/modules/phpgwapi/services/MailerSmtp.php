<?php

/**
 * phpGroupWare - phpmailer wrapper script
 * @author Dave Hall - skwashd at phpgroupware.org
 * @copyright Copyright (C) 2005 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @package phpgwapi
 * @subpackage communication
 * @version $Id$
 */

 namespace App\modules\phpgwapi\services;
/**
 * @see phpmailer
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Send email messages via SMTP
 *
 * @internal this is really just a phpgw friendly wrapper for phpmailer
 * @package phpgwapi
 * @subpackage communication
 */
class MailerSmtp extends PHPMailer
{
	/**
	 * @var array
	 */
	protected $serverSettings;
	/**
	 * Constructor
	 */
	public function __construct()
	{
		$this->serverSettings = Settings::getInstance()->get('server');

		parent::__construct(true); // enable exceptions
		$this->IsSMTP(true);
		$this->Host = $this->serverSettings['smtp_server'];
		$this->Port = isset($this->serverSettings['smtp_port']) ? $this->serverSettings['smtp_port'] : 25;
		$this->SMTPSecure = isset($this->serverSettings['smtpSecure']) ? $this->serverSettings['smtpSecure'] : '';
		$this->CharSet = 'utf-8';
		$this->Timeout = isset($this->serverSettings['smtp_timeout']) && $this->serverSettings['smtp_timeout'] ? (int)$this->serverSettings['smtp_timeout'] : 10;

		if (isset($this->serverSettings['smtpAuth']) && $this->serverSettings['smtpAuth'] == 'yes') {
			$this->SMTPAuth	= true;
			$this->Username = isset($this->serverSettings['smtpUser']) ? $this->serverSettings['smtpUser'] : '';
			$this->Password =  isset($this->serverSettings['smtpPassword']) ? $this->serverSettings['smtpPassword'] : '';
		}

		/*
			 *	http://stackoverflow.com/questions/26827192/phpmailer-ssl3-get-server-certificatecertificate-verify-failed
			 */
		$this->SMTPOptions = array(
			'ssl' => array(
				'verify_peer' => false,
				'verify_peer_name' => false,
				'allow_self_signed' => true
			)
		);
		/**
		 * SMTP class debug output mode.
		 * Options: 0 = off, 1 = commands, 2 = commands and data
		 * (`3`) As DEBUG_SERVER plus connection status
		 * (`4`) Low-level data output, all messages
		 * @type int
		 */

		if (isset($this->serverSettings['SMTPDebug'])) {
			$this->SMTPDebug = (int)$this->serverSettings['SMTPDebug'];
		}

		/**
		 * The function/method to use for debugging output.
		 * Options: 'echo', 'html' or 'error_log'
		 * @type string
		 * @see SMTP::$Debugoutput
		 */

		if (isset($this->serverSettings['Debugoutput']) && $this->serverSettings['Debugoutput'] != 'echo') {
			switch ($this->serverSettings['Debugoutput']) {
				case 'html':
					$this->Debugoutput =  'html';
					break;
				case 'errorlog':
					$this->Debugoutput =  'error_log';
					break;
				default:
			}
		}
	}
}
