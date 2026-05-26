<?php

namespace App\modules\property\controllers;

use JsonException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\modules\phpgwapi\security\Acl;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

class ProjectController
{
	private $bo = null;

	public function __construct(ContainerInterface $container)
	{
	}

	protected function bo()
	{
		if ($this->bo === null)
		{
			$this->bo = CreateObject('property.boproject', true);
		}

		return $this->bo;
	}

	private function jsonResponse(Response $response, mixed $payload, int $statusCode = 200): Response
	{
		try
		{
			$response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));
			return $response->withHeader('Content-Type', 'application/json')->withStatus($statusCode);
		}
		catch (JsonException $e)
		{
			$response->getBody()->write(json_encode(array(
				'error' => 'Unable to encode JSON response'
			)));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	private function requestBodyAsArray(Request $request): array
	{
		$parsedBody = $request->getParsedBody();
		if (is_array($parsedBody))
		{
			return $parsedBody;
		}

		$rawBody = (string)$request->getBody();
		if ($rawBody === '')
		{
			return array();
		}

		$contentType = strtolower((string)$request->getHeaderLine('Content-Type'));
		if (strpos($contentType, 'application/json') !== false)
		{
			$json = json_decode($rawBody, true);
			return is_array($json) ? $json : array();
		}

		$decoded = array();
		parse_str($rawBody, $decoded);
		return is_array($decoded) ? $decoded : array();
	}

	private function hasReadAccess(): bool
	{
		return (bool)Acl::getInstance()->check($this->bo()->acl_location, ACL_READ, 'property');
	}

	private function currentDraw(array $input): int
	{
		$draw = (int)($input['draw'] ?? 1);
		return $draw > 0 ? $draw : 1;
	}

	private function isDataTablesRequest(array $input): bool
	{
		return array_key_exists('draw', $input)
			|| array_key_exists('columns', $input)
			|| array_key_exists('order', $input);
	}

	private function normalizeSearchValue(mixed $search): string
	{
		if (is_array($search))
		{
			return (string)($search['value'] ?? '');
		}

		return (string)$search;
	}

	private function readParams(array $input): array
	{
		$order = is_array($input['order'] ?? null) ? $input['order'] : array();
		$columns = is_array($input['columns'] ?? null) ? $input['columns'] : array();
		$search = $this->normalizeSearchValue($input['search'] ?? '');
		$orderIndex = (int)($order[0]['column'] ?? -1);
		$orderField = ($orderIndex >= 0 && isset($columns[$orderIndex]['data']))
			? (string)$columns[$orderIndex]['data']
			: (string)($input['order_by'] ?? $input['order'] ?? '');
		$orderDir = strtolower((string)($order[0]['dir'] ?? $input['sort'] ?? $input['order_dir'] ?? 'asc')) === 'desc'
			? 'DESC'
			: 'ASC';

		$start = (int)($input['start'] ?? 0);
		$length = (int)($input['length'] ?? $input['results'] ?? 25);
		$export = !empty($input['export']);
		$allrows = $export || ($length === -1);

		return array(
			'start' => $start,
			'results' => $length,
			'query' => $search,
			'order' => $orderField,
			'sort' => $orderDir,
			'allrows' => $allrows,
			'start_date' => (string)($input['start_date'] ?? ''),
			'end_date' => (string)($input['end_date'] ?? ''),
			'skip_origin' => !empty($input['skip_origin']),
			'cat_id' => isset($input['cat_id']) ? (int)$input['cat_id'] : null,
			'status_id' => $input['status_id'] ?? null,
			'filter' => isset($input['filter']) ? (int)$input['filter'] : null,
			'user_id' => isset($input['user_id']) ? (int)$input['user_id'] : null,
			'district_id' => isset($input['district_id']) ? (int)$input['district_id'] : null,
			'criteria_id' => isset($input['criteria_id']) ? (int)$input['criteria_id'] : null,
			'project_type_id' => isset($input['project_type_id']) ? (int)$input['project_type_id'] : null,
			'wo_hour_cat_id' => isset($input['wo_hour_cat_id']) ? (int)$input['wo_hour_cat_id'] : null,
			'filter_year' => $input['filter_year'] ?? null,
			'b_account_id' => $input['b_account_id'] ?? null,
		);
	}

	/**
	 * DataTables-compatible project list endpoint.
	 */
	public function index(Request $request, Response $response): Response
	{
		if (!$this->hasReadAccess())
		{
			throw new HttpForbiddenException($request, 'No read access to project');
		}

		$input = array_merge($request->getQueryParams(), $this->requestBodyAsArray($request));
		$params = $this->readParams($input);
		$values = $this->bo()->read($params);

		if (!empty($input['export']))
		{
			return $this->jsonResponse($response, $values);
		}

		return $this->jsonResponse($response, array(
			'data' => $values,
			'recordsTotal' => (int)$this->bo()->total_records,
			'recordsFiltered' => (int)$this->bo()->total_records,
			'draw' => $this->currentDraw($input),
		));
	}

	/**
	 * Canonical list endpoint with envelope payload.
	 */
	public function listProjects(Request $request, Response $response): Response
	{
		if (!$this->hasReadAccess())
		{
			throw new HttpForbiddenException($request, 'No read access to project');
		}

		$input = array_merge($request->getQueryParams(), $this->requestBodyAsArray($request));
		$params = $this->readParams($input);
		$items = $this->bo()->read($params);

		return $this->jsonResponse($response, array(
			'status' => 'success',
			'data' => $items,
			'meta' => array(
				'start' => (int)$params['start'],
				'length' => (int)$params['results'],
				'total' => (int)$this->bo()->total_records,
			),
		));
	}

	/**
	 * Canonical collection POST endpoint.
	 */
	public function postCollection(Request $request, Response $response): Response
	{
		$input = array_merge($request->getQueryParams(), $this->requestBodyAsArray($request));
		if ($this->isDataTablesRequest($input))
		{
			return $this->index($request, $response);
		}

		return $this->listProjects($request, $response);
	}

	/**
	 * Project detail endpoint.
	 */
	public function show(Request $request, Response $response, array $args): Response
	{
		if (!$this->hasReadAccess())
		{
			throw new HttpForbiddenException($request, 'No read access to project');
		}

		$id = (int)($args['id'] ?? 0);
		if ($id <= 0)
		{
			throw new HttpNotFoundException($request, 'Project not found');
		}

		$item = $this->bo()->read_single($id);
		if (!$item || !is_array($item) || empty($item['id']))
		{
			throw new HttpNotFoundException($request, 'Project not found');
		}

		return $this->jsonResponse($response, array(
			'status' => 'success',
			'data' => $item,
		));
	}
}