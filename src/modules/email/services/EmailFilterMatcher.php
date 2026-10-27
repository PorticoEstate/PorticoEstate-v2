<?php

/**
 * EMail - Filter Matching
 *
 * @author Sigurd Nes <sigurdne at gmail.com>
 * @copyright Copyright (C) 2026 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @package email
 */

/**
 * Evaluates configured filter conditions against message header values without performing mailbox actions.
 */

namespace App\modules\email\services;

final class EmailFilterMatcher
{
	/**
	 * Evaluate a stored filter's header conditions against parsed message headers.
	 *
	 * @param array $filter The stored or submitted filter definition.
	 * @param array $headers The decoded message envelope or parsed header values used by the operation.
	 * @return bool Whether the filter matches the supplied headers.
	 */
	public function matches(array $filter, array $headers): bool
	{
		$matches = array_values((array) ($filter['matches'] ?? []));
		if (!$matches || count($matches) > 3)
        {
        	return false;
        }
		$results = [];
		foreach ($matches as $match)
		{
			$match = (array) $match;
			$field = strtolower((string) ($match['examine'] ?? ''));
			$actual = (string) ($headers[$field] ?? '');
			$needle = (string) ($match['matchthis'] ?? '');
			if ($actual === '' || $needle === '')
            {
            	$results[] = false;
			}
            else
			{
				$contains = stripos($actual, $needle) !== false;
				$results[] = ($match['comparator'] ?? 'contains') === 'notcontains' ? !$contains : $contains;
			}
		}
		if (count($results) === 1)
        {
        	return $results[0];
        }
		if (count($results) === 2)
        {
        	return ($matches[1]['andor'] ?? 'and') === 'or' ? ($results[0] || $results[1]) : ($results[0] && $results[1]);
        }
		$firstOperator = ($matches[1]['andor'] ?? 'and') === 'or' ? 'or' : 'and';
		$secondOperator = ($matches[2]['andor'] ?? 'and') === 'or' ? 'or' : 'and';
		if ($firstOperator === 'or' && $secondOperator === 'or')
        {
        	return $results[0] || $results[1] || $results[2];
        }
		$firstGroup = $firstOperator === 'or' ? ($results[0] || $results[1]) : ($results[0] && $results[1]);
		return $secondOperator === 'or' ? ($firstGroup || $results[2]) : ($firstGroup && $results[2]);
	}
}