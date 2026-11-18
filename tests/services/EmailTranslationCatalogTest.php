<?php

namespace Tests\Services;

use PHPUnit\Framework\TestCase;
use App\modules\phpgwapi\services\Translation;

require_once __DIR__ . '/../../vendor/autoload.php';

class EmailTranslationCatalogTest extends TestCase
{
	/**
	 * @dataProvider catalogLanguages
	 */
	public function testCatalogContainsModuleTranslationKeys(string $language): void
	{
		$root = __DIR__ . '/../../src/modules/email';
		$catalog = [];
		foreach (file($root . '/setup/phpgw_' . $language . '.lang', FILE_IGNORE_NEW_LINES) as $line)
		{
			$fields = explode("\t", $line);
			$this->assertCount(4, $fields, 'Invalid catalog row: ' . $line);
			$this->assertSame($language, $fields[2]);
			$this->assertNotSame('', trim($fields[3]), 'Empty translation: ' . $fields[0]);
			$catalog[$this->normalizeKey($fields[0])] = $fields[3];
		}

		$keys = [];
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
		foreach ($files as $file)
		{
			if (!in_array($file->getExtension(), ['php', 'twig'], true)) continue;
			$source = file_get_contents($file->getPathname());
			preg_match_all('~\b(?:lang|translate|language)\(\s*([\'"])((?:\\\\.|(?!\1).)*?)\1~s', $source, $matches, PREG_SET_ORDER);
			foreach ($matches as $match)
			{
				$key = $match[1] === "'"
					? str_replace(["\\'", "\\\\"], ["'", "\\"], $match[2])
					: stripcslashes($match[2]);
				$keys[$this->normalizeKey($key)] = true;
			}
			if ($file->getFilename() === 'search.twig')
			{
				preg_match_all("/\\['flg_[^']+',\\s*'([^']+)'\\]/", $source, $flags);
				foreach ($flags[1] as $label) $keys[$this->normalizeKey($label)] = true;
			}
		}

		$missing = array_keys(array_diff_key($keys, $catalog));
		sort($missing);
		$this->assertSame([], $missing, 'Missing ' . $language . ' translations: ' . implode(', ', $missing));
	}

	public static function catalogLanguages(): array
	{
		return [['en'], ['no']];
	}

	private function normalizeKey(string $key): string
	{
		return strtolower(trim(substr($key, 0, Translation::MAX_MESSAGE_ID_LENGTH)));
	}
}
