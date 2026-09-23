<?php

namespace App\modules\calendar\services;

use App\modules\phpgwapi\services\Settings;

class HolidayLoader
{
	public function load(string $locale): array
	{
		$locale = strtoupper(trim($locale));
		if ($locale === '')
		{
			return [];
		}

		$source = $this->source();
		$filename = 'holidays.' . $locale . '.txt';
		$lines = $this->readLines($source, $filename);
		$holidays = [];
		foreach ($lines as $line)
		{
			$fields = explode("\t", trim($line));
			if (count($fields) !== 7)
			{
				continue;
			}

			$holidays[] = [
				'locale' => strtoupper(trim($fields[0])),
				'name' => trim($fields[1]),
				'mday' => (int)$fields[2],
				'month_num' => (int)$fields[3],
				'occurence' => (int)$fields[4],
				'dow' => (int)$fields[5],
				'observance_rule' => (int)$fields[6],
				'hol_id' => 0,
			];
		}

		return $holidays;
	}

	public function availableLocales(): array
	{
		$source = $this->source();
		if (!is_dir($source))
		{
			return [];
		}

		$locales = [];
		foreach ((array)glob($source . DIRECTORY_SEPARATOR . 'holidays.[A-Za-z][A-Za-z].txt') as $file)
		{
			if (preg_match('/holidays\.([A-Za-z]{2})\.txt$/', $file, $matches))
			{
				$locales[] = strtoupper($matches[1]);
			}
		}

		sort($locales);
		return array_values(array_unique($locales));
	}

	private function source(): string
	{
		$serverSettings = Settings::getInstance()->get('server');
		$source = is_array($serverSettings) ? trim((string)($serverSettings['holidays_url_path'] ?? '')) : '';
		if ($source === '' || strtolower($source) === 'localhost')
		{
			return dirname(__DIR__) . '/phpgroupware.org';
		}

		return rtrim($source, '/');
	}

	private function readLines(string $source, string $filename): array
	{
		if (is_dir($source))
		{
			$path = $source . DIRECTORY_SEPARATOR . $filename;
			return is_readable($path) ? (file($path, FILE_IGNORE_NEW_LINES) ?: []) : [];
		}

		if (filter_var($source, FILTER_VALIDATE_URL))
		{
			$network = \CreateObject('phpgwapi.network');
			return (array)$network->gethttpsocketfile($source . '/' . $filename);
		}

		return [];
	}
}
