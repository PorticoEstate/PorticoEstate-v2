<?php

namespace App\modules\bookingfrontend\controllers;

use App\modules\bookingfrontend\helpers\ResponseHelper;
use App\modules\bookingfrontend\models\AgeGroup;
use App\modules\bookingfrontend\models\Audience;
use App\modules\bookingfrontend\models\Building;
use App\modules\booking\models\Document;
use App\modules\bookingfrontend\models\Season;
use DateTime;
use DateTimeZone;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Exception;
use App\modules\phpgwapi\services\Settings;
use App\Database\Db;

/**
 * @OA\Tag(
 *     name="Buildings",
 *     description="API Endpoints for Buildings"
 * )
 */
class BuildingController extends DocumentController
{
	private $db;
	private $userSettings;

	public function __construct(ContainerInterface $container)
	{
		parent::__construct(Document::OWNER_BUILDING);

		$this->db = Db::getInstance();
		$this->userSettings = Settings::getInstance()->get('user');
	}
	
	/**
	 * @OA\Get(
	 *     path="/bookingfrontend/towns",
	 *     summary="Get a list of all towns",
	 *     tags={"Towns"},
	 *     @OA\Response(
	 *         response=200,
	 *         description="A list of towns with id and name",
	 *         @OA\JsonContent(
	 *             type="array",
	 *             @OA\Items(
	 *                 @OA\Property(property="id", type="integer"),
	 *                 @OA\Property(property="name", type="string")
	 *             )
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=500,
	 *         description="Server error",
	 *         @OA\JsonContent(
	 *             @OA\Property(property="error", type="string")
	 *         )
	 *     )
	 * )
	 */
	public function getTowns(Request $request, Response $response): Response
	{
		try {
			$sql = "SELECT id, name FROM fm_part_of_town ORDER BY name";
			$stmt = $this->db->prepare($sql);
			$stmt->execute();
			$towns = $stmt->fetchAll(\PDO::FETCH_ASSOC);
			
			$response->getBody()->write(json_encode($towns));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
		} catch (Exception $e) {
			$error = "Error fetching towns: " . $e->getMessage();
			$response->getBody()->write(json_encode(['error' => $error]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	private function getUserRoles()
	{
		return $this->userSettings['groups'] ?? [];
	}

	/**
	 * @OA\Get(
	 *     path="/bookingfrontend/buildings",
	 *     summary="Get a list of all buildings",
	 *     tags={"Buildings"},
	 *     @OA\Parameter(
	 *         name="start",
	 *         in="query",
	 *         description="Starting index for pagination",
	 *         required=false,
	 *         @OA\Schema(type="integer", default=0)
	 *     ),
	 *     @OA\Parameter(
	 *         name="results",
	 *         in="query",
	 *         description="Number of results per page",
	 *         required=false,
	 *         @OA\Schema(type="integer")
	 *     ),
	 *     @OA\Response(
	 *         response=200,
	 *         description="A list of buildings",
	 *         @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/Building"))
	 *     ),
	 *     @OA\Response(
	 *         response=500,
	 *         description="Server error",
	 *         @OA\JsonContent(
	 *             @OA\Property(property="error", type="string")
	 *         )
	 *     )
	 * )
	 */
	public function index(Request $request, Response $response): Response
	{
		$maxMatches = isset($this->userSettings['preferences']['common']['maxmatchs']) ? (int)$this->userSettings['preferences']['common']['maxmatchs'] : 15;
		$queryParams = $request->getQueryParams();
		$start = isset($queryParams['start']) ? (int)$queryParams['start'] : 0;
		$perPage = isset($queryParams['results']) ? (int)$queryParams['results'] : $maxMatches;

		$sql = "SELECT bb_building.*, fm_part_of_town.id as town_id FROM bb_building 
          LEFT JOIN fm_locations ON bb_building.location_code = fm_locations.location_code
          LEFT JOIN fm_location1 ON fm_locations.loc1 = fm_location1.loc1
          LEFT JOIN fm_part_of_town ON fm_location1.part_of_town_id = fm_part_of_town.id
          ORDER BY bb_building.id";
		if ($perPage > 0)
		{
			$sql .= " LIMIT :limit OFFSET :start";
		}

		try
		{
			$stmt = $this->db->prepare($sql);
			if ($perPage > 0)
			{
				$stmt->bindParam(':limit', $perPage, \PDO::PARAM_INT);
				$stmt->bindParam(':start', $start, \PDO::PARAM_INT);
			}
			$stmt->execute();
			$results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

			$buildings = array_map(function ($data)
			{
				$building = new Building($data);
				return $building->serialize($this->getUserRoles());
			}, $results);

			$response->getBody()->write(json_encode($buildings));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
		} catch (Exception $e)
		{
			$error = "Error fetching buildings: " . $e->getMessage();
			$response->getBody()->write(json_encode(['error' => $error]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}


	/**
	 * @OA\Get(
	 *     path="/bookingfrontend/buildings/{id}",
	 *     summary="Get a specific building by ID",
	 *     tags={"Buildings"},
	 *     @OA\Parameter(
	 *         name="id",
	 *         in="path",
	 *         description="ID of the building to fetch",
	 *         required=true,
	 *         @OA\Schema(type="integer")
	 *     ),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Building details",
	 *         @OA\JsonContent(ref="#/components/schemas/Building")
	 *     ),
	 *     @OA\Response(
	 *         response=404,
	 *         description="Building not found"
	 *     )
	 * )
	 */
	public function show(Request $request, Response $response, array $args): Response
	{
		$buildingId = $args['id'];

		try
		{
			$sql = "SELECT bb_building.*, fm_part_of_town.id as town_id FROM bb_building 
          LEFT JOIN fm_locations ON bb_building.location_code = fm_locations.location_code
          LEFT JOIN fm_location1 ON fm_locations.loc1 = fm_location1.loc1
          LEFT JOIN fm_part_of_town ON fm_location1.part_of_town_id = fm_part_of_town.id
          WHERE bb_building.id = :id";
			$stmt = $this->db->prepare($sql);
			$stmt->bindParam(':id', $buildingId, \PDO::PARAM_INT);
			$stmt->execute();

			$result = $stmt->fetch(\PDO::FETCH_ASSOC);

			if (!$result)
			{
				$response->getBody()->write(json_encode(['error' => 'Building not found']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
			}

			$building = new Building($result);
			$response->getBody()->write(json_encode($building->serialize($this->getUserRoles())));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
		} catch (Exception $e)
		{
			$error = "Error fetching building: " . $e->getMessage();
			$response->getBody()->write(json_encode(['error' => $error]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}


	/**
	 * @OA\Get(
	 *     path="/bookingfrontend/buildings/{id}/agegroups",
	 *     summary="Get age groups for a building's activities",
	 *     tags={"Buildings"},
	 *     @OA\Parameter(
	 *         name="id",
	 *         in="path",
	 *         description="ID of the building",
	 *         required=true,
	 *         @OA\Schema(type="integer")
	 *     ),
	 *     @OA\Response(
	 *         response=200,
	 *         description="List of age groups",
	 *         @OA\JsonContent(
	 *             type="array",
	 *             @OA\Items(ref="#/components/schemas/AgeGroup")
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=404,
	 *         description="Building not found"
	 *     )
	 * )
	 */
	public function getAgeGroups(Request $request, Response $response, array $args): Response
	{
		try
		{
			$building_id = (int)$args['id'];
			$activity_id = null;

			// Verify building exists
			$sql = "SELECT id FROM bb_building WHERE id = :id";
			$stmt = $this->db->prepare($sql);
			$stmt->bindParam(':id', $building_id, \PDO::PARAM_INT);
			$stmt->execute();

			if (!$stmt->fetch(\PDO::FETCH_ASSOC))
			{
				return ResponseHelper::sendErrorResponse(
					['error' => 'Building not found'],
					404
				);
			}

			// Try to determine activity_id from building or resources
			$activity_id = $this->determineActivityId($building_id);

			// If activity_id is found, get its top level activity
			$top_level_activity = $activity_id ? $this->findTopLevelActivity($activity_id) : 0;

			// Get age groups based on top level activity (or all if none found)
			$sql = "SELECT * FROM bb_agegroup WHERE active = 1";
			$params = [];

			if ($top_level_activity)
			{
				$sql .= " AND activity_id = :activity_id";
				$params[':activity_id'] = $top_level_activity;
			}

			$sql .= " ORDER BY sort";

			$stmt = $this->db->prepare($sql);
			if ($params)
			{
				$stmt->execute($params);
			} else
			{
				$stmt->execute();
			}

			$results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

			$agegroups = array_map(function ($data)
			{
				$agegroup = new AgeGroup($data);
				return $agegroup->serialize();
			}, $results);

			$response->getBody()->write(json_encode($agegroups));
			return $response->withHeader('Content-Type', 'application/json');

		} catch (Exception $e)
		{
			return ResponseHelper::sendErrorResponse(
				['error' => "Error fetching age groups: " . $e->getMessage()],
				500
			);
		}
	}

	/**
	 * @OA\Get(
	 *     path="/bookingfrontend/buildings/{id}/audience",
	 *     summary="Get target audience for a building's activities",
	 *     tags={"Buildings"},
	 *     @OA\Parameter(
	 *         name="id",
	 *         in="path",
	 *         description="ID of the building",
	 *         required=true,
	 *         @OA\Schema(type="integer")
	 *     ),
	 *     @OA\Response(
	 *         response=200,
	 *         description="List of target audiences",
	 *         @OA\JsonContent(
	 *             type="array",
	 *             @OA\Items(ref="#/components/schemas/Audience")
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=404,
	 *         description="Building not found"
	 *     )
	 * )
	 */
	public function getAudience(Request $request, Response $response, array $args): Response
	{
		try
		{
			$building_id = (int)$args['id'];
			$activity_id = null;

			// Verify building exists
			$sql = "SELECT id FROM bb_building WHERE id = :id";
			$stmt = $this->db->prepare($sql);
			$stmt->bindParam(':id', $building_id, \PDO::PARAM_INT);
			$stmt->execute();

			if (!$stmt->fetch(\PDO::FETCH_ASSOC))
			{
				return ResponseHelper::sendErrorResponse(
					['error' => 'Building not found'],
					404
				);
			}

			// Try to determine activity_id from building or resources
			$activity_id = $this->determineActivityId($building_id);

			// If activity_id is found, get its top level activity
			$top_level_activity = $activity_id ? $this->findTopLevelActivity($activity_id) : 0;

			// Get target audience based on top level activity (or all if none found)
			$sql = "SELECT * FROM bb_targetaudience WHERE active = 1";
			$params = [];

			if ($top_level_activity)
			{
				$sql .= " AND activity_id = :activity_id";
				$params[':activity_id'] = $top_level_activity;
			}

			$sql .= " ORDER BY sort";

			$stmt = $this->db->prepare($sql);
			if ($params)
			{
				$stmt->execute($params);
			} else
			{
				$stmt->execute();
			}

			$results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

			$audiences = array_map(function ($data)
			{
				$audience = new Audience($data);
				return $audience->serialize();
			}, $results);

			$response->getBody()->write(json_encode($audiences));
			return $response->withHeader('Content-Type', 'application/json');

		} catch (Exception $e)
		{
			return ResponseHelper::sendErrorResponse(
				['error' => "Error fetching target audience: " . $e->getMessage()],
				500
			);
		}
	}

	private function findTopLevelActivity(int $activity_id): ?int
	{
		$sql = "WITH RECURSIVE activity_path AS (
            SELECT id, parent_id
            FROM bb_activity
            WHERE id = :activity_id
            UNION ALL
            SELECT a.id, a.parent_id
            FROM bb_activity a
            INNER JOIN activity_path ap ON a.id = ap.parent_id
        )
        SELECT id FROM activity_path
        WHERE parent_id IS NULL OR parent_id = 0
        LIMIT 1";

		$stmt = $this->db->prepare($sql);
		$stmt->bindParam(':activity_id', $activity_id, \PDO::PARAM_INT);
		$stmt->execute();

		$result = $stmt->fetch(\PDO::FETCH_ASSOC);
		return $result ? (int)$result['id'] : null;
	}

	private function determineActivityId(int $building_id): ?int
	{
		// 1. Check building's activity_id
		$sql = "SELECT activity_id FROM bb_building WHERE id = :id";
		$stmt = $this->db->prepare($sql);
		$stmt->bindParam(':id', $building_id, \PDO::PARAM_INT);
		$stmt->execute();
		$building = $stmt->fetch(\PDO::FETCH_ASSOC);

		if ($building && !empty($building['activity_id']))
		{
			return (int)$building['activity_id'];
		}

		// 2. Check resources' activity_id
		$sql = "SELECT DISTINCT r.activity_id
                FROM bb_resource r
                JOIN bb_building_resource br ON r.id = br.resource_id
                WHERE br.building_id = :building_id
                AND r.activity_id IS NOT NULL
                LIMIT 1";
		$stmt = $this->db->prepare($sql);
		$stmt->bindParam(':building_id', $building_id, \PDO::PARAM_INT);
		$stmt->execute();
		$resource = $stmt->fetch(\PDO::FETCH_ASSOC);

		if ($resource && !empty($resource['activity_id']))
		{
			return (int)$resource['activity_id'];
		}

		return null;
	}


	/**
	 * @OA\Get(
	 *     path="/bookingfrontend/buildings/{id}/seasons",
	 *     summary="Get seasons for a building",
	 *     tags={"Buildings"},
	 *     @OA\Parameter(
	 *         name="id",
	 *         in="path",
	 *         description="ID of the building",
	 *         required=true,
	 *         @OA\Schema(type="integer")
	 *     ),
	 *     @OA\Parameter(
	 *         name="from",
	 *         in="query",
	 *         description="Start date (defaults to today)",
	 *         required=false,
	 *         @OA\Schema(type="string", format="date-time", example="2025-03-17T00:00:00+01:00")
	 *     ),
	 *     @OA\Parameter(
	 *         name="to",
	 *         in="query",
	 *         description="End date (optional)",
	 *         required=false,
	 *         @OA\Schema(type="string", format="date-time", example="2025-06-17T23:59:59+02:00")
	 *     ),
	 *     @OA\Response(
	 *         response=200,
	 *         description="List of seasons with associated resources and boundaries",
	 *         @OA\JsonContent(
	 *             type="array",
	 *             @OA\Items(ref="#/components/schemas/Season")
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=404,
	 *         description="Building not found",
	 *         @OA\JsonContent(
	 *             @OA\Property(property="error", type="string", example="Building not found")
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=500,
	 *         description="Server error",
	 *         @OA\JsonContent(
	 *             @OA\Property(property="error", type="string")
	 *         )
	 *     )
	 * )
	 */
	public function getSeasons(Request $request, Response $response, array $args): Response
	{
		try
		{
			$building_id = (int)$args['id'];

			// Verify building exists
			$sql = "SELECT id FROM bb_building WHERE id = :id";
			$stmt = $this->db->prepare($sql);
			$stmt->bindParam(':id', $building_id, \PDO::PARAM_INT);
			$stmt->execute();

			if (!$stmt->fetch())
			{
				return ResponseHelper::sendErrorResponse(
					['error' => 'Building not found'],
					404
				);
			}

			$queryParams = $request->getQueryParams();

			// Default to today at 00:00:00
			$fromDate = new DateTime(($queryParams['from'] ?? 'today'), new DateTimeZone('Europe/Oslo'));
			$fromDate->setTime(0, 0, 0);

			// Create to date if provided, otherwise use from date
			if (isset($queryParams['to']))
			{
				$toDate = new DateTime($queryParams['to'], new DateTimeZone('Europe/Oslo'));
				$toDate->setTime(23, 59, 59);
			}

			// Get season boundaries
			// Get season boundaries
			$sql = "SELECT s.*,
						sb.id as boundary_id,
						sb.from_ as boundary_from,
						sb.to_ as boundary_to,
						sb.wday,
						r.id as resource_id,
						r.name as resource_name,
						r.activity_id,
						r.simple_booking,
						r.active as resource_active,
						act.name as activity_name
					FROM bb_season s
					LEFT JOIN bb_season_boundary sb ON s.id = sb.season_id
					LEFT JOIN bb_season_resource sr ON s.id = sr.season_id
					LEFT JOIN bb_resource r ON sr.resource_id = r.id
					LEFT JOIN bb_activity act ON r.activity_id = act.id
					WHERE s.building_id = :building_id
					AND s.active = 1
					AND s.status = 'PUBLISHED'
					AND (r.active = 1 OR r.active IS NULL)
					AND (r.hidden_in_frontend = 0 OR r.hidden_in_frontend IS NULL)";

			$params = [':building_id' => $building_id];

			$sql .= " AND s.to_ >= :from";
			$params[':from'] = $fromDate->format('Y-m-d H:i:s');

			if (isset($toDate))
			{
				$sql .= " AND s.from_ <= :to";
				$params[':to'] = $toDate->format('Y-m-d H:i:s');
			}

			$sql .= " ORDER BY s.from_, r.name";

			$stmt = $this->db->prepare($sql);
			$stmt->execute($params);
			$results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

			// Group results by season
			$seasonsMap = [];
			foreach ($results as $row)
			{
				$seasonId = $row['id'];

				// Format timestamps
				$from = new DateTime($row['from_'], new DateTimeZone('Europe/Oslo'));
				$from->setTime(0, 0, 0);
				$row['from_'] = $from->format('c');

				$to = new DateTime($row['to_'], new DateTimeZone('Europe/Oslo'));
				$to->setTime(23, 59, 59);
				$row['to_'] = $to->format('c');

				if (!empty($row['boundary_from']))
				{
					$boundaryFrom = explode(' ', $row['boundary_from'])[1];
					$boundaryTo = explode(' ', $row['boundary_to'])[1];
				}

				// Update the grouping logic:
				if (!isset($seasonsMap[$seasonId]))
				{
					// Initialize season
					$seasonsMap[$seasonId] = [
						'id' => $row['id'],
						'name' => $row['name'],
						'building_id' => $row['building_id'],
						'from_' => $row['from_'],
						'to_' => $row['to_'],
						'active' => $row['active'],
						'status' => $row['status'],
						'resources' => [],
						'boundaries' => []
					];
				}
				if (!empty($row['boundary_id'])) {
					$boundaryKey = $row['boundary_id'];
					if (!isset($seasonsMap[$seasonId]['boundaries'][$boundaryKey])) {
						$seasonsMap[$seasonId]['boundaries'][$boundaryKey] = [
							'from_' => $row['boundary_from'],
							'to_' => $row['boundary_to'],
							'wday' => (int)$row['wday']
						];
					}
				}

				// Add resource if it exists
				if (!empty($row['resource_id']))
				{
					$seasonsMap[$seasonId]['resources'][] = [
						'id' => $row['resource_id'],
						'name' => $row['resource_name'],
						'activity_id' => $row['activity_id'],
						'activity_name' => $row['activity_name'],
						'simple_booking' => $row['simple_booking'],
						'active' => $row['resource_active']
					];
				}
			}
			foreach ($seasonsMap as &$season) {
				$season['boundaries'] = array_values($season['boundaries']);
			}
			$seasons = array_map(function ($data)
			{
				$season = new Season($data);
				return $season->serialize();
			}, array_values($seasonsMap));

			$response->getBody()->write(json_encode($seasons));
			return $response->withHeader('Content-Type', 'application/json');

		} catch (Exception $e)
		{
			return ResponseHelper::sendErrorResponse(
				['error' => "Error fetching seasons: " . $e->getMessage()],
				500
			);
		}
	}
}