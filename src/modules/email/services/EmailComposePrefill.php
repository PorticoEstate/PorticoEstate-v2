<?php

/**
 * EMail - Compose Prefill
 *
 * @author Sigurd Nes <sigurdne at gmail.com>
 * @copyright Copyright (C) 2026 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @package email
 */

/**
 * Builds compose defaults and reply or forward prefill, including recipients, quoted bodies, and attachments.
 */

namespace App\modules\email\services;

final class EmailComposePrefill
{
	/**
	 * Bind the mail runtime used to read source messages and account preferences.
	 *
	 * @param object $mail The active mail runtime used for mailbox access and header decoding.
	 */
	public function __construct(private object $mail)
	{
	}

	/**
	 * Build compose fields for a new message, reply, forward, or draft edit.
	 *
	 * @param array $query The query parameters containing compose action and mailbox references.
	 * @return array Recipient fields, subject, body, and signature state.
	 */
	public function build(array $query): array
	{
		$hasSignature = trim((string) $this->mail->get_pref_value('email_sig')) !== '';
		$prefill = [
			'to' => '', 'cc' => '', 'bcc' => '', 'subject' => '', 'body' => '',
			'has_signature' => $hasSignature, 'attach_signature' => $hasSignature,
		];
		$action = (string) ($query['action'] ?? 'new');
		$msgball = (array) ($query['msgball'] ?? []);
		if (!isset($msgball['msgnum']))
        {
        	return $this->newMessage($query, $prefill);
        }
		if (!in_array($action, ['reply', 'replyall', 'forward', 'edit'], true))
        {
        	return $prefill;
        }

		$active = (array) $this->mail->get_arg_value('msgball');
		if (!$active)
        {
        	$active = $msgball;
        }
		if (!isset($active['acctnum'], $active['folder'], $active['msgnum']))
        {
        	throw new \InvalidArgumentException('Invalid source message');
        }
		$headers = $this->mail->phpgw_header($active, false);
		if (!$headers)
        {
        	throw new \InvalidArgumentException('The source message no longer exists');
        }
		$structure = $this->mail->phpgw_fetchstructure($active, false);
		if (!$structure)
        {
        	throw new \RuntimeException('Unable to fetch source message MIME structure');
        }
		$parts = (array) $this->mail->get_flat_pgw_struct($structure);
		$firstPart = $this->firstPresentable($parts);

		switch ($action)
		{
			case 'reply':
				$replyTo = $headers->reply_to[0] ?? $headers->from[0] ?? null;
				$prefill['to'] = $replyTo ? $this->address($replyTo) : '';
				$prefill['subject'] = $this->subject($headers, 'Re: ');
				$prefill['body'] = $this->replyBody($headers, $active, $firstPart);
				break;
			case 'replyall':
				[$prefill['to'], $prefill['cc']] = $this->replyAllAddresses($headers);
				$prefill['subject'] = $this->subject($headers, 'Re: ');
				$prefill['body'] = $this->replyBody($headers, $active, $firstPart);
				break;
			case 'forward':
				$prefill['subject'] = $this->subject($headers, 'Fw: ');
				$prefill['body'] = $this->forwardBody($headers, $active, $firstPart);
				$this->restoreAttachments($active, $parts);
				break;
			case 'edit':
				if (trim((string) ($headers->Draft ?? '')) === '')
                {
                	throw new \InvalidArgumentException('The selected message is not a draft');
                }
				$prefill['to'] = $this->addresses($headers->to ?? []);
				$prefill['cc'] = $this->addresses($headers->cc ?? []);
				$prefill['bcc'] = $this->addresses($headers->bcc ?? []);
				$prefill['subject'] = $this->subject($headers);
				$prefill['body'] = $this->decodedPart($active, $firstPart);
				$this->restoreAttachments($active, $parts);
				break;
		}
		return $prefill;
	}

	/**
	 * Merge new-message query values into the initial compose defaults.
	 *
	 * @param array $query The query parameters containing compose action and mailbox references.
	 * @param array $prefill The initial compose values to populate from the query.
	 * @return array The populated new-message fields.
	 */
	private function newMessage(array $query, array $prefill): array
	{
		if (isset($query['mailto']) && is_string($query['mailto']) && $query['mailto'] !== '')
		{
			$prefill['to'] = substr($query['mailto'], 7);
		}
        elseif (!empty($query['to']) && !empty($query['personal']) && urldecode((string) $query['personal']) !== urldecode((string) $query['to']))
		{
			$prefill['to'] = '"' . str_replace('"', '\\"', urldecode((string) $query['personal'])) . '" <' . urldecode((string) $query['to']) . '>';
		}
        else
		{
			$prefill['to'] = urldecode((string) ($query['to'] ?? ''));
		}
		foreach (['cc', 'bcc', 'subject', 'body'] as $field)
        {
        	$prefill[$field] = (string) ($query[$field] ?? '');
        }
		return $prefill;
	}

	/**
	 * Choose the first suitable visible MIME body part.
	 *
	 * @param array $parts The classified MIME part descriptors for the source message.
	 * @return array The selected part descriptor or an empty array when none is suitable.
	 */
	private function firstPresentable(array $parts): array
	{
		foreach ($parts as $part)
		{
			if (($part['m_description'] ?? '') === 'presentable' && (int) ($part['bytes'] ?? 0) > 5)
            {
            	return $part;
            }
		}
		return ['m_part_num_mime' => '1', 'encoding' => '', 'subtype' => 'plain'];
	}

	/**
	 * Build quoted reply text from the source headers and selected MIME part.
	 *
	 * @param object $headers The decoded message envelope or parsed header values used by the operation.
	 * @param array $msgball The source message reference containing acctnum, folder, and msgnum.
	 * @param array $part The selected classified MIME body-part descriptor.
	 * @return string The reply attribution and quoted source body.
	 */
	private function replyBody(object $headers, array $msgball, array $part): string
	{
		$from = $headers->from[0] ?? $headers->reply_to[0] ?? null;
		$who = $from ? $this->address($from, false) : 'somebody';
		if ($from && !empty($from->personal))
        {
        	$who = $this->mail->decode_header_string($from->personal) . ' (' . $from->mailbox . '@' . $from->host . ')';
        }
		$prefix = (string) $this->mail->reply_prefix;
		return "\r\n\r\n\r\n" . $who . ' ' . $this->language('wrote') . ":\r\n" . $prefix . "\r\n" . $this->quoteBody($msgball, $part, $prefix) . "\r\n";
	}

	/**
	 * Build the forwarded body according to the account's inline-forward preference.
	 *
	 * @param object $headers The decoded message envelope or parsed header values used by the operation.
	 * @param array $msgball The source message reference containing acctnum, folder, and msgnum.
	 * @param array $part The selected classified MIME body-part descriptor.
	 * @return string The prepared forward body.
	 */
	private function forwardBody(object $headers, array $msgball, array $part): string
	{
		$from = $headers->from[0] ?? null;
		$common = new \phpgwapi_common();
		$body = "\r\n\r\nforward - original mail:\r\n  From " . ($from ? $this->address($from, false) : '')
			. "\r\n  Date " . $common->show_date((int) ($headers->udate ?? 0))
			. "\r\n  Subject " . $this->subject($headers) . "\r\n";
		if ($this->mail->get_pref_value('fwd_inline_text'))
        {
        	$body = $this->quoteBody($msgball, $part, (string) $this->mail->reply_prefix, $body . "\r\n");
        }
		return $body;
	}

	/**
	 * Build reply-all recipient lists while excluding inappropriate duplicates.
	 *
	 * @param object $headers The decoded message envelope or parsed header values used by the operation.
	 * @return array The To and Cc recipient strings.
	 */
	private function replyAllAddresses(object $headers): array
	{
		$from = $headers->from[0] ?? null;
		$replyTo = $headers->reply_to[0] ?? null;
		$reply = $replyTo && $from && $this->plainAddress($replyTo) !== $this->plainAddress($from) ? $replyTo : ($from ?? $replyTo);
		$to = $this->addresses($headers->to ?? []);
		if ($reply && !empty($headers->to))
		{
			$plain = $this->plainAddress($reply);
			if ($plain !== '' && stripos($to, $plain) === false)
            {
            	$to = $this->address($reply) . ($to === '' ? '' : ', ' . $to);
            }
		}
		return [$to, $this->addresses($headers->cc ?? [])];
	}

	/**
	 * Format a collection of transport addresses for compose input fields.
	 *
	 * @param mixed $addresses The transport-provided header address collection.
	 * @return string The formatted recipient list.
	 */
	private function addresses(mixed $addresses): string
	{
		if (!is_array($addresses))
        {
        	return '';
        }
		return implode(', ', array_filter(array_map(fn($address) => $this->address($address), $addresses)));
	}

	/**
	 * Format a transport address with its decoded personal name.
	 *
	 * @param object $address The transport address object containing personal, mailbox, and host values.
	 * @param bool $htmlEncode Whether the resulting address should be escaped for HTML output.
	 * @return string The formatted address, optionally HTML-encoded.
	 */
	private function address(object $address, bool $htmlEncode = false): string
	{
		return (string) $this->mail->make_rfc2822_address($address, $htmlEncode);
	}

	/**
	 * Join a transport address mailbox and host into an email address.
	 *
	 * @param object $address The transport address object containing personal, mailbox, and host values.
	 * @return string The plain email address.
	 */
	private function plainAddress(object $address): string
	{
		return trim((string) ($address->mailbox ?? '') . '@' . (string) ($address->host ?? ''), '@');
	}

	/**
	 * Decode the source subject and apply the requested reply or forward prefix.
	 *
	 * @param object $headers The decoded message envelope or parsed header values used by the operation.
	 * @param string $prefix The reply or forward subject prefix to apply when needed.
	 * @return string The compose subject.
	 */
	private function subject(object $headers, string $prefix = ''): string
	{
		return htmlspecialchars_decode((string) $this->mail->get_subject($headers, $prefix), ENT_QUOTES | ENT_HTML5);
	}

	/**
	 * Fetch and decode a MIME body part for plain compose text.
	 *
	 * @param array $msgball The source message reference containing acctnum, folder, and msgnum.
	 * @param array $part The selected classified MIME body-part descriptor.
	 * @param bool $stripHtml Whether HTML source content should be converted to plain compose text.
	 * @return string The decoded source text, with HTML stripped when requested.
	 */
	private function decodedPart(array $msgball, array $part, bool $stripHtml = true): string
	{
		$body = $this->fetchPart($msgball, $part);
		if ($stripHtml && ($part['subtype'] ?? '') === 'html')
        {
        	$body = \CreateObject('phpgwapi.validator')->strip_html($body);
        }
		return htmlspecialchars_decode((string) $this->mail->normalize_crlf($body), ENT_QUOTES | ENT_HTML5);
	}

	/**
	 * Retrieve a selected source-message MIME part through the mail runtime.
	 *
	 * @param array $msgball The source message reference containing acctnum, folder, and msgnum.
	 * @param array $part The selected classified MIME body-part descriptor.
	 * @return string The fetched MIME-part content.
	 */
	private function fetchPart(array $msgball, array $part): string
	{
		$msgball['part_no'] = (string) ($part['m_part_num_mime'] ?? '1');
		$body = (string) $this->mail->phpgw_fetchbody($msgball);
		if (($part['encoding'] ?? '') === 'qprint')
        {
        	$body = $this->mail->qprint($body);
		}
        elseif (($part['encoding'] ?? '') === 'base64')
        {
        	$body = $this->mail->de_base64($body);
        }
		return $body;
	}

	/**
	 * Decode a source body and prefix its lines for quoting.
	 *
	 * @param array $msgball The source message reference containing acctnum, folder, and msgnum.
	 * @param array $part The selected classified MIME body-part descriptor.
	 * @param string $quotePrefix The text prepended to each quoted source line.
	 * @param string $leading The attribution or leading text placed before the quoted body.
	 * @return string The leading attribution and quoted body.
	 */
	private function quoteBody(array $msgball, array $part, string $quotePrefix, string $leading = ''): string
	{
		$body = $this->decodedPart($msgball, $part);
		$body = preg_replace("/\r\n[-]{2}\s{0,1}\r\n\w.{0,}\r\n(.{1,}\r\n){0,4}/", "\r\n", $body) ?? $body;
		if (!preg_match('/[\r\n]/', $body))
        {
        	$body = $this->mail->body_hard_wrap($body, 74);
        }
		$lines = explode("\r\n", $body);
		foreach ($lines as $line)
        {
        	$leading .= $quotePrefix . rtrim($line) . "\r\n";
        }
		return htmlspecialchars_decode(trim($leading), ENT_QUOTES | ENT_HTML5);
	}

	/**
	 * Copy source-message attachments into the current compose store.
	 *
	 * @param array $msgball The source message reference containing acctnum, folder, and msgnum.
	 * @param array $parts The classified MIME part descriptors for the source message.
	 * @return void No value is returned.
	 */
	private function restoreAttachments(array $msgball, array $parts): void
	{
		$storage = new EmailAttachmentStorage((string) $this->mail->att_files_dir);
		foreach ($parts as $index => $part)
		{
			if (empty($part['ex_attachment']))
            {
            	continue;
            }
			$name = trim((string) ($part['ex_part_name'] ?? ''));
			if ($name === '' || $name === 'attachment.txt')
            {
            	$name = $this->language('attachment') . $index;
            }
			$data = $this->fetchPart($msgball, $part);
			$mimeType = trim((string) ($part['type'] ?? '') . '/' . (string) ($part['subtype'] ?? ''), '/');
			if (!preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i', $mimeType))
            {
            	$mimeType = 'application/octet-stream';
            }
			$storage->storeContent($data, $name, $mimeType);
		}
	}

	/**
	 * Resolve a legacy email language token for compose attribution text.
	 *
	 * @param string $key The legacy email language token to resolve.
	 * @return string The translated token value.
	 */
	private function language(string $key): string
	{
		return function_exists('lang') ? \lang($key) : $key;
	}
}