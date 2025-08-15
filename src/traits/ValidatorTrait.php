<?php

namespace App\traits;

trait ValidatorTrait
{
	/**
	 * Validate that a value is positive (greater than 0)
	 */
	public static function validatePositive($value, string $fieldName = 'Value'): ?string
	{
		return ($value > 0) ? null : "$fieldName must be greater than 0";
	}

	/**
	 * Validate that a value is zero or positive (>= 0)
	 */
	public static function validateNonNegative($value, string $fieldName = 'Value'): ?string
	{
		return ($value >= 0) ? null : "$fieldName must be zero or positive";
	}

	/**
	 * Validate email format
	 */
	public static function validateEmail($value, string $fieldName = 'Email'): ?string
	{
		if (empty($value)) return null; // Allow empty emails
		return filter_var($value, FILTER_VALIDATE_EMAIL) ? null : "$fieldName is invalid";
	}

	/**
	 * Validate Norwegian SSN (fødselsnummer) with proper check digit validation
	 */
	public static function validateNorwegianSSN($value, string $fieldName = 'SSN'): ?string
	{
		if (empty($value)) return null;
		
		// Remove any spaces or dashes and check basic format
		$ssn = preg_replace('/[\s\-]/', '', $value);
		if (!preg_match('/^\d{11}$/', $ssn)) {
			return "$fieldName must be 11 digits";
		}
		
		// Extract digits
		$digits = array_map('intval', str_split($ssn));
		
		// Validate date part (first 6 digits: DDMMYY)
		$day = $digits[0] * 10 + $digits[1];
		$month = $digits[2] * 10 + $digits[3];
		$year = $digits[4] * 10 + $digits[5];
		
		if ($day < 1 || $day > 31 || $month < 1 || $month > 12) {
			return "$fieldName contains invalid date";
		}
		
		// Calculate first check digit (position 9)
		$checkWeights1 = [3, 7, 6, 1, 8, 9, 4, 5, 2];
		$sum1 = 0;
		for ($i = 0; $i < 9; $i++) {
			$sum1 += $digits[$i] * $checkWeights1[$i];
		}
		$remainder1 = $sum1 % 11;
		$checkDigit1 = $remainder1 < 2 ? $remainder1 : 11 - $remainder1;
		
		if ($remainder1 < 2) {
			$checkDigit1 = $remainder1;
		} else {
			$checkDigit1 = 11 - $remainder1;
		}
		
		if ($checkDigit1 !== $digits[9]) {
			return "$fieldName has invalid check digit";
		}
		
		// Calculate second check digit (position 10)
		$checkWeights2 = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
		$sum2 = 0;
		for ($i = 0; $i < 10; $i++) {
			$sum2 += $digits[$i] * $checkWeights2[$i];
		}
		$remainder2 = $sum2 % 11;
		
		if ($remainder2 < 2) {
			$checkDigit2 = $remainder2;
		} else {
			$checkDigit2 = 11 - $remainder2;
		}
		
		if ($checkDigit2 !== $digits[10]) {
			return "$fieldName has invalid check digit";
		}
		
		return null; // Valid Norwegian SSN
	}

	/**
	 * Validate Norwegian organization number (organisasjonsnummer) with proper check digit validation
	 */
	public static function validateNorwegianOrgNumber($value, string $fieldName = 'Organization number'): ?string
	{
		if (empty($value)) return null;
		
		// Remove any spaces or dashes and check basic format
		$orgNumber = preg_replace('/[\s\-]/', '', $value);
		if (!preg_match('/^\d{9}$/', $orgNumber)) {
			return "$fieldName must be 9 digits";
		}
		
		// Extract digits
		$digits = array_map('intval', str_split($orgNumber));
		
		// Norwegian organization numbers must start with 8 or 9
		$firstDigit = $digits[0];
		if (!in_array($firstDigit, [8, 9])) {
			return "$fieldName must start with 8 or 9";
		}
		
		// Use MOD11 algorithm for check digit validation
		$weights = [3, 2, 7, 6, 5, 4, 3, 2];
		$sum = 0;
		
		for ($i = 0; $i < 8; $i++) {
			$sum += $digits[$i] * $weights[$i];
		}
		
		$remainder = $sum % 11;
		
		// Calculate check digit
		if ($remainder == 0) {
			$checkDigit = 0;
		} elseif ($remainder == 1) {
			// If remainder is 1, the organization number is invalid
			return "$fieldName is invalid (check digit cannot be calculated)";
		} else {
			$checkDigit = 11 - $remainder;
		}
		
		if ($checkDigit !== $digits[8]) {
			return "$fieldName has invalid check digit";
		}
		
		return null; // Valid Norwegian organization number
	}

	/**
	 * Validate Swedish organization number (organisationsnummer) with proper validation
	 */
	public static function validateSwedishOrgNumber($value, string $fieldName = 'Organization number'): ?string
	{
		if (empty($value)) return null;
		
		// Remove any spaces or dashes and check basic format
		$orgNumber = preg_replace('/[\s\-]/', '', $value);
		if (!preg_match('/^\d{10}$/', $orgNumber)) {
			return "$fieldName must be 10 digits";
		}
		
		// Extract digits
		$digits = array_map('intval', str_split($orgNumber));
		
		// Swedish organization numbers must start with certain digits
		// First digit indicates the type of organization:
		// 1 = Death estates, 2 = State, county councils, municipalities, parishes
		// 3 = Foreign companies, 5 = Aktiebolag, 6 = Ekonomisk förening
		// 7 = Ideell förening, 8 = Handelsbolag, 9 = Kommanditbolag
		$firstDigit = $digits[0];
		if (!in_array($firstDigit, [1, 2, 3, 5, 6, 7, 8, 9])) {
			return "$fieldName has invalid organization type";
		}
		
		// Third digit must be >= 2 for most organization types (except some special cases)
		if ($digits[2] < 2 && !($firstDigit == 1 || $firstDigit == 2)) {
			return "$fieldName has invalid format";
		}
		
		// Use Luhn algorithm for check digit validation (same as Swedish SSN)
		$sum = 0;
		for ($i = 0; $i < 9; $i++) {
			$multiplier = ($i % 2) + 1;
			$product = $digits[$i] * $multiplier;
			
			// If product is two digits, add them together
			if ($product > 9) {
				$product = intval($product / 10) + ($product % 10);
			}
			
			$sum += $product;
		}
		
		$checkDigit = (10 - ($sum % 10)) % 10;
		
		if ($checkDigit !== $digits[9]) {
			return "$fieldName has invalid check digit";
		}
		
		return null; // Valid Swedish organization number
	}

	/**
	 * Validate Norwegian OR Swedish organization number with proper validation
	 */
	public static function validateNorwegianOrSwedishOrgNumber($value, string $fieldName = 'Organization number'): ?string
	{
		if (empty($value)) return null;

		// Try Norwegian organization number validation first
		$norwegianError = self::validateNorwegianOrgNumber($value, $fieldName);
		if ($norwegianError === null) {
			return null; // Valid Norwegian organization number
		}

		// Try Swedish organization number validation
		$swedishError = self::validateSwedishOrgNumber($value, $fieldName);
		if ($swedishError === null) {
			return null; // Valid Swedish organization number
		}

		// Neither format is valid
		return "$fieldName must be a valid Norwegian (9 digits) or Swedish (10 digits) organization number";
	}

	/**
	 * Validate array is not empty
	 */
	public static function validateNonEmptyArray($value, string $fieldName = 'Array'): ?string
	{
		return (is_array($value) && count($value) > 0) ? null : "$fieldName cannot be empty";
	}

	/**
	 * Validate phone number (basic format)
	 */
	public static function validatePhone($value, string $fieldName = 'Phone'): ?string
	{
		if (empty($value)) return null;
		return preg_match('/^[\+]?[0-9\s\-\(\)]{8,}$/', $value) ? null : "$fieldName format is invalid";
	}

	/**
	 * Validate URL format
	 */
	public static function validateUrl($value, string $fieldName = 'URL'): ?string
	{
		if (empty($value)) return null;
		return filter_var($value, FILTER_VALIDATE_URL) ? null : "$fieldName is invalid";
	}

	/**
	 * Validate string length
	 */
	public static function validateMaxLength($value, int $maxLength, string $fieldName = 'Field'): ?string
	{
		if (!is_string($value)) return null;
		return strlen($value) <= $maxLength ? null : "$fieldName must be $maxLength characters or less";
	}

	/**
	 * Validate required field
	 */
	public static function validateRequired($value, string $fieldName = 'Field'): ?string
	{
		if (is_null($value) || $value === '' || (is_array($value) && count($value) === 0))
		{
			return "$fieldName is required";
		}
		return null;
	}

	/**
	 * Validate Swedish SSN (personnummer) with proper check digit validation
	 */
	public static function validateSwedishSSN($value, string $fieldName = 'Swedish SSN'): ?string
	{
		if (empty($value)) return null;
		
		// Remove any spaces, dashes, or plus signs and check basic format
		$ssn = preg_replace('/[\s\-\+]/', '', $value);
		
		// Swedish SSN can be 10 digits (YYMMDDNNNC) or 12 digits (YYYYMMDDNNNC)
		if (!preg_match('/^\d{10}$/', $ssn) && !preg_match('/^\d{12}$/', $ssn)) {
			return "$fieldName must be 10 or 12 digits";
		}
		
		// If 12 digits, take the last 10 for validation
		if (strlen($ssn) === 12) {
			$ssn = substr($ssn, 2);
		}
		
		// Extract digits
		$digits = array_map('intval', str_split($ssn));
		
		// Validate date part (first 6 digits: YYMMDD)
		$year = $digits[0] * 10 + $digits[1];
		$month = $digits[2] * 10 + $digits[3];
		$day = $digits[4] * 10 + $digits[5];
		
		if ($day < 1 || $day > 31 || $month < 1 || $month > 12) {
			return "$fieldName contains invalid date";
		}
		
		// Use Luhn algorithm for check digit validation
		$sum = 0;
		for ($i = 0; $i < 9; $i++) {
			$multiplier = ($i % 2) + 1;
			$product = $digits[$i] * $multiplier;
			
			// If product is two digits, add them together
			if ($product > 9) {
				$product = intval($product / 10) + ($product % 10);
			}
			
			$sum += $product;
		}
		
		$checkDigit = (10 - ($sum % 10)) % 10;
		
		if ($checkDigit !== $digits[9]) {
			return "$fieldName has invalid check digit";
		}
		
		return null; // Valid Swedish SSN
	}

	/**
	 * Validate Norwegian OR Swedish SSN
	 */
	public static function validateNorwegianOrSwedishSSN($value, string $fieldName = 'SSN'): ?string
	{
		if (empty($value)) return null;
		
		// Try Norwegian SSN validation first
		$norwegianError = self::validateNorwegianSSN($value, $fieldName);
		if ($norwegianError === null) {
			return null; // Valid Norwegian SSN
		}
		
		// Try Swedish SSN validation
		$swedishError = self::validateSwedishSSN($value, $fieldName);
		if ($swedishError === null) {
			return null; // Valid Swedish SSN
		}
		
		// Neither format is valid
		return "$fieldName must be a valid Norwegian (11 digits) or Swedish (10/12 digits) personal number";
	}
}
