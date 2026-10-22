<?php

namespace App\modules\email\services;

final class EmailFolderActions
{
	public function execute(object $mail, array $input): array
	{
		$action = (string) ($input['action'] ?? '');
		$target = (array) ($input['target_fldball'] ?? []);
		$source = (array) ($input['source_fldball'] ?? []);
		$account = filter_var($target['acctnum'] ?? null, FILTER_VALIDATE_INT);
		$folderName = $target['folder'] ?? null;
		if (!in_array($action, ['create', 'delete', 'rename'], true) || $account === false || $account < 0
			|| !is_string($folderName) || trim($folderName) === '' || preg_match('/[\x00\r\n]/', $folderName))
		{
			throw new \InvalidArgumentException('Invalid folder action');
		}
		$enabled = array_map('intval', array_column(array_filter((array) $mail->extra_and_default_acounts,
			static fn($item) => ($item['status'] ?? '') === 'enabled'), 'acctnum'));
		if (!in_array($account, $enabled, true)) throw new \InvalidArgumentException('Select an enabled email account');
		$folders = array_column((array) $mail->get_folder_list($account, true), 'folder_long');
		$delimiter = (string) $mail->get_arg_value('mailsvr_delimiter', $account);
		$targetName = trim($folderName);
		$sourceName = (string) ($source['folder'] ?? '');
		if ($action === 'create')
		{
			if (array_key_exists('parent_folder', $input))
			{
				$parent = $input['parent_folder'];
				if (!is_string($parent)) throw new \InvalidArgumentException('Select a valid parent folder');
				if ($parent !== '')
				{
					if ($delimiter === '' || !in_array($parent, $folders, true)) throw new \InvalidArgumentException('Select a valid parent folder');
					if (str_contains($targetName, $delimiter)) throw new \InvalidArgumentException('Enter a single folder name when selecting a parent');
					$targetName = $parent . $delimiter . $targetName;
				} elseif ($delimiter !== '' && str_contains($targetName, $delimiter))
				{
					throw new \InvalidArgumentException('Select a parent instead of entering a folder path');
				}
			} else
			{
				$targetName = (string) $mail->get_folder_long($targetName);
			}
			if (in_array($targetName, $folders, true)) throw new \InvalidArgumentException('Folder already exists');
			$success = $mail->phpgw_createmailbox_ex(['acctnum' => $account, 'folder' => $mail->prep_folder_out($targetName)], false);
		} elseif ($action === 'delete')
		{
			if (!in_array($targetName, $folders, true)) throw new \InvalidArgumentException('Select an existing folder');
			$success = $mail->phpgw_deletemailbox_ex(['acctnum' => $account, 'folder' => $mail->prep_folder_out($targetName)], false);
		} else
		{
			$sourceAccount = filter_var($source['acctnum'] ?? null, FILTER_VALIDATE_INT);
			if ($sourceAccount !== $account || !in_array($sourceName, $folders, true)) throw new \InvalidArgumentException('Select an existing folder to rename');
			if ($delimiter !== '' && str_contains($targetName, $delimiter)) throw new \InvalidArgumentException('Enter a folder name, not a path');
			$parent = '';
			$position = $delimiter === '' ? false : strrpos($sourceName, $delimiter);
			if ($position !== false) $parent = substr($sourceName, 0, $position + strlen($delimiter));
			$targetName = $parent . $targetName;
			if (in_array($targetName, $folders, true)) throw new \InvalidArgumentException('Folder already exists');
			$success = $mail->phpgw_renamemailbox_ex(
				['acctnum' => $account, 'folder' => $mail->prep_folder_out($sourceName)],
				['acctnum' => $account, 'folder' => $mail->prep_folder_out($targetName)], false);
		}
		if (!$success)
		{
			$error = (string) $mail->phpgw_server_last_error();
			throw new \RuntimeException($error !== '' ? $error : 'Folder action failed');
		}
		$mail->expire_session_cache_item('folder_list', $account);
		return ['updated' => true, 'folder' => $targetName];
	}
}