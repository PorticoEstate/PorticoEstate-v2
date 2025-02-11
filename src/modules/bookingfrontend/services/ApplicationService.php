<?php

namespace App\modules\bookingfrontend\services;

use App\modules\bookingfrontend\models\Application;
use App\modules\bookingfrontend\models\Document;
use App\modules\bookingfrontend\models\helper\Date;
use App\modules\bookingfrontend\models\Resource;
use App\modules\bookingfrontend\models\Order;
use App\modules\bookingfrontend\models\OrderLine;
use App\Database\Db;
use PDO;

require_once SRC_ROOT_PATH . '/helpers/LegacyObjectHandler.php';


class ApplicationService
{
    private $db;
    private $documentService;

    public function __construct()
    {
        $this->db = Db::getInstance();
        $this->documentService = new DocumentService(Document::OWNER_APPLICATION);
    }

    public function getPartialApplications(string $session_id): array
    {
        $sql = "SELECT * FROM bb_application
                WHERE status = 'NEWPARTIAL1' AND session_id = :session_id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':session_id' => $session_id]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $applications = [];
        foreach ($results as $result) {
            $application = new Application($result);
            $application->dates = $this->fetchDates($application->id);
            $application->resources = $this->fetchResources($application->id);
            $application->orders = $this->fetchOrders($application->id);
            $application->agegroups = $this->fetchAgeGroups($application->id);
            $application->audience = $this->fetchTargetAudience($application->id);
            $application->documents = $this->fetchDocuments($application->id);
            $applications[] = $application->serialize([]);
        }

        return $applications;
    }

    private function fetchDocuments(int $application_id): array
    {
        $documents = $this->documentService->getDocumentsForId($application_id);
        return $documents;
    }

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
        foreach ($results as $result) {
            $application = new Application($result);
            $application->dates = $this->fetchDates($application->id);
            $application->resources = $this->fetchResources($application->id);
            $application->orders = $this->fetchOrders($application->id);
            $application->agegroups = $this->fetchAgeGroups($application->id);
            $application->audience = $this->fetchTargetAudience($application->id);
            $application->documents = $this->fetchDocuments($application->id);
            $applications[] = $application->serialize([]);
        }

        return $applications;
    }




    /**
     * Update all partial applications with contact and organization info
     *
     * @param string $session_id Current session ID
     * @param array $data Contact and organization information
     * @return array Updated applications
     * @throws Exception If update fails
     */
    public function checkoutPartials(string $session_id, array $data): array
    {
        try
        {
            $errors = $this->validateCheckoutData($data);
            if (!empty($errors))
            {
                throw new Exception(implode(", ", $errors));
            }

            $this->db->beginTransaction();

            $applications = $this->getPartialApplications($session_id);

            if (empty($applications))
            {
                throw new Exception('No partial applications found for checkout');
            }

            $parent_id = $data['parent_id'] ?? $applications[0]['id'];

            // Prepare base update data
            $baseUpdateData = [
                'contact_name' => $data['contactName'],
                'contact_email' => $data['contactEmail'],
                'contact_phone' => $data['contactPhone'],
                'responsible_street' => $data['street'],
                'responsible_zip_code' => $data['zipCode'],
                'responsible_city' => $data['city'],
                'name' => $data['eventTitle'],
                'organizer' => $data['organizerName'],
                'customer_identifier_type' => $data['customerType'],
                'customer_organization_number' => $data['customerType'] === 'organization_number' ? $data['organizationNumber'] : null,
                'customer_organization_name' => $data['customerType'] === 'organization_number' ? $data['organizationName'] : null,
                'modified' => date('Y-m-d H:i:s'),
                'session_id' => null
            ];

            $updatedApplications = [];
            foreach ($applications as $application)
            {
                // Check if this application should be automatically approved
                $isDirectBooking = $this->checkDirectBooking($application);

                // Prepare update data for this application
                $updateData = array_merge($baseUpdateData, [
                    'status' => $isDirectBooking ? 'ACCEPTED' : 'NEW',
                    'parent_id' => $application['id'] == $parent_id ? null : $parent_id
                ]);

                // Update application
                $this->patchApplicationMainData($updateData, $application['id']);

                // If direct booking, create corresponding event
                if ($isDirectBooking)
                {
                    $this->createEventForApplication($application);
                }

                // Send appropriate notification
                $this->sendApplicationNotification($application['id']);

                $updatedApplications[] = array_merge($application, $updateData);
            }

            $this->db->commit();
            return $updatedApplications;

        } catch (Exception $e)
        {
            $this->db->rollBack();
            throw $e;
        }
    }


    private function createEventForApplication(array $application): void
    {
        $event = array_merge($application, [
            'active' => 1,
            'application_id' => $application['id'],
            'completed' => 0,
            'is_public' => 0,
            'include_in_list' => 0,
            'reminder' => 0,
            'customer_internal' => 0
        ]);

        // Create event for each date
        foreach ($application['dates'] as $date) {
            $event['from_'] = $date['from_'];
            $event['to_'] = $date['to_'];

            $booking_boevent = CreateObject('booking.boevent');
            $receipt = $booking_boevent->so->add($event);

            // Update ID string after event creation
            $booking_boevent->so->update_id_string();
        }

        // Handle any purchase orders
        createObject('booking.sopurchase_order')->identify_purchase_order(
            $application['id'],
            $receipt['id'],
            'event'
        );
    }

    private function checkDirectBooking(array $application): bool
    {
        // First check if all resources have direct booking enabled
        $sql = "SELECT r.*, br.building_id,
            r.booking_limit_number,
            r.booking_limit_number_horizont
            FROM bb_resource r
            JOIN bb_application_resource ar ON r.id = ar.resource_id
            JOIN bb_building_resource br ON r.id = br.resource_id
            WHERE ar.application_id = :application_id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':application_id' => $application['id']]);
        $resources = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($resources as $resource) {
            // Check if direct booking is enabled and the date is valid
            if (!$resource['direct_booking'] || $resource['direct_booking'] > time()) {
                return false;
            }

            // Check booking limits for the user
            if ($resource['booking_limit_number_horizont'] > 0 &&
                $resource['booking_limit_number'] > 0 &&
                $application['customer_ssn']) {

                $limit_reached = $this->checkBookingLimit(
                    $application['session_id'],
                    $resource['id'],
                    $application['customer_ssn'],
                    $resource['booking_limit_number_horizont'],
                    $resource['booking_limit_number']
                );

                if ($limit_reached) {
                    return false;
                }
            }
        }

        // Check for collisions
        foreach ($application['dates'] as $date) {
            $collision = $this->checkCollision(
                $application['resources'],
                $date['from_'],
                $date['to_'],
                $application['session_id']
            );
            if ($collision) {
                return false;
            }
        }

        return true;
    }


    private function checkCollision(array $resources, string $from, string $to, string $session_id): bool
    {
        $resource_ids = implode(',', array_map('intval', $resources));

        $sql = "SELECT 1 FROM bb_application a
            JOIN bb_application_resource ar ON a.id = ar.application_id
            WHERE ar.resource_id IN ($resource_ids)
            AND a.status NOT IN ('REJECTED', 'NEWPARTIAL1')
            AND a.active = 1
            AND ((a.from_ BETWEEN :from_date AND :to_date)
                OR (a.to_ BETWEEN :from_date AND :to_date)
                OR (:from_date BETWEEN a.from_ AND a.to_)
                OR (:to_date BETWEEN a.from_ AND a.to_))
            AND (a.session_id IS NULL OR a.session_id != :session_id)
            LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':from_date' => $from,
            ':to_date' => $to,
            ':session_id' => $session_id
        ]);

        return (bool)$stmt->fetch();
    }

    /**
     * Helper function to check if user has too many direct bookings of type
     */
    private function checkBookingLimit(
        string $session_id,
        int $resource_id,
        string $ssn,
        int $horizon_days,
        int $limit
    ): bool {
        $sql = "SELECT COUNT(*) as count
            FROM bb_application a
            JOIN bb_application_resource ar ON a.id = ar.application_id
            WHERE ar.resource_id = :resource_id
            AND a.customer_ssn = :ssn
            AND a.created >= NOW() - INTERVAL :horizon_days DAY
            AND a.status != 'REJECTED'
            AND a.active = 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':resource_id' => $resource_id,
            ':ssn' => $ssn,
            ':horizon_days' => $horizon_days
        ]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] >= $limit;
    }



    private function validateCheckoutData(array $data): array
    {
        $errors = [];

        // Basic required field validation
        $required_fields = [
            'contactName' => 'Contact name',
            'contactEmail' => 'Contact email',
            'contactPhone' => 'Contact phone',
            'street' => 'Street address',
            'zipCode' => 'Zip code',
            'city' => 'City',
            'eventTitle' => 'Event title',
            'organizerName' => 'Organizer name',
            'customerType' => 'Customer type'
        ];

        foreach ($required_fields as $field => $label) {
            if (empty($data[$field])) {
                $errors[] = "{$label} is required";
            }
        }

        // Email validation
        if (!empty($data['contactEmail'])) {
            $validator = createObject('booking.sfValidatorEmail', array(), array(
                'invalid' => '%field% contains an invalid email'
            ));
            try {
                $validator->clean($data['contactEmail']);
            } catch (\sfValidatorError $e) {
                $errors[] = 'Invalid email format';
            }
        }

        // Zip code validation
        if (!empty($data['zipCode']) && !preg_match('/^\d{4}$/', $data['zipCode'])) {
            $errors[] = 'Invalid zip code format';
        }

        // Phone number validation
        if (!empty($data['contactPhone']) && strlen($data['contactPhone']) < 8) {
            $errors[] = 'Phone number must be at least 8 digits';
        }

        // Organization number validation if organization type
        if ($data['customerType'] === 'organization_number') {
            if (empty($data['organizationNumber'])) {
                $errors[] = 'Organization number is required for organization bookings';
            } else {
                try {
                    $validator = createObject('booking.sfValidatorNorwegianOrganizationNumber');
                    $validator->clean($data['organizationNumber']);
                } catch (\sfValidatorError $e) {
                    $errors[] = 'Invalid organization number';
                }
            }
        }

        // SSN validation if provided through POST
        if ($data['customerType'] === 'ssn' && !empty($_POST['customer_ssn'])) {
            try {
                $validator = createObject('booking.sfValidatorNorwegianSSN');
                $validator->clean($_POST['customer_ssn']);
            } catch (\sfValidatorError $e) {
                $errors[] = 'Invalid SSN';
            }
        }

        // Validate organization name is provided if organization number is provided
        if (!empty($data['organizationNumber']) && empty($data['organizationName'])) {
            $errors[] = 'Organization name is required when organization number is provided';
        }

        // Validate customer type is valid
        if (!in_array($data['customerType'], ['ssn', 'organization_number'])) {
            $errors[] = 'Invalid customer type';
        }

        // Event title and organizer name length validation
        if (strlen($data['eventTitle']) > 255) {
            $errors[] = 'Event title is too long (maximum 255 characters)';
        }
        if (strlen($data['organizerName']) > 255) {
            $errors[] = 'Organizer name is too long (maximum 255 characters)';
        }

        return $errors;
    }


    /**
     * Send notification for completed application
     */
    private function sendApplicationNotification(int $application_id): void
    {
//        $sql = "SELECT * FROM bb_application WHERE id = :id";
//        $stmt = $this->db->prepare($sql);
//        $stmt->execute([':id' => $application_id]);
        $application = $this->getFullApplication($application_id);

        if ($application) {
            // Call existing notification method from booking.boapplication
            $bo = CreateObject('booking.boapplication');
            $bo->send_notification((array) $application, true);
        }
    }

    private function fetchDates(int $application_id): array
    {
        $sql = "SELECT * FROM bb_application_date WHERE application_id = :application_id ORDER BY from_";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':application_id' => $application_id]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($dateData) {
            return (new Date($dateData))->serialize();
        }, $results);
    }

    private function fetchResources(int $application_id): array
    {
        $sql = "SELECT r.*, br.building_id
            FROM bb_resource r
            JOIN bb_application_resource ar ON r.id = ar.resource_id
            LEFT JOIN bb_building_resource br ON r.id = br.resource_id
            WHERE ar.application_id = :application_id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':application_id' => $application_id]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($resourceData) {
            return (new Resource($resourceData))->serialize();
        }, $results);
    }

    private function fetchOrders(int $application_id): array
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
        foreach ($results as $row) {
            $order_id = $row['id'];
            if (!isset($orders[$order_id])) {
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

        return array_map(function ($order) {
            return $order->serialize();
        }, array_values($orders));
    }

    public function calculateTotalSum(array $applications): float
    {
        $total_sum = 0;
        foreach ($applications as $application) {
            foreach ($application['orders'] as $order) {
                $total_sum += $order['sum'];
            }
        }
        return round($total_sum, 2);
    }

    public function deletePartial(int $id): bool
    {
        try {
            $this->db->beginTransaction();

            // Verify the application exists and belongs to the current session
            $sql = "SELECT id FROM bb_application WHERE id = :id AND status = 'NEWPARTIAL1'";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':id' => $id]);

            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                throw new Exception("Application not found or not owned by the current session");
            }

            // Delete associated data
            $this->deleteAssociatedData($id);

            // Delete the application
            $sql = "DELETE FROM bb_application WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':id' => $id]);

            $this->db->commit();
            return true;
        } catch (PDOException $e) {
            $this->db->rollBack();
            // Log the error
            error_log("Database error: " . $e->getMessage());
            throw new Exception("An error occurred while deleting the application");
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }


    private function deleteAssociatedData(int $application_id): void
    {


        $documents = $this->documentService->getDocumentsForId($application_id);
        foreach ($documents as $document) {
            $this->documentService->deleteDocument($document->id);
        }
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

        foreach ($tables as $table) {
            $column = $table === 'bb_purchase_order_line' ? 'order_id' : 'application_id';

            if ($table === 'bb_purchase_order_line') {
                $sql = "DELETE FROM $table WHERE order_id IN (SELECT id FROM bb_purchase_order WHERE application_id = :application_id)";
            } else {
                $sql = "DELETE FROM $table WHERE $column = :application_id";
            }

            $stmt = $this->db->prepare($sql);
            $stmt->execute([':application_id' => $application_id]);
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
        try {
            $this->db->beginTransaction();

            // Save main application data
            if (!empty($data['id'])) {
                $receipt = $this->updateApplication($data);
                $id = $data['id'];
            } else {
                $receipt = $this->insertApplication($data);
                $id = $receipt['id'];
                $this->update_id_string();
            }

            // Save age groups if present
            if (!empty($data['agegroups'])) {
                $this->saveApplicationAgeGroups($id, $data['agegroups']);
            }

            // Save target audience if present
            if (!empty($data['audience'])) {
                $this->saveApplicationTargetAudience($id, $data['audience']);
            }

            // Handle other related data...
            if (!empty($data['purchase_order']['lines'])) {
                $data['purchase_order']['application_id'] = $id;
                $this->savePurchaseOrder($data['purchase_order']);
            }

            if (!empty($data['resources'])) {
                $this->saveApplicationResources($id, $data['resources']);
            }

            if (!empty($data['dates'])) {
                $this->saveApplicationDates($id, $data['dates']);
            }

            $this->db->commit();
            return $id;

        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Get a partial application by ID
     *
     * @param int $id Application ID
     * @return array|null The application data or null if not found
     */
    public function getPartialApplicationById(int $id): ?array
    {
        $sql = "SELECT * FROM bb_application WHERE id = :id AND status = 'NEWPARTIAL1'";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result) {
            return null;
        }

        // Get associated resources
        $result['resources'] = $this->fetchResources($id);

        // Get associated dates
        $result['dates'] = $this->fetchDates($id);

        // Get age groups
        $result['agegroups'] = $this->fetchAgeGroups($id);

        // Get target audience
        $result['audience'] = $this->fetchTargetAudience($id);

        // Get purchase orders if any
        $result['purchase_order'] = $this->fetchOrders($id);

        return $result;
    }

    protected function generate_secret($length = 16)
    {
        return bin2hex(random_bytes($length));
    }

    public function update_id_string()
    {
        $table_name	 = "bb_application";
        $sql		 = "UPDATE $table_name SET id_string = cast(id AS varchar)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
    }

    /**
     * Insert a new application
     */
    private function insertApplication(array $data): array
    {
        $sql = "INSERT INTO bb_application (
        status, session_id, building_name,building_id,
        activity_id, contact_name, contact_email, contact_phone,
        responsible_street, responsible_zip_code, responsible_city,
        customer_identifier_type, customer_organization_number,
        created, modified, secret, owner_id, name, organizer
    ) VALUES (
        :status, :session_id, :building_name, :building_id,
        :activity_id, :contact_name, :contact_email, :contact_phone,
        :responsible_street, :responsible_zip_code, :responsible_city,
        :customer_identifier_type, :customer_organization_number,
        NOW(), NOW(), :secret, :owner_id, :name, :organizer
    )";

        $params = [
            ':status' => $data['status'],
            ':session_id' => $data['session_id'],
            ':building_name' => $data['building_name'],
            ':building_id' => $data['building_id'],
            ':activity_id' => $data['activity_id'] ?? null,
            ':contact_name' => $data['contact_name'],
            ':contact_email' => $data['contact_email'],
            ':contact_phone' => $data['contact_phone'],
            ':responsible_street' => $data['responsible_street'],
            ':responsible_zip_code' => $data['responsible_zip_code'],
            ':responsible_city' => $data['responsible_city'],
            ':customer_identifier_type' => $data['customer_identifier_type'],
            ':customer_organization_number' => $data['customer_organization_number'],
            ':secret' => $this->generate_secret(),
            ':owner_id' => $data['owner_id'],
            ':name' => $data['name'],
            ':organizer' => $data['organizer']
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
        modified = NOW()
        WHERE id = :id AND session_id = :session_id";

        $params = [
            ':id' => $data['id'],
            ':session_id' => $data['session_id'],
            ':building_name' => $data['building_name'],
            ':building_id' => $data['building_id'],
            ':activity_id' => $data['activity_id'] ?? null,
            ':contact_name' => $data['contact_name'],
            ':contact_email' => $data['contact_email'],
            ':contact_phone' => $data['contact_phone'],
            ':responsible_street' => $data['responsible_street'],
            ':responsible_zip_code' => $data['responsible_zip_code'],
            ':responsible_city' => $data['responsible_city'],
            ':customer_identifier_type' => $data['customer_identifier_type'],
            ':customer_organization_number' => $data['customer_organization_number'],
            ':organizer' => $data['organizer'],
            ':name' => $data['name']
        ];

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Save application resources
     */
    private function saveApplicationResources(int $applicationId, array $resources): void
    {
        // First delete existing resources
        $sql = "DELETE FROM bb_application_resource WHERE application_id = :application_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':application_id' => $applicationId]);

        // Then insert new ones
        $sql = "INSERT INTO bb_application_resource (application_id, resource_id)
            VALUES (:application_id, :resource_id)";
        $stmt = $this->db->prepare($sql);

        foreach ($resources as $resourceId) {
            $stmt->execute([
                ':application_id' => $applicationId,
                ':resource_id' => $resourceId
            ]);
        }
    }


    /**
     * Save application dates
     */
    private function saveApplicationDates(int $applicationId, array $dates): void
    {
        // First delete existing dates
        $sql = "DELETE FROM bb_application_date WHERE application_id = :application_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':application_id' => $applicationId]);

        // Then insert new ones
        $sql = "INSERT INTO bb_application_date (application_id, from_, to_)
            VALUES (:application_id, :from_, :to_)";
        $stmt = $this->db->prepare($sql);

        foreach ($dates as $date) {
            $stmt->execute([
                ':application_id' => $applicationId,
                ':from_' => $date['from_'],
                ':to_' => $date['to_']
            ]);
        }
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

        // Save order lines
        foreach ($purchaseOrder['lines'] as $line) {
            $this->savePurchaseOrderLine($orderId, $line);
        }
    }

    /**
     * Save purchase order line
     */
    private function savePurchaseOrderLine(int $orderId, array $line): void
    {
        $sql = "INSERT INTO bb_purchase_order_line (
        order_id, article_mapping_id, quantity,
        tax_code, ex_tax_price, parent_mapping_id
    ) VALUES (
        :order_id, :article_mapping_id, :quantity,
        :tax_code, :ex_tax_price, :parent_mapping_id
    )";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':order_id' => $orderId,
            ':article_mapping_id' => $line['article_mapping_id'],
            ':quantity' => $line['quantity'],
            ':tax_code' => $line['tax_code'],
            ':ex_tax_price' => $line['ex_tax_price'],
            ':parent_mapping_id' => $line['parent_mapping_id'] ?? null
        ]);
    }

    private function fetchAgeGroups(int $application_id): array
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

    private function fetchTargetAudience(int $application_id): array
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

    public function saveApplicationAgeGroups(int $application_id, array $agegroups): void
    {
//        $this->db->beginTransaction();
        try {
            // Delete existing age groups
            $sql = "DELETE FROM bb_application_agegroup WHERE application_id = :application_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':application_id' => $application_id]);

            // Insert new age groups
            $sql = "INSERT INTO bb_application_agegroup
                    (application_id, agegroup_id, male, female)
                    VALUES (:application_id, :agegroup_id, :male, :female)";
            $stmt = $this->db->prepare($sql);

            foreach ($agegroups as $agegroup) {
                $stmt->execute([
                    ':application_id' => $application_id,
                    ':agegroup_id' => $agegroup['agegroup_id'],
                    ':male' => $agegroup['male'],
                    ':female' => $agegroup['female']
                ]);
            }

        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function saveApplicationTargetAudience(int $application_id, array $audience_ids): void
    {
//        $this->db->beginTransaction();
        try {
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

            foreach ($audience_ids as $audience_id) {
                $stmt->execute([
                    ':application_id' => $application_id,
                    ':targetaudience_id' => $audience_id
                ]);
            }

        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
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

        if (!$result) {
            return null;
        }

        $application = new Application($result);
        $application->dates = $this->fetchDates($application->id);
        $application->resources = $this->fetchResources($application->id);
        $application->orders = $this->fetchOrders($application->id);
        $application->agegroups = $this->fetchAgeGroups($application->id);
        $application->audience = $this->fetchTargetAudience($application->id);
        $application->documents = $this->fetchDocuments($application->id);

        return $application;
    }

    /**
     * Patch an existing application with partial data
     *
     * @param array $data Partial application data
     * @throws Exception If update fails
     */
    public function patchApplication(array $data): void
    {
        try {
            $this->db->beginTransaction();

            // Handle main application data
            $this->patchApplicationMainData($data);

            // Handle resources if present (complete replacement)
            if (isset($data['resources'])) {
                $this->saveApplicationResources($data['id'], $data['resources']);
            }

            // Handle dates if present (update existing, create new)
            if (isset($data['dates'])) {
                $this->patchApplicationDates($data['id'], $data['dates']);
            }

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Update main application data
     * @param array $data The data to update
     * @param int|null $id Optional ID parameter. If not provided, uses ID from data array
     */
    private function patchApplicationMainData(array $data, ?int $id = null): void
    {
        // Use provided ID if available, otherwise fall back to data['id']
        $applicationId = $id ?? $data['id'];
        if (!$applicationId) {
            throw new Exception("No application ID provided");
        }

        // Build dynamic UPDATE query based on provided fields
        $updateFields = [];
        $params = [':id' => $applicationId];

        // List of allowed fields to update
        $allowedFields = [
            'status', 'name', 'contact_name', 'contact_email', 'contact_phone',
            'responsible_street', 'responsible_zip_code', 'responsible_city',
            'customer_identifier_type', 'customer_organization_number',
            'customer_organization_name', 'description', 'equipment', 'organizer', 'parent_id'
        ];

        foreach ($data as $field => $value) {
            if ($field !== 'id' && in_array($field, $allowedFields)) {
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

        if ($stmt->rowCount() === 0) {
            throw new Exception("Application not found or no changes made");
        }
    }
    /**
     * Patch application dates - update existing dates and create new ones
     */
    private function patchApplicationDates(int $applicationId, array $dates): void
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

        foreach ($dates as $date) {
            if (isset($date['id'])) {
                // Update existing date if it exists
                if (isset($existingDatesById[$date['id']])) {
                    $updateStmt->execute([
                        ':id' => $date['id'],
                        ':application_id' => $applicationId,
                        ':from_' => $date['from_'],
                        ':to_' => $date['to_']
                    ]);
                }
            } else {
                // Create new date
                $insertStmt->execute([
                    ':application_id' => $applicationId,
                    ':from_' => $date['from_'],
                    ':to_' => $date['to_']
                ]);
            }
        }
    }


}