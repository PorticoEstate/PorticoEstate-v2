<?php

namespace App\modules\bookingfrontend\services;

use App\modules\bookingfrontend\helpers\UserHelper;
use App\modules\bookingfrontend\models\Application;
use App\modules\bookingfrontend\models\Document;
use App\modules\bookingfrontend\models\helper\Date;
use App\modules\bookingfrontend\models\Resource;
use App\modules\bookingfrontend\models\Order;
use App\modules\bookingfrontend\models\OrderLine;
use App\Database\Db;
use App\modules\phpgwapi\services\Settings;
use PDO;

require_once SRC_ROOT_PATH . '/helpers/LegacyObjectHandler.php';


class ApplicationService
{
	private $db;
	private $documentService;
	private $userHelper;
	private $userSettings;

	public function __construct()
	{
		$this->db = Db::getInstance();
		$this->documentService = new DocumentService(Document::OWNER_APPLICATION);
		$this->userHelper = new UserHelper();
		$this->userSettings = Settings::getInstance()->get('user');
	}

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
		foreach ($results as $result)
		{
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
				'customer_ssn' => $data['customerType'] === 'ssn' ? $this->userHelper->ssn : null,
				'session_id' => null
			];

			$updatedApplications = [];
			$skippedApplications = [];
			$collisionDebugInfo = []; // Debug information for collisions

			foreach ($applications as $application)
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
						$collisionCheck = $this->checkCollisionWithDebug(
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

					// If direct booking eligible but has collision, skip this application
					if ($hasCollision)
					{
						$collisionDebugInfo[$application['id']] = $applicationCollisionInfo;

						$skippedApplications[] = [
							'id' => $application['id'],
							'reason' => 'Collision detected for direct booking application',
							'collision_debug' => $applicationCollisionInfo
						];
						continue; // Skip to next application
					}

					// No collision - proceed with direct booking
					$updateData = array_merge($baseUpdateData, [
						'status' => 'ACCEPTED',
						'parent_id' => $application['id'] == $parent_id ? null : $parent_id
					]);

					$this->patchApplicationMainData($updateData, $application['id']);
					$this->createEventForApplication($application);
				} else
				{
					// Not eligible for direct booking - process normally
					$updateData = array_merge($baseUpdateData, [
						'status' => 'NEW',
						'parent_id' => $application['id'] == $parent_id ? null : $parent_id
					]);

					$this->patchApplicationMainData($updateData, $application['id']);
				}

				// Send notification and add to updated list
				$this->sendApplicationNotification($application['id']);
				$updatedApplications[] = array_merge($application, $updateData);
			}
			$this->db->commit();
			return [
				'updated' => $updatedApplications,
				'skipped' => $skippedApplications,
				'debug_collisions' => $collisionDebugInfo
			];

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
		foreach ($application['dates'] as $date)
		{
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
				$limit_reached = $this->checkBookingLimit(
					$application['session_id'],
					$resource['id'],
					$ssn,
					$resource['booking_limit_number_horizont'],
					$resource['booking_limit_number']
				);

				if ($limit_reached)
				{
					return false;
				}
			}
		}

		return true; // Eligible for direct booking
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

				$limit_reached = $this->checkBookingLimit(
					$application['session_id'],
					$resource['id'],
					$ssn,
					$resource['booking_limit_number_horizont'],
					$resource['booking_limit_number']
				);

				if ($limit_reached)
				{
					return false;
				}
			}
		}

		// Check for collisions
		foreach ($application['dates'] as $date)
		{
			$collision = $this->checkCollision(
				$application['resources'],
				$date['from_'],
				$date['to_'],
				$application['session_id']
			);
			if ($collision)
			{
				return false;
			}
		}

		return true;
	}


	/**
	 * Enhanced collision checking with debugging information
	 *
	 * @param array $resources Array of resource IDs
	 * @param string $from Start time
	 * @param string $to End time
	 * @param string $session_id Current session ID
	 * @return array Debug information with collision result
	 */
	private function checkCollisionWithDebug(array $resources, string $from, string $to, string $session_id): array
	{
		$resourceIds = [];
		foreach ($resources as $resource) {
			if (is_array($resource) && isset($resource['id'])) {
				$resourceIds[] = (int)$resource['id'];
			} else {
				$resourceIds[] = (int)$resource;
			}
		}

		$resource_ids_str = implode(',', $resourceIds);

		if (empty($resource_ids_str)) {
			return [
				'has_collision' => false,
				'from' => $from,
				'to' => $to,
				'resource_ids' => $resources,
				'extracted_ids' => $resourceIds,
				'session_id' => $session_id,
				'error' => 'No valid resource IDs found'
			];
		}

		$sql = "SELECT a.id, a.name, a.status, ad.from_, ad.to_,
            r.id as resource_id, r.name as resource_name
            FROM bb_application a
            JOIN bb_application_resource ar ON a.id = ar.application_id
            JOIN bb_application_date ad ON a.id = ad.application_id
            JOIN bb_resource r ON ar.resource_id = r.id
            WHERE ar.resource_id IN ($resource_ids_str)
            AND a.status NOT IN ('REJECTED', 'NEWPARTIAL1')
            AND a.active = 1
            AND ((ad.from_ BETWEEN :from_date AND :to_date)
                OR (ad.to_ BETWEEN :from_date AND :to_date)
                OR (:from_date BETWEEN ad.from_ AND ad.to_)
                OR (:to_date BETWEEN ad.from_ AND ad.to_))
            AND (a.session_id IS NULL OR a.session_id != :session_id)";

		$params = [
			':from_date' => $from,
			':to_date' => $to,
			':session_id' => $session_id
		];

		$stmt = $this->db->prepare($sql);
		$stmt->execute($params);
		$collisions = $stmt->fetchAll(\PDO::FETCH_ASSOC);

		return [
			'has_collision' => !empty($collisions),
			'from' => $from,
			'to' => $to,
			'resource_ids' => $resources,
			'session_id' => $session_id,
			'sql' => $sql,
			'params' => $params,
			'collisions' => $collisions
		];
	}

	/**
	 * Original collision check method - now calls the debug version
	 */
	private function checkCollision(array $resources, string $from, string $to, string $session_id): bool
	{
		$result = $this->checkCollisionWithDebug($resources, $from, $to, $session_id);
		return $result['has_collision'];
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
			':resource_id' => $resource_id,
			':ssn' => $ssn,
			':horizon_days' => $horizon_days
		]);

		$result = $stmt->fetch(PDO::FETCH_ASSOC);
		return (int)$result['count'] >= $limit;
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
	 * Send notification for completed application
	 */
	private function sendApplicationNotification(int $application_id): void
	{
//        $sql = "SELECT * FROM bb_application WHERE id = :id";
//        $stmt = $this->db->prepare($sql);
//        $stmt->execute([':id' => $application_id]);
		$application = $this->getFullApplication($application_id);

		if ($application)
		{
			// Call existing notification method from booking.boapplication
			$bo = CreateObject('booking.boapplication');
			$bo->send_notification((array)$application, true);
		}
	}

	private function fetchDates(int $application_id): array
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

		return array_map(function ($resourceData)
		{
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
				throw new Exception("Application not found or not a partial application");
			}

			// If application has a session ID, cancel any associated blocks
			if (!empty($application['session_id']))
			{
				// Get dates and resources
				$dates = $this->fetchDates($id);
				$resourceIds = [];
				$resources = $this->fetchResources($id);

				foreach ($resources as $resource)
				{
					$resourceIds[] = $resource['id'];
				}

				if (!empty($dates) && !empty($resourceIds))
				{
					// Cancel blocks
					$placeholders = implode(',', array_fill(0, count($resourceIds), '?'));
					$params = [$application['session_id']];
					$params = array_merge($params, $resourceIds);

					foreach ($dates as $date)
					{
						$sql = "UPDATE bb_block SET active = 0
                            WHERE session_id = ?
                            AND resource_id IN ($placeholders)
                            AND from_ = ?
                            AND to_ = ?";

						$stmt = $this->db->prepare($sql);
						$stmt->execute(array_merge($params, [$date['from_'], $date['to_']]));
					}
				}
			}

			// Delete associated data
			$this->deleteAssociatedData($id);

			// Delete the application
			$sql = "DELETE FROM bb_application WHERE id = :id";
			$stmt = $this->db->prepare($sql);
			$stmt->execute([':id' => $id]);

			$this->db->commit();
			return true;
		} catch (PDOException $e)
		{
			$this->db->rollBack();
			// Log the error
			error_log("Database error: " . $e->getMessage());
			throw new Exception("An error occurred while deleting the application");
		} catch (Exception $e)
		{
			$this->db->rollBack();
			throw $e;
		}
	}


	private function deleteAssociatedData(int $application_id): void
	{


		$documents = $this->documentService->getDocumentsForId($application_id);
		foreach ($documents as $document)
		{
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
				$receipt = $this->updateApplication($data);
				$id = $data['id'];
			} else
			{
				$receipt = $this->insertApplication($data);
				$id = $receipt['id'];
				$this->update_id_string();
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

			// Handle other related data...
			if (!empty($data['purchase_order']['lines']))
			{
				$data['purchase_order']['application_id'] = $id;
				$this->savePurchaseOrder($data['purchase_order']);
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

		if (!$result)
		{
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
		$table_name = "bb_application";
		$sql = "UPDATE $table_name SET id_string = cast(id AS varchar)";
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

		foreach ($dates as $date)
		{
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
		foreach ($purchaseOrder['lines'] as $line)
		{
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

		} catch (Exception $e)
		{
			$this->db->rollBack();
			throw $e;
		}
	}

	public function saveApplicationTargetAudience(int $application_id, array $audience_ids): void
	{
//        $this->db->beginTransaction();
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

		} catch (Exception $e)
		{
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

		if (!$result)
		{
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
		try
		{
			$this->db->beginTransaction();

			// Handle main application data
			$this->patchApplicationMainData($data);

			// Handle resources if present (complete replacement)
			if (isset($data['resources']))
			{
				$this->saveApplicationResources($data['id'], $data['resources']);
			}

			// Handle dates if present (update existing, create new)
			if (isset($data['dates']))
			{
				$this->patchApplicationDates($data['id'], $data['dates']);
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

				$this->saveApplicationAgeGroups($data['id'], $transformedAgegroups);
			}


			$this->db->commit();
		} catch (Exception $e)
		{
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
		if (!$applicationId)
		{
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

	// Helper method to ensure consistent date formatting with Oslo timezone
	private function formatDateForDatabase($dateString): string
	{
		if (strpos($dateString, 'T') !== false)
		{
			// Create a DateTime object with the UTC timezone
			$utcDate = new \DateTime($dateString, new \DateTimeZone('UTC'));

			// Convert to Oslo timezone
			$osloTz = new \DateTimeZone('Europe/Oslo');
			$utcDate->setTimezone($osloTz);

			// Format for MySQL
			return $utcDate->format('Y-m-d H:i:s');
		}
		return $dateString; // Already in correct format
	}


	/**
	 * Check if a resource supports simple booking and get details
	 *
	 * @param int $resourceId Resource ID
	 * @return array|false Resource data or false if not supported
	 */
	public function getSimpleBookingResource(int $resourceId)
	{
		$sql = "SELECT r.*, br.building_id
            FROM bb_resource r
            JOIN bb_building_resource br ON r.id = br.resource_id
            WHERE r.id = :id
            AND r.active = 1
            AND r.simple_booking = 1";

		$stmt = $this->db->prepare($sql);
		$stmt->bindParam(':id', $resourceId, \PDO::PARAM_INT);
		$stmt->execute();

		return $stmt->fetch(\PDO::FETCH_ASSOC);
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
		$blockExists = $this->checkBlockExists($session_id, $resourceId, $from, $to);
		if ($blockExists)
		{
			return [
				'available' => true,
				'supports_simple_booking' => true,
				'message' => 'Timeslot is already blocked for your session'
			];
		}

		// Check for collisions
		$collision = $this->checkCollision([$resourceId], $from, $to, $session_id);

		$limitInfo = null;
		$ssn = $this->userHelper->ssn;
		if ($ssn && $resource['booking_limit_number'] > 0 && $resource['booking_limit_number_horizont'] > 0)
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
					'limit_info' => $limitInfo
				];
			}
		}

		return [
			'available' => !$collision,
			'supports_simple_booking' => true,
			'message' => $collision ? 'Timeslot is not available' : 'Timeslot is available',
			'limit_info' => $limitInfo
		];
	}

	/**
	 * Check if a block already exists
	 */
	private function checkBlockExists(string $session_id, int $resource_id, string $from, string $to): bool
	{
		$sql = "SELECT 1 FROM bb_block
            WHERE active = 1
            AND session_id = :session_id
            AND resource_id = :resource_id
            AND from_ = :from
            AND to_ = :to";

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
	 */
	private function createBlock(string $session_id, int $resource_id, string $from, string $to): bool
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
			// Check if a transaction is already in progress
			if (!$this->db->inTransaction())
			{
				$this->db->beginTransaction();
				$startedTransaction = true;
			}

			// Check if resource supports simple booking
			$resource = $this->getSimpleBookingResource($resourceId);
			if (!$resource)
			{
				throw new \Exception("Resource does not support simple booking");
			}

			// Check availability
			$availability = $this->checkSimpleBookingAvailability($resourceId, $from, $to, $sessionId);
			if (!$availability['available'])
			{
				throw new \Exception("Timeslot is not available");
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
			if (!$this->createBlock($sessionId, $resourceId, $from, $to))
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
				'active' => 1,
				'secret' => $this->generate_secret()
			];

			// Insert the application
			$id = $this->savePartialApplication($application);

			// Add the resource to the application
			$this->saveApplicationResources($id, [$resourceId]);

			// Add the date to the application
			$this->saveApplicationDates($id, [['from_' => $from, 'to_' => $to]]);

			// Update ID string
			$this->update_id_string();

			// Only commit if we started the transaction
			if ($startedTransaction)
			{
				$this->db->commit();
			}

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
			throw $e;
		}
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
			$dates = $this->fetchDates($applicationId);
			$resourceIds = [];
			$resources = $this->fetchResources($applicationId);
			foreach ($resources as $resource)
			{
				$resourceIds[] = $resource['id'];
			}

			if (empty($dates) || empty($resourceIds))
			{
				return false;
			}

			// Cancel blocks
			$placeholders = implode(',', array_fill(0, count($resourceIds), '?'));
			$params = [$application['session_id']];
			$params = array_merge($params, $resourceIds);

			foreach ($dates as $date)
			{
				$sql = "UPDATE bb_block SET active = 0
                    WHERE session_id = ?
                    AND resource_id IN ($placeholders)
                    AND from_ = ?
                    AND to_ = ?";

				$stmt = $this->db->prepare($sql);
				$stmt->execute(array_merge($params, [$date['from_'], $date['to_']]));
			}

			return true;
		} catch (\Exception $e)
		{
			error_log("Error cancelling blocks: " . $e->getMessage());
			return false;
		}
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
					$collisionCheck = $this->checkCollisionWithDebug(
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

}