<?php

/**
 * EMail - Outgoing Messages
 *
 * @author Sigurd Nes <sigurdne at gmail.com>
 * @copyright Copyright (C) 2026 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @package email
 */

/**
 * Prepares and sends outgoing MIME messages, including signatures, attachments, forwarding, and Gmail SMTP OAuth.
 */

namespace App\modules\email\services;

use PHPMailer\PHPMailer\PHPMailer;
use App\modules\phpgwapi\services\MailDiskWriter;
use App\modules\phpgwapi\services\MailerSmtp;
use App\modules\phpgwapi\services\Settings;

final class EmailOutgoingMessage
{
	private array $attachmentDescriptors = [];
	private array $captureEnvelope = [];

	public function __construct(private ?\Closure $mailerFactory = null)
	{
	}

	public function prepare(array $payload, object $mail): PHPMailer
	{
		$mailer = $this->mailerFactory ? ($this->mailerFactory)() : new MailerSmtp();
		if (!$mailer instanceof PHPMailer)
        {
        	throw new \RuntimeException('Invalid PHPMailer factory');
        }
		if ($this->mailerFactory)
        {
        	$mailer->isSMTP();
        }
		$mailer->CharSet = 'UTF-8';
		$mailer->WordWrap = 76;

		$user = (array) Settings::getInstance()->get('user');
		$account = (int) ($payload['msgball']['acctnum'] ?? $payload['fldball']['acctnum'] ?? 0);
		$mail->set_acctnum($account);
		$from = trim((string) $mail->get_pref_value('address', $account));
		$fromOverride = getenv('SMTP_FROM_OVERRIDE');
		if ($fromOverride !== false && trim($fromOverride) !== '')
        {
        	$from = trim($fromOverride);
        }
		$fullname = trim((string) $mail->get_pref_value('fullname', $account));
		if ($from === '')
        {
        	$from = trim((string) ($user['fullname'] ?? '')) !== ''
			? (string) ($user['preferences']['email']['address'] ?? '')
			: 'NoReply@' . (string) (Settings::getInstance()->get('server')['hostname'] ?? 'localhost');
        }
		$mailer->setFrom($from, $fullname);
		$sender = trim((string) ($payload['sender'] ?? ''));
		if ($sender !== '')
        {
        	$mailer->Sender = $sender;
        }
		$mailer->Subject = (string) ($payload['subject'] ?? '');
		$mailer->isHTML(false);
		$body = htmlspecialchars_decode((string) ($payload['body'] ?? ''), ENT_QUOTES | ENT_HTML5);
		$action = (string) ($payload['action'] ?? 'new');
		if ($action !== 'forward' && !empty($payload['attach_sig']) && trim((string) $mail->get_pref_value('email_sig', $account)) !== '')
		{
			$signature = $mail->html_quotes_decode((string) $mail->get_pref_value('email_sig', $account));
			$body .= "\r\n\r\n-- \r\n" . $signature . "\r\n";
		}
		$body = $action === 'reply' || $action === 'replyall'
			? $this->wrapUnquotedReplyText($body, $mail)
			: $mail->body_hard_wrap($body, 78);
		$mailer->Body = $body;
		$mailer->addCustomHeader('X-Originating-IP: ' . $this->originatingIp());
		if (!empty($payload['req_notify']))
        {
        	$mailer->ConfirmReadingTo = $mailer->Sender ?: $from;
        }

		$to = (string) ($payload['to'] ?? '');
		$cc = (string) ($payload['cc'] ?? '');
		$bcc = (string) ($payload['bcc'] ?? '');
		$subject = $mailer->Subject;
		$redirect = getenv('REDIRECT_EMAIL');
		if ($redirect !== false && trim($redirect) !== '')
		{
			$originalRecipients = $to;
			$to = trim($redirect);
			$cc = '';
			$bcc = '';
			$subject = '[REDIRECTED] ' . $subject . ' (Original: ' . $originalRecipients . ')';
			$mailer->Subject = $subject;
		}
		$this->addAddresses($mailer, $to, 'addAddress');
		$this->addAddresses($mailer, $cc, 'addCC');
		$this->addAddresses($mailer, $bcc, 'addBCC');
		if (!$mailer->getToAddresses() && !$mailer->getCcAddresses() && !$mailer->getBccAddresses())
        {
        	$mailer->addAddress('undisclosed-recipients:;');
        }

		$storage = new EmailAttachmentStorage((string) $mail->att_files_dir);
		$attachmentDescriptors = [];
		foreach ($storage->list() as $attachment)
		{
			$path = $mail->att_files_dir . '/' . $attachment['id'];
			if (!is_file($path) || is_link($path))
            {
            	continue;
            }
			$mimeType = preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i', (string) $attachment['mimeType'])
				? $attachment['mimeType'] : 'application/octet-stream';
			$mailer->addAttachment($path, $attachment['name'], PHPMailer::ENCODING_BASE64, $mimeType);
			$attachmentDescriptors[] = ['file' => $path, 'name' => $attachment['name'], 'type' => $mimeType];
		}
		if ($action === 'forward' && isset($payload['msgball']))
		{
			$mailer->addStringAttachment($this->forwardedMessage($mail, (array) $payload['msgball']), lang('forwarded_message'), '7bit', 'message/rfc822');
		}
		$this->configureGoogleSmtp($mailer, $mail, $account);
		$this->attachmentDescriptors = $attachmentDescriptors;
		$this->captureEnvelope = ['to' => $to, 'cc' => $cc, 'bcc' => $bcc, 'subject' => $subject, 'from' => $from];
		return $mailer;
	}

	public function send(PHPMailer $mailer): array
	{
		if (!$mailer->preSend())
        {
        	throw new \RuntimeException($mailer->ErrorInfo ?: 'Unable to prepare message');
        }
		$mime = $mailer->getSentMIMEMessage();
		if (MailDiskWriter::isEnabled())
		{
			$envelope = $this->captureEnvelope;
			$files = $this->attachmentDescriptors;
			MailDiskWriter::capture($envelope['to'] ?? '', $envelope['subject'] ?? '', $mailer->Body,
				$envelope['from'] ?? '', $envelope['cc'] ?? '', $envelope['bcc'] ?? '', 'plain', $files);
			return ['sent' => true, 'mime' => $mime, 'error' => ''];
		}
		try
		{
			$mailer->postSend();
			return ['sent' => true, 'mime' => $mime, 'error' => ''];
		}
		catch (\PHPMailer\PHPMailer\Exception $exception)
		{
			return ['sent' => false, 'mime' => '', 'error' => $mailer->ErrorInfo ?: $exception->getMessage()];
		}
	}

	private function addAddresses(PHPMailer $mailer, string $value, string $method): void
	{
		if (trim($value) === '')
        {
        	return;
        }
		try
		{
			$addresses = PHPMailer::parseAddresses($value, true, 'UTF-8');
		}
        catch (\PHPMailer\PHPMailer\Exception $exception)
		{
			throw new \InvalidArgumentException('Enter valid recipient addresses', 0, $exception);
		}
		if (!$addresses)
        {
        	throw new \InvalidArgumentException('Enter valid recipient addresses');
        }
		try
		{
			foreach ($addresses as $address)
            {
            	$mailer->$method($address['address'], $address['name']);
            }
		}
        catch (\PHPMailer\PHPMailer\Exception $exception)
		{
			throw new \InvalidArgumentException('Enter valid recipient addresses', 0, $exception);
		}
	}

	private function forwardedMessage(object $mail, array $msgball): string
	{
		$account = filter_var($msgball['acctnum'] ?? null, FILTER_VALIDATE_INT);
		$number = filter_var($msgball['msgnum'] ?? null, FILTER_VALIDATE_INT);
		$folder = $msgball['folder'] ?? null;
		if ($account === false || $number === false || $number < 1 || !is_string($folder) || $folder === '')
        {
        	throw new \InvalidArgumentException('Invalid forwarded message reference');
        }
		$msgball['folder'] = $mail->prep_folder_out($folder);
		if (!$mail->ensure_stream_and_folder($msgball, 'email.api.send.forward', false))
        {
        	throw new \RuntimeException('Unable to open forwarded message');
        }
		$transport = $mail->getTransport($account);
		$options = $transport->force_msg_uids ? 1 : 0;
		$stream = $mail->get_arg_value('mailsvr_stream', $account);
		$header = \IMAPManager::imap_fetchheader($stream, $number, $options);
		$body = \IMAPManager::imap_body($stream, $number, $options | 2);
		if ($header === false || $body === false)
        {
        	throw new \RuntimeException('Unable to fetch forwarded message');
        }
		$header = $mail->normalize_crlf(trim((string) $header));
		$header = preg_replace('/^>?From\s.{1,}\r\n/im', '', $header) ?? $header;
		$header = preg_replace('/^Received:\s(.{1,}\r\n\s){0,6}.{1,}\r\n(?!\s)/im', '', $header) ?? $header;
		$header = preg_replace('/^.{0,3}Return-Path.*\r\n/im', '', $header) ?? $header;
		$body = $mail->normalize_crlf(trim((string) $body));
		return trim($header) . "\r\n\r\n" . $body;
	}

	private function configureGoogleSmtp(PHPMailer $mailer, object $mail, int $account): void
	{
		if ((string) $mail->get_pref_value('imap_auth_type', $account) !== 'google_oauth')
        {
        	return;
        }
		$emailAddress = trim((string) $mail->get_pref_value('userid', $account));
		$encryptedRefreshToken = (string) $mail->get_pref_value('passwd', $account);
		$refreshToken = $mail->decrypt_email_passwd($encryptedRefreshToken);
		$credentials = $mail->resolve_imap_credentials($account, (string) $refreshToken);
		if (($credentials['authentication'] ?? '') !== 'oauth' || empty($credentials['password']))
		{
			throw new \RuntimeException('Unable to obtain Gmail SMTP OAuth credentials');
		}

		$mailer->Host = 'smtp.gmail.com';
		$mailer->Port = 587;
		$mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
		$mailer->SMTPAuth = true;
		$mailer->AuthType = 'XOAUTH2';
		$mailer->Username = $emailAddress;
		$mailer->Password = '';
		$mailer->SMTPOptions = [
			'ssl' => [
				'verify_peer' => true,
				'verify_peer_name' => true,
				'allow_self_signed' => false,
			],
		];
		$mailer->setOAuth(new GoogleSmtpOAuthTokenProvider($emailAddress, (string) $credentials['password']));
	}

	private function originatingIp(): string
	{
		$forwarded = trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0]);
		$remote = (string) ($forwarded !== '' ? $forwarded : ($_SERVER['REMOTE_ADDR'] ?? ''));
		return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : 'not available';
	}

	private function wrapUnquotedReplyText(string $body, object $mail): string
	{
		$prefix = (string) ($mail->reply_prefix ?? '>');
		$lines = explode("\r\n", $mail->normalize_crlf($body));
		$output = [];
		$unquoted = [];
		$flush = static function () use (&$unquoted, &$output, $mail): void
		{
			if ($unquoted)
            {
            	$output[] = $mail->body_hard_wrap(implode("\r\n", $unquoted), 78);
            }
			$unquoted = [];
		};
		foreach ($lines as $line)
		{
			if (strlen($line) > 1 && $line[0] === $prefix[0])
			{
				$flush();
				$output[] = $line;
			}
            else
			{
				$unquoted[] = $line;
			}
		}
		$flush();
		return implode("\r\n", $output);
	}
}