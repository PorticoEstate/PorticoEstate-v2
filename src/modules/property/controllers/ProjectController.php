<?php

namespace App\modules\property\controllers;

use JsonException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\modules\property\helpers\ProjectFormHelper;
use App\modules\phpgwapi\security\Acl;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

class ProjectController
{
	private $bo = null;
	private $bocommon = null;
	private ?ProjectFormHelper $formHelperInstance = null;

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

	protected function bocommon()
	{
		if ($this->bocommon === null)
		{
			$this->bocommon = CreateObject('property.bocommon');
		}

		return $this->bocommon;
	}

	protected function workorderBo()
	{
		return CreateObject('property.boworkorder');
	}

	protected function readBudgetAccount(string $bAccountId): array
	{
		$result = execMethod(
			'property.bogeneric.read_single',
			array(
				'id' => $bAccountId,
				'location_info' => array(
					'type' => 'budget_account'
				)
			)
		);

		return is_array($result) ? $result : array();
	}

	protected function readBudgetAccountGroup(int $groupId): array
	{
		$sogeneric = CreateObject('property.sogeneric');
		$sogeneric->get_location_info('b_account_category', false);
		$result = $sogeneric->read_single(array('id' => $groupId), array());

		return is_array($result) ? $result : array();
	}

	protected function notifyService()
	{
		return CreateObject('property.notify');
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

	private function normalizeProjectSavePayload(Request $request, array $input): array
	{
		if (array_key_exists('values', $input) && !is_array($input['values']))
		{
			throw new HttpBadRequestException($request, 'Invalid payload: values must be an object');
		}

		if (array_key_exists('values_attribute', $input) && !is_array($input['values_attribute']))
		{
			throw new HttpBadRequestException($request, 'Invalid payload: values_attribute must be an object');
		}

		if (array_key_exists('RelationInfo', $input) && !is_array($input['RelationInfo']))
		{
			throw new HttpBadRequestException($request, 'Invalid payload: RelationInfo must be an object');
		}

		$values = isset($input['values']) && is_array($input['values'])
			? $input['values']
			: $input;
		$valuesAttribute = isset($input['values_attribute']) && is_array($input['values_attribute'])
			? $input['values_attribute']
			: array();

		// Mirror legacy uiproject::_populate() collection from top-level POST fields.
		$legacyValueFields = array(
			'descr',
			'new_project_id',
			'copy_project',
			'bypass',
			'contact',
			'contact_id',
			'remark',
			'mail_address',
			'approval',
		);
		foreach ($legacyValueFields as $field)
		{
			if (array_key_exists($field, $input) && !array_key_exists($field, $values))
			{
				$values[$field] = $input[$field];
			}
		}

		// Legacy save sets contact_id from the top-level 'contact' field.
		if (array_key_exists('contact', $input))
		{
			$values['contact_id'] = (int)$input['contact'];
		}

		$relationInfo = isset($input['RelationInfo']) && is_array($input['RelationInfo'])
			? $input['RelationInfo']
			: array();

		$derivedParentRelation = $this->extractParentRelationFromEntityLookupFields($input, $values);
		foreach ($derivedParentRelation as $field => $value)
		{
			if (!array_key_exists($field, $relationInfo) || $relationInfo[$field] === '' || $relationInfo[$field] === null)
			{
				$relationInfo[$field] = $value;
			}
		}

		$relationFields = array(
			'location_code',
			'tenant_id',
			'p_num',
			'p_entity_id',
			'p_cat_id',
			'origin',
			'origin_id',
		);
		foreach ($relationFields as $field)
		{
			if (!array_key_exists($field, $relationInfo) && array_key_exists($field, $values))
			{
				$relationInfo[$field] = $values[$field];
			}
		}

		$values = $this->applyRelationInfoPayload($values, $relationInfo, $input);

		return array(
			'values' => $values,
			'values_attribute' => $valuesAttribute,
			'RelationInfo' => $relationInfo,
		);
	}

	/**
	 * Derive parent relation fields from legacy lookup keys like entity_id_1/cat_id_1/entity_num_1.
	 *
	 * @return array{p_entity_id?: int, p_cat_id?: int, p_num?: string}
	 */
	private function extractParentRelationFromEntityLookupFields(array $input, array $values): array
	{
		$sources = array($values, $input);
		$matches = array();

		foreach ($sources as $source)
		{
			foreach ($source as $key => $value)
			{
				if (!is_string($key))
				{
					continue;
				}

				if (!preg_match('/^(entity_id|cat_id|entity_num)_(\d+)$/', $key, $match))
				{
					continue;
				}

				$prefix = $match[1];
				$suffix = (int)$match[2];
				if (!isset($matches[$suffix]))
				{
					$matches[$suffix] = array();
				}
				$matches[$suffix][$prefix] = $value;
			}
		}

		if (!$matches)
		{
			return array();
		}

		ksort($matches);
		foreach ($matches as $entry)
		{
			$entityId = isset($entry['entity_id']) ? (int)$entry['entity_id'] : 0;
			$catId = isset($entry['cat_id']) ? (int)$entry['cat_id'] : 0;
			$pNum = isset($entry['entity_num']) ? trim((string)$entry['entity_num']) : '';

			if ($entityId > 0 && $catId > 0 && $pNum !== '')
			{
				return array(
					'p_entity_id' => $entityId,
					'p_cat_id' => $catId,
					'p_num' => $pNum,
				);
			}
		}

		return array();
	}

	/**
	 * Apply RelationInfo-based enrichment for project save payloads.
	 *
	 * Ensures legacy BO/SO-compatible location and extra structures.
	 */
	private function applyRelationInfoPayload(array $values, array $relationInfo, array $input): array
	{
		if (!isset($values['extra']) || !is_array($values['extra']))
		{
			$values['extra'] = array();
		}

		$relationFields = array(
			'location_code',
			'tenant_id',
			'p_num',
			'p_entity_id',
			'p_cat_id',
			'origin',
			'origin_id',
		);

		$extraRelationFields = array(
			'location_code',
			'tenant_id',
			'p_num',
			'p_entity_id',
			'p_cat_id',
		);

		foreach ($relationFields as $field)
		{
			if (array_key_exists($field, $relationInfo) && !array_key_exists($field, $values))
			{
				$values[$field] = $relationInfo[$field];
			}

			if (in_array($field, $extraRelationFields, true) && array_key_exists($field, $relationInfo) && !array_key_exists($field, $values['extra']))
			{
				$values['extra'][$field] = $relationInfo[$field];
			}
		}

		// Keep origin metadata in RelationInfo/values, never in legacy extra payload.
		unset($values['extra']['origin'], $values['extra']['origin_id']);

		if (isset($values['contact_phone']) && $values['contact_phone'] !== '' && !array_key_exists('contact_phone', $values['extra']))
		{
			$values['extra']['contact_phone'] = $values['contact_phone'];
		}

		// Legacy bypass/origin flows populate values['p'][p_entity_id][...].
		$pEntityId = isset($values['p_entity_id']) ? (int)$values['p_entity_id'] : 0;
		$pCatId = isset($values['p_cat_id']) ? (int)$values['p_cat_id'] : 0;
		$pNum = isset($values['p_num']) ? (string)$values['p_num'] : '';
		if ($pEntityId > 0 && $pCatId > 0 && $pNum !== '')
		{
			if (!isset($values['p']) || !is_array($values['p']))
			{
				$values['p'] = array();
			}

			if (!isset($values['p'][$pEntityId]) || !is_array($values['p'][$pEntityId]))
			{
				$values['p'][$pEntityId] = array();
			}

			$values['p'][$pEntityId]['p_entity_id'] = $pEntityId;
			$values['p'][$pEntityId]['p_cat_id'] = $pCatId;
			$values['p'][$pEntityId]['p_num'] = $pNum;
		}

		$location = array();
		$locationSegmentCount = 0;
		if (isset($values['location']) && is_array($values['location']) && $values['location'])
		{
			foreach ($values['location'] as $key => $part)
			{
				if ((string)$part === '')
				{
					continue;
				}

				if (is_string($key) && preg_match('/^loc\d+$/', $key))
				{
					$location[$key] = $part;
				}
				else
				{
					$location['loc' . (count($location) + 1)] = $part;
				}
				$locationSegmentCount++;
			}
			$locationNameKey = 'loc' . $locationSegmentCount . '_name';
			if ($locationSegmentCount > 0 && !empty($input[$locationNameKey]))
			{
				$values['location_name'] = \Sanitizer::clean_value((string)$input[$locationNameKey], 'string');
			}
		}

		if (!$location)
		{
			for ($i = 1; $i <= 10; $i++)
			{
				$field = 'loc' . $i;
				if (array_key_exists($field, $values) && (string)$values[$field] !== '')
				{
					$location[$field] = $values[$field];
				}
			}
		}

		if (!$location)
		{
			$locationCode = '';
			if (isset($values['location_code']) && trim((string)$values['location_code']) !== '')
			{
				$locationCode = trim((string)$values['location_code']);
			}
			else if (isset($relationInfo['location_code']) && trim((string)$relationInfo['location_code']) !== '')
			{
				$locationCode = trim((string)$relationInfo['location_code']);
				$values['location_code'] = $locationCode;
			}

			if ($locationCode !== '')
			{
				$parts = array_values(array_filter(explode('-', $locationCode), static function ($part)
				{
					return $part !== '';
				}));

				foreach ($parts as $index => $part)
				{
					$location['loc' . ($index + 1)] = $part;
				}
			}
		}

		if ($location)
		{
			$values['location'] = $location;
			if (!isset($values['location_code']) || trim((string)$values['location_code']) === '')
			{
				$values['location_code'] = implode('-', array_values($location));
			}
		}

		return $values;
	}

	protected function hasReadAccess(): bool
	{
		return (bool)Acl::getInstance()->check($this->bo()->acl_location, ACL_READ, 'property');
	}

	protected function hasAddAccess(): bool
	{
		return (bool)Acl::getInstance()->check($this->bo()->acl_location, ACL_ADD, 'property');
	}

	protected function hasEditAccess(): bool
	{
		return (bool)Acl::getInstance()->check($this->bo()->acl_location, ACL_EDIT, 'property');
	}

	protected function hasDeleteAccess(): bool
	{
		return (bool)Acl::getInstance()->check($this->bo()->acl_location, ACL_DELETE, 'property');
	}

	protected function formHelper(): ProjectFormHelper
	{
		if ($this->formHelperInstance === null)
		{
			$this->formHelperInstance = new ProjectFormHelper();
		}

		return $this->formHelperInstance;
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

	private function datatableResponse(Response $response, array $input, array $rows, ?int $total = null): Response
	{
		$count = $total ?? count($rows);
		return $this->jsonResponse($response, array(
			'data' => $rows,
			'recordsTotal' => $count,
			'recordsFiltered' => $count,
			'draw' => $this->currentDraw($input),
		));
	}

	private function normalizeSearchValue(mixed $search): string
	{
		if (is_array($search))
		{
			return (string)($search['value'] ?? '');
		}

		return (string)$search;
	}

	/**
	 * Normalize id filter to either positive int, non-empty positive int array, or 0.
	 */
	private function normalizeIdFilter(mixed $value): mixed
	{
		if (is_array($value))
		{
			$ids = array_values(array_unique(array_filter(array_map(static function ($item)
			{
				return (int)$item;
			}, $value), static function ($id)
			{
				return $id > 0;
			})));

			return $ids ?: 0;
		}

		$id = (int)$value;
		return $id > 0 ? $id : 0;
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
	 * Add legacy UI edit links expected by existing DataTables formatters.
	 */
	private function withLegacyEditLinks(array $rows): array
	{
		foreach ($rows as &$row)
		{
			if (!is_array($row))
			{
				continue;
			}

			$id = (int)($row['id'] ?? $row['project_id'] ?? 0);
			if ($id <= 0)
			{
				continue;
			}

			if (!isset($row['id']))
			{
				$row['id'] = $id;
			}

			$row['link'] = $this->legacyEditLink($id);
		}
		unset($row);

		return $rows;
	}

	private function legacyEditLink(int $id): string
	{
		$params = array(
			'menuaction' => 'property.uiproject.edit',
			'id' => $id,
		);

		if (class_exists('phpgw'))
		{
			return \phpgw::link('/index.php', $params);
		}

		return '?' . http_build_query($params);
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

		$values = $this->withLegacyEditLinks(is_array($values) ? $values : array());

		return $this->datatableResponse($response, $input, $values, (int)$this->bo()->total_records);
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
		$items = $this->withLegacyEditLinks(is_array($items) ? $items : array());

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

	/**
	 * Project orders list endpoint (DataTables-compatible).
	 */
	public function getOrders(Request $request, Response $response, array $args): Response
	{
		if (!$this->hasReadAccess())
		{
			throw new HttpForbiddenException($request, 'No read access to project');
		}

		$input = array_merge($request->getQueryParams(), $this->requestBodyAsArray($request));
		$rawProjectFilter = $input['project_id'] ?? ($args['id'] ?? 0);
		$rawOrderFilter = $input['order_id'] ?? 0;
		$projectId = $this->normalizeIdFilter($rawProjectFilter);
		$orderId = $this->normalizeIdFilter($rawOrderFilter);

		$hasProjectFilter = is_array($projectId) ? !empty($projectId) : ($projectId > 0);
		$hasOrderFilter = is_array($orderId) ? !empty($orderId) : ($orderId > 0);

		if (!$hasProjectFilter && !$hasOrderFilter)
		{
			return $this->datatableResponse($response, $input, array(), 0);
		}

		$order = is_array($input['order'] ?? null) ? $input['order'] : array();
		$columns = is_array($input['columns'] ?? null) ? $input['columns'] : array();
		$orderIndex = (int)($order[0]['column'] ?? -1);
		$orderField = ($orderIndex >= 0 && isset($columns[$orderIndex]['data']))
			? (string)$columns[$orderIndex]['data']
			: (string)($input['order_by'] ?? 'workorder_id');
		$orderDir = strtolower((string)($order[0]['dir'] ?? $input['order_dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';

		$params = array(
			'order' => $orderField,
			'sort' => $orderDir,
			'project_id' => $projectId,
			'order_id' => $orderId,
			'year' => isset($input['year']) ? (int)$input['year'] : 0,
			'start' => isset($input['start']) ? (int)$input['start'] : 0,
			'results' => isset($input['length']) ? (int)$input['length'] : 0,
		);

		$rows = $this->bo()->get_orders($params);
		$total = (int)($this->bo()->so->total_records ?? count($rows));

		return $this->datatableResponse($response, $input, is_array($rows) ? $rows : array(), $total);
	}

	/**
	 * Project vouchers list endpoint (DataTables-compatible).
	 */
	public function getVouchers(Request $request, Response $response, array $args): Response
	{
		if (!$this->hasReadAccess())
		{
			throw new HttpForbiddenException($request, 'No read access to project vouchers');
		}

		$input = array_merge($request->getQueryParams(), $this->requestBodyAsArray($request));
		$projectId = (int)($args['id'] ?? $input['project_id'] ?? 0);
		if ($projectId <= 0)
		{
			return $this->datatableResponse($response, $input, array(), 0);
		}

		$order = is_array($input['order'] ?? null) ? $input['order'] : array();
		$columns = is_array($input['columns'] ?? null) ? $input['columns'] : array();
		$orderIndex = (int)($order[0]['column'] ?? -1);
		$orderField = ($orderIndex >= 0 && isset($columns[$orderIndex]['data']))
			? (string)$columns[$orderIndex]['data']
			: (string)($input['order_by'] ?? 'voucher_id');
		$orderDir = strtolower((string)($order[0]['dir'] ?? $input['order_dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';

		$soinvoice = CreateObject('property.soinvoice');
		$invoices = $soinvoice->read_invoice_sub_sum(array(
			'project_id' => $projectId,
			'year' => isset($input['year']) ? (int)$input['year'] : 0,
			'paid' => 'both',
			'order' => $orderField,
			'sort' => $orderDir,
			'start' => isset($input['start']) ? (int)$input['start'] : 0,
			'results' => isset($input['length']) ? (int)$input['length'] : 0,
			'allrows' => isset($input['length']) && (int)$input['length'] === -1,
		));

		$values = array();
		$invoiceHandler2 = isset($this->bo()->config['invoicehandler']) && $this->bo()->config['invoicehandler'] == 2;
		foreach ((array)$invoices as $entry)
		{
			$voucherId = $invoiceHandler2
				? (!empty($entry['transfer_time']) ? -1 * (int)$entry['voucher_id'] : (int)$entry['voucher_id'])
				: ($entry['external_voucher_id'] ?? null);

			$values[] = array(
				'voucher_id' => $voucherId,
				'voucher_out_id' => $entry['voucher_out_id'] ?? null,
				'workorder_id' => $entry['workorder_id'] ?? null,
				'status' => $entry['status'] ?? '',
				'period' => $entry['period'] ?? '',
				'periodization' => $entry['periodization'] ?? '',
				'periodization_start' => $entry['periodization_start'] ?? '',
				'invoice_id' => $entry['invoice_id'] ?? '',
				'budget_account' => $entry['budget_account'] ?? '',
				'dima' => $entry['dima'] ?? '',
				'dimb' => $entry['dimb'] ?? '',
				'dimd' => $entry['dimd'] ?? '',
				'type' => $entry['type'] ?? '',
				'amount_ex_tax' => ((float)($entry['amount'] ?? 0)) * 0.8,
				'amount_tax' => ((float)($entry['amount'] ?? 0)) * 0.2,
				'amount' => $entry['amount'] ?? 0,
				'approved_amount' => $entry['approved_amount'] ?? 0,
				'vendor' => $entry['vendor'] ?? '',
				'external_project_id' => $entry['project_id'] ?? null,
				'currency' => $entry['currency'] ?? '',
				'budget_responsible' => $entry['budget_responsible'] ?? '',
				'budsjettsigndato' => !empty($entry['budsjettsigndato']) ? (new \phpgwapi_common())->show_date(strtotime($entry['budsjettsigndato']), $GLOBALS['phpgw_info']['user']['preferences']['common']['dateformat'] ?? 'Y-m-d') : '',
				'transfer_time' => !empty($entry['transfer_time']) ? (new \phpgwapi_common())->show_date(strtotime($entry['transfer_time']), $GLOBALS['phpgw_info']['user']['preferences']['common']['dateformat'] ?? 'Y-m-d') : '',
			);
		}

		$total = (int)($soinvoice->total_records ?? count($values));
		return $this->datatableResponse($response, $input, $values, $total);
	}

	/**
	 * Project other-projects list endpoint.
	 */
	public function getOtherProjects(Request $request, Response $response, array $args): Response
	{
		if (!$this->hasReadAccess())
		{
			throw new HttpForbiddenException($request, 'No read access to project');
		}

		$input = array_merge($request->getQueryParams(), $this->requestBodyAsArray($request));

		$id = (int)($args['id'] ?? $input['id'] ?? 0);
		$locationCode = (string)($input['location_code'] ?? '');

		if ($id < 0)
		{
			return $this->datatableResponse($response, $input, array(), 0);
		}
		$search = $this->normalizeSearchValue($input['search'] ?? '');
		$order = is_array($input['order'] ?? null) ? $input['order'] : array();
		$columns = is_array($input['columns'] ?? null) ? $input['columns'] : array();
		$orderIndex = (int)($order[0]['column'] ?? -1);
		$orderField = ($orderIndex >= 0 && isset($columns[$orderIndex]['data']))
			? (string)$columns[$orderIndex]['data']
			: (string)($input['order_by'] ?? 'location_code');
		$orderDir = strtolower((string)($order[0]['dir'] ?? $input['order_dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';

		$params = array(
			'start' => isset($input['start']) ? (int)$input['start'] : 0,
			'results' => isset($input['length']) ? (int)$input['length'] : 0,
			'allrows' => isset($input['length']) && (int)$input['length'] === -1,
			'query'	 => $search,
			'orderField' => $orderField,
			'orderDir'	 => $orderDir,

		);
		$result = $this->bo()->get_other_projects($id, $locationCode, $params);

		$phpgwapi_common = new \phpgwapi_common();
		foreach ($result['values'] as &$entry)
		{
			$link = \phpgw::link('/index.php', array('menuaction' => 'property.uiproject.view', 'id' => $entry['id']));
			$entry['url'] = '<a href="' . $link . '">' . $entry['id'] . '</a>';
			$entry['start_date'] = $phpgwapi_common->show_date($entry['start_date'], 'Y-m-d');
		}
		unset($entry);

		return $this->datatableResponse($response, $input, is_array($result['values']) ? $result['values'] : array(), $result['total_records']);
	}

	/**
	 * Project attachment list endpoint.
	 */
	public function getAttachment(Request $request, Response $response, array $args): Response
	{
		if (!$this->hasReadAccess())
		{
			throw new HttpForbiddenException($request, 'No read access to project attachments');
		}

		$input = array_merge($request->getQueryParams(), $this->requestBodyAsArray($request));
		$voucherId = (int)($input['voucher_id'] ?? $args['id'] ?? 0);
		if ($voucherId <= 0)
		{
			return $this->datatableResponse($response, $input, array(), 0);
		}

		$attachmenList = array();
		$locations = CreateObject('phpgwapi.locations');
		$invoiceConfig = CreateObject('admin.soconfig', $locations->get_id('property', '.invoice'));
		$directoryAttachment = rtrim($invoiceConfig->config_data['import']['local_path'], '/') . "/attachment/{$voucherId}/";

		try
		{
			$dir = new \DirectoryIterator($directoryAttachment);
			foreach ($dir as $file)
			{
				if ($file->isDot() || !$file->isFile() || !$file->isReadable())
				{
					continue;
				}

				$url = \phpgw::link('/index.php', array(
					'menuaction' => 'property.uitts.show_attachment',
					'file_name' => urlencode((string)$file),
					'key' => $voucherId,
				));

				$attachmenList[] = array(
					'voucher_id' => $voucherId,
					'file_name' => '<a href="' . $url . '" target="_blank">' . (string)$file . '</a>',
				);
			}
		}
		catch (\Exception $e)
		{
		}

		return $this->datatableResponse($response, $input, $attachmenList, count($attachmenList));
	}

	/**
	 * Project external project lookup endpoint.
	 */
	public function getExternalProject(Request $request, Response $response): Response
	{
		if (!$this->hasReadAccess())
		{
			throw new HttpForbiddenException($request, 'No read access to project lookups');
		}

		$result = $this->bocommon()->get_external_project();
		return $this->jsonResponse($response, is_array($result) ? $result : array());
	}

	/**
	 * Budget account lookup endpoint.
	 */
	public function getBAccountLookup(Request $request, Response $response): Response
	{
		if (!$this->hasReadAccess())
		{
			throw new HttpForbiddenException($request, 'No access to project budget account lookup');
		}

		$input = array_merge($request->getQueryParams(), $this->requestBodyAsArray($request));
		$query = isset($input['query']) ? (string)$input['query'] : '';
		$role = isset($input['role']) ? (string)$input['role'] : '';

		$result = $this->bocommon()->getBAccount($query, $role);
		return $this->jsonResponse($response, is_array($result) ? $result : array());
	}

	/**
	 * Ecodimb lookup endpoint.
	 */
	public function getEcodimbLookup(Request $request, Response $response): Response
	{
		if (!$this->hasReadAccess())
		{
			throw new HttpForbiddenException($request, 'No access to project ecodimb lookup');
		}

		$input = array_merge($request->getQueryParams(), $this->requestBodyAsArray($request));
		$query = isset($input['query']) ? (string)$input['query'] : '';

		$result = $this->bocommon()->getEcodimb($query);
		return $this->jsonResponse($response, is_array($result) ? $result : array());
	}

	/**
	 * Validate if selected project category is valid for a budget account.
	 */
	public function getCategoryLookup(Request $request, Response $response): Response
	{
		if (!$this->hasReadAccess())
		{
			throw new HttpForbiddenException($request, 'No access to project category lookup');
		}

		$input = array_merge($request->getQueryParams(), $this->requestBodyAsArray($request));
		$catId = (int)($input['cat_id'] ?? 0);
		$bAccountId = isset($input['b_account_id']) ? (string)$input['b_account_id'] : '';

		$boWorkorder = $this->workorderBo();
		$categoryRows = $boWorkorder->cats->return_single($catId);
		$category = isset($categoryRows[0]) && is_array($categoryRows[0]) ? $categoryRows[0] : array();

		if ($bAccountId)
		{
			$bAccount = $this->readBudgetAccount($bAccountId);

			$bAccountGroup = $bAccount['category'] ?? null;
			if (!empty($bAccountGroup))
			{
				$accountGroupData = $this->readBudgetAccountGroup((int)$bAccountGroup);

				$category['mandatory_external_project'] = $accountGroupData['external_project'] ?? null;

				$parentCategories = array();
				if (!empty($accountGroupData['project_category']))
				{
					$parentCategories = explode(',', trim((string)$accountGroupData['project_category'], ','));
				}

				$subCategories = $boWorkorder->cats->return_sorted_array(0, false, '', '', '', false, $parentCategories);
				$catIds = array();
				foreach ((array)$subCategories as $entry)
				{
					$catIds[] = $entry['id'];
				}

				if (!in_array($catId, $catIds))
				{
					$category['active'] = 0;
				}
			}
		}

		return $this->jsonResponse($response, $category);
	}

	/**
	 * Project notify contacts list/update endpoint.
	 *
	 * Preserves legacy notify behavior by delegating to property_notify::refresh_notify_contact_2.
	 */
	public function notifyContacts(Request $request, Response $response, array $args): Response
	{
		if (!$this->hasReadAccess())
		{
			throw new HttpForbiddenException($request, 'No access to project notify contacts');
		}

		$input = array_merge($request->getQueryParams(), $this->requestBodyAsArray($request));
		$projectId = (int)($args['id'] ?? 0);
		$locationId = (int)($input['location_id'] ?? 0);
		$contactId = (int)($input['contact_id'] ?? 0);
		$type = isset($input['type']) ? (string)$input['type'] : '';
		$notify = !empty($input['notify']);
		$ids = $input['ids'] ?? array();

		if (!is_array($ids))
		{
			$ids = $ids !== '' && $ids !== null ? array((int)$ids) : array();
		}

		$notifier = $this->notifyService();
		$content = $notifier->refresh_notify_contact_2($locationId, $projectId, $contactId, $type, $notify, $ids);

		$totalRecords = count((array)$content);
		return $this->jsonResponse($response, array(
			'data' => is_array($content) ? $content : array(),
			'total_records' => $totalRecords,
			'draw' => (int)($input['draw'] ?? 0),
			'recordsTotal' => $totalRecords,
			'recordsFiltered' => $totalRecords,
		));
	}

	public function store(Request $request, Response $response): Response
	{
		if (!$this->hasAddAccess())
		{
			throw new HttpForbiddenException($request, 'No add access to project');
		}

		$input = array_merge($request->getQueryParams(), $this->requestBodyAsArray($request));
		$input = $this->normalizeProjectSavePayload($request, $input);
		$state = $this->formHelper()->mapInput($input, false, 0);
		$state = $this->formHelper()->validate($state);
		$state = $this->formHelper()->persistSave($state, $this->bo());

		if (!empty($state['errors']) || !empty($state['receipt']['error']))
		{
			return $this->jsonResponse($response, array(
				'status' => 'error',
				'errors' => $state['errors'] ?? array(),
				'receipt' => $state['receipt'] ?? array(),
			), 400);
		}

		return $this->jsonResponse($response, array(
			'status' => 'success',
			'data' => array(
				'id' => (int)($state['id'] ?? 0),
			),
			'receipt' => $state['receipt'] ?? array(),
		), 201);
	}

	public function update(Request $request, Response $response, array $args): Response
	{
		if (!$this->hasEditAccess())
		{
			throw new HttpForbiddenException($request, 'No edit access to project');
		}

		$id = (int)($args['id'] ?? 0);
		if ($id <= 0)
		{
			throw new HttpNotFoundException($request, 'Project not found');
		}

		$input = array_merge($request->getQueryParams(), $this->requestBodyAsArray($request));
		$input = $this->normalizeProjectSavePayload($request, $input);
		$state = $this->formHelper()->mapInput($input, true, $id);
		$state = $this->formHelper()->validate($state);
		$state = $this->formHelper()->persistSave($state, $this->bo());

		if (!empty($state['errors']) || !empty($state['receipt']['error']))
		{
			return $this->jsonResponse($response, array(
				'status' => 'error',
				'errors' => $state['errors'] ?? array(),
				'receipt' => $state['receipt'] ?? array(),
			), 400);
		}

		return $this->jsonResponse($response, array(
			'status' => 'success',
			'data' => array(
				'id' => (int)($state['id'] ?? $id),
			),
			'receipt' => $state['receipt'] ?? array(),
		));
	}

	public function destroy(Request $request, Response $response, array $args): Response
	{
		if (!$this->hasDeleteAccess())
		{
			throw new HttpForbiddenException($request, 'No delete access to project');
		}

		$id = (int)($args['id'] ?? 0);
		if ($id <= 0)
		{
			throw new HttpNotFoundException($request, 'Project not found');
		}

		$this->bo()->delete($id);

		return $this->jsonResponse($response, array(
			'status' => 'success',
			'message' => 'Project deleted',
			'data' => array('id' => $id),
		));
	}

	/**
	 * Stream project file by file_id.
	 */
	public function viewFile(Request $request, Response $response): Response
	{
		if (!$this->hasReadAccess())
		{
			throw new HttpForbiddenException($request, 'No read access to project files');
		}

		$input = array_merge($request->getQueryParams(), $this->requestBodyAsArray($request));
		$fileId = (int)($input['file_id'] ?? 0);
		if ($fileId <= 0)
		{
			throw new HttpNotFoundException($request, 'File not found');
		}

		execMethod('property.bofiles.get_file', $fileId);
		return $response;
	}

	/**
	 * Stream or thumbnail a project image file.
	 */
	public function viewImage(Request $request, Response $response): Response
	{
		if (!$this->hasReadAccess())
		{
			throw new HttpForbiddenException($request, 'No read access to project images');
		}

		$input = array_merge($request->getQueryParams(), $this->requestBodyAsArray($request));
		$thumb = !empty($input['thumb']);
		$imgId = (int)($input['img_id'] ?? 0);

		$bofiles = CreateObject('property.bofiles');
		$file = '';
		if ($imgId > 0)
		{
			$fileInfo = $bofiles->vfs->get_info($imgId);
			$file = isset($fileInfo['directory'], $fileInfo['name'])
				? $fileInfo['directory'] . '/' . $fileInfo['name']
				: '';
		}
		else
		{
			$file = urldecode((string)($input['file'] ?? ''));
		}

		if ($file === '')
		{
			throw new HttpNotFoundException($request, 'Image not found');
		}

		$source = "{$bofiles->rootdir}{$file}";
		if (preg_match('/\.\./', $source))
		{
			throw new HttpForbiddenException($request, 'Invalid image path');
		}

		$thumbfile = $source . '.thumb';
		if ($thumb)
		{
			if (!is_file($thumbfile) && $bofiles->is_image($source))
			{
				$bofiles->resize_image($source, $thumbfile, 100);
			}

			if (is_file($thumbfile))
			{
				readfile($thumbfile);
				return $response;
			}
		}

		if ($imgId > 0)
		{
			$bofiles->get_file($imgId);
			return $response;
		}

		$bofiles->view_file('', $file);
		return $response;
	}

	/**
	 * Download report of missing project budgets.
	 */
	public function checkMissingProjectBudget(Request $request, Response $response): Response
	{
		if (!$this->hasReadAccess())
		{
			throw new HttpForbiddenException($request, 'No read access to project reports');
		}

		$values = $this->bo()->get_missing_project_budget();
		$this->bocommon()->download($values, array('project_id', 'year'), array(lang('project_id'), lang('year')));

		return $response;
	}

	/**
	 * Download project list report.
	 */
	public function downloadProjects(Request $request, Response $response): Response
	{
		if (!$this->hasReadAccess())
		{
			throw new HttpForbiddenException($request, 'No read access to project reports');
		}

		$input = array_merge($request->getQueryParams(), $this->requestBodyAsArray($request));
		$params = $this->readParams($input);
		$values = $this->bo()->read($params);
		$uicols = $this->bo()->uicols;

		$this->bocommon()->download(
			$values,
			$uicols['name'] ?? array(),
			$uicols['descr'] ?? array(),
			$uicols['input_type'] ?? array()
		);

		return $response;
	}

	/**
	 * Project file list endpoint (DataTables-compatible).
	 */
	public function getFiles(Request $request, Response $response, array $args): Response
	{
		if (!$this->hasReadAccess())
		{
			throw new HttpForbiddenException($request, 'No read access to project files');
		}

		$input = array_merge($request->getQueryParams(), $this->requestBodyAsArray($request));
		$id = (int)($args['id'] ?? 0);
		$filterTags = $input['tags'] ?? null;
		if (!is_array($filterTags))
		{
			$filterTags = $filterTags !== null && $filterTags !== '' ? array($filterTags) : array();
		}

		if ($id <= 0)
		{
			return $this->datatableResponse($response, $input, array(), 0);
		}

		$linkViewFile = \phpgw::link('/property/project/files/view');

		$values = $this->bo()->get_files($id);
		$bofiles = CreateObject('property.bofiles');

		$contentFiles = array();
		$imgTypes = array('image/jpeg', 'image/png', 'image/gif');
		$sortArray = array();

		foreach ((array)$values as $_entry)
		{
			$tags = array();
			if (!empty($_entry['tags']))
			{
				$decodedTags = json_decode((string)$_entry['tags'], true);
				if (is_array($decodedTags))
				{
					$tags = $decodedTags;
				}
			}

			if ($filterTags)
			{
				if (!$tags || !array_intersect($tags, $filterTags))
				{
					continue;
				}
			}

			$sortArray[] = $_entry['name'];
			$contentFiles[] = array(
				'file_id' => $_entry['file_id'],
				'tags' => $tags,
				'file_name' => '<a href="' . $linkViewFile . '&amp;file_id=' . $_entry['file_id'] . '" target="_blank" title="' . lang('click to view file') . '">' . $_entry['name'] . '</a>',
				'delete_file' => '<input type="checkbox" name="values[file_action][]" value="' . $_entry['file_id'] . '" title="' . lang('Check to delete file') . '">',
				'attach_file' => '<input type="checkbox" name="values[file_attach][]" value="' . $_entry['file_id'] . '" title="' . lang('Check to attach file') . '">',
			);

			$lastIndex = count($contentFiles) - 1;
			if (in_array($_entry['mime_type'], $imgTypes, true) || $bofiles->is_image("{$bofiles->rootdir}{$_entry['directory']}/{$_entry['name']}"))
			{
				$contentFiles[$lastIndex]['file_name'] = $_entry['name'];
				$contentFiles[$lastIndex]['img_id'] = $_entry['file_id'];
				$contentFiles[$lastIndex]['img_url'] = \phpgw::link('/property/project/files/image', array(
					'img_id' => $_entry['file_id'],
					'file' => $_entry['directory'] . '/' . $_entry['file_name'],
				));
				$contentFiles[$lastIndex]['thumbnail_flag'] = 'thumb=1';
			}
		}

		if ($contentFiles)
		{
			array_multisort($sortArray, SORT_ASC, $contentFiles);
		}

		return $this->datatableResponse($response, $input, $contentFiles, count($contentFiles));
	}

	/**
	 * Project file metadata/tag/delete actions endpoint.
	 */
	public function updateFileData(Request $request, Response $response, array $args): Response
	{
		if (!$this->hasEditAccess())
		{
			throw new HttpForbiddenException($request, 'No edit access to project files');
		}

		$input = array_merge($request->getQueryParams(), $this->requestBodyAsArray($request));
		$id = (int)($args['id'] ?? 0);
		$action = (string)($input['action'] ?? '');
		$ids = $input['ids'] ?? array();
		if (!is_array($ids))
		{
			$ids = $ids !== '' && $ids !== null ? array((int)$ids) : array();
		}
		$tags = $input['tags'] ?? array();

		$bofiles = CreateObject('property.bofiles');
		if ($action === 'delete_file' && $ids && $id > 0)
		{
			$bofiles->delete_file("/project/{$id}/", array('file_action' => $ids));
		}
		else if ($action === 'set_tag' && $ids)
		{
			$bofiles->set_tags($ids, $tags);
		}
		else if ($action === 'remove_tag' && $ids)
		{
			$bofiles->remove_tags($ids, $tags);
		}

		return $this->jsonResponse($response, array(
			'status' => 'success',
			'action' => $action,
			'ids' => $ids,
		));
	}

	/**
	 * Build project multi-upload UI fragment.
	 */
	public function buildMultiUploadFile(Request $request, Response $response, array $args): Response
	{
		if (!$this->hasEditAccess())
		{
			throw new HttpForbiddenException($request, 'No edit access to project files');
		}

		\phpgwapi_jquery::init_multi_upload_file();
		$id = (int)($args['id'] ?? 0);

		$multiUploadAction = \phpgw::link('/index.php/property/project/' . $id . '/multi-upload');
		$response->getBody()->write(json_encode(array(
			'multi_upload_action' => $multiUploadAction,
		), JSON_THROW_ON_ERROR));

		return $response->withHeader('Content-Type', 'application/json');
	}

	/**
	 * Handle project multi-upload actions.
	 */
	public function handleMultiUploadFile(Request $request, Response $response, array $args): Response
	{
		if (!$this->hasEditAccess())
		{
			throw new HttpForbiddenException($request, 'No edit access to project files');
		}

		$id = (int)($args['id'] ?? 0);

		\phpgw::import_class('property.multiuploader');
		$options = array();
		$options['base_dir'] = 'project/' . $id;
		$options['upload_dir'] = $GLOBALS['phpgw_info']['server']['files_dir'] . '/property/' . $options['base_dir'] . '/';
		$options['script_url'] = html_entity_decode(\phpgw::link('/index.php/property/project/' . $id . '/multi-upload'));
		$uploadHandler = new \property_multiuploader($options, false);

		switch ($request->getMethod())
		{
			case 'OPTIONS':
			case 'HEAD':
				$uploadHandler->head();
				break;
			case 'GET':
				$uploadHandler->get();
				break;
			case 'PATCH':
			case 'PUT':
			case 'POST':
				$uploadHandler->add_file();
				break;
			case 'DELETE':
				$uploadHandler->delete_file();
				break;
			default:
				return $this->jsonResponse($response, array('status' => 'error', 'message' => 'Method not allowed'), 405);
		}

		return $response;
	}
}