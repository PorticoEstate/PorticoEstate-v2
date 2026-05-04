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
                 allow_on_site_hospitality, include_in_checkout_payment,
                 order_by_time_value, order_by_time_unit, created_by, modified_by)
                VALUES (:resource_id, :name, :description, :active, :remote_serving_enabled,
                        :allow_on_site_hospitality, :include_in_checkout_payment,
                        :order_by_time_value, :order_by_time_unit, :created_by, :modified_by)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':resource_id' => $data['resource_id'],
            ':name' => $data['name'],
            ':description' => $data['description'] ?? null,
            ':active' => $data['active'] ?? 1,
            ':remote_serving_enabled' => $data['remote_serving_enabled'] ?? 0,
            ':allow_on_site_hospitality' => $data['allow_on_site_hospitality'] ?? 0,
            ':include_in_checkout_payment' => $data['include_in_checkout_payment'] ?? 0,
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
            'remote_serving_enabled', 'allow_on_site_hospitality', 'include_in_checkout_payment',
            'order_by_time_value', 'order_by_time_unit',
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
        $sql = "SELECT rl.*, r.name AS resource_name,
                       b.id AS building_id, b.name AS building_name
                FROM bb_hospitality_remote_location rl
                LEFT JOIN bb_resource r ON rl.resource_id = r.id
                LEFT JOIN bb_building_resource br ON r.id = br.resource_id
                LEFT JOIN bb_building b ON br.building_id = b.id
                WHERE rl.hospitality_id = :hospitality_id
                ORDER BY b.name NULLS LAST, r.name";
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
     * Get applications that have resource bookings on this hospitality's delivery locations.
     * Returns application rows with id, status, contact_name, created.
     */
    public function getRelevantApplications(int $hospitalityId): array
    {
        $sql = "SELECT DISTINCT a.id, a.status, a.contact_name, a.created
                FROM bb_application a
                JOIN bb_application_resource ar ON a.id = ar.application_id
                WHERE ar.resource_id IN (
                    -- Main resource if on-site hospitality enabled
                    SELECT r.id FROM bb_hospitality h
                    JOIN bb_resource r ON h.resource_id = r.id
                    WHERE h.id = :id1 AND h.allow_on_site_hospitality = 1
                    UNION
                    -- Active remote locations if remote serving enabled
                    SELECT rl.resource_id FROM bb_hospitality_remote_location rl
                    JOIN bb_hospitality h ON rl.hospitality_id = h.id
                    WHERE rl.hospitality_id = :id2 AND rl.active = 1 AND h.remote_serving_enabled = 1
                )
                ORDER BY a.id DESC
                LIMIT 100";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id1' => $hospitalityId, ':id2' => $hospitalityId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get all valid delivery locations for a hospitality.
     * - Main resource included only if allow_on_site_hospitality = 1 (pre-order without booking the resource)
     * - Remote locations included only if remote_serving_enabled = 1
     */
    public function getDeliveryLocations(int $hospitalityId): array
    {
        $sql = "SELECT r.id, r.name, 'main' AS location_type
                FROM bb_hospitality h
                JOIN bb_resource r ON h.resource_id = r.id
                WHERE h.id = :id1
                  AND h.allow_on_site_hospitality = 1
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

    /**
     * Find active hospitalities that serve any of the given resource IDs.
     * A resource is "served" if it's the main resource (with allow_on_site_hospitality)
     * or an active remote location (with remote_serving_enabled).
     *
     * @param int[] $resourceIds
     */
    public function getActiveByResourceIds(array $resourceIds): array
    {
        if (empty($resourceIds)) {
            return [];
        }
        $ids = array_map('intval', $resourceIds);
        $placeholders = implode(',', $ids);

        $sql = "SELECT DISTINCT h.id, h.name, h.resource_id, r.name AS resource_name,
                       h.remote_serving_enabled, h.allow_on_site_hospitality,
                       h.include_in_checkout_payment
                FROM bb_hospitality h
                LEFT JOIN bb_resource r ON h.resource_id = r.id
                WHERE h.active = 1
                  AND (
                    (h.allow_on_site_hospitality = 1 AND h.resource_id IN ({$placeholders}))
                    OR
                    (h.remote_serving_enabled = 1 AND h.id IN (
                      SELECT rl.hospitality_id FROM bb_hospitality_remote_location rl
                      WHERE rl.resource_id IN ({$placeholders}) AND rl.active = 1
                    ))
                  )
                ORDER BY h.name";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
