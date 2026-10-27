<?php

namespace App\modules\email\services;

final class EmailMessageActions
{
	public static function normalize(array $input): ?array
	{
		$action = $input['action'] ?? '';
		if (!in_array($action, ['move', 'delete', 'emptyTrash'], true))
        {
        	return null;
        }
		if ($action !== 'move' && ($input['confirmed'] ?? false) !== true)
        {
        	return null;
        }
		$account = filter_var($input['account'] ?? null, FILTER_VALIDATE_INT);
		if ($account === false || $account < 0)
        {
        	return null;
        }
		$messages = $input['messages'] ?? [];
		if (!is_array($messages) || count($messages) > 100 || ($action !== 'emptyTrash' && !$messages))
        {
        	return null;
        }
		$normalized = [];
		foreach ($messages as $message)
		{
			if (!is_array($message))
            {
            	return null;
            }
			$sourceAccount = filter_var($message['acctnum'] ?? null, FILTER_VALIDATE_INT);
			$number = filter_var($message['msgnum'] ?? null, FILTER_VALIDATE_INT);
			$folder = $message['folder'] ?? null;
			if ($sourceAccount !== $account || $number === false || $number < 1 || !is_string($folder)
				|| $folder === '' || preg_match('/[\x00\r\n]/', $folder))
            {
            	return null;
            }
			$normalized[json_encode([$account, $folder, $number])] = ['acctnum' => $account, 'folder' => $folder, 'msgnum' => $number];
		}
		$target = $input['target_fldball'] ?? [];
		if ($action === 'move')
		{
			if (!is_array($target))
            {
            	return null;
            }
			$targetAccount = filter_var($target['acctnum'] ?? null, FILTER_VALIDATE_INT);
			$folder = $target['folder'] ?? null;
			if ($targetAccount === false || $targetAccount < 0 || !is_string($folder)
				|| $folder === '' || preg_match('/[\x00\r\n]/', $folder))
            {
            	return null;
            }
			$target = ['acctnum' => $targetAccount, 'folder' => $folder];
		}
		return ['action' => $action, 'account' => $account, 'messages' => array_values($normalized), 'target' => $target];
	}

	public function execute(object $mail, array $request): array
	{
		$account = $request['account'];
		$enabled = array_map('intval', array_column(array_filter((array) $mail->extra_and_default_acounts,
			static fn($item) => ($item['status'] ?? '') === 'enabled'), 'acctnum'));
		if (!in_array($account, $enabled, true))
        {
        	throw new \InvalidArgumentException('Select an enabled source account');
        }
		$folders = array_column((array) $mail->get_folder_list($account, true), 'folder_long');
		$trash = $request['action'] === 'move' ? '' : (string) $mail->get_arg_value('verified_trash_folder_long', $account);
		$messages = $request['messages'];
		$target = $request['target'];
		if ($request['action'] === 'move')
		{
			if ($target['acctnum'] !== $account && !in_array($target['acctnum'], $enabled, true))
			{
				throw new \InvalidArgumentException('Select an enabled destination account');
			}
			$destinations = $target['acctnum'] === $account ? $folders : array_column((array) $mail->get_folder_list($target['acctnum'], true), 'folder_long');
			if (!in_array($target['folder'], $destinations, true))
            {
            	throw new \InvalidArgumentException('Select a valid destination folder');
            }
		}
		if ($request['action'] === 'emptyTrash')
		{
			if ($trash === '' || !in_array($trash, $folders, true))
            {
            	throw new \InvalidArgumentException('No Trash folder is configured');
            }
			$mail->ensure_stream_and_folder(['acctnum' => $account, 'folder' => $mail->prep_folder_out($trash)], 'email.api.emptyTrash', false);
			$stream = $mail->get_arg_value('mailsvr_stream', $account);
			$messages = [];
			$numbers = \IMAPManager::imap_search($stream, 'ALL', $mail->getTransport($account)->force_msg_uids ? 1 : 0);
			if ($numbers === false)
			{
				$path = $mail->get_arg_value('mailsvr_callstr', $account) . $mail->getTransport($account)->utf7_encode($trash);
				$status = \IMAPManager::imap_status($stream, $path, SA_MESSAGES);
				if (!$status || (int) $status->messages !== 0)
                {
                	throw new \RuntimeException('Unable to list Trash messages');
                }
				$numbers = [];
			}
			foreach ($numbers as $number)
            {
            	$messages[] = ['acctnum' => $account, 'folder' => $trash, 'msgnum' => (int) $number];
            }
		}
		$result = ['completed' => [], 'failed' => []];
		usort($messages, static fn($first, $second) => $second['msgnum'] <=> $first['msgnum']);
		foreach ($messages as $message)
		{
			$copied = false;
			$destination = null;
			try
			{
				if (!in_array($message['folder'], $folders, true))
                {
                	throw new \RuntimeException('Source folder no longer exists');
                }
				$destination = $request['action'] === 'move' ? $target :
					($request['action'] === 'delete' && $trash !== '' && $message['folder'] !== $trash ? ['acctnum' => $account, 'folder' => $trash] : null);
				if ($destination && $destination['acctnum'] === $account && $destination['folder'] === $message['folder'])
                {
                	throw new \RuntimeException('Source and destination are the same');
                }
				$ball = $message;
				$ball['folder'] = $mail->prep_folder_out($message['folder']);
				$headers = $mail->phpgw_header($ball, false);
				if (!$headers)
                {
                	throw new \RuntimeException('Message no longer exists');
                }
				if (!$mail->ensure_stream_and_folder($ball, 'email.api.messageAction', false))
                {
                	throw new \RuntimeException('Unable to open source folder');
                }
				$stream = $mail->get_arg_value('mailsvr_stream', $account);
				$transport = $mail->getTransport($account);
				$options = $transport->force_msg_uids ? 1 : 0;
				if ($destination && $destination['acctnum'] !== $account)
				{
					$header = \IMAPManager::imap_fetchheader($stream, $message['msgnum'], $options);
					$body = \IMAPManager::imap_body($stream, $message['msgnum'], $options | 2);
					if ($header === false || $body === false)
                    {
                    	throw new \RuntimeException('Unable to fetch source message');
                    }
					$destinationBall = ['acctnum' => $destination['acctnum'], 'folder' => $mail->prep_folder_out($destination['folder']), 'no_switch_away' => true];
					if (!$mail->ensure_stream_and_folder($destinationBall, 'email.api.messageAction', false))
                    {
                    	throw new \RuntimeException('Unable to open destination account');
                    }
					$targetStream = $mail->get_arg_value('mailsvr_stream', $destination['acctnum']);
					$targetTransport = $mail->getTransport($destination['acctnum']);
					$targetPath = $mail->get_arg_value('mailsvr_callstr', $destination['acctnum']) . $targetTransport->utf7_encode($destination['folder']);
					if (!\IMAPManager::imap_append($targetStream, $targetPath, rtrim($header, "\r\n") . "\r\n\r\n" . $body, $mail->make_flags_str($headers)))
                    {
                    	throw new \RuntimeException('Destination copy failed; source was retained');
                    }
					$copied = true;
				}
				if ($destination && $destination['acctnum'] === $account)
				{
					if (!$transport->mail_move($stream, (string) $message['msgnum'], $destination['folder']))
                    {
                    	throw new \RuntimeException('Unable to move message');
                    }
					if (!is_string($stream) && !$transport->expunge($stream))
                    {
                    	throw new \RuntimeException('Unable to finish source cleanup');
                    }
				}
				else
				{
					if (!\IMAPManager::imap_setflag_full($stream, (string) $message['msgnum'], '\\Deleted', $options)
						|| !\IMAPManager::imap_expunge($stream))
                    {
                    	throw new \RuntimeException($copied ? 'Copied to destination, but source cleanup failed; check both folders before retrying' : 'Unable to delete message');
                    }
				}
				$result['completed'][] = $message;
			}
			catch (\Throwable $exception)
			{
				error_log('[email] Message action failed (' . get_class($exception) . ')');
				$result['failed'][] = ['message' => $message, 'error' => $exception->getMessage(), 'copied' => $copied];
			}
			finally
			{
				$this->expire($mail, $message['acctnum'], $message['folder']);
				if (isset($destination) && $destination)
                {
                	$this->expire($mail, $destination['acctnum'], $destination['folder']);
                }
			}
		}
		return $result;
	}

	private function expire(object $mail, int $account, string $folder): void
	{
		$folder = $mail->prep_folder_out($folder);
		$mail->expire_session_cache_item('msgball_list', $account);
		foreach (['phpgw_header', 'folder_status_info', 'msg_structure'] as $key)
        {
        	$mail->expire_session_cache_item($key, $account, $folder);
        }
	}
}