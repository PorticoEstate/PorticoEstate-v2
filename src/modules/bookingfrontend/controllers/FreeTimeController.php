<?php

namespace App\modules\bookingfrontend\controllers;

use App\modules\bookingfrontend\helpers\ResponseHelper;
use App\modules\bookingfrontend\services\FreeTimeService;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class FreeTimeController
{
	private FreeTimeService $freeTimeService;

	public function __construct(ContainerInterface $container)
	{
		$this->freeTimeService = new FreeTimeService();
	}

	/**
	 * Parse a boolean query parameter.
	 * Matches legacy Sanitizer behavior: only "0" is treated as false.
	 * BUG PRESERVED: Legacy Sanitizer has a broken regex for bool parsing -
	 * the string "false" is treated as truthy because the regex uses a character
	 * class [false|0|no] instead of alternation (false|0|no). We use proper
	 * parsing here (accepting "0", "false", "no" as false) since the new endpoint
	 * should handle booleans correctly. The legacy endpoint retains its buggy behavior.
	 */
	private static function parseBool($value, bool $default): bool
	{
		if ($value === null) {
			return $default;
		}
		if (is_bool($value)) {
			return $value;
		}
		$str = strtolower(trim((string)$value));
		if (in_array($str, ['0', 'false', 'no', ''], true)) {
			return false;
		}
		return true;
	}

	/**
	 * GET /bookingfrontend/buildings/{id}/freetime
	 *
	 * Query params:
	 *   - start_date (YYYY-MM-DD)
	 *   - end_date (YYYY-MM-DD)
	 *   - resource_id (optional int)
	 *   - detailed_overlap (optional bool, default false)
	 *   - stop_on_end_date (optional bool, defaults to detailed_overlap)
	 *   - debug (optional bool, adds timing info)
	 */
	public function getBuildingFreeTime(Request $request, Response $response, array $args): Response
	{
		$buildingId = (int)$args['id'];
		$params = $request->getQueryParams();

		$startDate = $params['start_date'] ?? null;
		$endDate = $params['end_date'] ?? null;

		if (!$startDate || !$endDate) {
			return ResponseHelper::sendErrorResponse(
				['error' => 'start_date and end_date are required (YYYY-MM-DD)'],
				400,
				$response
			);
		}

		$resourceId = !empty($params['resource_id']) ? (int)$params['resource_id'] : null;
		$detailedOverlap = self::parseBool($params['detailed_overlap'] ?? null, false);
		// Legacy compat: default to detailed_overlap value when not specified
		$stopOnEndDate = isset($params['stop_on_end_date'])
			? self::parseBool($params['stop_on_end_date'], $detailedOverlap)
			: $detailedOverlap;

		$debug = self::parseBool($params['debug'] ?? null, false);
		$this->freeTimeService->setDebug($debug);

		try {
			$result = $this->freeTimeService->getFreeTime(
				$buildingId,
				$resourceId,
				$startDate,
				$endDate,
				$detailedOverlap,
				$stopOnEndDate
			);

			if ($debug) {
				$result = [
					'data' => $result,
					'_debug' => $this->freeTimeService->getDebugInfo(),
				];
			}

			return ResponseHelper::sendJSONResponse($result, 200, $response);
		} catch (\Exception $e) {
			return ResponseHelper::sendErrorResponse(
				['error' => $e->getMessage()],
				500,
				$response
			);
		}
	}
}
