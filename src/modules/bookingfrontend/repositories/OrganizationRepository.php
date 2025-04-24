<?php
namespace App\modules\bookingfrontend\repositories;

use PDO;
use App\Database\Db;
use App\modules\bookingfrontend\models\Organization;

class OrganizationRepository
{
    private $db;

    public function __construct()
    {
        $this->db = Db::getInstance();
    }

    /**
     * Get organization by ID
     *
     * @param int $id Organization ID
     * @return array|null Organization data or null if not found
     */
    public function getOrganizationById(int $id): ?array
    {
        $sql = "SELECT * FROM bb_organization WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Get organization by organization number
     *
     * @param string|null $organizationNumber Organization number
     * @return array|null Organization data or null if not found
     */
    public function getOrganizationByNumber(?string $organizationNumber): ?array
    {
        if (!$organizationNumber) {
            return null;
        }
        
        $sql = "SELECT * FROM bb_organization WHERE organization_number = :organization_number";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':organization_number' => $organizationNumber]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}