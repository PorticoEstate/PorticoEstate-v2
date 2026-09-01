<?php

namespace App\modules\bookingfrontend\services;

use App\modules\bookingfrontend\repositories\AllocationCancellationRepository;
use DateTimeImmutable;
use Exception;
use InvalidArgumentException;
use RuntimeException;

/**
 * Cancel-preview and scoped cancel for an allocation series.
 *
 * Ported from bookingfrontend_uiallocation::cancel(). The legacy scope selector is
 *
 *     if ($_POST['recurring'] != 'on' && $_POST['outseason'] != 'on')   // single occurrence
 *
 * and in the recurring branch `recurring` is tested first, so when both flags are set
 * `recurring` wins and the season end is ignored. That silent precedence is why this port
 * takes ONE named scope instead of two independent flags - the three scopes are mutually
 * exclusive here, and a caller cannot express the ambiguous state at all.
 *
 * THE SERIES IS RECONSTRUCTED BY DATE-WALK, exactly as legacy does it. It is NOT read from
 * bb_allocation.allocation_group_id: that column is non-NULL on 151 of 436,819 rows and is the
 * section-982 group-minting artifact, not a recurrence key.
 */
class AllocationCancellationService
{
	public const SCOPE_OCCURRENCE = 'occurrence';
	public const SCOPE_SEASON = 'season';
	public const SCOPE_UNTIL = 'until';

	public const STATUS_CANCELLABLE = 'cancellable';
	public const STATUS_BLOCKED = 'blocked_by_booking';
	public const STATUS_NO_ALLOCATION = 'no_allocation';

	/**
	 * The walked series is bounded so a malformed repeat_until cannot spin. A weekly series
	 * cannot outrun a season by this much; the bound is a stop, not a business rule, and it is
	 * reported when it bites rather than silently truncating the set.
	 */
	private const MAX_OCCURRENCES = 520;

	private AllocationCancellationRepository $repository;

	public function __construct(?AllocationCancellationRepository $repository = null)
	{
		$this->repository = $repository ?: new AllocationCancellationRepository();
	}

	/**
	 * The allocation row, for the guard and for the caller's own error handling.
	 */
	public function getAllocation(int $id): ?array
	{
		return $this->repository->getAllocation($id);
	}

	/**
	 * Walk the series and report, per occurrence, whether it can be cancelled and why not.
	 *
	 * Read-only. Called both by the preview endpoint and, again, inside the cancel request, so
	 * that what is deleted is decided by the same code that reported what would be.
	 *
	 * @param array $allocation the row from getAllocation()
	 * @param array $options scope, repeat_until, field_interval
	 */
	public function preview(array $allocation, array $options): array
	{
		$scope = $this->resolveScope($options);
		$fieldInterval = $this->resolveFieldInterval($options);

		$occurrences = $scope === self::SCOPE_OCCURRENCE
			? [$this->describeClickedOccurrence($allocation)]
			: $this->walkSeries($allocation, $this->resolveRepeatUntil($scope, $allocation, $options), $fieldInterval);

		$cancellable = 0;
		$blocked = 0;
		$missing = 0;
		foreach ($occurrences as $occurrence)
		{
			if ($occurrence['status'] === self::STATUS_CANCELLABLE)
			{
				$cancellable++;
			}
			elseif ($occurrence['status'] === self::STATUS_BLOCKED)
			{
				$blocked++;
			}
			else
			{
				$missing++;
			}
		}

		$effectiveRepeatUntil = $scope === self::SCOPE_OCCURRENCE
			? null
			: $this->resolveRepeatUntil($scope, $allocation, $options)->format('Y-m-d');

		$resourceNames = $this->repository->getResourceNames($allocation['resources']);

		return [
			'allocation_id' => (int)$allocation['id'],
			'scope' => $scope,
			'scope_resolved' => [
				'effective_repeat_until' => $effectiveRepeatUntil,
				'field_interval' => $fieldInterval,
				'season_id' => (int)$allocation['season_id'],
				'season_name' => $allocation['season_name'],
				'season_to' => $allocation['season_to'],
			],
			'organization_id' => (int)$allocation['organization_id'],
			'organization_name' => $allocation['organization_name'],
			'building_name' => $allocation['building_name'],
			'resources' => array_map(static function ($id) use ($resourceNames)
			{
				return ['id' => (int)$id, 'name' => $resourceNames[(int)$id] ?? null];
			}, $allocation['resources']),
			// Surfaced so a client can see for itself that the series was NOT reconstructed
			// from this column. It is the group-mint artifact, not the recurrence key.
			'allocation_group_id' => $allocation['allocation_group_id'] === null
				? null : (int)$allocation['allocation_group_id'],
			'total' => count($occurrences),
			'cancellable' => $cancellable,
			'blocked' => $blocked,
			'no_allocation' => $missing,
			'occurrences' => $occurrences,
			'confirm_token' => $this->confirmToken($allocation, $scope, $effectiveRepeatUntil, $fieldInterval, $occurrences),
		];
	}

	/**
	 * Delete every cancellable occurrence in the scope, and nothing else.
	 *
	 * The preview is re-run here, inside the same request, and the caller's confirm_token must
	 * still match it. Legacy re-computed the list in the deleting loop with no re-check against
	 * what step 2 had displayed; the token closes that window - if a booking has appeared under
	 * an occurrence since the preview, the token no longer matches and the request is refused
	 * rather than silently deleting a different set than the user confirmed.
	 *
	 * The whole scope runs in ONE transaction. Legacy committed per occurrence, inside the
	 * loop, so a failure halfway left the series partly deleted.
	 *
	 * @throws RuntimeException on a stale confirm_token
	 */
	public function cancel(array $allocation, array $options): array
	{
		$preview = $this->preview($allocation, $options);

		$presentedToken = $options['confirm_token'] ?? null;
		if (!is_string($presentedToken) || $presentedToken === '')
		{
			throw new InvalidArgumentException('confirm_token is required; obtain one from cancel-preview');
		}
		if (!hash_equals($preview['confirm_token'], $presentedToken))
		{
			throw new RuntimeException('confirm_token is stale: the series changed since the preview');
		}

		$deleted = [];
		$skipped = [];

		$this->repository->beginTransaction();
		try
		{
			foreach ($preview['occurrences'] as $occurrence)
			{
				if ($occurrence['status'] === self::STATUS_CANCELLABLE)
				{
					$this->repository->deleteAllocation((int)$occurrence['allocation_id']);
					$deleted[] = (int)$occurrence['allocation_id'];
				}
				else
				{
					$skipped[] = [
						'allocation_id' => $occurrence['allocation_id'],
						'from_' => $occurrence['from_'],
						'to_' => $occurrence['to_'],
						'status' => $occurrence['status'],
						'reason' => $occurrence['reason'],
						'blocking_bookings' => $occurrence['blocking_bookings'],
					];
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
			'allocation_id' => (int)$allocation['id'],
			'scope' => $preview['scope'],
			'scope_resolved' => $preview['scope_resolved'],
			'deleted' => $deleted,
			'deleted_count' => count($deleted),
			'skipped' => $skipped,
			'skipped_count' => count($skipped),
		];
	}

	/**
	 * The clicked occurrence, described the same way a walked one is, so scope=occurrence and
	 * the recurring scopes return the same per-occurrence shape.
	 */
	private function describeClickedOccurrence(array $allocation): array
	{
		return $this->describeOccurrence(
			0,
			$this->toDateTime($allocation['from_']),
			$this->toDateTime($allocation['to_']),
			(int)$allocation['id']
		);
	}

	/**
	 * Reconstruct the series by walking forward one interval at a time and matching each slot
	 * back to a stored allocation, which is what legacy's loop does.
	 *
	 * ONE DEPARTURE FROM LEGACY, AND IT CHANGES THE ROW SET ACROSS A DST BOUNDARY.
	 * Legacy computes `$interval = field_interval * 60*60*24*7` and adds that many SECONDS to a
	 * Unix timestamp, then renders it with date() under Europe/Oslo. When the walk crosses the
	 * autumn DST change the rendered wall-clock time moves back by an hour, so the exact
	 * `from_ =` equality in get_allocation_id matches nothing and every occurrence after the
	 * change is silently classed invalid and left un-cancelled. This walk advances in WALL-CLOCK
	 * weeks (DateTimeImmutable::modify('+N weeks')), which keeps 17:00 at 17:00 across the
	 * change and is what both the stored series and the design mean by "every week".
	 *
	 * The i=0 iteration uses the UNSHIFTED from_/to_, so the clicked occurrence is INCLUDED in
	 * the season and until scopes. That is legacy's behaviour and it is preserved.
	 */
	private function walkSeries(array $allocation, DateTimeImmutable $repeatUntil, int $fieldInterval): array
	{
		$from = $this->toDateTime($allocation['from_']);
		$to = $this->toDateTime($allocation['to_']);

		$occurrences = [];
		for ($i = 0; $i < self::MAX_OCCURRENCES; $i++)
		{
			$step = $i * $fieldInterval;
			$occurrenceFrom = $step === 0 ? $from : $from->modify('+' . $step . ' weeks');
			$occurrenceTo = $step === 0 ? $to : $to->modify('+' . $step . ' weeks');

			// Legacy bounds the walk on the occurrence's TO date, not its from date.
			if ($occurrenceTo > $repeatUntil)
			{
				break;
			}

			$matchedId = $this->repository->findOccurrenceAllocationId(
				$occurrenceFrom->format('Y-m-d H:i:s'),
				$occurrenceTo->format('Y-m-d H:i:s'),
				(int)$allocation['organization_id'],
				(int)$allocation['season_id'],
				$allocation['resources']
			);

			$occurrences[] = $this->describeOccurrence($i, $occurrenceFrom, $occurrenceTo, $matchedId);
		}

		return $occurrences;
	}

	/**
	 * One occurrence's verdict.
	 *
	 * Legacy sets `$err = true` both when a booking blocks the slot AND when no allocation
	 * exists for it, so its invalid_dates list cannot tell "something is booked underneath"
	 * from "there is no allocation that week". The design's step 2 needs to name the blocker,
	 * so the two are split here into distinct statuses.
	 */
	private function describeOccurrence(int $index, DateTimeImmutable $from, DateTimeImmutable $to, ?int $allocationId): array
	{
		$occurrence = [
			'index' => $index,
			'allocation_id' => $allocationId,
			'from_' => $from->format('Y-m-d H:i:s'),
			'to_' => $to->format('Y-m-d H:i:s'),
			'blocking_bookings' => [],
		];

		if ($allocationId === null)
		{
			$occurrence['status'] = self::STATUS_NO_ALLOCATION;
			$occurrence['cancellable'] = false;
			$occurrence['reason'] = 'no_allocation_at_this_time';

			return $occurrence;
		}

		$blockers = $this->repository->getBlockingBookings($allocationId);
		if (!empty($blockers))
		{
			$occurrence['status'] = self::STATUS_BLOCKED;
			$occurrence['cancellable'] = false;
			$occurrence['reason'] = 'booking_still_uses_allocation';
			$occurrence['blocking_bookings'] = $blockers;
			// The legacy rule blocks on the existence of ANY bb_booking row, active or not,
			// and that rule is unchanged. This flag reports whether the block is live, so a
			// dead block is visibly distinguishable without the server having ruled on it.
			$occurrence['has_active_blocking_booking'] = $this->anyActive($blockers);

			return $occurrence;
		}

		$occurrence['status'] = self::STATUS_CANCELLABLE;
		$occurrence['cancellable'] = true;
		$occurrence['reason'] = null;

		return $occurrence;
	}

	/**
	 * @param array[] $blockers
	 */
	private function anyActive(array $blockers): bool
	{
		foreach ($blockers as $blocker)
		{
			if ((int)$blocker['active'] !== 0)
			{
				return true;
			}
		}

		return false;
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
	 * Legacy adds a whole day to the chosen end date before comparing, so an occurrence ending
	 * ON the end date is inside the scope. That is preserved: the bound is midnight at the
	 * start of the following day.
	 */
	private function resolveRepeatUntil(string $scope, array $allocation, array $options): DateTimeImmutable
	{
		if ($scope === self::SCOPE_SEASON)
		{
			if (empty($allocation['season_to']))
			{
				throw new InvalidArgumentException('the allocation\'s season has no end date, so scope=season cannot be resolved');
			}

			return $this->toDateTime($allocation['season_to'])->setTime(0, 0, 0)->modify('+1 day');
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
	 * A digest of the exact set the preview reported, so the cancel that follows can prove it
	 * is acting on that set and not on one that has since changed underneath it.
	 *
	 * The occurrence statuses are inside the digest deliberately: if a booking appears under an
	 * occurrence between preview and cancel, the token stops matching and the caller is told,
	 * rather than the server quietly deleting a smaller set than was confirmed.
	 */
	private function confirmToken(
		array $allocation,
		string $scope,
		?string $effectiveRepeatUntil,
		int $fieldInterval,
		array $occurrences
	): string
	{
		$material = [
			'allocation_id' => (int)$allocation['id'],
			'scope' => $scope,
			'repeat_until' => $effectiveRepeatUntil,
			'field_interval' => $fieldInterval,
			'occurrences' => array_map(static function ($occurrence)
			{
				return [
					$occurrence['allocation_id'],
					$occurrence['from_'],
					$occurrence['to_'],
					$occurrence['status'],
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
