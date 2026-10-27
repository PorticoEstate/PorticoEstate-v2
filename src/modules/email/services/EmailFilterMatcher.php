<?php

namespace App\modules\email\services;

final class EmailFilterMatcher
{
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