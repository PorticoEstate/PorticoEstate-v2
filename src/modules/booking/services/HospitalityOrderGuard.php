<?php

namespace App\modules\booking\services;

/**
 * Decides whether a citizen may ADD, CHANGE or CANCEL a catering (hospitality) order right now.
 *
 * Pure: it takes plain values and an explicit "now" and touches neither the database nor the
 * clock, so the same rule can be proven hermetically (tests/hospitality-deadline) and the client
 * mirror in hospitality-deadline.ts can be held to the same cases.
 *
 * THE RULE (operator rulings 34068 + 34077, GH #1423). Two deadlines, both measured back from
 * the order's serving time S with HospitalityDeadlineCalculator, and both "open ⇔ now ≤ deadline":
 *   C(S) = the ORDER CUTOFF   bb_hospitality.order_by_time_*  (+ open_days)
 *   X(S) = the KITCHEN'S CANCELLATION DEADLINE  bb_resource.cancellation_deadline_*  (+ open_days)
 *
 *   add    open while now ≤ C(S')                        S' = the serving time being ordered
 *   change open while now ≤ C(S) AND now ≤ X(S)          S  = the STORED serving time; if the
 *                                                        serving time moves, also now ≤ C(S')
 *   cancel open while now ≤ X(S)
 *
 * After the cancellation deadline an order is therefore fully locked: no change and no cancel.
 * A deadline that is not configured (value 0 / null) closes nothing.
 *
 * Locks that apply on top of the two deadlines (each is one condition below, individually removable):
 *   - the serving time itself: nothing may be added/changed/cancelled once it has started
 *     (otherwise "no deadline configured" would keep a past order editable for ever);
 *   - the order has been billed (converted to a purchase order) or was paid at checkout, so a
 *     change would never reach the invoice/payment;
 *   - the application is CANCELLED / REJECTED, or the order itself is cancelled / delivered.
 *
 * A draft in the cart (application status NEWPARTIAL1) is not a submitted order: the order
 * cutoff and the serving-time bound apply to adding/changing it exactly as the client always
 * blocked them, but removing a draft is not a "cancellation" and is never refused.
 */
final class HospitalityOrderGuard
{
    public const ACTION_ADD = 'add';
    public const ACTION_CHANGE = 'change';
    public const ACTION_CANCEL = 'cancel';

    public const REASON_ORDER_CUTOFF = 'order_cutoff_passed';
    public const REASON_CANCEL_DEADLINE = 'cancellation_deadline_passed';
    public const REASON_SERVING_PASSED = 'serving_time_passed';
    public const REASON_BILLED = 'order_billed';
    public const REASON_PAID = 'order_paid_at_checkout';
    public const REASON_APPLICATION_CLOSED = 'application_closed';
    public const REASON_ORDER_STATUS = 'order_status';
    public const REASON_NO_BASIS = 'no_deadline_basis';

    public const DRAFT_STATUS = 'NEWPARTIAL1';

    /** Application statuses in which catering may still be handled from Min side. */
    private const OPEN_APPLICATION_STATUSES = [self::DRAFT_STATUS, 'NEW', 'PENDING', 'ACCEPTED'];

    /** Order statuses that can no longer be changed or cancelled by the applicant. */
    private const FINAL_ORDER_STATUSES = ['cancelled', 'delivered'];

    private HospitalityDeadlineCalculator $calculator;

    public function __construct(?HospitalityDeadlineCalculator $calculator = null)
    {
        $this->calculator = $calculator ?? new HospitalityDeadlineCalculator();
    }

    /**
     * @param string $action one of the ACTION_* constants
     * @param array{
     *   application_status: string,
     *   order_status?: ?string,
     *   billed?: bool,
     *   paid_at_checkout?: bool,
     *   anchor: ?\DateTimeInterface,
     *   moved_to?: ?\DateTimeInterface,
     *   order_by_value?: ?int,
     *   order_by_unit?: ?string,
     *   cancel_value?: ?int,
     *   cancel_unit?: ?string,
     *   open_days?: int
     * } $ctx  `anchor` is S (change/cancel: the stored serving time, else the application's earliest
     *         date; add: the serving time being ordered). `moved_to` is S' for a change that moves
     *         the serving time. Both must be given in Europe/Oslo (see computeDeadline).
     * @return array{allowed: bool, reason: ?string, until: ?\DateTimeImmutable}
     *         `until` is the last instant the action is open (the earliest of the deadlines that
     *         apply and the serving time); when refused it is the instant that closed it.
     */
    public function evaluate(string $action, array $ctx, \DateTimeInterface $now): array
    {
        $status = (string)($ctx['application_status'] ?? '');
        $isDraft = $status === self::DRAFT_STATUS;

        // Removing a draft from the cart is not a cancellation: nothing was submitted.
        if ($action === self::ACTION_CANCEL && $isDraft) {
            return self::result(true, null, null);
        }

        if (!in_array($status, self::OPEN_APPLICATION_STATUSES, true)) {
            return self::result(false, self::REASON_APPLICATION_CLOSED, null);
        }

        // Editing a draft in the cart is, for the deadlines, just (re-)ordering for whatever
        // serving time it now has: only the order cutoff of THAT time applies. The kitchen's
        // cancellation deadline never locks a draft — nothing was submitted, and the cart has
        // never refused anything for it.
        if ($isDraft && $action === self::ACTION_CHANGE) {
            $action = self::ACTION_ADD;
            $ctx['anchor'] = $ctx['moved_to'] ?? $ctx['anchor'] ?? null;
            $ctx['moved_to'] = null;
        }

        if ($action !== self::ACTION_ADD) {
            if (in_array((string)($ctx['order_status'] ?? ''), self::FINAL_ORDER_STATUSES, true)) {
                return self::result(false, self::REASON_ORDER_STATUS, null);
            }
            if (!empty($ctx['billed'])) {
                return self::result(false, self::REASON_BILLED, null);
            }
            if (!empty($ctx['paid_at_checkout'])) {
                return self::result(false, self::REASON_PAID, null);
            }
        }

        $anchor = $ctx['anchor'] ?? null;
        if ($anchor === null) {
            // Nothing to measure a deadline from: fail closed.
            return self::result(false, self::REASON_NO_BASIS, null);
        }
        $anchor = \DateTimeImmutable::createFromInterface($anchor);

        $openDays = (int)($ctx['open_days'] ?? HospitalityDeadlineCalculator::ALL_DAYS_OPEN);
        $cutoff = $this->calculator->computeDeadline(
            $anchor,
            self::nullableInt($ctx['order_by_value'] ?? null),
            $ctx['order_by_unit'] ?? null,
            $openDays
        );
        $cancelBy = $this->calculator->computeDeadline(
            $anchor,
            self::nullableInt($ctx['cancel_value'] ?? null),
            $ctx['cancel_unit'] ?? null,
            $openDays
        );

        // Candidate closing instants for THIS action, in the order they win a tie.
        $closers = [];
        switch ($action) {
            case self::ACTION_ADD:
                $closers[] = [$cutoff, self::REASON_ORDER_CUTOFF];
                break;
            case self::ACTION_CHANGE:
                $closers[] = [$cancelBy, self::REASON_CANCEL_DEADLINE];
                $closers[] = [$cutoff, self::REASON_ORDER_CUTOFF];
                break;
            case self::ACTION_CANCEL:
                $closers[] = [$cancelBy, self::REASON_CANCEL_DEADLINE];
                break;
            default:
                throw new \InvalidArgumentException("Unknown action '{$action}'");
        }
        // The serving time is the outer bound of every action.
        $closers[] = [$anchor, self::REASON_SERVING_PASSED];

        $until = null;
        $closedBy = null;      // the EARLIEST closer that has already passed
        $closedAt = null;
        foreach ($closers as [$instant, $reason]) {
            if ($instant === null) {
                continue;
            }
            if ($until === null || $instant < $until) {
                $until = $instant;
            }
            if (!HospitalityDeadlineCalculator::isOpenAt($now, $instant)
                && ($closedAt === null || $instant < $closedAt)) {
                $closedAt = $instant;
                $closedBy = $reason;
            }
        }

        if ($closedBy !== null) {
            return self::result(false, $closedBy, $closedAt);
        }

        // A change that moves the serving time is, for the new slot, a new order: it must still
        // be inside the order cutoff of S' and S' must not already be in the past.
        $movedTo = $ctx['moved_to'] ?? null;
        if ($action === self::ACTION_CHANGE && $movedTo !== null) {
            $movedTo = \DateTimeImmutable::createFromInterface($movedTo);
            $newCutoff = $this->calculator->computeDeadline(
                $movedTo,
                self::nullableInt($ctx['order_by_value'] ?? null),
                $ctx['order_by_unit'] ?? null,
                $openDays
            );
            if (!HospitalityDeadlineCalculator::isOpenAt($now, $newCutoff)) {
                return self::result(false, self::REASON_ORDER_CUTOFF, $newCutoff);
            }
            if (!HospitalityDeadlineCalculator::isOpenAt($now, $movedTo)) {
                return self::result(false, self::REASON_SERVING_PASSED, $movedTo);
            }
        }

        return self::result(true, null, $until);
    }

    private static function nullableInt(mixed $v): ?int
    {
        return ($v === null || $v === '') ? null : (int)$v;
    }

    /**
     * @return array{allowed: bool, reason: ?string, until: ?\DateTimeImmutable}
     */
    private static function result(bool $allowed, ?string $reason, ?\DateTimeInterface $until): array
    {
        return [
            'allowed' => $allowed,
            'reason' => $reason,
            'until' => $until === null ? null : \DateTimeImmutable::createFromInterface($until),
        ];
    }
}
