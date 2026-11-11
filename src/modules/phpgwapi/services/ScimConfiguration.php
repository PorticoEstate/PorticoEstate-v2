<?php

namespace App\modules\phpgwapi\services;

use App\modules\phpgwapi\controllers\Locations;
use Throwable;

final class ScimConfiguration
{
	private const SETTING_NAMES = [
		'SCIM_TENANT_ID',
		'SCIM_BEARER_TOKEN',
		'SCIM_PUBLIC_BASE_URL',
	];

	private array $databaseValues;

	public function __construct(?array $databaseValues = null)
	{
		$this->databaseValues = $databaseValues ?? self::readDatabaseValues();
	}

	public function get(string $name): ?string
	{
		if (!in_array($name, self::SETTING_NAMES, true))
		{
			return null;
		}

		$databaseValue = $this->databaseValues[$name] ?? null;
		if (is_string($databaseValue) && trim($databaseValue) !== '')
		{
			return trim($databaseValue);
		}

		$environmentValue = getenv($name);
		if (is_string($environmentValue) && trim($environmentValue) !== '')
		{
			return trim($environmentValue);
		}

		return null;
	}

	private static function readDatabaseValues(): array
	{
		try
		{
			$locationId = (new Locations())->get_id('admin', 'scim_config');
			if (!$locationId)
			{
				return [];
			}

			$config = (new ConfigLocation((int) $locationId))->read();
			$values = $config['scim_config'] ?? [];

			return is_array($values) ? $values : [];
		}
		catch (Throwable $exception)
		{
			return [];
		}
	}
}