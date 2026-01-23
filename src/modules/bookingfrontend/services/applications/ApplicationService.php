<?php

namespace App\modules\bookingfrontend\services\applications;

use App\modules\bookingfrontend\helpers\UserHelper;
use App\modules\bookingfrontend\models\Application;
use App\modules\bookingfrontend\models\Document;
use App\modules\bookingfrontend\repositories\ApplicationRepository;
use App\modules\bookingfrontend\repositories\ArticleRepository;
use App\Database\Db;
use App\modules\bookingfrontend\services\DocumentService;
use App\modules\bookingfrontend\services\EventService;
use App\modules\booking\services\EmailService;
use App\modules\bookingfrontend\services\TestEmailService;
use App\modules\phpgwapi\services\Settings;
use PDO;
use Exception;

require_once SRC_ROOT_PATH . '/helpers/LegacyObjectHandler.php';


class ApplicationService
{
    private $db;
    private $documentService;
    private $emailService;
    private $userHelper;
    private $userSettings;
    public $applicationRepository; // Made public to access from controller
    private $articleRepository;

    public function __construct()
    {
        $this->db = Db::getInstance();
        $this->documentService = new DocumentService(Document::OWNER_APPLICATION);
        $this->emailService = new EmailService();
        $this->userHelper = new UserHelper();
        $this->userSettings = Settings::getInstance()->get('user');
        $this->articleRepository = new ArticleRepository();
        $this->applicationRepository = new ApplicationRepository();
    }

    /**
     * Get partial applications for a session
     *
     * @param string $session_id Session ID
     * @return array Array of applications
     */
    public function getPartialApplications(string $session_id): array
    {
        return $this->applicationRepository->getPartialApplications($session_id);
    }

    /**
     * Get applications by SSN
     *
     * @param string $ssn Social security number
     * @param bool $includeOrganizations Whether to include organization applications
     * @return array Array of applications
     */
    public function getApplicationsBySsn(string $ssn, bool $includeOrganizations = false): array
    {
        return $this->applicationRepository->getApplicationsBySsnAndOrganizations($ssn, $includeOrganizations);
    }

    /**
     * Get an application by ID
     *
     * @param int $id Application ID
     * @return array|null The application data or null if not found
     */
    public function getApplicationById(int $id): ?array
    {
        return $this->applicationRepository->getApplicationById($id);
    }

    /**
     * Get a full application object with all related data
     *
     * @param int $id Application ID
     * @return Application|null The complete application data or null if not found
     */
    public function getFullApplication(int $id): ?Application
    {
        return $this->applicationRepository->getFullApplication($id);
    }

    /**
     * Calculate total sum of applications
     *
     * @param array $applications Array of applications
     * @return float Total sum
     */
    public function calculateTotalSum(array $applications): float
    {
        $total_sum = 0;
        foreach ($applications as $application)
        {
            foreach ($application['orders'] as $order)
            {
                $total_sum += $order['sum'];
            }
        }
        return round($total_sum, 2);
    }

    /**
     * Delete a partial application
     *
     * @param int $id The application ID
     * @return bool True if deleted successfully
     * @throws Exception If deletion fails
     */
    public function deletePartial(int $id): bool
    {
        return $this->applicationRepository->deletePartial($id);
    }

    /**
     * Save a new partial application or update an existing one
     *
     * @param array $data Application data
     * @return int The application ID
     */
    public function savePartialApplication(array $data): int
    {
        return $this->applicationRepository->savePartialApplication($data);
    }

    /**
     * Patch an existing application with partial data
     *
     * @param array $data Partial application data
     * @throws Exception If update fails
     */
    public function patchApplication(array $data): void
    {
        try
        {
            $this->db->beginTransaction();

            // Handle main application data
            $this->applicationRepository->patchApplicationMainData($data);

            // Handle resources if present (complete replacement)
            if (isset($data['resources']))
            {
                $this->applicationRepository->saveApplicationResources($data['id'], $data['resources']);
            }

            // Handle dates if present (update existing, create new)
            if (isset($data['dates']))
            {
                $this->applicationRepository->patchApplicationDates($data['id'], $data['dates']);
            }

            // Handle articles if present (complete replacement)
            if (isset($data['articles']))
            {
                $this->articleRepository->saveArticlesForApplication($data['id'], $data['articles']);
            }

            // Handle agegroups if present
            if (isset($data['agegroups']))
            {
                // Transform agegroups from agegroup_id format to match saveApplicationAgeGroups
                $transformedAgegroups = array_map(function ($ag)
                {
                    return [
                        'agegroup_id' => $ag['agegroup_id'],
                        'male' => $ag['male'],
                        'female' => $ag['female'] ?? 0
                    ];
                }, $data['agegroups']);

                $this->applicationRepository->saveApplicationAgeGroups($data['id'], $transformedAgegroups);
            }

            $this->db->commit();
        } catch (Exception $e)
        {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Apply updates to main application data
     *
     * @param array $data The data to update
     * @param int|null $id Optional ID parameter. If not provided, uses ID from data array
     */
    public function patchApplicationMainData(array $data, ?int $id = null): void
    {
        $this->applicationRepository->patchApplicationMainData($data, $id);
    }

    /**
     * Update applications with contact info and validate booking limits
     *
     * @param string $session_id Current session ID
     * @param array $data Contact and organization information
     * @param bool $finalize Whether to finalize applications (remove session_id) or keep them partial
     * @return array Updated applications with contact info
     * @throws Exception If update fails
     */
    public function updateApplicationsWithContactInfo(string $session_id, array $data, bool $finalize = true): array
    {
        $errors = $this->validateCheckoutData($data);
        if (!empty($errors))
        {
            throw new Exception(implode(", ", $errors));
        }

        $startedTransaction = false;
        try
        {
            // Only start transaction if not already in one
            if (!$this->db->inTransaction()) {
                $this->db->beginTransaction();
                $startedTransaction = true;
            }

            $applications = $this->getPartialApplications($session_id);

            if (empty($applications))
            {
                throw new Exception('No partial applications found for checkout');
            }

            $resourceBookings = [];
            foreach ($applications as $application)
            {
                foreach ($application['resources'] as $resource)
                {
                    $resourceId = $resource['id'];
                    if (!isset($resourceBookings[$resourceId]))
                    {
                        $resourceBookings[$resourceId] = 0;
                    }
                    $resourceBookings[$resourceId]++;
                }
            }

            // Check booking limits for all resources
            $ssn = $this->userHelper->ssn;

            if ($ssn)
            {
                foreach ($resourceBookings as $resourceId => $count)
                {
                    // Get resource details
                    $sql = "SELECT r.name, r.booking_limit_number, r.booking_limit_number_horizont
                        FROM bb_resource r
                        WHERE r.id = :id";
                    $stmt = $this->db->prepare($sql);
                    $stmt->bindParam(':id', $resourceId, \PDO::PARAM_INT);
                    $stmt->execute();
                    $resource = $stmt->fetch(\PDO::FETCH_ASSOC);

                    if ($resource && $resource['booking_limit_number'] > 0 && $resource['booking_limit_number_horizont'] > 0)
                    {
                        // Get existing bookings count
                        $existingCount = $this->getUserBookingCount($resourceId, $ssn, $resource['booking_limit_number_horizont']);

                        // Calculate total bookings after checkout
                        $totalBookings = $existingCount + $count;

                        // Check if limit would be exceeded
                        if ($totalBookings > $resource['booking_limit_number'])
                        {
                            throw new Exception(
                                "Quantity limit exceeded for {$resource['name']}: You already have {$existingCount} " .
                                "bookings and are trying to add {$count} more, which would exceed the maximum " .
                                "of {$resource['booking_limit_number']} bookings within {$resource['booking_limit_number_horizont']} days"
                            );
                        }
                    }
                }
            }

            // Handle parent_id selection per building
            $buildingParentIds = $data['building_parent_ids'] ?? [];

            // If legacy parent_id is provided, convert to new format
            if (isset($data['parent_id']) && empty($buildingParentIds)) {
                // Find the building of the legacy parent_id and use it
                foreach ($applications as $app) {
                    if ($app['id'] == $data['parent_id'] && empty($app['recurring_info'])) {
                        $buildingParentIds[$app['building_id']] = $data['parent_id'];
                        break;
                    }
                }
            }

            // Generate fallback parent_ids per building if not provided
            if (empty($buildingParentIds)) {
                foreach ($applications as $app) {
                    if (empty($app['recurring_info'])) {
                        if (!isset($buildingParentIds[$app['building_id']])) {
                            $buildingParentIds[$app['building_id']] = $app['id'];
                        }
                    }
                }
            }

            // Validate parent_id building constraint if finalize is true
            if ($finalize && !empty($buildingParentIds)) {
                $this->validateBuildingParentIdConstraints($applications, $buildingParentIds);
            }

            // Prepare base update data
            $baseUpdateData = [
                'contact_name' => $data['contactName'],
                'contact_email' => $data['contactEmail'],
                'contact_phone' => $data['contactPhone'],
                'responsible_street' => $data['street'],
                'responsible_zip_code' => $data['zipCode'],
                'responsible_city' => $data['city'],
//                'name' => $data['eventTitle'],
                'organizer' => $data['organizerName'],
                'modified' => date('Y-m-d H:i:s')
            ];

            // Prepare customer data for checkout
            $baseUpdateData['customer_identifier_type'] = $data['customerType'];
            $baseUpdateData['customer_organization_number'] = $data['customerType'] === 'organization_number' ? $data['organizationNumber'] : null;
            $baseUpdateData['customer_organization_name'] = $data['customerType'] === 'organization_number' ? $data['organizationName'] : null;
            $baseUpdateData['customer_ssn'] = $data['customerType'] === 'ssn' ? $this->userHelper->ssn : null;

            // Handle organization ID
            if ($data['customerType'] === 'organization_number') {
                if (!empty($data['organizationId'])) {
                    // Use organization ID provided by client
                    $baseUpdateData['customer_organization_id'] = (int)$data['organizationId'];
                } elseif (!empty($data['organizationNumber'])) {
                    // Look up organization ID and name by number
                    $sql = "SELECT id, name FROM bb_organization WHERE organization_number = :org_number";
                    $stmt = $this->db->prepare($sql);
                    $stmt->bindParam(':org_number', $data['organizationNumber'], \PDO::PARAM_STR);
                    $stmt->execute();
                    $organization = $stmt->fetch(\PDO::FETCH_ASSOC);

                    if ($organization) {
                        $baseUpdateData['customer_organization_id'] = (int)$organization['id'];
                        $baseUpdateData['customer_organization_name'] = $organization['name']; // Use DB name, not client name
                    } else {
                        // Organization not found - try to create it using modern OrganizationService
                        try {
                            $organizationService = new \App\modules\bookingfrontend\services\OrganizationService();
                            $organizationData = [
                                'organization_number' => $data['organizationNumber'],
                                'name' => $data['organizationName'],
                                'customer_identifier_type' => 'organization_number',
                                'customer_organization_number' => $data['organizationNumber'],
                                'customer_ssn' => $this->userHelper->ssn,
                                'contacts' => [
                                    [
                                        'name' => $data['contactName'],
                                        'email' => $data['contactEmail'],
                                        'phone' => $data['contactPhone']
                                    ]
                                ]
                            ];

                            $organizationId = $organizationService->createOrganization($organizationData);

                            if ($organizationId) {
                                $baseUpdateData['customer_organization_id'] = (int)$organizationId;
                                // Use the name as validated/processed by OrganizationService
                                // Note: OrganizationService appends " [ikke validert]" to the name
                            }
                        } catch (Exception $e) {
                            // Log the error but continue without organization ID
                            error_log("Failed to create organization for number {$data['organizationNumber']}: " . $e->getMessage());
                        }
                    }
                }
            }

            // Only set session_id to null if finalizing applications
            if ($finalize) {
                $baseUpdateData['session_id'] = null;
            }

            $updatedApplications = [];

            foreach ($applications as $application)
            {
                // For recurring applications that already have organization data, preserve it
                $updateData = $baseUpdateData;
                // For recurring applications, preserve organization data if it was set during creation
                if (!empty($application['recurring_info']) &&
                    isset($application['customer_identifier_type']) &&
                    $application['customer_identifier_type'] === 'organization_number') {

                    // Preserve existing organization data for recurring applications
                    unset($updateData['customer_identifier_type']);
                    unset($updateData['customer_organization_number']);
                    unset($updateData['customer_organization_name']);
                    unset($updateData['customer_organization_id']);
                    unset($updateData['customer_ssn']);

                    error_log("RECURRING APP DEBUG: Preserving org data for app {$application['id']}, removed customer fields from update data");
                    error_log("RECURRING APP DEBUG: updateData keys after unset: " . implode(', ', array_keys($updateData)));
                } else {
                    error_log("RECURRING APP DEBUG: App {$application['id']} - recurring_info: " . (!empty($application['recurring_info']) ? 'yes' : 'no') .
                             ", customer_identifier_type: " . ($application['customer_identifier_type'] ?? 'null') .
                             ", customer_organization_id: " . ($application['customer_organization_id'] ?? 'null'));
                }

                $this->patchApplicationMainData($updateData, $application['id']);

                $updateData['session_id'] = $application['session_id']; // Keep session_id for partial applications

                $updatedApplications[] = array_merge($application, $updateData);
            }

            // Only commit if we started the transaction
            if ($startedTransaction) {
                $this->db->commit();
            }

            return $updatedApplications;

        } catch (Exception $e)
        {
            // Only rollback if we started the transaction
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Complete application approval after successful Vipps payment
     * This is the modern equivalent of vipps_helper->approve_application()
     *
     * @param string $remote_order_id Payment order ID from Vipps
     * @param float $amount Payment amount
     * @return array Approved applications
     * @throws Exception If approval fails
     */
    public function approveVippsPayment(string $remote_order_id, float $amount): array
    {
        try {
            $this->db->beginTransaction();

            // Get application IDs from payment order using legacy system
            $soapplication = CreateObject('booking.soapplication');
            $application_ids = $soapplication->get_application_from_payment_order($remote_order_id);

            if (empty($application_ids)) {
                throw new Exception('No applications found for payment order: ' . $remote_order_id);
            }

            $approvedApplications = [];

            // Process each application in the payment
            foreach ($application_ids as $application_id) {
                // Get application details
                $application = $this->getApplicationById($application_id);
                if (!$application) {
                    throw new Exception('Application not found: ' . $application_id);
                }

                // Update application status to ACCEPTED
                $updateData = [
                    'status' => 'ACCEPTED',
                    'session_id' => null, // Finalize application
                    'modified' => date('Y-m-d H:i:s')
                ];

                $this->patchApplicationMainData($updateData, $application_id);

                // Create events for each timeslot (this already exists and works)
                $this->createEventForApplication($application_id);

                // Cancel blocks for this session to free up resources
                $this->cancelBlocksForApplication($application_id);

                $approvedApplications[] = array_merge($application, $updateData);
            }

            // Group applications by parent_id for email notifications
            $this->sendGroupedApplicationNotifications($approvedApplications);

            // Update payment status to completed (once for all applications)
            $soapplication->update_payment_status($remote_order_id, 'completed', 'CAPTURE');

            $this->db->commit();

            return $approvedApplications;

        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Handle failed or cancelled Vipps payment
     * Clean up applications and payment records
     *
     * @param string $remote_order_id Payment order ID from Vipps
     * @param string $operation Vipps operation (CANCEL, VOID, etc.)
     * @return bool Success status
     * @throws Exception If cleanup fails
     */
    public function cancelVippsPayment(string $remote_order_id, string $operation): bool
    {
        try {
            $this->db->beginTransaction();

            $soapplication = CreateObject('booking.soapplication');
            $sopurchase_order = CreateObject('booking.sopurchase_order');

            // Get application IDs from payment order
            $application_ids = $soapplication->get_application_from_payment_order($remote_order_id);

            if (!empty($application_ids)) {
                foreach ($application_ids as $application_id) {
                    // Cancel blocks for this application to free up resources
                    $this->cancelBlocksForApplication($application_id);

                    // Delete purchase orders
                    $sopurchase_order->delete_purchase_order($application_id);

                    // Delete the application
                    $soapplication->delete_application($application_id);
                }

                // Update payment status to voided once for all applications
                $soapplication->update_payment_status($remote_order_id, 'voided', $operation);
            }

            $this->db->commit();

            // Set cancelled message
            \App\modules\phpgwapi\services\Cache::message_set('cancelled');

            return true;

        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Update payment status for an order
     * Wrapper around legacy soapplication method
     *
     * @param string $remote_order_id Payment order ID
     * @param string $status New status (pending, completed, voided)
     * @param string $operation Vipps operation
     * @return bool Success status
     */
    public function updatePaymentStatus(string $remote_order_id, string $status, string $operation): bool
    {
        try {
            $soapplication = CreateObject('booking.soapplication');
            $soapplication->update_payment_status($remote_order_id, $status, $operation);
            return true;
        } catch (Exception $e) {
            error_log("Error updating payment status: " . $e->getMessage());
            return false;
        }
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
            $this->db->beginTransaction();

            // First update all applications with contact info and validate limits
            $updatedApplications = $this->updateApplicationsWithContactInfo($session_id, $data);

            // Handle parent_id selection per building (same logic as updateApplicationsWithContactInfo)
            $buildingParentIds = $data['building_parent_ids'] ?? [];

            // If legacy parent_id is provided, convert to new format
            if (isset($data['parent_id']) && empty($buildingParentIds)) {
                foreach ($updatedApplications as $app) {
                    if ($app['id'] == $data['parent_id'] && empty($app['recurring_info'])) {
                        $buildingParentIds[$app['building_id']] = $data['parent_id'];
                        break;
                    }
                }
            }

            // Generate fallback parent_ids per building if not provided
            if (empty($buildingParentIds)) {
                foreach ($updatedApplications as $app) {
                    if (empty($app['recurring_info'])) {
                        if (!isset($buildingParentIds[$app['building_id']])) {
                            $buildingParentIds[$app['building_id']] = $app['id'];
                        }
                    }
                }
            }

            // Validate parent_id building constraints
            if (!empty($buildingParentIds)) {
                $this->validateBuildingParentIdConstraints($updatedApplications, $buildingParentIds);
            }
            $finalUpdatedApplications = [];
            $skippedApplications = [];
            $collisionDebugInfo = []; // Debug information for collisions

            foreach ($updatedApplications as $application)
            {
                // First check if eligible for direct booking without checking collisions
                $isEligibleForDirectBooking = $this->isEligibleForDirectBooking($application);

                if ($isEligibleForDirectBooking)
                {
                    // Check for collisions separately with detailed debug info
                    $hasCollision = false;
                    $applicationCollisionInfo = [];

                    foreach ($application['dates'] as $date)
                    {
                        $collisionCheck = $this->applicationRepository->checkCollisionWithDebug(
                            $application['resources'],
                            $date['from_'],
                            $date['to_'],
                            $application['session_id']
                        );

                        if ($collisionCheck['has_collision']) {
                            $hasCollision = true;
                            $applicationCollisionInfo[] = $collisionCheck;
                        }
                    }

                    // If direct booking eligible but has collision, reject it and don't continue with it
                    if ($hasCollision)
                    {
                        $collisionDebugInfo[$application['id']] = $applicationCollisionInfo;

                        // Reject the application with collision
                        $updateData = [
                            'status' => 'REJECTED',
                            // Don't set parent_id for recurring applications - they are processed individually
                            'parent_id' => (!empty($application['recurring_info'])) ? null : ($buildingParentIds[$application['building_id']] ?? null)
                        ];

                        $this->patchApplicationMainData($updateData, $application['id']);
                        $skippedApplications[] = array_merge($application, $updateData);

                        // Skip sending notification and adding to updated list
                        continue;
                    }
                    else
                    {
                        // No collision - check if it's a repeating application
                        // Repeating direct bookings should be sent for review, not auto-accepted
                        if (!empty($application['recurring_info']))
                        {
                            // Treat as normal repeating application - sent for review
                            $updateData = [
                                'status' => 'NEW',
                                'parent_id' => null // Don't set parent_id for recurring applications
                            ];
                            $this->patchApplicationMainData($updateData, $application['id']);
                        }
                        else
                        {
                            // Proceed with direct booking auto-acceptance
                            $updateData = [
                                'status' => 'ACCEPTED',
                                'parent_id' => $buildingParentIds[$application['building_id']] ?? null
                            ];
                            $this->patchApplicationMainData($updateData, $application['id']);
                            $this->createEventForApplication($application['id']);
                        }
                    }
                } else
                {
                    // Not eligible for direct booking - process normally
                    $updateData = [
                        'status' => 'NEW',
                        // Don't set parent_id for recurring applications - they are processed individually
                        'parent_id' => (!empty($application['recurring_info'])) ? null : ($buildingParentIds[$application['building_id']] ?? null)
                    ];

                    $this->patchApplicationMainData($updateData, $application['id']);
                }

                // Add to updated list but don't send notification yet
                $finalUpdatedApplications[] = array_merge($application, $updateData);
            }

            // Group applications by parent_id for email notifications
            $this->sendGroupedApplicationNotifications($finalUpdatedApplications);

            $this->db->commit();
            return [
                'updated' => $finalUpdatedApplications,
                'skipped' => $skippedApplications,
                'debug_collisions' => $collisionDebugInfo
            ];

        } catch (Exception $e)
        {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Create events for an application that has been accepted for direct booking
     */
    private function createEventForApplication(int $applicationId): void
    {
        $eventService = new EventService();
        $lastEventId = null;

        $startedTransaction = false;
        try
        {
            // Check if a transaction is already in progress
            if (!$this->db->inTransaction())
            {
                $this->db->beginTransaction();
                $startedTransaction = true;
            }

            // Fetch the most up-to-date application data
            $application = $this->getFullApplication($applicationId);

            if (!$application) {
                throw new Exception("Application not found with ID: {$applicationId}");
            }

            // Convert to array for compatibility with EventService
            $applicationData = (array)$application;

            // Create an event for each date
            foreach ($applicationData['dates'] as $date)
            {
                $eventId = $eventService->createFromApplication($applicationData, $date);
                $lastEventId = $eventId;
            }

            // Update ID strings (legacy format)
            $eventService->repository->updateIdString();

            // Handle purchase orders using the legacy system
            if ($lastEventId)
            {
                createObject('booking.sopurchase_order')->identify_purchase_order(
                    $applicationId,
                    $lastEventId,
                    'event'
                );
            }

            // Only commit if we started the transaction
            if ($startedTransaction)
            {
                $this->db->commit();
            }
        } catch (Exception $e)
        {
            // Only rollback if we started the transaction
            if ($startedTransaction && $this->db->inTransaction())
            {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Check if application is eligible for direct booking
     */
    private function isEligibleForDirectBooking(array $application): bool
    {
        // Check if all resources have direct booking enabled
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
        $ssn = $this->userHelper->ssn;

        foreach ($resources as $resource)
        {
            // Check if direct booking is enabled and the date is valid
            if (empty($resource['direct_booking']) || time() < $resource['direct_booking'])
            {
                return false;
            }

            // Check booking limits for the user
            if ($resource['booking_limit_number_horizont'] > 0 &&
                $resource['booking_limit_number'] > 0 &&
                $ssn)
            {
                $user_session_id = $this->userHelper->get_session_id();
                if ($user_session_id) {
                    $limit_reached = $this->checkBookingLimit(
                        $user_session_id,
                        $resource['id'],
                        $ssn,
                        $resource['booking_limit_number_horizont'],
                        $resource['booking_limit_number']
                    );
                } else {
                    // Skip limit check if no session available
                    $limit_reached = false;
                }

                if ($limit_reached)
                {
                    return false;
                }
            }
        }

        return true; // Eligible for direct booking
    }

    /**
     * Helper function to check if user has too many direct bookings of type
     */
    private function checkBookingLimit(
        string $session_id,
        int    $resource_id,
        string $ssn,
        int    $horizon_days,
        int    $limit
    ): bool
    {
        // Get user's current booking count
        $count = $this->getUserBookingCount($resource_id, $ssn, $horizon_days);
        return $count >= $limit;
    }

    /**
     * Helper method to get user's current booking count
     */
    private function getUserBookingCount(int $resourceId, string $ssn, int $horizonDays): int
    {
        // PostgreSQL uses a different interval syntax
        $sql = "SELECT COUNT(*) as count
            FROM bb_application a
            JOIN bb_application_resource ar ON a.id = ar.application_id
            WHERE ar.resource_id = :resource_id
            AND a.customer_ssn = :ssn
            AND a.created >= NOW() - (INTERVAL '1 day' * :horizon_days)
            AND a.status != 'REJECTED'
            AND a.active = 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':resource_id' => $resourceId,
            ':ssn' => $ssn,
            ':horizon_days' => $horizonDays
        ]);

        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return (int)$result['count'];
    }

    /**
     * Validate checkout data
     */
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
            'organizerName' => 'Organizer name',
            'customerType' => 'Customer type'
        ];

        foreach ($required_fields as $field => $label)
        {
            if (empty($data[$field]))
            {
                $errors[] = "{$label} is required";
            }
        }

        // Email validation
        if (!empty($data['contactEmail']))
        {
            $validator = createObject('booking.sfValidatorEmail', array(), array(
                'invalid' => '%field% contains an invalid email'
            ));
            try
            {
                $validator->clean($data['contactEmail']);
            } catch (\sfValidatorError $e)
            {
                $errors[] = 'Invalid email format';
            }
        }

        // Zip code validation
        if (!empty($data['zipCode']) && !preg_match('/^\d{4}$/', $data['zipCode']))
        {
            $errors[] = 'Invalid zip code format';
        }

        // Phone number validation
        if (!empty($data['contactPhone']) && strlen($data['contactPhone']) < 8)
        {
            $errors[] = 'Phone number must be at least 8 digits';
        }

        // Organization number validation if organization type
        if ($data['customerType'] === 'organization_number')
        {
            if (empty($data['organizationNumber']))
            {
                $errors[] = 'Organization number is required for organization bookings';
            } else
            {
                try
                {
                    $validator = createObject('booking.sfValidatorNorwegianOrganizationNumber');
                    $validator->clean($data['organizationNumber']);
                } catch (\sfValidatorError $e)
                {
                    $errors[] = 'Invalid organization number';
                }
            }
        }

        // SSN validation if provided through POST
        if ($data['customerType'] === 'ssn' && !empty($_POST['customer_ssn']))
        {
            try
            {
                $validator = createObject('booking.sfValidatorNorwegianSSN');
                $validator->clean($_POST['customer_ssn']);
            } catch (\sfValidatorError $e)
            {
                $errors[] = 'Invalid SSN';
            }
        }

        // Validate organization name is provided if organization number is provided
        if (!empty($data['organizationNumber']) && empty($data['organizationName']))
        {
            $errors[] = 'Organization name is required when organization number is provided';
        }

        // Validate customer type is valid
        if (!in_array($data['customerType'], ['ssn', 'organization_number']))
        {
            $errors[] = 'Invalid customer type';
        }

        // Event title and organizer name length validation
        if (strlen($data['eventTitle']) > 255)
        {
            $errors[] = 'Event title is too long (maximum 255 characters)';
        }
        if (strlen($data['organizerName']) > 255)
        {
            $errors[] = 'Organizer name is too long (maximum 255 characters)';
        }

        return $errors;
    }

    /**
     * Send grouped notifications for applications with the same parent_id
     */
    private function sendGroupedApplicationNotifications(array $applications): void
    {
        if (empty($applications)) {
            return;
        }

        // Group applications by parent_id (null parent_id gets grouped with its own id)
        $groupedApplications = [];
        foreach ($applications as $application) {
            $groupKey = $application['parent_id'] ?? $application['id'];
            if (!isset($groupedApplications[$groupKey])) {
                $groupedApplications[$groupKey] = [];
            }
            $groupedApplications[$groupKey][] = $application;
        }

        // Send email for each group
        foreach ($groupedApplications as $groupKey => $group) {
            if (count($group) > 1) {
                // Multiple applications with same parent_id - send grouped email
                $this->sendGroupApplicationNotification($group);
            } else {
                // Single application - send individual email
                $this->sendApplicationNotification($group[0]['id']);
            }
        }
    }

    /**
     * Send notification for a group of applications using modern EmailService
     */
    private function sendGroupApplicationNotification(array $applications): void
    {
        if (empty($applications)) {
            return;
        }

        // Get full application data for each application
        $fullApplications = [];
        foreach ($applications as $application) {
            $fullApp = $this->getFullApplication($application['id']);
            if ($fullApp) {
                $_application = (array)$fullApp;
                $resources_array = $_application['resources'] ?? [];

                // Extract only the id values from the resources array
                $_application['resources'] = array_map(function($resource) {
                    return is_array($resource) ? (int)$resource['id'] : (int)$resource;
                }, $resources_array);

                $fullApplications[] = $_application;
            }
        }

        if (!empty($fullApplications)) {
            // Determine if this is a newly created application
            $created = $fullApplications[0]['status'] === 'NEW';

            // Use modern EmailService group notification
            $this->emailService->sendApplicationGroupNotification($fullApplications, $created);
        }
    }

    /**
     * Send notification for completed application using modern EmailService (single application)
     */
    private function sendApplicationNotification(int $application_id): void
    {
        $application = $this->getFullApplication($application_id);

        if ($application)
        {
            $_application = (array)$application;
            $resources_array = $_application['resources'] ?? [];

            // Extract only the id values from the resources array (each resource is array('id' => 1, 'name' => 'name'))
            $_application['resources'] = array_map(function($resource) {
                return is_array($resource) ? (int)$resource['id'] : (int)$resource;
            }, $resources_array);

            // Determine if this is a newly created application
            $created = $_application['status'] === 'NEW';

            // Use modern EmailService instead of legacy method
            $this->emailService->sendApplicationNotification($_application, $created);

            // Handle additional notifications to case officers (BCC functionality)
            if ($created)
            {
                $this->emailService->sendCaseOfficerNotifications($_application);
            }

            // Handle SMS notifications (legacy functionality preserved)
            if ($created)
            {
                $this->emailService->sendSmsNotifications($_application);
            }
        }
    }

    /**
     * Pre-validate applications for checkout without making changes
     *
     * @param string $session_id Current session ID
     * @param array $data Contact and organization information
     * @return array Validation results with potential issues
     */
    public function validateCheckout(string $session_id, array $data): array
    {
        // Validate checkout data
        $dataErrors = $this->validateCheckoutData($data);
        if (!empty($dataErrors)) {
            return [
                'valid' => false,
                'data_errors' => $dataErrors,
                'applications' => []
            ];
        }

        // Get all applications for this session
        $applications = $this->getPartialApplications($session_id);
        if (empty($applications)) {
            return [
                'valid' => false,
                'error' => 'No partial applications found for checkout',
                'applications' => []
            ];
        }

        // Check resource booking limits across all applications
        $resourceBookings = [];
        foreach ($applications as $application) {
            foreach ($application['resources'] as $resource) {
                $resourceId = $resource['id'];
                if (!isset($resourceBookings[$resourceId])) {
                    $resourceBookings[$resourceId] = 0;
                }
                $resourceBookings[$resourceId]++;
            }
        }

        $limitErrors = [];
        $ssn = $this->userHelper->ssn;
        if ($ssn) {
            foreach ($resourceBookings as $resourceId => $count) {
                // Get resource details
                $sql = "SELECT r.name, r.booking_limit_number, r.booking_limit_number_horizont
                FROM bb_resource r
                WHERE r.id = :id";
                $stmt = $this->db->prepare($sql);
                $stmt->bindParam(':id', $resourceId, \PDO::PARAM_INT);
                $stmt->execute();
                $resource = $stmt->fetch(\PDO::FETCH_ASSOC);

                if ($resource && $resource['booking_limit_number'] > 0 && $resource['booking_limit_number_horizont'] > 0) {
                    // Get existing bookings count
                    $existingCount = $this->getUserBookingCount($resourceId, $ssn, $resource['booking_limit_number_horizont']);

                    // Calculate total bookings after checkout
                    $totalBookings = $existingCount + $count;

                    // Check if limit would be exceeded
                    if ($totalBookings > $resource['booking_limit_number']) {
                        $limitErrors[] = [
                            'resource_id' => $resourceId,
                            'resource_name' => $resource['name'],
                            'current_bookings' => $existingCount,
                            'additional_bookings' => $count,
                            'max_allowed' => $resource['booking_limit_number'],
                            'time_period_days' => $resource['booking_limit_number_horizont'],
                            'message' => "Quantity limit would be exceeded for {$resource['name']}: You already have {$existingCount} " .
                                "bookings and are trying to add {$count} more, which would exceed the maximum " .
                                "of {$resource['booking_limit_number']} bookings within {$resource['booking_limit_number_horizont']} days"
                        ];
                    }
                }
            }
        }

        // If there are global limit errors, return immediately
        if (!empty($limitErrors)) {
            return [
                'valid' => false,
                'limit_errors' => $limitErrors,
                'applications' => []
            ];
        }

        // Check each application individually
        $applicationResults = [];
        $debugCollisions = []; // Store all collision debug information

        foreach ($applications as $application) {
            $result = [
                'id' => $application['id'],
                'valid' => true,
                'issues' => [],
                'would_be_direct_booking' => false
            ];

            // Check if eligible for direct booking
            $isEligibleForDirectBooking = $this->isEligibleForDirectBooking($application);
            $result['would_be_direct_booking'] = $isEligibleForDirectBooking;

            if ($isEligibleForDirectBooking) {
                // Check for collisions with detailed debug info
                $collisionDates = [];
                $collisionDebugInfo = [];
                foreach ($application['dates'] as $date) {
                    $collisionCheck = $this->applicationRepository->checkCollisionWithDebug(
                        $application['resources'],
                        $date['from_'],
                        $date['to_'],
                        $application['session_id']
                    );

                    if ($collisionCheck['has_collision']) {
                        $collisionDates[] = [
                            'from' => $date['from_'],
                            'to' => $date['to_']
                        ];
                        $collisionDebugInfo[] = $collisionCheck;
                    }
                }


                if (!empty($collisionDates)) {
                    $result['valid'] = false;
                    $result['issues'][] = [
                        'type' => 'collision',
                        'dates' => $collisionDates,
                        'message' => 'Collision detected for dates that would be direct booked',
                        'debug_collision_details' => $collisionDebugInfo
                    ];

                    // Also store in our global debug array
                    $debugCollisions[$application['id']] = $collisionDebugInfo;
                }
            }

            $applicationResults[] = $result;
        }

        return [
            'valid' => !count(array_filter($applicationResults, function($result) { return !$result['valid']; })),
            'applications' => $applicationResults,
            'debug_collisions' => $debugCollisions
        ];
    }

    /**
     * Create a simple booking application
     *
     * @param int $resourceId Resource ID
     * @param int $buildingId Building ID
     * @param string $from Start datetime
     * @param string $to End datetime
     * @param string $sessionId Session ID
     * @return array Application data with ID and status
     * @throws \Exception If booking fails
     */

    public function createSimpleBooking(int $resourceId, int $buildingId, string $from, string $to, string $sessionId): array
    {

        $startedTransaction = false;
        try
        {
            // ATOMIC LOCK ACQUISITION - Use Redis SETNX for true atomicity
            $lockKey = "booking_lock_{$resourceId}_{$from}_{$to}";
            $lockTtl = 30; // 30 seconds should be enough for the booking process

            // Try to acquire the atomic lock
            $lockAcquired = \App\modules\phpgwapi\services\Cache::acquire_atomic_lock('booking', $lockKey, $sessionId, $lockTtl);

            if (!$lockAcquired) {
                $errorMessage = lang('resource_already_being_booked');
                \App\modules\phpgwapi\services\Cache::message_set($errorMessage, 'error');
                throw new \Exception($errorMessage);
            }

            try {
                // Start database transaction for atomic booking operation
                // This ensures that the overlap check and application creation happen atomically
                if (!$this->db->inTransaction())
                {
                    $this->db->beginTransaction();
                    $startedTransaction = true;
                }

                // IMPORTANT: All overlap checking must happen WITHIN this transaction
                // to prevent race conditions between concurrent booking attempts

                // Verify we're in a transaction before using FOR UPDATE
                if (!$this->db->inTransaction()) {
                    throw new \Exception("Database transaction required for atomic booking operation");
                }

                // CRITICAL RACE CONDITION FIX - ATOMIC OVERLAP CHECK WITH ROW LOCKING
                // Use SELECT FOR UPDATE to lock overlapping rows within the transaction
                // This ensures that only one transaction can check and create an application at a time
                // for any given time slot, completely preventing race conditions
                $overlapCheckSql = "SELECT a.id, a.status, ad.from_, ad.to_
                FROM bb_application a
                JOIN bb_application_resource ar ON a.id = ar.application_id
                JOIN bb_application_date ad ON a.id = ad.application_id
                WHERE ar.resource_id = :resource_id
                AND a.status NOT IN ('REJECTED')
                AND a.active = 1
                AND ((ad.from_ < :to_date AND ad.to_ > :from_date)
                    AND NOT (ad.from_ = :to_date OR ad.to_ = :from_date))
                FOR UPDATE
                LIMIT 1";

                // Execute the atomic overlap check with row locking
                // This will block any concurrent transaction trying to book the same slot
                $stmt = $this->db->prepare($overlapCheckSql);
                $stmt->execute([
                    ':resource_id' => $resourceId,
                    ':from_date' => $from,
                    ':to_date' => $to
                ]);

                // Check if any overlapping application was found and locked
                $overlappingApp = $stmt->fetch(\PDO::FETCH_ASSOC);
                $hasOverlap = (bool)$overlappingApp;

                // Log the atomic check for debugging
                if ($hasOverlap) {
                    error_log("ATOMIC OVERLAP DETECTED: Found conflicting application #{$overlappingApp['id']} (status: {$overlappingApp['status']}) for resource {$resourceId}, time {$from} to {$to}");
                } else {
                    error_log("ATOMIC CHECK PASSED: No conflicts for resource {$resourceId}, time {$from} to {$to} - proceeding with booking");
                }

                // If we found any overlapping applications, set a session message and reject the request
                if ($hasOverlap) {
                    $errorMessage = lang('resource_already_booked');

                    // Set message using Cache::message_set() like ApplicationController does
                    \App\modules\phpgwapi\services\Cache::message_set($errorMessage, 'error');

                    // Enhanced logging with conflicting application details
                    error_log("ATOMIC BOOKING CONFLICT: Resource {$resourceId}, requested {$from} to {$to}, conflicts with app #{$overlappingApp['id']} ({$overlappingApp['from_']} to {$overlappingApp['to_']})");

                    // Release atomic lock since we're not proceeding with the booking
                    \App\modules\phpgwapi\services\Cache::release_atomic_lock('booking', $lockKey, $sessionId);

                    // Also clear database blocks since booking failed
                    try {
                        $sql = "UPDATE bb_block SET active = 0
                            WHERE session_id = :session_id
                            AND resource_id = :resource_id
                            AND from_ = :from
                            AND to_ = :to";

                        $clearStmt = $this->db->prepare($sql);
                        $clearStmt->execute([
                            ':session_id' => $sessionId,
                            ':resource_id' => $resourceId,
                            ':from' => $from,
                            ':to' => $to
                        ]);

                        $updatedCount = $clearStmt->rowCount();
                        error_log("LOCKS AND BLOCKS RELEASED (database conflict): Resource ID {$resourceId}, time {$from} to {$to}, cleared {$updatedCount} blocks");
                    } catch (\Exception $clearEx) {
                        // Just log this error but don't interrupt the flow
                        error_log("ERROR CLEARING BLOCKS: " . $clearEx->getMessage());
                    }

                    // Throw exception to stop the booking process
                    throw new \Exception($errorMessage);
                }
            } catch (\Exception $e) {
                // If any exception occurs during the DB check, release atomic lock before re-throwing
                \App\modules\phpgwapi\services\Cache::release_atomic_lock('booking', $lockKey, $sessionId);

                // Also clear database blocks since booking failed
                try {
                    $sql = "UPDATE bb_block SET active = 0
                        WHERE session_id = :session_id
                        AND resource_id = :resource_id
                        AND from_ = :from
                        AND to_ = :to";

                    $clearStmt = $this->db->prepare($sql);
                    $clearStmt->execute([
                        ':session_id' => $sessionId,
                        ':resource_id' => $resourceId,
                        ':from' => $from,
                        ':to' => $to
                    ]);

                    $updatedCount = $clearStmt->rowCount();
                    error_log("LOCKS AND BLOCKS RELEASED (early error): Resource ID {$resourceId}, time {$from} to {$to}, cleared {$updatedCount} blocks, error: " . $e->getMessage());
                } catch (\Exception $clearEx) {
                    // Just log this error but don't interrupt the flow
                    error_log("ERROR CLEARING BLOCKS: " . $clearEx->getMessage() . " while handling original error: " . $e->getMessage());
                }

                throw $e;
            }

            // Check if resource supports simple booking
            $resource = $this->getSimpleBookingResource($resourceId);
            if (!$resource)
            {
                throw new \Exception("Resource does not support simple booking");
            }

            // Check availability using detailed checking with BuildingScheduleService
            $availability = $this->checkSimpleBookingAvailability($resourceId, $from, $to, $sessionId);
            if (!$availability['available'])
            {
                // Use the detailed information we now have from checkSimpleBookingAvailability
                $message = $availability['message'] ?? 'Timeslot is not available';
                $reason = $availability['overlap_reason'] ?? null;
                $type = $availability['overlap_type'] ?? null;

                if ($reason && $type) {
                    throw new \Exception("{$message}: {$reason} ({$type})");
                } else {
                    throw new \Exception($message);
                }
            }


            $ssn = $this->userHelper->ssn;
            // Only check limits if user is authenticated
            if ($ssn && $resource['booking_limit_number'] > 0 && $resource['booking_limit_number_horizont'] > 0)
            {
                $currentBookings = $this->getUserBookingCount($resourceId, $ssn, $resource['booking_limit_number_horizont']);

                if ($currentBookings >= $resource['booking_limit_number'])
                {
                    throw new \Exception(
                        "Quantity limit ({$currentBookings}) exceeded for {$resource['name']}: " .
                        "maximum {$resource['booking_limit_number']} times within a period of " .
                        "{$resource['booking_limit_number_horizont']} days"
                    );
                }
            }


            // Create block
            if (!$this->applicationRepository->createBlock($sessionId, $resourceId, $from, $to))
            {
                throw new \Exception("Failed to create block for timeslot");
            }

            // Get building name
            $sql = "SELECT name FROM bb_building WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->bindParam(':id', $buildingId, \PDO::PARAM_INT);
            $stmt->execute();
            $building = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$building)
            {
                throw new \Exception("Building not found");
            }

            // Create application data
            $application = [
                'status' => 'NEWPARTIAL1',
                'session_id' => $sessionId,
                'building_name' => $building['name'],
                'building_id' => $buildingId,
                'activity_id' => $resource['activity_id'],
                'contact_name' => 'dummy',
                'contact_email' => 'dummy@example.com',
                'contact_phone' => 'dummy',
                'responsible_street' => 'dummy',
                'responsible_zip_code' => '0000',
                'responsible_city' => 'dummy',
                'customer_identifier_type' => 'organization_number',
                'customer_organization_number' => '',
                'name' => $resource['name'] . ' (simple booking)',
                'organizer' => 'dummy',
                'owner_id' => $this->userSettings['account_id'] ?? 0,
                'active' => 1
            ];

            // Insert the application
            $id = $this->savePartialApplication($application);

            // Add the resource to the application
            $this->applicationRepository->saveApplicationResources($id, [$resourceId]);

            // Add the date to the application
            $this->applicationRepository->saveApplicationDates($id, [['from_' => $from, 'to_' => $to]]);

            // Update ID string
            $this->applicationRepository->updateIdString();

            // Only commit if we started the transaction
            if ($startedTransaction)
            {
                $this->db->commit();
            }

            // Operation successful - release the atomic lock
            \App\modules\phpgwapi\services\Cache::release_atomic_lock('booking', $lockKey, $sessionId);

            return [
                'id' => $id,
                'status' => $application['status']
            ];
        } catch (\Exception $e)
        {
            // Only rollback if we started the transaction
            if ($startedTransaction && $this->db->inTransaction())
            {
                $this->db->rollBack();
            }

            // Release atomic lock in error cases
            \App\modules\phpgwapi\services\Cache::release_atomic_lock('booking', $lockKey, $sessionId);

            // Also clear database blocks since booking failed
            try {
                $sql = "UPDATE bb_block SET active = 0
                    WHERE session_id = :session_id
                    AND resource_id = :resource_id
                    AND from_ = :from
                    AND to_ = :to";

                $clearStmt = $this->db->prepare($sql);
                $clearStmt->execute([
                    ':session_id' => $sessionId,
                    ':resource_id' => $resourceId,
                    ':from' => $from,
                    ':to' => $to
                ]);

                $updatedCount = $clearStmt->rowCount();
            } catch (\Exception $clearEx) {
                // Just log this error but don't interrupt the flow
                error_log("ERROR CLEARING BLOCKS: " . $clearEx->getMessage() . " while handling original error: " . $e->getMessage());
            }

            throw $e;
        }
    }

    /**
     * Check if a timeslot is available for simple booking
     *
     * @param int $resourceId Resource ID
     * @param string $from Start datetime
     * @param string $to End datetime
     * @param string $session_id Session ID
     * @return array Availability details
     */
    public function checkSimpleBookingAvailability(int $resourceId, string $from, string $to, string $session_id): array
    {
        // Check if resource supports simple booking
        $resource = $this->getSimpleBookingResource($resourceId);

        if (!$resource)
        {
            return [
                'available' => false,
                'supports_simple_booking' => false,
                'message' => 'Resource does not support simple booking'
            ];
        }

        // Check if there's already a block for this session
        $blockExists = $this->applicationRepository->checkBlockExists($session_id, $resourceId, $from, $to);
        if ($blockExists)
        {
            return [
                'available' => true,
                'supports_simple_booking' => true,
                'message' => 'Timeslot is already blocked for your session'
            ];
        }

        // Initialize variables to store detailed overlap information
        $available = true;
        $overlapReason = null;
        $overlapType = null;
        $overlapEvent = null;

        // Use bobooking's check_if_resurce_is_taken for detailed checking
        $bobooking = CreateObject('booking.bobooking');

        // Convert datetime strings to DateTime objects
        $timezone = !empty($this->userSettings['preferences']['common']['timezone']) ?
            $this->userSettings['preferences']['common']['timezone'] : 'UTC';
        $DateTimeZone = new \DateTimeZone($timezone);
        $fromDateTime = new \DateTime($from, $DateTimeZone);
        $toDateTime = new \DateTime($to, $DateTimeZone);

        // Get events for the resource to check against using BuildingScheduleService
        $events = $this->getResourceEventsForBookingCheck($resourceId, $fromDateTime, $toDateTime);

        // Use detailed check function
        $overlap_result = $bobooking->check_if_resurce_is_taken($resource, $fromDateTime, $toDateTime, $events);

        // Process the overlap result
        if (is_array($overlap_result)) {
            // Detailed result with status, reason, type, and event
            $available = !(bool)$overlap_result['status'];
            $overlapReason = $overlap_result['reason'] ?? null;
            $overlapType = $overlap_result['type'] ?? null;
            $overlapEvent = $overlap_result['event'] ?? null;
        } else {
            // Simple boolean result
            $available = !$overlap_result;
        }

        // Check booking limits if the timeslot is available
        $limitInfo = null;
        $ssn = $this->userHelper->ssn;
        if ($available && $ssn && $resource['booking_limit_number'] > 0 && $resource['booking_limit_number_horizont'] > 0)
        {
            $currentBookings = $this->getUserBookingCount($resourceId, $ssn, $resource['booking_limit_number_horizont']);
            $limitInfo = [
                'current_bookings' => $currentBookings,
                'max_allowed' => $resource['booking_limit_number'],
                'time_period_days' => $resource['booking_limit_number_horizont']
            ];

            // Check if user has exceeded their limit
            if ($currentBookings >= $resource['booking_limit_number'])
            {
                return [
                    'available' => false,
                    'supports_simple_booking' => true,
                    'message' => "You have reached the maximum allowed bookings ({$resource['booking_limit_number']}) for this resource within {$resource['booking_limit_number_horizont']} days",
                    'limit_info' => $limitInfo,
                    'overlap_reason' => 'booking_limit_exceeded',
                    'overlap_type' => 'disabled'
                ];
            }
        }

        // Build the response with detailed information
        $response = [
            'available' => $available,
            'supports_simple_booking' => true,
            'limit_info' => $limitInfo
        ];

        // Add detailed overlap information if not available
        if (!$available) {
            $response['message'] = $this->getOverlapMessage($overlapReason, $overlapType);
            $response['overlap_reason'] = $overlapReason;
            $response['overlap_type'] = $overlapType;

            // Add event details if available
            if ($overlapEvent) {
                $response['overlap_event'] = $overlapEvent;
            }
        } else {
            $response['message'] = 'Timeslot is available';
        }

        return $response;
    }

    /**
     * Get a human-readable message for overlap reasons
     *
     * @param string|null $reason The overlap reason
     * @param string|null $type The overlap type
     * @return string The human-readable message
     */
    private function getOverlapMessage(?string $reason, ?string $type): string
    {
        if (!$reason) {
            return 'Timeslot is not available';
        }

        switch ($reason) {
            case 'time_in_past':
                return 'Booking time is in the past';
            case 'complete_overlap':
                return 'Timeslot is already booked';
            case 'complete_containment':
                return 'Another booking exists within this timeslot';
            case 'start_overlap':
                return 'Timeslot overlaps with the start of another booking';
            case 'end_overlap':
                return 'Timeslot overlaps with the end of another booking';
            default:
                return 'Timeslot is not available: ' . $reason;
        }
    }

    /**
     * Get resource information for simple booking with caching
     *
     * @param int $resourceId The resource ID to query
     * @return array|false Resource data or false if not found/eligible
     */
    public function getSimpleBookingResource(int $resourceId)
    {
        // Use static cache to avoid repeated DB queries in the same request
        static $resourceCache = [];

        // Check if we have this resource in cache
        if (isset($resourceCache[$resourceId])) {
            return $resourceCache[$resourceId];
        }

        $sql = "SELECT r.*, br.building_id
            FROM bb_resource r
            JOIN bb_building_resource br ON r.id = br.resource_id
            WHERE r.id = :id
            AND r.active = 1
            AND r.simple_booking = 1";

        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':id', $resourceId, \PDO::PARAM_INT);
        $stmt->execute();

        // Store in cache and return
        $resourceCache[$resourceId] = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $resourceCache[$resourceId];
    }

    /**
     * Get resource events for booking availability check
     *
     * This method directly queries for blocks, events and applications (including NEWPARTIAL1)
     * to ensure accurate overlap detection
     *
     * @param int $resourceId The resource ID
     * @param \DateTime $from Start datetime
     * @param \DateTime $to End datetime
     * @return array Events formatted for check_if_resurce_is_taken
     */
    private function getResourceEventsForBookingCheck(int $resourceId, \DateTime $from, \DateTime $to): array
    {
        // Debug
        error_log("Resource $resourceId check from " . $from->format('Y-m-d H:i:s') . " to " . $to->format('Y-m-d H:i:s'));

        // Format dates for SQL
        $from_date = $from->format('Y-m-d H:i:s');
        $to_date = $to->format('Y-m-d H:i:s');

        // Combine all events
        $formattedEvents = [];

        try {
            // First get all applications (INCLUDING NEWPARTIAL1)
            // This should use the exact same date overlap algorithm as checkCollisionWithDebug
            $sql = "SELECT a.id, ad.from_, ad.to_, 'application' as type, a.status
                    FROM bb_application a
                    JOIN bb_application_resource ar ON a.id = ar.application_id
                    JOIN bb_application_date ad ON a.id = ad.application_id
                    WHERE ar.resource_id = :resource_id
                    AND a.active = 1
                    AND a.status != 'REJECTED'
                    AND ((ad.from_ BETWEEN :from_date AND :to_date)
                        OR (ad.to_ BETWEEN :from_date AND :to_date)
                        OR (:from_date BETWEEN ad.from_ AND ad.to_)
                        OR (:to_date BETWEEN ad.from_ AND ad.to_))";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':resource_id' => $resourceId,
                ':from_date' => $from_date,
                ':to_date' => $to_date
            ]);
            $applications = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Get blocks
            $sql = "SELECT b.id, b.from_, b.to_, 'block' as type, b.session_id as status
                    FROM bb_block b
                    WHERE b.resource_id = :resource_id
                    AND b.active = 1
                    AND ((b.from_ BETWEEN :from_date AND :to_date)
                        OR (b.to_ BETWEEN :from_date AND :to_date)
                        OR (:from_date BETWEEN b.from_ AND b.to_)
                        OR (:to_date BETWEEN b.from_ AND b.to_))";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':resource_id' => $resourceId,
                ':from_date' => $from_date,
                ':to_date' => $to_date
            ]);
            $blocks = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Get events
            $sql = "SELECT e.id, e.from_, e.to_, 'event' as type, 'ACCEPTED' as status
                    FROM bb_event e
                    JOIN bb_event_resource er ON e.id = er.event_id
                    WHERE er.resource_id = :resource_id
                    AND e.active = 1
                    AND ((e.from_ BETWEEN :from_date AND :to_date)
                        OR (e.to_ BETWEEN :from_date AND :to_date)
                        OR (:from_date BETWEEN e.from_ AND e.to_)
                        OR (:to_date BETWEEN e.from_ AND e.to_))";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':resource_id' => $resourceId,
                ':from_date' => $from_date,
                ':to_date' => $to_date
            ]);
            $events = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Process applications
            foreach ($applications as $app) {
                $formattedEvent = [
                    'from_' => $app['from_'],
                    'to_' => $app['to_'],
                    'resources' => [$resourceId],
                    'type' => 'application',
                    'id' => $app['id'],
                    'status' => $app['status'] ?? null
                ];
                $formattedEvents[] = $formattedEvent;
            }

            // Process blocks
            foreach ($blocks as $block) {
                $formattedEvent = [
                    'from_' => $block['from_'],
                    'to_' => $block['to_'],
                    'resources' => [$resourceId],
                    'type' => 'block',
                    'id' => $block['id'],
                    'status' => $block['status'] ?? null
                ];
                $formattedEvents[] = $formattedEvent;
            }

            // Process events
            foreach ($events as $event) {
                $formattedEvent = [
                    'from_' => $event['from_'],
                    'to_' => $event['to_'],
                    'resources' => [$resourceId],
                    'type' => 'event',
                    'id' => $event['id'],
                    'status' => $event['status'] ?? 'ACCEPTED'
                ];
                $formattedEvents[] = $formattedEvent;
            }

            error_log("Found " . count($formattedEvents) . " events/blocks/applications for resource");

        } catch (\Exception $e) {
            error_log("Error in getResourceEventsForBookingCheck: " . $e->getMessage());
            // Even with an error, we continue with whatever events we found
        }

        // Return in the expected format
        return [
            'results' => $formattedEvents
        ];
    }

    /**
     * Cancel blocks for an application
     *
     * @param int $applicationId Application ID
     * @return bool True if blocks were cancelled
     */
    public function cancelBlocksForApplication(int $applicationId): bool
    {
        try
        {
            // Get application details
            $application = $this->getApplicationById($applicationId);
            if (!$application || empty($application['session_id']))
            {
                return false;
            }

            // Get dates and resources
            $dates = $this->applicationRepository->fetchDates($applicationId);
            $resourceIds = [];
            $resources = $this->applicationRepository->fetchResources($applicationId);
            foreach ($resources as $resource)
            {
                $resourceIds[] = $resource['id'];
            }

            if (empty($dates) || empty($resourceIds))
            {
                return false;
            }

            // Log that we're canceling blocks
            error_log("Canceling blocks for application #{$applicationId}, session: {$application['session_id']}, resources: " . implode(',', $resourceIds));

            // Cancel blocks
            $placeholders = implode(',', array_fill(0, count($resourceIds), '?'));
            $params = [$application['session_id']];
            $params = array_merge($params, $resourceIds);

            $totalUpdated = 0;
            foreach ($dates as $date)
            {
                $sql = "UPDATE bb_block SET active = 0
                    WHERE session_id = ?
                    AND resource_id IN ($placeholders)
                    AND from_ = ?
                    AND to_ = ?";

                $stmt = $this->db->prepare($sql);
                $stmt->execute(array_merge($params, [$date['from_'], $date['to_']]));
                $totalUpdated += $stmt->rowCount();
            }

            error_log("Cancelled {$totalUpdated} blocks for application #{$applicationId}");
            return true;
        } catch (\Exception $e)
        {
            error_log("Error cancelling blocks: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Explicitly clear blocks and cache locks for a resource and time slot
     *
     * This should be called after a successful registration or when we're sure
     * the booking process is complete (whether successful or not)
     *
     * @param int $resourceId Resource ID
     * @param string $from Start time
     * @param string $to End time
     * @param string $sessionId Session ID
     * @return bool True if successful
     */
    public function clearBlocksAndLocks(int $resourceId, string $from, string $to, string $sessionId): bool
    {
        try {
            // Clear the specific timeslot lock
            $lockKey = "timeslot_lock_{$resourceId}_{$from}_{$to}";
            \App\modules\phpgwapi\services\Cache::system_clear('booking', $lockKey);

            // Clear resource-level locks if this session owns them
            $resourceBookingFlag = "resource_booking_in_progress_{$resourceId}";
            $resourceBookingDetails = "resource_booking_details_{$resourceId}";

            $currentLock = \App\modules\phpgwapi\services\Cache::system_get('booking', $resourceBookingFlag);
            if ($currentLock === $sessionId) {
                \App\modules\phpgwapi\services\Cache::system_clear('booking', $resourceBookingFlag);
                \App\modules\phpgwapi\services\Cache::system_clear('booking', $resourceBookingDetails);
            }

            // Update blocks in the database
            $sql = "UPDATE bb_block SET active = 0
                WHERE session_id = :session_id
                AND resource_id = :resource_id
                AND from_ = :from
                AND to_ = :to";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':session_id' => $sessionId,
                ':resource_id' => $resourceId,
                ':from' => $from,
                ':to' => $to
            ]);

            $updatedCount = $stmt->rowCount();
            error_log("Cleared {$updatedCount} blocks and locks for resource {$resourceId}, time {$from} to {$to}, session {$sessionId}");

            return true;
        } catch (\Exception $e) {
            error_log("Error clearing blocks and locks: " . $e->getMessage());
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

    /**
     * Validate that all applications that will get the same parent_id belong to the same building
     * This method handles per-building parent ID assignments
     *
     * @param array $applications List of applications
     * @param array $buildingParentIds Array of building_id => parent_id mappings
     * @throws Exception If mixed building applications would be combined
     */
    private function validateBuildingParentIdConstraints(array $applications, array $buildingParentIds): void
    {
        // Group applications by building and validate each building's parent selection
        foreach ($buildingParentIds as $buildingId => $parentId) {
            // Get the parent application
            $parentApplication = null;
            foreach ($applications as $app) {
                if ($app['id'] == $parentId) {
                    $parentApplication = $app;
                    break;
                }
            }

            if (!$parentApplication) {
                throw new Exception("Parent application with ID {$parentId} not found");
            }

            // Ensure parent belongs to the correct building
            if ($parentApplication['building_id'] != $buildingId) {
                throw new Exception(
                    "Invalid parent selection: Parent application #{$parentId} belongs to building '{$parentApplication['building_name']}' " .
                    "but was selected as parent for building ID {$buildingId}."
                );
            }

            // Validate that all non-recurring applications in this building would get this parent_id
            foreach ($applications as $app) {
                // Skip recurring applications (they are processed individually)
                if (!empty($app['recurring_info'])) {
                    continue;
                }

                // Skip applications from other buildings
                if ($app['building_id'] != $buildingId) {
                    continue;
                }

                // Skip the parent application itself
                if ($app['id'] == $parentId) {
                    continue;
                }

                // This validation ensures consistency within each building
                // All non-recurring applications in a building should be grouped under the same parent
            }
        }
    }

    /**
     * Legacy method - kept for backward compatibility
     * Validate that all applications that will get the same parent_id belong to the same building
     *
     * @param array $applications List of applications
     * @param int $parent_id The parent ID to validate
     * @throws Exception If mixed building applications would be combined
     */
    private function validateBuildingParentIdConstraint(array $applications, int $parent_id): void
    {
        // Get the building_id of the parent application
        $parentApplication = null;
        foreach ($applications as $app) {
            if ($app['id'] == $parent_id) {
                $parentApplication = $app;
                break;
            }
        }

        if (!$parentApplication) {
            throw new Exception("Parent application with ID {$parent_id} not found");
        }

        $parentBuildingId = $parentApplication['building_id'];

        // Check all regular (non-recurring) applications that would get this parent_id
        foreach ($applications as $app) {
            // Skip recurring applications (they are processed individually)
            if (!empty($app['recurring_info'])) {
                continue;
            }

            // Skip the parent application itself
            if ($app['id'] == $parent_id) {
                continue;
            }

            // Check if this application belongs to a different building
            if ($app['building_id'] != $parentBuildingId) {
                throw new Exception(
                    "Cannot combine applications from different buildings. " .
                    "Parent application (#{$parent_id}) is in building '{$parentApplication['building_name']}' " .
                    "but application #{$app['id']} is in building '{$app['building_name']}'. " .
                    "Please select applications from the same building only."
                );
            }
        }
    }
}