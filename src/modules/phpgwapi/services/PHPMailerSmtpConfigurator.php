<?php

namespace App\modules\phpgwapi\services;

use PHPMailer\PHPMailer\PHPMailer;

final class PHPMailerSmtpConfigurator
{
	public static function configure(PHPMailer $mailer, ?array $serverSettings = null): void
	{
		$serverSettings ??= (array) Settings::getInstance()->get('server');
		$mailer->isSMTP();
		$mailer->Host = getenv('SMTP_HOST') ?: ($serverSettings['smtp_server'] ?? '');
		$mailer->Port = (int) (getenv('SMTP_PORT') ?: ($serverSettings['smtp_port'] ?? 25));
		$mailer->SMTPSecure = getenv('SMTP_SECURE') ?: ($serverSettings['smtpSecure'] ?? '');
		$mailer->CharSet = 'utf-8';
		$mailer->Timeout = (int) (getenv('SMTP_TIMEOUT') ?: ($serverSettings['smtp_timeout'] ?? 10));
		$smtpAuth = getenv('SMTP_AUTH') ?: ($serverSettings['smtpAuth'] ?? 'no');
		if (in_array(strtolower((string) $smtpAuth), ['yes', 'true', '1'], true))
		{
			$mailer->SMTPAuth = true;
			$mailer->Username = getenv('SMTP_USER') ?: ($serverSettings['smtpUser'] ?? '');
			$mailer->Password = getenv('SMTP_PASSWORD') ?: ($serverSettings['smtpPassword'] ?? '');
		}
		$mailer->SMTPOptions = [
			'ssl' => [
				'verify_peer' => false,
				'verify_peer_name' => false,
				'allow_self_signed' => true,
			],
		];
		$envDebug = getenv('SMTP_DEBUG');
		$mailer->SMTPDebug = (int) (($envDebug !== false && $envDebug !== '') ? $envDebug : ($serverSettings['SMTPDebug'] ?? '0'));
		if (isset($serverSettings['Debugoutput']) && $serverSettings['Debugoutput'] !== 'echo')
		{
			if (in_array($serverSettings['Debugoutput'], ['html', 'errorlog', 'error_log'], true))
			{
				$mailer->Debugoutput = $serverSettings['Debugoutput'] === 'html' ? 'html' : 'error_log';
			}
		}
	}
}