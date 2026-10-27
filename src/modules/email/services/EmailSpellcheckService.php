<?php

/**
 * EMail - Spellchecking
 *
 * @author Sigurd Nes <sigurdne at gmail.com>
 * @copyright Copyright (C) 2026 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @package email
 */

/**
 * Checks dictionary availability and reports misspelled compose text with replacement suggestions.
 */

namespace App\modules\email\services;

final class EmailSpellcheckService
{
	/**
	 * Check whether the configured spelling backend can open the requested dictionary.
	 *
	 * @param string $language The requested dictionary language code.
	 * @return bool Whether spellchecking is available for the language.
	 */
	public static function available(string $language = 'en'): bool
	{
		return self::openDictionary($language) !== false;
	}

	/**
	 * Find misspelled nonquoted message words and bounded replacement suggestions.
	 *
	 * @param string $text The compose text to check, including any quoted lines.
	 * @param string $language The requested dictionary language code.
	 * @return array Backend availability and spelling issues with suggestions.
	 */
	public function check(string $text, string $language): array
	{
		$link = self::openDictionary($language);
		if (!$link)
        {
        	return ['available' => false, 'issues' => []];
        }
		$issues = [];
		$seen = [];
		foreach (preg_split('/\R/u', $text) ?: [] as $line)
		{
			if (str_starts_with(ltrim($line), '>'))
            {
            	continue;
            }
			preg_match_all('/\S+/u', $line, $matches);
			foreach ($matches[0] as $token)
			{
				if (!$this->shouldCheck($token))
                {
                	continue;
                }
				$word = str_replace(['.', ',', '?', ':', ';', '!', '"', '(', ')', '*', '[', ']'], '', $token);
				if (!$this->shouldCheck($word) || pspell_check($link, $word))
                {
                	continue;
                }
				$key = function_exists('mb_strtolower') ? mb_strtolower($word, 'UTF-8') : strtolower($word);
				if (isset($seen[$key]))
                {
                	continue;
                }
				$seen[$key] = true;
				$suggestions = array_slice((array) pspell_suggest($link, $word), 0, 6);
				$issues[] = ['word' => $word, 'suggestions' => array_values(array_map('strval', $suggestions))];
				if (count($issues) >= 100)
                {
                	break 2;
                }
			}
		}
		return ['available' => true, 'issues' => $issues];
	}

	/**
	 * Open a Pspell dictionary when the extension and language data are available.
	 *
	 * @param string $language The requested dictionary language code.
	 * @return mixed The dictionary object or legacy handle, or false when unavailable.
	 */
	private static function openDictionary(string $language): mixed
	{
		if (!function_exists('pspell_new') || !function_exists('pspell_check') || !function_exists('pspell_suggest'))
        {
        	return false;
        }
		$language = trim($language) !== '' ? $language : 'en';
		return @pspell_new($language, '', '', '', defined('PSPELL_FAST') ? PSPELL_FAST : 0);
	}

	/**
	 * Exclude short tokens, addresses, URLs, and nonword content from spellchecking.
	 *
	 * @param string $word The candidate token to classify for spellchecking.
	 * @return bool Whether the token should be checked.
	 */
	private function shouldCheck(string $word): bool
	{
		if (strlen(trim($word)) < 2 || preg_match('/[0-9_=@<>]/u', $word))
        {
        	return false;
        }
		if (preg_match('#(?:https?://|www\.)#i', $word))
        {
        	return false;
        }
		if (preg_match('/[a-z0-9-]+\.(?:[a-z]{2,}|local)\b/i', $word))
        {
        	return false;
        }
		return (bool) preg_match('/[\p{L}\p{M}]/u', $word);
	}
}