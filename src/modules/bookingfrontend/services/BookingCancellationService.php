<?php

namespace App\modules\bookingfrontend\services;

use App\modules\bookingfrontend\repositories\BookingCancellationRepository;
use App\modules\phpgwapi\models\ServerSettings;
use DateTimeImmutable;
use Exception;
use InvalidArgumentException;
use RuntimeException;

/**
 * Cancel-preview and scoped cancel for a booking series.
 *
 * Ported from bookingfrontend_uibooking::cancel() (bookingfrontend/inc/class.uibooking.inc.php:1265),
 * the DELETE branch (:1413 onward, reached when config booking.user_can_delete_bookings == 'yes').
 * Sibling of AllocationCancellationService, built the same way and departing from it in exactly the
 * places a booking's own semantics differ from an allocation's:
 *
 *  - A booking is never "blocked" from being cancelled - only its OWN caller can reach it (the
 *    #1210 guard), and legacy's single-occurrence branch (:1466-1491) deletes the booking
 *    unconditionally. What legacy conditions is whether the booking's PARENT ALLOCATION can also
 *    go: :1479 `check_for_booking($allocation_id)` after the booking is already gone. That is the
 *    cascade this service discloses - see describeOccurrence().
 *  - The recurring walk (:1536-1601) matches each occurrence by BOOKING first
 *    (`get_booking_id`), and only when no booking exists that week does it fall back to a "bare"
 *    allocation match (`check_for_booking($booking)`, the sobooking variant - an allocation with
 *    literally no booking on it). Both are ported; see walkSeries()/describeOccurrence().
 *
 * THE SERIES IS RECONSTRUCTED BY DATE-WALK, exactly as legacy does it and exactly as
 * AllocationCancellationService does it - wall-clock weeks via DateTimeImmutable::modify(), not
 * legacy's fixed-second arithmetic, for the same DST reason documented there.
 */
class BookingCancellationService
{
	public const SCOPE_OCCURRENCE = 'occurrence';
	public const SCOPE_SEASON = 'season';
	public const SCOPE_UNTIL = 'until';

	public const STATUS_CANCELLABLE = 'cancellable';
	public const STATUS_NO_BOOKING = 'no_booking';

	/** Same stop-not-a-business-rule bound as AllocationCancellationService::MAX_OCCURRENCES. */
	private const MAX_OCCURRENCES = 520;

	private BookingCancellationRepository $repository;

	public function __construct(?BookingCancellationRepository $repository = null)
	{
		$this->repository = $repository ?: new BookingCancellationRepository();
	}

	/**
	 * The booking row, for the guard and for the caller's own error handling.
	 */
	public function getBooking(int $id): ?array
	{
		return $this->repository->getBooking($id);
	}

	/**
	 * Walk the series and report, per occurrence, what cancelling it would do - INCLUDING the
	 * delete_allocation cascade, disclosed regardless of whether this call's own $options asked
	 * for it. A caller deciding whether to check that box needs to see its effect first; only
	 * cancel() reads options['delete_allocation'] to decide whether to actually act on it.
	 *
	 * Read-only. Called both by the preview endpoint and, again, inside the cancel request, so
	 * that what is deleted is decided by the same code that reported what would be.
	 *
	 * @param array $booking the row from getBooking()
	 * @param array $options scope, repeat_until, field_interval, delete_allocation
	 */
	public function preview(array $booking, array $options): array
	{
		$scope = $this->resolveScope($options);
		$fieldInterval = $this->resolveFieldInterval($options);
		$deleteAllocationRequested = $this->resolveDeleteAllocation($options);

		$occurrences = $scope === self::SCOPE_OCCURRENCE
			? [$this->describeClickedOccurrence($booking)]
			: $this->walkSeries($booking, $this->resolveRepeatUntil($scope, $booking, $options), $fieldInterval);

		$cancellable = 0;
		$noBooking = 0;
		$cascadingAllocationIds = [];
		foreach ($occurrences as $occurrence)
		{
			if ($occurrence['status'] === self::STATUS_CANCELLABLE)
			{
				$cancellable++;
			}
			else
			{
				$noBooking++;
			}

			if ($occurrence['allocation_would_cascade'] && $occurrence['allocation_id'] !== null)
			{
				$cascadingAllocationIds[$occurrence['allocation_id']] = true;
			}
		}

		$effectiveRepeatUntil = $scope === self::SCOPE_OCCURRENCE
			? null
			: $this->resolveRepeatUntil($scope, $booking, $options)->format('Y-m-d');

		$resourceNames = $this->repository->getResourceNames($booking['resources']);
		$splitPoolActive = $this->splitPoolActive();

		return [
			'booking_id' => (int)$booking['id'],
			'scope' => $scope,
			'scope_resolved' => [
				'effective_repeat_until' => $effectiveRepeatUntil,
				'field_interval' => $fieldInterval,
				'season_id' => (int)$booking['season_id'],
				'season_name' => $booking['season_name'],
				'season_to' => $booking['season_to'],
			],
			'group_id' => (int)$booking['group_id'],
			'group_name' => $booking['group_name'],
			'organization_id' => $booking['organization_id'] === null ? null : (int)$booking['organization_id'],
			'organization_name' => $booking['organization_name'],
			'building_name' => $booking['building_name'],
			'resources' => array_map(static function ($id) use ($resourceNames)
			{
				return ['id' => (int)$id, 'name' => $resourceNames[(int)$id] ?? null];
			}, $booking['resources']),
			'delete_allocation_requested' => $deleteAllocationRequested,
			// Disclosure required regardless of delete_allocation_requested - see the class docblock
			// and the preview() docblock: a caller must be able to see the cascade BEFORE opting in.
			'cascade' => [
				'allocation_ids' => array_values(array_map('intval', array_keys($cascadingAllocationIds))),
				'count' => count($cascadingAllocationIds),
				'note' => count($cascadingAllocationIds) > 0
					? 'Cancelling the listed occurrence(s) would leave their parent allocation with no other booking. '
						. 'If delete_allocation is set on the cancel call, that allocation is hard-deleted too - not just this booking.'
					: 'No occurrence in this scope would leave its parent allocation without another booking, so delete_allocation has nothing to cascade to here.',
			],
			// legacy's split_pool (booking.config_data['split_pool'], read at :1435) only ever
			// changed WHO the legacy recipient-pool notification went to (building_users($split)).
			// This port sends no notification at all on either endpoint (see class docblocks and
			// acceptance arm 6), so the setting has no effect here - disclosed for parity with the
			// legacy confirmation screen, not because this service acts on it.
			'split_pool' => [
				'active' => $splitPoolActive,
				'effect' => 'Legacy uses this only to split the notification recipient pool by resource activity. '
					. 'This endpoint sends no notification, so the setting has no effect on what gets deleted.',
			],
			'total' => count($occurrences),
			'cancellable' => $cancellable,
			'no_booking' => $noBooking,
			'occurrences' => $occurrences,
			'confirm_token' => $this->confirmToken($booking, $scope, $effectiveRepeatUntil, $fieldInterval, $deleteAllocationRequested, $occurrences),
		];
	}

	/**
	 * Delete every cancellable booking in the scope, and cascade to its parent allocation only
	 * where delete_allocation_requested is true AND the preview marked that allocation as would-cascade.
	 *
	 * The preview is re-run here, inside the same request, and the caller's confirm_token must
	 * still match it - same TOCTOU close as AllocationCancellationService::cancel.
	 *
	 * The whole scope runs in ONE transaction, unlike legacy which commits per occurrence.
	 *
	 * @throws RuntimeException on a stale confirm_token
	 */
	public function cancel(array $booking, array $options): array
	{
		$preview = $this->preview($booking, $options);

		$presentedToken = $options['confirm_token'] ?? null;
		if (!is_string($presentedToken) || $presentedToken === '')
		{
			throw new InvalidArgumentException('confirm_token is required; obtain one from cancel-preview');
		}
		if (!hash_equals($preview['confirm_token'], $presentedToken))
		{
			throw new RuntimeException('confirm_token is stale: the series changed since the preview');
		}

		$deleteAllocation = $preview['delete_allocation_requested'];

		$deletedBookings = [];
		$deletedAllocations = [];
		$deletedAllocationIds = [];
		$skipped = [];

		$this->repository->beginTransaction();
		try
		{
			foreach ($preview['occurrences'] as $occurrence)
			{
				if ($occurrence['status'] === self::STATUS_CANCELLABLE)
				{
					$this->repository->deleteBooking((int)$occurrence['booking_id']);
					$deletedBookings[] = (int)$occurrence['booking_id'];
				}
				else
				{
					$skipped[] = [
						'booking_id' => $occurrence['booking_id'],
						'from_' => $occurrence['from_'],
						'to_' => $occurrence['to_'],
						'status' => $occurrence['status'],
						'reason' => $occurrence['reason'],
					];
				}

				$allocationId = $occurrence['allocation_id'];
				if (
					$deleteAllocation
					&& $allocationId !== null
					&& $occurrence['allocation_would_cascade']
					&& !isset($deletedAllocationIds[$allocationId])
				)
				{
					$this->repository->deleteAllocation((int)$allocationId);
					$deletedAllocationIds[$allocationId] = true;
					$deletedAllocations[] = (int)$allocationId;
				}
			}
			$this->repository->commit();
		}
		catch (Exception $e)
		{
			$this->repository->rollBack();
			throw $e;
		}

		return [
			'mode' => 'deleted',
			'booking_id' => (int)$booking['id'],
			'scope' => $preview['scope'],
			'scope_resolved' => $preview['scope_resolved'],
			'delete_allocation_requested' => $deleteAllocation,
			'deleted_bookings' => $deletedBookings,
			'deleted_bookings_count' => count($deletedBookings),
			'deleted_allocations' => $deletedAllocations,
			'deleted_allocations_count' => count($deletedAllocations),
			'skipped' => $skipped,
			'skipped_count' => count($skipped),
		];
	}

	/**
	 * The clicked occurrence, described from the booking row itself rather than re-matched by
	 * date/group/resources - the id is already known, so there is nothing to look up.
	 */
	private function describeClickedOccurrence(array $booking): array
	{
		return $this->describeOccurrence(
			0,
			$this->toDateTime($booking['from_']),
			$this->toDateTime($booking['to_']),
			(int)$booking['id'],
			$booking['allocation_id'] === null ? null : (int)$booking['allocation_id'],
			(int)$booking['group_id'],
			$booking['organization_id'] === null ? null : (int)$booking['organization_id'],
			(int)$booking['season_id'],
			$booking['resources']
		);
	}

	/**
	 * Reconstruct the series by walking forward one interval at a time and matching each slot to a
	 * stored booking (or, failing that, a bare allocation), which is what legacy's loop
	 * (:1536-1601) does. Same wall-clock-week departure from legacy's fixed-second walk as
	 * AllocationCancellationService::walkSeries, for the same DST reason - see that method's
	 * docblock.
	 *
	 * The i=0 iteration uses the UNSHIFTED from_/to_, so the clicked booking is INCLUDED in the
	 * season and until scopes, matching legacy's `$_POST['from_']`/`$_POST['to_']` being the
	 * clicked booking's own dates at i=0.
	 */
	private function walkSeries(array $booking, DateTimeImmutable $repeatUntil, int $fieldInterval): array
	{
		$from = $this->toDateTime($booking['from_']);
		$to = $this->toDateTime($booking['to_']);
		$groupId = (int)$booking['group_id'];
		$organizationId = $booking['organization_id'] === null ? null : (int)$booking['organization_id'];
		$seasonId = (int)$booking['season_id'];
		$resourceIds = $booking['resources'];

		$occurrences = [];
		for ($i = 0; $i < self::MAX_OCCURRENCES; $i++)
		{
			$step = $i * $fieldInterval;
			$occurrenceFrom = $step === 0 ? $from : $from->modify('+' . $step . ' weeks');
			$occurrenceTo = $step === 0 ? $to : $to->modify('+' . $step . ' weeks');

			// Legacy bounds the walk on the occurrence's TO date, not its from date - same as
			// AllocationCancellationService::walkSeries.
			if ($occurrenceTo > $repeatUntil)
			{
				break;
			}

			$fromStr = $occurrenceFrom->format('Y-m-d H:i:s');
			$toStr = $occurrenceTo->format('Y-m-d H:i:s');

			$matchedBookingId = $this->repository->findOccurrenceBookingId(
				$fromStr,
				$toStr,
				$groupId,
				$seasonId,
				$resourceIds
			);

			$allocationId = null;
			if ($matchedBookingId !== null)
			{
				$allocationId = $this->repository->getAllocationIdForBooking($matchedBookingId);
			}
			elseif ($organizationId !== null)
			{
				// No booking this week - legacy falls back to a bare-allocation match
				// (sobooking::check_for_booking) so a stray unbooked allocation in the scope can
				// still be cleaned up when delete_allocation is set.
				$allocationId = $this->repository->findBareAllocationId(
					$fromStr,
					$toStr,
					$organizationId,
					$seasonId,
					$resourceIds
				);
			}

			$occurrences[] = $this->describeOccurrence(
				$i,
				$occurrenceFrom,
				$occurrenceTo,
				$matchedBookingId,
				$allocationId,
				$groupId,
				$organizationId,
				$seasonId,
				$resourceIds
			);
		}

		return $occurrences;
	}

	/**
	 * One occurrence's verdict, including the delete_allocation cascade disclosure.
	 *
	 * Unlike AllocationCancellationService::describeOccurrence there is no "blocked" status here:
	 * nothing ever prevents a booking from being cancelled once the #1210 guard has already passed
	 * (legacy's single-occurrence branch deletes the booking unconditionally, :1474/:1478). What
	 * legacy conditions is whether the PARENT ALLOCATION also goes - that is allocation_would_cascade.
	 *
	 * @param int[] $resourceIds
	 */
	private function describeOccurrence(
		int $index,
		DateTimeImmutable $from,
		DateTimeImmutable $to,
		?int $bookingId,
		?int $allocationId,
		int $groupId,
		?int $organizationId,
		int $seasonId,
		array $resourceIds
	): array
	{
		$occurrence = [
			'index' => $index,
			'booking_id' => $bookingId,
			'from_' => $from->format('Y-m-d H:i:s'),
			'to_' => $to->format('Y-m-d H:i:s'),
			'allocation_id' => $allocationId,
			'allocation_would_cascade' => false,
			'allocation_other_bookings' => null,
		];

		if ($bookingId === null)
		{
			$occurrence['status'] = self::STATUS_NO_BOOKING;
			$occurrence['cancellable'] = false;
			$occurrence['reason'] = $allocationId === null ? 'no_booking_at_this_time' : 'no_booking_bare_allocation_only';
		}
		else
		{
			$occurrence['status'] = self::STATUS_CANCELLABLE;
			$occurrence['cancellable'] = true;
			$occurrence['reason'] = null;
		}

		if ($allocationId !== null)
		{
			$totalOnAllocation = $this->repository->countBookingsOnAllocation($allocationId);
			// A bare allocation (bookingId === null but allocationId found) already satisfies
			// NOT EXISTS booking by construction (see findBareAllocationId), so totalOnAllocation
			// is 0 there and it always cascades. Where a booking WAS matched, this booking is
			// itself one of the counted rows - deleting it leaves (total - 1) others.
			$remainingAfterDelete = $bookingId === null ? $totalOnAllocation : max(0, $totalOnAllocation - 1);
			$occurrence['allocation_other_bookings'] = $remainingAfterDelete;
			$occurrence['allocation_would_cascade'] = $remainingAfterDelete === 0;
		}

		return $occurrence;
	}

	private function resolveScope(array $options): string
	{
		$scope = $options['scope'] ?? null;
		$allowed = [self::SCOPE_OCCURRENCE, self::SCOPE_SEASON, self::SCOPE_UNTIL];

		if (!is_string($scope) || !in_array($scope, $allowed, true))
		{
			throw new InvalidArgumentException(
				'scope must be one of: ' . implode(', ', $allowed)
			);
		}

		return $scope;
	}

	/**
	 * Same +1 day bound as AllocationCancellationService::resolveRepeatUntil, and the same source
	 * for scope=season: the booking's OWN season (booking_bo->season_bo->read_single at :1423).
	 */
	private function resolveRepeatUntil(string $scope, array $booking, array $options): DateTimeImmutable
	{
		if ($scope === self::SCOPE_SEASON)
		{
			if (empty($booking['season_to']))
			{
				throw new InvalidArgumentException('the booking\'s season has no end date, so scope=season cannot be resolved');
			}

			return $this->toDateTime($booking['season_to'])->setTime(0, 0, 0)->modify('+1 day');
		}

		$repeatUntil = $options['repeat_until'] ?? null;
		if (!is_string($repeatUntil) || trim($repeatUntil) === '')
		{
			throw new InvalidArgumentException('repeat_until is required when scope is "until"');
		}

		try
		{
			$parsed = new DateTimeImmutable($repeatUntil);
		}
		catch (Exception $e)
		{
			throw new InvalidArgumentException('repeat_until is not a date this server can read: ' . $repeatUntil);
		}

		return $parsed->setTime(0, 0, 0)->modify('+1 day');
	}

	private function resolveFieldInterval(array $options): int
	{
		$interval = $options['field_interval'] ?? 1;

		if (is_string($interval) && trim($interval) === '')
		{
			$interval = 1;
		}

		$interval = (int)$interval;
		if ($interval < 1)
		{
			throw new InvalidArgumentException('field_interval must be a positive number of weeks');
		}

		return $interval;
	}

	/**
	 * Accepts a real JSON boolean (the shape this API actually speaks) as well as legacy's
	 * form-post 'on', so a client that still thinks in checkbox terms is not silently ignored.
	 * Everything else, including absence, is false - the same "gated on === true, never on
	 * truthiness" discipline the client's own AllocationCancellationError.isRequestMode uses.
	 */
	private function resolveDeleteAllocation(array $options): bool
	{
		$value = $options['delete_allocation'] ?? false;

		if (is_bool($value))
		{
			return $value;
		}

		if (is_string($value))
		{
			return in_array(strtolower($value), ['on', 'true', '1', 'yes'], true);
		}

		return false;
	}

	private function splitPoolActive(): bool
	{
		$config = ServerSettings::getInstance(true)->booking_config;

		return ($config->split_pool ?? 'no') === 'yes';
	}

	/**
	 * A digest of the exact set the preview reported, so the cancel that follows can prove it is
	 * acting on that set and not one that has since changed underneath it. delete_allocation is
	 * inside the digest: flipping it between preview and cancel changes what gets deleted, so it
	 * must invalidate the token exactly like a changed occurrence set does.
	 */
	private function confirmToken(
		array $booking,
		string $scope,
		?string $effectiveRepeatUntil,
		int $fieldInterval,
		bool $deleteAllocationRequested,
		array $occurrences
	): string
	{
		$material = [
			'booking_id' => (int)$booking['id'],
			'scope' => $scope,
			'repeat_until' => $effectiveRepeatUntil,
			'field_interval' => $fieldInterval,
			'delete_allocation' => $deleteAllocationRequested,
			'occurrences' => array_map(static function ($occurrence)
			{
				return [
					$occurrence['booking_id'],
					$occurrence['from_'],
					$occurrence['to_'],
					$occurrence['status'],
					$occurrence['allocation_id'],
					$occurrence['allocation_would_cascade'],
				];
			}, $occurrences),
		];

		return hash('sha256', json_encode($material));
	}

	private function toDateTime(string $value): DateTimeImmutable
	{
		try
		{
			return new DateTimeImmutable($value);
		}
		catch (Exception $e)
		{
			throw new InvalidArgumentException('unreadable stored timestamp: ' . $value);
		}
	}
}
