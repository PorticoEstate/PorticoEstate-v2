<?php

namespace App\modules\booking\repositories;

use App\Database\Db;
use App\modules\booking\models\Hospitality;
use App\modules\booking\models\HospitalityRemoteLocation;
use PDO;

class HospitalityRepository
{
    private Db $db;

    public function __construct()
    {
        $this->db = Db::getInstance();
    }

    public function getById(int $id): ?array
    {
        $sql = "SELECT h.*, r.name AS resource_name
                FROM bb_hospitality h
                LEFT JOIN bb_resource r ON h.resource_id = r.id
                WHERE h.id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getAll(bool $activeOnly = false): array
    {
        $sql = "SELECT h.*, r.name AS resource_name
                FROM bb_hospitality h
                LEFT JOIN bb_resource r ON h.resource_id = r.id";
        if ($activeOnly) {
            $sql .= " WHERE h.active = 1";
        }
        $sql .= " ORDER BY h.name";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getByResourceId(int $resourceId): array
    {
        $sql = "SELECT h.*, r.name AS resource_name
                FROM bb_hospitality h
                LEFT JOIN bb_resource r ON h.resource_id = r.id
                WHERE h.resource_id = :resource_id
                ORDER BY h.name";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':resource_id' => $resourceId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(array $data): int
    {
        $sql = "INSERT INTO bb_hospitality
                (resource_id, name, description, active, remote_serving_enabled,
                 allow_delivery, order_by_time_value, order_by_time_unit, created_by, modified_by)
                VALUES (:resource_id, :name, :description, :active, :remote_serving_enabled,
                        :allow_delivery, :order_by_time_value, :order_by_time_unit, :created_by, :modified_by)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':resource_id' => $data['resource_id'],
            ':name' => $data['name'],
            ':description' => $data['description'] ?? null,
            ':active' => $data['active'] ?? 1,
            ':remote_serving_enabled' => $data['remote_serving_enabled'] ?? 0,
            ':allow_delivery' => $data['allow_delivery'] ?? 0,
            ':order_by_time_value' => $data['order_by_time_value'] ?? null,
            ':order_by_time_unit' => $data['order_by_time_unit'] ?? null,
            ':created_by' => $data['created_by'],
            ':modified_by' => $data['created_by'],
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $updates = [];
        $params = [':id' => $id];

        $allowedFields = [
            'resource_id', 'name', 'description', 'active',
            'remote_serving_enabled', 'allow_delivery', 'order_by_time_value', 'order_by_time_unit',
        ];

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

        $sql = "UPDATE bb_hospitality SET " . implode(', ', $updates) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE bb_hospitality SET active = 0, modified = NOW() WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    // -- Remote locations --

    public function getRemoteLocations(int $hospitalityId): array
    {
        $sql = "SELECT rl.*, r.name AS resource_name
                FROM bb_hospitality_remote_location rl
                LEFT JOIN bb_resource r ON rl.resource_id = r.id
                WHERE rl.hospitality_id = :hospitality_id
                ORDER BY r.name";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':hospitality_id' => $hospitalityId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addRemoteLocation(int $hospitalityId, int $resourceId): bool
    {
        $sql = "INSERT INTO bb_hospitality_remote_location (hospitality_id, resource_id, active)
                VALUES (:hospitality_id, :resource_id, 1)
                ON CONFLICT (hospitality_id, resource_id) DO UPDATE SET active = 1";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':hospitality_id' => $hospitalityId,
            ':resource_id' => $resourceId,
        ]);
    }

    public function removeRemoteLocation(int $hospitalityId, int $resourceId): bool
    {
        $sql = "DELETE FROM bb_hospitality_remote_location
                WHERE hospitality_id = :hospitality_id AND resource_id = :resource_id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':hospitality_id' => $hospitalityId,
            ':resource_id' => $resourceId,
        ]);
    }

    public function toggleRemoteLocation(int $hospitalityId, int $resourceId, bool $active): bool
    {
        $sql = "UPDATE bb_hospitality_remote_location
                SET active = :active
                WHERE hospitality_id = :hospitality_id AND resource_id = :resource_id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':active' => $active ? 1 : 0,
            ':hospitality_id' => $hospitalityId,
            ':resource_id' => $resourceId,
        ]);
    }

    /**
     * Get all valid delivery locations for a hospitality.
     * - Main resource included only if allow_delivery = 1 (pre-order without booking the resource)
     * - Remote locations included only if remote_serving_enabled = 1
     */
    public function getDeliveryLocations(int $hospitalityId): array
    {
        $sql = "SELECT r.id, r.name, 'main' AS location_type
                FROM bb_hospitality h
                JOIN bb_resource r ON h.resource_id = r.id
                WHERE h.id = :id1
                  AND h.allow_delivery = 1
                UNION ALL
                SELECT r.id, r.name, 'remote' AS location_type
                FROM bb_hospitality_remote_location rl
                JOIN bb_resource r ON rl.resource_id = r.id
                JOIN bb_hospitality h ON rl.hospitality_id = h.id
                WHERE rl.hospitality_id = :id2
                  AND rl.active = 1
                  AND h.remote_serving_enabled = 1
                ORDER BY location_type, name";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id1' => $hospitalityId, ':id2' => $hospitalityId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
