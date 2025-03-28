<?php
namespace App\modules\bookingfrontend\controllers;

use PDO;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Exception; // For handling potential errors
use App\Database\Db;
use App\modules\bookingfrontend\models\Activity;
use App\modules\bookingfrontend\models\Building;
use App\modules\bookingfrontend\models\Resource;
use App\modules\bookingfrontend\models\Organization;

/**
 * @OA\OpenApi(
 *    @OA\Server(url="http://localhost:8080"),
 *   @OA\Info(
 *    title="Portico API",
 *   version="1.0.0",
 *  description="Portico API",
 * @OA\Contact(
 * email="sigurdne@gmail.com"
 * )
 * )
 * )
 */

class DataStore
{
    private $db;

    public function __construct(ContainerInterface $container)
	{
		$this->db = Db::getInstance();

	}
	/**
	 * @OA\Get(
	 *     path="/bookingfrontend/searchdataall",
	 *     summary="Get various search data",
	 *     tags={"Search Data"},
	 *     @OA\Response(
	 *         response=200,
	 *         description="Successful response",
	 *         @OA\JsonContent(
	 *             type="object",
	 *             @OA\Property(property="activities", type="array", @OA\Items(ref="#/components/schemas/Activity")),
	 *             @OA\Property(property="buildings", type="array", @OA\Items(ref="#/components/schemas/Building")),
	 *             @OA\Property(property="building_resources", type="array", @OA\Items(ref="#/components/schemas/BuildingResource")),
	 *             @OA\Property(property="facilities", type="array", @OA\Items(ref="#/components/schemas/Facility")),
	 *             @OA\Property(property="resources", type="array", @OA\Items(ref="#/components/schemas/Resource")),
	 *             @OA\Property(property="resource_activities", type="array", @OA\Items(ref="#/components/schemas/ResourceActivity")),
	 *             @OA\Property(property="resource_facilities", type="array", @OA\Items(ref="#/components/schemas/ResourceFacility")),
	 *             @OA\Property(property="resource_categories", type="array", @OA\Items(ref="#/components/schemas/ResourceCategory")),
	 *             @OA\Property(property="resource_category_activity", type="array", @OA\Items(ref="#/components/schemas/ResourceCategoryActivity")),
	 *             @OA\Property(property="towns", type="array", @OA\Items(ref="#/components/schemas/Town")),
	 *             @OA\Property(property="organizations", type="array", @OA\Items(ref="#/components/schemas/Organization"))
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=500,
	 *         description="Internal server error",
	 *         @OA\JsonContent(
	 *             type="object",
	 *             @OA\Property(property="error", type="string")
	 *         )
	 *     )
	 * )
	 */

	public function SearchDataAll(Request $request, Response $response): Response
	{
		try {
			$data = [
				'activities' => $this->getRowsAsArray("SELECT * from bb_activity where active=1"),
				'buildings' => $this->getRowsAsArray("SELECT id, activity_id, deactivate_calendar, deactivate_application,"
				. " deactivate_sendmessage, extra_kalendar, name, homepage, location_code, phone, email, tilsyn_name, tilsyn_phone,"
				. " tilsyn_email, tilsyn_name2, tilsyn_phone2, tilsyn_email2, street, zip_code, district, city, calendar_text, opening_hours"
				. " FROM bb_building WHERE active=1"),
				'building_resources' => $this->getRowsAsArray("SELECT * from bb_building_resource"),
				'facilities' => $this->getRowsAsArray("SELECT * from bb_facility where active=1"),
				'resources' => $this->getRowsAsArray("SELECT * from bb_resource where active=1 and hidden_in_frontend=0 and deactivate_calendar=0"),
				'resource_activities' => $this->getRowsAsArray("SELECT * from bb_resource_activity"),
				'resource_facilities' => $this->getRowsAsArray("SELECT * from bb_resource_facility"),
				'resource_categories' => $this->getRowsAsArray("SELECT * from bb_rescategory where active=1"),
				'resource_category_activity' => $this->getRowsAsArray("SELECT * from bb_rescategory_activity"),
				'towns' => $this->getRowsAsArray("SELECT DISTINCT bb_building.id as b_id, bb_building.name as b_name, fm_part_of_town.id, fm_part_of_town.name FROM"
					. " bb_building JOIN fm_locations ON bb_building.location_code = fm_locations.location_code"
					. " JOIN fm_location1 ON fm_locations.loc1 = fm_location1.loc1"
					. " JOIN fm_part_of_town ON fm_location1.part_of_town_id = fm_part_of_town.id"
					. " where bb_building.active=1"),
				'organizations' => $this->getRowsAsArray("SELECT id, organization_number, name, homepage, phone, email, co_address,"
				. " street, zip_code, district, city, activity_id, show_in_portal"
				. " FROM bb_organization WHERE active=1 AND show_in_portal=1"),
			];

			$response->getBody()->write(json_encode($data));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
		} catch (Exception $e) {
			// Handle database error (e.g., log the error, return an error response)
			$error = "Error fetching data: " . $e->getMessage();
			$response->getBody()->write(json_encode(['error' => $error]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}


	public function SearchDataAllOptimised(Request $request, Response $response): Response
	{
		try {
			$data = [];
			
			// Activities
			$activities = [];
			$rows = $this->getRowsAsArray("SELECT id, parent_id, name, active from bb_activity where active=1");
			foreach ($rows as $row) {
				$activity = new Activity($row);
				$activities[] = $activity->serialize([], true);
			}
			$data['activities'] = $activities;
			
			// Buildings
			$buildings = [];
			$rows = $this->getRowsAsArray("SELECT id, activity_id, deactivate_calendar, deactivate_application,"
				. " deactivate_sendmessage, extra_kalendar, name, location_code, street, zip_code, district, city"
				. " FROM bb_building WHERE active=1");
			foreach ($rows as $row) {
				$building = new Building($row);
				$buildings[] = $building->serialize([], true);
			}
			$data['buildings'] = $buildings;
			
			// Building resources (no model yet, use array)
			$data['building_resources'] = $this->getRowsAsArray("SELECT * from bb_building_resource");
			
			// Resources
			$resources = [];
			$rows = $this->getRowsAsArray("SELECT id, name, activity_id, active, simple_booking, deactivate_calendar,
              deactivate_application
              FROM bb_resource WHERE active=1 AND hidden_in_frontend=0 AND deactivate_calendar=0");
			foreach ($rows as $row) {
				$resource = new Resource($row);
				$resources[] = $resource->serialize([], true);
			}
			$data['resources'] = $resources;
			
			// Towns (no model yet, use array)
			$data['towns'] = $this->getRowsAsArray("SELECT DISTINCT bb_building.id as b_id, bb_building.name as b_name, fm_part_of_town.id, fm_part_of_town.name FROM"
				. " bb_building JOIN fm_locations ON bb_building.location_code = fm_locations.location_code"
				. " JOIN fm_location1 ON fm_locations.loc1 = fm_location1.loc1"
				. " JOIN fm_part_of_town ON fm_location1.part_of_town_id = fm_part_of_town.id"
				. " where bb_building.active=1");
			
			// Organizations
			$organizations = [];
			$rows = $this->getRowsAsArray("SELECT id, organization_number, name, homepage, phone, email, co_address,"
				. " street, zip_code, district, city, activity_id, show_in_portal"
				. " FROM bb_organization WHERE active=1 AND show_in_portal=1");
			foreach ($rows as $row) {
				$organization = new Organization($row);
				$organizations[] = $organization->serialize([], true);
			}
			$data['organizations'] = $organizations;

			$response->getBody()->write(json_encode($data));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
		} catch (Exception $e) {
			// Handle database error (e.g., log the error, return an error response)
			$error = "Error fetching data: " . $e->getMessage();
			$response->getBody()->write(json_encode(['error' => $error]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}


	public function getRowsAsArray($sql, $params = [])
	{
		$values = array();
		$stmt = $this->db->prepare($sql);
		$stmt->execute($params);
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$values[] = $row;
		}
		return $values;
	}

	/**
	 * Get resources that have at least a 30 minute opening on the specified date
	 * 
	 * @OA\Get(
	 *     path="/bookingfrontend/availableresources",
	 *     summary="Get resources with at least a 30 minute availability for a specific date",
	 *     tags={"Resources"},
	 *     @OA\Parameter(
	 *         name="date",
	 *         in="query",
	 *         description="Date to check (YYYY-MM-DD)",
	 *         required=true,
	 *         @OA\Schema(type="string", format="date")
	 *     ),
	 *     @OA\Parameter(
	 *         name="debug",
	 *         in="query",
	 *         description="Enable debug mode to see why resources were excluded",
	 *         required=false,
	 *         @OA\Schema(type="boolean")
	 *     ),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Successful response",
	 *         @OA\JsonContent(
	 *             type="object",
	 *             @OA\Property(property="resources", type="array", @OA\Items(type="integer")),
	 *             @OA\Property(
	 *                 property="debug_info",
	 *                 type="object",
	 *                 description="Only present when debug=true",
	 *                 additionalProperties=true
	 *             )
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=400,
	 *         description="Invalid date format",
	 *         @OA\JsonContent(
	 *             type="object",
	 *             @OA\Property(property="error", type="string")
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=500,
	 *         description="Internal server error",
	 *         @OA\JsonContent(
	 *             type="object",
	 *             @OA\Property(property="error", type="string")
	 *         )
	 *     )
	 * )
	 */
	public function getAvailableResources(Request $request, Response $response): Response
	{
		try {
			// Get the query parameters
			$params = $request->getQueryParams();
			$date = $params['date'] ?? date('Y-m-d');
			$debug = isset($params['debug']) && ($params['debug'] === 'true' || $params['debug'] === '1');
			
			// Validate date format
			$dateObj = \DateTime::createFromFormat('Y-m-d', $date);
			if (!$dateObj || $dateObj->format('Y-m-d') !== $date) {
				$response->getBody()->write(json_encode(['error' => 'Invalid date format. Use YYYY-MM-DD']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
			}
			
			// Get day of week (1-7, where 1 is Monday and 7 is Sunday)
			$dayOfWeek = $dateObj->format('N');
			
			// Initialize debug info
			$debugInfo = [];
			
			// Get all resources
			$resources = $this->getRowsAsArray(
				"SELECT r.id, r.name 
				FROM bb_resource r"
			);
			
			// Keep track of all resources for debug
			if ($debug) {
				$debugInfo['all_resources'] = [];
				foreach ($resources as $resource) {
					$debugInfo['all_resources'][$resource['id']] = [
						'id' => $resource['id'],
						'name' => $resource['name'],
						'reason' => 'Not processed yet'
					];
				}
			}
			
			// Extract resource IDs
			$resourceIds = array_map(function($r) { return $r['id']; }, $resources);
			$resourceNames = [];
			foreach ($resources as $r) {
				$resourceNames[$r['id']] = $r['name'];
			}
			
			if (empty($resourceIds)) {
				$result = ['resources' => []];
				if ($debug) {
					$result['debug_info'] = ['error' => 'No resources found'];
				}
				$response->getBody()->write(json_encode($result));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
			}
			
			// Filter active resources
			$activeResources = $this->getRowsAsArray(
				"SELECT r.id 
				FROM bb_resource r 
				WHERE r.active = 1 
				AND r.hidden_in_frontend = 0 
				AND r.deactivate_calendar = 0"
			);
			
			$activeResourceIds = array_map(function($r) { return $r['id']; }, $activeResources);
			
			// Update debug info for inactive resources
			if ($debug) {
				foreach ($resourceIds as $id) {
					if (!in_array($id, $activeResourceIds)) {
						$debugInfo['all_resources'][$id]['reason'] = 'Resource is inactive, hidden, or calendar deactivated';
					}
				}
			}
			
			if (empty($activeResourceIds)) {
				$result = ['resources' => []];
				if ($debug) {
					$result['debug_info'] = $debugInfo;
				}
				$response->getBody()->write(json_encode($result));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
			}
			
			// Get seasons and boundaries for these resources on the specified date
			$seasonBoundaries = $this->getRowsAsArray(
				"SELECT DISTINCT r.id as resource_id, 
					sb.from_ as start_time, 
					sb.to_ as end_time
				FROM bb_resource r
				JOIN bb_season_resource sr ON r.id = sr.resource_id
				JOIN bb_season s ON sr.season_id = s.id
				JOIN bb_season_boundary sb ON s.id = sb.season_id
				WHERE r.id IN (" . implode(',', $activeResourceIds) . ")
				AND s.status = 'PUBLISHED'
				AND s.active = 1
				AND sb.wday = :dayOfWeek
				AND s.from_ <= :date
				AND s.to_ >= :date",
				[
					':dayOfWeek' => $dayOfWeek,
					':date' => $date
				]
			);
			
			// Group boundaries by resource ID
			$resourceBoundaryMap = [];
			foreach ($seasonBoundaries as $boundary) {
				$resourceId = $boundary['resource_id'];
				if (!isset($resourceBoundaryMap[$resourceId])) {
					$resourceBoundaryMap[$resourceId] = [];
				}
				$resourceBoundaryMap[$resourceId][] = $boundary;
			}
			
			// Update debug info for resources without boundaries
			if ($debug) {
				foreach ($activeResourceIds as $id) {
					if (!isset($resourceBoundaryMap[$id])) {
						$debugInfo['all_resources'][$id]['reason'] = 'No season boundary for this day or date not in active season';
					}
				}
			}
			
			// Get all bookings for the current date
			$bookings = $this->getRowsAsArray(
				"SELECT DISTINCT br.resource_id,
					b.from_ as booking_start,
					b.to_ as booking_end
				FROM bb_booking b
				JOIN bb_booking_resource br ON b.id = br.booking_id
				WHERE br.resource_id IN (" . implode(',', $activeResourceIds) . ")
				AND b.active = 1
				AND ((b.from_ >= :date_start AND b.from_ < :date_end)
					OR (b.to_ > :date_start AND b.to_ <= :date_end)
					OR (b.from_ < :date_start AND b.to_ > :date_end))",
				[
					':date_start' => $date . ' 00:00:00',
					':date_end' => $date . ' 23:59:59'
				]
			);
			
			// Group bookings by resource ID
			$resourceBookingsMap = [];
			foreach ($bookings as $booking) {
				$resourceId = $booking['resource_id'];
				if (!isset($resourceBookingsMap[$resourceId])) {
					$resourceBookingsMap[$resourceId] = [];
				}
				$resourceBookingsMap[$resourceId][] = $booking;
			}
			
			// Get all allocations for the current date
			$allocations = $this->getRowsAsArray(
				"SELECT DISTINCT ar.resource_id,
					a.from_ as alloc_start,
					a.to_ as alloc_end
				FROM bb_allocation a
				JOIN bb_allocation_resource ar ON a.id = ar.allocation_id
				WHERE ar.resource_id IN (" . implode(',', $activeResourceIds) . ")
				AND a.active = 1
				AND ((a.from_ >= :date_start AND a.from_ < :date_end)
					OR (a.to_ > :date_start AND a.to_ <= :date_end)
					OR (a.from_ < :date_start AND a.to_ > :date_end))",
				[
					':date_start' => $date . ' 00:00:00',
					':date_end' => $date . ' 23:59:59'
				]
			);
			
			// Group allocations by resource ID
			$resourceAllocationsMap = [];
			foreach ($allocations as $allocation) {
				$resourceId = $allocation['resource_id'];
				if (!isset($resourceAllocationsMap[$resourceId])) {
					$resourceAllocationsMap[$resourceId] = [];
				}
				$resourceAllocationsMap[$resourceId][] = $allocation;
			}
			
			// Process each resource to check availability
			$availableResources = [];
			
			foreach ($activeResourceIds as $resourceId) {
				// Skip if resource has no boundaries (not available this day)
				if (!isset($resourceBoundaryMap[$resourceId])) {
					continue;
				}
				
				$resourceBookings = $resourceBookingsMap[$resourceId] ?? [];
				$resourceAllocations = $resourceAllocationsMap[$resourceId] ?? [];
				
				$isAvailable = false;
				$availableMinutesPerBoundary = [];
				
				// Check for each boundary if there's at least 30 minutes available
				foreach ($resourceBoundaryMap[$resourceId] as $boundary) {
					$startTime = strtotime($date . ' ' . $boundary['start_time']);
					$endTime = strtotime($date . ' ' . $boundary['end_time']);
					$boundaryDuration = ($endTime - $startTime) / 60; // duration in minutes
					
					// Skip if boundary is less than 30 minutes
					if ($boundaryDuration < 30) {
						if ($debug) {
							$availableMinutesPerBoundary[] = [
								'boundary' => $date . ' ' . $boundary['start_time'] . ' - ' . $date . ' ' . $boundary['end_time'],
								'duration' => $boundaryDuration,
								'reason' => 'Boundary too short (less than 30 minutes)'
							];
						}
						continue;
					}
					
					// Check overlap with bookings and allocations
					$totalUnavailableMinutes = 0;
					$overlappingBookings = [];
					$overlappingAllocations = [];
					
					// Process bookings
					foreach ($resourceBookings as $booking) {
						$bookingStart = strtotime($booking['booking_start']);
						$bookingEnd = strtotime($booking['booking_end']);
						
						// If booking is outside boundary, skip
						if ($bookingEnd <= $startTime || $bookingStart >= $endTime) {
							continue;
						}
						
						// Calculate overlap duration
						$overlapStart = max($startTime, $bookingStart);
						$overlapEnd = min($endTime, $bookingEnd);
						$overlapDuration = ($overlapEnd - $overlapStart) / 60; // minutes
						
						$totalUnavailableMinutes += $overlapDuration;
						
						if ($debug) {
							$overlappingBookings[] = [
								'time' => date('H:i', $bookingStart) . ' - ' . date('H:i', $bookingEnd),
								'duration' => $overlapDuration
							];
						}
					}
					
					// Process allocations
					foreach ($resourceAllocations as $allocation) {
						$allocStart = strtotime($allocation['alloc_start']);
						$allocEnd = strtotime($allocation['alloc_end']);
						
						// If allocation is outside boundary, skip
						if ($allocEnd <= $startTime || $allocStart >= $endTime) {
							continue;
						}
						
						// Calculate overlap duration
						$overlapStart = max($startTime, $allocStart);
						$overlapEnd = min($endTime, $allocEnd);
						$overlapDuration = ($overlapEnd - $overlapStart) / 60; // minutes
						
						$totalUnavailableMinutes += $overlapDuration;
						
						if ($debug) {
							$overlappingAllocations[] = [
								'time' => date('H:i', $allocStart) . ' - ' . date('H:i', $allocEnd),
								'duration' => $overlapDuration
							];
						}
					}
					
					// Check if at least 30 minutes are available
					$availableMinutes = $boundaryDuration - $totalUnavailableMinutes;
					
					if ($debug) {
						$boundaryInfo = [
							'boundary' => date('H:i', $startTime) . ' - ' . date('H:i', $endTime),
							'total_duration' => $boundaryDuration,
							'booked_duration' => $totalUnavailableMinutes,
							'available_duration' => $availableMinutes,
							'has_30min_available' => $availableMinutes >= 30,
						];
						
						if (!empty($overlappingBookings)) {
							$boundaryInfo['bookings'] = $overlappingBookings;
						}
						
						if (!empty($overlappingAllocations)) {
							$boundaryInfo['allocations'] = $overlappingAllocations;
						}
						
						if ($availableMinutes < 30) {
							$boundaryInfo['reason'] = 'Less than 30 minutes available in this boundary';
						}
						
						$availableMinutesPerBoundary[] = $boundaryInfo;
					}
					
					if ($availableMinutes >= 30) {
						$isAvailable = true;
						if (!$debug) {
							break; // Found an available slot, no need to check other boundaries (skip in debug mode)
						}
					}
				}
				
				if ($isAvailable) {
					$availableResources[] = $resourceId;
					
					if ($debug) {
						$debugInfo['all_resources'][$resourceId]['reason'] = 'Available (at least 30 minutes)';
						$debugInfo['all_resources'][$resourceId]['boundaries'] = $availableMinutesPerBoundary;
					}
				} else if ($debug) {
					$debugInfo['all_resources'][$resourceId]['reason'] = 'No 30-minute slot available in any boundary';
					$debugInfo['all_resources'][$resourceId]['boundaries'] = $availableMinutesPerBoundary;
				}
			}
			
			// Prepare response
			$result = ['resources' => $availableResources];
			
			if ($debug) {
				// Convert debug info to a more readable format
				$formattedDebugInfo = [];
				foreach ($debugInfo['all_resources'] as $id => $info) {
					$info['name'] = $resourceNames[$id] ?? 'Unknown';
					$formattedDebugInfo[$id] = $info;
				}
				
				$result['debug_info'] = [
					'date' => $date,
					'day_of_week' => $dayOfWeek,
					'resources' => $formattedDebugInfo,
					'available_count' => count($availableResources),
					'total_resources' => count($resourceIds)
				];
			}
			
			// Return the list of available resource IDs
			$response->getBody()->write(json_encode($result));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
			
		} catch (Exception $e) {
			// Handle errors
			$error = "Error fetching available resources: " . $e->getMessage();
			$response->getBody()->write(json_encode(['error' => $error]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

}