<?php

/**
 * EMail - Filter Serialization
 *
 * @author Sigurd Nes <sigurdne at gmail.com>
 * @copyright Copyright (C) 2026 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @package email
 */

/**
 * Converts stored filter definitions into the data returned by the filter-list API.
 */

namespace App\modules\email\services;

final class EmailFilterSerializer
{
	public static function serialize(array $filters): array
	{
		$result = [];
		foreach (array_values($filters) as $index => $filter)
		{
			$filter = (array) $filter;
			$name = html_entity_decode((string) ($filter['filtername'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
			$result[] = [
				'id' => $index,
				'name' => $name !== '' ? $name : sprintf('Filter %d', $index + 1),
				'sourceCount' => count((array) ($filter['source_accounts'] ?? [])),
				'matchCount' => count((array) ($filter['matches'] ?? [])),
				'actionCount' => count((array) ($filter['actions'] ?? [])),
			];
		}
		return $result;
	}
}
