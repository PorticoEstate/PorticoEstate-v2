<?php

/**
 * Mail-to-disk capture for local development and testing.
 *
 * When enabled, every outgoing message is also written to disk as a
 * self-contained HTML file. The files are designed to be browsed with the
 * "dmail" viewer (see docker-compose service `dmail`), but they are plain
 * HTML and can equally be read with `cat` or opened in a browser.
 *
 * This is OPT-IN and defaults to OFF. Nothing changes unless MAIL_TO_DISK is
 * set to a truthy value in the environment.
 *
 * @package phpgwapi
 * @subpackage communication
 */

namespace App\modules\phpgwapi\services;

class MailDiskWriter
{
	/**
	 * Default location for captured mail. Mounted into the dmail container.
	 */
	const DEFAULT_PATH = '/var/www/html/storage/emails';

	/**
	 * Is disk capture switched on?
	 *
	 * Controlled by the MAIL_TO_DISK environment variable. Accepts
	 * 1/true/yes/on (case-insensitive). Anything else - including unset -
	 * means disabled.
	 */
	public static function isEnabled(): bool
	{
		$flag = getenv('MAIL_TO_DISK');

		if ($flag === false || $flag === '')
		{
			return false;
		}

		return in_array(strtolower(trim((string)$flag)), array('1', 'true', 'yes', 'on'), true);
	}

	/**
	 * Directory captured mail is written to.
	 */
	public static function getPath(): string
	{
		$path = getenv('MAIL_DISK_PATH');

		if ($path === false || trim((string)$path) === '')
		{
			return self::DEFAULT_PATH;
		}

		return rtrim(trim((string)$path), '/');
	}

	/**
	 * Write one message to disk.
	 *
	 * Never throws: a failure to capture mail for debugging must not break
	 * the actual send. Problems are reported via error_log only.
	 *
	 * @param string $to           recipient(s), as passed to Send::msg()
	 * @param string $subject      message subject
	 * @param string $body         message body (HTML or plain text)
	 * @param string $from         sender address / "Name <addr>" form
	 * @param string $cc           carbon copy recipients
	 * @param string $bcc          blind carbon copy recipients
	 * @param string $content_type 'html' for HTML bodies, anything else = plain text
	 * @param array  $attachments  attachment descriptors, as passed to Send::msg()
	 *
	 * @return string|false absolute path of the written file, or false on failure
	 */
	public static function capture(
		$to,
		$subject,
		$body,
		$from = '',
		$cc = '',
		$bcc = '',
		$content_type = '',
		$attachments = array()
	)
	{
		if (!self::isEnabled())
		{
			return false;
		}

		try
		{
			$path = self::getPath();

			if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path))
			{
				error_log("MailDiskWriter: unable to create mail capture directory: {$path}");
				return false;
			}

			if (!is_writable($path))
			{
				error_log("MailDiskWriter: mail capture directory is not writable: {$path}");
				return false;
			}

			$subject = (string)$subject;
			$to = (string)$to;

			$filename = sprintf(
				'%s_%s_%s.html',
				date('Y-m-d_His'),
				self::sanitize($subject !== '' ? $subject : 'no-subject'),
				self::sanitize($to !== '' ? $to : 'unknown')
			);

			$file = $path . '/' . self::uniqueName($path, $filename);

			$contents = self::renderHeader($to, $subject, $from, $cc, $bcc, $attachments)
				. self::renderBody($body, $content_type);

			if (@file_put_contents($file, $contents) === false)
			{
				error_log("MailDiskWriter: failed writing captured mail to {$file}");
				return false;
			}

			return $file;
		}
		catch (\Throwable $e)
		{
			// Capturing mail is a debugging aid - it must never break a send.
			error_log('MailDiskWriter: ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * Build the metadata banner prepended to every captured message.
	 *
	 * Emitted in two forms: an HTML comment (machine readable, parsed by the
	 * dmail viewer) and a visible block (human readable when the file is
	 * opened directly in a browser).
	 */
	private static function renderHeader($to, $subject, $from, $cc, $bcc, $attachments): string
	{
		$date = date('Y-m-d H:i:s');

		$names = array();
		if (is_array($attachments))
		{
			foreach ($attachments as $attachment)
			{
				if (!empty($attachment['name']))
				{
					$names[] = (string)$attachment['name'];
				}
				else if (!empty($attachment['file']))
				{
					$names[] = basename((string)$attachment['file']);
				}
			}
		}
		$attachmentList = implode(', ', $names);

		$meta = array(
			'From'		=> (string)$from,
			'To'		=> (string)$to,
			'Cc'		=> (string)$cc,
			'Bcc'		=> (string)$bcc,
			'Subject'	=> (string)$subject,
			'Date'		=> $date,
			'Attachments'	=> $attachmentList,
		);

		// Machine-readable block for the dmail viewer.
		$comment = "<!--\n";
		foreach ($meta as $key => $value)
		{
			if ($value === '')
			{
				continue;
			}
			// Keep the comment well-formed even if a header contains "--".
			$comment .= $key . ': ' . str_replace(array('--', "\r", "\n"), array('- -', ' ', ' '), $value) . "\n";
		}
		$comment .= "-->\n";

		// Human-readable banner.
		$rows = '';
		foreach ($meta as $key => $value)
		{
			if ($value === '')
			{
				continue;
			}
			$rows .= '<strong>' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . ':</strong> '
				. htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '<br>';
		}

		$banner = '<div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', sans-serif;'
			. ' font-size: 13px; padding: 12px 16px; margin-bottom: 16px; background: #f0f0f0;'
			. ' border-bottom: 2px solid #ccc; color: #333;">' . $rows . '</div>';

		return $comment . $banner;
	}

	/**
	 * Render the message body. Plain-text bodies are escaped and wrapped so
	 * they stay readable in a browser.
	 */
	private static function renderBody($body, $content_type): string
	{
		$body = (string)$body;

		if (strtolower((string)$content_type) === 'html')
		{
			return $body;
		}

		return '<pre style="font-family: ui-monospace, SFMono-Regular, Menlo, monospace;'
			. ' white-space: pre-wrap; word-wrap: break-word; padding: 0 16px;">'
			. htmlspecialchars($body, ENT_QUOTES, 'UTF-8')
			. '</pre>';
	}

	/**
	 * Avoid clobbering an existing capture when several messages are written
	 * within the same second.
	 */
	private static function uniqueName(string $path, string $filename): string
	{
		if (!file_exists($path . '/' . $filename))
		{
			return $filename;
		}

		$base = substr($filename, 0, -strlen('.html'));

		for ($i = 2; $i < 1000; $i++)
		{
			$candidate = "{$base}_{$i}.html";
			if (!file_exists($path . '/' . $candidate))
			{
				return $candidate;
			}
		}

		return "{$base}_" . uniqid() . '.html';
	}

	/**
	 * Make a string safe to use as part of a filename.
	 */
	private static function sanitize(string $value): string
	{
		$value = preg_replace('/[^a-zA-Z0-9@._-]/', '_', $value);

		return substr((string)$value, 0, 80);
	}
}
