<?php

/**
 * EMail - Message Serialization
 *
 * @author Sigurd Nes <sigurdne at gmail.com>
 * @copyright Copyright (C) 2026 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @package email
 */

/**
 * Serializes message headers, sanitized display bodies, and attachment descriptors for the message API.
 */

namespace App\modules\email\services;

final class EmailMessageSerializer
{
	/**
	 * Convert prepared message data and MIME parts into sanitized API content.
	 *
	 * @param array $message Prepared account, message-reference, subject, and date values.
	 * @param array $parts The classified MIME part descriptors for the source message.
	 * @param array $headers The decoded message envelope or parsed header values used by the operation.
	 * @return array The message envelope, display bodies, and attachment descriptors.
	 */
	public static function serialize(array $message, array $parts, array $headers = []): array
	{
		$bodyParts = [];
		$attachments = [];
		foreach ($parts as $part)
		{
			$part = (array) $part;
			if (($part['d_processed_as'] ?? '') === 'image_href' && !empty($part['message_body']))
			{
				$bodyParts[] = [
					'html' => \Sanitizer::clean_html((string) $part['message_body']),
					'mimeType' => trim((string) ($part['type'] ?? '') . '/' . (string) ($part['subtype'] ?? ''), '/'),
					'text' => null,
					'source' => null,
					'requiresConfirmation' => false,
				];
			}
			if (!empty($part['ex_attachment']))
			{
				$attachments[] = [
					'partNumber' => (string) ($part['m_part_num_mime'] ?? ''),
					'fileName' => (string) ($part['ex_part_name'] ?? ''),
					'size' => (int) ($part['bytes'] ?? 0),
					'mimeType' => trim((string) ($part['type'] ?? '') . '/' . (string) ($part['subtype'] ?? ''), '/'),
					'encoding' => strtolower((string) ($part['encoding'] ?? '')),
				];
				continue;
			}

			if (($part['d_instructions'] ?? '') !== 'show')
            {
            	continue;
            }
			$body = (string) ($part['message_body'] ?? '');
			if ($body === '')
            {
            	continue;
            }

			$bodyParts[] = [
				'html' => \Sanitizer::clean_html($body),
				'mimeType' => trim((string) ($part['type'] ?? '') . '/' . (string) ($part['subtype'] ?? ''), '/'),
				'text' => isset($part['unformatted_text']) ? (string) $part['unformatted_text'] : null,
				'source' => !empty($part['requires_html_confirmation']) ? (string) ($part['source_text'] ?? '') : null,
				'requiresConfirmation' => !empty($part['requires_html_confirmation']),
			];
		}

		return [
			'account' => (int) ($message['account'] ?? 0),
			'folder' => (string) ($message['folder'] ?? ''),
			'messageNumber' => (int) ($message['messageNumber'] ?? 0),
			'subject' => (string) ($message['message_subject'] ?? ''),
			'date' => (string) ($message['message_date'] ?? ''),
			'from' => (array) ($headers['from'] ?? []),
			'to' => (array) ($headers['to'] ?? []),
			'cc' => (array) ($headers['cc'] ?? []),
			'bodyParts' => $bodyParts,
			'attachments' => $attachments,
		];
	}
}