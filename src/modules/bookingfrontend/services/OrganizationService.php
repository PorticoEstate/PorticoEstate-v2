<?php

namespace App\modules\bookingfrontend\services;

use App\Database\Db;
use App\modules\bookingfrontend\models\Organization;
use App\modules\bookingfrontend\helpers\UserHelper;
use Exception;
use PDO;


require_once SRC_ROOT_PATH . '/helpers/LegacyObjectHandler.php';

class OrganizationService
{
    private $db;
    private $config;
    private $userHelper;

    public function __construct()
    {
        $this->db = Db::getInstance();
        $this->config = new \App\modules\phpgwapi\services\Config('bookingfrontend');
        $this->userHelper = new UserHelper();
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
        $startedTransaction = false;
        try {
            // Reject test organization number
            if (!empty($data['organization_number']) && $data['organization_number'] == '000000000') {
                throw new Exception('Invalid organization number: test number not allowed');
            }

            // Verify organization exists in Brønnøysund
            if (!empty($data['organization_number'])) {
                $brreg_data = $this->lookupOrganization($data['organization_number']);
                if (!$brreg_data) {
                    throw new Exception('Organization not found in Brønnøysund Register');
                }

                $data = array_merge($data, $this->extractBrregData($brreg_data));
            }

            $this->preValidate($data);

            // Get first active activity as fallback if activity_id not provided (same as legacy)
            if (empty($data['activity_id'])) {
                $activities = CreateObject('booking.soactivity')->read(array('filters' => array('active' => 1)));
                $data['activity_id'] = $activities['results'][0]['id'] ?? null;
            }

            // Only start transaction if not already in one
            if (!$this->db->inTransaction()) {
                $this->db->beginTransaction();
                $startedTransaction = true;
            }

            $sql = "INSERT INTO bb_organization (
                name, shortname,
                street, zip_code, district, city,
                phone, email, homepage,
                active, activity_id,
                customer_identifier_type, customer_organization_number,
                customer_number, customer_ssn,
                organization_number,
                customer_internal, show_in_portal, description_json
            ) VALUES (
                :name, :shortname,
                :street, :zip_code, :district, :city,
                :phone, :email, :homepage,
                :active, :activity_id,
                :customer_identifier_type, :customer_organization_number,
                :customer_number, :customer_ssn,
                :organization_number,
                :customer_internal, :show_in_portal, :description_json
            )";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':name' => $data['name'] . " [ikke validert]",
                ':shortname' => substr($data['name'], 0, 11),
                ':street' => $data['street'] ?? '',
                ':zip_code' => $data['zip_code'] ?? '',
                ':district' => $data['district'] ?? 'N/A',
                ':city' => $data['city'] ?? '',
                ':phone' => $data['phone'] ?? 'N/A',
                ':email' => $data['email'] ?? 'N/A',
                ':homepage' => $data['homepage'] ?? 'N/A',
                ':active' => 1,
                ':activity_id' => $data['activity_id'] ?? null,
                ':customer_identifier_type' => $data['customer_identifier_type'] ?? 'organization_number',
                ':customer_organization_number' => $data['organization_number'] ?? null,
                ':customer_number' => $data['customer_number'] ?? null,
                ':customer_ssn' => $data['customer_ssn'] ?? $_POST['customer_ssn'] ?? $this->userHelper->ssn ?? null,
                ':organization_number' => $data['organization_number'] ?? null,
                ':customer_internal' => 0,
                ':show_in_portal' => 1,
                ':description_json' => null
            ]);

            $id = $this->db->lastInsertId();

            // Handle contacts if provided (max 2)
            if (!empty($data['contacts'])) {
                $this->saveContacts($id, array_slice($data['contacts'], 0, 2));
            }

            // Only commit if we started the transaction
            if ($startedTransaction) {
                $this->db->commit();
            }

            return $id;

        } catch (Exception $e) {
            // Only rollback if we started the transaction
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
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
    public function addDelegate(int $organizationId, array $data): void
    {
        $ssn = $data['ssn'];
        $name = $data['name'] ?? '';
        $email = $data['email'] ?? '';
        $phone = $data['phone'] ?? '';
        $active = $data['active'] ?? 1;

        // Validate SSN format before encoding (same as old system)
        if (!preg_match('/^{(.+)}(.+)$/', $ssn)) {
            // Raw SSN - validate it
            try {
                $validator = createObject('booking.sfValidatorNorwegianSSN');
                $ssn = $validator->clean($ssn);
            } catch (Exception $e) {
                throw new Exception($e->getMessage());
            }
        }

        // Encode SSN using the same method as the old system
        $ssn = $this->encodeSSN($ssn);

        // Check if delegate already exists (active)
        $sql = "SELECT 1 FROM bb_delegate
                WHERE organization_id = :organization_id
                AND ssn = :ssn
                AND active = 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':organization_id' => $organizationId,
            ':ssn' => $ssn
        ]);

        if (!$stmt->fetch()) {
            // Check if inactive delegate exists and reactivate
            $sql = "SELECT id FROM bb_delegate
                    WHERE organization_id = :organization_id
                    AND ssn = :ssn
                    AND active = 0";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':organization_id' => $organizationId,
                ':ssn' => $ssn
            ]);

            $existing = $stmt->fetch();
            if ($existing) {
                // Reactivate existing delegate and update details
                $sql = "UPDATE bb_delegate SET active = :active, name = :name, email = :email, phone = :phone WHERE id = :id";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([
                    ':id' => $existing['id'],
                    ':active' => $active,
                    ':name' => $name,
                    ':email' => $email,
                    ':phone' => $phone
                ]);
            } else {
                // Create new delegate
                $sql = "INSERT INTO bb_delegate (organization_id, ssn, name, email, phone, active)
                        VALUES (:organization_id, :ssn, :name, :email, :phone, :active)";

                $stmt = $this->db->prepare($sql);
                $stmt->execute([
                    ':organization_id' => $organizationId,
                    ':ssn' => $ssn,
                    ':name' => $name,
                    ':email' => $email,
                    ':phone' => $phone,
                    ':active' => $active
                ]);
            }
        }
    }

    /**
     * Remove a delegate from an organization
     *
     * @param int $organizationId The ID of the organization
     * @param string $ssn Norwegian social security number of the delegate
     * @throws Exception If database operation fails
     */
    public function removeDelegate(int $organizationId, string $ssn): void
    {
        $sql = "UPDATE bb_delegate
                SET active = 0
                WHERE organization_id = :organization_id
                AND ssn = :ssn";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':organization_id' => $organizationId,
            ':ssn' => $ssn
        ]);

        if ($stmt->rowCount() === 0) {
            throw new Exception('Delegate not found or already inactive');
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

        $organizations = [];

        // First add organizations where user has active delegate access
        if ($this->userHelper->ssn) {
            $encodedSSN = $this->encodeSSN($this->userHelper->ssn);

            $sql = "SELECT o.*, true as is_delegate
                    FROM bb_organization o
                    INNER JOIN bb_delegate d ON o.id = d.organization_id
                    WHERE (d.ssn = :ssn OR d.ssn = :encoded_ssn)
                    AND d.active = 1";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':ssn' => $this->userHelper->ssn,
                ':encoded_ssn' => $encodedSSN
            ]);
            $organizations = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        // Then add organizations where user is the direct owner (by SSN)
        if ($this->userHelper->ssn) {
            $sql = "SELECT *, false as is_delegate
                FROM bb_organization
                WHERE customer_ssn = :ssn";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([':ssn' => $this->userHelper->ssn]);
            $owned_orgs = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Merge owned organizations, avoiding duplicates
            foreach ($owned_orgs as $owned_org) {
                $found = false;
                foreach ($organizations as $existing) {
                    if ($existing['id'] == $owned_org['id']) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $organizations[] = $owned_org;
                }
            }
        }

        // Sort by name
        usort($organizations, function($a, $b) {
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
        if (!$this->userHelper->is_logged_in()) {
            return false;
        }

        // Check if user owns the organization directly by SSN (direct owner access)
        if ($this->userHelper->ssn) {
            $sql = "SELECT 1 FROM bb_organization
                    WHERE id = :org_id AND customer_ssn = :ssn";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':org_id' => $organizationId,
                ':ssn' => $this->userHelper->ssn
            ]);

            if ($stmt->fetch()) {
                return true; // User is direct owner
            }
        }

        // Check if user has delegate access to this organization (must be active delegate)
        if ($this->userHelper->ssn) {
            $encodedSSN = $this->encodeSSN($this->userHelper->ssn);

            $sql = "SELECT 1 FROM bb_delegate d
                    WHERE d.organization_id = :org_id
                    AND (d.ssn = :ssn OR d.ssn = :encoded_ssn)
                    AND d.active = 1";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':org_id' => $organizationId,
                ':ssn' => $this->userHelper->ssn,
                ':encoded_ssn' => $encodedSSN
            ]);

            return (bool)$stmt->fetch();
        }

        return false;
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
            'shortname' => true,
            'phone' => true,
            'email' => true,
            'homepage' => true,
            'activity_id' => true,
            'show_in_portal' => true,
            'street' => true,
            'zip_code' => true,
            'city' => true,
            'description_json' => true
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

    /**
     * Get a specific organization by ID
     *
     * @param int $id Organization ID
     * @return Organization|null Organization model or null if not found
     * @throws Exception If database error occurs
     */
    public function getOrganization(int $id): ?\App\modules\bookingfrontend\models\Organization
    {
        try {
            $sql = "SELECT * FROM bb_organization WHERE id = :id";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([':id' => $id]);

            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$result) {
                return null;
            }

            return new \App\modules\bookingfrontend\models\Organization($result);

        } catch (Exception $e) {
            throw new Exception("Error fetching organization: " . $e->getMessage());
        }
    }

    /**
     * Get groups associated with an organization
     *
     * @param int $organizationId Organization ID
     * @return array List of Group models in short format
     * @throws Exception If database error occurs
     */
    public function getOrganizationGroups(int $organizationId): array
    {
        try {
            // Check if user has access to this organization (delegate or owner)
            $userHasAccess = $this->hasAccess($organizationId);

            // If user has access, show all groups (including inactive)
            // If no access, show only active groups
            $activeFilter = $userHasAccess ? '' : 'AND g.active = 1';

            $sql = "SELECT g.*
                    FROM bb_group g
                    WHERE g.organization_id = :organization_id
                    {$activeFilter}
                    ORDER BY g.active DESC, g.name";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([':organization_id' => $organizationId]);

            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Convert to Group models and include contacts
            $groups = [];
            foreach ($results as $result) {
                $group = new \App\modules\bookingfrontend\models\Group($result);

                // Fetch and populate contacts for this group
                $contactsData = $this->getGroupContacts($result['id']);
                $contacts = [];
                foreach ($contactsData as $contactData) {
                    $contacts[] = new \App\modules\bookingfrontend\models\GroupContact($contactData);
                }
                $group->contacts = $contacts;

                // Serialize group with contacts included
                $groups[] = $group->serialize(['short' => true]);
            }

            return $groups;

        } catch (Exception $e) {
            throw new Exception("Error fetching organization groups: " . $e->getMessage());
        }
    }

    /**
     * Get a specific group from an organization
     *
     * @param int $organizationId Organization ID
     * @param int $groupId Group ID
     * @return array|null Group data in short format or null if not found
     * @throws Exception If database error occurs
     */
    public function getOrganizationGroup(int $organizationId, int $groupId): ?array
    {
        try {
            // Check if user has access to this organization (delegate or owner)
            $userHasAccess = $this->hasAccess($organizationId);

            // If user has access, show group even if inactive
            // If no access, only show active groups
            $activeFilter = $userHasAccess ? '' : 'AND g.active = 1';

            $sql = "SELECT g.*
                    FROM bb_group g
                    WHERE g.organization_id = :organization_id
                    AND g.id = :group_id
                    {$activeFilter}";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':organization_id' => $organizationId,
                ':group_id' => $groupId
            ]);

            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$result) {
                return null;
            }

            // Convert to Group model and include contacts
            $group = new \App\modules\bookingfrontend\models\Group($result);

            // Fetch and populate contacts for this group
            $contactsData = $this->getGroupContacts($result['id']);
            $contacts = [];
            foreach ($contactsData as $contactData) {
                $contacts[] = new \App\modules\bookingfrontend\models\GroupContact($contactData);
            }
            $group->contacts = $contacts;

            // Return group in short format with contacts included
            return $group->serialize();

        } catch (Exception $e) {
            throw new Exception("Error fetching organization group: " . $e->getMessage());
        }
    }

    /**
     * Get buildings used by an organization (within last 300 days)
     *
     * @param int $organizationId Organization ID
     * @return array List of Building models in short format
     * @throws Exception If database error occurs
     */
    public function getOrganizationBuildings(int $organizationId): array
    {
        try {
            $sql = "SELECT DISTINCT b.*
                    FROM bb_building b
                    JOIN bb_building_resource br ON br.building_id = b.id
                    JOIN bb_resource r ON r.id = br.resource_id
                    JOIN bb_allocation_resource ar ON ar.resource_id = r.id
                    JOIN bb_allocation a ON a.id = ar.allocation_id
                    WHERE a.organization_id = :organization_id
                    AND (a.from_ - 'now'::timestamp < '300 days')
                    ORDER BY b.name";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([':organization_id' => $organizationId]);

            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Convert to Building models and return in short format
            $buildings = [];
            foreach ($results as $result) {
                $building = new \App\modules\booking\models\Building($result);
                $buildings[] = $building->serialize(['short' => true]);
            }

            return $buildings;

        } catch (Exception $e) {
            throw new Exception("Error fetching organization buildings: " . $e->getMessage());
        }
    }

    /**
     * Get delegates for an organization
     *
     * @param int $organizationId Organization ID
     * @param bool $userHasAccess Whether user has access to see sensitive data
     * @return array List of OrganizationDelegate models in short format
     * @throws Exception If database error occurs
     */
    public function getOrganizationDelegates(int $organizationId, bool $userHasAccess = false): array
    {
        try {
            $sql = "SELECT d.*
                    FROM bb_delegate d
                    WHERE d.organization_id = :organization_id
                    ORDER BY d.name";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([':organization_id' => $organizationId]);

            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Convert to OrganizationDelegate models and return in short format
            $delegates = [];
            $currentUserSSN = $this->userHelper->is_logged_in() ? $this->userHelper->ssn : null;

            foreach ($results as $result) {
                $delegate = new \App\modules\bookingfrontend\models\OrganizationDelegate($result);

                // Set is_self flag
                $delegate->is_self = $currentUserSSN && $this->ssnMatches($result['ssn'], $currentUserSSN);

                $delegates[] = $delegate->serialize(['short' => true, 'user_has_access' => $userHasAccess]);
            }

            return $delegates;

        } catch (Exception $e) {
            throw new Exception("Error fetching organization delegates: " . $e->getMessage());
        }
    }

    /**
     * Get a specific delegate for an organization
     *
     * @param int $organizationId Organization ID
     * @param int $delegateId Delegate ID
     * @return array|null OrganizationDelegate data or null if not found
     * @throws Exception If database error occurs
     */
    public function getOrganizationDelegate(int $organizationId, int $delegateId): ?array
    {
        try {
            $sql = "SELECT d.*
                    FROM bb_delegate d
                    WHERE d.organization_id = :organization_id
                    AND d.id = :delegate_id";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':organization_id' => $organizationId,
                ':delegate_id' => $delegateId
            ]);

            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$result) {
                return null;
            }

            $delegate = new \App\modules\bookingfrontend\models\OrganizationDelegate($result);
            $currentUserSSN = $this->userHelper->is_logged_in() ? $this->userHelper->ssn : null;
            $delegate->is_self = $currentUserSSN && $this->ssnMatches($result['ssn'], $currentUserSSN);

            return $delegate->serialize(['user_has_access' => true]);

        } catch (Exception $e) {
            throw new Exception("Error fetching organization delegate: " . $e->getMessage());
        }
    }

    /**
     * Update delegate details
     *
     * @param int $delegateId The ID of the delegate
     * @param array $data Updated delegate data (name, email, phone, active)
     * @throws Exception If validation fails or database error occurs
     */
    public function updateDelegate(int $delegateId, array $data): void
    {
        // Define which fields can be updated
        $allowedFields = [
            'name' => true,
            'email' => true,
            'phone' => true,
            'active' => true
        ];

        // Filter out any fields that aren't allowed to be updated
        $updateData = array_intersect_key($data, $allowedFields);
        if (empty($updateData)) {
            throw new Exception('No valid fields to update');
        }

        // Build update query dynamically
        $setClauses = [];
        $params = [':id' => $delegateId];

        foreach ($updateData as $field => $value) {
            $setClauses[] = "{$field} = :{$field}";
            $params[":{$field}"] = $value;
        }

        $sql = "UPDATE bb_delegate
                SET " . implode(', ', $setClauses) . "
                WHERE id = :id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            throw new Exception('Delegate not found or no changes made');
        }
    }

    /**
     * Soft delete a delegate (set active = false)
     *
     * @param int $delegateId The ID of the delegate
     * @throws Exception If database operation fails
     */
    public function deleteDelegate(int $delegateId): void
    {
        // Check if user is trying to delete themselves
        if ($this->userHelper->is_logged_in() && $this->userHelper->ssn) {
            $sql = "SELECT ssn FROM bb_delegate WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':id' => $delegateId]);
            $delegate = $stmt->fetch();

            if ($delegate && $this->ssnMatches($delegate['ssn'], $this->userHelper->ssn)) {
                throw new Exception('You cannot remove yourself as a delegate');
            }
        }

        $sql = "UPDATE bb_delegate SET active = 0 WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $delegateId]);

        if ($stmt->rowCount() === 0) {
            throw new Exception('Delegate not found');
        }
    }

    /**
     * Encode SSN using SHA1 hash with base64 encoding (same as old system)
     *
     * @param string $ssn Plain text SSN
     * @return string Encoded SSN in format {SHA1}base64hash
     */
    private function encodeSSN(string $ssn): string
    {
        // Check if SSN is already encoded
        if (preg_match('/^{(.+)}(.+)$/', $ssn)) {
            return $ssn; // Already encoded
        }

        // Encode using SHA1 + base64 (same as old system)
        $hash = sha1($ssn);
        return '{SHA1}' . base64_encode($hash);
    }

    /**
     * Decode an encoded SSN back to plain text (NOTE: This is not possible with SHA1 hash)
     * This method is for reference only - SHA1 is a one-way hash
     *
     * @param string $encodedSSN Encoded SSN in format {SHA1}base64hash
     * @return string|null Returns null since SHA1 cannot be decoded
     */
    private function decodeSSN(string $encodedSSN): ?string
    {
        // SHA1 is a one-way hash - cannot be decoded
        // This method exists for documentation purposes
        return null;
    }

    /**
     * Check if two SSNs match (handles both encoded and plain text)
     *
     * @param string $ssn1 First SSN (can be encoded or plain)
     * @param string $ssn2 Second SSN (can be encoded or plain)
     * @return bool True if SSNs match
     */
    private function ssnMatches(string $ssn1, string $ssn2): bool
    {
        // If both are encoded, compare directly
        if (preg_match('/^{(.+)}(.+)$/', $ssn1) && preg_match('/^{(.+)}(.+)$/', $ssn2)) {
            return $ssn1 === $ssn2;
        }

        // If one is encoded and one is plain, encode the plain one and compare
        $encoded1 = $this->encodeSSN($ssn1);
        $encoded2 = $this->encodeSSN($ssn2);

        return $encoded1 === $encoded2;
    }

    /**
     * Create a new group for an organization
     *
     * @param array $data Group data containing:
     *                    - name (required): Group name
     *                    - organization_id (required): Organization ID
     *                    - shortname: Short name (max 11 chars)
     *                    - description: Group description
     *                    - parent_id: Parent group ID
     *                    - activity_id: Activity ID
     *                    - show_in_portal: Whether to show in portal
     *                    - contacts: Array of contact information
     * @return int The ID of the newly created group
     * @throws Exception If validation fails or database error occurs
     */
    public function createGroup(array $data): int
    {
        if (empty($data['name'])) {
            throw new Exception('Group name is required');
        }

        if (empty($data['organization_id'])) {
            throw new Exception('Organization ID is required');
        }

        // Validate parent group if specified
        if (!empty($data['parent_id'])) {
            if (!$this->validateParentGroup($data['parent_id'], $data['organization_id'])) {
                throw new Exception('Invalid parent group or circular reference detected');
            }
        }

        $this->db->beginTransaction();

        try {
            $sql = "INSERT INTO bb_group (
                name, shortname, description, organization_id,
                parent_id, activity_id, active, show_in_portal
            ) VALUES (
                :name, :shortname, :description, :organization_id,
                :parent_id, :activity_id, :active, :show_in_portal
            )";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':name' => $data['name'],
                ':shortname' => !empty($data['shortname']) ? substr($data['shortname'], 0, 11) : substr($data['name'], 0, 11),
                ':description' => $data['description'] ?? null,
                ':organization_id' => $data['organization_id'],
                ':parent_id' => $data['parent_id'] ?? null,
                ':activity_id' => $data['activity_id'] ?? null,
                ':active' => 1,
                ':show_in_portal' => !empty($data['show_in_portal']) ? 1 : 0
            ]);

            $groupId = $this->db->lastInsertId();

            // Add contacts if provided (max 2)
            if (!empty($data['contacts']) && is_array($data['contacts'])) {
                $this->createGroupContacts($groupId, array_slice($data['contacts'], 0, 2));
            }

            $this->db->commit();
            return $groupId;

        } catch (Exception $e) {
            $this->db->rollBack();
            throw new Exception("Error creating group: " . $e->getMessage());
        }
    }

    /**
     * Update group details
     *
     * @param int $groupId The ID of the group
     * @param array $data Updated group data
     * @throws Exception If validation fails or database error occurs
     */
    public function updateGroup(int $groupId, array $data): void
    {
        // Define which fields can be updated
        $allowedFields = [
            'name' => true,
            'shortname' => true,
            'description' => true,
            'parent_id' => true,
            'activity_id' => true,
            'active' => true,
            'show_in_portal' => true
        ];

        // Filter out any fields that aren't allowed to be updated
        $updateData = array_intersect_key($data, $allowedFields);

        if (empty($updateData) && empty($data['contacts'])) {
            throw new Exception('No valid fields to update');
        }

        // Validate parent group if being updated
        if (isset($updateData['parent_id']) && !empty($updateData['parent_id'])) {
            $sql = "SELECT organization_id FROM bb_group WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':id' => $groupId]);
            $group = $stmt->fetch();

            if (!$group) {
                throw new Exception('Group not found');
            }

            if (!$this->validateParentGroup($updateData['parent_id'], $group['organization_id'], $groupId)) {
                throw new Exception('Invalid parent group or circular reference detected');
            }
        }

        $this->db->beginTransaction();

        try {
            // Update group if there are fields to update
            if (!empty($updateData)) {
                // Handle shortname length limit
                if (isset($updateData['shortname'])) {
                    $updateData['shortname'] = substr($updateData['shortname'], 0, 11);
                }

                // Convert boolean to int for database
                if (isset($updateData['active'])) {
                    $updateData['active'] = $updateData['active'] ? 1 : 0;
                }
                if (isset($updateData['show_in_portal'])) {
                    $updateData['show_in_portal'] = $updateData['show_in_portal'] ? 1 : 0;
                }

                $setClauses = [];
                $params = [':id' => $groupId];

                foreach ($updateData as $field => $value) {
                    $setClauses[] = "{$field} = :{$field}";
                    $params[":{$field}"] = $value;
                }

                $sql = "UPDATE bb_group SET " . implode(', ', $setClauses) . " WHERE id = :id";
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);
            }

            // Update contacts if provided
            if (isset($data['contacts']) && is_array($data['contacts'])) {
                $this->updateGroupContacts($groupId, array_slice($data['contacts'], 0, 2));
            }

            $this->db->commit();

        } catch (Exception $e) {
            $this->db->rollBack();
            throw new Exception("Error updating group: " . $e->getMessage());
        }
    }


    /**
     * Check if a group belongs to a specific organization
     *
     * @param int $groupId Group ID
     * @param int $organizationId Organization ID
     * @return bool True if group belongs to organization
     */
    public function groupBelongsToOrganization(int $groupId, int $organizationId): bool
    {
        $sql = "SELECT 1 FROM bb_group WHERE id = :group_id AND organization_id = :org_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':group_id' => $groupId,
            ':org_id' => $organizationId
        ]);

        return (bool)$stmt->fetch();
    }

    /**
     * Get contacts for a specific group
     *
     * @param int $groupId Group ID
     * @return array List of group contacts
     */
    private function getGroupContacts(int $groupId): array
    {
        $sql = "SELECT id, name, email, phone, group_id
                FROM bb_group_contact
                WHERE group_id = :group_id
                ORDER BY id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':group_id' => $groupId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    /**
     * Validate parent group to prevent circular references
     *
     * @param int $parentId Parent group ID
     * @param int $organizationId Organization ID
     * @param int|null $excludeGroupId Group ID to exclude from validation (for updates)
     * @return bool True if parent is valid
     */
    private function validateParentGroup(int $parentId, int $organizationId, ?int $excludeGroupId = null): bool
    {
        // Check if parent exists and belongs to same organization
        $sql = "SELECT parent_id FROM bb_group WHERE id = :parent_id AND organization_id = :org_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':parent_id' => $parentId,
            ':org_id' => $organizationId
        ]);

        $parent = $stmt->fetch();
        if (!$parent) {
            return false;
        }

        // Check for circular reference by traversing up the hierarchy
        $currentParentId = $parent['parent_id'];
        $visited = [$parentId];

        if ($excludeGroupId) {
            $visited[] = $excludeGroupId;
        }

        while ($currentParentId) {
            if (in_array($currentParentId, $visited)) {
                return false; // Circular reference detected
            }

            $visited[] = $currentParentId;

            $sql = "SELECT parent_id FROM bb_group WHERE id = :id AND organization_id = :org_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':id' => $currentParentId,
                ':org_id' => $organizationId
            ]);

            $currentParent = $stmt->fetch();
            if (!$currentParent) {
                break;
            }

            $currentParentId = $currentParent['parent_id'];
        }

        return true;
    }

    /**
     * Create contacts for a group
     *
     * @param int $groupId Group ID
     * @param array $contacts Array of contact data
     * @throws Exception If database error occurs
     */
    private function createGroupContacts(int $groupId, array $contacts): void
    {
        foreach ($contacts as $contact) {
            if (empty($contact['name'])) {
                continue; // Skip contacts without names
            }

            $sql = "INSERT INTO bb_group_contact (group_id, name, email, phone)
                    VALUES (:group_id, :name, :email, :phone)";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':group_id' => $groupId,
                ':name' => $contact['name'],
                ':email' => $contact['email'] ?? '',
                ':phone' => $contact['phone'] ?? ''
            ]);
        }
    }

    /**
     * Update contacts for a group
     *
     * @param int $groupId Group ID
     * @param array $contacts Array of contact data (may include IDs for updates)
     * @throws Exception If database error occurs
     */
    private function updateGroupContacts(int $groupId, array $contacts): void
    {
        // First, get existing contacts
        $sql = "SELECT id FROM bb_group_contact WHERE group_id = :group_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':group_id' => $groupId]);
        $existingIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $updatedIds = [];

        foreach ($contacts as $contact) {
            if (empty($contact['name'])) {
                continue; // Skip contacts without names
            }

            if (!empty($contact['id']) && in_array($contact['id'], $existingIds)) {
                // Update existing contact
                $sql = "UPDATE bb_group_contact
                        SET name = :name, email = :email, phone = :phone
                        WHERE id = :id AND group_id = :group_id";

                $stmt = $this->db->prepare($sql);
                $stmt->execute([
                    ':id' => $contact['id'],
                    ':group_id' => $groupId,
                    ':name' => $contact['name'],
                    ':email' => $contact['email'] ?? '',
                    ':phone' => $contact['phone'] ?? ''
                ]);

                $updatedIds[] = $contact['id'];
            } else {
                // Create new contact
                $sql = "INSERT INTO bb_group_contact (group_id, name, email, phone)
                        VALUES (:group_id, :name, :email, :phone)";

                $stmt = $this->db->prepare($sql);
                $stmt->execute([
                    ':group_id' => $groupId,
                    ':name' => $contact['name'],
                    ':email' => $contact['email'] ?? '',
                    ':phone' => $contact['phone'] ?? ''
                ]);

                $updatedIds[] = $this->db->lastInsertId();
            }
        }

        // Delete contacts that weren't included in the update
        $toDelete = array_diff($existingIds, $updatedIds);
        if (!empty($toDelete)) {
            $placeholders = str_repeat('?,', count($toDelete) - 1) . '?';
            $sql = "DELETE FROM bb_group_contact WHERE id IN ($placeholders)";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($toDelete);
        }
    }
}