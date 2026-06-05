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
}
