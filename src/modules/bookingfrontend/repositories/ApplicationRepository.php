<?php
namespace App\modules\bookingfrontend\repositories;

use App\modules\bookingfrontend\helpers\UserHelper;
use App\modules\bookingfrontend\models\Article;
use App\modules\bookingfrontend\models\User;
use PDO;
use App\Database\Db;
use App\modules\bookingfrontend\models\Application;
use App\modules\booking\models\Document;
use App\modules\bookingfrontend\models\Resource;
use App\modules\bookingfrontend\repositories\ResourceRepository;
use App\modules\bookingfrontend\models\Order;
use App\modules\bookingfrontend\models\OrderLine;
use App\modules\bookingfrontend\models\helper\Date;
use App\modules\booking\services\DocumentService;

class ApplicationRepository
{
    private $db;
    private $articleRepository;
    private $userHelper;
    private $userModel = null;
    private $resourceRepository;

    public function __construct()
    {
        $this->db = Db::getInstance();
        $this->articleRepository = new ArticleRepository();
        $this->userHelper = new UserHelper();
        // Don't initialize User model here - wait until we need it and have valid SSN
        $this->resourceRepository = new ResourceRepository();
    }

    /**
     * Get partial applications for a session
     *
     * @param string $session_id Session ID
     * @return array Array of applications
     */
    public function getPartialApplications(string $session_id): array
    {
        $sql = "SELECT * FROM bb_application
                WHERE status = 'NEWPARTIAL1' AND session_id = :session_id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':session_id' => $session_id]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $applications = [];
        foreach ($results as $result)
        {
            $application = new Application($result);
            $application->dates = $this->fetchDates($application->id);
            $application->resources = $this->fetchResources($application->id);
            $application->orders = $this->fetchOrders($application->id);
            $application->articles = $this->fetchArticles($application->id);
            $application->agegroups = $this->fetchAgeGroups($application->id);
            $application->audience = $this->fetchTargetAudience($application->id);
            $application->documents = $this->fetchDocuments($application->id);
            $applications[] = $application->serialize([]);
        }

        return $applications;
    }

    /**
     * Get applications by SSN
     *
     * @param string $ssn Social security number
     * @return array Array of applications
     */
    public function getApplicationsBySsn(string $ssn): array
    {
        $sql = "SELECT * FROM bb_application
            WHERE customer_ssn = :ssn
            AND status != 'NEWPARTIAL1'
            ORDER BY created DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':ssn' => $ssn]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $applications = [];
        foreach ($results as $result)
        {
            $application = new Application($result);
            $application->dates = $this->fetchDates($application->id);
            $application->resources = $this->fetchResources($application->id);
            $application->orders = $this->fetchOrders($application->id);
            $application->articles = $this->fetchArticles($application->id);
            $application->agegroups = $this->fetchAgeGroups($application->id);
            $application->audience = $this->fetchTargetAudience($application->id);
            $application->documents = $this->fetchDocuments($application->id);
            $applications[] = $application->serialize([]);
        }

        return $applications;
    }

    /**
     * Get applications by SSN including applications from organizations the user belongs to
     *
     * @param string $ssn Social security number
     * @param bool $includeOrganizations Whether to include organization applications
     * @return array Array of applications
     */
    public function getApplicationsBySsnAndOrganizations(string $ssn, bool $includeOrganizations = false): array
    {
        // Base query for personal applications
        $sql = "SELECT *, 'personal' as application_type FROM bb_application
            WHERE customer_ssn = :ssn
            AND status != 'NEWPARTIAL1'";

        $params = [':ssn' => $ssn];

        if ($includeOrganizations) {
            // Create User model with the provided SSN to get delegates (same as /user endpoint)
            $tempUserHelper = UserHelper::fromSSN($ssn);
            $tempUserModel = new User($tempUserHelper);
            $organizations = $tempUserModel->delegates ?? [];

            // Filter to only include active delegates
            $organizations = array_filter($organizations, function($org) {
                return !empty($org['active']);
            });

            if (!empty($organizations)) {
                $orgIds = [];
                $orgNumbers = [];

                foreach ($organizations as $org) {
                    if (!empty($org['org_id'])) {
                        $orgIds[] = $org['org_id'];
                    }
                    if (!empty($org['organization_number'])) {
                        $orgNumbers[] = $org['organization_number'];
                    }
                }

                // Add UNION to include organization applications
                if (!empty($orgIds)) {
                    $orgIdsStr = implode(',', $orgIds);
                    $sql .= " UNION SELECT *, 'organization' as application_type FROM bb_application
                        WHERE customer_organization_id IN ({$orgIdsStr})
                        AND status != 'NEWPARTIAL1'";
                }

                // Also include applications by organization number
                if (!empty($orgNumbers)) {
                    $orgNumberPlaceholders = [];
                    foreach ($orgNumbers as $index => $orgNumber) {
                        $placeholder = ":org_number_{$index}";
                        $orgNumberPlaceholders[] = $placeholder;
                        $params[$placeholder] = $orgNumber;
                    }
                    $orgNumbersStr = implode(',', $orgNumberPlaceholders);

                    $sql .= " UNION SELECT *, 'organization' as application_type FROM bb_application
                        WHERE customer_organization_number IN ({$orgNumbersStr})
                        AND status != 'NEWPARTIAL1'
                        AND (customer_ssn IS NULL OR customer_ssn != :ssn2)";

                    $params[':ssn2'] = $ssn; // Avoid duplicates
                }
            }
        }

        $sql .= " ORDER BY created DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $applications = [];
        foreach ($results as $result)
        {
            $application = new Application($result);
            $application->dates = $this->fetchDates($application->id);
            $application->resources = $this->fetchResources($application->id);
            $application->orders = $this->fetchOrders($application->id);
            $application->articles = $this->fetchArticles($application->id);
            $application->agegroups = $this->fetchAgeGroups($application->id);
            $application->audience = $this->fetchTargetAudience($application->id);
            $application->documents = $this->fetchDocuments($application->id);

            // Add metadata about application type
            $serialized = $application->serialize([]);
            $serialized['application_type'] = $result['application_type'];
            $applications[] = $serialized;
        }

        return $applications;
    }

    /**
     * Get an application by ID
     *
     * @param int $id Application ID
     * @return array|null The application data or null if not found
     */
    public function getApplicationById(int $id): ?array
    {
        $sql = "SELECT * FROM bb_application WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get a full application object with all related data
     *
     * @param int $id Application ID
     * @return Application|null The complete application data or null if not found
     */
    public function getFullApplication(int $id): ?Application
    {
        $result = $this->getApplicationById($id);

        if (!$result)
        {
            return null;
        }

        $application = new Application($result);
		$application->dates = $this->fetchDates($application->id);
		$application->resources = $this->fetchResources($application->id);
		$application->orders = $this->fetchOrders($application->id);
		$application->articles = $this->fetchArticles($application->id);
		$application->agegroups = $this->fetchAgeGroups($application->id);
		$application->audience = $this->fetchTargetAudience($application->id);
		$application->documents = $this->fetchDocuments($application->id);

        return $application;
    }

    /**
     * Delete a partial application
     *
     * @param int $id The application ID
     * @return bool True if deleted successfully
     * @throws \Exception If deletion fails
     */
    public function deletePartial(int $id): bool
    {
        try
        {
            $this->db->beginTransaction();

            // Get the application to check if it's a valid partial application
            $sql = "SELECT * FROM bb_application WHERE id = :id AND status = 'NEWPARTIAL1'";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':id' => $id]);
            $application = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$application)
            {
                throw new \Exception("Application not found or not a partial application");
            }

            // NOTE: We don't automatically clear blocks here anymore.
            // Blocks should only be cleared when we're sure the booking was successfully recorded
            // or properly rejected in the database. This prevents race conditions where blocks
            // could be prematurely cleared, allowing conflicting bookings.

            // Delete associated data
            $this->deleteAssociatedData($id);

            // Delete the application
            $sql = "DELETE FROM bb_application WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':id' => $id]);

            $this->db->commit();
            return true;
        } catch (\PDOException $e)
        {
            $this->db->rollBack();
            // Log the error
            error_log("Database error: " . $e->getMessage());
            throw new \Exception("An error occurred while deleting the application");
        } catch (\Exception $e)
        {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Delete data associated with an application
     *
     * @param int $application_id The application ID
     */
    private function deleteAssociatedData(int $application_id): void
    {
        // First, delete documents (including physical files)
        $this->deleteApplicationDocuments($application_id);

        // Order matters here due to foreign key constraints
        $tables = [
            'bb_purchase_order_line',
            'bb_purchase_order',
            'bb_application_comment',
            'bb_application_date',
            'bb_application_resource',
            'bb_application_targetaudience',
            'bb_application_agegroup'
        ];

        foreach ($tables as $table)
        {
            $column = $table === 'bb_purchase_order_line' ? 'order_id' : 'application_id';

            if ($table === 'bb_purchase_order_line')
            {
                $sql = "DELETE FROM $table WHERE order_id IN (SELECT id FROM bb_purchase_order WHERE application_id = :application_id)";
            } else
            {
                $sql = "DELETE FROM $table WHERE $column = :application_id";
            }

            $stmt = $this->db->prepare($sql);
            $stmt->execute([':application_id' => $application_id]);
        }
    }

    /**
     * Delete all documents associated with an application
     *
     * @param int $application_id The application ID
     */
    private function deleteApplicationDocuments(int $application_id): void
    {
        // Get all documents for this application
        $sql = "SELECT id FROM bb_document_application WHERE owner_id = :application_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':application_id' => $application_id]);
        $documentIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        if (!empty($documentIds)) {
            // Use DocumentService to properly delete each document (files and DB records)
            $documentService = new DocumentService(Document::OWNER_APPLICATION);

            foreach ($documentIds as $documentId) {
                try {
                    $documentService->deleteDocument($documentId);
                } catch (\Exception $e) {
                    // Log error but continue with other documents
                    error_log("Error deleting document {$documentId} for application {$application_id}: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * Save a new partial application or update an existing one
     *
     * @param array $data Application data
     * @return int The application ID
     */
    public function savePartialApplication(array $data): int
    {
        $startedTransaction = false;
        try
        {
            // Check if a transaction is already in progress
            if (!$this->db->inTransaction()) {
                $this->db->beginTransaction();
                $startedTransaction = true;
            }

            // Save main application data
            if (!empty($data['id']))
            {
                $this->updateApplication($data);
                $id = $data['id'];
            } else
            {
                $receipt = $this->insertApplication($data);
                $id = $receipt['id'];
                $this->updateIdString();
            }

            // Save age groups if present
            if (!empty($data['agegroups']))
            {
                $this->saveApplicationAgeGroups($id, $data['agegroups']);
            }

            // Save target audience if present
            if (!empty($data['audience']))
            {
                $this->saveApplicationTargetAudience($id, $data['audience']);
            }

            // Handle purchase order data
            if (!empty($data['purchase_order']['lines']))
            {
                $data['purchase_order']['application_id'] = $id;
                $this->savePurchaseOrder($data['purchase_order']);
            }

            // Process new articles format if present
            if (!empty($data['articles']))
            {
                $this->saveApplicationArticles($id, $data['articles']);
            }

            if (!empty($data['resources']))
            {
                $this->saveApplicationResources($id, $data['resources']);
            }

            if (!empty($data['dates']))
            {
                $this->saveApplicationDates($id, $data['dates']);
            }

            // Only commit if we started the transaction
            if ($startedTransaction) {
                $this->db->commit();
            }
            return $id;

        } catch (\Exception $e)
        {
            // Only rollback if we started the transaction
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Update ID string (legacy format)
     */
    public function updateIdString(): void
    {
        $sql = "UPDATE bb_application SET id_string = cast(id AS varchar)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
    }

    /**
     * Insert a new application
     *
     * @param array $data Application data
     * @return array Receipt with ID
     */
    private function insertApplication(array $data): array
    {
        $sql = "INSERT INTO bb_application (
        status, session_id, building_name,building_id,
        activity_id, contact_name, contact_email, contact_phone,
        responsible_street, responsible_zip_code, responsible_city,
        customer_identifier_type, customer_organization_number,
        created, modified, secret, owner_id, name, organizer, recurring_info,
        homepage, description, equipment
    ) VALUES (
        :status, :session_id, :building_name, :building_id,
        :activity_id, :contact_name, :contact_email, :contact_phone,
        :responsible_street, :responsible_zip_code, :responsible_city,
        :customer_identifier_type, :customer_organization_number,
        NOW(), NOW(), :secret, :owner_id, :name, :organizer, :recurring_info,
        :homepage, :description, :equipment
    )";

        $params = [
            ':status' => $data['status'],
            ':session_id' => $data['session_id'],
            ':building_name' => $data['building_name'] ?? '',
            ':building_id' => $data['building_id'] ?? null,
            ':activity_id' => $data['activity_id'] ?? null,
            ':contact_name' => $data['contact_name'],
            ':contact_email' => $data['contact_email'],
            ':contact_phone' => $data['contact_phone'],
            ':responsible_street' => $data['responsible_street'],
            ':responsible_zip_code' => $data['responsible_zip_code'],
            ':responsible_city' => $data['responsible_city'],
            ':customer_identifier_type' => $data['customer_identifier_type'],
            ':customer_organization_number' => $data['customer_organization_number'],
            ':secret' => $this->generateSecret(),
            ':owner_id' => $data['owner_id'],
            ':name' => $data['name'] ?? '',
            ':organizer' => $data['organizer'] ?? '',
            ':recurring_info' => $data['recurring_info'] ?? null,
            ':homepage' => $data['homepage'] ?? null,
            ':description' => $data['description'] ?? null,
            ':equipment' => $data['equipment'] ?? null
        ];

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return ['id' => $this->db->lastInsertId()];
    }

    /**
     * Update an existing application
     */
    private function updateApplication(array $data): void
    {
        $sql = "UPDATE bb_application SET
        building_name = :building_name,
        building_id = :building_id,
        activity_id = :activity_id,
        contact_name = :contact_name,
        contact_email = :contact_email,
        contact_phone = :contact_phone,
        responsible_street = :responsible_street,
        responsible_zip_code = :responsible_zip_code,
        responsible_city = :responsible_city,
        customer_identifier_type = :customer_identifier_type,
        customer_organization_number = :customer_organization_number,
        name = :name,
        organizer = :organizer,
        recurring_info = :recurring_info,
        homepage = :homepage,
        description = :description,
        equipment = :equipment,
        modified = NOW()
        WHERE id = :id AND session_id = :session_id";

        $params = [
            ':id' => $data['id'],
            ':session_id' => $data['session_id'],
            ':building_name' => $data['building_name'] ?? '',
            ':building_id' => $data['building_id'] ?? null,
            ':activity_id' => $data['activity_id'] ?? null,
            ':contact_name' => $data['contact_name'],
            ':contact_email' => $data['contact_email'],
            ':contact_phone' => $data['contact_phone'],
            ':responsible_street' => $data['responsible_street'],
            ':responsible_zip_code' => $data['responsible_zip_code'],
            ':responsible_city' => $data['responsible_city'],
            ':customer_identifier_type' => $data['customer_identifier_type'],
            ':customer_organization_number' => $data['customer_organization_number'],
            ':organizer' => $data['organizer'] ?? '',
            ':name' => $data['name'] ?? '',
            ':recurring_info' => $data['recurring_info'] ?? null,
            ':homepage' => $data['homepage'] ?? null,
            ':description' => $data['description'] ?? null,
            ':equipment' => $data['equipment'] ?? null
        ];

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Update application main data with partial update
     *
     * @param array $data The data to update
     * @param int|null $id Optional ID parameter. If not provided, uses ID from data array
     */
    public function patchApplicationMainData(array $data, ?int $id = null): void
    {
        // Use provided ID if available, otherwise fall back to data['id']
        $applicationId = $id ?? $data['id'];
        if (!$applicationId)
        {
            throw new \Exception("No application ID provided");
        }

        // Build dynamic UPDATE query based on provided fields
        $updateFields = [];
        $params = [':id' => $applicationId];

        // List of allowed fields to update
        $allowedFields = [
            'status', 'name', 'contact_name', 'contact_email', 'contact_phone',
            'responsible_street', 'responsible_zip_code', 'responsible_city',
            'customer_identifier_type', 'customer_organization_number',
            'customer_organization_name', 'customer_organization_id', 'description', 'equipment', 'organizer', 'parent_id', 'customer_ssn',
            'session_id', 'recurring_info'
        ];

        foreach ($data as $field => $value)
        {
            if ($field !== 'id' && in_array($field, $allowedFields))
            {
                $updateFields[] = "$field = :$field";
                $params[":$field"] = $value;
            }
        }

        // Add modified timestamp
        $updateFields[] = "modified = NOW()";

        $sql = "UPDATE bb_application SET " . implode(', ', $updateFields) .
            " WHERE id = :id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        if ($stmt->rowCount() === 0)
        {
            throw new \Exception("Application not found or no changes made");
        }
    }

    /**
     * Patch application dates - update existing dates and create new ones
     */
    public function patchApplicationDates(int $applicationId, array $dates): void
    {
        // Get existing dates
        $sql = "SELECT id, from_, to_ FROM bb_application_date WHERE application_id = :application_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':application_id' => $applicationId]);
        $existingDates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $existingDatesById = array_column($existingDates, null, 'id');

        // Prepare statements
        $updateStmt = $this->db->prepare(
            "UPDATE bb_application_date SET from_ = :from_, to_ = :to_
            WHERE id = :id AND application_id = :application_id"
        );

        $insertStmt = $this->db->prepare(
            "INSERT INTO bb_application_date (application_id, from_, to_)
            VALUES (:application_id, :from_, :to_)"
        );

        foreach ($dates as $date)
        {
            // Format dates properly for database
            $from = $this->formatDateForDatabase($date['from_']);
            $to = $this->formatDateForDatabase($date['to_']);

            if (isset($date['id']))
            {
                // Update existing date if it exists
                if (isset($existingDatesById[$date['id']]))
                {
                    $updateStmt->execute([
                        ':id' => $date['id'],
                        ':application_id' => $applicationId,
                        ':from_' => $from,
                        ':to_' => $to
                    ]);
                }
            } else
            {
                // Create new date
                $insertStmt->execute([
                    ':application_id' => $applicationId,
                    ':from_' => $from,
                    ':to_' => $to
                ]);
            }
        }
    }

    /**
     * Save application resources
     */
    public function saveApplicationResources(int $applicationId, array $resources): void
    {
        // First delete existing resources
        $sql = "DELETE FROM bb_application_resource WHERE application_id = :application_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':application_id' => $applicationId]);

        // Then insert new ones
        $sql = "INSERT INTO bb_application_resource (application_id, resource_id)
            VALUES (:application_id, :resource_id)";
        $stmt = $this->db->prepare($sql);

        foreach ($resources as $resourceId)
        {
            $stmt->execute([
                ':application_id' => $applicationId,
                ':resource_id' => $resourceId
            ]);
        }
    }

    /**
     * Save application dates
     */
    public function saveApplicationDates(int $applicationId, array $dates): void
    {
        // First delete existing dates
        $sql = "DELETE FROM bb_application_date WHERE application_id = :application_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':application_id' => $applicationId]);

        // Then insert new ones
        $sql = "INSERT INTO bb_application_date (application_id, from_, to_)
            VALUES (:application_id, :from_, :to_)";
        $stmt = $this->db->prepare($sql);

        foreach ($dates as $date)
        {
            $stmt->execute([
                ':application_id' => $applicationId,
                ':from_' => $this->formatDateForDatabase($date['from_']),
                ':to_' => $this->formatDateForDatabase($date['to_'])
            ]);
        }
    }

    /**
     * Save application age groups
     */
    public function saveApplicationAgeGroups(int $application_id, array $agegroups): void
    {
        try
        {
            // Delete existing age groups
            $sql = "DELETE FROM bb_application_agegroup WHERE application_id = :application_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':application_id' => $application_id]);

            // Insert new age groups
            $sql = "INSERT INTO bb_application_agegroup
                    (application_id, agegroup_id, male, female)
                    VALUES (:application_id, :agegroup_id, :male, :female)";
            $stmt = $this->db->prepare($sql);

            foreach ($agegroups as $agegroup)
            {
                $stmt->execute([
                    ':application_id' => $application_id,
                    ':agegroup_id' => $agegroup['agegroup_id'],
                    ':male' => $agegroup['male'],
                    ':female' => $agegroup['female']
                ]);
            }
        } catch (\Exception $e)
        {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Save application target audience
     */
    public function saveApplicationTargetAudience(int $application_id, array $audience_ids): void
    {
        try
        {
            // Delete existing target audience
            $sql = "DELETE FROM bb_application_targetaudience
                    WHERE application_id = :application_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':application_id' => $application_id]);

            // Insert new target audience
            $sql = "INSERT INTO bb_application_targetaudience
                    (application_id, targetaudience_id)
                    VALUES (:application_id, :targetaudience_id)";
            $stmt = $this->db->prepare($sql);

            foreach ($audience_ids as $audience_id)
            {
                $stmt->execute([
                    ':application_id' => $application_id,
                    ':targetaudience_id' => $audience_id
                ]);
            }
        } catch (\Exception $e)
        {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Save articles for an application using the new ArticleOrder format
     *
     * @param int $applicationId The application ID
     * @param array $articles Array of ArticleOrder objects with id, quantity, and parent_id
     */
    public function saveApplicationArticles(int $applicationId, array $articles): void
    {
        $this->articleRepository->saveArticlesForApplication($applicationId, $articles);
    }



    /**
     * Save purchase order
     */
    private function savePurchaseOrder(array $purchaseOrder): void
    {
        $sql = "INSERT INTO bb_purchase_order (
            application_id, status, customer_id
        ) VALUES (
            :application_id, :status, :customer_id
        )";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':application_id' => $purchaseOrder['application_id'],
            ':status' => $purchaseOrder['status'] ?? 0,
            ':customer_id' => $purchaseOrder['customer_id'] ?? -1
        ]);

        $orderId = $this->db->lastInsertId();

        // Save order lines using the ArticleRepository
        if (!empty($purchaseOrder['lines'])) {
            $articles = [];
            foreach ($purchaseOrder['lines'] as $line) {
                $articles[] = [
                    'id' => $line['article_mapping_id'],
                    'quantity' => $line['quantity'],
                    'parent_id' => $line['parent_mapping_id'] ?? null
                ];
            }

            // Use the ArticleRepository to save the lines
            $this->articleRepository->saveArticlesForApplication($purchaseOrder['application_id'], $articles);
        }
    }

    /**
     * Fetch dates for an application
     *
     * @param int $application_id The application ID
     * @return array Array of date data
     */
    public function fetchDates(int $application_id): array
    {
        $sql = "SELECT * FROM bb_application_date WHERE application_id = :application_id ORDER BY from_";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':application_id' => $application_id]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($dateData)
        {
            return (new Date($dateData))->serialize();
        }, $results);
    }

    /**
     * Fetch resources for an application
     *
     * @param int $application_id The application ID
     * @return array Array of resource data
     */
    public function fetchResources(int $application_id): array
    {
        $sql = "SELECT r.*, br.building_id
            FROM bb_resource r
            JOIN bb_application_resource ar ON r.id = ar.resource_id
            LEFT JOIN bb_building_resource br ON r.id = br.resource_id
            WHERE ar.application_id = :application_id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':application_id' => $application_id]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->resourceRepository->createAndSerialize($results);
    }

    /**
     * Fetch orders for an application
     *
     * @param int $application_id The application ID
     * @return array Array of order data
     */
    public function fetchOrders(int $application_id): array
    {
        $sql = "SELECT po.*, pol.*, am.unit,
                CASE WHEN r.name IS NULL THEN s.name ELSE r.name END AS name
                FROM bb_purchase_order po
                JOIN bb_purchase_order_line pol ON po.id = pol.order_id
                JOIN bb_article_mapping am ON pol.article_mapping_id = am.id
                LEFT JOIN bb_service s ON (am.article_id = s.id AND am.article_cat_id = 2)
                LEFT JOIN bb_resource r ON (am.article_id = r.id AND am.article_cat_id = 1)
                WHERE po.cancelled IS NULL AND po.application_id = :application_id
                ORDER BY pol.id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':application_id' => $application_id]);
        $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $orders = [];
        foreach ($results as $row)
        {
            $order_id = $row['id'];
            if (!isset($orders[$order_id]))
            {
                $orders[$order_id] = new Order([
                    'order_id' => $order_id,
                    'sum' => 0,
                    'lines' => []
                ]);
            }

            $line = new OrderLine($row);
            $orders[$order_id]->lines[] = $line;
            $orders[$order_id]->sum += $line->amount + $line->tax;
        }

        return array_map(function ($order)
        {
            return $order->serialize();
        }, array_values($orders));
    }

    /**
     * Fetch articles for an application in ArticleOrder format
     *
     * @param int $application_id The application ID
     * @return array Array of articles in ArticleOrder format
     */
    public function fetchArticles(int $application_id): array
    {
        return $this->articleRepository->fetchArticlesForApplication($application_id);
    }

    /**
     * Fetch age groups for an application
     *
     * @param int $application_id The application ID
     * @return array Array of age group data
     */
    public function fetchAgeGroups(int $application_id): array
    {
        $sql = "SELECT ag.*, aag.male, aag.female
                FROM bb_application_agegroup aag
                JOIN bb_agegroup ag ON aag.agegroup_id = ag.id
                WHERE aag.application_id = :application_id
                ORDER BY ag.sort";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':application_id' => $application_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Fetch target audience for an application
     *
     * @param int $application_id The application ID
     * @return array Array of target audience IDs
     */
    public function fetchTargetAudience(int $application_id): array
    {
        $sql = "SELECT ta.id
                FROM bb_application_targetaudience ata
                JOIN bb_targetaudience ta ON ata.targetaudience_id = ta.id
                WHERE ata.application_id = :application_id
                ORDER BY ta.sort";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':application_id' => $application_id]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id');
    }

    /**
     * Fetch documents for an application
     *
     * @param int $application_id The application ID
     * @return array Array of document data
     */
    public function fetchDocuments(int $application_id): array
    {
        $documentRepository = new DocumentRepository(Document::OWNER_APPLICATION);
        $documents = $documentRepository->getDocumentsForOwner($application_id);

        return array_map(function($document) {
            return $document->serialize();
        }, $documents);
    }

    /**
     * Generate a random secret
     *
     * @param int $length Length of the secret
     * @return string Generated secret
     */
    private function generateSecret(int $length = 16): string
    {
        return bin2hex(random_bytes($length));
    }

    /**
     * Helper method to ensure consistent date formatting with Oslo timezone
     *
     * @param string $dateString The date string to format
     * @return string Formatted date for database
     */
    private function formatDateForDatabase(string $dateString): string
    {
        if (strpos($dateString, 'T') !== false)
        {
            // Create a DateTime object with the UTC timezone
            $utcDate = new \DateTime($dateString, new \DateTimeZone('UTC'));

            // Convert to Oslo timezone
            $osloTz = new \DateTimeZone('Europe/Oslo');
            $utcDate->setTimezone($osloTz);

            // Format for MySQL
            $formatted = $utcDate->format('Y-m-d H:i:s');
            error_log("ApplicationRepository: formatDateForDatabase - Input: {$dateString}, Output: {$formatted}");
            return $formatted;
        }
        error_log("ApplicationRepository: formatDateForDatabase - Input (no conversion): {$dateString}");
        return $dateString; // Already in correct format
    }

    /**
     * Check for collisions between applications
     *
     * @param array $resources Array of resource IDs
     * @param string $from_ Start time
     * @param string $to_ End time
     * @param string|null $session_id Current session ID
     * @return bool True if collision found, false otherwise
     */
    public function checkCollision(array $resources, string $from_, string $to_, ?string $session_id = null): bool
    {
        $filter_block = '';
        if ($session_id)
        {
            $filter_block = " AND session_id != '{$session_id}'";
        }

        $rids = join(',', array_map("intval", $resources));
        $sql = "SELECT bb_block.id, 'block' as type
                  FROM bb_block
                  WHERE  bb_block.resource_id in ($rids)
                  AND ((bb_block.from_ <= '$from_' AND bb_block.to_ > '$from_')
                  OR (bb_block.from_ >= '$from_' AND bb_block.to_ <= '$to_')
                  OR (bb_block.from_ < '$to_' AND bb_block.to_ >= '$to_')) AND active = 1 {$filter_block}
                  UNION
                  SELECT ba.id, 'allocation' as type
                  FROM bb_allocation ba, bb_allocation_resource bar
                  WHERE active = 1
                  AND ba.id = bar.allocation_id
                  AND bar.resource_id in ($rids)
                  AND ((ba.from_ <= '$from_' AND ba.to_ > '$from_')
                  OR (ba.from_ >= '$from_' AND ba.to_ <= '$to_')
                  OR (ba.from_ < '$to_' AND ba.to_ >= '$to_'))
                  UNION
                  SELECT be.id, 'event' as type
                  FROM bb_event be, bb_event_resource ber
                  WHERE active = 1
                  AND be.id = ber.event_id
                  AND ber.resource_id in ($rids)
                  AND ((be.from_ <= '$from_' AND be.to_ > '$from_')
                  OR (be.from_ >= '$from_' AND be.to_ <= '$to_')
                  OR (be.from_ < '$to_' AND be.to_ >= '$to_'))";

        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch();

        return (bool)$result;
    }

    /**
     * Enhanced collision checking with debugging information
     *
     * @param array $resources Array of resource IDs
     * @param string $from Start time
     * @param string $to End time
     * @param string|null $session_id Current session ID
     * @return array Debug information with collision result
     */
    public function checkCollisionWithDebug(array $resources, string $from, string $to, ?string $session_id = null): array
    {
        $resourceIds = [];
        foreach ($resources as $resource) {
            if (is_array($resource) && isset($resource['id'])) {
                $resourceIds[] = (int)$resource['id'];
            } else {
                $resourceIds[] = (int)$resource;
            }
        }

        // Use the check_collision function logic
        $hasCollision = $this->checkCollision($resourceIds, $from, $to, $session_id);

        // Debug information to return
        return [
            'has_collision' => $hasCollision,
            'from' => $from,
            'to' => $to,
            'resource_ids' => $resources,
            'session_id' => $session_id
        ];
    }

    /**
     * Check if a block exists for a session and resource
     *
     * @param string $session_id The session ID
     * @param int $resource_id The resource ID
     * @param string $from Start datetime
     * @param string $to End datetime
     * @return bool True if block exists
     */
    public function checkBlockExists(string $session_id, int $resource_id, string $from, string $to): bool
    {
        $sql = "SELECT 1 FROM bb_block
            WHERE active = 1
            AND session_id = :session_id
            AND resource_id = :resource_id
            AND from_ = :from
            AND to_ = :to
            LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':session_id' => $session_id,
            ':resource_id' => $resource_id,
            ':from' => $from,
            ':to' => $to
        ]);

        return (bool)$stmt->fetch();
    }

    /**
     * Create a block for a timeslot
     *
     * @param string $session_id The session ID
     * @param int $resource_id The resource ID
     * @param string $from Start datetime
     * @param string $to End datetime
     * @return bool True if block was created
     */
    public function createBlock(string $session_id, int $resource_id, string $from, string $to): bool
    {
        try
        {
            // Check if block already exists
            if ($this->checkBlockExists($session_id, $resource_id, $from, $to))
            {
                return true;
            }

            // Create new block
            $sql = "INSERT INTO bb_block (session_id, resource_id, from_, to_, active)
                VALUES (:session_id, :resource_id, :from, :to, 1)";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':session_id' => $session_id,
                ':resource_id' => $resource_id,
                ':from' => $from,
                ':to' => $to
            ]);

            return true;
        } catch (\Exception $e)
        {
            error_log("Error creating block: " . $e->getMessage());
            return false;
        }
    }

	/**
	 * Get articles by resources without requiring an application
	 */
	public function getArticlesByResources(array $resourceIds): array
	{
		return $this->articleRepository->getArticlesByResources($resourceIds);
	}
}