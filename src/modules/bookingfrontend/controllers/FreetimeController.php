<?php

namespace App\modules\bookingfrontend\controllers;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\modules\bookingfrontend\helpers\ResponseHelper;
use App\modules\bookingfrontend\services\FreetimeService;
use App\modules\phpgwapi\services\Settings;
use DateTime;
use Exception;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="Freetime",
 *     description="API Endpoints for checking resource availability"
 * )
 */
class FreetimeController
{
	private $userSettings;
	private $freetimeService;

	public function __construct(ContainerInterface $container)
	{
		$this->userSettings = Settings::getInstance()->get('user');
		$this->freetimeService = new FreetimeService();
	}

	/**
	 * Get available time slots for a specific resource
	 *
	 * @OA\Get(
	 *     path="/bookingfrontend/resources/{id}/freetime",
	 *     summary="Get available time slots for a resource",
	 *     tags={"Freetime"},
	 *     @OA\Parameter(
	 *         name="id",
	 *         in="path",
	 *         required=true,
	 *         description="Resource ID",
	 *         @OA\Schema(type="integer")
	 *     ),
	 *     @OA\Parameter(
	 *         name="start_date",
	 *         in="query",
	 *         required=true,
	 *         description="Start date (YYYY-MM-DD)",
	 *         @OA\Schema(type="string", format="date", example="2026-01-20")
	 *     ),
	 *     @OA\Parameter(
	 *         name="end_date",
	 *         in="query",
	 *         required=true,
	 *         description="End date (YYYY-MM-DD)",
	 *         @OA\Schema(type="string", format="date", example="2026-01-27")
	 *     ),
	 *     @OA\Parameter(
	 *         name="detailed_overlap",
	 *         in="query",
	 *         required=false,
	 *         description="Include detailed overlap information",
	 *         @OA\Schema(type="boolean", default=false)
	 *     ),
	 *     @OA\Parameter(
	 *         name="stop_on_end_date",
	 *         in="query",
	 *         required=false,
	 *         description="Stop on end date",
	 *         @OA\Schema(type="boolean", default=false)
	 *     ),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Array of available time slots",
	 *         @OA\JsonContent(
	 *             type="array",
	 *             @OA\Items(
	 *                 type="object",
	 *                 @OA\Property(property="when", type="string", example="21/01-2026 08:00 - 21/01-2026 10:00"),
	 *                 @OA\Property(property="start", type="string", example="1737442800000"),
	 *                 @OA\Property(property="end", type="string", example="1737450000000"),
	 *                 @OA\Property(property="overlap", oneOf={
	 *                     @OA\Schema(type="boolean"),
	 *                     @OA\Schema(type="integer", enum={0, 1, 2, 3})
	 *                 }),
	 *                 @OA\Property(property="start_iso", type="string", format="date-time"),
	 *                 @OA\Property(property="end_iso", type="string", format="date-time"),
	 *                 @OA\Property(property="resource_id", type="integer")
	 *             )
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=400,
	 *         description="Invalid parameters",
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
	public function resourceFreetime(Request $request, Response $response, array $args): Response
	{
		try {
			// 1. Extract and validate parameters
			$resourceId = (int)$args['id'];
			$params = $request->getQueryParams();

			if (!isset($params['start_date']) || !isset($params['end_date'])) {
				return ResponseHelper::sendErrorResponse(
					['error' => 'start_date and end_date are required'],
					400
				);
			}

			// 2. Parse and convert date format (YYYY-MM-DD to DateTime)
			$startDate = $this->parseDate($params['start_date']);
			$endDate = $this->parseDate($params['end_date']);

			if (!$startDate || !$endDate) {
				return ResponseHelper::sendErrorResponse(
					['error' => 'Invalid date format. Expected YYYY-MM-DD, got: ' . ($params['start_date'] ?? 'null')],
					400
				);
			}

			// 3. Parse boolean parameters
			$detailedOverlap = filter_var(
				$params['detailed_overlap'] ?? false,
				FILTER_VALIDATE_BOOLEAN
			);

			$stopOnEndDate = filter_var(
				$params['stop_on_end_date'] ?? $detailedOverlap,
				FILTER_VALIDATE_BOOLEAN
			);

			// 4. Call FreetimeService to get available slots
			$slots = $this->freetimeService->getFreetimeForResource(
				$resourceId,
				$startDate,
				$endDate,
				$detailedOverlap,
				$stopOnEndDate
			);

			// 5. Return JSON response
			return ResponseHelper::sendJSONResponse($slots);

		} catch (Exception $e) {
			return ResponseHelper::sendErrorResponse(
				['error' => 'Error fetching freetime: ' . $e->getMessage()],
				500
			);
		}
	}

	/**
	 * Get available time slots for all resources in a building
	 *
	 * @OA\Get(
	 *     path="/bookingfrontend/buildings/{id}/freetime",
	 *     summary="Get available time slots for all resources in a building",
	 *     tags={"Freetime"},
	 *     @OA\Parameter(
	 *         name="id",
	 *         in="path",
	 *         required=true,
	 *         description="Building ID",
	 *         @OA\Schema(type="integer")
	 *     ),
	 *     @OA\Parameter(
	 *         name="start_date",
	 *         in="query",
	 *         required=true,
	 *         description="Start date (YYYY-MM-DD)",
	 *         @OA\Schema(type="string", format="date", example="2026-01-20")
	 *     ),
	 *     @OA\Parameter(
	 *         name="end_date",
	 *         in="query",
	 *         required=true,
	 *         description="End date (YYYY-MM-DD)",
	 *         @OA\Schema(type="string", format="date", example="2026-01-27")
	 *     ),
	 *     @OA\Parameter(
	 *         name="detailed_overlap",
	 *         in="query",
	 *         required=false,
	 *         description="Include detailed overlap information",
	 *         @OA\Schema(type="boolean", default=false)
	 *     ),
	 *     @OA\Parameter(
	 *         name="stop_on_end_date",
	 *         in="query",
	 *         required=false,
	 *         description="Stop on end date",
	 *         @OA\Schema(type="boolean", default=false)
	 *     ),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Object with resource IDs as keys, time slot arrays as values",
	 *         @OA\JsonContent(
	 *             type="object",
	 *             @OA\AdditionalProperties(
	 *                 type="array",
	 *                 @OA\Items(
	 *                     type="object",
	 *                     @OA\Property(property="when", type="string"),
	 *                     @OA\Property(property="start", type="string"),
	 *                     @OA\Property(property="end", type="string"),
	 *                     @OA\Property(property="overlap", oneOf={
	 *                         @OA\Schema(type="boolean"),
	 *                         @OA\Schema(type="integer")
	 *                     })
	 *                 )
	 *             )
	 *         )
	 *     ),
	 *     @OA\Response(response=400, description="Invalid parameters"),
	 *     @OA\Response(response=500, description="Internal server error")
	 * )
	 */
	public function buildingFreetime(Request $request, Response $response, array $args): Response
	{
		try {
			// 1. Extract and validate parameters
			$buildingId = (int)$args['id'];
			$params = $request->getQueryParams();

			if (!isset($params['start_date']) || !isset($params['end_date'])) {
				return ResponseHelper::sendErrorResponse(
					['error' => 'start_date and end_date are required'],
					400
				);
			}

			// 2. Parse dates
			$startDate = $this->parseDate($params['start_date']);
			$endDate = $this->parseDate($params['end_date']);

			if (!$startDate || !$endDate) {
				return ResponseHelper::sendErrorResponse(
					['error' => 'Invalid date format. Expected YYYY-MM-DD, got: ' . ($params['start_date'] ?? 'null')],
					400
				);
			}

			// 3. Parse boolean parameters
			$detailedOverlap = filter_var(
				$params['detailed_overlap'] ?? false,
				FILTER_VALIDATE_BOOLEAN
			);

			$stopOnEndDate = filter_var(
				$params['stop_on_end_date'] ?? $detailedOverlap,
				FILTER_VALIDATE_BOOLEAN
			);

			// 4. Call FreetimeService to get available slots for all resources
			$result = $this->freetimeService->getFreetimeForBuilding(
				$buildingId,
				$startDate,
				$endDate,
				$detailedOverlap,
				$stopOnEndDate
			);

			// 5. Return JSON response (object with resource IDs as keys)
			return ResponseHelper::sendJSONResponse($result);

		} catch (Exception $e) {
			return ResponseHelper::sendErrorResponse(
				['error' => 'Error fetching freetime: ' . $e->getMessage()],
				500
			);
		}
	}

	/**
	 * Parse date from YYYY-MM-DD format to DateTime object
	 *
	 * @param string $dateString
	 * @return DateTime|null
	 */
	private function parseDate(string $dateString): ?DateTime
	{
		try {
			$date = new DateTime($dateString);
			return $date;
		} catch (Exception $e) {
			return null;
		}
	}
}
