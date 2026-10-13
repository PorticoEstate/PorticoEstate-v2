<?php

namespace App\modules\booking\services;

use App\Database\Db;
use App\modules\booking\repositories\ApplicationChangeRequestRepository;
use App\modules\booking\repositories\ApplicationRepository;
use RuntimeException;

/**
 * The case officer's decision on an applicant's request for a new time on an
 * element of an application (bb_application_change_request).
 *
 * The applicant asks from the event's edit page (bookingfrontend.uievent.edit)
 * or from Min side's change of an allocation's time
 * (bookingfrontend AllocationTimeChangeService) for a time outside the one
 * approved; the request is stored on the element and the application keeps its
 * status. "Godta endring" moves the SAME element to the requested time - nothing
 * is deleted or recreated. An event keeps its date row and, when it is alone on
 * it, the application's date row on that time; an allocation never moves the
 * application's date row, which for a series is the time of every occurrence.
 * "Avslå endring" keeps the old time.
 * Neither writes a comment or sends mail, and neither recalculates a price.
 */
class ApplicationChangeRequestService
{
	private ApplicationRepository $repo;
	private ApplicationService $applicationService;
	private ApplicationChangeRequestRepository $requests;

	public function __construct(ApplicationRepository $repo, ApplicationService $applicationService)
	{
		$this->repo = $repo;
		$this->applicationService = $applicationService;
		$this->requests = new ApplicationChangeRequestRepository();
	}

	/**
	 * The open requests of the application and of the applications combined
	 * with it, with what the case officer needs to decide: whether the element
	 * is active, whether its time changed after the request (stale - it can then
	 * only be rejected) and whether it is priced per hour.
	 */
	public function listOpen(int $appId): array
	{
		$groupIds = $this->repo->getRelatedApplications($appId)['application_ids'];
		$rows = $this->requests->findOpenForApplications($groupIds);

		$ids = ['event' => [], 'allocation' => []];
		foreach ($rows as $row) {
			if (isset($ids[$row['element_type']])) {
				$ids[$row['element_type']][] = (int) $row['element_id'];
			}
		}
		$elements = [
			'event'      => $this->requests->fetchEvents($ids['event']),
			'allocation' => $this->requests->fetchAllocations($ids['allocation']),
		];
		$hourly = [
			'event'      => array_flip($this->requests->findEventsWithHourlyPrice($ids['event'])),
			'allocation' => array_flip($this->requests->findAllocationsWithHourlyPrice($ids['allocation'])),
		];

		$result = [];
		foreach ($rows as $row) {
			$elementId = (int) $row['element_id'];
			$element = $elements[$row['element_type']][$elementId] ?? null;
			$result[] = [
				'id'             => (int) $row['id'],
				'application_id' => (int) $row['application_id'],
				'element_type'   => $row['element_type'],
				'element_id'     => $elementId,
				'old_from_'      => $row['old_from_'],
				'old_to_'        => $row['old_to_'],
				'new_from_'      => $row['new_from_'],
				'new_to_'        => $row['new_to_'],
				'requested_by'   => $row['requested_by'],
				'created'        => $row['created'],
				'element_active' => $element !== null && (int) $element['active'] === 1,
				'stale'          => $element === null || !$this->holdsTime($element, $row['old_from_'], $row['old_to_']),
				'price_review'   => isset($hourly[$row['element_type']][$elementId]),
			];
		}
		return $result;
	}

	/**
	 * Move the element to the requested time, in one transaction.
	 *
	 * Refused - nothing written, the request stays open - when the element is not
	 * active, when its time changed after the request, when the legacy
	 * validation (soevent::doValidate or soallocation::doValidate: other events,
	 * allocations and bookings on the element's resources) fails for the new
	 * time, or when the new time collides with anything on the application's
	 * resources other than the elements of the element's own slot (the #1438
	 * rule the date rows show).
	 *
	 * @return array{status:string, from_:string, to_:string, application_date_id:?int, application_date_shared:bool, price_review:bool}
	 *         plus event_id or allocation_id
	 * @throws RuntimeException 404 unknown request, 409 refused, 422 neither an event nor an allocation
	 */
	public function accept(int $appId, int $requestId, int $accountId): array
	{
		$groupIds = $this->repo->getRelatedApplications($appId)['application_ids'];

		$db = Db::getInstance();
		if ($db->get_transaction()) {
			throw new RuntimeException('Cannot accept the change inside an open transaction', 500);
		}
		$db->transaction_begin();
		try {
			$request = $this->lockOpenRequest($requestId, $groupIds);
			if ($request['element_type'] === 'event') {
				[$result, $resourceIds] = $this->applyEventChange($request);
			} elseif ($request['element_type'] === 'allocation') {
				[$result, $resourceIds] = $this->applyAllocationChange($request);
			} else {
				throw new RuntimeException('Only a change of an event or an allocation can be accepted', 422);
			}

			if (!$this->requests->resolve($requestId, ApplicationChangeRequestRepository::ACCEPTED, $accountId)) {
				throw new RuntimeException(lang('booking.change_request_not_open'), 409);
			}

			$db->transaction_commit();
		} catch (\Throwable $e) {
			$db->transaction_abort();
			throw $e;
		}

		// After the commit, like boevent::update(): a calendar subscriber must not
		// hear of a change that was rolled back.
		$this->notifyWebhook($request['element_type'], (int) $request['element_id'], $resourceIds);

		return $result;
	}

	/**
	 * The event's part of accept(). The application's date row moves with the
	 * event when the event is the only active element on it; a row it shares
	 * keeps its time (application_date_shared), and an event on no row moves alone.
	 *
	 * @return array{0: array, 1: array} the response and the event's resource ids
	 */
	private function applyEventChange(array $request): array
	{
		$eventId = (int) $request['element_id'];
		$event = $this->requests->lockEvent($eventId);
		if (!$event || (int) $event['active'] !== 1) {
			throw new RuntimeException(lang('booking.change_request_element_inactive'), 409);
		}
		if (!$this->holdsTime($event, $request['old_from_'], $request['old_to_'])) {
			throw new RuntimeException(lang('booking.change_request_stale'), 409);
		}

		$newFrom = (string) $request['new_from_'];
		$newTo = (string) $request['new_to_'];
		$applicationId = (int) $event['application_id'];

		// The date row the event sits on, found before the event moves: the
		// placement matches an element to its row by the element's time.
		$slot = $this->findSlot($applicationId, 'event', $eventId);

		$legacyEvent = \CreateObject('booking.boevent')->read_single($eventId);
		$validation = $this->legacyValidation('booking.boevent', $legacyEvent, $newFrom, $newTo);
		$foreign = $this->applicationService->windowForeignCollisions(
			$this->repo->fetchResourceIds($applicationId),
			$newFrom,
			$newTo,
			$slot['elements'] ?? [['type' => 'event', 'id' => $eventId]]
		);
		if ($validation || $foreign) {
			throw new RuntimeException($this->refusalText($validation, $foreign), 409);
		}

		$movedDateRows = $this->requests->moveEventDate($eventId, (string) $event['from_'], (string) $event['to_'], $newFrom, $newTo);
		if ($movedDateRows === null) {
			throw new RuntimeException(lang('booking.change_request_dates_ambiguous'), 409);
		}
		$this->requests->updateEventTime($eventId, $newFrom, $newTo);
		// A date row holding other active elements stays on their time: the
		// event leaves it and is placed apart, like any moved element.
		$moveDate = $slot !== null && !$this->holdsOtherActiveElement($slot['elements'], 'event', $eventId);
		if ($moveDate) {
			$this->requests->updateApplicationDate($slot['date_id'], $applicationId, $newFrom, $newTo);
			$this->updateApplicationFrom($applicationId);
		}
		$this->requests->updateCompletedReservationTime($eventId, $newFrom, $newTo);

		return [[
			'status'                  => 'ok',
			'event_id'                => $eventId,
			'from_'                   => $newFrom,
			'to_'                     => $newTo,
			'application_date_id'     => $moveDate ? $slot['date_id'] : null,
			'application_date_shared' => $slot !== null && !$moveDate,
			'price_review'            => in_array($eventId, $this->requests->findEventsWithHourlyPrice([$eventId]), true),
		], $legacyEvent['resources'] ?? []];
	}

	/**
	 * The allocation's part of accept(). Only the allocation and its not yet
	 * exported invoicing row move: the application's date row keeps its time,
	 * because for a recurring application it is the time of every occurrence,
	 * and the price is not recalculated (price_review).
	 *
	 * The bookings made inside the allocation are its own, never a collision.
	 *
	 * @return array{0: array, 1: array} the response and the allocation's resource ids
	 */
	private function applyAllocationChange(array $request): array
	{
		$allocationId = (int) $request['element_id'];
		$allocation = $this->requests->lockAllocation($allocationId);
		if (!$allocation || (int) $allocation['active'] !== 1) {
			throw new RuntimeException(lang('booking.change_request_element_inactive_allocation'), 409);
		}
		if (!$this->holdsTime($allocation, $request['old_from_'], $request['old_to_'])) {
			throw new RuntimeException(lang('booking.change_request_stale_allocation'), 409);
		}

		$newFrom = (string) $request['new_from_'];
		$newTo = (string) $request['new_to_'];
		$applicationId = (int) $allocation['application_id'];

		$slot = $this->findSlot($applicationId, 'allocation', $allocationId);
		$own = $slot['elements'] ?? [['type' => 'allocation', 'id' => $allocationId]];
		foreach ($this->requests->findAllocationBookingIds($allocationId) as $bookingId) {
			$own[] = ['type' => 'booking', 'id' => $bookingId];
		}

		$legacyAllocation = \CreateObject('booking.boallocation')->read_single($allocationId);
		$validation = $this->legacyValidation('booking.boallocation', $legacyAllocation, $newFrom, $newTo);
		$foreign = $this->applicationService->windowForeignCollisions(
			$this->repo->fetchResourceIds($applicationId),
			$newFrom,
			$newTo,
			$own
		);
		if ($validation || $foreign) {
			throw new RuntimeException($this->refusalText($validation, $foreign), 409);
		}

		$this->requests->updateAllocationTime($allocationId, $newFrom, $newTo);
		$this->requests->updateCompletedReservationTime($allocationId, $newFrom, $newTo, 'allocation');

		return [[
			'status'                  => 'ok',
			'allocation_id'           => $allocationId,
			'from_'                   => $newFrom,
			'to_'                     => $newTo,
			'application_date_id'     => null,
			'application_date_shared' => false,
			'price_review'            => in_array($allocationId, $this->requests->findAllocationsWithHourlyPrice([$allocationId]), true),
		], $legacyAllocation['resources'] ?? []];
	}

	/**
	 * Close the request and keep the element's time.
	 *
	 * @throws RuntimeException 404 unknown request, 409 already handled
	 */
	public function reject(int $appId, int $requestId, int $accountId): void
	{
		$groupIds = $this->repo->getRelatedApplications($appId)['application_ids'];

		$db = Db::getInstance();
		if ($db->get_transaction()) {
			throw new RuntimeException('Cannot reject the change inside an open transaction', 500);
		}
		$db->transaction_begin();
		try {
			$this->lockOpenRequest($requestId, $groupIds);
			if (!$this->requests->resolve($requestId, ApplicationChangeRequestRepository::REJECTED, $accountId)) {
				throw new RuntimeException(lang('booking.change_request_not_open'), 409);
			}
			$db->transaction_commit();
		} catch (\Throwable $e) {
			$db->transaction_abort();
			throw $e;
		}
	}

	/**
	 * The request, locked, when it belongs to the application group {id} is
	 * opened on and is still open.
	 *
	 * @param int[] $groupIds
	 */
	private function lockOpenRequest(int $requestId, array $groupIds): array
	{
		$request = $this->requests->lockById($requestId);
		if (!$request || !in_array((int) $request['application_id'], $groupIds, true)) {
			throw new RuntimeException('Change request not found', 404);
		}
		if ($request['status'] !== ApplicationChangeRequestRepository::OPEN) {
			throw new RuntimeException(lang('booking.change_request_not_open'), 409);
		}
		return $request;
	}

	private function holdsTime(array $element, string $from, string $to): bool
	{
		return strtotime((string) $element['from_']) === strtotime($from)
			&& strtotime((string) $element['to_']) === strtotime($to);
	}

	/**
	 * The application date row the element is placed on, with every element on
	 * it, or null when the element sits on none (ApplicationService::placeDateElements).
	 *
	 * @return array{date_id:int, elements:array}|null
	 */
	private function findSlot(int $applicationId, string $type, int $id): ?array
	{
		$placed = $this->applicationService->placeDateElements(
			$applicationId,
			$this->repo->fetchDates($applicationId),
			$this->repo->fetchResourceIds($applicationId)
		);
		foreach ($placed as $dateId => $row) {
			foreach ($row['associations'] as $element) {
				if ($element['type'] === $type && (int) $element['id'] === $id) {
					return ['date_id' => (int) $dateId, 'elements' => $row['associations']];
				}
			}
		}
		return null;
	}

	/**
	 * Whether a date row's elements (findSlot) hold an active element other than
	 * the given one. An inactive one does not keep the row on its time.
	 */
	private function holdsOtherActiveElement(array $elements, string $type, int $id): bool
	{
		foreach ($elements as $element) {
			$isSelf = $element['type'] === $type && (int) $element['id'] === $id;
			if (!$isSelf && (int) $element['active'] === 1) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The legacy validation errors of the element at the new time ({field:
	 * [message]}). boevent reports allocation and booking overlaps as [[ids]],
	 * boallocation as [message]. The element's own id is excluded from its
	 * overlap check.
	 */
	private function legacyValidation(string $boClass, array $element, string $from, string $to): array
	{
		$element['from_'] = $from;
		$element['to_'] = $to;
		return \CreateObject($boClass)->validate($element);
	}

	/**
	 * One sentence naming what the new time collides with, followed by any
	 * other validation message.
	 */
	private function refusalText(array $validation, array $foreign): string
	{
		$labels = [];
		foreach ($foreign as $collision) {
			$name = (string) ($collision['name'] ?? '');
			$labels[$collision['type'] . ':' . $collision['id']] = $collision['type'] . ($name !== '' ? ' (' . $name . ')' : ' #' . $collision['id']);
		}
		$overlapMessages = [];
		foreach (['allocation', 'booking'] as $type) {
			foreach ($validation[$type] ?? [] as $ids) {
				foreach ((array) $ids as $id) {
					if (!is_numeric($id)) {
						// boallocation's overlap message, which already names the element
						$overlapMessages[] = (string) $id;
						continue;
					}
					$labels[$type . ':' . (int) $id] ??= $type . ' #' . (int) $id;
				}
			}
		}
		unset($validation['allocation'], $validation['booking']);
		if ($overlapMessages) {
			$validation['overlap'] = $overlapMessages;
		}

		// The event overlap message names one event; the collision list already
		// names every event it found.
		foreach (array_keys($labels) as $ref) {
			if (str_starts_with($ref, 'event:')) {
				unset($validation['event']);
				break;
			}
		}

		$messages = [];
		if ($labels) {
			$messages[] = lang('booking.change_conflicts_with') . ': ' . implode(', ', $labels) . '.';
		}
		foreach ($validation as $fieldMessages) {
			foreach ((array) $fieldMessages as $message) {
				if (is_string($message) && $message !== '') {
					$messages[] = $message;
				}
			}
		}
		return implode(' ', $messages);
	}

	/**
	 * bb_application.from_ is the earliest date of the application, for a
	 * combined application of the whole cart (soapplication::update_from_field).
	 */
	private function updateApplicationFrom(int $applicationId): void
	{
		$soapplication = \CreateObject('booking.soapplication');
		$soapplication->update_from_field($applicationId);

		$application = $this->repo->getById($applicationId);
		$parentId = (int) ($application['parent_id'] ?? 0);
		if ($parentId && $parentId !== $applicationId) {
			$soapplication->update_from_field($parentId);
		}
	}

	private function notifyWebhook(string $type, int $id, array $resourceIds): void
	{
		try {
			(new WebhookNotifier())->notifyChange($type, 'updated', $id, array_map('intval', $resourceIds));
		} catch (\Throwable $e) {
			error_log("Webhook notification failed after the change of {$type} {$id}: " . $e->getMessage());
		}
	}
}
