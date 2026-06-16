<?php

namespace App\modules\booking\services;

use App\modules\booking\repositories\NotificationRepository;
use App\WebSocket\helpers\WebSocketHelper;

/**
 * Service for creating and managing in-app notifications.
 */
class NotificationService
{
    private NotificationRepository $repo;

    public function __construct()
    {
        $this->repo = new NotificationRepository();
    }

    /**
     * Create a notification for a new comment on an application.
     *
     * @param int    $applicationId       Application ID
     * @param int    $commentId           Comment ID
     * @param string $authorName          Author display name
     * @param string $commentText         Full comment text
     * @param string $recipientUserType   e.g. 'phpgw_accounts' or 'bb_user'
     * @param string $recipientIdentifier e.g. account ID or SSN
     * @return int Created notification ID
     */
    public function createCommentNotification(
        int $applicationId,
        int $commentId,
        string $authorName,
        string $commentText,
        string $recipientUserType,
        string $recipientIdentifier
    ): int {
        $notificationId = $this->repo->create([
            'source_type'          => 'application_comment',
            'source_id'            => $commentId,
            'entity_type'          => 'application',
            'entity_id'            => $applicationId,
            'recipient_user_type'  => $recipientUserType,
            'recipient_identifier' => $recipientIdentifier,
            'title'                => 'new_comment_notification',
            'message'              => mb_substr($commentText, 0, 200),
            'link'                 => '/user/applications/' . $applicationId,
            'data'                 => [
                'title_is_key'  => true,
                'title_params'  => ['1' => $authorName],
            ],
        ]);

        // Publish real-time events via Redis/WebSocket
        try {
            // 1. Live thread update for anyone viewing the application
            WebSocketHelper::sendEntityEvent('application', $applicationId, 'new_comment', [
                'comment' => [
                    'id'      => $commentId,
                    'author'  => $authorName,
                    'comment' => $commentText,
                    'time'    => date('c'),
                    'type'    => 'comment',
                ],
                'notification_id' => $notificationId,
            ]);

            // 2. Bell update for the recipient on every tab/page (identity room)
            WebSocketHelper::sendToUserRoom($recipientUserType, $recipientIdentifier, [
                'type'      => 'notification_event',
                'eventType' => 'new',
                'notification' => [
                    'id'          => $notificationId,
                    'entity_type' => 'application',
                    'entity_id'   => $applicationId,
                    'title'       => 'new_comment_notification',
                    'message'     => mb_substr($commentText, 0, 200),
                    'link'        => '/user/applications/' . $applicationId,
                    'is_read'     => false,
                    'data'        => [
                        'title_is_key' => true,
                        'title_params' => ['1' => $authorName],
                    ],
                    'created'     => date('c'),
                ],
                'timestamp' => date('c'),
            ]);
        } catch (\Throwable $e) {
            error_log("Failed to send WebSocket event for comment notification: " . $e->getMessage());
        }

        return $notificationId;
    }
}
