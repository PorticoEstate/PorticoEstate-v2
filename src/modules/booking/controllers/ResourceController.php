<?php

namespace App\modules\booking\controllers;

use App\modules\bookingfrontend\services\ScheduleEntityService;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Exception;

class ResourceController
{
	protected ScheduleEntityService $scheduleEntityService;

	public function __construct(ContainerInterface $container)
	{
		$this->scheduleEntityService = new ScheduleEntityService($container);
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
}
