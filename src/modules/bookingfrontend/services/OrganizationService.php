<?php

namespace App\modules\bookingfrontend\services;

use App\modules\bookingfrontend\repositories\OrganizationRepository;
use App\modules\bookingfrontend\helpers\UserHelper;
use Exception;
use App\Database\Db;
use PDO;


require_once SRC_ROOT_PATH . '/helpers/LegacyObjectHandler.php';

class OrganizationService
{
    private OrganizationRepository $repository;
    private $db;
    private $config;
    private $userHelper;

    public function __construct()
    {
        $this->repository = new OrganizationRepository();
        $this->db = Db::getInstance();
        $this->config = new \App\modules\phpgwapi\services\Config('bookingfrontend');
        $this->userHelper = new UserHelper();
    }

    public function delegateExist($id = null)
    {
        return !!$this->repository->getDelegateById($id);
    }

    public function getOrganizationById(int $id)
    {
        if (!$this->hasAccess($id)) {
            throw new Exception('Access denied to this organization');
        }
        return $this->repository->organizationById($id);
    }

    public function getSubActivityList(int $id)
    {
        return $this->repository->getSubActivityList($id);
    }

    public function getGroupById(int $id)
    {
        return $this->repository->getGroupById($id);
    }

    public function existOrganization(int $id)
    {
        return $this->repository->partialOrganization($id);
    }
    public function existGroup(int $id)
    {
        return $this->repository->partialGroup($id);
    }
    public function existLeader(int $id)
    {
        return $this->repository->partialLeader($id);
    }

    public function getDelegateById(int $delegateId)
    {
        return $this->repository->getDelegateById($delegateId);
    }

    public function patchDelegate(int $delegateId, array $data)
    {
        return $this->repository->patchDelegate($delegateId, $data);
    }

    public function createDelegate(int $id, array $data)
    {
        $existing = $this->repository->getDelegate($data['ssn'], $id);
        if ($existing) throw new Exception('Delegate already exists');
        $delegateId = $this->repository->insertDelegate($id, $data);
        return $delegateId;
    }

    public function createGroup(int $id, array $data)
    {
        $this->db->beginTransaction();
        $group = $this->repository->insertGroup($id, (array) $data['groupData']);

        foreach ((array) $data['groupLeaders'] as $leader) {
            if ($leader['id']) {
                $leaderData = $this->repository->getGroupLeader($leader['id']);
                if (!$leaderData || $leaderData['group_id'] === $group['id']) {
                    throw new Exception();
                }
                $this->repository->insertGroupContact($group['id'], (array) $leaderData);
            } else {
                $this->repository->insertGroupContact($group['id'], (array) $leader);
            }
        }
        $this->db->commit();
        return $group;
    }

    /**
     * Create a new organization
     *
     * @param array $data Organization data containing:
     *                    - organization_number (required): Norwegian organization number
     *                    - name: Organization name
     *                    - activity_id: Activity ID
     *                    - contacts: Array of contact information
     *                    - street: Street address
     *                    - zip_code: Postal code
     *                    - city: City name
     *                    - phone: Phone number
     *                    - email: Email address
     *                    - homepage: Website URL
     * @return int The ID of the newly created organization
     * @throws Exception If organization validation fails or database error occurs
     */
    public function createOrganization(array $data): int
    {
        try {
            // Verify organization exists in Brønnøysund
            if (!empty($data['organization_number'])) {
                $brreg_data = $this->lookupOrganization($data['organization_number']);
                if (!$brreg_data) {
                    throw new Exception('Organization not found in Brønnøysund Register');
                }

                $data = array_merge($data, $this->extractBrregData($brreg_data));
            }

            $this->preValidate($data);

            $this->db->beginTransaction();

            $sql = "INSERT INTO bb_organization (
                name, shortname,
                street, zip_code, city,
                phone, email, homepage,
                active, activity_id,
                customer_identifier_type, customer_organization_number,
                customer_number, customer_ssn,
                organization_number
            ) VALUES (
                :name, :shortname,
                :street, :zip_code, :city,
                :phone, :email, :homepage,
                :active, :activity_id,
                :customer_identifier_type, :customer_organization_number,
                :customer_number, :customer_ssn,
                :organization_number
            )";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':name' => $data['name'] . " [ikke validert]",
                ':shortname' => substr($data['name'], 0, 11),
                ':street' => $data['street'] ?? '',
                ':zip_code' => $data['zip_code'] ?? '',
                ':city' => $data['city'] ?? '',
                ':phone' => $data['phone'] ?? 'N/A',
                ':email' => $data['email'] ?? 'N/A',
                ':homepage' => $data['homepage'] ?? 'N/A',
                ':active' => 1,
                ':activity_id' => $data['activity_id'] ?? null,
                ':customer_identifier_type' => $data['customer_identifier_type'] ?? 'organization_number',
                ':customer_organization_number' => $data['organization_number'] ?? null,
                ':customer_number' => $data['customer_number'] ?? null,
                ':customer_ssn' => !empty($_POST['customer_ssn']) ? $_POST['customer_ssn'] : null,
                ':organization_number' => $data['organization_number'] ?? null
            ]);

            $id = $this->db->lastInsertId();

            // Handle contacts if provided (max 2)
            if (!empty($data['contacts'])) {
                $this->saveContacts($id, array_slice($data['contacts'], 0, 2));
            }

            $this->db->commit();
            return $id;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function patchGroup(int $groupId, array $data)
    {
        // $this->db->beginTransaction();
        // $this->repository->patchGroupLeader($data['groupLeaders'][0]);
        // if ($data['groupLeaders'][1]) {
        //     if ($data['groupLeaders'][1]['id']) {
        //         $this->repository->patchGroupLeader($data['groupLeaders'][1]);
        //     } else {
        //         $this->repository->insertGroupContact($groupId, $data['groupLeaders'][1]);
        //     }
        // }
        $result = $this->repository->patchGroup($groupId, $data);
        // $this->db->commit();
        return $result;
    }

    public function deleteGroupLeader(int $groupId, int $id)
    {
        $group = $this->repository->getGroupById($groupId);
        if (!$group) {
            throw new Exception('Group not found');
        }
        $parsed = json_decode($group['data'], true);
        $leaders = $parsed['contact'];
        if (count($leaders) <= 1) {
            throw new Exception('Cannot delete last group leader');
        }
        
        return $this->repository->deleteGroupLeader($groupId, $id);
    }  
    public function addGroupLeader(int $groupId, array $data)
    {
        $group = $this->repository->getGroupById($groupId);
        if (!$group) {
            throw new Exception('Group not found');
        }
        $parsed = json_decode($group['data'], true);
        $leaders = $parsed['contact'];
        if (count($leaders) >= 2) {
            throw new Exception('Cannot add more than 2 group leaders');
        }
        
        return $this->repository->insertGroupContact($groupId, $data);
    }

    public function updateGroupLeaders(int $groupId, array $data)
    {
        $group = $this->repository->getGroupById($groupId);
        if (!$group) {
            throw new Exception('Group not found');
        }
    
        $parsed = json_decode($group['data'], true);
        $leaders = $parsed['contact'];
    
        $addedLeaders = $data['added'] ?? [];
        $deletedLeaders = $data['removed'] ?? [];

        // Validate that the total number of leaders does not exceed 2
        if (count($leaders) + count($addedLeaders) - count($deletedLeaders) > 2) {
            throw new Exception('Cannot have more than 2 group leaders');
        }
    
        $this->db->beginTransaction();
        try {
            // Add new leaders
            foreach ($addedLeaders as $leader) {
                $this->repository->insertGroupContact($groupId, $leader);
            }
    
            // Delete leaders
            foreach ($deletedLeaders as $leader) {
                $this->repository->deleteGroupLeader($groupId, $leader['id']);
            }
    
            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Perform pre-validation of organization data
     * Validates organization number and SSN if provided
     *
     * @param array &$data Organization data to validate
     * @throws Exception If validation fails
     */
    private function preValidate(&$data): void
    {
        if (!empty($data['organization_number'])) {
            $data['organization_number'] = str_replace(" ", "", $data['organization_number']);
            $validator = createObject('booking.sfValidatorNorwegianOrganizationNumber');
            $data['organization_number'] = $validator->clean($data['organization_number']);
        }

        // SSN is only validated if provided through POST
        if (!empty($_POST['customer_ssn'])) {
            $validator = createObject('booking.sfValidatorNorwegianSSN');
            $_POST['customer_ssn'] = $validator->clean($_POST['customer_ssn']);
        }
    }

    /**
     * Extract relevant data from Brønnøysund Register response
     *
     * @param array $brreg_data Raw data from Brønnøysund Register
     * @return array Extracted and formatted organization data
     */
    private function extractBrregData(array $brreg_data): array
    {
        $postadresse = $brreg_data['postadresse'] ?? $brreg_data['forretningsadresse'] ?? $brreg_data['beliggenhetsadresse'] ?? [];

        return [
            'name' => $brreg_data['navn'],
            'street' => $postadresse['adresse'][0] ?? '',
            'zip_code' => $postadresse['postnummer'] ?? '',
            'city' => $postadresse['poststed'] ?? '',
            'homepage' => $brreg_data['hjemmeside'] ?? null
        ];
    }

    /**
     * Add a delegate to an organization
     *
     * @param int $organizationId The ID of the organization
     * @param string $ssn Norwegian social security number of the delegate
     * @throws Exception If database operation fails
     */
    public function addDelegate(int $organizationId, string $ssn): void
    {
        // Check if delegate already exists
        $sql = "SELECT 1 FROM bb_delegate
                WHERE organization_id = :organization_id
                AND customer_ssn = :ssn";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':organization_id' => $organizationId,
            ':ssn' => $ssn
        ]);

        if (!$stmt->fetch()) {
            $sql = "INSERT INTO bb_delegate (organization_id, customer_ssn, active)
                    VALUES (:organization_id, :ssn, 1)";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':organization_id' => $organizationId,
                ':ssn' => $ssn
            ]);
        }
    }

    /**
     * Look up organization information in the Brønnøysund Register
     *
     * @param string $organization_number Norwegian organization number
     * @return array|null Organization data from Brønnøysund or null if not found
     */
    public function lookupOrganization(string $organization_number)
    {
        $url = "https://data.brreg.no/enhetsregisteret/api/enheter/{$organization_number}";

        $ch = curl_init();
        if ($this->config->config_data['proxy']) {
            curl_setopt($ch, CURLOPT_PROXY, $this->config->config_data['proxy']);
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'accept: application/json',
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($result, true);
        if (!$data) {
            return $this->lookupSubOrganization($organization_number);
        }

        if (!isset($data['postadresse'])) {
            $data['postadresse'] = $data['forretningsadresse'] ?? $data['beliggenhetsadresse'] ?? null;
        }

        return $data;
    }

    /**
     * Look up a sub-organization in the Brønnøysund Register
     * Used when main organization lookup fails
     *
     * @param string $organization_number Norwegian organization number
     * @return array|null Sub-organization data or null if not found
     */
    private function lookupSubOrganization(string $organization_number)
    {
        $url = "https://data.brreg.no/enhetsregisteret/api/underenheter/{$organization_number}";

        $ch = curl_init();
        if ($this->config->config_data['proxy']) {
            curl_setopt($ch, CURLOPT_PROXY, $this->config->config_data['proxy']);
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'accept: application/json',
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($result, true);
        if ($data && !isset($data['postadresse'])) {
            $data['postadresse'] = $data['forretningsadresse'] ?? $data['beliggenhetsadresse'] ?? null;
        }

        return $data;
    }



    /**
     * Get all organizations accessible by the current user
     * Includes both owned organizations and those where user is a delegate
     *
     * @return array List of organizations with their details and access type (owner/delegate)
     */
    public function getMyOrganizations(): array
    {
        if (!$this->userHelper->is_logged_in()) {
            return [];
        }

        // Get organization details for the user's delegated organizations
        $organizations = [];
        $org_numbers = [];

        // First add the organizations the user has delegate access to
        if ($this->userHelper->organizations) {
            foreach ($this->userHelper->organizations as $org) {
                if (!empty($org['orgnr'])) {
                    $org_numbers[] = $org['orgnr'];
                }
            }
        }

        if (!empty($org_numbers)) {
            $placeholders = str_repeat('?,', count($org_numbers) - 1) . '?';
            $sql = "SELECT o.*, true as is_delegate
                FROM bb_organization o
                WHERE o.organization_number IN ($placeholders)";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($org_numbers);
            $organizations = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        // Then add organizations where user is the direct owner (by SSN)
        if ($this->userHelper->ssn) {
            $sql = "SELECT *, false as is_delegate
                FROM bb_organization
                WHERE customer_ssn = ?";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$this->userHelper->ssn]);
            $owned_orgs = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $organizations = array_merge($organizations, $owned_orgs);
        }

        // Sort by name
        usort($organizations, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        return $organizations;
    }


    /**
     * Check if current user has access to an organization
     *
     * @param int $organizationId The ID of the organization to check
     * @return bool True if user has access, false otherwise
     */
    public function hasAccess(int $organizationId): bool
    {
        $this->userHelper = new UserHelper();
        if (!$this->userHelper->is_logged_in()) {
            return false;
        }

        $sql = "SELECT 1 FROM bb_organization o
                LEFT JOIN bb_delegate d ON o.id = d.organization_id
                WHERE o.id = :org_id
                AND (
                    (d.ssn = :ssn AND d.active = 1)
                    OR o.customer_ssn = :ssn
                )
                LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':org_id' => $organizationId,
            ':ssn' => $this->userHelper->ssn
        ]);

        return (bool)$stmt->fetch();
    }


    /**
     * Update organization details
     * Only allows updating of specific fields and validates access rights
     *
     * @param int $id Organization ID
     * @param array $data Updated organization data
     * @throws Exception If validation fails or user lacks permission
     */
    public function updateOrganization(int $id, array $data): void
    {
        // Define which fields can be updated
        $allowedFields = [
            'name' => true,
            'phone' => true,
            'email' => true,
            'homepage' => true,
            'description' => true
        ];

        // Filter out any fields that aren't allowed to be updated
        $updateData = array_intersect_key($data, $allowedFields);

        if (empty($updateData)) {
            throw new Exception('No valid fields to update');
        } 

        // Build update query dynamically
        $setClauses = [];
        $params = [':id' => $id];

        foreach ($updateData as $field => $value) {
            $setClauses[] = "{$field} = :{$field}";
            $params[":{$field}"] = $value;
        }

        $sql = "UPDATE bb_organization
                SET " . implode(', ', $setClauses) . "
                WHERE id = :id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            throw new Exception('Organization not found or no changes made');
        }
    }

    /**
     * Save contact information for an organization
     * Stores up to 2 contacts per organization
     *
     * @param int $organizationId The ID of the organization
     * @param array $contacts Array of contact information containing name, email, and phone
     * @throws Exception If database operation fails
     */
    private function saveContacts(int $organizationId, array $contacts): void
    {
        $sql = "INSERT INTO bb_organization_contact
                (organization_id, name, email, phone)
                VALUES (:organization_id, :name, :email, :phone)";

        $stmt = $this->db->prepare($sql);

        foreach ($contacts as $contact) {
            if (count($contacts) <= 2) { // Only allow up to 2 contacts
                $stmt->execute([
                    ':organization_id' => $organizationId,
                    ':name' => $contact['name'],
                    ':email' => $contact['email'],
                    ':phone' => $contact['phone']
                ]);
            }
        }
    }

    /**
     * Get paginated list of organizations with search
     *
     * @param int $start Start offset
     * @param int $length Number of records to return
     * @param string $query Search query
     * @return array Organizations and total count
     */
    public function getOrganizationList(int $start, int $length, string $query = ''): array
    {
        try {
            // Ensure parameters are valid
            $start = max(0, $start);
            $length = max(1, $length);

            $whereClauses = ["active = 1"];
            $params = [];

            if ($query) {
                $searchPattern = "%{$query}%";
                // Search in both organization number and name
                $whereClauses[] = "(organization_number ILIKE :search OR name ILIKE :search)";
                $params[':search'] = $searchPattern;
            }

            // Add organization number length validation
            $whereClauses[] = "length(organization_number) = 9";

            // Get total count
            $sql = "SELECT COUNT(*) as total FROM bb_organization
                WHERE " . implode(' AND ', $whereClauses);

            $stmt = $this->db->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
            $total = $stmt->fetch()['total'];

            // Get paginated results
            $sql = "SELECT id, organization_number, name, active
                FROM bb_organization
                WHERE " . implode(' AND ', $whereClauses) . "
                ORDER BY name
                LIMIT :length OFFSET :start";

            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':start', $start, PDO::PARAM_INT);
            $stmt->bindValue(':length', $length, PDO::PARAM_INT);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }

            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return [
                'results' => $results,
                'total' => $total
            ];
        } catch (Exception $e) {
            throw new Exception("Error fetching organization list: " . $e->getMessage());
        }
    }
}
