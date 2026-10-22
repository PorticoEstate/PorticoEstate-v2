<?php

namespace App\modules\phpgwapi\services;

use PHPMailer\PHPMailer\PHPMailer;

class MailerSmtp extends PHPMailer
{
	protected $serverSettings;

	public function __construct()
	{
		$this->serverSettings = Settings::getInstance()->get('server');
		parent::__construct(true);
		PHPMailerSmtpConfigurator::configure($this, $this->serverSettings);
	}
}
