<?php

namespace App\modules\bookingfrontend\services\applications;

use App\Database\Db;
use App\modules\booking\services\NotificationService;
use App\modules\bookingfrontend\helpers\UserHelper;
use App\modules\bookingfrontend\helpers\WebSocketHelper;
use App\modules\bookingfrontend\interfaces\CommentsServiceInterface;
use App\modules\bookingfrontend\models\ApplicationComment;
use App\modules\bookingfrontend\models\User;
use App\modules\phpgwapi\services\Settings;
use PDO;
use Exception;

class ApplicationCommentsService implements CommentsServiceInterface
{
    private $db;
    private $userSettings;
	private UserHelper $userHelper;
	/** @var array Notifications queued during a transaction, dispatched (forked) after commit */
	private array $pendingNotifications = [];

	public function __construct()
    {
        $this->db = Db::getInstance();
        $this->userSettings = Settings::getInstance()->get('user');
		$this->userHelper = new UserHelper();

    }

    /**
     * Get comments for an entity (implementation of interface method)
     *
     * @param int $entityId Entity ID (application ID in this case)
     * @param array $types Comment types to filter by
     * @return array Array of comment objects
     */
    public function getEntityComments(int $entityId, array $types = ['comment']): array
    {
        return $this->getApplicationComments($entityId, $types);
    }

    /**
     * Get all comments for an application
     *
     * @param int $applicationId Application ID
     * @param array $types Optional comment types to filter by (default: ['comment'])
     * @return array Array of ApplicationComment objects serialized to arrays
     */
    public function getApplicationComments(int $applicationId, array $types = ['comment']): array
    {

        $typesList = implode(',', array_fill(0, count($types), '?'));

        $sql = "SELECT id, application_id, time, author, comment, type
                FROM bb_application_comment
                WHERE application_id = ?
                AND type IN ($typesList)
                ORDER BY time ASC";

        $params = array_merge([$applicationId], $types);

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Convert database rows to ApplicationComment models and serialize
        $comments = [];
        foreach ($rows as $row) {
            $comment = new ApplicationComment($row);
            $comments[] = $comment->toArray();
        }

        return $comments;
    }

    /**
     * Add a comment to an application
     *
     * @param int $applicationId Application ID
     * @param string $comment Comment text
     * @param string $type Comment type (default: 'comment')
     * @param string|null $author Optional author name (defaults to current user)
     * @return array The created comment
     * @throws Exception If comment creation fails
     */
    public function addComment(int $applicationId, string $comment, string $type = 'comment', ?string $author = null): array
    {
        // Only manage the transaction if no outer transaction is active (e.g. from addStatusChangeComment)
        $ownTransaction = !$this->db->inTransaction();
        try {
            if ($ownTransaction) {
                $this->db->beginTransaction();
            }
			$userModel = new User($this->userHelper);

            // Use provided author or get current user name
            if (!$author) {
                $author = $userModel->name;
            }

            // Insert comment
            $sql = "INSERT INTO bb_application_comment (application_id, time, author, comment, type)
                    VALUES (?, NOW(), ?, ?, ?)";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$applicationId, $author, $comment, $type]);

            $commentId = $this->db->lastInsertId();

            // Get the created comment
            $sql = "SELECT id, application_id, time, author, comment, type
                    FROM bb_application_comment
                    WHERE id = ?";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$commentId]);
            $createdComment = $stmt->fetch(PDO::FETCH_ASSOC);

            // Update application's frontend_modified timestamp
            $this->updateApplicationModified($applicationId);

            // Queue notifications (admin email + case officer) to be dispatched after the
            // outermost commit. Sending inline would block the response on a slow/unreachable
            // SMTP server and hold the transaction open the whole time.
            $this->pendingNotifications[] = [
                'applicationId' => $applicationId,
                'commentId' => (int) $commentId,
                'author' => $author,
                'comment' => $comment,
            ];

            if ($ownTransaction) {
                $this->db->commit();
                $this->flushPendingNotifications();
            }

            // Convert to ApplicationComment model and return serialized
            $comment = new ApplicationComment($createdComment);
            return $comment->toArray();

        } catch (Exception $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
                $this->pendingNotifications = [];
            }
            throw $e;
        }
    }

    /**
     * Add a status change comment and update application status
     *
     * @param int $applicationId Application ID
     * @param string $newStatus New application status
     * @param string|null $additionalComment Optional additional comment
     * @param string|null $author Optional author name
     * @return array The created comments
     * @throws Exception If update fails
     */
    public function addStatusChangeComment(int $applicationId, string $newStatus, ?string $additionalComment = null, ?string $author = null): array
    {
        try {
            $this->db->beginTransaction();

            $createdComments = [];

            // Add the additional comment first if provided
            if ($additionalComment) {
                $createdComments[] = $this->addComment($applicationId, $additionalComment, 'comment', $author);
            }

            // Add status change comment
            $statusComment = "Status: " . strtolower($newStatus);
            $createdComments[] = $this->addComment($applicationId, $statusComment, 'status', $author);

            // Update application status
            $sql = "UPDATE bb_application SET status = ?, frontend_modified = NOW() WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$newStatus, $applicationId]);

            $this->db->commit();

            // Dispatch queued notifications now that the transaction has committed
            $this->flushPendingNotifications();

            return $createdComments;

        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->pendingNotifications = [];
            throw $e;
        }
    }

    /**
     * Add an ownership change comment
     *
     * @param int $applicationId Application ID
     * @param string $comment Ownership change description
     * @param string|null $author Optional author name
     * @return array The created comment
     */
    public function addOwnershipChangeComment(int $applicationId, string $comment, ?string $author = null): array
    {
        return $this->addComment($applicationId, $comment, 'ownership', $author);
    }

    /**
     * Get current user's full name
     *
     * @return string User's full name
     */
    private function getCurrentUserName(): string
    {
        // Try to get from user settings
        if (!empty($this->userSettings['fullname'])) {
            return $this->userSettings['fullname'];
        }

        // Fallback to account name if available
        if (!empty($this->userSettings['account_lid'])) {
            return $this->userSettings['account_lid'];
        }

        // Last resort fallback
        return 'System User';
    }

    /**
     * Update application's modified timestamp
     *
     * @param int $applicationId Application ID
     */
    private function updateApplicationModified(int $applicationId): void
    {
        $sql = "UPDATE bb_application SET frontend_modified = NOW() WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$applicationId]);
    }

    /**
     * Send admin notification for new comment
     *
     * @param int $applicationId Application ID
     * @param string $comment Comment text
     */
    private function sendAdminNotification(int $applicationId, string $comment): void
    {
        try {
            // Get application data for notification
            $sql = "SELECT * FROM bb_application WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$applicationId]);
            $application = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($application) {
                // Use legacy notification system
                $bo = CreateObject('booking.boapplication');
                $bo->send_admin_notification($application, $comment);
            }
        } catch (Exception $e) {
            // Log error but don't fail the comment creation
            error_log("Failed to send admin notification for comment on application {$applicationId}: " . $e->getMessage());
        }
    }

    /**
     * Get comment statistics for an application
     *
     * @param int $applicationId Application ID
     * @return array Comment statistics
     */
    public function getCommentStats(int $applicationId): array
    {
        $sql = "SELECT
                    COUNT(*) as total_comments,
                    COUNT(CASE WHEN type = 'comment' THEN 1 END) as regular_comments,
                    COUNT(CASE WHEN type = 'ownership' THEN 1 END) as ownership_comments,
                    MAX(time) as last_comment_time
                FROM bb_application_comment
                WHERE application_id = ?";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$applicationId]);

        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        // Format last comment time
        if ($stats['last_comment_time']) {
            $stats['last_comment_time'] = date('c', strtotime($stats['last_comment_time']));
        }

        return $stats;
    }

    /**
     * Create an in-app notification for the case officer when a frontend user posts a comment.
     *
     * @param int    $applicationId Application ID
     * @param int    $commentId     Created comment ID
     * @param string $authorName    Comment author name
     * @param string $commentText   Comment text
     */
    private function notifyCaseOfficer(int $applicationId, int $commentId, string $authorName, string $commentText): void
    {
        $sql = "SELECT case_officer_id FROM bb_application WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$applicationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $caseOfficerId = (int) ($row['case_officer_id'] ?? 0);
        if ($caseOfficerId === 0) {
            return;
        }

        $notificationService = new NotificationService();
        $notificationService->createCommentNotification(
            $applicationId,
            $commentId,
            $authorName,
            $commentText,
            'phpgw_accounts',
            strval($caseOfficerId)
        );
    }

    /**
     * Dispatch any queued notifications. Runs in a forked child process so the slow
     * admin email send (synchronous SMTP) never blocks the HTTP response. Must only be
     * called after the surrounding transaction has committed, so the forked child does
     * not share an open transaction on the database connection.
     */
    private function flushPendingNotifications(): void
    {
        if (empty($this->pendingNotifications)) {
            return;
        }

        $pending = $this->pendingNotifications;
        $this->pendingNotifications = [];

        WebSocketHelper::forkNotification(function () use ($pending) {
            foreach ($pending as $notification) {
                try {
                    $this->sendAdminNotification($notification['applicationId'], $notification['comment']);
                } catch (Exception $e) {
                    error_log("Failed to send admin notification for application {$notification['applicationId']}: " . $e->getMessage());
                }

                try {
                    $this->notifyCaseOfficer(
                        $notification['applicationId'],
                        $notification['commentId'],
                        $notification['author'],
                        $notification['comment']
                    );
                } catch (Exception $e) {
                    error_log("Failed to create case officer notification for application {$notification['applicationId']}: " . $e->getMessage());
                }
            }
        });
    }

}