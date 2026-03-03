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
                 status, comment, special_requirements, created_by, modified_by)
                VALUES (:application_id, :hospitality_id, :location_resource_id,
                        :status, :comment, :special_requirements, :created_by, :modified_by)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':application_id' => $data['application_id'],
            ':hospitality_id' => $data['hospitality_id'],
            ':location_resource_id' => $data['location_resource_id'],
            ':status' => $data['status'] ?? HospitalityOrder::STATUS_PENDING,
            ':comment' => $data['comment'] ?? null,
            ':special_requirements' => $data['special_requirements'] ?? null,
            ':created_by' => $data['created_by'] ?? null,
            ':modified_by' => $data['created_by'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $updates = [];
        $params = [':id' => $id];
        $allowedFields = ['location_resource_id', 'comment', 'special_requirements'];

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

    public function delete(int $id): bool
    {
        // Delete order lines first
        $stmt = $this->db->prepare("DELETE FROM bb_hospitality_order_line WHERE order_id = :id");
        $stmt->execute([':id' => $id]);

        $stmt = $this->db->prepare("DELETE FROM bb_hospitality_order WHERE id = :id");
        return $stmt->execute([':id' => $id]);
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
                (order_id, hospitality_article_id, quantity, unit_price, tax_code, amount)
                VALUES (:order_id, :hospitality_article_id, :quantity, :unit_price, :tax_code, :amount)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':order_id' => $data['order_id'],
            ':hospitality_article_id' => $data['hospitality_article_id'],
            ':quantity' => $data['quantity'] ?? 1,
            ':unit_price' => $data['unit_price'],
            ':tax_code' => $data['tax_code'],
            ':amount' => $amount,
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
     * Get full order with lines and computed total.
     */
    public function getOrderWithLines(int $orderId): ?array
    {
        $order = $this->getById($orderId);
        if (!$order) {
            return null;
        }

        $order['lines'] = $this->getOrderLines($orderId);
        $order['total_amount'] = $this->calculateOrderTotal($orderId);
        return $order;
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
}
