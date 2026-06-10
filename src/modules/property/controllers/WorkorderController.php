<?php

namespace App\modules\property\controllers;

use App\Database\Db;
use App\modules\property\helpers\WorkorderFormHelper;
use App\modules\phpgwapi\services\Settings;
use JsonException;
use OpenApi\Annotations as OA;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\modules\phpgwapi\security\Acl;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

/**
 * @OA\Tag(
 *     name="Workorder",
 *     description="REST API for workorder resources"
 * )
 *
 * @OA\Schema(
 *     schema="WorkorderItem",
 *     type="object",
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="workorder_id", type="integer"),
 *     @OA\Property(property="project_id", type="integer"),
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="status", type="string")
 * )
 *
 * @OA\Schema(
 *     schema="WorkorderReceipt",
 *     type="object",
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="message", type="array", @OA\Items(type="object")),
 *     @OA\Property(property="error", type="array", @OA\Items(type="object"))
 * )
 *
 * @OA\Schema(
 *     schema="WorkorderSavePayload",
 *     type="object",
 *     @OA\Property(property="values", type="object", additionalProperties=true),
 *     @OA\Property(property="values_attribute", type="object", additionalProperties=true),
 *     @OA\Property(property="RelationInfo", type="object", additionalProperties=true)
 * )
 *
 * @OA\Schema(
 *     schema="DataTablesEnvelope",
 *     type="object",
 *     description="Generic DataTables response envelope",
 *     required={"data", "recordsTotal", "recordsFiltered", "draw"},
 *     @OA\Property(
 *         property="data",
 *         type="array",
 *         @OA\Items(type="object", additionalProperties=true)
 *     ),
 *     @OA\Property(property="recordsTotal", type="integer", minimum=0),
 *     @OA\Property(property="recordsFiltered", type="integer", minimum=0),
 *     @OA\Property(property="draw", type="integer", minimum=0)
 * )
 */
class WorkorderController
{
	private $bo = null;
	private $bocommon = null;
	private ?WorkorderFormHelper $formHelper = null;

	public function __construct(ContainerInterface $container)
	{
	}

	protected function bo()
	{
		if ($this->bo === null)
		{
			$this->bo = CreateObject('property.boworkorder');
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

	protected function hasReadAccess(): bool
	{
		return (bool)Acl::getInstance()->check('.project', ACL_READ, 'property');
	}

	protected function hasEditAccess(): bool
	{
		return (bool)Acl::getInstance()->check('.project', ACL_EDIT, 'property');
	}

	protected function hasAddAccess(): bool
	{
		return (bool)Acl::getInstance()->check('.project', ACL_ADD, 'property');
	}

	protected function hasDeleteAccess(): bool
	{
		return (bool)Acl::getInstance()->check('.project', ACL_DELETE, 'property');
	}

	protected function formHelper(): WorkorderFormHelper
	{
		if ($this->formHelper === null)
		{
			$this->formHelper = new WorkorderFormHelper();
		}

		return $this->formHelper;
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
			if (!is_array($json))
			{
				throw new HttpBadRequestException($request, 'Invalid JSON request body');
			}

			return $json;
		}

		$decoded = array();
		parse_str($rawBody, $decoded);
		return is_array($decoded) ? $decoded : array();
	}

	private function normalizeWorkorderSavePayload(Request $request, array $input): array
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

		return $input;
	}

	private function datatableResponse(Response $response, array $input, array $rows, ?int $total = null): Response
	{
		$count = $total ?? count($rows);
		return $this->jsonResponse($response, array(
			'data' => $rows,
			'recordsTotal' => $count,
			'recordsFiltered' => $count,
			'draw' => (int)($input['draw'] ?? 0),
		));
	}

	private function listReadParams(array $input): array
	{
		$searchValue = '';
		if (isset($input['search']) && is_array($input['search']))
		{
			$searchValue = (string)($input['search']['value'] ?? '');
		}

		$orderColumn = '';
		$orderDir = '';
		if (isset($input['order'][0]) && is_array($input['order'][0]) && isset($input['columns']) && is_array($input['columns']))
		{
			$columnIndex = (int)($input['order'][0]['column'] ?? 0);
			if (isset($input['columns'][$columnIndex]) && is_array($input['columns'][$columnIndex]))
			{
				$orderColumn = (string)($input['columns'][$columnIndex]['data'] ?? '');
			}
			$orderDir = (string)($input['order'][0]['dir'] ?? '');
		}

		$length = (int)($input['length'] ?? 0);

		return array(
			'start' => (int)($input['start'] ?? 0),
			'results' => $length,
			'query' => $searchValue,
			'order' => $orderColumn,
			'sort' => $orderDir,
			'allrows' => $length === -1 || !empty($input['export']),
			'start_date' => !empty($input['start_date']) ? urldecode((string)$input['start_date']) : '',
			'end_date' => !empty($input['end_date']) ? urldecode((string)$input['end_date']) : '',
		);
	}

	/**
	 * Workorder DataTables-compatible list endpoint.
	 *
	 * @OA\Get(
	 *     path="/property/workorder",
	 *     summary="List workorders",
	 *     tags={"Workorder"},
	 *     @OA\Parameter(name="start", in="query", @OA\Schema(type="integer", default=0)),
	 *     @OA\Parameter(name="length", in="query", @OA\Schema(type="integer", default=25)),
	 *     @OA\Parameter(name="search", in="query", @OA\Schema(type="string")),
	 *     @OA\Response(response=200, description="Workorder DataTables payload", @OA\JsonContent(ref="#/components/schemas/DataTablesEnvelope"))
	 * )
	 *
	 * @OA\Post(
	 *     path="/property/workorder",
	 *     summary="List workorders (POST variant)",
	 *     tags={"Workorder"},
	 *     @OA\Response(response=200, description="Workorder DataTables payload", @OA\JsonContent(ref="#/components/schemas/DataTablesEnvelope"))
	 * )
	 */
	public function index(Request $request, Response $response): Response
	{
		if (!$this->hasReadAccess())
		{
			throw new HttpForbiddenException($request, 'No read access to workorders');
		}

		$input = array_merge($request->getQueryParams(), $this->requestBodyAsArray($request));
		$params = $this->listReadParams($input);

		$rows = $this->bo()->read($params);
		$total = isset($this->bo()->total_records) ? (int)$this->bo()->total_records : count($rows);

		return $this->datatableResponse($response, $input, is_array($rows) ? $rows : array(), $total);
	}

	/**
	 * Download workorder list report.
	 *
	 * @OA\Get(
	 *     path="/property/workorder/reports/download",
	 *     summary="Download workorder list report",
	 *     tags={"Workorder"},
	 *     @OA\Response(response=200, description="Report download")
	 * )
	 */
	public function download(Request $request, Response $response): Response
	{
		if (!$this->hasReadAccess())
		{
			throw new HttpForbiddenException($request, 'No read access to workorder reports');
		}

		$input = array_merge($request->getQueryParams(), $this->requestBodyAsArray($request));
		$params = $this->listReadParams($input);
		$values = $this->bo()->read($params);
		$uicols = $this->bo()->uicols;

		$this->bocommon()->download(
			is_array($values) ? $values : array(),
			$uicols['name'] ?? array(),
			$uicols['descr'] ?? array(),
			$uicols['input_type'] ?? array()
		);

		return $response;
	}

	/**
	 * Create workorder.
	 *
	 * @OA\Post(
	 *     path="/property/workorder/create",
	 *     summary="Create workorder",
	 *     tags={"Workorder"},
	 *     @OA\RequestBody(
	 *         required=false,
	 *         @OA\JsonContent(ref="#/components/schemas/WorkorderSavePayload")
	 *     ),
	 *     @OA\Response(response=201, description="Created", @OA\JsonContent(ref="#/components/schemas/WorkorderReceipt")),
	 *     @OA\Response(response=400, description="Validation error")
	 * )
	 */
	public function store(Request $request, Response $response): Response
	{
		if (!$this->hasAddAccess())
		{
			throw new HttpForbiddenException($request, 'No add access to workorder');
		}

		$input = array_merge($request->getQueryParams(), $this->requestBodyAsArray($request));
		$input = $this->normalizeWorkorderSavePayload($request, $input);
		$state = $this->formHelper()->mapInput($input, false, 0);
		$state = $this->formHelper()->validate($state);
		$state = $this->formHelper()->persistSave($state, $this->bo());

		if (!empty($state['errors']) || !empty($state['receipt']['error']))
		{
			return $this->jsonResponse($response, array(
				'status' => 'error',
				'errors' => $state['errors'] ?? array(),
				'data' => array('id' => (int)($state['id'] ?? 0)),
				'receipt' => $state['receipt'] ?? array(),
			), 400);
		}

		return $this->jsonResponse($response, array(
			'status' => 'success',
			'data' => array('id' => (int)($state['id'] ?? 0)),
			'receipt' => $state['receipt'] ?? array(),
		), 201);
	}

	/**
	 * Update workorder.
	 *
	 * @OA\Put(
	 *     path="/property/workorder/{id}",
	 *     summary="Update workorder",
	 *     tags={"Workorder"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\RequestBody(
	 *         required=false,
	 *         @OA\JsonContent(ref="#/components/schemas/WorkorderSavePayload")
	 *     ),
	 *     @OA\Response(response=200, description="Updated", @OA\JsonContent(ref="#/components/schemas/WorkorderReceipt")),
	 *     @OA\Response(response=400, description="Validation error")
	 * )
	 */
	public function update(Request $request, Response $response, array $args): Response
	{
		if (!$this->hasEditAccess())
		{
			throw new HttpForbiddenException($request, 'No edit access to workorder');
		}

		$id = (int)($args['id'] ?? 0);
		if ($id <= 0)
		{
			throw new HttpBadRequestException($request, 'Invalid workorder id');
		}

		$input = array_merge($request->getQueryParams(), $this->requestBodyAsArray($request));
		$input = $this->normalizeWorkorderSavePayload($request, $input);
		$state = $this->formHelper()->mapInput($input, true, $id);
		$state = $this->formHelper()->validate($state);
		$state = $this->formHelper()->persistSave($state, $this->bo());

		if (!empty($state['errors']) || !empty($state['receipt']['error']))
		{
			return $this->jsonResponse($response, array(
				'status' => 'error',
				'errors' => $state['errors'] ?? array(),
				'data' => array('id' => (int)($state['id'] ?? $id)),
				'receipt' => $state['receipt'] ?? array(),
			), 400);
		}

		return $this->jsonResponse($response, array(
			'status' => 'success',
			'data' => array('id' => (int)($state['id'] ?? $id)),
			'receipt' => $state['receipt'] ?? array(),
		));
	}

	/**
	 * Delete workorder.
	 *
	 * @OA\Delete(
	 *     path="/property/workorder/{id}",
	 *     summary="Delete workorder",
	 *     tags={"Workorder"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="Deleted")
	 * )
	 */
	public function destroy(Request $request, Response $response, array $args): Response
	{
		if (!$this->hasDeleteAccess())
		{
			throw new HttpForbiddenException($request, 'No delete access to workorder');
		}

		$id = (int)($args['id'] ?? 0);
		if ($id <= 0)
		{
			throw new HttpNotFoundException($request, 'Workorder not found');
		}

		$this->bo()->delete($id);

		return $this->jsonResponse($response, array(
			'status' => 'success',
			'message' => 'Workorder deleted',
			'data' => array('id' => $id),
		));
	}

	private function getVendorContractOptions(int $vendorId, int $selected = 0): array
	{
		$contractList = $this->bocommon()->get_vendor_contract($vendorId, $selected);
		$config = CreateObject('phpgwapi.config', 'property')->read();

		if ($contractList || !empty($config['alternative_to_contract_1']))
		{
			$contractList[] = array(
				'id' => -1,
				'name' => !empty($config['alternative_to_contract_1']) ? $config['alternative_to_contract_1'] : lang('outside contract')
			);

			if (!empty($config['alternative_to_contract_2']))
			{
				$contractList[] = array('id' => -2, 'name' => $config['alternative_to_contract_2']);
			}
			if (!empty($config['alternative_to_contract_3']))
			{
				$contractList[] = array('id' => -3, 'name' => $config['alternative_to_contract_3']);
			}
			if (!empty($config['alternative_to_contract_4']))
			{
				$contractList[] = array('id' => -4, 'name' => $config['alternative_to_contract_4']);
			}
		}

		if ($selected)
		{
			foreach ($contractList as &$contract)
			{
				$contract['selected'] = $selected == $contract['id'] ? 1 : 0;
			}
			unset($contract);
		}

		return is_array($contractList) ? $contractList : array();
	}

	/**
	 * Vendor contract lookup.
	 *
	 * @OA\Get(
	 *     path="/property/workorder/lookups/vendor-contract",
	 *     summary="Vendor contract lookup",
	 *     tags={"Workorder"},
	 *     @OA\Parameter(name="vendor_id", in="query", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="selected", in="query", @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="Lookup rows")
	 * )
	 *
	 * @OA\Post(
	 *     path="/property/workorder/lookups/vendor-contract",
	 *     summary="Vendor contract lookup (POST variant)",
	 *     tags={"Workorder"},
	 *     @OA\Response(response=200, description="Lookup rows")
	 * )
	 */
	public function getVendorContract(Request $request, Response $response): Response
	{
		if (!$this->hasReadAccess())
		{
			throw new HttpForbiddenException($request, 'No read access to vendor contracts');
		}

		$input = array_merge($request->getQueryParams(), $this->requestBodyAsArray($request));
		$vendorId = (int)($input['vendor_id'] ?? 0);
		$selected = (int)($input['selected'] ?? 0);
		return $this->jsonResponse($response, $this->getVendorContractOptions($vendorId, $selected));
	}

	/**
	 * Eco service lookup.
	 *
	 * @OA\Get(
	 *     path="/property/workorder/lookups/eco-service",
	 *     summary="Eco service lookup",
	 *     tags={"Workorder"},
	 *     @OA\Response(response=200, description="Lookup rows")
	 * )
	 *
	 * @OA\Post(
	 *     path="/property/workorder/lookups/eco-service",
	 *     summary="Eco service lookup (POST variant)",
	 *     tags={"Workorder"},
	 *     @OA\Response(response=200, description="Lookup rows")
	 * )
	 */
	public function getEcoService(Request $request, Response $response): Response
	{
		if (!$this->hasReadAccess())
		{
			throw new HttpForbiddenException($request, 'No read access to eco service lookup');
		}

		return $this->jsonResponse($response, (array)$this->bocommon()->get_eco_service());
	}

	/**
	 * UNSPSC lookup.
	 *
	 * @OA\Get(
	 *     path="/property/workorder/lookups/unspsc-code",
	 *     summary="UNSPSC lookup",
	 *     tags={"Workorder"},
	 *     @OA\Response(response=200, description="Lookup rows")
	 * )
	 *
	 * @OA\Post(
	 *     path="/property/workorder/lookups/unspsc-code",
	 *     summary="UNSPSC lookup (POST variant)",
	 *     tags={"Workorder"},
	 *     @OA\Response(response=200, description="Lookup rows")
	 * )
	 */
	public function getUnspscCode(Request $request, Response $response): Response
	{
		if (!$this->hasReadAccess())
		{
			throw new HttpForbiddenException($request, 'No read access to UNSPSC lookup');
		}

		return $this->jsonResponse($response, (array)$this->bocommon()->get_unspsc_code());
	}

	/**
	 * Ecodimb lookup.
	 *
	 * @OA\Get(
	 *     path="/property/workorder/lookups/ecodimb",
	 *     summary="Ecodimb lookup",
	 *     tags={"Workorder"},
	 *     @OA\Response(response=200, description="Lookup rows")
	 * )
	 *
	 * @OA\Post(
	 *     path="/property/workorder/lookups/ecodimb",
	 *     summary="Ecodimb lookup (POST variant)",
	 *     tags={"Workorder"},
	 *     @OA\Response(response=200, description="Lookup rows")
	 * )
	 */
	public function getEcodimb(Request $request, Response $response): Response
	{
		if (!$this->hasReadAccess())
		{
			throw new HttpForbiddenException($request, 'No read access to ecodimb lookup');
		}

		return $this->jsonResponse($response, (array)$this->bocommon()->get_ecodimb());
	}

	/**
	 * Budget account lookup.
	 *
	 * @OA\Get(
	 *     path="/property/workorder/lookups/b-account",
	 *     summary="Budget account lookup",
	 *     tags={"Workorder"},
	 *     @OA\Response(response=200, description="Lookup rows")
	 * )
	 *
	 * @OA\Post(
	 *     path="/property/workorder/lookups/b-account",
	 *     summary="Budget account lookup (POST variant)",
	 *     tags={"Workorder"},
	 *     @OA\Response(response=200, description="Lookup rows")
	 * )
	 */
	public function getBAccount(Request $request, Response $response): Response
	{
		if (!$this->hasReadAccess())
		{
			throw new HttpForbiddenException($request, 'No read access to budget account lookup');
		}

		return $this->jsonResponse($response, (array)$this->bocommon()->get_b_account());
	}

	private function getCategoryLookupResult(int $catId, string $bAccountId = ''): array
	{
		if ($catId <= 0)
		{
			return array();
		}

		$categoryRows = $this->bo()->cats->return_single($catId);
		$category = (is_array($categoryRows) && isset($categoryRows[0]) && is_array($categoryRows[0]))
			? $categoryRows[0]
			: array();

		if (!$category || $bAccountId === '')
		{
			return $category;
		}

		$bAccount = execMethod(
			'property.bogeneric.read_single',
			array(
				'id' => $bAccountId,
				'location_info' => array('type' => 'budget_account')
			)
		);

		$accountGroupId = is_array($bAccount) ? (string)($bAccount['category'] ?? '') : '';
		if ($accountGroupId === '')
		{
			return $category;
		}

		$sogeneric = CreateObject('property.sogeneric');
		$sogeneric->get_location_info('b_account_category', false);
		$accountGroupData = $sogeneric->read_single(array('id' => (int)$accountGroupId), array());

		if (is_array($accountGroupData) && isset($accountGroupData['external_project']))
		{
			$category['mandatory_external_project'] = $accountGroupData['external_project'];
		}

		$parentCategories = array();
		if (is_array($accountGroupData) && !empty($accountGroupData['project_category']))
		{
			$parentCategories = explode(',', trim((string)$accountGroupData['project_category'], ','));
		}

		if ($parentCategories)
		{
			$subCategories = $this->bo()->cats->return_sorted_array(0, false, '', '', '', false, $parentCategories);
			$allowedCatIds = array();
			foreach ((array)$subCategories as $entry)
			{
				if (is_array($entry) && isset($entry['id']))
				{
					$allowedCatIds[] = (int)$entry['id'];
				}
			}

			if (!in_array($catId, $allowedCatIds, true))
			{
				$category['active'] = 0;
			}
		}

		return $category;
	}

	/**
	 * Category lookup and validation.
	 *
	 * @OA\Get(
	 *     path="/property/workorder/lookups/category",
	 *     summary="Category lookup and validation",
	 *     tags={"Workorder"},
	 *     @OA\Parameter(name="cat_id", in="query", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="b_account_id", in="query", @OA\Schema(type="string")),
	 *     @OA\Response(response=200, description="Category payload")
	 * )
	 *
	 * @OA\Post(
	 *     path="/property/workorder/lookups/category",
	 *     summary="Category lookup and validation (POST variant)",
	 *     tags={"Workorder"},
	 *     @OA\Response(response=200, description="Category payload")
	 * )
	 */
	public function getCategory(Request $request, Response $response): Response
	{
		if (!$this->hasReadAccess())
		{
			throw new HttpForbiddenException($request, 'No read access to workorder category lookup');
		}

		$input = array_merge($request->getQueryParams(), $this->requestBodyAsArray($request));
		$catId = (int)($input['cat_id'] ?? 0);
		$bAccountId = (string)($input['b_account_id'] ?? '');

		return $this->jsonResponse($response, $this->getCategoryLookupResult($catId, $bAccountId));
	}

	/**
	 * Receive order amount.
	 *
	 * @OA\Post(
	 *     path="/property/workorder/{id}/receive-order",
	 *     summary="Register received amount for workorder",
	 *     tags={"Workorder"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\RequestBody(
	 *         required=false,
	 *         @OA\JsonContent(
	 *             type="object",
	 *             @OA\Property(property="received_amount", type="number", format="float")
	 *         )
	 *     ),
	 *     @OA\Response(response=200, description="Receive order result")
	 * )
	 */
	public function receiveOrder(Request $request, Response $response, array $args): Response
	{
		if (!$this->hasEditAccess())
		{
			throw new HttpForbiddenException($request, 'No edit access to receive workorder');
		}

		$input = array_merge($request->getQueryParams(), $this->requestBodyAsArray($request));
		$id = (int)($args['id'] ?? $input['id'] ?? 0);
		$receivedAmount = (float)($input['received_amount'] ?? 0);
		return $this->jsonResponse($response, $this->bo()->receive_order($id, $receivedAmount));
	}

	/**
	 * Other orders lookup/list.
	 *
	 * @OA\Get(
	 *     path="/property/workorder/lookups/other-orders",
	 *     summary="List other orders",
	 *     tags={"Workorder"},
	 *     @OA\Parameter(name="vendor_id", in="query", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="location_code", in="query", @OA\Schema(type="string")),
	 *     @OA\Response(response=200, description="Order rows", @OA\JsonContent(ref="#/components/schemas/DataTablesEnvelope"))
	 * )
	 *
	 * @OA\Post(
	 *     path="/property/workorder/lookups/other-orders",
	 *     summary="List other orders (POST variant)",
	 *     tags={"Workorder"},
	 *     @OA\Response(response=200, description="Order rows", @OA\JsonContent(ref="#/components/schemas/DataTablesEnvelope"))
	 * )
	 */
	public function getOtherOrders(Request $request, Response $response): Response
	{
		if (!$this->hasReadAccess())
		{
			throw new HttpForbiddenException($request, 'No read access to other workorders');
		}

		$input = array_merge($request->getQueryParams(), $this->requestBodyAsArray($request));
		$vendorId = (int)($input['vendor_id'] ?? 0);
		$locationCode = (string)($input['location_code'] ?? '');
		$rows = (array)$this->bo()->get_other_orders($vendorId, $locationCode);

		foreach ($rows as &$row)
		{
			if (!is_array($row))
			{
				continue;
			}

			$orderId = (int)($row['id'] ?? $row['workorder_id'] ?? 0);
			if ($orderId <= 0)
			{
				continue;
			}

			$link = \phpgw::link('/index.php', array(
				'menuaction' => 'property.uiworkorder.view',
				'id' => $orderId
			));

			$row['id'] = $orderId;
			$row['url'] = "<a href='{$link}'>{$orderId}</a>";
			$row['select'] = "<input type='radio' name='order_id' value='{$orderId}' class='mychecks'/>";
		}
		unset($row);

		return $this->datatableResponse($response, $input, $rows);
	}

	/**
	 * Workorder file list endpoint.
	 *
	 * @OA\Get(
	 *     path="/property/workorder/{id}/files",
	 *     summary="List workorder files",
	 *     tags={"Workorder"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="File rows", @OA\JsonContent(ref="#/components/schemas/DataTablesEnvelope"))
	 * )
	 *
	 * @OA\Post(
	 *     path="/property/workorder/{id}/files",
	 *     summary="List workorder files (POST variant)",
	 *     tags={"Workorder"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="File rows", @OA\JsonContent(ref="#/components/schemas/DataTablesEnvelope"))
	 * )
	 */
	public function getFiles(Request $request, Response $response, array $args): Response
	{
		if (!$this->hasReadAccess())
		{
			throw new HttpForbiddenException($request, 'No read access to workorder files');
		}

		$input = array_merge($request->getQueryParams(), $this->requestBodyAsArray($request));
		$id = (int)($args['id'] ?? $input['id'] ?? 0);
		$filterTags = $input['tags'] ?? null;
		if (!is_array($filterTags))
		{
			$filterTags = $filterTags !== null && $filterTags !== '' ? array($filterTags) : array();
		}

		if ($id <= 0)
		{
			return $this->datatableResponse($response, $input, array(), 0);
		}

		$viewImageUrl = '/property/workorder/' . $id . '/files/image';

		$linkViewFile = \phpgw::link('/property/workorder/files/view');

		$values = $this->bo()->get_files($id);
		$contentFiles = array();
		$imgTypes = array('image/jpeg', 'image/png', 'image/gif');
		$sortArray = array();

		foreach ((array)$values as $_entry)
		{
			if ($filterTags && empty($_entry['tags']))
			{
				continue;
			}
			if ($filterTags && !empty($_entry['tags']))
			{
				$filterCheck = json_decode((string)$_entry['tags'], true);
				if (!is_array($filterCheck) || !array_intersect($filterCheck, $filterTags))
				{
					continue;
				}
			}

			$tags = array();
			if (!empty($_entry['tags']))
			{
				$decodedTags = json_decode((string)$_entry['tags'], true);
				if (is_array($decodedTags))
				{
					foreach ($decodedTags as $tag)
					{
						$tagValue = (string)$tag;
						$tags[] = Db::getInstance()->stripslashes($tagValue);
					}
				}
			}

			$sortArray[] = $_entry['name'];
			$contentFiles[] = array(
				'file_id' => $_entry['file_id'],
				'tags' => $tags,
				'file_name' => '<a href="' . $linkViewFile . '&amp;file_id=' . $_entry['file_id'] . '" target="_blank" title="' . lang('click to view file') . '">' . $_entry['name'] . '</a>',
				'attach_file' => '<input type="checkbox" name="values[file_attach][]" value="' . $_entry['file_id'] . '" title="' . lang('Check to attach file') . '">'
			);

			$lastIndex = count($contentFiles) - 1;
			if (in_array($_entry['mime_type'], $imgTypes, true))
			{
				$contentFiles[$lastIndex]['file_name'] = $_entry['name'];
				$contentFiles[$lastIndex]['img_id'] = $_entry['file_id'];
				$contentFiles[$lastIndex]['img_url'] = \phpgw::link($viewImageUrl, array(
					'img_id' => $_entry['file_id'],
					'file' => $_entry['directory'] . '/' . $_entry['file_name']
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
	 * Update workorder file metadata/tags.
	 *
	 * @OA\Post(
	 *     path="/property/workorder/{id}/files/actions",
	 *     summary="Update workorder file metadata",
	 *     tags={"Workorder"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="Action result")
	 * )
	 */
	public function updateFileData(Request $request, Response $response, array $args): Response
	{
		if (!$this->hasEditAccess())
		{
			throw new HttpForbiddenException($request, 'No edit access to workorder files');
		}

		$input = array_merge($request->getQueryParams(), $this->requestBodyAsArray($request));
		$id = (int)($args['id'] ?? $input['location_item_id'] ?? 0);
		$ids = $input['ids'] ?? array();
		if (!is_array($ids))
		{
			$ids = $ids !== '' && $ids !== null ? array((int)$ids) : array();
		}
		$action = (string)($input['action'] ?? '');
		$tags = $input['tags'] ?? array();

		$bofiles = CreateObject('property.bofiles');
		if ($action === 'delete_file' && $ids && $id > 0)
		{
			$bofiles->delete_file("/workorder/{$id}/", array('file_action' => $ids));
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
	 * Build workorder multi-upload payload.
	 *
	 * @OA\Get(
	 *     path="/property/workorder/{id}/multi-upload",
	 *     summary="Build workorder multi-upload payload",
	 *     tags={"Workorder"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="Upload metadata")
	 * )
	 */
	public function buildMultiUploadFile(Request $request, Response $response, array $args): Response
	{
		if (!$this->hasEditAccess())
		{
			throw new HttpForbiddenException($request, 'No edit access to workorder files');
		}

		\phpgwapi_jquery::init_multi_upload_file();
		$id = (int)($args['id'] ?? 0);

		$multiUploadAction = \phpgw::link('/property/workorder/' . $id . '/multi-upload');
		return $this->jsonResponse($response, array(
			'multi_upload_action' => $multiUploadAction,
		));
	}

	/**
	 * Handle workorder multi-upload file operations.
	 *
	 * @OA\Post(
	 *     path="/property/workorder/{id}/multi-upload",
	 *     summary="Handle workorder multi-upload (POST/PUT/PATCH/DELETE/HEAD/OPTIONS)",
	 *     tags={"Workorder"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="Upload handler response")
	 * )
	 */
	public function handleMultiUploadFile(Request $request, Response $response, array $args): Response
	{
		if (!$this->hasEditAccess())
		{
			throw new HttpForbiddenException($request, 'No edit access to workorder files');
		}

		$id = (int)($args['id'] ?? 0);
		$serverSettings = Settings::getInstance()->get('server');

		\phpgw::import_class('property.multiuploader');
		$options = array();
		$options['base_dir'] = 'workorder/' . $id;
		$options['upload_dir'] = $serverSettings['files_dir'] . '/property/' . $options['base_dir'] . '/';
		$options['script_url'] = html_entity_decode(\phpgw::link('/property/workorder/' . $id . '/multi-upload'));
		$uploadHandler = new \property_multiuploader($options, false);

		switch (strtoupper($request->getMethod()))
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

	/**
	 * Workorder file attachments endpoint.
	 *
	 * @OA\Get(
	 *     path="/property/workorder/{id}/files-attachments",
	 *     summary="List workorder and project file attachments",
	 *     tags={"Workorder"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="Attachment rows", @OA\JsonContent(ref="#/components/schemas/DataTablesEnvelope"))
	 * )
	 *
	 * @OA\Post(
	 *     path="/property/workorder/{id}/files-attachments",
	 *     summary="List workorder and project file attachments (POST variant)",
	 *     tags={"Workorder"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="Attachment rows", @OA\JsonContent(ref="#/components/schemas/DataTablesEnvelope"))
	 * )
	 */
	public function getFilesAttachments(Request $request, Response $response, array $args): Response
	{
		if (!$this->hasReadAccess())
		{
			throw new HttpForbiddenException($request, 'No read access to workorder file attachments');
		}

		$input = array_merge($request->getQueryParams(), $this->requestBodyAsArray($request));
		$id = (int)($args['id'] ?? $input['id'] ?? 0);
		if ($id <= 0)
		{
			return $this->datatableResponse($response, $input, array(), 0);
		}

		$viewImageUrl = '/property/workorder/' . $id . '/files/image';

		$values = $this->bo()->read_single($id);
		$fileAttachments = isset($values['file_attachments']) && is_array($values['file_attachments']) ? $values['file_attachments'] : array();
		$contentAttachments = array();
		$imgTypes = array('image/jpeg', 'image/png', 'image/gif');
		$sortArray = array();

		$linkWorkorderFile = \phpgw::link('/property/workorder/files/view');
		$langViewFile = lang('click to view file');
		$langSelectFile = lang('Check to attach file');
		$langWorkorder = lang('workorder');

		$z = 0;
		foreach ((array)($values['files'] ?? array()) as $_entry)
		{
			$checked = in_array($_entry['file_id'], $fileAttachments, true) ? 'checked="checked"' : '';
			$sortArray[] = $_entry['name'];

			$contentAttachments[] = array(
				'source' => $langWorkorder,
				'file_id' => $_entry['file_id'],
				'file_name' => "<a href='{$linkWorkorderFile}&amp;file_id={$_entry['file_id']}' target='_blank' title='{$langViewFile}'>{$_entry['name']}</a>",
				'attach_file' => "<input type='checkbox' {$checked} name='values[file_attach][]' value='{$_entry['file_id']}' title='{$langSelectFile}'>"
			);

			if (in_array($_entry['mime_type'], $imgTypes, true))
			{
				$contentAttachments[$z]['file_name'] = $_entry['name'];
				$contentAttachments[$z]['img_id'] = $_entry['file_id'];
				$contentAttachments[$z]['img_url'] = \phpgw::link($viewImageUrl, array(
					'img_id' => $_entry['file_id']
				));
			}
			$z++;
		}

		$linkProjectFile = \phpgw::link('/property/project/files/view');
		$boproject = CreateObject('property.boproject');
		$projectFiles = $boproject->get_files((int)($values['project_id'] ?? 0));
		$langProject = lang('project');

		foreach ((array)$projectFiles as $_entry)
		{
			$checked = in_array($_entry['file_id'], $fileAttachments, true) ? 'checked="checked"' : '';
			$sortArray[] = $_entry['name'];
			$contentAttachments[] = array(
				'source' => $langProject,
				'file_id' => $_entry['file_id'],
				'file_name' => "<a href='{$linkProjectFile}&amp;file_id={$_entry['file_id']}' target='_blank' title='{$langViewFile}'>{$_entry['name']}</a>",
				'attach_file' => "<input type='checkbox' {$checked} name='values[file_attach][]' value='{$_entry['file_id']}' title='{$langSelectFile}'>"
			);

			if (in_array($_entry['mime_type'], $imgTypes, true))
			{
				$contentAttachments[$z]['file_name'] = $_entry['name'];
				$contentAttachments[$z]['img_id'] = $_entry['file_id'];
				$contentAttachments[$z]['img_url'] = \phpgw::link($viewImageUrl, array(
					'img_id' => $_entry['file_id']
				));
			}
			$z++;
		}

		if ($contentAttachments)
		{
			array_multisort($sortArray, SORT_ASC, $contentAttachments);
		}

		return $this->datatableResponse($response, $input, $contentAttachments, count($contentAttachments));
	}

	/**
	 * Stream workorder file by file_id.
	 *
	 * @OA\Get(
	 *     path="/property/workorder/files/view",
	 *     summary="View/download workorder file",
	 *     tags={"Workorder"},
	 *     @OA\Parameter(name="file_id", in="query", required=true, @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="File stream")
	 * )
	 */
	public function viewFile(Request $request, Response $response): Response
	{
		if (!$this->hasReadAccess())
		{
			throw new HttpForbiddenException($request, 'No read access to workorder files');
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
	 * Stream workorder image or thumbnail.
	 *
	 * @OA\Get(
	 *     path="/property/workorder/{id}/files/image",
	 *     summary="View workorder image or thumbnail",
	 *     tags={"Workorder"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="img_id", in="query", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="thumb", in="query", @OA\Schema(type="boolean")),
	 *     @OA\Response(response=200, description="Image stream")
	 * )
	 */
	public function viewImage(Request $request, Response $response, array $args): Response
	{
		if (!$this->hasReadAccess())
		{
			throw new HttpForbiddenException($request, 'No read access to workorder images');
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
}
