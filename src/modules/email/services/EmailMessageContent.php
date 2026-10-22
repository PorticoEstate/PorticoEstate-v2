<?php

namespace App\modules\email\services;

final class EmailMessageContent
{
	public function build(object $mail, array $msgball, object $headers, callable $attachmentUrl, ?callable $formatDate = null): array
	{
		$structure = $mail->phpgw_fetchstructure($msgball, false);
		$parts = $structure ? (array) $mail->get_flat_pgw_struct($structure) : [];
		$imageUrls = [];
		foreach ($parts as $index => $part)
		{
			if (($part['m_description'] ?? '') !== 'presentable/image') continue;
			$partNumber = (string) ($part['m_part_num_mime'] ?? '');
			if ($partNumber === '') continue;
			$imageUrl = $attachmentUrl($partNumber, true);
			$parts[$index]['d_instructions'] = 'show';
			$parts[$index]['d_processed_as'] = 'image_href';
			$parts[$index]['message_body'] = '<img src="' . htmlspecialchars($imageUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" alt="">';
			$id = trim((string) ($part['id'] ?? ''), "<> \t\r\n");
			if ($id !== '') $imageUrls['cid:' . $id] = $imageUrl;
		}

		foreach ($parts as $index => $part)
		{
			if (($part['m_description'] ?? '') !== 'presentable' || str_contains((string) ($part['m_keywords'] ?? ''), 'alt_hide')) continue;
			$partNumber = (string) ($part['m_part_num_mime'] ?? '');
			if ($partNumber === '') continue;
			$partBall = $msgball;
			$partBall['part_no'] = $partNumber;
			$body = (string) $mail->phpgw_fetchbody($partBall);
			$keywords = (string) ($part['m_keywords'] ?? '');
			if (stripos($keywords, 'qprint') !== false) $body = $mail->qprint($body);
			elseif (stripos($keywords, 'base64') !== false) $body = $mail->de_base64($body);
			if (stripos((string) ($part['subtype'] ?? ''), 'html') !== false)
			{
				foreach ($imageUrls as $contentId => $url) $body = str_ireplace($contentId, htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $body);
				$parts[$index]['source_text'] = $body;
				$parts[$index]['requires_html_confirmation'] = (bool) preg_match('/<style\b[^>]*>.*?\bbody\b.*?\{.*?\}|<script\b|<iframe\b/is', $body);
			} else
			{
				$body = htmlspecialchars_decode($body, ENT_QUOTES | ENT_HTML5);
				$parts[$index]['unformatted_text'] = $mail->normalize_crlf($body);
				$body = htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
				$body = $mail->make_clickable($body, $msgball['folder'] ?? '');
				$body = nl2br($mail->normalize_crlf($body), true) . '<br /><br />';
			}
			$parts[$index]['message_body'] = $body;
			$parts[$index]['d_instructions'] = 'show';
			$parts[$index]['d_processed_as'] = 'html';
		}

		$date = $formatDate
			? $formatDate((int) ($headers->udate ?? 0))
			: (new \phpgwapi_common())->show_date((int) ($headers->udate ?? 0));
		return [
			'data' => [
				'account' => (int) ($msgball['acctnum'] ?? 0),
				'folder' => (string) ($msgball['folder'] ?? ''),
				'messageNumber' => (int) ($msgball['msgnum'] ?? 0),
				'message_subject' => htmlspecialchars_decode((string) $mail->get_subject($headers, ''), ENT_QUOTES | ENT_HTML5),
				'message_date' => (string) $date,
			],
			'parts' => $parts,
		];
	}

}