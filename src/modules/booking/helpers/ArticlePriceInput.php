<?php

namespace App\modules\booking\helpers;

/**
 * The price and date an administrator types on the article mapping's
 * "Prising" tab, and the VAT-inclusive figure shown beside the price.
 *
 * js/base/article_mapping.js runs the same rules in the browser, so the
 * figures shown while typing are the ones stored; change both or neither.
 */
class ArticlePriceInput
{
	/**
	 * Digits before the decimal mark that bb_article_price.price
	 * (numeric(10,2)) holds
	 */
	const MAX_INTEGER_DIGITS = 8;

	/**
	 * A typed price in øre, or null when it is not a price.
	 *
	 * Whitespace groups digits. The last '.' or ',' is the decimal mark when
	 * one or two digits follow it; every other separator groups thousands,
	 * so "136.99" and "136,99" are both 136.99 and "1.000" is 1000. Grouped
	 * digits come in threes after a first group of one to three, so "0,125"
	 * and "12,3456" are refused rather than guessed at.
	 */
	public static function parseCents(?string $input): ?int
	{
		$value = (string)preg_replace('/[ \t\n\r\f\v\x{00A0}\x{2009}\x{202F}]+/u', '', (string)$input);

		if (!preg_match('/^([+-]?)([0-9.,]*[0-9][0-9.,]*)$/', $value, $matches))
		{
			return null;
		}

		$integer = $matches[2];
		$fraction = '';
		if (preg_match('/^(.*)[.,]([0-9]{1,2})$/', $matches[2], $parts))
		{
			$integer = $parts[1];
			$fraction = $parts[2];
		}

		if (preg_match('/[.,]/', $integer) && !preg_match('/^[1-9][0-9]{0,2}([.,][0-9]{3})+$/', $integer))
		{
			return null;
		}

		$integer = ltrim(str_replace(array('.', ','), '', $integer), '0');
		if (strlen($integer) > self::MAX_INTEGER_DIGITS)
		{
			return null;
		}

		$cents = (int)$integer * 100 + (int)str_pad($fraction, 2, '0');

		return $matches[1] === '-' ? -$cents : $cents;
	}

	/**
	 * A typed "valid from" date as YYYY-MM-DD, or null when it is not one.
	 * Only that form is read, as the price history shows and picks it, so
	 * "15.10.2030" and "2030-02-30" are refused rather than guessed at.
	 */
	public static function parseDate(?string $input): ?string
	{
		$value = trim((string)$input);

		if (!preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/', $value, $matches)
			|| !checkdate((int)$matches[2], (int)$matches[3], (int)$matches[1]))
		{
			return null;
		}

		return $value;
	}

	/**
	 * Øre as a decimal string with two decimals and no grouping: "1000.50"
	 */
	public static function format(int $cents, string $decimalMark = '.'): string
	{
		$magnitude = abs($cents);

		return ($cents < 0 ? '-' : '') . intdiv($magnitude, 100) . $decimalMark . str_pad((string)($magnitude % 100), 2, '0', STR_PAD_LEFT);
	}

	/**
	 * The VAT-inclusive price in øre, rounded half away from zero to whole
	 * øre: the figure a purchase order line bills (unit_price + tax, the tax
	 * rounded by numeric(10,2)).
	 */
	public static function inclVatCents(int $exCents, int $percent): int
	{
		$magnitude = intdiv(abs($exCents) * (100 + $percent) * 2 + 100, 200);

		return $exCents < 0 ? -$magnitude : $magnitude;
	}
}
