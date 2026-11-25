<?php

namespace App\modules\booking\helpers;

/**
 * What the legacy event edit form shows when the event has no participants or no target audience of its own.
 *
 * An event whose agegroup rows were never stored would ask the officer to type the participants again,
 * though its application has them (#1498). The edit form takes them from the application instead. This
 * is for display only: a POST is still validated against the event's own rows, so validation is unchanged.
 *
 * Pure: no database and no framework. The caller reads the application and passes it in.
 */
final class EventFormFallback
{
	/**
	 * The fields to show in place of the event's own, where the event has none.
	 *
	 * Returns a subset of the keys agegroups and audience. An event that has its own rows gets nothing back,
	 * so its stored values are never replaced.
	 *
	 * @param array $event       the stored event: agegroups (rows with male and female), audience (ids)
	 * @param array $application the stored application, same shape; may be empty
	 */
	public static function fillFor(array $event, array $application): array
	{
		$fill = [];

		// Participants come from the application only when it has at least one. An application that has none
		// leaves the field empty, so the officer still has to enter them and the save is refused as before.
		if (empty($event['agegroups']) && self::hasParticipants($application['agegroups'] ?? []))
		{
			$fill['agegroups'] = $application['agegroups'];
		}

		if (empty($event['audience']) && !empty($application['audience']))
		{
			$fill['audience'] = $application['audience'];
		}

		return $fill;
	}

	/**
	 * True when at least one agegroup row has a male or a female participant.
	 */
	public static function hasParticipants(array $agegroups): bool
	{
		foreach ($agegroups as $row)
		{
			if ((int)($row['male'] ?? 0) > 0 || (int)($row['female'] ?? 0) > 0)
			{
				return true;
			}
		}

		return false;
	}
}
