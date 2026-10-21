<?php

namespace App\modules\email\controllers;

use App\modules\email\services\EmailMessageSerializer;
use App\modules\email\services\EmailFolderSerializer;
use App\modules\email\services\InboxSerializer;
use App\modules\phpgwapi\services\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class EmailInboxController
{
	private function json(Response $response, array $data, int $status = 200): Response
	{
		$encoded = json_encode($data);
		$response->getBody()->write($encoded === false ? '{}' : $encoded);
		return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
	}

	public function index(Request $request, Response $response): Response
	{
		try
		{
			$bootstrap = \CreateObject('email.msg_bootstrap');
			$bootstrap->ensure_mail_msg_exists('email.api.inbox');
			$mail = \App\modules\email\services\MailMessage::getInstance();
			$folderInfo = (array) $mail->get_folder_status_info();
			$data = [
				'folder_info' => $folderInfo,
				'current_start' => max(0, (int) $mail->get_arg_value('start')),
				'msg_list_dsp' => (array) $mail->get_msg_list_display($folderInfo),
			];
			if ((int) ($folderInfo['number_all'] ?? 0) === 0)
			{
				$data['report_no_msgs'] = $mail->get_arg_value('mailsvr_stream')
					? lang('this folder is empty')
					: lang('Could not open this mailbox');
			}
			$result = InboxSerializer::serialize($data);
			$user = (array) Settings::getInstance()->get('user');
			$result['pageSize'] = max(1, (int) ($user['preferences']['common']['maxmatchs'] ?? 25));
			$query = $request->getQueryParams();
			foreach ($result['items'] as $index => $item)
			{
				$result['items'][$index]['sourceFolder'] = (string) $mail->prep_folder_in($item['folder'], $item['account']);
				$params = [
					'msgball[acctnum]' => $item['account'],
					'msgball[folder]' => $item['folder'],
					'msgball[msgnum]' => $item['messageNumber'],
				];
				foreach (['sort', 'order', 'start', 'click_history'] as $key)
				{
					if (isset($query[$key])) $params[$key] = $query[$key];
				}
				$result['items'][$index]['messageUrl'] = \phpgw::link('/email/view/message', $params, true);
			}
			return $this->json($response, $result);
		}
		catch (\App\modules\email\services\EmailMailConfigurationException $exception)
		{
			$message = $exception->getMessage();
			return $this->json($response, ['error' => $message, 'reason' => $message], 422);
		}
		catch (\Throwable $exception)
		{
			return $this->json($response, ['error' => lang('Unable to load messages')], 500);
		}
	}

	public function move(Request $request, Response $response): Response
	{
		$input = $request->getParsedBody();
		if (!is_array($input)) $input = json_decode((string) $request->getBody(), true);
		$input = is_array($input) ? $input : [];
		$message = (array) ($input['msgball'] ?? []);
		$target = (array) ($input['target_fldball'] ?? []);
		$account = filter_var($message['acctnum'] ?? null, FILTER_VALIDATE_INT);
		$targetAccount = filter_var($target['acctnum'] ?? null, FILTER_VALIDATE_INT);
		$messageNumber = filter_var($message['msgnum'] ?? null, FILTER_VALIDATE_INT);
		$sourceFolder = $message['folder'] ?? null;
		$targetFolder = $target['folder'] ?? null;
		if ($account === false || $account < 0 || $targetAccount !== $account
			|| $messageNumber === false || $messageNumber < 1
			|| !is_string($sourceFolder) || $sourceFolder === ''
			|| !is_string($targetFolder) || $targetFolder === '' || $sourceFolder === $targetFolder)
		{
			return $this->json($response, ['error' => lang('Invalid message move')], 400);
		}

		$originalPost = $_POST;
		$_POST = ['fldball' => ['acctnum' => $account, 'folder' => $sourceFolder]];
		$mail = null;
		try
		{
			$bootstrap = \CreateObject('email.msg_bootstrap');
			$bootstrap->ensure_mail_msg_exists('email.api.move');
			$mail = \App\modules\email\services\MailMessage::getInstance();
			$folders = array_column((array) $mail->get_folder_list($account, true), 'folder_long');
			if (!in_array($sourceFolder, $folders, true) || !in_array($targetFolder, $folders, true))
			{
				return $this->json($response, ['error' => lang('Select a valid destination folder')], 422);
			}
			$message = ['acctnum' => $account, 'folder' => $mail->prep_folder_out($sourceFolder), 'msgnum' => $messageNumber];
			$target = ['acctnum' => $account, 'folder' => $mail->prep_folder_out($targetFolder)];
			if (!$mail->phpgw_header($message, false))
			{
				return $this->json($response, ['error' => lang('Message no longer exists')], 404);
			}
			$mail->ensure_stream_and_folder($message, 'email.api.move', false);
			$stream = $mail->get_arg_value('mailsvr_stream', $account);
			$transport = $mail->getTransport($account);
			if (!$transport->mail_move($stream, (string) $messageNumber, $targetFolder))
			{
				return $this->json($response, ['error' => lang('Unable to move message'), 'reason' => $transport->server_last_error()], 422);
			}
			if (!is_string($stream)) $transport->expunge($stream);
			$mail->event_msg_move_or_delete($message, 'email.api.move', $target);
			return $this->json($response, ['moved' => true]);
		}
		catch (\App\modules\email\services\EmailMailConfigurationException $exception)
		{
			return $this->json($response, ['error' => $exception->getMessage()], 422);
		}
		catch (\Throwable $exception)
		{
			return $this->json($response, ['error' => lang('Unable to move message'), 'reason' => $exception->getMessage()], 500);
		}
		finally
		{
			$_POST = $originalPost;
			if (is_object($mail))
			{
				$mail->end_request();
				\App\modules\email\services\MailMessage::releaseInstance();
			}
		}
	}

	public function seen(Request $request, Response $response): Response
	{
		$input = $request->getParsedBody();
		if (!is_array($input)) $input = json_decode((string) $request->getBody(), true);
		$input = is_array($input) ? $input : [];
		$message = (array) ($input['msgball'] ?? []);
		$account = filter_var($message['acctnum'] ?? null, FILTER_VALIDATE_INT);
		$number = filter_var($message['msgnum'] ?? null, FILTER_VALIDATE_INT);
		$folder = $message['folder'] ?? null;
		$seen = $input['seen'] ?? null;
		if ($account === false || $account < 0 || $number === false || $number < 1
			|| !is_string($folder) || $folder === '' || !is_bool($seen))
		{
			return $this->json($response, ['error' => lang('Invalid read status request')], 400);
		}
		$originalPost = $_POST;
		$_POST = ['fldball' => ['acctnum' => $account, 'folder' => $folder]];
		$mail = null;
		try
		{
			$bootstrap = \CreateObject('email.msg_bootstrap');
			$bootstrap->ensure_mail_msg_exists('email.api.seen');
			$mail = \App\modules\email\services\MailMessage::getInstance();
			$folders = array_column((array) $mail->get_folder_list($account, true), 'folder_long');
			if (!in_array($folder, $folders, true))
			{
				return $this->json($response, ['error' => lang('Select a valid folder')], 422);
			}
			$message = ['acctnum' => $account, 'folder' => $mail->prep_folder_out($folder), 'msgnum' => $number];
			if (!$mail->phpgw_header($message, false))
			{
				return $this->json($response, ['error' => lang('Message no longer exists')], 404);
			}
			$mail->ensure_stream_and_folder($message, 'email.api.seen', false);
			$stream = $mail->get_arg_value('mailsvr_stream', $account);
			$transport = $mail->getTransport($account);
			$method = $seen ? 'imap_setflag_full' : 'imap_clearflag_full';
			if (!\IMAPManager::$method($stream, (string) $number, '\\Seen', $transport->force_msg_uids ? 1 : 0))
			{
				return $this->json($response, ['error' => lang('Unable to update read status'), 'reason' => \IMAPManager::imap_last_error()], 422);
			}
			$mail->expire_session_cache_item('phpgw_header', $account, $message['folder'], $number);
			$mail->expire_session_cache_item('folder_status_info', $account, $message['folder']);
			return $this->json($response, ['updated' => true, 'seen' => $seen]);
		}
		catch (\App\modules\email\services\EmailMailConfigurationException $exception)
		{
			return $this->json($response, ['error' => $exception->getMessage()], 422);
		}
		catch (\Throwable $exception)
		{
			return $this->json($response, ['error' => lang('Unable to update read status'), 'reason' => $exception->getMessage()], 500);
		}
		finally
		{
			$_POST = $originalPost;
			if (is_object($mail))
			{
				$mail->end_request();
				\App\modules\email\services\MailMessage::releaseInstance();
			}
		}
	}

	private function addressList($addresses, object $mail): array
	{
		$result = [];
		foreach ((array) $addresses as $address)
		{
			if (!is_object($address)) continue;
			$mailbox = (string) ($address->mailbox ?? '');
			$host = (string) ($address->host ?? '');
			$email = $mailbox !== '' && $host !== '' ? $mailbox . '@' . $host : '';
			$personal = trim((string) ($address->personal ?? ''));
			if ($personal !== '') $personal = (string) $mail->decode_header_string($personal);
			$result[] = ['name' => $personal !== '' ? $personal : $email, 'address' => $email];
		}
		return $result;
	}

	public function message(Request $request, Response $response): Response
	{
		$msgball = (array) (($request->getQueryParams()['msgball'] ?? []));
		$account = $msgball['acctnum'] ?? null;
		$folder = $msgball['folder'] ?? null;
		$messageNumber = $msgball['msgnum'] ?? null;
		if (!is_numeric($account) || (int) $account < 0 || !is_string($folder) || $folder === '' || !is_numeric($messageNumber) || (int) $messageNumber < 0)
		{
			return $this->json($response, ['error' => lang('Invalid message reference')], 400);
		}

		try
		{
			$businessObject = \CreateObject('email.bomessage');
			$businessObject->message_data();
			$mail = \App\modules\email\services\MailMessage::getInstance();
			$activeMsgball = (array) $mail->get_arg_value('msgball');
			$headers = $mail->phpgw_header($activeMsgball);
			if (!$headers)
			{
				return $this->json($response, ['error' => lang('Message no longer exists')], 404);
			}
			$parts = (array) $businessObject->part_nice;
			foreach ($parts as $index => $part)
			{
				if (($part['d_processed_as'] ?? '') === 'image_href')
				{
					$imageUrl = \phpgw::link('/email/api/attachment', [
						'msgball[acctnum]' => $activeMsgball['acctnum'] ?? $account,
						'msgball[folder]' => $activeMsgball['folder'] ?? $folder,
						'msgball[msgnum]' => $activeMsgball['msgnum'] ?? $messageNumber,
						'msgball[part_no]' => $part['m_part_num_mime'] ?? '',
						'inline' => '1',
					], true);
					$parts[$index]['message_body'] = '<img src="' . htmlspecialchars($imageUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" alt="">';
					continue;
				}
				if (($part['d_instructions'] ?? '') !== 'echo_out') continue;
				$partBall = $activeMsgball;
				$partBall['part_no'] = $part['m_part_num_mime'] ?? '';
				$body = (string) $mail->phpgw_fetchbody($partBall);
				$keywords = (string) ($part['m_keywords'] ?? '');
				if (stripos($keywords, 'qprint') !== false) $body = $mail->qprint($body);
				elseif (stripos($keywords, 'base64') !== false) $body = $mail->de_base64($body);
				$parts[$index]['message_body'] = htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
				$parts[$index]['d_instructions'] = 'show';
			}

			$data = (array) ($businessObject->xi ?? []);
			$data['account'] = (int) ($activeMsgball['acctnum'] ?? $account);
			$data['folder'] = (string) ($activeMsgball['folder'] ?? $folder);
			$data['messageNumber'] = (int) ($activeMsgball['msgnum'] ?? $messageNumber);
			$item = EmailMessageSerializer::serialize($data, $parts, [
				'from' => $this->addressList($headers->from ?? [], $mail),
				'to' => $this->addressList($headers->to ?? [], $mail),
				'cc' => $this->addressList($headers->cc ?? [], $mail),
			]);
			foreach ($item['attachments'] as $index => $attachment)
			{
				$item['attachments'][$index]['downloadUrl'] = \phpgw::link('/email/api/attachment', [
					'msgball[acctnum]' => $item['account'],
					'msgball[folder]' => $item['folder'],
					'msgball[msgnum]' => $item['messageNumber'],
					'msgball[part_no]' => $attachment['partNumber'],
				], true);
			}
			if (!$mail->phpgw_set_flag($activeMsgball, '\\Seen', false))
			{
				return $this->json($response, ['error' => lang('Unable to mark message as read')], 422);
			}
			$mail->expire_session_cache_item('phpgw_header', $activeMsgball['acctnum'], $activeMsgball['folder'], $activeMsgball['msgnum']);
			$mail->expire_session_cache_item('folder_status_info', $activeMsgball['acctnum'], $activeMsgball['folder']);
			$item['sourceFolder'] = (string) $mail->prep_folder_in($activeMsgball['folder'], $activeMsgball['acctnum']);
			$item['seen'] = true;
			return $this->json($response, ['item' => $item]);
		}
		catch (\App\modules\email\services\EmailMailConfigurationException $exception)
		{
			$message = $exception->getMessage();
			return $this->json($response, ['error' => $message, 'reason' => $message], 422);
		}
		catch (\Throwable $exception)
		{
			return $this->json($response, ['error' => lang('Unable to load message')], 500);
		}
	}

	public function folders(Request $request, Response $response): Response
	{
		$query = $request->getQueryParams();
		$folderBall = (array) ($query['fldball'] ?? []);
		$account = filter_var($folderBall['acctnum'] ?? 0, FILTER_VALIDATE_INT);
		if ($account === false || $account < 0)
		{
			return $this->json($response, ['error' => lang('Invalid account')], 400);
		}
		try
		{
			$bootstrap = \CreateObject('email.msg_bootstrap');
			$bootstrap->ensure_mail_msg_exists('email.api.folders', $account);
			$mail = \App\modules\email\services\MailMessage::getInstance();
			$mail->get_folder_list($account, true);
			$businessObject = \CreateObject('email.bofolder');
			$businessObject->folder_data();
			$folders = (array) ($businessObject->xi['folder_list_display'] ?? []);
			return $this->json($response, [
				'items' => EmailFolderSerializer::serialize($folders),
				'delimiter' => (string) $mail->get_arg_value('mailsvr_delimiter', $account),
			]);
		}
		catch (\App\modules\email\services\EmailMailConfigurationException $exception)
		{
			$message = $exception->getMessage();
			return $this->json($response, ['error' => $message, 'reason' => $message], 422);
		}
		catch (\Throwable $exception)
		{
			return $this->json($response, ['error' => lang('Unable to load folders')], 500);
		}
	}

	public function folderAction(Request $request, Response $response): Response
	{
		$input = $request->getParsedBody();
		if (!is_array($input)) $input = json_decode((string) $request->getBody(), true);
		$input = is_array($input) ? $input : [];
		$action = (string) ($input['action'] ?? '');
		$target = (array) ($input['target_fldball'] ?? []);
		$source = (array) ($input['source_fldball'] ?? []);
		$targetFolder = $target['folder'] ?? null;
		$sourceFolder = $source['folder'] ?? null;
		$parentFolder = $input['parent_folder'] ?? '';
		$account = filter_var($target['acctnum'] ?? 0, FILTER_VALIDATE_INT);
		if (!in_array($action, ['create', 'delete', 'rename'], true)
			|| $account === false || $account < 0
			|| !is_string($targetFolder) || trim($targetFolder) === ''
			|| ($action === 'create' && !is_string($parentFolder))
			|| (($action === 'rename') && (!is_string($sourceFolder) || trim($sourceFolder) === '')))
		{
			return $this->json($response, ['error' => lang('Invalid folder action')], 400);
		}

		$originalPost = $_POST;
		$_POST = array_replace($originalPost, $input);
		try
		{
			$bootstrap = \CreateObject('email.msg_bootstrap');
			$bootstrap->ensure_mail_msg_exists('email.api.folders.action', $account);
			if ($action === 'create' && $parentFolder !== '')
			{
				$mail = \App\modules\email\services\MailMessage::getInstance();
				$delimiter = (string) $mail->get_arg_value('mailsvr_delimiter', $account);
				$availableFolders = (array) $mail->get_arg_value('folder_list', $account);
				if ($delimiter === '' || !in_array($parentFolder, array_column($availableFolders, 'folder_long'), true))
				{
					return $this->json($response, ['error' => lang('Select a valid parent folder')], 422);
				}
				if (str_contains($targetFolder, $delimiter))
				{
					return $this->json($response, ['error' => lang('Enter a single folder name when selecting a parent')], 422);
				}
				$target['folder'] = $parentFolder . $delimiter . trim($targetFolder);
			}
			if ($action === 'create' && array_key_exists('parent_folder', $input))
			{
				$mail = \App\modules\email\services\MailMessage::getInstance();
				$target['folder'] = trim($target['folder']);
				$mail->set_arg_value('target_fldball', $target, $account);
				$mail->set_arg_value('action', 'create_expert', $account);
			}
			$businessObject = \CreateObject('email.bofolder');
			$success = $businessObject->folder_action();
			$report = (string) ($businessObject->xi['action_report'] ?? '');
			$reason = trim(html_entity_decode(strip_tags(str_ireplace(['<br />', '<br>'], ' ', $report)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
			if (!$success)
			{
				return $this->json($response, [
					'error' => lang('Unable to update folder'),
					'reason' => $reason !== '' ? $reason : lang('Folder action failed'),
				], 422);
			}
			return $this->json($response, ['updated' => true, 'message' => $reason]);
		}
		catch (\App\modules\email\services\EmailMailConfigurationException $exception)
		{
			$message = $exception->getMessage();
			return $this->json($response, ['error' => $message, 'reason' => $message], 422);
		}
		catch (\Throwable $exception)
		{
			return $this->json($response, [
				'error' => lang('Unable to update folder'),
				'reason' => $exception->getMessage(),
			], 500);
		}
		finally
		{
			$_POST = $originalPost;
			if (\App\modules\email\services\MailMessage::hasInstance())
			{
				\App\modules\email\services\MailMessage::releaseInstance();
			}
		}
	}

	public function attachment(Request $request, Response $response): Response
	{
		$query = $request->getQueryParams();
		$msgball = (array) ($query['msgball'] ?? []);
		$inline = ($query['inline'] ?? '') === '1';
		$account = $msgball['acctnum'] ?? null;
		$folder = $msgball['folder'] ?? null;
		$messageNumber = $msgball['msgnum'] ?? null;
		$partNumber = $msgball['part_no'] ?? null;
		if (!is_numeric($account) || (int) $account < 0 || !is_string($folder) || $folder === '' || !is_numeric($messageNumber) || (int) $messageNumber < 0 || !is_string($partNumber) || !preg_match('/^\d+(?:\.\d+)*$/', $partNumber))
		{
			return $this->json($response, ['error' => lang('Invalid attachment reference')], 400);
		}

		try
		{
			$businessObject = \CreateObject('email.bomessage');
			$businessObject->message_data();
			$mail = \App\modules\email\services\MailMessage::getInstance();
			$activeMsgball = (array) $mail->get_arg_value('msgball');
			if ((int) ($activeMsgball['acctnum'] ?? -1) !== (int) $account || (string) ($activeMsgball['folder'] ?? '') !== $folder || (int) ($activeMsgball['msgnum'] ?? -1) !== (int) $messageNumber)
			{
				return $this->json($response, ['error' => lang('Invalid attachment reference')], 404);
			}

			$selectedPart = null;
			foreach ((array) $businessObject->part_nice as $part)
			{
				$isInlineImage = $inline
					&& strtolower((string) ($part['type'] ?? '')) === 'image'
					&& strtolower((string) ($part['subtype'] ?? '')) !== 'svg+xml';
				if ((string) ($part['m_part_num_mime'] ?? '') === $partNumber && (!empty($part['ex_attachment']) || $isInlineImage))
				{
					$selectedPart = $part;
					break;
				}
			}
			if (!$selectedPart)
			{
				return $this->json($response, ['error' => lang('Attachment not found')], 404);
			}

			$partBall = $activeMsgball;
			$partBall['part_no'] = $partNumber;
			$data = (string) $mail->phpgw_fetchbody($partBall);
			$encoding = strtolower((string) ($selectedPart['encoding'] ?? ''));
			if ($encoding === 'base64') $data = $mail->de_base64($data);
			elseif ($encoding === 'qprint') $data = $mail->qprint($data);

			$fileName = preg_replace('/[\x00-\x1f\x7f]/', '_', (string) ($selectedPart['ex_part_name'] ?? 'attachment'));
			$fileName = basename(str_replace('\\', '/', $fileName));
			if ($fileName === '') $fileName = 'attachment';
			$fallbackName = preg_replace('/[^A-Za-z0-9._-]/', '_', $fileName);
			$mimeType = strtolower((string) ($selectedPart['type'] ?? '') . '/' . (string) ($selectedPart['subtype'] ?? ''));
			if (!preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#', $mimeType)) $mimeType = 'application/octet-stream';

			$response->getBody()->write($data);
			return $response
				->withHeader('Content-Type', $mimeType)
				->withHeader('Content-Length', (string) strlen($data))
				->withHeader('Content-Disposition', ($inline ? 'inline' : 'attachment') . '; filename="' . $fallbackName . '"; filename*=UTF-8\'\'' . rawurlencode($fileName))
				->withHeader('X-Content-Type-Options', 'nosniff');
		}
		catch (\App\modules\email\services\EmailMailConfigurationException $exception)
		{
			$message = $exception->getMessage();
			return $this->json($response, ['error' => $message, 'reason' => $message], 422);
		}
		catch (\Throwable $exception)
		{
			return $this->json($response, ['error' => lang('Unable to download attachment')], 500);
		}
	}
}
