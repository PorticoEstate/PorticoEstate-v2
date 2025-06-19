<?php

namespace App\modules\bookingfrontend\controllers;

use App\modules\bookingfrontend\models\Document;
use App\modules\bookingfrontend\models\Resource;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Exception;
use App\modules\phpgwapi\services\Settings;
use App\Database\Db;
use App\modules\bookingfrontend\helpers\ResponseHelper;

/**
 * @OA\Tag(
 *     name="Resources",
 *     description="API Endpoints for Resources"
 * )
 */
class ResourceController extends DocumentController
{
    private $db;
    private $userSettings;
    private $buildingScheduleService;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct(Document::OWNER_RESOURCE);
        $this->db = Db::getInstance();
        $this->userSettings = Settings::getInstance()->get('user');
        $this->buildingScheduleService = new \App\modules\bookingfrontend\services\BuildingScheduleService();
    }

    private function getUserRoles()
    {
        return $this->userSettings['groups'] ?? [];
    }

    /**
     * @OA\Get(
     *     path="/bookingfrontend/resources",
     *     summary="Get a list of active and visible resources",
     *     tags={"Resources"},
     *     @OA\Parameter(
     *         name="start",
     *         in="query",
     *         description="Start index for pagination",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="results",
     *         in="query",
     *         description="Number of results per page",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="sort",
     *         in="query",
     *         description="Sort field",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *          name="short",
     *          in="query",
     *          description="If set to 1, returns only a subset of fields",
     *          required=false,
     *          @OA\Schema(type="integer", enum={0, 1})
     *      ),
     *     @OA\Parameter(
     *         name="dir",
     *         in="query",
     *         description="Sort direction (asc or desc)",
     *         required=false,
     *         @OA\Schema(type="string", enum={"asc", "desc"})
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="A list of active and visible resources",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="total_records", type="integer"),
     *             @OA\Property(property="start", type="integer"),
     *             @OA\Property(property="sort", type="string"),
     *             @OA\Property(property="dir", type="string"),
     *             @OA\Property(
     *                 property="results",
     *                 type="array",
     *                 @OA\Items(ref="#/components/schemas/Resource")
     *             )
     *         )
     *     )
     * )
     */
    public function index(Request $request, Response $response): Response
    {
        $maxMatches = isset($this->userSettings['preferences']['common']['maxmatchs']) ? (int)$this->userSettings['preferences']['common']['maxmatchs'] : 15;
        $queryParams = $request->getQueryParams();
        $start = isset($queryParams['start']) ? (int)$queryParams['start'] : 0;
        $short = isset($queryParams['short']) && $queryParams['short'] == '1';
        $perPage = isset($queryParams['results']) ? (int)$queryParams['results'] : $maxMatches;
        $sort = $queryParams['sort'] ?? 'id';
        $dir = $queryParams['dir'] ?? 'asc';

        // Validate and sanitize the sort field to prevent SQL injection
        $allowedSortFields = ['id', 'name', 'activity_id', 'sort'];
        $sort = in_array($sort, $allowedSortFields) ? $sort : 'id';
        $dir = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';

        // Get current date for participant limit filtering
        $currentDate = date('Y-m-d H:i:s');
        
        $sql = "SELECT r.*, br.building_id, pl.quantity as participant_limit
                FROM bb_resource r
                LEFT JOIN bb_building_resource br ON r.id = br.resource_id
                LEFT JOIN (
                    SELECT pl.resource_id, pl.quantity 
                    FROM bb_participant_limit pl
                    INNER JOIN (
                        SELECT resource_id, MAX(from_) as latest_from
                        FROM bb_participant_limit
                        WHERE from_ <= :current_date
                        GROUP BY resource_id
                    ) latest ON pl.resource_id = latest.resource_id AND pl.from_ = latest.latest_from
                ) pl ON r.id = pl.resource_id
                WHERE r.active = 1 AND (r.hidden_in_frontend = 0 OR r.hidden_in_frontend IS NULL)
                ORDER BY r.$sort $dir";

        if ($perPage > 0)
        {
            $sql .= " LIMIT :limit OFFSET :start";
        }

        try
        {
            $stmt = $this->db->prepare($sql);
            $stmt->bindParam(':current_date', $currentDate);
            if ($perPage > 0)
            {
                $stmt->bindParam(':limit', $perPage, \PDO::PARAM_INT);
                $stmt->bindParam(':start', $start, \PDO::PARAM_INT);
            }
            $stmt->execute();
            $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $resources = array_map(function ($data) use ($short)
            {
                $resource = new Resource($data);
                return $resource->serialize($this->getUserRoles(), $short);
            }, $results);

            $totalCount = $this->getTotalCount();

            $responseData = [
                'total_records' => $totalCount,
                'start' => $start,
                'sort' => $sort,
                'dir' => $dir,
                'results' => $resources
            ];

            $response->getBody()->write(json_encode($responseData));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (Exception $e)
        {
            $error = "Error fetching resources: " . $e->getMessage();
            $response->getBody()->write(json_encode(['error' => $error]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }


    /**
     * @OA\Get(
     *     path="/bookingfrontend/resources/{id}",
     *     summary="Get a specific resource by ID",
     *     tags={"Resources"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID of the resource to fetch",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="The requested resource",
     *         @OA\JsonContent(ref="#/components/schemas/Resource")
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Resource not found"
     *     )
     * )
     */
    public function getResource(Request $request, Response $response, array $args): Response
    {
        $resourceId = (int)$args['id'];

        $currentDate = date('Y-m-d H:i:s');
        
        $sql = "SELECT r.*, br.building_id, pl.quantity as participant_limit
                FROM bb_resource r
                LEFT JOIN bb_building_resource br ON r.id = br.resource_id
                LEFT JOIN (
                    SELECT pl.resource_id, pl.quantity 
                    FROM bb_participant_limit pl
                    INNER JOIN (
                        SELECT resource_id, MAX(from_) as latest_from
                        FROM bb_participant_limit
                        WHERE from_ <= :current_date
                        GROUP BY resource_id
                    ) latest ON pl.resource_id = latest.resource_id AND pl.from_ = latest.latest_from
                ) pl ON r.id = pl.resource_id
                WHERE r.id = :id";

        try
        {
            $stmt = $this->db->prepare($sql);
            $stmt->bindParam(':id', $resourceId, \PDO::PARAM_INT);
            $stmt->bindParam(':current_date', $currentDate);
            $stmt->execute();
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$result)
            {
                $response->getBody()->write(json_encode(['error' => 'Resource not found']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }

            $resource = new Resource($result);
            $serializedResource = $resource->serialize($this->getUserRoles());

            $response->getBody()->write(json_encode($serializedResource));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (Exception $e)
        {
            $error = "Error fetching resource: " . $e->getMessage();
            $response->getBody()->write(json_encode(['error' => $error]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * @OA\Get(
     *     path="/bookingfrontend/buildings/{id}/resources",
     *     summary="Get a list of active and visible resources for a specific building",
     *     tags={"Buildings", "Resources"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID of the building",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="start",
     *         in="query",
     *         description="Start index for pagination",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="results",
     *         in="query",
     *         description="Number of results per page",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *      @OA\Parameter(
     *           name="short",
     *           in="query",
     *           description="If set to 1, returns only a subset of fields",
     *           required=false,
     *           @OA\Schema(type="integer", enum={0, 1})
     *     ),
     *     @OA\Parameter(
     *         name="sort",
     *         in="query",
     *         description="Sort field",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="dir",
     *         in="query",
     *         description="Sort direction (asc or desc)",
     *         required=false,
     *         @OA\Schema(type="string", enum={"asc", "desc"})
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="A list of active and visible resources for the specified building",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="total_records", type="integer"),
     *             @OA\Property(property="start", type="integer"),
     *             @OA\Property(property="sort", type="string"),
     *             @OA\Property(property="dir", type="string"),
     *             @OA\Property(
     *                 property="results",
     *                 type="array",
     *                 @OA\Items(ref="#/components/schemas/Resource")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Building not found"
     *     )
     * )
     */
    public function getResourcesByBuilding(Request $request, Response $response, array $args): Response
    {
        $buildingId = (int)$args['id'];
        $maxMatches = isset($this->userSettings['preferences']['common']['maxmatchs']) ? (int)$this->userSettings['preferences']['common']['maxmatchs'] : 15;
        $queryParams = $request->getQueryParams();
        $short = isset($queryParams['short']) && $queryParams['short'] == '1';
        $start = isset($queryParams['start']) ? (int)$queryParams['start'] : 0;
        $perPage = isset($queryParams['results']) ? (int)$queryParams['results'] : $maxMatches;
        $sort = $queryParams['sort'] ?? 'id';
        $dir = $queryParams['dir'] ?? 'asc';

        // Check if the building exists
        $buildingSql = "SELECT id FROM bb_building WHERE id = :id";
        $buildingStmt = $this->db->prepare($buildingSql);
        $buildingStmt->bindParam(':id', $buildingId, \PDO::PARAM_INT);
        $buildingStmt->execute();
        if (!$buildingStmt->fetch())
        {
            $response->getBody()->write(json_encode(['error' => 'Building not found']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        // Validate and sanitize the sort field to prevent SQL injection
        $allowedSortFields = ['id', 'name', 'activity_id', 'sort'];
        $sort = in_array($sort, $allowedSortFields) ? $sort : 'id';
        $dir = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';

        $currentDate = date('Y-m-d H:i:s');
        
        $sql = "SELECT r.*, br.building_id, pl.quantity as participant_limit
                FROM bb_resource r
                JOIN bb_building_resource br ON r.id = br.resource_id
                LEFT JOIN (
                    SELECT pl.resource_id, pl.quantity 
                    FROM bb_participant_limit pl
                    INNER JOIN (
                        SELECT resource_id, MAX(from_) as latest_from
                        FROM bb_participant_limit
                        WHERE from_ <= :current_date
                        GROUP BY resource_id
                    ) latest ON pl.resource_id = latest.resource_id AND pl.from_ = latest.latest_from
                ) pl ON r.id = pl.resource_id
                WHERE br.building_id = :building_id
                AND r.active = 1
                AND (r.hidden_in_frontend = 0 OR r.hidden_in_frontend IS NULL)
                ORDER BY r.$sort $dir";

        if ($perPage > 0)
        {
            $sql .= " LIMIT :limit OFFSET :start";
        }

        try
        {
            $stmt = $this->db->prepare($sql);
            $stmt->bindParam(':building_id', $buildingId, \PDO::PARAM_INT);
            $stmt->bindParam(':current_date', $currentDate);
            if ($perPage > 0)
            {
                $stmt->bindParam(':limit', $perPage, \PDO::PARAM_INT);
                $stmt->bindParam(':start', $start, \PDO::PARAM_INT);
            }
            $stmt->execute();
            $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $resources = array_map(function ($data) use ($short)
            {
                $resource = new Resource($data);
                return $resource->serialize($this->getUserRoles(), $short);
            }, $results);

            $totalCount = $this->getTotalCountByBuilding($buildingId);

            $responseData = [
                'total_records' => $totalCount,
                'start' => $start,
                'sort' => $sort,
                'dir' => $dir,
                'results' => $resources
            ];

            $response->getBody()->write(json_encode($responseData));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (Exception $e)
        {
            $error = "Error fetching resources for building: " . $e->getMessage();
            $response->getBody()->write(json_encode(['error' => $error]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }


    private function getTotalCount(): int
    {
        $sql = "SELECT COUNT(DISTINCT r.id)
                FROM bb_resource r
                LEFT JOIN bb_building_resource br ON r.id = br.resource_id
                WHERE r.active = 1
                AND (r.hidden_in_frontend = 0 OR r.hidden_in_frontend IS NULL)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchColumn();
    }

    private function getTotalCountByBuilding(int $buildingId): int
    {
        $sql = "SELECT COUNT(DISTINCT r.id)
                FROM bb_resource r
                JOIN bb_building_resource br ON r.id = br.resource_id
                WHERE br.building_id = :building_id
                AND r.active = 1
                AND (r.hidden_in_frontend = 0 OR r.hidden_in_frontend IS NULL)";
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':building_id', $buildingId, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchColumn();
    }
    
    /**
     * @OA\Get(
     *     path="/bookingfrontend/resources/{id}/schedule",
     *     summary="Get a schedule for a specific resource within a date range",
     *     tags={"Resources"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID of the resource",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="start_date",
     *         in="query",
     *         description="Start date for the schedule (format: YYYY-MM-DD)",
     *         required=true,
     *         @OA\Schema(type="string", format="date", example="2025-03-17")
     *     ),
     *     @OA\Parameter(
     *         name="end_date",
     *         in="query",
     *         description="End date for the schedule (format: YYYY-MM-DD)",
     *         required=true,
     *         @OA\Schema(type="string", format="date", example="2025-03-24")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Resource schedule for the specified date range",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(
     *                 oneOf={
     *                     @OA\Schema(ref="#/components/schemas/Event"),
     *                     @OA\Schema(ref="#/components/schemas/Booking"),
     *                     @OA\Schema(ref="#/components/schemas/Allocation")
     *                 }
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid date format or missing parameters",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="Invalid date format or missing parameters")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Resource not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="Resource not found")
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
    public function getResourceSchedule(Request $request, Response $response, array $args): Response
    {
        try {
            $resourceId = (int)$args['id'];
            $queryParams = $request->getQueryParams();
            
            // Check if required parameters are provided
            if (!isset($queryParams['start_date'])) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'start_date parameter is required'],
                    400
                );
            }
            
            // Verify resource exists
            $sql = "SELECT r.id FROM bb_resource r WHERE r.id = :id AND r.active = 1";
            $stmt = $this->db->prepare($sql);
            $stmt->bindParam(':id', $resourceId, \PDO::PARAM_INT);
            //$stmt->bindParam(':current_date', $currentDate);
            $stmt->execute();
            
            if (!$stmt->fetch()) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Resource not found or not active'],
                    404
                );
            }
            
            // Convert dates to DateTime objects
            try {
                $startDate = new \DateTime($queryParams['start_date']);
                $startDate->setTime(0, 0, 0);
                
                if(isset($queryParams['end_date']))
                {
                    $endDate = new \DateTime($queryParams['end_date']);
                    $endDate->setTime(23, 59, 59);
                }
                else
                {
                    // Default to six months later if end_date is not provided
                    $endDate = (clone $startDate)->modify('+6 month');
                }
            } catch (\Exception $e) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Invalid date format. Use YYYY-MM-DD format.'],
                    400
                );
            }
            
            // Get schedule from service
            $schedule = $this->buildingScheduleService->getResourceSchedule($resourceId, $startDate, $endDate);
            
            $response->getBody()->write(json_encode($schedule));
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'Error fetching resource schedule: ' . $e->getMessage()],
                500
            );
        }
    }
}