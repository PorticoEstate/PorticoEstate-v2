<?php

namespace App\modules\bookingfrontend\services;

use App\modules\bookingfrontend\repositories\AllocationCancellationRepository;
use App\modules\bookingfrontend\services\applications\ApplicationCommentsService;
use DateTimeImmutable;
use Throwable;

/**
 * Withdraw ONE allocation occurrence from Min side (GH #1393, criteria 5+6+7+8).
 *
 * Deliberately NOT AllocationCancellationService: that service is the admin/calendar-driven,
 * multi-scope (occurrence/season/until) HARD DELETE with a confirm-token preview workflow, built
 * for an organization admin clearing a series - and it sends no mail and leaves no comment trail
 * (re-derived: neither AllocationCancellationService.php nor AllocationController.php contains
 * 'mail'/'notif'/'email').
 *
 * This is always exactly one occurrence, triggered by the applicant, and the officer is emailed
 * about it - so the row is soft-deactivated (bb_allocation.active = 0) rather than deleted. That
 * is not a novel choice: CommentsController::updateApplicationStatus already withdraws a WHOLE
 * application this same way, via ApplicationRepository::deactivateAssociatedEntities's
 * `UPDATE {table} SET active = 0` - never a delete. Hard-deleting a single occurrence would make
 * the applicant-facing path MORE destructive than its own sibling whole-application path for no
 * stated reason. See this task's handoff for the reuse-hard-delete-vs-trace tradeoff in full;
 * this class is the "leave a trace" arm, built because it is the one already precedented here.
 */
class AllocationWithdrawalService
{
	private AllocationCancellationRepository $repository;
	private ApplicationCommentsService $commentsService;

	public function __construct(
		?AllocationCancellationRepository $repository = null,
		?ApplicationCommentsService $commentsService = null
	)
	{
		$this->repository = $repository ?: new AllocationCancellationRepository();
		$this->commentsService = $commentsService ?: new ApplicationCommentsService();
	}

	/**
	 * @param array       $allocation The row from AllocationCancellationRepository::getAllocation()
	 * @param string      $comment    The applicant's own text (criterion 8). May be empty; a
	 *                                fallback is stored instead of an empty comment row, matching
	 *                                addStatusChangeComment's own always-non-empty status line.
	 * @param string|null $author     Comment author name, resolved by the caller the same way
	 *                                CommentsController resolves it for every other comment.
	 * @return array{allocation_id:int, application_id:int, deactivated:bool}
	 */
	public function withdraw(array $allocation, string $comment, ?string $author): array
	{
		$applicationId = (int)$allocation['application_id'];

		$resourceNames = array_values(array_filter(
			$this->repository->getResourceNames($allocation['resources'])
		));
		$resourceName = !empty($resourceNames) ? implode(', ', $resourceNames) : (string)($allocation['building_name'] ?? '');

		// Threaded through addComment -> sendAdminNotification -> boapplication::send_admin_notification
		// -> EmailService::sendStatusChangeNotificationToStaff -> admin_comment_notification.twig
		// (criterion 7's signature change - see EmailService.php). Kept separate from $message so
		// the applicant's own words (criterion 8) are never decorated with system text.
		$occurrenceContext = [
			'resource_name' => $resourceName,
			'date' => $this->formatDate($allocation['from_']),
			'time' => $this->formatTimeRange($allocation['from_'], $allocation['to_']),
		];

		$message = $comment !== '' ? $comment : 'Trukket enkelttime';

		// Deactivate + comment insert succeed or fail together (GH #1393 atomicity follow-up).
		// addComment() shares this same Db::getInstance() connection, sees the transaction
		// already open, and nests under it instead of managing its own - it queues the
		// notification but does NOT flush it (see its $ownTransaction check). That is the
		// same pattern addStatusChangeComment already uses around its own nested addComment
		// call. The officer mail is only flushed (forked, post-commit) once OUR commit below
		// has actually happened, so a mail failure can never roll back a legitimate withdrawal,
		// and a comment-insert failure (e.g. the NULL-author NOT NULL violation, #26491) rolls
		// the deactivation back too instead of leaving the slot released with nothing recorded.
		$this->repository->beginTransaction();
		try
		{
			$this->repository->deactivateAllocation((int)$allocation['id']);

			$this->commentsService->addComment(
				$applicationId,
				$message,
				'comment',
				$author,
				$occurrenceContext
			);

			$this->repository->commit();
		}
		catch (Throwable $e)
		{
			$this->repository->rollBack();
			throw $e;
		}

		$this->commentsService->flushPendingNotifications();

		return [
			'allocation_id' => (int)$allocation['id'],
			'application_id' => $applicationId,
			'deactivated' => true,
		];
	}

	private function formatDate(string $sqlDateTime): string
	{
		return (new DateTimeImmutable($sqlDateTime))->format('d.m.Y');
	}

	private function formatTimeRange(string $from, string $to): string
	{
		return (new DateTimeImmutable($from))->format('H:i') . '–' . (new DateTimeImmutable($to))->format('H:i');
	}
}
