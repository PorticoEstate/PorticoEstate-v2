<?php

/**
 * EMail - Search Criteria
 *
 * @author Sigurd Nes <sigurdne at gmail.com>
 * @copyright Copyright (C) 2026 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @package email
 */

/**
 * Normalizes search form values and builds escaped mailbox criteria for text, flags, and dates.
 */

namespace App\modules\email\services;

final class EmailSearchCriteria
{
	private const TEXT_FIELDS = [
		'search_subject' => 'SUBJECT',
		'search_body' => 'BODY',
		'search_from' => 'FROM',
		'search_to' => 'TO',
		'search_cc' => 'CC',
		'search_bcc' => 'BCC',
		'search_keyword' => 'KEYWORD',
	];

	private const FLAGS = [
		'flg_all' => 'ALL',
		'flg_answered' => 'ANSWERED',
		'flg_deleted' => 'DELETED',
		'flg_flagged' => 'FLAGGED',
		'flg_new' => 'NEW',
		'flg_old' => 'OLD',
		'flg_recent' => 'RECENT',
		'flg_seen' => 'SEEN',
		'flg_unanswered' => 'UNANSWERED',
		'flg_undeleted' => 'UNDELETED',
		'flg_unflagged' => 'UNFLAGGED',
		'flg_unseen' => 'UNSEEN',
	];

	/**
	 * Validate search account, folder, term, flag, and date input.
	 *
	 * @param array $input The submitted request values to validate and normalize.
	 * @return array|null Normalized search criteria, or null when the request is invalid.
	 */
	public static function normalize(array $input): ?array
	{
		$fldball = (array) ($input['fldball'] ?? []);
		$account = $fldball['acctnum'] ?? null;
		$folders = $input['folder_list'] ?? [];
		if (!is_numeric($account) || (int) $account < 0 || !is_array($folders))
        {
        	return null;
        }
		$folders = array_values(array_unique(array_filter(array_map(
			static fn($folder) => is_scalar($folder) ? trim((string) $folder) : '',
			$folders
		), static fn($folder) => $folder !== '')));
		if (!$folders)
        {
        	return null;
        }

		$terms = [];
		foreach (self::TEXT_FIELDS as $field => $operator)
		{
			$value = $input[$field] ?? '';
			if (!is_scalar($value))
            {
            	return null;
            }
			$value = trim((string) $value);
			if ($value !== '')
            {
            	$terms[$operator] = $value;
            }
		}

		$flagsInput = (array) ($input['flags'] ?? []);
		$flags = [];
		foreach ($flagsInput as $flag)
		{
			if (is_string($flag) && isset(self::FLAGS[$flag]))
            {
            	$flags[] = self::FLAGS[$flag];
            }
		}

		$dates = [];
		foreach (['date_on' => 'ON', 'date_before' => 'BEFORE', 'date_after' => 'SINCE'] as $field => $operator)
		{
			$value = $input[$field] ?? '';
			if (!is_scalar($value))
            {
            	return null;
            }
			$value = trim((string) $value);
			if ($value === '')
            {
            	continue;
            }
			$date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
			$errors = \DateTimeImmutable::getLastErrors();
			if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $date->format('Y-m-d') !== $value)
            {
            	return null;
            }
			$dates[$operator] = $date->format('m/d/Y');
		}

		return [
			'account' => (int) $account,
			'folders' => $folders,
			'terms' => $terms,
			'flags' => array_values(array_unique($flags)),
			'dates' => $dates,
			'start' => max(0, (int) ($input['start'] ?? 0)),
		];
	}

	/**
	 * Build escaped mailbox search syntax from normalized criteria.
	 *
	 * @param array $criteria Normalized text terms, flags, and date restrictions.
	 * @return string The combined search expression, defaulting to ALL.
	 */
	public static function build(array $criteria): string
	{
		$parts = [];
		foreach ((array) ($criteria['terms'] ?? []) as $operator => $value)
		{
			$value = preg_replace('/[\r\n]+/', ' ', (string) $value) ?? (string) $value;
			$value = addcslashes($value, "\\\"");
			$parts[] = $operator . ' "' . $value . '"';
		}
		foreach ((array) ($criteria['flags'] ?? []) as $flag)
		{
			$parts[] = (string) $flag;
		}
		foreach ((array) ($criteria['dates'] ?? []) as $operator => $date)
		{
			$parts[] = $operator . ' "' . (string) $date . '"';
		}
		return $parts ? implode(' ', $parts) : 'ALL';
	}
}
