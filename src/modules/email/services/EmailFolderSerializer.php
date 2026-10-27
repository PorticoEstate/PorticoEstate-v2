<?php

/**
 * EMail - Folder Serialization
 *
 * @author Sigurd Nes <sigurdne at gmail.com>
 * @copyright Copyright (C) 2026 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @package email
 */

/**
 * Normalizes transport folder listings and message counts into the public folder API format.
 */

namespace App\modules\email\services;

final class EmailFolderSerializer
{
	/**
	 * Normalize transport folder names, account references, and message counts.
	 *
	 * @param array $folders The transport folder entries to normalize.
	 * @return array Folder descriptors for the folder API.
	 */
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