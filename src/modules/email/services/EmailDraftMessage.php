<?php

/**
 * EMail - Draft Message Construction
 *
 * @author Sigurd Nes <sigurdne at gmail.com>
 * @copyright Copyright (C) 2026 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @package email
 */

/**
 * Builds MIME draft messages from compose content and attachments, including drafts without recipients.
 */

namespace App\modules\email\services;

use PHPMailer\PHPMailer\PHPMailer;

final class EmailDraftMessage extends PHPMailer
{
	/**
	 * Build draft MIME without requiring a final recipient list.
	 *
	 * @param array $payload The normalized compose values used to build the message.
	 * @param string $from The sender email address.
	 * @param string $name The sender display name.
	 * @param array $attachments The attachment descriptors containing source paths and MIME metadata.
	 * @return string The complete MIME message ready to append to the Drafts mailbox.
	 */
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
			if ($raw === '')
            {
            	continue;
            }
			$addresses = self::parseAddresses($raw, true, 'UTF-8');
			if (!$addresses)
            {
            	throw new \InvalidArgumentException('Enter valid recipient addresses');
            }
			foreach ($addresses as $address)
            {
            	$message->$method($address['address'], $address['name']);
            }
		}
		$message->Subject = (string) ($payload['subject'] ?? '');
		$message->Body = (string) ($payload['body'] ?? '');
		foreach ($attachments as $attachment)
        {
        	$message->addAttachment($attachment['path'], $attachment['name']);
        }
		$message->setMessageType();
		$body = $message->createBody();
		if ($body === '' && $message->isError())
        {
        	throw new \RuntimeException('Unable to build draft message');
        }
		$header = $message->createHeader();
		if ($message->getBccAddresses())
        {
        	$header .= $message->addrAppend('Bcc', $message->getBccAddresses());
        }
		return $header . "\r\n" . $body;
	}
}