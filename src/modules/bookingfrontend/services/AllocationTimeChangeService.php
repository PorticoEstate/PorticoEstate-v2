<?php

namespace App\modules\bookingfrontend\services;

use App\Database\Db;
use App\modules\booking\repositories\ApplicationChangeRequestRepository;
use App\modules\booking\services\WebhookNotifier;
use App\modules\bookingfrontend\repositories\AllocationCancellationRepository;
use App\modules\bookingfrontend\repositories\AllocationTimeChangeRepository;
use App\modules\bookingfrontend\helpers\UserHelper;
use App\modules\bookingfrontend\models\User;
use App\modules\bookingfrontend\services\applications\ApplicationCommentsService;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Change the time of ONE allocation occurrence from Min side (GH #1392).
 *
 * A shorter time inside the approved one is applied at once: the allocation
 * gets the new time, its price follows the new hours (see recalculatePrice) and
 * the case officer is told in a comment. Any other time - longer, or moved - is
 * a request on the allocation in bb_application_change_request, which the case
 * officer accepts or rejects ("Godta endring", booking's
 * ApplicationChangeRequestService), exactly as for an event. Either way the
 * application keeps its status, and the officer hears of it through the same
 * comment and staff mail as a withdrawal (AllocationWithdrawalService); the
 * applicant gets no mail.
 *
 * Only the one row changes: never another occurrence of its series.
 */
class AllocationTimeChangeService
{
	private const TIMEZONE = 'Europe/Oslo';

	private Db $db;
	private AllocationTimeChangeRepository $repository;
	private ApplicationChangeRequestRepository $requests;
	private AllocationCancellationRepository $allocations;
	private ApplicationCommentsService $commentsService;

	public function __construct()
	{
		$this->db = Db::getInstance();
		$this->repository = new AllocationTimeChangeRepository();
		$this->requests = new ApplicationChangeRequestRepository();
		$this->allocations = new AllocationCancellationRepository();
		$this->commentsService = new ApplicationCommentsService();
	}

	/**
	 * @param array       $allocation The row from AllocationCancellationRepository::getAllocation(),
	 *                                already judged by the caller's access guard
	 * @param string      $from       The new start, naive local "Y-m-d H:i"
	 * @param string      $to         The new end, naive local "Y-m-d H:i"
	 * @param string      $comment    The applicant's own words to the case officer; may be empty
	 * @param string|null $author     Comment author, resolved the way every comment's is; null
	 *                                means the logged-in user, as ApplicationCommentsService::addComment reads it
	 * @return array{outcome:string, allocation_id:int, application_id:int, from_:string, to_:string}
	 *         plus request_id for a request, price for a shortening
	 * @throws InvalidArgumentException 400: the new time is unusable
	 * @throws RuntimeException 409: the allocation can no longer be changed, or a booking is in the way
	 */
	public function changeTime(array $allocation, string $from, string $to, string $comment, ?string $author): array
	{
		$applicationId = (int) ($allocation['application_id'] ?? 0);
		if ($applicationId <= 0) {
			throw new RuntimeException(lang('bookingfrontend.allocation_change_no_application'), 409);
		}
		// The comment falls back to the logged-in user's name by itself; the cost history
		// and the change request take the author as given, so they get the same name here.
		$author ??= (new User(new UserHelper()))->name;
		if ((int) $allocation['active'] !== 1) {
			throw new RuntimeException(lang('bookingfrontend.allocation_change_inactive'), 409);
		}

		$zone = new DateTimeZone(self::TIMEZONE);
		$oldFrom = new DateTimeImmutable($allocation['from_'], $zone);
		$oldTo = new DateTimeImmutable($allocation['to_'], $zone);
		if ($oldFrom <= new DateTimeImmutable('now', $zone)) {
			throw new RuntimeException(lang('bookingfrontend.allocation_change_started'), 409);
		}

		$newFrom = $this->parseTime($from, $zone);
		$newTo = $this->parseTime($to, $zone);
		// Only the time of day changes, as on an event's edit page: a new date is
		// another occurrence, not this one at a new time.
		if ($newFrom->format('Y-m-d') !== $oldFrom->format('Y-m-d') || $newTo->format('Y-m-d') !== $oldTo->format('Y-m-d')) {
			throw new InvalidArgumentException(lang('bookingfrontend.allocation_change_other_day'));
		}
		if ($newFrom >= $newTo) {
			throw new InvalidArgumentException(lang('bookingfrontend.end_time_must_be_after_start_time'));
		}
		if ($newFrom == $oldFrom && $newTo == $oldTo) {
			throw new InvalidArgumentException(lang('bookingfrontend.allocation_change_unchanged'));
		}

		$times = [
			'old_from' => $oldFrom->format('Y-m-d H:i:s'),
			'old_to' => $oldTo->format('Y-m-d H:i:s'),
			'new_from' => $newFrom->format('Y-m-d H:i:s'),
			'new_to' => $newTo->format('Y-m-d H:i:s'),
		];
		$context = $this->occurrenceContext($allocation, $oldFrom, $oldTo, $newFrom, $newTo);

		$inside = $newFrom >= $oldFrom && $newTo <= $oldTo;

		return $inside
			? $this->shorten($allocation, $times, $context, $comment, $author)
			: $this->request($allocation, $times, $context, $comment, $author);
	}

	/**
	 * Apply a shorter time inside the approved one, with its price, in one transaction.
	 */
	private function shorten(array $allocation, array $times, array $context, string $comment, ?string $author): array
	{
		$allocationId = (int) $allocation['id'];
		$applicationId = (int) $allocation['application_id'];

		$this->db->transaction_begin();
		try {
			$locked = $this->lockUnchanged($allocationId, $times);

			if ($this->repository->findBookingOutside($allocationId, $times['new_from'], $times['new_to']) !== null) {
				throw new RuntimeException(lang('bookingfrontend.allocation_change_booking_in_removed_time'), 409);
			}

			$this->requests->updateAllocationTime($allocationId, $times['new_from'], $times['new_to']);
			$this->requests->updateCompletedReservationTime($allocationId, $times['new_from'], $times['new_to'], 'allocation');

			$price = $this->recalculatePrice($locked, $times, $context['time'], (string) $author);

			// The allocation now has a new approved time, so an open request made
			// from the old one is no longer what the applicant asks for.
			$this->requests->supersedeOpen('allocation', $allocationId);

			$text = lang(
				'bookingfrontend.allocation_shortened_comment',
				$allocationId,
				$context['resource_name'],
				$context['date'],
				$context['old_time'],
				$context['new_time']
			) . ' ' . $price['message'];
			$this->commentsService->addComment($applicationId, $this->withApplicantComment($text, $comment), 'comment', $author, $context, ['type' => 'allocation', 'id' => $allocationId]);

			$this->db->transaction_commit();
		} catch (Throwable $e) {
			$this->db->transaction_abort();
			throw $e;
		}

		$this->afterCommit($allocation);

		return [
			'outcome' => 'shortened',
			'allocation_id' => $allocationId,
			'application_id' => $applicationId,
			'from_' => $times['new_from'],
			'to_' => $times['new_to'],
			'price' => $price,
		];
	}

	/**
	 * Store any other time as a request the case officer decides on. The
	 * allocation keeps its time.
	 */
	private function request(array $allocation, array $times, array $context, string $comment, ?string $author): array
	{
		$allocationId = (int) $allocation['id'];
		$applicationId = (int) $allocation['application_id'];

		$this->db->transaction_begin();
		try {
			$this->lockUnchanged($allocationId, $times);

			$requestId = $this->requests->open(
				$applicationId,
				'allocation',
				$allocationId,
				$times['old_from'],
				$times['old_to'],
				$times['new_from'],
				$times['new_to'],
				$author
			);

			$text = lang(
				'bookingfrontend.allocation_change_requested_comment',
				$allocationId,
				$context['resource_name'],
				$context['date'],
				$context['old_time'],
				$context['new_time']
			);
			$this->commentsService->addComment($applicationId, $this->withApplicantComment($text, $comment), 'comment', $author, $context, ['type' => 'allocation', 'id' => $allocationId]);

			$this->db->transaction_commit();
		} catch (Throwable $e) {
			$this->db->transaction_abort();
			throw $e;
		}

		$this->flushNotifications($allocationId);

		return [
			'outcome' => 'requested',
			'allocation_id' => $allocationId,
			'application_id' => $applicationId,
			'request_id' => $requestId,
			'from_' => $times['new_from'],
			'to_' => $times['new_to'],
		];
	}

	/**
	 * The allocation, locked, still active and still on the time the request was judged on.
	 */
	private function lockUnchanged(int $allocationId, array $times): array
	{
		$locked = $this->requests->lockAllocation($allocationId);
		if (!$locked || (int) $locked['active'] !== 1) {
			throw new RuntimeException(lang('bookingfrontend.allocation_change_inactive'), 409);
		}
		if (strtotime((string) $locked['from_']) !== strtotime($times['old_from'])
			|| strtotime((string) $locked['to_']) !== strtotime($times['old_to'])) {
			throw new RuntimeException(lang('bookingfrontend.allocation_changed_meanwhile'), 409);
		}
		return $locked;
	}

	/**
	 * The price for the new hours, on the allocation's OWN purchase order only.
	 *
	 * Lines priced per hour carry quantity = whole hours begun, max(1, ceil(hours)):
	 * the applicant's client computes it so (article-table.tsx), and measured
	 * 2026-10-14 it holds for 305 of the 312 hour lines on application-backed
	 * allocations - every miss a test fixture. When EVERY hour line of the order
	 * still follows that rule for the old time, each gets the quantity for the
	 * new time, amount = unit_price × quantity and tax = amount × the tax code's
	 * percent, as the order writers compute them; the unit price and the other
	 * lines keep theirs. All or nothing: one hour line off the rule and the order
	 * is not touched. In practice such a line belongs to a time the case officer
	 * changed without recalculating the price: "Godta endring" moves the time and
	 * keeps the lines (ApplicationChangeRequestService), and his allocation form
	 * does not offer the quantity of the line priced per hour.
	 *
	 * The rule, not price_locked, decides: a locked price is recalculated too when
	 * its hour lines still follow the time. price_locked cannot tell a price the
	 * case officer typed from one an earlier shortening locked, and a unit price
	 * the officer typed survives the recalculation anyway, so only a quantity that
	 * no longer follows the time has to stop it.
	 *
	 * Not recalculated - the price stays, and the comment says why - when an hour
	 * line is off the rule, when there is no order or more than one, when the
	 * order is parent-less (the application's own order) and the application is
	 * recurring, or when it is already billed. The occurrences "Opprett alle"
	 * creates later are copied from that order
	 * (sopurchase_order::copy_purchase_order_from_application), so its hour lines
	 * must keep the series' length. The own order of a plain application is
	 * recalculated like any other; an element the case officer creates from that
	 * application afterwards copies its lines as they then are.
	 *
	 * The hour lines follow the new time even when the total does not move (a
	 * line priced 0). A changed total is written with
	 * soallocation::update_price_only - one bb_allocation_cost row, the cost and
	 * the not yet exported invoicing row - and the price is then locked, so a
	 * later group price change from another occurrence does not put the old
	 * hours' price back.
	 *
	 * @param array  $allocation The locked row (lockAllocation)
	 * @param string $timeChange "18:00–20:00 → 18:00–19:00", for the cost history
	 * @return array{status:string, reason:?string, old_cost:float, new_cost:float, message:string}
	 */
	private function recalculatePrice(array $allocation, array $times, string $timeChange, string $author): array
	{
		$allocationId = (int) $allocation['id'];
		$oldCost = round((float) $allocation['cost'], 2);
		$kept = fn(string $status, ?string $reason, string $messageKey) => [
			'status' => $status,
			'reason' => $reason,
			'old_cost' => $oldCost,
			'new_cost' => $oldCost,
			'message' => lang($messageKey),
		];

		$orders = $this->repository->findOwnOrders($allocationId);
		if (count($orders) !== 1) {
			return $kept('not_recalculated', $orders ? 'several_orders' : 'no_order', 'bookingfrontend.allocation_price_not_recalculated');
		}
		$orderId = $orders[0]['id'];
		if ($orders[0]['parent_id'] === null && $this->repository->isRecurringApplication((int) $allocation['application_id'])) {
			return $kept('not_recalculated', 'master_order', 'bookingfrontend.allocation_price_master_order');
		}
		if ($this->repository->isBilled($orderId, $allocationId)) {
			return $kept('not_recalculated', 'billed', 'bookingfrontend.allocation_price_not_recalculated');
		}

		$oldQuantity = $this->hourQuantity($times['old_from'], $times['old_to']);
		$newQuantity = $this->hourQuantity($times['new_from'], $times['new_to']);

		$hourLines = array_filter($this->repository->fetchOrderLines($orderId), fn(array $line) => $line['unit'] === 'hour');
		foreach ($hourLines as $line) {
			if (abs((float) $line['quantity'] - $oldQuantity) >= 0.005) {
				return $kept('not_recalculated', 'off_rule', 'bookingfrontend.allocation_price_off_rule');
			}
		}
		if (!$hourLines || $newQuantity === $oldQuantity) {
			return $kept('unchanged', null, 'bookingfrontend.allocation_price_unchanged');
		}

		$sopurchaseOrder = \CreateObject('booking.sopurchase_order');
		$oldSum = round((float) ($sopurchaseOrder->get_single_purchase_order($orderId)['sum'] ?? 0), 2);

		foreach ($hourLines as $line) {
			$amount = round((float) $line['unit_price'] * $newQuantity, 2);
			$tax = round($amount * $line['percent'] / 100, 2);
			$this->repository->updateOrderLine($line['id'], $newQuantity, $amount, $tax);
		}

		// TAX-INCLUSIVE, as get_single_purchase_order sums amount + tax per line -
		// the figure bb_allocation.cost holds.
		$newSum = round((float) ($sopurchaseOrder->get_single_purchase_order($orderId)['sum'] ?? 0), 2);
		if ($newSum === $oldSum) {
			return $kept('unchanged', null, 'bookingfrontend.allocation_price_unchanged');
		}

		\CreateObject('booking.soallocation')->update_price_only(
			$allocationId,
			$newSum,
			$author,
			lang('bookingfrontend.allocation_cost_recalculated_after_shortening', $timeChange)
		);
		$this->repository->lockPrice($allocationId);

		return [
			'status' => 'recalculated',
			'reason' => null,
			'old_cost' => $oldCost,
			'new_cost' => $newSum,
			'message' => lang('bookingfrontend.allocation_price_recalculated', $this->formatMoney($oldCost), $this->formatMoney($newSum)),
		];
	}

	/**
	 * Whole hours begun, at least one: the quantity of a line priced per hour.
	 */
	private function hourQuantity(string $from, string $to): int
	{
		return max(1, (int) ceil((strtotime($to) - strtotime($from)) / 3600));
	}

	/**
	 * A naive local "Y-m-d H:i" (seconds, when given, must be :00).
	 */
	private function parseTime(string $value, DateTimeZone $zone): DateTimeImmutable
	{
		$value = trim($value);
		if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:00)?$/', $value)) {
			$time = DateTimeImmutable::createFromFormat('!Y-m-d H:i', substr($value, 0, 16), $zone);
			if ($time && $time->format('Y-m-d H:i') === substr($value, 0, 16)) {
				return $time;
			}
		}
		throw new InvalidArgumentException(lang('bookingfrontend.allocation_change_invalid_time'));
	}

	/**
	 * What the comment and the staff mail name the occurrence by
	 * (admin_comment_notification.twig shows resource, date and time).
	 */
	private function occurrenceContext(array $allocation, DateTimeImmutable $oldFrom, DateTimeImmutable $oldTo, DateTimeImmutable $newFrom, DateTimeImmutable $newTo): array
	{
		$resourceNames = array_values(array_filter($this->allocations->getResourceNames($allocation['resources'] ?? [])));
		$oldTime = $oldFrom->format('H:i') . '–' . $oldTo->format('H:i');
		$newTime = $newFrom->format('H:i') . '–' . $newTo->format('H:i');

		return [
			'resource_name' => $resourceNames ? implode(', ', $resourceNames) : (string) ($allocation['building_name'] ?? ''),
			'date' => $oldFrom->format('d.m.Y'),
			'time' => $oldTime . ' → ' . $newTime,
			'old_time' => $oldTime,
			'new_time' => $newTime,
		];
	}

	/**
	 * The system sentence first, then the applicant's own words untouched.
	 */
	private function withApplicantComment(string $text, string $comment): string
	{
		return $comment !== '' ? $text . "\n\n" . $comment : $text;
	}

	private function formatMoney(float $amount): string
	{
		return number_format($amount, 2, ',', ' ') . ' kr';
	}

	/**
	 * The officer mail and the calendar subscribers hear of the change only once
	 * it is committed; neither may turn a stored change into an error.
	 */
	private function afterCommit(array $allocation): void
	{
		$this->flushNotifications((int) $allocation['id']);

		try {
			(new WebhookNotifier())->notifyChange('allocation', 'updated', (int) $allocation['id'], array_map('intval', $allocation['resources'] ?? []));
		} catch (Throwable $e) {
			error_log('AllocationTimeChangeService: webhook notification failed for allocation ' . (int) $allocation['id'] . ': ' . $e->getMessage());
		}
	}

	private function flushNotifications(int $allocationId): void
	{
		try {
			$this->commentsService->flushPendingNotifications();
		} catch (Throwable $e) {
			error_log('AllocationTimeChangeService: post-commit notification flush failed for allocation ' . $allocationId . ': ' . $e->getMessage());
		}
	}
}
