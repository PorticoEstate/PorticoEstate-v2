<?php

namespace App\modules\bookingfrontend\controllers;

use App\helpers\ResponseHelper;
use App\modules\booking\repositories\NotificationRepository;
use App\modules\bookingfrontend\helpers\UserHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Exception;

class NotificationController
{
    private NotificationRepository $notificationRepo;

    public function __construct()
    {
        $this->notificationRepo = new NotificationRepository();
    }

    /**
     * GET /bookingfrontend/notifications
     *
     * Returns the user's notifications (newest first), paginated.
     * Query params: ?unread=1 (only unread), ?limit=50, ?offset=0
     */
    public function getNotifications(Request $request, Response $response): Response
    {
        try {
            $ssn = $this->getAuthenticatedSsn();
            if ($ssn === null) {
                return ResponseHelper::sendErrorResponse(['error' => 'Not authenticated'], 401);
            }

            $query = $request->getQueryParams();
            $onlyUnread = !empty($query['unread']) && $query['unread'] !== '0';
            $limit = isset($query['limit']) ? max(1, min(100, (int) $query['limit'])) : 50;
            $offset = isset($query['offset']) ? max(0, (int) $query['offset']) : 0;

            $rows = $this->notificationRepo->getAllByRecipient('bb_user', $ssn, $limit, $offset, $onlyUnread);
            $total = $this->notificationRepo->countAllByRecipient('bb_user', $ssn, $onlyUnread);

            $notifications = array_map(function (array $row) {
                return [
                    'id'          => (int) $row['id'],
                    'source_type' => $row['source_type'],
                    'source_id'   => (int) $row['source_id'],
                    'entity_type' => $row['entity_type'],
                    'entity_id'   => (int) $row['entity_id'],
                    'title'       => $row['title'],
                    'message'     => $row['message'],
                    'link'        => $row['link'],
                    'is_read'     => (bool) $row['is_read'],
                    'read_at'     => $this->toIso8601Utc($row['read_at']),
                    'data'        => !empty($row['data']) ? json_decode($row['data'], true) : null,
                    'created'     => $this->toIso8601Utc($row['created']),
                ];
            }, $rows);

            return ResponseHelper::sendJSONResponse([
                'notifications' => $notifications,
                'total'         => $total,
                'limit'         => $limit,
                'offset'        => $offset,
            ], 200, $response);
        } catch (Exception $e) {
            error_log("Error fetching notifications: " . $e->getMessage());
            return ResponseHelper::sendErrorResponse(['error' => 'Failed to fetch notifications'], 500);
        }
    }

    /**
     * GET /bookingfrontend/notifications/unread-count
     *
     * Returns total unread count and per-application breakdown.
     */
    public function getUnreadCount(Request $request, Response $response): Response
    {
        try {
            $ssn = $this->getAuthenticatedSsn();
            if ($ssn === null) {
                return ResponseHelper::sendErrorResponse(['error' => 'Not authenticated'], 401);
            }

            $counts = $this->notificationRepo->getUnreadCount('bb_user', $ssn);

            $totalUnread = 0;
            $applications = [];
            foreach ($counts as $row) {
                $unread = (int) $row['unread_count'];
                $totalUnread += $unread;

                if ($row['entity_type'] === 'application') {
                    $applications[] = [
                        'application_id' => (int) $row['entity_id'],
                        'unread_count'   => $unread,
                    ];
                }
            }

            return ResponseHelper::sendJSONResponse([
                'total_unread' => $totalUnread,
                'applications' => $applications,
            ], 200, $response);
        } catch (Exception $e) {
            error_log("Error fetching unread notification count: " . $e->getMessage());
            return ResponseHelper::sendErrorResponse(['error' => 'Failed to fetch notifications'], 500);
        }
    }

    /**
     * PUT /bookingfrontend/notifications/{entity_type}/{entity_id}/mark-read
     *
     * Mark all unread notifications as read for the given entity.
     */
    public function markAsRead(Request $request, Response $response, array $args): Response
    {
        try {
            $ssn = $this->getAuthenticatedSsn();
            if ($ssn === null) {
                return ResponseHelper::sendErrorResponse(['error' => 'Not authenticated'], 401);
            }

            $entityType = $args['entity_type'] ?? '';
            $entityId = (int) ($args['entity_id'] ?? 0);

            if (empty($entityType) || $entityId === 0) {
                return ResponseHelper::sendErrorResponse(['error' => 'Missing entity_type or entity_id'], 400);
            }

            $updated = $this->notificationRepo->markAsRead('bb_user', $ssn, $entityType, $entityId);

            return ResponseHelper::sendJSONResponse([
                'status'  => 'ok',
                'updated' => $updated,
            ], 200, $response);
        } catch (Exception $e) {
            error_log("Error marking notifications as read: " . $e->getMessage());
            return ResponseHelper::sendErrorResponse(['error' => 'Failed to mark notifications as read'], 500);
        }
    }

    /**
     * Get the SSN of the currently authenticated booking-frontend user.
     *
     * @return string|null SSN or null if not authenticated
     */
    private function getAuthenticatedSsn(): ?string
    {
        $bouser = new UserHelper();
        if (!$bouser->is_logged_in()) {
            return null;
        }

        $ssn = $bouser->ssn ?? '';
        return !empty($ssn) ? $ssn : null;
    }

    /**
     * Normalise a naive DB timestamp (stored in UTC, `timestamp without time zone`)
     * to an unambiguous ISO-8601 string with offset — matching the WS payload's
     * date('c') format so the client applies one consistent Europe/Oslo conversion.
     *
     * @param string|null $ts Raw DB value (e.g. "2026-07-01 08:57:28.88399") or null
     * @return string|null ISO-8601 with offset (e.g. "2026-07-01T08:57:28+00:00"), or null
     */
    private function toIso8601Utc(?string $ts): ?string
    {
        if (empty($ts)) {
            return null;
        }
        try {
            return (new \DateTime($ts, new \DateTimeZone('UTC')))->format('c');
        } catch (\Exception $e) {
            return $ts;
        }
    }
}
