<?php

namespace App\modules\email\services;

final class EmailSpellcheckService
{
	public static function available(): bool
	{
		return function_exists('pspell_new') && function_exists('pspell_check') && function_exists('pspell_suggest');
	}

	public function check(string $text, string $language): array
	{
		if (!self::available()) return ['available' => false, 'issues' => []];
		$link = @pspell_new($language !== '' ? $language : 'en', '', '', '', defined('PSPELL_FAST') ? PSPELL_FAST : 0);
		if (!$link) return ['available' => false, 'issues' => []];
		$issues = [];
		$seen = [];
		foreach (preg_split('/\R/u', $text) ?: [] as $line)
		{
			if (str_starts_with(ltrim($line), '>')) continue;
			preg_match_all('/\S+/u', $line, $matches);
			foreach ($matches[0] as $token)
			{
				$word = str_replace(['.', ',', '?', ':', ';', '!', '"', '(', ')', '*', '[', ']'], '', $token);
				if (!$this->shouldCheck($word) || pspell_check($link, $word)) continue;
				$key = function_exists('mb_strtolower') ? mb_strtolower($word, 'UTF-8') : strtolower($word);
				if (isset($seen[$key])) continue;
				$seen[$key] = true;
				$suggestions = array_slice((array) pspell_suggest($link, $word), 0, 6);
				$issues[] = ['word' => $word, 'suggestions' => array_values(array_map('strval', $suggestions))];
				if (count($issues) >= 100) break 2;
			}
		}
		return ['available' => true, 'issues' => $issues];
	}

	private function shouldCheck(string $word): bool
	{
		if (strlen(trim($word)) < 2 || preg_match('/[0-9_=@<>]/u', $word)) return false;
		if (preg_match('/[a-z0-9-]+\.(?:[a-z]{2,}|local)\b/i', $word)) return false;
		return (bool) preg_match('/[\p{L}\p{M}]/u', $word);
	}
}