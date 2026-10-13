<?php

namespace App\modules\email\services;

final class EmailFolderSerializer
{
	public static function serialize(array $folders): array
	{
		return array_values(array_map(static function ($folder): array
		{
			$folder = (array) $folder;
			return [
				'name' => (string) ($folder['folder_short'] ?? $folder['folder_name'] ?? ''),
				'longName' => (string) ($folder['folder_long'] ?? ''),
				'folderParam' => (string) ($folder['folder_param'] ?? ''),
				'account' => (int) ($folder['acctnum'] ?? 0),
				'unseen' => (int) ($folder['unseen_count'] ?? 0),
				'total' => (int) ($folder['total_count'] ?? 0),
			];
		}, $folders));
	}
}