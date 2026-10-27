<?php

/**
 * EMail - Home Summary
 *
 * @author Sigurd Nes <sigurdne at gmail.com>
 * @copyright Copyright (C) 2026 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @package email
 */

/**
 * Collects main-account counts, recent message links, and folder choices for the optional home-screen summary.
 */

namespace App\modules\email\services;

/**
 * Collects home-screen data without generating HTML or opening disabled widgets.
 */
final class EmailHomeSummary
{
	public function collect(object $mail, callable $link): ?array
	{
		if (!filter_var($mail->get_pref_value('mainscreen_showmail', 0), FILTER_VALIDATE_BOOLEAN))
		{
			return null;
		}
		$inbox = $mail->new_message_check();
		$messages = [];
		if ($inbox['number_all'] > 0)
		{
			foreach (array_slice($mail->get_msgball_list(), 0, 5) as $message)
			{
				$ball = $mail->ball_data_parse_str($message);
				// The legacy subject helper returns escaped HTML; expose plain text.
				$subject = $mail->htmlspecialchars_decode($mail->get_subject($mail->phpgw_header($ball), ''));
				if (mb_strlen($subject) > 65)
				{
					$subject = mb_substr($subject, 0, 65) . ' ...';
				}
				$messages[] = ['text' => $subject, 'link' => $link('/email/view/message', $ball['uri'])];
			}
		}
		$folders = [];
		if ($mail->get_mailsvr_supports_folders())
		{
			$showUnseen = filter_var($mail->get_pref_value('newmsg_combobox', 0), FILTER_VALIDATE_BOOLEAN);
			foreach ($mail->get_folder_list(0) as $folder)
			{
				$parameter = $mail->prep_folder_out($folder['folder_long']);
				$account = (int) $folder['acctnum'];
				$name = $folder['folder_short'] === 'INBOX'
					? $mail->get_common_langs('lang_inbox') : $folder['folder_short'];
				if ($showUnseen)
				{
					$status = $mail->get_folder_status_info(['folder' => $parameter, 'acctnum' => $account]);
					$name .= ' (' . number_format($status['number_new']) . ')';
				}
				$folders[] = ['name' => $name, 'value' => '&folder=' . $parameter . '&acctnum=' . $account];
			}
		}
		return [
			'total' => (int) $inbox['number_all'],
			'unread' => (int) $inbox['number_new'],
			'alert' => $inbox['alert_string'],
			'messages' => $messages,
			'folders' => $folders,
			'inboxUrl' => $link('/email/view/inbox', []),
			'composeUrl' => $link('/email/view/compose', ['fldball[folder]' => 'INBOX', 'fldball[acctnum]' => '0']),
		];
	}
}
