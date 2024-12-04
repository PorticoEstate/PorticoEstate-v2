<?php

namespace App\modules\bookingfrontend\controllers;

use App\modules\bookingfrontend\helpers\ResponseHelper;
use App\modules\bookingfrontend\models\Building;
use App\modules\bookingfrontend\models\Document;
use App\modules\bookingfrontend\services\BuildingScheduleService;
use DateTime;
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
    private $buildingScheduleService;
    public function __construct(ContainerInterface $container)
    {
        parent::__construct(Document::OWNER_BUILDING);

        $this->db = Db::getInstance();
        $this->userSettings = Settings::getInstance()->get('user');
        $this->buildingScheduleService = new BuildingScheduleService();
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
     *     @OA\Response(
     *         response=200,
     *         description="A list of buildings",
     *         @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/Building"))
     *     )
     * )
     */
    public function index(Request $request, Response $response): Response
    {
        $maxMatches = isset($this->userSettings['preferences']['common']['maxmatchs']) ? (int)$this->userSettings['preferences']['common']['maxmatchs'] : 15;
        $queryParams = $request->getQueryParams();
        $start = isset($queryParams['start']) ? (int)$queryParams['start'] : 0;
        $perPage = isset($queryParams['results']) ? (int)$queryParams['results'] : $maxMatches;

        $sql = "SELECT * FROM bb_building ORDER BY id";
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
            $sql = "SELECT * FROM bb_building WHERE id = :id";
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
     *     path="/bookingfrontend/buildings/{id}/schedule",
     *     summary="Get schedules for a single date or multiple weeks",
     *     tags={"Buildings"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Building ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="date",
     *         in="query",
     *         required=false,
     *         description="Single date to get schedule for",
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Parameter(
     *         name="dates[]",
     *         in="query",
     *         required=false,
     *         description="Array of dates to get schedules for (overrides single date if both provided)",
     *         @OA\Schema(
     *             type="array",
     *             @OA\Items(type="string", format="date")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Building schedules mapped by week",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\AdditionalProperties(
     *                 type="array",
     *                 @OA\Items(ref="#/components/schemas/BuildingSchedule")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Building not found"
     *     )
     * )
     */
    public function getSchedule(Request $request, Response $response, array $args): Response
    {
        try {
            $building_id = (int)$args['id'];

            // Verify building exists
            $sql = "SELECT id FROM bb_building WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->bindParam(':id', $building_id, \PDO::PARAM_INT);
            $stmt->execute();

            if (!$stmt->fetch()) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Building not found'],
                    404
                );
            }

            $queryParams = $request->getQueryParams();

            // Check for dates array first, then single date, then default to current date
            if (isset($queryParams['dates']) && is_array($queryParams['dates'])) {
                $dates = $queryParams['dates'];
            } elseif (isset($queryParams['date'])) {
                $dates = [$queryParams['date']];
            } else {
                $dates = [date('Y-m-d')];
            }

            // Convert dates to DateTime objects and validate
            $dateTimes = array_map(function($dateStr) {
                try {
                    return new DateTime($dateStr);
                } catch (\Exception $e) {
                    throw new \InvalidArgumentException("Invalid date format: {$dateStr}");
                }
            }, $dates);

            // Get schedules from service
            $schedules = $this->buildingScheduleService->getWeeklySchedules($building_id, $dateTimes);

            // For single date requests, also include the direct date as a key
//            if (count($dates) === 1 && isset($queryParams['date'])) {
//                $singleDate = new DateTime($queryParams['date']);
//                $weekStart = clone $singleDate;
//                if ($weekStart->format('w') != 1) {
//                    $weekStart->modify('last monday');
//                }
//                $weekStartStr = $weekStart->format('Y-m-d');
//
//                // Add the specific date as a key pointing to the same schedule
//                $schedules[$singleDate->format('Y-m-d')] = $schedules[$weekStartStr];
//            }

            $response->getBody()->write(json_encode($schedules));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (\InvalidArgumentException $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => $e->getMessage()],
                400
            );
        } catch (Exception $e) {
            $error = "Error fetching building schedule: " . $e->getMessage();
            return ResponseHelper::sendErrorResponse(
                ['error' => $error],
                500
            );
        }
    }
}