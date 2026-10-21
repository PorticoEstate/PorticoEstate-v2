<?php

namespace App\modules\email\services;

use PHPMailer\PHPMailer\PHPMailer;

final class EmailDraftMessage extends PHPMailer
{
	public static function build(array $payload, string $from, string $name, array $attachments = []): string
	{
		$message = new self(true);
		$message->CharSet = 'UTF-8';
		$message->Mailer = 'smtp';
		self::setLE("\r\n");
		$message->setFrom($from, $name);
		foreach (['to' => 'addAddress', 'cc' => 'addCC', 'bcc' => 'addBCC'] as $field => $method)
		{
			$raw = trim((string) ($payload[$field] ?? ''));
			if ($raw === '') continue;
			$addresses = self::parseAddresses($raw, true, 'UTF-8');
			if (!$addresses) throw new \InvalidArgumentException('Enter valid recipient addresses');
			foreach ($addresses as $address) $message->$method($address['address'], $address['name']);
		}
		$message->Subject = (string) ($payload['subject'] ?? '');
		$message->Body = (string) ($payload['body'] ?? '');
		foreach ($attachments as $attachment) $message->addAttachment($attachment['path'], $attachment['name']);
		$message->setMessageType();
		$body = $message->createBody();
		if ($body === '' && $message->isError()) throw new \RuntimeException('Unable to build draft message');
		$header = $message->createHeader();
		if ($message->getBccAddresses()) $header .= $message->addrAppend('Bcc', $message->getBccAddresses());
		return $header . "\r\n" . $body;
	}
}