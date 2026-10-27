<?php

namespace App\modules\email\controllers;

use App\modules\email\services\EmailComposeSerializer;
use App\modules\email\services\EmailAccountDirectory;
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

	public function compose(Request $request, Response $response): Response
	{
		$originalGet = $_GET;
		$originalPost = $_POST;
		$originalRequest = $_REQUEST;
		$query = $request->getQueryParams();
		$_GET = $query;
		$_REQUEST = array_replace($originalPost, $query);
		try
		{
			$bootstrap = \CreateObject('email.msg_bootstrap');
			$bootstrap->ensure_mail_msg_exists('email.api.compose');
			$mail = \App\modules\email\services\MailMessage::getInstance();
			$prefill = (new \App\modules\email\services\EmailComposePrefill($mail))->build($query);
			$prefill['spellcheck_available'] = \App\modules\email\services\EmailSpellcheckService::available($this->spellcheckLanguage());
			return $this->json($response, $prefill);
		}
		catch (\InvalidArgumentException $exception)
		{
			return $this->json($response, ['error' => $exception->getMessage()], 422);
		}
		catch (\Throwable $exception)
		{
			error_log('[email] EmailComposeController::compose failed (' . get_class($exception) . ')');
			return $this->json($response, ['error' => lang('Unable to prepare message')], 500);
		}
		finally
		{
			try
			{
				\App\modules\email\services\MailMessage::releaseInstance();
			}
			finally
			{
				$_GET = $originalGet;
				$_POST = $originalPost;
				$_REQUEST = $originalRequest;
			}
		}
	}

	public function spellcheck(Request $request, Response $response): Response
	{
		$input = $request->getParsedBody();
		if (!is_array($input))
        {
        	$input = json_decode((string) $request->getBody(), true);
        }
		$body = is_array($input) ? ($input['body'] ?? null) : null;
		if (!is_string($body) || strlen($body) > 200000)
        {
        	return $this->json($response, ['error' => lang('Invalid spellcheck text')], 400);
        }
		try
		{
			return $this->json($response, (new \App\modules\email\services\EmailSpellcheckService())->check($body, $this->spellcheckLanguage()));
		}
		catch (\Throwable $exception)
		{
			error_log('[email] EmailComposeController::spellcheck failed (' . get_class($exception) . ')');
			return $this->json($response, ['error' => lang('Unable to check spelling')], 500);
		}
	}

	private function spellcheckLanguage(): string
	{
		$user = (array) \App\modules\phpgwapi\services\Settings::getInstance()->get('user');
		return (string) ($user['preferences']['common']['lang'] ?? 'en');
	}

	public function draft(Request $request, Response $response): Response
	{
		$input = $request->getParsedBody();
		if (!is_array($input))
        {
        	$input = json_decode((string) $request->getBody(), true);
        }
		$payload = EmailComposeSerializer::normalize(is_array($input) ? $input : [], false);
		if ($payload === null)
        {
        	return $this->json($response, ['error' => lang('Invalid draft data')], 422);
        }
		$account = (int) ($payload['msgball']['acctnum'] ?? $payload['fldball']['acctnum'] ?? 0);
		$originalPost = $_POST;
		$_POST = ['fldball' => ['acctnum' => $account, 'folder' => 'INBOX']];
		$mail = null;
		try
		{
			$bootstrap = \CreateObject('email.msg_bootstrap');
			$bootstrap->ensure_mail_msg_exists('email.api.draft');
			$mail = \App\modules\email\services\MailMessage::getInstance();
			if (!EmailAccountDirectory::hasEnabledAccount((array) $mail->extra_and_default_acounts, $account))
			{
				throw new \InvalidArgumentException('Select an enabled email account');
			}
			$mail->set_acctnum($account);
			$storage = new \App\modules\email\services\EmailAttachmentStorage($mail->att_files_dir);
			$attachments = [];
			foreach ($storage->list() as $attachment)
			{
				$id = (string) $attachment['id'];
				$path = $mail->att_files_dir . '/' . $id;
				if (!is_file($path) || is_link($path))
                {
                	throw new \RuntimeException('Attachment no longer exists');
                }
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
				if (!$mail->phpgw_createmailbox_ex(['acctnum' => $account, 'folder' => $mail->prep_folder_out($folder)], false))
                {
                	throw new \RuntimeException('Unable to create Drafts folder');
                }
			}
			$stream = $mail->get_arg_value('mailsvr_stream', $account);
			$path = $mail->get_arg_value('mailsvr_callstr', $account) . $mail->getTransport($account)->utf7_encode($folder);
			$oldDraft = null;
			if (($payload['action'] ?? 'new') === 'edit' && isset($payload['msgball']))
			{
				$oldDraft = $payload['msgball'];
				if ((int) ($oldDraft['acctnum'] ?? -1) !== $account || (int) ($oldDraft['msgnum'] ?? 0) < 1
					|| !is_string($oldDraft['folder'] ?? null) || !in_array($oldDraft['folder'], array_column((array) $mail->get_folder_list($account), 'folder_long'), true))
				{
					throw new \InvalidArgumentException('The draft no longer exists');
				}
				$oldDraft['folder'] = $mail->prep_folder_out($oldDraft['folder']);
				$oldHeaders = $mail->phpgw_header($oldDraft, false);
				if (!$oldHeaders || trim((string) ($oldHeaders->Draft ?? '')) === '')
                {
                	throw new \InvalidArgumentException('The selected message is not a draft');
                }
			}
			if (!\IMAPManager::imap_append($stream, $path, $raw, '\\Draft \\Seen'))
            {
            	throw new \RuntimeException('Unable to save draft');
            }
			$transport = $mail->getTransport($account);
			$searchOptions = $transport->force_msg_uids ? 1 : 0;
			$mail->ensure_stream_and_folder(['acctnum' => $account, 'folder' => $mail->prep_folder_out($folder)], 'email.api.draft', false);
			$numbers = \IMAPManager::imap_search($mail->get_arg_value('mailsvr_stream', $account), 'ALL', $searchOptions);
			if ($numbers === false || !$numbers)
            {
            	throw new \RuntimeException('Draft saved, but its message reference could not be read');
            }
			$newMessageNumber = max(array_map('intval', $numbers));
			$cleanupWarning = false;
			if ($oldDraft)
			{
				$oldStream = $mail->get_arg_value('mailsvr_stream', $account);
				$oldFolder = $oldDraft['folder'];
				if ($mail->ensure_stream_and_folder($oldDraft, 'email.api.draft.replace', false)
					&& \IMAPManager::imap_setflag_full($oldStream, (string) $oldDraft['msgnum'], '\\Deleted', $searchOptions)
					&& \IMAPManager::imap_expunge($oldStream))
				{
					$mail->ensure_stream_and_folder(['acctnum' => $account, 'folder' => $mail->prep_folder_out($folder)], 'email.api.draft', false);
					$numbers = \IMAPManager::imap_search($mail->get_arg_value('mailsvr_stream', $account), 'ALL', $searchOptions);
					if ($numbers)
                    {
                    	$newMessageNumber = max(array_map('intval', $numbers));
                    }
				}
                else
				{
					$cleanupWarning = true;
				}
				$mail->expire_session_cache_item('msgball_list', $account, $oldFolder);
				$mail->expire_session_cache_item('folder_status_info', $account, $oldFolder);
			}
			foreach (['msgball_list', 'folder_status_info'] as $key)
            {
            	$mail->expire_session_cache_item($key, $account, $mail->prep_folder_out($folder));
            }
			$mail->expire_session_cache_item('folder_list', $account);
			return $this->json($response, ['saved' => true, 'folder' => $folder, 'msgnum' => $newMessageNumber, 'cleanupWarning' => $cleanupWarning]);
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
			try
			{
				\App\modules\email\services\MailMessage::releaseInstance();
			}
			finally
			{
				$_POST = $originalPost;
			}
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
		$originalGet = $_GET;
		$account = (int) ($payload['msgball']['acctnum'] ?? $payload['fldball']['acctnum'] ?? 0);
		$_POST = array_replace($originalPost, ['fldball' => ['acctnum' => $account, 'folder' => 'INBOX']]);
		$mail = null;
		try
		{
			$bootstrap = \CreateObject('email.msg_bootstrap');
			$bootstrap->ensure_mail_msg_exists('email.api.send');
			$mail = \App\modules\email\services\MailMessage::getInstance();
			if (!EmailAccountDirectory::hasEnabledAccount((array) $mail->extra_and_default_acounts, $account))
			{
				return $this->json($response, ['error' => lang('Select an enabled email account')], 422);
			}
			$mail->set_acctnum($account);
			$sender = new \App\modules\email\services\EmailOutgoingMessage();
			$mailer = $sender->prepare($payload, $mail);
			$result = $sender->send($mailer);
			if (empty($result['sent']))
			{
				return $this->json($response, ['error' => lang('Unable to send message'), 'reason' => $result['error']], 502);
			}
			try
			{
				if ($mail->get_pref_value('use_sent_folder', $account)
					&& !$mail->phpgw_append($mail->get_pref_value('sent_folder_name', $account), $result['mime'] . "\r\n", '\\Seen', false))
				{
					error_log('[email] EmailComposeController::send sent-copy append failed');
				}
			}
            catch (\Throwable $exception)
			{
				error_log('[email] EmailComposeController::send sent-copy append failed (' . get_class($exception) . ')');
			}
			if (in_array((string) ($payload['action'] ?? ''), ['reply', 'replyall'], true) && isset($payload['msgball']['acctnum'], $payload['msgball']['folder'], $payload['msgball']['msgnum']))
			{
				try
				{
					$source = $payload['msgball'];
					$source['folder'] = $mail->prep_folder_out((string) $source['folder']);
					$mail->phpgw_set_flag($source, '\\Answered', false);
				}
                catch (\Throwable $exception)
				{
					error_log('[email] EmailComposeController::send reply flag update failed (' . get_class($exception) . ')');
				}
			}
			return $this->json($response, ['sent' => true]);
		}
		catch (\InvalidArgumentException $exception)
		{
			return $this->json($response, ['error' => $exception->getMessage()], 422);
		}
		catch (\App\modules\email\services\EmailMailConfigurationException $exception)
		{
			$message = $exception->getMessage();
			return $this->json($response, ['error' => $message, 'reason' => $message], 422);
		}
		catch (\Throwable $exception)
		{
			error_log('[email] EmailComposeController::send failed (' . get_class($exception) . ')');
			return $this->json($response, ['error' => lang('Unable to send message')], 500);
		}
		finally
		{
			if ($mail && is_dir((string) $mail->att_files_dir))
			{
				try
				{
					$storage = new \App\modules\email\services\EmailAttachmentStorage((string) $mail->att_files_dir);
					$storage->delete(array_column($storage->list(), 'id'));
				}
                catch (\Throwable $exception)
				{
					error_log('[email] EmailComposeController::send attachment cleanup failed (' . get_class($exception) . ')');
				}
			}
			try
			{
				\App\modules\email\services\MailMessage::releaseInstance();
			}
			finally
			{
				$_POST = $originalPost;
				$_GET = $originalGet;
			}
		}
	}
}
