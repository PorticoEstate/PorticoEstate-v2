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
		
		// SMTP configuration with environment variable overrides
		$this->Host = getenv('SMTP_HOST') ?: ($this->serverSettings['smtp_server'] ?? '');
		$this->Port = getenv('SMTP_PORT') ?: ($this->serverSettings['smtp_port'] ?? 25);
		$this->SMTPSecure = getenv('SMTP_SECURE') ?: ($this->serverSettings['smtpSecure'] ?? '');
		$this->CharSet = 'utf-8';
		$this->Timeout = getenv('SMTP_TIMEOUT') ?: ($this->serverSettings['smtp_timeout'] ?? 10);

		// SMTP Authentication - check environment variables first
		$smtpAuth = getenv('SMTP_AUTH') ?: ($this->serverSettings['smtpAuth'] ?? 'no');
		if ($smtpAuth === 'yes' || $smtpAuth === 'true' || $smtpAuth === '1') {
			$this->SMTPAuth = true;
			$this->Username = getenv('SMTP_USER') ?: ($this->serverSettings['smtpUser'] ?? '');
			$this->Password = getenv('SMTP_PASSWORD') ?: ($this->serverSettings['smtpPassword'] ?? '');
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

		// SMTP Debug mode - environment variable override.
		// getenv() returns false when SMTP_DEBUG is unset; an unset or empty
		// value falls through to the DB setting, while an explicit value
		// (including "0") wins outright so SMTP_DEBUG=0 can force debug OFF.
		$envSmtpDebug = getenv('SMTP_DEBUG');
		if ($envSmtpDebug !== false && $envSmtpDebug !== '') {
			$smtpDebug = $envSmtpDebug;
		} else {
			$smtpDebug = $this->serverSettings['SMTPDebug'] ?? '0';
		}
		$this->SMTPDebug = (int)$smtpDebug;

		// Debug logging for SMTP configuration - only when SMTP debug is
		// enabled, since this line includes the SMTP username.
		if ($this->SMTPDebug > 0) {
			error_log("SMTP CONFIG: Host={$this->Host}, Port={$this->Port}, Secure={$this->SMTPSecure}, Auth=" . ($this->SMTPAuth ? 'YES' : 'NO') . ", User={$this->Username}, Debug={$this->SMTPDebug}");
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
				case 'error_log':
					$this->Debugoutput =  'error_log';
					break;
				default:
			}
		}
	}
}
