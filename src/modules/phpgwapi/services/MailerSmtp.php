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

	public function postSend()
	{
		if (!MailDiskWriter::isEnabled()) return parent::postSend();

		$attachments = [];
		foreach ($this->getAttachments() as $attachment)
		{
			$attachments[] = ['name' => $attachment[2] ?? ''];
		}
		MailDiskWriter::capture(
			$this->formatAddresses($this->getToAddresses()),
			$this->Subject,
			$this->Body,
			trim($this->FromName . ' <' . $this->From . '>'),
			$this->formatAddresses($this->getCcAddresses()),
			$this->formatAddresses($this->getBccAddresses()),
			$this->ContentType === 'text/html' ? 'html' : 'plain',
			$attachments
		);
		return true;
	}

	private function formatAddresses(array $addresses): string
	{
		return implode('; ', array_map(static function (array $address): string
		{
			return !empty($address[1]) ? $address[1] . ' <' . $address[0] . '>' : $address[0];
		}, $addresses));
	}
}
