<?php

namespace App\modules\booking\repositories;

use App\Database\Db;
use PDO;

/**
 * Repository for in-app notification persistence (bb_notification table).
 */
class NotificationRepository
{
    private Db $db;

    public function __construct()
    {
        $this->db = Db::getInstance();
    }

    /**
     * Insert a new notification and return its ID.
     *
     * @param array $notification Associative array with column values
     * @return int The auto-generated notification ID
     */
    public function create(array $notification): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO bb_notification
                (source_type, source_id, entity_type, entity_id,
                 recipient_user_type, recipient_identifier,
                 title, message, link, is_read, data, created, expires_at)
             VALUES
                (:source_type, :source_id, :entity_type, :entity_id,
                 :recipient_user_type, :recipient_identifier,
                 :title, :message, :link, false, :data, NOW(), :expires_at)"
        );

        $stmt->execute([
            ':source_type'          => $notification['source_type'],
            ':source_id'            => $notification['source_id'],
            ':entity_type'          => $notification['entity_type'],
            ':entity_id'            => $notification['entity_id'],
            ':recipient_user_type'  => $notification['recipient_user_type'],
            ':recipient_identifier' => $notification['recipient_identifier'],
            ':title'                => $notification['title'],
            ':message'              => $notification['message'] ?? null,
            ':link'                 => $notification['link'] ?? null,
            ':data'                 => isset($notification['data']) ? json_encode($notification['data']) : null,
            ':expires_at'           => $notification['expires_at'] ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Get unread notifications for a recipient, optionally filtered by entity.
     *
     * @param string      $userType   Recipient user type
     * @param string      $identifier Recipient identifier
     * @param string|null $entityType Optional entity type filter
     * @param int|null    $entityId   Optional entity ID filter
     * @return array
     */
    public function getUnreadByRecipient(
        string $userType,
        string $identifier,
        ?string $entityType = null,
        ?int $entityId = null
    ): array {
        $sql = "SELECT * FROM bb_notification
                WHERE recipient_user_type = :user_type
                  AND recipient_identifier = :identifier
                  AND is_read = false";
        $params = [
            ':user_type'  => $userType,
            ':identifier' => $identifier,
        ];

        if ($entityType !== null) {
            $sql .= " AND entity_type = :entity_type";
            $params[':entity_type'] = $entityType;
        }
        if ($entityId !== null) {
            $sql .= " AND entity_id = :entity_id";
            $params[':entity_id'] = $entityId;
        }

        $sql .= " ORDER BY created DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get unread notification counts grouped by entity_type + entity_id.
     *
     * @param string $userType   Recipient user type
     * @param string $identifier Recipient identifier
     * @return array Array of rows with entity_type, entity_id, unread_count
     */
    public function getUnreadCount(string $userType, string $identifier): array
    {
        $stmt = $this->db->prepare(
            "SELECT entity_type, entity_id, COUNT(*) AS unread_count
             FROM bb_notification
             WHERE recipient_user_type = :user_type
               AND recipient_identifier = :identifier
               AND is_read = false
             GROUP BY entity_type, entity_id
             ORDER BY entity_type, entity_id"
        );
        $stmt->execute([
            ':user_type'  => $userType,
            ':identifier' => $identifier,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Mark all notifications as read for a specific entity and recipient.
     *
     * @param string $userType   Recipient user type
     * @param string $identifier Recipient identifier
     * @param string $entityType Entity type
     * @param int    $entityId   Entity ID
     * @return int Number of rows updated
     */
    public function markAsRead(string $userType, string $identifier, string $entityType, int $entityId): int
    {
        $stmt = $this->db->prepare(
            "UPDATE bb_notification
             SET is_read = true, read_at = NOW()
             WHERE recipient_user_type = :user_type
               AND recipient_identifier = :identifier
               AND entity_type = :entity_type
               AND entity_id = :entity_id
               AND is_read = false"
        );
        $stmt->execute([
            ':user_type'   => $userType,
            ':identifier'  => $identifier,
            ':entity_type' => $entityType,
            ':entity_id'   => $entityId,
        ]);

        return $stmt->rowCount();
    }

    /**
     * Mark a single notification as read by its ID.
     *
     * @param int $notificationId
     * @return bool True if a row was updated
     */
    public function markSingleAsRead(int $notificationId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE bb_notification
             SET is_read = true, read_at = NOW()
             WHERE id = :id AND is_read = false"
        );
        $stmt->execute([':id' => $notificationId]);

        return $stmt->rowCount() > 0;
    }
}
