<?php

namespace App\helpers;

/**
 * The rule for an owner check that compares an identifier stored on an object (an ssn, an organisation number) with
 * the viewer's own: an empty identifier identifies nobody, so it never makes anyone the owner.
 *
 * SerializableTrait applies it to the owner arms of @Expose(when=…); EventService::checkEventOwnerShip applies it to
 * who may change an event. Loosely, a stored '' or NULL equals the null of a viewer with no session, and an empty
 * element in a delegate's list of organisations equals a stored '' or NULL.
 */
final class OwnerIdentifier
{
	/**
	 * What MinId2 puts in a delegate's list of organisations for an organisation that has an ssn and no number. It is
	 * the same string for every such organisation, so it says nothing about which one is meant.
	 */
	public const PLACEHOLDER_ORG_NUMBER = '000000000';

	/**
	 * Null, false, [] or a string that is empty after trim(). 0 and '0' are values, not empty.
	 */
	public static function isEmpty(mixed $value): bool
	{
		return $value === null || $value === false || $value === []
			|| (is_string($value) && trim($value) === '');
	}

	/**
	 * The organisation number MinId2 gives every organisation that has an ssn and no number ('000000000'), compared
	 * as a string so the int 0 and other numbers are not taken for it.
	 */
	public static function isPlaceholderOrgNumber(mixed $value): bool
	{
		return is_string($value) && trim($value) === self::PLACEHOLDER_ORG_NUMBER;
	}
}
