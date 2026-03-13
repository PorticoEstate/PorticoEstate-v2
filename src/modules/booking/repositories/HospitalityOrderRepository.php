<?php

namespace App\modules\booking\repositories;

use App\Database\Db;
use App\modules\booking\models\HospitalityOrder;
use PDO;

class HospitalityOrderRepository
{
    private Db $db;

    public function __construct()
    {
        $this->db = Db::getInstance();
    }

    // -- Orders --

    public function getById(int $id): ?array
    {
        $sql = "SELECT o.*,
                       h.name AS hospitality_name,
                       r.name AS location_name
                FROM bb_hospitality_order o
                LEFT JOIN bb_hospitality h ON o.hospitality_id = h.id
                LEFT JOIN bb_resource r ON o.location_resource_id = r.id
                WHERE o.id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getByApplicationId(int $applicationId): array
    {
        $sql = "SELECT o.*,
                       h.name AS hospitality_name,
                       r.name AS location_name
                FROM bb_hospitality_order o
                LEFT JOIN bb_hospitality h ON o.hospitality_id = h.id
                LEFT JOIN bb_resource r ON o.location_resource_id = r.id
                WHERE o.application_id = :application_id
                ORDER BY o.created DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':application_id' => $applicationId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Fetch orders for multiple application IDs in a single query.
     *
     * @param int[] $applicationIds
     */
    public function getByApplicationIds(array $applicationIds): array
    {
        if (empty($applicationIds)) {
            return [];
        }
        $ids = array_map('intval', $applicationIds);
        $placeholders = implode(',', $ids);
        $sql = "SELECT o.*,
                       h.name AS hospitality_name,
                       r.name AS location_name
                FROM bb_hospitality_order o
                LEFT JOIN bb_hospitality h ON o.hospitality_id = h.id
                LEFT JOIN bb_resource r ON o.location_resource_id = r.id
                WHERE o.application_id IN ({$placeholders})
                ORDER BY o.created DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getByHospitalityId(int $hospitalityId, ?string $status = null): array
    {
        $sql = "SELECT o.*,
                       h.name AS hospitality_name,
                       r.name AS location_name
                FROM bb_hospitality_order o
                LEFT JOIN bb_hospitality h ON o.hospitality_id = h.id
                LEFT JOIN bb_resource r ON o.location_resource_id = r.id
                WHERE o.hospitality_id = :hospitality_id";
        $params = [':hospitality_id' => $hospitalityId];

        if ($status !== null) {
            $sql .= " AND o.status = :status";
            $params[':status'] = $status;
        }

        $sql .= " ORDER BY o.created DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(array $data): int
    {
        $sql = "INSERT INTO bb_hospitality_order
                (application_id, hospitality_id, location_resource_id,
                 status, comment, special_requirements, serving_time_iso, created_by, modified_by)
                VALUES (:application_id, :hospitality_id, :location_resource_id,
                        :status, :comment, :special_requirements, :serving_time_iso, :created_by, :modified_by)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':application_id' => $data['application_id'],
            ':hospitality_id' => $data['hospitality_id'],
            ':location_resource_id' => $data['location_resource_id'],
            ':status' => $data['status'] ?? HospitalityOrder::STATUS_PENDING,
            ':comment' => $data['comment'] ?? null,
            ':special_requirements' => $data['special_requirements'] ?? null,
            ':serving_time_iso' => $data['serving_time_iso'] ?? null,
            ':created_by' => $data['created_by'] ?? null,
            ':modified_by' => $data['created_by'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $updates = [];
        $params = [':id' => $id];
        $allowedFields = ['application_id', 'location_resource_id', 'comment', 'special_requirements', 'serving_time_iso'];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updates[] = "{$field} = :{$field}";
                $params[":{$field}"] = $data[$field];
            }
        }

        if (empty($updates)) {
            return true;
        }

        $updates[] = "modified = NOW()";
        if (isset($data['modified_by'])) {
            $updates[] = "modified_by = :modified_by";
            $params[':modified_by'] = $data['modified_by'];
        }

        $sql = "UPDATE bb_hospitality_order SET " . implode(', ', $updates) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function updateStatus(int $id, string $status, ?int $modifiedBy = null): bool
    {
        $sql = "UPDATE bb_hospitality_order
                SET status = :status, modified = NOW(), modified_by = :modified_by
                WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':id' => $id,
            ':status' => $status,
            ':modified_by' => $modifiedBy,
        ]);
    }

    public function delete(int $id, ?int $modifiedBy = null): bool
    {
        return $this->updateStatus($id, HospitalityOrder::STATUS_CANCELLED, $modifiedBy);
    }

    // -- Order Lines --

    public function getOrderLines(int $orderId): array
    {
        $sql = "SELECT ol.*,
                       av.name AS article_name,
                       am.unit
                FROM bb_hospitality_order_line ol
                JOIN bb_hospitality_article ha ON ol.hospitality_article_id = ha.id
                JOIN bb_article_mapping am ON ha.article_mapping_id = am.id
                LEFT JOIN bb_article_view av ON am.article_id = av.id AND am.article_cat_id = av.article_cat_id
                WHERE ol.order_id = :order_id
                ORDER BY ol.id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':order_id' => $orderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addOrderLine(array $data): int
    {
        $amount = (float)($data['quantity'] ?? 1) * (float)$data['unit_price'];

        $sql = "INSERT INTO bb_hospitality_order_line
                (order_id, hospitality_article_id, quantity, unit_price, tax_code, amount, comment)
                VALUES (:order_id, :hospitality_article_id, :quantity, :unit_price, :tax_code, :amount, :comment)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':order_id' => $data['order_id'],
            ':hospitality_article_id' => $data['hospitality_article_id'],
            ':quantity' => $data['quantity'] ?? 1,
            ':unit_price' => $data['unit_price'],
            ':tax_code' => $data['tax_code'],
            ':amount' => $amount,
            ':comment' => $data['comment'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function updateOrderLine(int $lineId, array $data): bool
    {
        $updates = [];
        $params = [':id' => $lineId];

        if (array_key_exists('quantity', $data)) {
            $updates[] = "quantity = :quantity";
            $params[':quantity'] = $data['quantity'];

            // Recalculate amount if quantity changes
            if (array_key_exists('unit_price', $data)) {
                $amount = (float)$data['quantity'] * (float)$data['unit_price'];
            } else {
                // Need to fetch current unit_price
                $stmt = $this->db->prepare(
                    "SELECT unit_price FROM bb_hospitality_order_line WHERE id = :id"
                );
                $stmt->execute([':id' => $lineId]);
                $currentPrice = (float)$stmt->fetchColumn();
                $amount = (float)$data['quantity'] * $currentPrice;
            }
            $updates[] = "amount = :amount";
            $params[':amount'] = $amount;
        }

        if (array_key_exists('unit_price', $data)) {
            $updates[] = "unit_price = :unit_price";
            $params[':unit_price'] = $data['unit_price'];
        }

        if (array_key_exists('tax_code', $data)) {
            $updates[] = "tax_code = :tax_code";
            $params[':tax_code'] = $data['tax_code'];
        }

        if (array_key_exists('comment', $data)) {
            $updates[] = "comment = :comment";
            $params[':comment'] = $data['comment'];
        }

        if (empty($updates)) {
            return true;
        }

        $sql = "UPDATE bb_hospitality_order_line SET " . implode(', ', $updates) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function deleteOrderLine(int $lineId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM bb_hospitality_order_line WHERE id = :id");
        return $stmt->execute([':id' => $lineId]);
    }

    /**
     * Calculate total amount for an order from its line items.
     */
    public function calculateOrderTotal(int $orderId): float
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM bb_hospitality_order_line WHERE order_id = :order_id"
        );
        $stmt->execute([':order_id' => $orderId]);
        return (float) $stmt->fetchColumn();
    }

    /**
     * Get full order with lines, computed total, and changelog.
     */
    public function getOrderWithLines(int $orderId): ?array
    {
        $order = $this->getById($orderId);
        if (!$order) {
            return null;
        }

        $order['lines'] = $this->getOrderLines($orderId);
        $order['total_amount'] = $this->calculateOrderTotal($orderId);
        $order['changelog'] = $this->getChangelog($orderId);
        return $order;
    }

    // -- Changelog --

    public function addChangelogEntry(array $data): int
    {
        $sql = "INSERT INTO bb_hospitality_order_changelog
                (order_id, case_officer_id, booking_user_id, change_type, old_value, new_value, comment)
                VALUES (:order_id, :case_officer_id, :booking_user_id, :change_type, :old_value, :new_value, :comment)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':order_id' => $data['order_id'],
            ':case_officer_id' => $data['case_officer_id'] ?? null,
            ':booking_user_id' => $data['booking_user_id'] ?? null,
            ':change_type' => $data['change_type'],
            ':old_value' => isset($data['old_value']) ? json_encode($data['old_value']) : null,
            ':new_value' => isset($data['new_value']) ? json_encode($data['new_value']) : null,
            ':comment' => $data['comment'],
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function getChangelog(int $orderId): array
    {
        $sql = "SELECT cl.*,
                       a.account_lid AS case_officer_name,
                       bu.name AS booking_user_name
                FROM bb_hospitality_order_changelog cl
                LEFT JOIN phpgw_accounts a ON cl.case_officer_id = a.account_id
                LEFT JOIN bb_user bu ON cl.booking_user_id = bu.id
                WHERE cl.order_id = :order_id
                ORDER BY cl.changed_at DESC, cl.id DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':order_id' => $orderId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            if ($row['old_value']) {
                $row['old_value'] = json_decode($row['old_value'], true);
            }
            if ($row['new_value']) {
                $row['new_value'] = json_decode($row['new_value'], true);
            }
        }
        return $rows;
    }

    public function getOrderLineById(int $lineId): ?array
    {
        $sql = "SELECT ol.*,
                       av.name AS article_name,
                       am.unit
                FROM bb_hospitality_order_line ol
                JOIN bb_hospitality_article ha ON ol.hospitality_article_id = ha.id
                JOIN bb_article_mapping am ON ha.article_mapping_id = am.id
                LEFT JOIN bb_article_view av ON am.article_id = av.id AND am.article_cat_id = av.article_cat_id
                WHERE ol.id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $lineId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Get all orders for an application with lines and totals.
     */
    public function getOrdersWithLinesByApplication(int $applicationId): array
    {
        $orders = $this->getByApplicationId($applicationId);
        foreach ($orders as &$order) {
            $order['lines'] = $this->getOrderLines((int) $order['id']);
            $order['total_amount'] = $this->calculateOrderTotal((int) $order['id']);
        }
        return $orders;
    }

    /**
     * Get all orders for multiple applications with lines and totals.
     *
     * @param int[] $applicationIds
     */
    public function getOrdersWithLinesByApplicationIds(array $applicationIds): array
    {
        $orders = $this->getByApplicationIds($applicationIds);
        foreach ($orders as &$order) {
            $order['lines'] = $this->getOrderLines((int) $order['id']);
            $order['total_amount'] = $this->calculateOrderTotal((int) $order['id']);
        }
        return $orders;
    }
}
