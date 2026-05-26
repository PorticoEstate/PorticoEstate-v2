<?php

namespace App\modules\booking\services;

use App\modules\booking\models\Allocation;
use App\modules\booking\repositories\ApplicationRepository;
use App\modules\booking\services\EmailService;
use RuntimeException;

/**
 * Business logic for application case-officer workflows.
 *
 * Handles assign / unassign / dashboard-toggle including
 * propagation to combined (related) applications when the
 * `combined_applications_mode` config is enabled.
 */
class ApplicationService
{
	private ApplicationRepository $repo;
	private bool $combineApplications;

	public function __construct(ApplicationRepository $repo)
	{
		$this->repo = $repo;

		$config = $this->repo->fetchBookingConfig();
		$this->combineApplications = !empty($config['combined_applications_mode']);
	}

	// ── Assign ──────────────────────────────────────────────────────────

	/**
	 * Assign the current user as case officer on an application
	 * (and on every related combined application when enabled).
	 *
	 * @throws RuntimeException if the application does not exist.
	 */
	public function assignCurrentUser(int $appId, int $accountId): void
	{
		$row = $this->repo->getById($appId);
		if (!$row) {
			throw new RuntimeException('Application not found', 404);
		}

		$authorName = $this->repo->fetchAccountName($accountId) ?? 'Unknown';
		$commentText = "User '{$authorName}' was assigned";

		// Assign + status transition on the primary application
		$this->repo->assignCaseOfficer($appId, $accountId);
		if (($row['status'] ?? '') === 'NEW') {
			$this->repo->updateStatus($appId, 'PENDING');
		}
		$this->repo->addComment($appId, $authorName, $commentText, 'ownership');

		// Propagate to related applications when combining is enabled
		if ($this->combineApplications) {
			$relatedInfo = $this->repo->getRelatedApplications($appId);
			if ($relatedInfo['total_count'] > 1) {
				$relatedIds = array_filter(
					$relatedInfo['application_ids'],
					fn(int $id) => $id !== $appId
				);

				$relatedRows = $this->repo->getByIds($relatedIds);

				foreach ($relatedIds as $relatedId) {
					$relatedRow = $relatedRows[$relatedId] ?? null;
					if (!$relatedRow) {
						continue;
					}

					// Only assign if not already assigned to this user
					if (($relatedRow['case_officer_id'] ?? null) == $accountId) {
						continue;
					}

					$this->repo->assignCaseOfficer($relatedId, $accountId);

					if (($relatedRow['status'] ?? '') === 'NEW') {
						$this->repo->updateStatus($relatedId, 'PENDING');
					}

					$this->repo->addComment($relatedId, $authorName, $commentText, 'ownership');
				}
			}
		}
	}

	// ── Unassign ────────────────────────────────────────────────────────

	/**
	 * Unassign the current user from an application
	 * (and from every related combined application where they are CO).
	 *
	 * @throws RuntimeException if the application does not exist or the user is not CO.
	 */
	public function unassignCurrentUser(int $appId, int $accountId): void
	{
		$row = $this->repo->getById($appId);
		if (!$row) {
			throw new RuntimeException('Application not found', 404);
		}

		if ((int) ($row['case_officer_id'] ?? 0) !== $accountId) {
			throw new RuntimeException('You are not the case officer', 403);
		}

		$authorName = $this->repo->fetchAccountName($accountId) ?? 'Unknown';
		$commentText = "User '{$authorName}' was unassigned";

		// Unassign primary
		$this->repo->unassignCaseOfficer($appId);
		$this->repo->addComment($appId, $authorName, $commentText, 'ownership');

		// Reset dashboard visibility (legacy behavior)
		$this->repo->updateDisplayInDashboard($appId, 1);

		// Propagate to related applications when combining is enabled
		if ($this->combineApplications) {
			$relatedInfo = $this->repo->getRelatedApplications($appId);
			if ($relatedInfo['total_count'] > 1) {
				$relatedIds = array_filter(
					$relatedInfo['application_ids'],
					fn(int $id) => $id !== $appId
				);

				$relatedRows = $this->repo->getByIds($relatedIds);

				foreach ($relatedIds as $relatedId) {
					$relatedRow = $relatedRows[$relatedId] ?? null;
					if (!$relatedRow) {
						continue;
					}

					// Only unassign if this user IS the case officer on that related app
					if ((int) ($relatedRow['case_officer_id'] ?? 0) !== $accountId) {
						continue;
					}

					$this->repo->unassignCaseOfficer($relatedId);
					$this->repo->addComment($relatedId, $authorName, $commentText, 'ownership');
				}
			}
		}
	}

	// ── Toggle dashboard ────────────────────────────────────────────────

	/**
	 * Toggle the display_in_dashboard flag for an application.
	 * Only the assigned case officer may toggle.
	 *
	 * @return int The new display_in_dashboard value (0 or 1).
	 * @throws RuntimeException if the application does not exist or the user is not CO.
	 */
	public function toggleDashboard(int $appId, int $accountId): int
	{
		$row = $this->repo->getById($appId);
		if (!$row) {
			throw new RuntimeException('Application not found', 404);
		}

		if ((int) ($row['case_officer_id'] ?? 0) !== $accountId) {
			throw new RuntimeException('You are not the case officer', 403);
		}

		$current = (int) ($row['display_in_dashboard'] ?? 1);
		$newValue = $current === 1 ? 0 : 1;
		$this->repo->updateDisplayInDashboard($appId, $newValue);

		return $newValue;
	}

	// ── Add comment (reply to applicant) ───────────────────────────────

	/**
	 * Add a comment to the application and send email notification.
	 */
	public function addComment(int $appId, int $accountId, string $comment): void
	{
		$row = $this->repo->getById($appId);
		if (!$row) {
			throw new RuntimeException('Application not found', 404);
		}

		$authorName = $this->repo->fetchAccountName($accountId) ?? 'Unknown';
		$this->repo->addComment($appId, $authorName, $comment, 'comment');

		// Send email notification (non-fatal on failure)
		$this->sendNotificationSafe($appId);
	}

	// ── Add internal note ──────────────────────────────────────────────

	/**
	 * Add an internal (admin-only) note to the application.
	 */
	public function addInternalNote(int $appId, int $accountId, string $content): void
	{
		$row = $this->repo->getById($appId);
		if (!$row) {
			throw new RuntimeException('Application not found', 404);
		}

		$this->repo->addInternalNote($appId, $accountId, $content);
	}

	// ── Accept application ─────────────────────────────────────────────

	/**
	 * Accept an application: status change, activate associations, handle combined apps.
	 *
	 * @return array{status: string, accepted_ids: int[], rejected_ids: int[]}
	 */
	public function acceptApplication(int $appId, int $accountId, string $message, bool $sendEmail): array
	{
		$row = $this->repo->getById($appId);
		if (!$row) {
			throw new RuntimeException('Application not found', 404);
		}

		$status = $row['status'] ?? '';
		if (!in_array($status, ['PENDING', 'REJECTED', 'NEWPARTIAL1'])) {
			throw new RuntimeException('Application cannot be accepted in current status: ' . $status, 400);
		}

		$numAssoc = $this->repo->countAssociations($appId);
		if ($numAssoc === 0) {
			throw new RuntimeException('Cannot accept: no associations (allocations/bookings/events) exist', 400);
		}

		$authorName = $this->repo->fetchAccountName($accountId) ?? 'Unknown';

		// Add optional message as comment
		if (!empty($message)) {
			$this->repo->addComment($appId, $authorName, $message, 'comment');
		}

		// Accept primary application
		$this->repo->updateStatus($appId, 'ACCEPTED');
		$this->repo->activateAssociations($appId);

		$acceptedIds = [$appId];
		$rejectedIds = [];

		// Handle combined applications
		if ($this->combineApplications) {
			$relatedInfo = $this->repo->getRelatedApplications($appId);
			if ($relatedInfo['total_count'] > 1) {
				$relatedIds = array_filter(
					$relatedInfo['application_ids'],
					fn(int $id) => $id !== $appId
				);

				foreach ($relatedIds as $relatedId) {
					$relatedAssocCount = $this->repo->countAssociations($relatedId);
					if ($relatedAssocCount > 0) {
						// Accept related apps that have associations
						$this->repo->updateStatus($relatedId, 'ACCEPTED');
						$this->repo->activateAssociations($relatedId);
						$acceptedIds[] = $relatedId;
					} else {
						// Auto-reject related apps without associations
						$this->repo->updateStatus($relatedId, 'REJECTED');
						$this->repo->addComment(
							$relatedId,
							$authorName,
							'Automatisk avslått: Ingen tildelinger ble opprettet for denne søknaden.',
							'comment'
						);
						$rejectedIds[] = $relatedId;

						if ($sendEmail) {
							$this->sendNotificationSafe($relatedId);
						}
					}
				}
			}
		}

		// Send email for the primary application
		if ($sendEmail) {
			$this->sendNotificationSafe($appId);
		}

		return [
			'status'       => 'ok',
			'accepted_ids' => $acceptedIds,
			'rejected_ids' => $rejectedIds,
		];
	}

	// ── Reject application ─────────────────────────────────────────────

	/**
	 * Reject an application: status change, deactivate associations, handle combined apps.
	 */
	public function rejectApplication(int $appId, int $accountId, string $reason, bool $sendEmail): void
	{
		$row = $this->repo->getById($appId);
		if (!$row) {
			throw new RuntimeException('Application not found', 404);
		}

		$authorName = $this->repo->fetchAccountName($accountId) ?? 'Unknown';

		// Add rejection reason as comment
		if (!empty($reason)) {
			$this->repo->addComment($appId, $authorName, $reason, 'comment');
		}

		// Reject primary application
		$this->repo->updateStatus($appId, 'REJECTED');
		$this->repo->deactivateAssociations($appId);

		// Handle combined applications
		if ($this->combineApplications) {
			$relatedInfo = $this->repo->getRelatedApplications($appId);
			if ($relatedInfo['total_count'] > 1) {
				$relatedIds = array_filter(
					$relatedInfo['application_ids'],
					fn(int $id) => $id !== $appId
				);

				foreach ($relatedIds as $relatedId) {
					$this->repo->updateStatus($relatedId, 'REJECTED');
					$this->repo->deactivateAssociations($relatedId);
					$this->repo->addComment($relatedId, $authorName, $reason, 'comment');

					if ($sendEmail) {
						$this->sendNotificationSafe($relatedId);
					}
				}
			}
		}

		if ($sendEmail) {
			$this->sendNotificationSafe($appId);
		}
	}

	// ── Reassign case officer ──────────────────────────────────────────

	/**
	 * Reassign an application to a different case officer.
	 * Propagates to combined apps when enabled.
	 */
	public function reassignCaseOfficer(int $appId, int $accountId, int $newUserId): void
	{
		$row = $this->repo->getById($appId);
		if (!$row) {
			throw new RuntimeException('Application not found', 404);
		}

		$newUserName = $this->repo->fetchAccountName($newUserId) ?? 'Unknown';
		$commentText = "User '{$newUserName}' was assigned";

		// Assign + status transition on the primary application
		$this->repo->assignCaseOfficer($appId, $newUserId);
		if (($row['status'] ?? '') === 'NEW') {
			$this->repo->updateStatus($appId, 'PENDING');
		}
		$this->repo->addComment($appId, $newUserName, $commentText, 'ownership');

		// Propagate to related applications when combining is enabled
		if ($this->combineApplications) {
			$relatedInfo = $this->repo->getRelatedApplications($appId);
			if ($relatedInfo['total_count'] > 1) {
				$relatedIds = array_filter(
					$relatedInfo['application_ids'],
					fn(int $id) => $id !== $appId
				);

				$relatedRows = $this->repo->getByIds($relatedIds);

				foreach ($relatedIds as $relatedId) {
					$relatedRow = $relatedRows[$relatedId] ?? null;
					if (!$relatedRow) continue;

					if (($relatedRow['case_officer_id'] ?? null) == $newUserId) continue;

					$this->repo->assignCaseOfficer($relatedId, $newUserId);
					if (($relatedRow['status'] ?? '') === 'NEW') {
						$this->repo->updateStatus($relatedId, 'PENDING');
					}
					$this->repo->addComment($relatedId, $newUserName, $commentText, 'ownership');
				}
			}
		}
	}

	// ── Messenger ──────────────────────────────────────────────────────

	/**
	 * Send a message to the application's case officer via the internal messenger.
	 */
	public function sendMessage(int $appId, int $fromAccountId, string $subject, string $content): void
	{
		$app = $this->repo->getById($appId);
		if (!$app) {
			throw new RuntimeException('Application not found', 404);
		}

		$caseOfficerId = (int) ($app['case_officer_id'] ?? 0);
		if (!$caseOfficerId) {
			throw new RuntimeException('No case officer assigned', 400);
		}
		if ($caseOfficerId === $fromAccountId) {
			throw new RuntimeException('Cannot send message to yourself', 400);
		}

		$messenger = \CreateObject('messenger.somessenger');
		$messenger->send_message([
			'to'      => $caseOfficerId,
			'subject' => $subject,
			'content' => $content,
		]);
	}

	// ── Email helper ───────────────────────────────────────────────────

	/**
	 * Build the application data array needed by EmailService.
	 */
	private function buildEmailApplicationData(int $appId, array $row): array
	{
		$emailApp = $row;
		$emailApp['resources'] = $this->repo->fetchResourceIds($appId);
		$dates = $this->repo->fetchDates($appId);
		$emailApp['dates'] = array_map(function ($d) {
			return ['from_' => $d['from_'], 'to_' => $d['to_']];
		}, $dates);
		return $emailApp;
	}

	/**
	 * Send notification email, suppressing exceptions (logs on failure).
	 */
	private function sendNotificationSafe(int $appId): void
	{
		try {
			$row = $this->repo->getById($appId);
			if (!$row) return;

			$emailApp = $this->buildEmailApplicationData($appId, $row);
			$emailService = new EmailService();
			$emailService->sendApplicationNotification($emailApp);
		} catch (\Throwable $e) {
			error_log("Failed to send notification for application {$appId}: " . $e->getMessage());
		}
	}

	// ── Recurring preview ──────────────────────────────────────────────

	/**
	 * Generate a preview of recurring allocation dates for an application.
	 *
	 * Reimplements legacy generate_recurring_preview() logic cleanly:
	 * - Parses recurring_info JSON (field_interval, outseason, repeat_until)
	 * - Generates dates at N-week intervals from the first application date
	 * - Checks each date against existing allocations and collisions
	 *
	 * @throws RuntimeException if the application is not found or has no recurring data.
	 */
	public function generateRecurringPreview(int $appId): array
	{
		$app = $this->repo->getById($appId);
		if (!$app) {
			throw new RuntimeException('Application not found', 404);
		}

		$rawRecurring = $app['recurring_info'] ?? null;
		$recurringData = null;
		if (!empty($rawRecurring) && is_string($rawRecurring)) {
			$recurringData = json_decode($rawRecurring, true);
		} elseif (is_array($rawRecurring)) {
			$recurringData = $rawRecurring;
		}

		if (!is_array($recurringData)) {
			throw new RuntimeException('Application has no recurring data', 400);
		}

		// Fetch first application date
		$dates = $this->repo->fetchDates($appId);
		if (empty($dates)) {
			throw new RuntimeException('Application has no dates', 400);
		}

		$firstDate = $dates[0];
		$fromTs = strtotime($firstDate['from_']);
		$toTs = strtotime($firstDate['to_']);

		if (!$fromTs || !$toTs) {
			throw new RuntimeException('Invalid date format', 400);
		}

		// Interval in weeks (default 1)
		$intervalWeeks = max(1, (int) ($recurringData['field_interval'] ?? 1));
		$intervalSeconds = $intervalWeeks * 7 * 86400;

		// Determine end date
		$repeatUntilTs = null;
		$seasonInfo = null;
		$buildingId = (int) ($app['building_id'] ?? 0);

		if ($buildingId > 0) {
			$season = $this->repo->fetchSeasonInfo($buildingId, $firstDate['from_']);
			if ($season) {
				$seasonInfo = [
					'id'   => (int) $season['id'],
					'name' => $season['name'],
					'from_' => $season['from_'],
					'to_'   => $season['to_'],
				];

				if (!empty($recurringData['outseason'])) {
					// Use season end date
					$repeatUntilTs = strtotime($season['to_']);
				}
			}
		}

		if ($repeatUntilTs === null && !empty($recurringData['repeat_until'])) {
			$repeatUntilTs = strtotime($recurringData['repeat_until']);
		}

		// Fallback: season end or +3 months from first date (matches legacy DateInterval P3M)
		if (!$repeatUntilTs) {
			if ($seasonInfo) {
				$repeatUntilTs = strtotime($seasonInfo['to_']);
			} else {
				$repeatUntilTs = strtotime('+3 months', $fromTs);
			}
		}

		// Fetch resources and existing allocations
		$resourceIds = $this->repo->fetchResourceIds($appId);
		$resourceNames = $this->repo->getResourceNames($resourceIds);
		$existingAllocations = $this->repo->fetchExistingAllocations($appId);
		$resourceDisplay = implode(', ', $resourceNames);

		// Generate items
		$items = [];
		$maxIterations = 50;
		$counts = ['total' => 0, 'existing' => 0, 'conflict' => 0, 'creatable' => 0];
		$i = 0;

		// Loop: from_ts + (interval * i) until repeat_until, matching legacy condition
		while (($toTs + ($intervalSeconds * $i)) <= $repeatUntilTs && $i < $maxIterations) {
			$itemFromTs = $fromTs + ($intervalSeconds * $i);
			$itemToTs = $toTs + ($intervalSeconds * $i);
			$itemFrom = date('Y-m-d H:i', $itemFromTs);
			$itemTo = date('Y-m-d H:i', $itemToTs);
			$lookupKey = $itemFrom . '_' . $itemTo;

			$item = [
				'from_'            => $itemFrom,
				'to_'              => $itemTo,
				'date_display'     => date('d.m.Y', $itemFromTs),
				'day_name'         => $this->norwegianDayName($itemFromTs),
				'time_display'     => date('H:i', $itemFromTs) . ' - ' . date('H:i', $itemToTs),
				'resource_display' => $resourceDisplay,
				'exists'           => false,
				'allocation_id'    => null,
				'has_conflict'     => false,
				'conflict_details' => [],
				'schedule_link'    => '/?menuaction=bookingfrontend.uibuilding.schedule&id='
					. $buildingId . '&backend=1&date=' . date('Y-m-d', $itemFromTs),
			];

			// Check if allocation already exists
			if (isset($existingAllocations[$lookupKey])) {
				$item['exists'] = true;
				$item['allocation_id'] = (int) $existingAllocations[$lookupKey]['id'];
				$counts['existing']++;
			} else {
				// Check collision
				if ($this->repo->hasCollision($resourceIds, $itemFrom, $itemTo)) {
					$item['has_conflict'] = true;
					$item['conflict_details'] = $this->repo->getCollisionDetails(
						$resourceIds, $itemFrom, $itemTo
					);
					$counts['conflict']++;
				} else {
					$counts['creatable']++;
				}
			}

			$counts['total']++;
			$items[] = $item;
			$i++;
		}

		return [
			'items'          => $items,
			'counts'         => $counts,
			'can_create'     => $counts['creatable'] > 0,
			'season_info'    => $seasonInfo,
			'recurring_data' => $recurringData,
			'interval_weeks' => $intervalWeeks,
		];
	}

	// ── Create recurring allocations ───────────────────────────────────

	/**
	 * Create allocations for non-conflicting, non-existing recurring dates.
	 *
	 * @throws RuntimeException if the application is not found or not recurring.
	 */
	public function createRecurringAllocations(int $appId): array
	{
		$app = $this->repo->getById($appId);
		if (!$app) {
			throw new RuntimeException('Application not found', 404);
		}

		$preview = $this->generateRecurringPreview($appId);
		$buildingId = (int) ($app['building_id'] ?? 0);

		// Resolve organization ID: prefer stored ID, fall back to lookup by org number
		$organizationId = (int) ($app['customer_organization_id'] ?? 0);
		if ($organizationId === 0 && !empty($app['customer_organization_number'])) {
			$org = $this->repo->fetchOrganizationByNumber($app['customer_organization_number']);
			if ($org) {
				$organizationId = (int) $org['id'];
			}
		}

		$seasonId = $preview['season_info']['id'] ?? 0;
		$resourceIds = $this->repo->fetchResourceIds($appId);
		$buildingName = $buildingId > 0
			? $this->repo->fetchBuildingName($buildingId)
			: '';

		if ($organizationId === 0) {
			throw new RuntimeException('Cannot create allocations: organization not found', 400);
		}
		if ($seasonId === 0) {
			throw new RuntimeException('Cannot create allocations: no active season found', 400);
		}
		if (empty($resourceIds)) {
			throw new RuntimeException('Cannot create allocations: no resources linked', 400);
		}

		$created = [];
		$failed = [];
		$totalAttempted = 0;

		foreach ($preview['items'] as $item) {
			if ($item['exists']) {
				continue;
			}
			if ($item['has_conflict']) {
				$failed[] = [
					'date'   => $item['date_display'],
					'time'   => $item['time_display'],
					'reason' => 'conflict',
				];
				$totalAttempted++;
				continue;
			}

			$totalAttempted++;

			try {
				$allocation = new Allocation([
					'application_id' => $appId,
					'organization_id' => $organizationId,
					'season_id'      => $seasonId,
					'from_'          => $item['from_'],
					'to_'            => $item['to_'],
					'cost'           => 0,
					'active'         => 1,
					'completed'      => 0,
					'skip_bas'       => 0,
					'building_name'  => $buildingName,
					'resources'      => $resourceIds,
				]);

				if ($allocation->save()) {
					$created[] = [
						'id'   => $allocation->id,
						'date' => $item['date_display'],
						'time' => $item['time_display'],
					];
				} else {
					$failed[] = [
						'date'   => $item['date_display'],
						'time'   => $item['time_display'],
						'reason' => 'save_failed',
					];
				}
			} catch (\Throwable $e) {
				$failed[] = [
					'date'   => $item['date_display'],
					'time'   => $item['time_display'],
					'reason' => $e->getMessage(),
				];
			}
		}

		return [
			'created'         => $created,
			'failed'          => $failed,
			'total_attempted' => $totalAttempted,
		];
	}

	// ── Helpers ─────────────────────────────────────────────────────────

	private function norwegianDayName(int $timestamp): string
	{
		$days = ['Søndag', 'Mandag', 'Tirsdag', 'Onsdag', 'Torsdag', 'Fredag', 'Lørdag'];
		return $days[(int) date('w', $timestamp)];
	}
}
