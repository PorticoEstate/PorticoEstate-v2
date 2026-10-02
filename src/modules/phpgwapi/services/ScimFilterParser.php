<?php

namespace App\modules\phpgwapi\services;

use InvalidArgumentException;

final class ScimFilterParser
{
	private const SUPPORTED_ATTRIBUTES = ['userName', 'externalId', 'displayName'];

	public function parse(?string $filter): ?array
	{
		if ($filter === null || trim($filter) === '')
		{
			return null;
		}

		if (!preg_match('/^\s*([A-Za-z][A-Za-z0-9.]*)\s+eq\s+"((?:[^"\\\\]|\\\\.)*)"\s*$/i', $filter, $matches))
		{
			throw new InvalidArgumentException('Unsupported SCIM filter');
		}

		$attribute = $this->canonicalAttribute($matches[1]);
		if ($attribute === null)
		{
			throw new InvalidArgumentException('Unsupported SCIM filter attribute');
		}

		return [
			'attribute' => $attribute,
			'value' => stripcslashes($matches[2]),
		];
	}

	private function canonicalAttribute(string $attribute): ?string
	{
		foreach (self::SUPPORTED_ATTRIBUTES as $supported)
		{
			if (strcasecmp($attribute, $supported) === 0)
			{
				return $supported;
			}
		}

		return null;
	}
}