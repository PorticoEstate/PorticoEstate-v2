<?php

namespace App\modules\booking\controllers;

use App\modules\bookingfrontend\services\ScheduleEntityService;
use App\modules\phpgwapi\services\Settings;
use App\Database\Db;
use App\modules\bookingfrontend\models\Resource;
use PDO;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Exception;

class ResourceController
{
	protected ScheduleEntityService $scheduleEntityService;
	protected array $userSettings;
	protected Db $db;

	public function __construct(ContainerInterface $container)
	{
		$this->scheduleEntityService = new ScheduleEntityService($container);
		$this->userSettings = Settings::getInstance()->get('user');
		$this->db = Db::getInstance();
	}

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

		$sql = "SELECT r.*, br.building_id
                FROM bb_resource r
                LEFT JOIN bb_building_resource br ON r.id = br.resource_id
                WHERE r.active = 1 AND (r.hidden_in_frontend = 0 OR r.hidden_in_frontend IS NULL)
                ORDER BY r.$sort $dir";

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

			$resources = array_map(function ($data) use ($short)
			{
				$resource = new Resource($data);
				return $resource->serialize([], $short);
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
		}
		catch (Exception $e)
		{
			$error = "Error fetching resources: " . $e->getMessage();
			$response->getBody()->write(json_encode(['error' => $error]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	private function getTotalCount(): int
	{
		$sql = "SELECT COUNT(DISTINCT r.id)
                FROM bb_resource r
                LEFT JOIN bb_building_resource br ON r.id = br.resource_id
                WHERE r.active = 1";
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		return $stmt->fetchColumn();
	}

	/**
	 * @OA\Get(
	 *     path="/booking/resources/{id}/schedule",
	 *     summary="Get schedule for a specific resource within a date range",
	 *     description="Retrieves the schedule for a specific resource. Requires a start_date parameter. If end_date is not provided, it defaults to one month from the start_date.",
	 *     tags={"Resources"},
	 *     @OA\Parameter(
	 *         name="id",
	 *         in="path",
	 *         description="ID of the resource",
	 *         required=true,
	 *         @OA\Schema(type="integer", minimum=1)
	 *     ),
	 *     @OA\Parameter(
	 *         name="start_date",
	 *         in="query",
	 *         description="Start date for the schedule (YYYY-MM-DD format)",
	 *         required=true,
	 *         @OA\Schema(type="string", format="date", example="2025-06-19")
	 *     ),
	 *     @OA\Parameter(
	 *         name="end_date",
	 *         in="query",
	 *         description="End date for the schedule (YYYY-MM-DD format). If not provided, defaults to one month from start_date.",
	 *         required=false,
	 *         @OA\Schema(type="string", format="date", example="2025-07-19")
	 *     ),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Resource schedule retrieved successfully",
	 *         @OA\JsonContent(type="object", ref="#/components/schemas/Schedule")
	 *     ),
	 *     @OA\Response(
	 *         response=400,
	 *         description="Bad request - Invalid parameters",
	 *         @OA\JsonContent(
	 *             type="object",
	 *             @OA\Property(property="error", type="string", example="Both start_date and end_date parameters are required")
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=500,
	 *         description="Internal server error",
	 *         @OA\JsonContent(
	 *             type="object",
	 *             @OA\Property(property="error", type="string", example="Error message")
	 *         )
	 *     )
	 * )
	 */
	public function getResourceSchedule(Request $request, Response $response, array $args): Response
	{
		try
		{
			$resourceId = (int)$args['id'];
			if ($resourceId <= 0)
			{
				throw new Exception('Invalid resource ID provided.');
			}

			// Verify resource exists
			if (!$this->resourceExists($resourceId))
			{
				$response->getBody()->write(json_encode(['error' => 'Resource not found']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
			}


			$queryParams = $request->getQueryParams();


			if (!isset($queryParams['start_date']))
			{
				$response->getBody()->write(json_encode(['error' => 'Both start_date and end_date parameters are required']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
			}
			// Convert dates to DateTime objects
			try
			{
				$startDate = new \DateTime($queryParams['start_date']);
				$startDate->setTime(0, 0, 0);

				if (!isset($queryParams['end_date']))
				{
					// If end_date is not provided, set it to the same day as start_date
					$endDate = clone $startDate;
					$endDate->modify('+1 month');
					$endDate->setTime(23, 59, 59);
				}
				else
				{
					// If end_date is provided, parse it
					$endDate = new \DateTime($queryParams['end_date']);
					$endDate->setTime(23, 59, 59);
				}
			}
			catch (\Exception $e)
			{
				$response->getBody()->write(json_encode(['error' => 'Invalid date format. Use YYYY-MM-DD format.']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
			}

			$schedule = $this->scheduleEntityService->getResourceSchedule($resourceId, $startDate, $endDate);

			$response->getBody()->write(json_encode($schedule));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
		}
		catch (Exception $e)
		{
			$response->getBody()->write(json_encode(['error' => $e->getMessage()]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	/**
	 * Check if a resource exists in the database
	 */
	private function resourceExists(int $resourceId): bool
	{
		$sql = "SELECT id FROM bb_resource WHERE id = :id AND active = 1";
		$stmt = $this->db->prepare($sql);
		$stmt->execute([':id' => $resourceId]);
		return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
	}
}
