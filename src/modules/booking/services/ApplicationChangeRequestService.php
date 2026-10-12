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
 * for a time outside the one approved; the request is stored on the element
 * and the application keeps its status. "Godta endring" moves the SAME event to
 * the requested time - nothing is deleted or recreated - and keeps its date row
 * and the application's date row on that time; "Avslå endring" keeps the old
 * time. Neither writes a comment or sends mail. Only event requests can be
 * accepted; element_type admits allocations for later.
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

		$eventIds = [];
		foreach ($rows as $row) {
			if ($row['element_type'] === 'event') {
				$eventIds[] = (int) $row['element_id'];
			}
		}
		$events = $this->requests->fetchEvents($eventIds);
		$hourly = array_flip($this->requests->findEventsWithHourlyPrice($eventIds));

		$result = [];
		foreach ($rows as $row) {
			$elementId = (int) $row['element_id'];
			$event = $row['element_type'] === 'event' ? ($events[$elementId] ?? null) : null;
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
				'element_active' => $event !== null && (int) $event['active'] === 1,
				'stale'          => $event === null || !$this->holdsTime($event, $row['old_from_'], $row['old_to_']),
				'price_review'   => isset($hourly[$elementId]),
			];
		}
		return $result;
	}

	/**
	 * Move the event to the requested time, in one transaction.
	 *
	 * Refused - nothing written, the request stays open - when the event is not
	 * active, when its time changed after the request, when the legacy event
	 * validation (soevent::doValidate: other events, allocations and bookings on
	 * the event's resources) fails for the new time, or when the new time
	 * collides with anything on the application's resources other than the
	 * elements of the event's own slot (the #1438 rule the date rows show).
	 *
	 * @return array{status:string, event_id:int, from_:string, to_:string, application_date_id:?int, price_review:bool}
	 * @throws RuntimeException 404 unknown request, 409 refused, 422 not an event
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
			if ($request['element_type'] !== 'event') {
				throw new RuntimeException('Only a change of an event can be accepted', 422);
			}

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
			$slot = $this->findSlot($applicationId, $eventId);

			$legacyEvent = \CreateObject('booking.boevent')->read_single($eventId);
			$validation = $this->legacyValidation($legacyEvent, $newFrom, $newTo);
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
			if ($slot !== null) {
				$this->requests->updateApplicationDate($slot['date_id'], $applicationId, $newFrom, $newTo);
				$this->updateApplicationFrom($applicationId);
			}
			$this->requests->updateCompletedReservationTime($eventId, $newFrom, $newTo);

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
		$this->notifyWebhook($eventId, $legacyEvent['resources'] ?? []);

		return [
			'status'              => 'ok',
			'event_id'            => $eventId,
			'from_'               => $newFrom,
			'to_'                 => $newTo,
			'application_date_id' => $slot['date_id'] ?? null,
			'price_review'        => in_array($eventId, $this->requests->findEventsWithHourlyPrice([$eventId]), true),
		];
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
	 * The application date row the event is placed on, with every element on
	 * it, or null when the event sits on none (ApplicationService::placeDateElements).
	 *
	 * @return array{date_id:int, elements:array}|null
	 */
	private function findSlot(int $applicationId, int $eventId): ?array
	{
		$placed = $this->applicationService->placeDateElements(
			$applicationId,
			$this->repo->fetchDates($applicationId),
			$this->repo->fetchResourceIds($applicationId)
		);
		foreach ($placed as $dateId => $row) {
			foreach ($row['associations'] as $element) {
				if ($element['type'] === 'event' && (int) $element['id'] === $eventId) {
					return ['date_id' => (int) $dateId, 'elements' => $row['associations']];
				}
			}
		}
		return null;
	}

	/**
	 * The legacy validation errors of the event at the new time, as boevent
	 * returns them ({field: [message]}; allocation and booking overlaps as
	 * [[ids]]). The event's own id is excluded from its overlap check.
	 */
	private function legacyValidation(array $event, string $from, string $to): array
	{
		$event['from_'] = $from;
		$event['to_'] = $to;
		return \CreateObject('booking.boevent')->validate($event);
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
		foreach (['allocation', 'booking'] as $type) {
			foreach ($validation[$type] ?? [] as $ids) {
				foreach ((array) $ids as $id) {
					$labels[$type . ':' . (int) $id] ??= $type . ' #' . (int) $id;
				}
			}
		}
		unset($validation['allocation'], $validation['booking']);

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

	private function notifyWebhook(int $eventId, array $resourceIds): void
	{
		try {
			(new WebhookNotifier())->notifyChange('event', 'updated', $eventId, array_map('intval', $resourceIds));
		} catch (\Throwable $e) {
			error_log("Webhook notification failed after the change of event {$eventId}: " . $e->getMessage());
		}
	}
}
