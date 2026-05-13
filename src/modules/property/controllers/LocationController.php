<?php

namespace App\modules\property\controllers;

use JsonException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use function include_class;

class LocationController
{
	public function __construct(ContainerInterface $container)
	{
		if (defined('SRC_ROOT_PATH') && !function_exists('include_class'))
		{
			require_once SRC_ROOT_PATH . '/helpers/LegacyObjectHandler.php';
		}
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
		include_class('property', 'uilocation');
		return CreateObject('property.uilocation');
	}

	private function jsonResponse(Response $response, mixed $payload): Response
	{
		try
		{
			$encoded = json_encode($payload, JSON_THROW_ON_ERROR);
			$response->getBody()->write($encoded);
			return $response->withHeader('Content-Type', 'application/json');
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

	public function delete(Request $request, Response $response, array $args): Response
	{
		$this->hydrateRequestGlobals($request, array('location_code' => $args['location_code']));
		return $this->jsonResponse($response, $this->ui()->delete());
	}
}
