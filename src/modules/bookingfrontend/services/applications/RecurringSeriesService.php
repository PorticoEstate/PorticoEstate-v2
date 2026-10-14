<?php

namespace App\modules\bookingfrontend\services\applications;

use App\modules\booking\repositories\ApplicationRepository as BookingApplicationRepository;
use App\modules\booking\services\ApplicationService as BookingApplicationService;
use DateTime;
use DateTimeZone;
use RuntimeException;

/**
 * The whole series of a recurring application, as Min side shows it (GH #1392).
 *
 * A recurring application stores ONE requested date plus recurring_info, so the
 * occurrences exist nowhere until the case officer creates them. They come from the
 * case officer's own recurring preview (booking ApplicationService::generateRecurringPreview),
 * unchanged: the applicant sees the dates the officer creates from, with the same
 * season pick and the same placement of the application's elements on them.
 *
 * Only the applicant's own data leaves here. The preview also carries the case
 * officer's view of each occurrence - collision details naming OTHER parties'
 * reservations, an admin schedule link - and none of that is passed on: every field
 * is copied by name, never spread.
 */
class RecurringSeriesService
{
	private const TIMEZONE = 'Europe/Oslo';

	/**
	 * @param int    $applicationId     Already judged by the caller's access guard
	 * @param string $applicationStatus bb_application.status, which decides an occurrence
	 *                                  that holds no active element (see occurrenceStatus)
	 * @return array{application_id:int, interval_weeks:int, occurrences:array, outside_series:array}
	 * @throws RuntimeException 400 when the application is not recurring or has no date,
	 *                          404 when it does not exist - the preview's own refusals
	 */
	public function getSeries(int $applicationId, string $applicationStatus): array
	{
		$preview = (new BookingApplicationService(new BookingApplicationRepository()))
			->generateRecurringPreview($applicationId);

		$occurrences = [];
		foreach ($preview['items'] as $item) {
			$occurrences[] = [
				'from_'    => $this->isoTime($item['from_']),
				'to_'      => $this->isoTime($item['to_']),
				'status'   => $this->occurrenceStatus($item, $applicationStatus),
				'elements' => array_map([$this, 'element'], $item['associations']),
			];
		}

		return [
			'application_id' => $applicationId,
			'interval_weeks' => (int) $preview['interval_weeks'],
			'occurrences'    => $occurrences,
			'outside_series' => array_map([$this, 'element'], $preview['outside_series']),
		];
	}

	/**
	 * What the applicant is told about one occurrence; the first rule that holds wins.
	 *
	 * An occurrence holding an active element of its own is the preview's `exists`, the
	 * same state the case officer sees as created - so it is read from the preview,
	 * never matched again here, and it survives a changed time exactly as far as the
	 * officer's placement does. A rejected or cancelled application decides every other
	 * occurrence; then elements that are all inactive mean the time was withdrawn, and
	 * an accepted application without one means this occurrence was never allocated
	 * (skipped as a collision, or not created).
	 */
	private function occurrenceStatus(array $item, string $applicationStatus): string
	{
		if ($item['exists']) {
			return 'allocated';
		}
		if ($applicationStatus === 'REJECTED') {
			return 'rejected';
		}
		if ($applicationStatus === 'CANCELLED') {
			return 'cancelled';
		}
		if (!empty($item['associations'])) {
			return 'withdrawn';
		}
		if ($applicationStatus === 'ACCEPTED') {
			return 'not_allocated';
		}
		return 'awaiting';
	}

	/**
	 * One of the application's own elements (bb_application_association), by name.
	 * A missing time stays null: DateTime would read an empty string as "now".
	 */
	private function element(array $association): array
	{
		return [
			'type'   => (string) $association['type'],
			'id'     => (int) $association['id'],
			'active' => (int) $association['active'] === 1,
			'from_'  => empty($association['from_']) ? null : $this->isoTime((string) $association['from_']),
			'to_'    => empty($association['to_']) ? null : $this->isoTime((string) $association['to_']),
		];
	}

	/**
	 * A naive local time as Europe/Oslo ISO 8601, the format applications/{id}/schedule
	 * sends its from_/to_ in (ScheduleEntityService).
	 */
	private function isoTime(string $naiveLocal): string
	{
		return (new DateTime($naiveLocal, new DateTimeZone(self::TIMEZONE)))->format('c');
	}
}
