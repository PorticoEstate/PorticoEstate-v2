<?php

namespace App\modules\email\services;

final class EmailMessageSerializer
{
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

			if (($part['d_instructions'] ?? '') !== 'show') continue;
			$body = (string) ($part['message_body'] ?? '');
			if ($body === '') continue;

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