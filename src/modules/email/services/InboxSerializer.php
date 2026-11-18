<?php

/**
 * EMail - Inbox Serialization
 *
 * @author Sigurd Nes <sigurdne at gmail.com>
 * @copyright Copyright (C) 2026 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @package email
 */

/**
 * Converts legacy message-list and folder data into inbox API items, counts, and paging state.
 */

namespace App\modules\email\services;

final class InboxSerializer
{
	/**
	 * Normalize legacy inbox rows and folder statistics into the API envelope.
	 *
	 * @param array $data The legacy message-list data and folder-status information.
	 * @return array Message items, folder counts, page offset, and any empty-folder message.
	 */
	public static function serialize(array $data): array
	{
		$folderInfo = (array) ($data['folder_info'] ?? []);
		$items = array_map(static function ($message): array
		{
			$message = (array) $message;
			return [
				'account' => (int) ($message['acctnum'] ?? 0),
				'folder' => (string) ($message['folder'] ?? ''),
				'messageNumber' => (int) ($message['msgnum'] ?? 0),
				'subject' => (string) ($message['subject'] ?? ''),
				'from' => (string) ($message['from_name'] ?? ''),
				'fromAddress' => (string) ($message['display_address_from'] ?? ''),
				'date' => (string) ($message['msg_date'] ?? ''),
				'size' => (string) ($message['size'] ?? ''),
				'unseen' => !empty($message['is_unseen']),
				'hasAttachments' => !empty($message['has_attachment']),
				'answered' => !empty($message['is_answered']),
				'flagged' => !empty($message['is_flagged']),
				'deleted' => !empty($message['is_deleted']),
				'draft' => !empty($message['is_draft']),
				'messageUrl' => (string) ($message['subject_link'] ?? ''),
			];
		}, (array) ($data['msg_list_dsp'] ?? []));

		return [
			'items' => $items,
			'folder' => (string) ($folderInfo['folder_checked'] ?? $data['current_folder'] ?? ''),
			'unseen' => (int) ($folderInfo['number_new'] ?? 0),
			'total' => (int) ($folderInfo['number_all'] ?? 0),
			'start' => max(0, (int) ($data['current_start'] ?? 0)),
			'error' => (string) ($data['report_no_msgs'] ?? ''),
		];
	}
}