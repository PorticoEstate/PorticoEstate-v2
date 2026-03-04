<?php

namespace App\modules\booking\repositories;

use App\Database\Db;
use PDO;

class PermissionRepository
{
    private Db $db;

    public function __construct(?Db $db = null)
    {
        $this->db = $db ?? Db::getInstance();
    }

    /**
     * @return array<array{role: string}>
     */
    public function getGlobalRoles(int $subjectId): array
    {
        $sql = "SELECT role FROM bb_permission_root WHERE subject_id = :subjectId";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':subjectId' => $subjectId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<array{role: string}>
     */
    public function getObjectRoles(int $subjectId, int $objectId, string $objectType): array
    {
        $sql = "SELECT role FROM bb_permission WHERE subject_id = :subjectId AND object_id = :objectId AND object_type = :objectType";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':subjectId' => $subjectId,
            ':objectId' => $objectId,
            ':objectType' => $objectType,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getBuildingIdForResource(int $resourceId): ?int
    {
        $sql = "SELECT building_id FROM bb_building_resource WHERE resource_id = :resourceId LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':resourceId' => $resourceId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? (int)$result['building_id'] : null;
    }
}
