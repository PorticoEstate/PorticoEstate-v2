<?php

namespace App\modules\email\controllers;

use App\modules\email\services\EmailComposeSerializer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class EmailComposeController
{
	private function json(Response $response, array $data, int $status = 200): Response
	{
		$encoded = json_encode($data);
		$response->getBody()->write($encoded === false ? '{}' : $encoded);
		return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
	}

	public function draft(Request $request, Response $response): Response
	{
		$input = $request->getParsedBody();
		if (!is_array($input)) $input = json_decode((string) $request->getBody(), true);
		$payload = EmailComposeSerializer::normalize(is_array($input) ? $input : [], false);
		if ($payload === null) return $this->json($response, ['error' => lang('Invalid draft data')], 422);
		$account = (int) ($payload['msgball']['acctnum'] ?? $payload['fldball']['acctnum'] ?? 0);
		$originalPost = $_POST;
		$_POST = ['fldball' => ['acctnum' => $account, 'folder' => 'INBOX']];
		$mail = null;
		try
		{
			$bootstrap = \CreateObject('email.msg_bootstrap');
			$bootstrap->ensure_mail_msg_exists('email.api.draft');
			$mail = \App\modules\email\services\MailMessage::getInstance();
			$enabled = array_map('intval', array_column(array_filter((array) $mail->extra_and_default_acounts,
				static fn($item) => ($item['status'] ?? '') === 'enabled'), 'acctnum'));
			if (!in_array($account, $enabled, true)) throw new \InvalidArgumentException('Select an enabled email account');
			$mail->set_acctnum($account);
			$storage = \CreateObject('email.boattach_file');
			$stored = $storage->attach(true);
			$attachments = [];
			foreach ((array) ($stored['items'] ?? []) as $attachment)
			{
				$id = (string) $attachment['id'];
				if (basename($id) !== $id) throw new \RuntimeException('Invalid attachment reference');
				$path = $storage->uploaddir . '/' . $id;
				if (!is_file($path) || is_link($path)) throw new \RuntimeException('Attachment no longer exists');
				$attachments[] = ['path' => $path, 'name' => $attachment['name']];
			}
			$raw = \App\modules\email\services\EmailDraftMessage::build($payload,
				(string) $mail->get_pref_value('address', $account), (string) $mail->get_pref_value('fullname', $account), $attachments);
			$mail->ensure_stream_and_folder(['acctnum' => $account, 'folder' => 'INBOX'], 'email.api.draft', false);
			$mail->get_folder_list($account, true);
			$folder = $mail->folder_lookup('', 'Drafts', $account);
			if ($folder === '')
			{
				$folder = $mail->get_folder_long('Drafts');
				if (!$mail->phpgw_createmailbox_ex(['acctnum' => $account, 'folder' => $mail->prep_folder_out($folder)], false)) throw new \RuntimeException('Unable to create Drafts folder');
			}
			$stream = $mail->get_arg_value('mailsvr_stream', $account);
			$path = $mail->get_arg_value('mailsvr_callstr', $account) . $mail->getTransport($account)->utf7_encode($folder);
			if (!\IMAPManager::imap_append($stream, $path, $raw, '\\Draft \\Seen')) throw new \RuntimeException('Unable to save draft');
			foreach (['msgball_list', 'folder_status_info'] as $key) $mail->expire_session_cache_item($key, $account, $mail->prep_folder_out($folder));
			$mail->expire_session_cache_item('folder_list', $account);
			return $this->json($response, ['saved' => true, 'folder' => $folder]);
		}
		catch (\InvalidArgumentException | \App\modules\email\services\EmailMailConfigurationException $exception)
		{
			return $this->json($response, ['error' => $exception->getMessage()], 422);
		}
		catch (\Throwable $exception)
		{
			error_log('[email] EmailComposeController::draft failed (' . get_class($exception) . ')');
			return $this->json($response, ['error' => lang('Unable to save draft')], 500);
		}
		finally
		{
			if (is_object($mail)) $mail->end_request();
			if (\App\modules\email\services\MailMessage::hasInstance()) \App\modules\email\services\MailMessage::releaseInstance();
			$_POST = $originalPost;
		}
	}

	public function send(Request $request, Response $response): Response
	{
		$input = $request->getParsedBody();
		if (!is_array($input))
		{
			$input = json_decode((string) $request->getBody(), true);
		}
		$payload = EmailComposeSerializer::normalize(is_array($input) ? $input : []);
		if ($payload === null)
		{
			return $this->json($response, ['error' => lang('Provide at least one recipient and valid message data')], 422);
		}

		$originalPost = $_POST;
		$_POST = $payload;
		try
		{
			$businessObject = \CreateObject('email.bosend');
			$businessObject->api_mode = true;
			$result = $businessObject->send();
			if (empty($result['sent']))
			{
				error_log('[email] Message submission failed');
				return $this->json($response, ['error' => lang('Unable to send message')], 502);
			}
			return $this->json($response, ['sent' => true]);
		}
		catch (\App\modules\email\services\EmailMailConfigurationException $exception)
		{
			if (\App\modules\email\services\MailMessage::hasInstance())
			{
				\App\modules\email\services\MailMessage::getInstance()->end_request();
				\App\modules\email\services\MailMessage::releaseInstance();
			}
			$message = $exception->getMessage();
			return $this->json($response, ['error' => $message, 'reason' => $message], 422);
		}
		catch (\Throwable $exception)
		{
			error_log('[email] EmailComposeController::send failed (' . get_class($exception) . ')');
			if (\App\modules\email\services\MailMessage::hasInstance() && \App\modules\email\services\MailMessage::hasInstance())
			{
				\App\modules\email\services\MailMessage::getInstance()->end_request();
				\App\modules\email\services\MailMessage::releaseInstance();
			}
			return $this->json($response, ['error' => lang('Unable to send message')], 500);
		}
		finally
		{
			$_POST = $originalPost;
		}
	}
}
