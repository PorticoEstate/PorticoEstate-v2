<?php

namespace App\modules\property\controllers;

use JsonException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\modules\property\helpers\LocationFormHelper;
use function include_class;

class LocationController
{
	private ?\property_uilocation $uiLocation = null;
	private LocationFormHelper $formHelper;
	private const ACL_ADD = 'acl_add';
	private const ACL_EDIT = 'acl_edit';
	private const ACL_DELETE = 'acl_delete';

	public function __construct(ContainerInterface $container)
	{
		if (defined('SRC_ROOT_PATH') && !function_exists('include_class'))
		{
			require_once SRC_ROOT_PATH . '/helpers/LegacyObjectHandler.php';
		}

		// Initialize form helper for write operations
		$this->formHelper = new LocationFormHelper();
	}

	private function hydrateRequestGlobals(Request $request, array $extra = array(), bool $json = true): void
	{
		$queryParams	 = $request->getQueryParams();
		$bodyParams		 = $request->getParsedBody();
		$bodyParams		 = is_array($bodyParams) ? $bodyParams : array();
		$commonExtra		 = $json ? array('phpgw_return_as' => 'json') : array();
		$extra			 = array_merge($commonExtra, $extra);

		$_GET = array_merge($_GET, $queryParams, $extra);
		$_POST = array_merge($_POST, $bodyParams, $extra);
		$_REQUEST = array_merge($_REQUEST, $queryParams, $bodyParams, $extra);
	}

	private function ui(): \property_uilocation
	{
		if ($this->uiLocation === null)
		{
			include_class('property', 'uilocation');
			$this->uiLocation = CreateObject('property.uilocation');
		}

		return $this->uiLocation;
	}

	private function jsonResponse(Response $response, mixed $payload, int $statusCode = 200): Response
	{
		try
		{
			$encoded = json_encode($payload, JSON_THROW_ON_ERROR);
			$response->getBody()->write($encoded);
			return $response->withHeader('Content-Type', 'application/json')->withStatus($statusCode);
		}
		catch (JsonException $e)
		{
			$response->getBody()->write(json_encode(array(
				'error' => 'Unable to encode JSON response'
			)));
			return $response
				->withHeader('Content-Type', 'application/json')
				->withStatus(500);
		}
	}

	protected function hasAcl(string $aclProperty): bool
	{
		return !empty($this->ui()->{$aclProperty});
	}

	private function forbiddenResponse(Response $response, string $message): Response
	{
		return $this->jsonResponse($response, [
			'status' => 'error',
			'message' => $message,
		], 403);
	}

	public function index(Request $request, Response $response): Response
	{
		$this->hydrateRequestGlobals($request);
		return $this->jsonResponse($response, $this->ui()->index());
	}

	public function summary(Request $request, Response $response): Response
	{
		$this->hydrateRequestGlobals($request);
		return $this->jsonResponse($response, $this->ui()->summary());
	}

	public function responsibilityRole(Request $request, Response $response): Response
	{
		$this->hydrateRequestGlobals($request);
		return $this->jsonResponse($response, $this->ui()->responsiblility_role());
	}

	public function responsibilityRoleSave(Request $request, Response $response): Response
	{
		$this->hydrateRequestGlobals($request);
		return $this->jsonResponse($response, $this->ui()->responsiblility_role_save());
	}

	public function getPartOfTown(Request $request, Response $response): Response
	{
		$this->hydrateRequestGlobals($request);
		return $this->jsonResponse($response, $this->ui()->get_part_of_town());
	}

	public function getAccounts(Request $request, Response $response): Response
	{
		$this->hydrateRequestGlobals($request);
		return $this->jsonResponse($response, $this->ui()->get_accounts());
	}

	public function getHistoryData(Request $request, Response $response): Response
	{
		$this->hydrateRequestGlobals($request);
		return $this->jsonResponse($response, $this->ui()->get_history_data());
	}

	public function getDocuments(Request $request, Response $response): Response
	{
		$this->hydrateRequestGlobals($request);
		return $this->jsonResponse($response, $this->ui()->get_documents());
	}

	public function getLocationData(Request $request, Response $response): Response
	{
		$this->hydrateRequestGlobals($request);
		return $this->jsonResponse($response, $this->ui()->get_location_data());
	}

	public function getDeliveryAddress(Request $request, Response $response): Response
	{
		$this->hydrateRequestGlobals($request);
		return $this->jsonResponse($response, $this->ui()->get_delivery_address());
	}

	public function getLocationException(Request $request, Response $response): Response
	{
		$this->hydrateRequestGlobals($request);
		return $this->jsonResponse($response, $this->ui()->get_location_exception());
	}

	public function queryRole(Request $request, Response $response): Response
	{
		$this->hydrateRequestGlobals($request);
		return $this->jsonResponse($response, $this->ui()->query_role());
	}

	public function querySummary(Request $request, Response $response): Response
	{
		$this->hydrateRequestGlobals($request);
		return $this->jsonResponse($response, $this->ui()->query_summary());
	}

	public function getControlsAtComponent(Request $request, Response $response): Response
	{
		$this->hydrateRequestGlobals($request);
		return $this->jsonResponse($response, $this->ui()->get_controls_at_component());
	}

	public function getCases(Request $request, Response $response): Response
	{
		$this->hydrateRequestGlobals($request);
		return $this->jsonResponse($response, $this->ui()->get_cases());
	}

	public function getChecklists(Request $request, Response $response): Response
	{
		$this->hydrateRequestGlobals($request);
		return $this->jsonResponse($response, $this->ui()->get_checklists());
	}

	public function getCasesForChecklist(Request $request, Response $response): Response
	{
		$this->hydrateRequestGlobals($request);
		return $this->jsonResponse($response, $this->ui()->get_cases_for_checklist());
	}

	public function editField(Request $request, Response $response): Response
	{
		$this->hydrateRequestGlobals($request);
		return $this->jsonResponse($response, $this->ui()->edit_field());
	}

	/**
	 * Create new location with explicit form helper orchestration
	 * 
	 * Hybrid approach: Explicit validation/persistence instead of global state hydration
	 * Supports: location creation with field validation and clear error handling
	 * 
	 * POST /property/location/add
	 * Body: {loc_code, loc1, loc2, ..., location_type}
	 */
	public function add(Request $request, Response $response): Response
	{
		if (!$this->hasAcl(self::ACL_ADD))
		{
			return $this->forbiddenResponse($response, 'No add access for location');
		}

		$bodyParams = $request->getParsedBody() ?? [];

		// Map input -> validate -> persist -> build response
		$state = $this->formHelper->mapInput($bodyParams, null);
		$state = $this->formHelper->applyLegacyRules($state, [], false);
		$state = $this->formHelper->validate($state);
		$state = $this->formHelper->persistSave($state);
		$responseData = $this->formHelper->buildSaveResponse($state, 'save');

		return $this->jsonResponse($response, $responseData['payload']);
	}

	/**
	 * Save/update location with explicit form helper orchestration
	 * 
	 * Hybrid approach: Explicit validation/persistence instead of global state hydration
	 * Supports: location updates with field validation, error recovery, and clear responses
	 * 
	 * PUT /property/location/:location_id
	 * Body: {loc_code, loc1, loc2, ..., location_type}
	 */
	public function save(Request $request, Response $response, array $args): Response
	{
		if (!$this->hasAcl(self::ACL_EDIT))
		{
			return $this->forbiddenResponse($response, 'No edit access for location');
		}

		$locationId = (int)($args['location_id'] ?? 0);
		if ($locationId <= 0) {
			return $this->jsonResponse($response, [
				'status' => 'error',
				'message' => 'Invalid location ID',
				'location_id' => null,
			], 400);
		}

		$bodyParams = $request->getParsedBody() ?? [];

		// Map input -> validate -> persist -> build response
		$state = $this->formHelper->mapInput($bodyParams, $locationId);
		$state = $this->formHelper->applyLegacyRules($state, [], true);
		$state = $this->formHelper->validate($state);
		$state = $this->formHelper->persistSave($state);
		$responseData = $this->formHelper->buildSaveResponse($state, 'save');

		$statusCode = ($responseData['payload']['status'] === 'error') ? 400 : 200;
		return $this->jsonResponse($response, $responseData['payload'], $statusCode);
	}

	public function addControl(Request $request, Response $response): Response
	{
		$this->hydrateRequestGlobals($request);
		return $this->jsonResponse($response, $this->ui()->controller_helper->add_control());
	}

	public function updateControlSerie(Request $request, Response $response): Response
	{
		$this->hydrateRequestGlobals($request);
		return $this->jsonResponse($response, $this->ui()->controller_helper->update_control_serie());
	}

	public function delete(Request $request, Response $response, array $args): Response
	{
		if (!$this->hasAcl(self::ACL_DELETE))
		{
			return $this->forbiddenResponse($response, 'No delete access for location');
		}

		$this->hydrateRequestGlobals($request, array('location_code' => $args['location_code']));
		return $this->jsonResponse($response, $this->ui()->delete());
	}

	public function deleteByLocationCode(Request $request, Response $response): Response
	{
		if (!$this->hasAcl(self::ACL_DELETE))
		{
			return $this->forbiddenResponse($response, 'No delete access for location');
		}

		$locationCode = (string)($request->getQueryParams()['location_code'] ?? '');
		if ($locationCode === '')
		{
			$parsedBody = $request->getParsedBody();
			if (is_array($parsedBody))
			{
				$locationCode = (string)($parsedBody['location_code'] ?? '');
			}
		}

		$this->hydrateRequestGlobals($request, array('location_code' => $locationCode));
		return $this->jsonResponse($response, $this->ui()->delete());
	}
}
