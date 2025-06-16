<?php

namespace App\modules\bookingfrontend\controllers;

use App\modules\bookingfrontend\helpers\ResponseHelper;
use App\modules\bookingfrontend\services\ScheduleEntityService;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Exception;
use DateTime;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="Schedule Entities",
 *     description="API Endpoints for Schedule Entities (Events, Allocations, Bookings)"
 * )
 */
class ScheduleEntityController
{
    private ScheduleEntityService $scheduleEntityService;

    public function __construct(ContainerInterface $container)
    {
        $this->scheduleEntityService = new ScheduleEntityService();
    }

    /**
     * @OA\Get(
     *     path="/bookingfrontend/applications/{id}/schedule",
     *     summary="Get schedule entities for an application",
     *     tags={"Schedule Entities"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer"),
     *         description="Application ID"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Schedule entities for the application",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="events", type="array", @OA\Items(type="object")),
     *             @OA\Property(property="allocations", type="array", @OA\Items(type="object")),
     *             @OA\Property(property="bookings", type="array", @OA\Items(type="object"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error"
     *     )
     * )
     */
    public function getApplicationSchedule(Request $request, Response $response, array $args): Response
    {
        try {
            $applicationId = (int)$args['id'];
            
            $scheduleEntities = $this->scheduleEntityService->getScheduleEntitiesByApplicationId($applicationId);
            
            return ResponseHelper::sendJSONResponse($scheduleEntities);
            
        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => "Error retrieving schedule entities: " . $e->getMessage()],
                500
            );
        }
    }


    /**
     * @OA\Get(
     *     path="/bookingfrontend/buildings/{id}/schedule",
     *     summary="Get schedules for a single date or multiple weeks",
     *     tags={"Schedule Entities"},
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
     *         description="Single date to get schedule for (format: YYYY-MM-DD)",
     *         @OA\Schema(type="string", format="date", example="2025-03-17")
     *     ),
     *     @OA\Parameter(
     *         name="dates[]",
     *         in="query",
     *         required=false,
     *         description="Array of dates to get schedules for (overrides single date if both provided)",
     *         @OA\Schema(
     *             type="array",
     *             @OA\Items(type="string", format="date", example="2025-03-17")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Building schedules mapped by week start date",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\AdditionalProperties(
     *                 description="Weekly schedule array keyed by week start date (Monday)",
     *                 type="array",
     *                 @OA\Items(
     *                     oneOf={
     *                         @OA\Schema(ref="#/components/schemas/Event"),
     *                         @OA\Schema(ref="#/components/schemas/Booking"),
     *                         @OA\Schema(ref="#/components/schemas/Allocation")
     *                     }
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid date format"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Building not found"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error"
     *     )
     * )
     */
    public function getBuildingSchedule(Request $request, Response $response, array $args): Response
    {
        try {
            $building_id = (int)$args['id'];

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
            $dateTimes = array_map(function ($dateStr) {
                try {
                    return new DateTime($dateStr);
                } catch (\Exception $e) {
                    throw new \InvalidArgumentException("Invalid date format: {$dateStr}");
                }
            }, $dates);

            // Get schedules from service
            $schedules = $this->scheduleEntityService->getBuildingWeeklySchedules($building_id, $dateTimes);

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

    /**
     * @OA\Get(
     *     path="/bookingfrontend/resources/{id}/schedule",
     *     summary="Get a schedule for a specific resource within a date range",
     *     tags={"Schedule Entities"},
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
     *         description="Invalid date format or missing parameters"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Resource not found"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error"
     *     )
     * )
     */
    public function getResourceSchedule(Request $request, Response $response, array $args): Response
    {
        try {
            $resourceId = (int)$args['id'];
            $queryParams = $request->getQueryParams();
            
            // Check if required parameters are provided
            if (!isset($queryParams['start_date']) || !isset($queryParams['end_date'])) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Both start_date and end_date parameters are required'],
                    400
                );
            }
            
            // Convert dates to DateTime objects
            try {
                $startDate = new DateTime($queryParams['start_date']);
                $startDate->setTime(0, 0, 0);
                
                $endDate = new DateTime($queryParams['end_date']);
                $endDate->setTime(23, 59, 59);
            } catch (\Exception $e) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Invalid date format. Use YYYY-MM-DD format.'],
                    400
                );
            }
            
            // Get schedule from service
            $schedule = $this->scheduleEntityService->getResourceSchedule($resourceId, $startDate, $endDate);
            
            $response->getBody()->write(json_encode($schedule));
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'Error fetching resource schedule: ' . $e->getMessage()],
                500
            );
        }
    }
}