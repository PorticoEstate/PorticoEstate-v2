<?php

namespace App\modules\property\controllers;

use JsonException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\modules\property\helpers\LocationFormHelper;
use App\modules\phpgwapi\controllers\Accounts\Accounts;
use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\security\Acl;

class LocationController
{
	private LocationFormHelper $formHelper;
	private const ACL_ADD = 'acl_add';
	private const ACL_EDIT = 'acl_edit';
	private const ACL_DELETE = 'acl_delete';
	private ?\phpgwapi_common $phpgwapiCommon = null;
	private $bo = null;
	private ?Accounts $accounts = null;
	private $controllerHelper = null;

	public function __construct(ContainerInterface $container)
	{
		// Initialize form helper for write operations
		$this->formHelper = new LocationFormHelper();
	}

	protected function bo()
	{
		if ($this->bo === null)
		{
			$this->bo = $this->createObject('property.bolocation', true);
		}

		return $this->bo;
	}

	protected function createObject(string $name, ...$args)
	{
		return CreateObject($name, ...$args);
	}

	protected function makeLocationsController()
	{
		return new \App\modules\phpgwapi\controllers\Locations();
	}

	protected function makeBoCommon()
	{
		return new \App\modules\property\helpers\BoCommon();
	}

	protected function hasReadAccess(): bool
	{
		$bo = $this->bo();
		return (bool)Acl::getInstance()->check($bo->acl_location, ACL_READ, 'property');
	}

	private function phpgwapiCommon(): \phpgwapi_common
	{
		if ($this->phpgwapiCommon === null)
		{
			$this->phpgwapiCommon = new \phpgwapi_common();
		}

		return $this->phpgwapiCommon;
	}

	private function currentAccountId(): int
	{
		$userSettings = Settings::getInstance()->get('user') ?? array();
		return (int)($userSettings['account_id'] ?? 0);
	}

	private function dateFormat(): string
	{
		$userSettings = Settings::getInstance()->get('user') ?? array();
		return (string)($userSettings['preferences']['common']['dateformat'] ?? 'Y-m-d');
	}

	private function accounts(): Accounts
	{
		if ($this->accounts === null)
		{
			$this->accounts = new Accounts();
		}

		return $this->accounts;
	}

	private function controllerHelper()
	{
		if ($this->controllerHelper === null)
		{
			$aclLocation = $this->bo()->acl_location;
			$acl = Acl::getInstance();
			$this->controllerHelper = CreateObject('property.controller_helper', array(
				'acl_location' => $aclLocation,
				'acl_read' => $acl->check($aclLocation, ACL_READ, 'property'),
				'acl_add' => $acl->check($aclLocation, ACL_ADD, 'property'),
				'acl_edit' => $acl->check($aclLocation, ACL_EDIT, 'property'),
				'acl_delete' => $acl->check($aclLocation, ACL_DELETE, 'property'),
				'acl_manage' => $acl->check($aclLocation, 16, 'property'),
			));
		}

		return $this->controllerHelper;
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

	protected function hasAcl(string $aclProperty): bool
	{
		$aclMap = array(
			self::ACL_ADD => ACL_ADD,
			self::ACL_EDIT => ACL_EDIT,
			self::ACL_DELETE => ACL_DELETE,
		);

		if (!isset($aclMap[$aclProperty]))
		{
			return false;
		}

		return (bool)Acl::getInstance()->check($this->bo()->acl_location, $aclMap[$aclProperty], 'property');
	}

	private function hasManageAcl(): bool
	{
		return (bool)Acl::getInstance()->check($this->bo()->acl_location, 16, 'property');
	}

	private function forbiddenResponse(Response $response, string $message): Response
	{
		return $this->jsonResponse($response, [
			'status' => 'error',
			'message' => $message,
		], 403);
	}

	/**
	 * @OA\Get(
	 *     path="/property/location",
	 *     summary="List locations with pagination and search",
	 *     description="Returns paginated list of locations with support for DataTables server-side processing and search/filter",
	 *     tags={"Location"},
	 *     @OA\Parameter(name="search", in="query", required=false, description="Search term", @OA\Schema(type="string")),
	 *     @OA\Parameter(name="order", in="query", required=false, description="Column ordering (JSON)", @OA\Schema(type="string")),
	 *     @OA\Parameter(name="draw", in="query", required=false, description="DataTables draw counter", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="start", in="query", required=false, description="Pagination start", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="length", in="query", required=false, description="Pagination length", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="export", in="query", required=false, description="Export mode", @OA\Schema(type="boolean")),
	 *     @OA\Response(
	 *         response=200,
	 *         description="List of locations",
	 *         @OA\JsonContent(type="object",
	 *             @OA\Property(property="data", type="array"),
	 *             @OA\Property(property="recordsTotal", type="integer"),
	 *             @OA\Property(property="recordsFiltered", type="integer"),
	 *             @OA\Property(property="draw", type="integer")
	 *         )
	 *     )
	 * )
	 */
	public function index(Request $request, Response $response): Response
	{
		$queryParams = $request->getQueryParams();

		$search	 = $queryParams['search'] ?? \Sanitizer::get_var('search');
		$order	 = $queryParams['order'] ?? \Sanitizer::get_var('order');
		$draw	 = (int)($queryParams['draw'] ?? \Sanitizer::get_var('draw', 'int'));
		$columns = (array)($queryParams['columns'] ?? \Sanitizer::get_var('columns'));
		$start = (int)($queryParams['start'] ?? \Sanitizer::get_var('start', 'int', 'REQUEST', 0));
		$length = (int)($queryParams['length'] ?? \Sanitizer::get_var('length', 'int', 'REQUEST', 10));
		$export = !empty($queryParams['export']) || \Sanitizer::get_var('export', 'bool', 'REQUEST', false);
		$lookupTenant = (bool)($queryParams['lookup_tenant'] ?? \Sanitizer::get_var('lookup_tenant', 'bool', 'REQUEST', false));
		$allrows = $export || ($length === -1);

		$orderColumnIndex = (int)($order[0]['column'] ?? -1);
		$orderField = ($orderColumnIndex >= 0 && isset($columns[$orderColumnIndex]['data']))
			? (string)$columns[$orderColumnIndex]['data']
			: '';
		$orderDir = strtolower((string)($order[0]['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';

		$columnSearch = array();
		foreach ($columns as $column)
		{
			if (!empty($column['search']['value']) && !empty($column['data']))
			{
				$columnSearch[$column['data']] = $column['search']['value'];
			}
		}

		$params = array(
			'start' => $start,
			'results' => $length,
			'query' => $search['value'] ?? '',
			'order' => $orderField,
			'sort' => $orderDir,
			'dir' => $orderDir,
			'allrows' => $allrows,
			'lookup_tenant' => $lookupTenant,
			'dry_run' => false,
			'column_search' => $columnSearch,
		);

		$values = $this->bo()->read($params);
		if ($export)
		{
			return $this->jsonResponse($response, $values);
		}

		return $this->jsonResponse($response, array(
			'data' => $values,
			'recordsTotal' => $this->bo()->total_records,
			'recordsFiltered' => $this->bo()->total_records,
			'draw' => $draw,
		));
	}

	/**
	 * Canonical collection POST endpoint.
	 *
	 * DataTables clients can still post collection requests by including DataTables
	 * keys (draw/order/columns), while create payloads are handled as resource creation.
	 */
	public function postCollection(Request $request, Response $response): Response
	{
		$input = array_merge($request->getQueryParams(), $this->requestBodyAsArray($request));
		if ($this->isDataTablesRequest($input))
		{
			return $this->index($request, $response);
		}

		return $this->add($request, $response);
	}

	/**
	 * @OA\Get(
	 *     path="/property/location/list",
	 *     summary="List locations (canonical envelope)",
	 *     description="Returns location collection in a canonical JSON envelope without DataTables-specific keys",
	 *     tags={"Location"},
	 *     @OA\Parameter(name="search", in="query", required=false, description="Search term or object with value key", @OA\Schema(type="string")),
	 *     @OA\Parameter(name="start", in="query", required=false, description="Pagination start", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="length", in="query", required=false, description="Pagination length", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="order_by", in="query", required=false, description="Order field", @OA\Schema(type="string")),
	 *     @OA\Parameter(name="order_dir", in="query", required=false, description="Order direction", @OA\Schema(type="string", enum={"asc", "desc"})),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Canonical location list",
	 *         @OA\JsonContent(type="object",
	 *             @OA\Property(property="status", type="string", example="success"),
	 *             @OA\Property(property="data", type="array", @OA\Items(type="object")),
	 *             @OA\Property(property="meta", type="object",
	 *                 @OA\Property(property="start", type="integer"),
	 *                 @OA\Property(property="length", type="integer"),
	 *                 @OA\Property(property="total", type="integer")
	 *             )
	 *         )
	 *     )
	 * )
	 */
	public function listLocations(Request $request, Response $response): Response
	{
		$input = array_merge($request->getQueryParams(), $this->requestBodyAsArray($request));
		$search = $input['search'] ?? '';
		$searchValue = is_array($search) ? (string)($search['value'] ?? '') : (string)$search;
		$start = (int)($input['start'] ?? 0);
		$length = (int)($input['length'] ?? 25);
		$orderBy = (string)($input['order_by'] ?? '');
		$orderDir = strtolower((string)($input['order_dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
		$lookupTenant = (bool)($input['lookup_tenant'] ?? false);
		$export = !empty($input['export']);
		$allrows = $export || ($length === -1) || !empty($input['allrows']);

		$params = array(
			'start' => $start,
			'results' => $length,
			'query' => $searchValue,
			'order' => $orderBy,
			'sort' => $orderDir,
			'dir' => $orderDir,
			'allrows' => $allrows,
			'lookup_tenant' => $lookupTenant,
			'dry_run' => false,
		);

		$values = $this->bo()->read($params);

		return $this->jsonResponse($response, array(
			'status' => 'success',
			'data' => $values,
			'meta' => array(
				'start' => $start,
				'length' => $length,
				'total' => (int)$this->bo()->total_records,
			),
		));
	}

	private function isDataTablesRequest(array $input): bool
	{
		return array_key_exists('draw', $input)
			|| array_key_exists('columns', $input)
			|| array_key_exists('order', $input);
	}

	/**
	 * @OA\Get(
	 *     path="/property/location/summary",
	 *     summary="Get location summary data",
	 *     description="Delegates to querySummary for summary report generation",
	 *     tags={"Location"},
	 *     @OA\Response(response=200, description="Summary data")
	 * )
	 */
	public function summary(Request $request, Response $response): Response
	{
		return $this->querySummary($request, $response);
	}

	/**
	 * @OA\Get(
	 *     path="/property/location/responsibility-role",
	 *     summary="Get responsibility role configuration",
	 *     description="Returns configuration for responsibility and role assignments with DataTables metadata",
	 *     tags={"Location"},
	 *     @OA\Parameter(name="head", in="query", required=false, description="Get header metadata", @OA\Schema(type="boolean")),
	 *     @OA\Parameter(name="type_id", in="query", required=false, description="Location type ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="user_id", in="query", required=false, description="User ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="role_id", in="query", required=false, description="Role ID", @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="Role responsibility data")
	 * )
	 */
	public function responsibilityRole(Request $request, Response $response): Response
	{
		$queryParams = $request->getQueryParams();
		$bodyParams = $request->getParsedBody();
		$bodyParams = is_array($bodyParams) ? $bodyParams : array();
		$input = array_merge($queryParams, $bodyParams);

		if (!empty($input['head']))
		{
			$typeId = (int)($input['type_id'] ?? 1);
			if (!$typeId)
			{
				$typeId = 1;
			}

			$userId = (int)($input['user_id'] ?? $this->currentAccountId());
			$roleId = (int)($input['role_id'] ?? 0);

			$this->bo()->get_responsible(array(
				'user_id' => $userId,
				'role_id' => $roleId,
				'type_id' => $typeId,
				'dry_run' => true,
			));

			$uicols = $this->getUicolsResponsibilityRole($this->hasAcl(self::ACL_EDIT));
			$searchLevels = array();
			for ($i = 1; $i < $typeId; $i++)
			{
				$searchLevels[] = "loc{$i}";
			}

			$entityDef = array();
			$head = '<thead>';
			$countUicolsName = count($uicols['name']);

			for ($k = 0; $k < $countUicolsName; $k++)
			{
				$params = array(
					'key' => $uicols['name'][$k],
					'label' => $uicols['descr'][$k],
					'sortable' => false,
					'hidden' => ($uicols['input_type'][$k] == 'hidden') ? true : false,
				);

				$params['formatter'] = ""
					. "formatter = function (dummy1, dummy2, oData) {"
					. "return oData['{$uicols['name'][$k]}'];"
					. "}";

				if (!empty($uicols['datatype'][$k]) && $uicols['datatype'][$k] === 'link')
				{
					$uicols['formatter'][$k] = 'JqueryPortico.formatLinkGeneric';
				}

				if (!empty($uicols['formatter'][$k]))
				{
					$params['formatter'] = "formatter = function (dummy1, dummy2, oData) {"
						. " try {"
						. " var ret = {$uicols['formatter'][$k]}('{$uicols['name'][$k]}', oData);"
						. " }"
						. " catch(err) {"
						. " return err.message;"
						. " }"
						. " return ret;"
						. " }";
				}

				if (in_array($uicols['name'][$k], $searchLevels, true))
				{
					$params['formatter'] = "formatter = function (dummy1, dummy2, oData) {"
						. " try {"
						. " var ret = JqueryPortico.searchLink('{$uicols['name'][$k]}', oData);"
						. " }"
						. " catch(err) {"
						. " return err.message;"
						. " }"
						. " return ret;"
						. " }";
				}

				if ($uicols['name'][$k] === 'loc1')
				{
					$params['formatter'] = "formatter = function (dummy1, dummy2, oData) {"
						. " try {"
						. " var ret = JqueryPortico.searchLink('{$uicols['name'][$k]}', oData);"
						. " }"
						. " catch(err) {"
						. " return err.message;"
						. " }"
						. " return ret;"
						. " }";
					$params['sortable'] = true;
				}
				else if (
					isset($uicols['cols_return_extra'][$k])
					&& ($uicols['cols_return_extra'][$k] != 'T' || $uicols['cols_return_extra'][$k] != 'CH')
				)
				{
					$params['sortable'] = true;
				}

				$entityDef[] = $params;

				if (($uicols['input_type'][$k] ?? '') !== 'hidden')
				{
					$head .= '<th>' . $uicols['descr'][$k] . '</th>';
				}
			}

			$head .= '</thead>';

			$datatableDef = array(
				'container' => 'datatable-container',
				'requestUrl' => \phpgw::link('/property/location/responsibility-role', array(
					'type_id' => $typeId,
					'second_display' => 1,
					'status' => (string)($input['status'] ?? ''),
					'location_code' => (string)($input['location_code'] ?? ''),
					'entity_id' => (string)($input['entity_id'] ?? ''),
				)),
				'ColumnDefs' => $entityDef,
				'download' => \phpgw::link('/property/location/download', array(
					'type_id' => $typeId,
					'role_id' => $roleId,
					'export' => true,
					'allrows' => true,
					'download_type' => 'responsiblility_role',
				)),
				'allrows' => true,
			);

			return $this->jsonResponse($response, array(
				'datatable_def' => $datatableDef,
				'datatable_head' => $head,
			));
		}

		return $this->queryRole($request, $response);
	}

	private function getUicolsResponsibilityRole(bool $aclEdit): array
	{
		$uicols = $this->bo()->uicols;
		$uicols['name'][] = 'responsible_contact';
		$uicols['descr'][] = lang('responsible');
		$uicols['sortable'][] = false;
		$uicols['format'][] = '';
		$uicols['formatter'][] = '';
		$uicols['input_type'][] = '';

		$uicols['name'][] = 'responsible_contact_id';
		$uicols['descr'][] = 'dummy';
		$uicols['sortable'][] = false;
		$uicols['format'][] = '';
		$uicols['formatter'][] = '';
		$uicols['input_type'][] = 'hidden';

		$uicols['name'][] = 'responsible_item';
		$uicols['descr'][] = 'dummy';
		$uicols['sortable'][] = false;
		$uicols['format'][] = '';
		$uicols['formatter'][] = '';
		$uicols['input_type'][] = 'hidden';

		$uicols['name'][] = 'select';
		$uicols['descr'][] = lang('select');
		$uicols['sortable'][] = false;
		$uicols['format'][] = '';
		$uicols['formatter'][] = $aclEdit ? 'myFormatterCheck' : '';
		$uicols['input_type'][] = '';

		return $uicols;
	}

	/**
	 * @OA\Post(
	 *     path="/property/location/responsibility-role/save",
	 *     summary="Save responsibility role assignments",
	 *     description="Update responsibility and role assignments for locations",
	 *     tags={"Location"},
	 *     @OA\RequestBody(
	 *         required=true,
	 *         @OA\JsonContent(type="object",
	 *             @OA\Property(property="assign_orig", type="array"),
	 *             @OA\Property(property="assignments", type="array")
	 *         )
	 *     ),
	 *     @OA\Response(response=200, description="Save result"),
	 *     @OA\Response(response=403, description="Forbidden - no edit access")
	 * )
	 */
	public function responsibilityRoleSave(Request $request, Response $response): Response
	{
		$queryParams = $request->getQueryParams();
		$values = $request->getParsedBody();
		$values = is_array($values) ? $values : array();
		$assignOrig = $values['assign_orig'] ?? ($queryParams['assign_orig'] ?? null);
		$assign = $values['assign'] ?? ($queryParams['assign'] ?? null);
		$roleId = (int)($values['role_id'] ?? ($queryParams['role_id'] ?? 0));
		$userId = (int)($values['user_id'] ?? ($queryParams['user_id'] ?? $this->currentAccountId()));

		$result = array('message' => array());
		if (($assign || $assignOrig) && $this->hasAcl(self::ACL_EDIT))
		{
			$userId = abs($userId);
			$account = $this->accounts()->get($userId);
			$contactId = $account->person_id;

			if (empty($roleId))
			{
				$result['error'][] = array('msg' => lang('missing role'));
			}
			else
			{
				$values['contact_id'] = $contactId;
				$values['responsibility_role_id'] = $roleId;
				$values['assign'] = $assign;
				$values['assign_orig'] = $assignOrig;
				$boResponsible = CreateObject('property.boresponsible');
				$result = $boResponsible->update_role_assignment($values);
			}
		}

		return $this->jsonResponse($response, $result);
	}

	/**
	 * @OA\Get(
	 *     path="/property/location/part-of-town",
	 *     summary="Get parts of town for district",
	 *     description="Returns list of parts of town filtered by district ID",
	 *     tags={"Location"},
	 *     @OA\Parameter(name="district_id", in="query", required=false, description="District ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="part_of_town_id", in="query", required=false, description="Part of town ID", @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="Parts of town list",
	 *         @OA\JsonContent(type="array", @OA\Items(type="object",
	 *             @OA\Property(property="id", type="string"),
	 *             @OA\Property(property="name", type="string")
	 *         ))
	 *     )
	 * )
	 */
	public function getPartOfTown(Request $request, Response $response): Response
	{
		$districtId = (int)($request->getQueryParams()['district_id'] ?? 0);
		$partOfTownId = (int)($request->getQueryParams()['part_of_town_id'] ?? 0);
		$bocommon = createObject('property.bocommon');
		$values = $bocommon->select_part_of_town('filter', $partOfTownId, $districtId);
		array_unshift($values, array('id' => '', 'name' => lang('no part of town')));
		return $this->jsonResponse($response, $values);
	}

	/**
	 * @OA\Get(
	 *     path="/property/location/accounts",
	 *     summary="Get available accounts",
	 *     description="Returns list of accounts, optionally filtered by account type",
	 *     tags={"Location"},
	 *     @OA\Parameter(name="account_type", in="query", required=false, description="Account type filter", @OA\Schema(type="string")),
	 *     @OA\Response(response=200, description="List of accounts",
	 *         @OA\JsonContent(type="array", @OA\Items(type="object"))
	 *     )
	 * )
	 */
	public function getAccounts(Request $request, Response $response): Response
	{
		$accountType = (string)($request->getQueryParams()['account_type'] ?? '');
		if ($accountType === '')
		{
			$accountType = (string)($request->getParsedBody()['account_type'] ?? '');
		}

		$values = $this->bo()->get_accounts($accountType);
		return $this->jsonResponse($response, $values);
	}

	/**
	 * @OA\Get(
	 *     path="/property/location/history",
	 *     summary="Get location change history",
	 *     description="Returns DataTables-formatted history of changes to a location",
	 *     tags={"Location"},
	 *     @OA\Parameter(name="location_code", in="query", required=false, description="Location code", @OA\Schema(type="string")),
	 *     @OA\Parameter(name="draw", in="query", required=false, description="DataTables draw counter", @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="History data",
	 *         @OA\JsonContent(type="object",
	 *             @OA\Property(property="data", type="array"),
	 *             @OA\Property(property="draw", type="integer"),
	 *             @OA\Property(property="recordsTotal", type="integer"),
	 *             @OA\Property(property="recordsFiltered", type="integer")
	 *         )
	 *     )
	 * )
	 */
	public function getHistoryData(Request $request, Response $response): Response
	{
		$locationCode = (string)($request->getQueryParams()['location_code'] ?? '');
		$draw = (int)($request->getQueryParams()['draw'] ?? 0) + 1;
		$values = $this->buildHistoryRows($locationCode);
		return $this->jsonResponse($response, array(
			'data' => $values,
			'recordsTotal' => count($values),
			'recordsFiltered' => count($values),
			'draw' => $draw,
		));
	}

	/**
	 * @OA\Get(
	 *     path="/property/location/history/list",
	 *     summary="Get location change history (canonical envelope)",
	 *     description="Returns location history in canonical JSON envelope without DataTables-specific keys",
	 *     tags={"Location"},
	 *     @OA\Parameter(name="location_code", in="query", required=false, description="Location code", @OA\Schema(type="string")),
	 *     @OA\Response(response=200, description="History list",
	 *         @OA\JsonContent(type="object",
	 *             @OA\Property(property="status", type="string", example="success"),
	 *             @OA\Property(property="data", type="array", @OA\Items(type="object")),
	 *             @OA\Property(property="meta", type="object",
	 *                 @OA\Property(property="total", type="integer")
	 *             )
	 *         )
	 *     )
	 * )
	 */
	public function listHistory(Request $request, Response $response): Response
	{
		$queryParams = $request->getQueryParams();
		$bodyParams = $this->requestBodyAsArray($request);
		$input = array_merge($queryParams, $bodyParams);
		$locationCode = (string)($input['location_code'] ?? '');
		$values = $this->buildHistoryRows($locationCode);

		return $this->jsonResponse($response, array(
			'status' => 'success',
			'data' => $values,
			'meta' => array(
				'total' => count($values),
			),
		));
	}

	private function buildHistoryRows(string $locationCode): array
	{
		$values = $this->bo()->get_history($locationCode);
		$dateFormat = $this->dateFormat();
		foreach ($values as &$entry)
		{
			$entry['entry_date'] = $this->phpgwapiCommon()->show_date($entry['entry_date'], $dateFormat);
		}
		unset($entry);

		return $values;
	}

	/**
	 * @OA\Get(
	 *     path="/property/location/documents",
	 *     summary="Get location documents",
	 *     description="Returns DataTables-formatted list of documents associated with a location",
	 *     tags={"Location"},
	 *     @OA\Parameter(name="location_code", in="query", required=false, description="Location code", @OA\Schema(type="string")),
	 *     @OA\Parameter(name="doc_type", in="query", required=false, description="Document type ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="draw", in="query", required=false, description="DataTables draw", @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="Documents list",
	 *         @OA\JsonContent(type="object",
	 *             @OA\Property(property="data", type="array"),
	 *             @OA\Property(property="recordsTotal", type="integer"),
	 *             @OA\Property(property="recordsFiltered", type="integer"),
	 *             @OA\Property(property="draw", type="integer")
	 *         )
	 *     )
	 * )
	 */
	public function getDocuments(Request $request, Response $response): Response
	{
		$queryParams = $request->getQueryParams();
		$bodyParams = $request->getParsedBody();
		$bodyParams = is_array($bodyParams) ? $bodyParams : array();
		$input = array_merge($queryParams, $bodyParams);
		$draw = (int)($input['draw'] ?? 0) + 1;
		$result = $this->buildDocumentRows($input);

		return $this->jsonResponse($response, array(
			'data' => $result['rows'],
			'recordsTotal' => $result['total'],
			'recordsFiltered' => $result['total'],
			'draw' => $draw,
		));
	}

	/**
	 * @OA\Get(
	 *     path="/property/location/documents/list",
	 *     summary="Get location documents (canonical envelope)",
	 *     description="Returns location documents in canonical JSON envelope without DataTables-specific keys",
	 *     tags={"Location"},
	 *     @OA\Parameter(name="location_code", in="query", required=false, description="Location code", @OA\Schema(type="string")),
	 *     @OA\Parameter(name="doc_type", in="query", required=false, description="Document type ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="start", in="query", required=false, description="Pagination start", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="length", in="query", required=false, description="Pagination length", @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="Documents list",
	 *         @OA\JsonContent(type="object",
	 *             @OA\Property(property="status", type="string", example="success"),
	 *             @OA\Property(property="data", type="array", @OA\Items(type="object")),
	 *             @OA\Property(property="meta", type="object",
	 *                 @OA\Property(property="start", type="integer"),
	 *                 @OA\Property(property="length", type="integer"),
	 *                 @OA\Property(property="total", type="integer")
	 *             )
	 *         )
	 *     )
	 * )
	 */
	public function listDocuments(Request $request, Response $response): Response
	{
		$queryParams = $request->getQueryParams();
		$bodyParams = $this->requestBodyAsArray($request);
		$input = array_merge($queryParams, $bodyParams);
		$result = $this->buildDocumentRows($input);

		return $this->jsonResponse($response, array(
			'status' => 'success',
			'data' => $result['rows'],
			'meta' => array(
				'start' => (int)($input['start'] ?? 0),
				'length' => (int)($input['length'] ?? 0),
				'total' => $result['total'],
			),
		));
	}

	private function buildDocumentRows(array $input): array
	{

		$search = $input['search'] ?? array();
		$order = (array)($input['order'] ?? array());
		$columns = (array)($input['columns'] ?? array());
		$docType = (int)($input['doc_type'] ?? 0);
		$locationCode = (string)($input['location_code'] ?? '');
		$export = !empty($input['export']);
		$orderColumnIndex = (int)($order[0]['column'] ?? -1);
		$orderField = ($orderColumnIndex >= 0 && isset($columns[$orderColumnIndex]['data']))
			? (string)$columns[$orderColumnIndex]['data']
			: '';
		$orderDir = strtolower((string)($order[0]['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
		$searchValue = is_array($search) ? ($search['value'] ?? '') : (string)$search;

		$params = array(
			'start' => (int)($input['start'] ?? 0),
			'results' => (int)($input['length'] ?? 0),
			'query' => $searchValue,
			'order' => $orderField,
			'sort' => $orderDir,
			'dir' => $orderDir,
			'allrows' => ((int)($input['length'] ?? 0) == -1) || $export,
			'doc_type' => $docType,
			'location_code' => $locationCode,
		);

		$document = $this->createObject('property.sodocument');
		$documents = $document->read_at_location($params);
		$recordsTotal = $document->total_records;
		$values = array();
		$dateFormat = null;
		foreach ($documents as $item)
		{
			if ($item['link'])
			{
				if ($dateFormat === null)
				{
					$dateFormat = $this->dateFormat();
				}
				$link = $item['link'];
				if (!preg_match('/^HTTP/i', $link))
				{
					$link = 'file:///' . str_replace(':', '|', $link);
				}
				$values[] = array(
					'id' => $item['id'],
					'type' => 'location',
					'document_name' => "<a href='{$link}'>{$item['title']}</a>",
					'title' => $item['title'],
					'document_date' => $this->phpgwapiCommon()->show_date($item['document_date'], $dateFormat)
				);
				continue;
			}
			if ($dateFormat === null)
			{
				$dateFormat = $this->dateFormat();
			}
			$documentName = '<a href="' . \phpgw::link('/index.php', array(
				'menuaction' => 'property.uidocument.view_file',
				'id' => $item['id']
			)) . '" target="_blank">' . $item['document_name'] . '</a>';
			$values[] = array(
				'id' => $item['id'],
				'type' => 'location',
				'document_name' => $documentName,
				'title' => $item['title'],
				'document_date' => $this->phpgwapiCommon()->show_date($item['document_date'], $dateFormat)
			);
		}
		unset($item);

		$locations = $this->makeLocationsController();
		$locationId = $locations->get_id('property', '.location.' . count(explode('-', $locationCode)));
		$genericDocument = $this->createObject('property.sogeneric_document');
		$params['location_id'] = $locationId;
		$params['location_item_id'] = $this->bo()->get_item_id($locationCode);
		$params['order'] = 'name';
		$params['cat_id'] = $docType;
		$documents2 = $genericDocument->read($params);
		$recordsTotal += $genericDocument->total_records;
		foreach ($documents2 as $item)
		{
			$title = '';
			if ($item['path'])
			{
				$temp = (array)json_decode($item['path']);
				$title = implode('<br/>', $temp);
			}
			$documentName = '<a href="' . \phpgw::link('/index.php', array(
				'menuaction' => 'property.uigeneric_document.view_file',
				'file_id' => $item['id']
			)) . '" target="_blank">' . $item['name'] . '</a>';
			$values[] = array(
				'id' => $item['id'],
				'type' => 'generic',
				'document_name' => $documentName,
				'title' => $title,
				'document_date' => $item['created']
			);
		}

		return array(
			'rows' => $values,
			'total' => (int)$recordsTotal,
		);
	}

	/**
	 * @OA\Get(
	 *     path="/property/location/location-data",
	 *     summary="Get complete location data",
	 *     description="Returns complete location record including part of town information",
	 *     tags={"Location"},
	 *     @OA\Parameter(name="location_code", in="query", required=true, description="Location code", @OA\Schema(type="string")),
	 *     @OA\Response(response=200, description="Location data",
	 *         @OA\JsonContent(type="object",
	 *             @OA\Property(property="location_code", type="string"),
	 *             @OA\Property(property="loc1", type="string"),
	 *             @OA\Property(property="part_of_town_name", type="string")
	 *         )
	 *     )
	 * )
	 */
	public function getLocationData(Request $request, Response $response): Response
	{
		$locationCode = (string)($request->getQueryParams()['location_code'] ?? '');
		$values = array();
		if ($locationCode)
		{
			$values = $this->bo()->read_single($locationCode, array('noattrib' => true));
			$partOfTownId = $values['part_of_town_id'] ?? 0;
			$partOfTown = createObject('property.bogeneric')->read_single(array(
				'id' => $partOfTownId,
				'location_info' => array('type' => 'part_of_town')
			));
			$values['part_of_town_name'] = $partOfTown['name'] ?? '';
		}
		return $this->jsonResponse($response, $values);
	}

	/**
	 * @OA\Get(
	 *     path="/property/location/delivery-address",
	 *     summary="Get delivery address",
	 *     description="Returns formatted delivery address for a location",
	 *     tags={"Location"},
	 *     @OA\Parameter(name="loc1", in="query", required=true, description="Location identifier (loc1)", @OA\Schema(type="string")),
	 *     @OA\Response(response=200, description="Delivery address",
	 *         @OA\JsonContent(type="object",
	 *             @OA\Property(property="delivery_address", type="string")
	 *         )
	 *     )
	 * )
	 */
	public function getDeliveryAddress(Request $request, Response $response): Response
	{
		$loc1 = (string)($request->getQueryParams()['loc1'] ?? '');
		return $this->jsonResponse($response, array(
			'delivery_address' => $this->bo()->get_delivery_address($loc1)
		));
	}

	/**
	 * @OA\Get(
	 *     path="/property/location/location-exception",
	 *     summary="Get location exception data",
	 *     description="Returns location exception records with formatted text (URLs as links)",
	 *     tags={"Location"},
	 *     @OA\Parameter(name="location_code", in="query", required=true, description="Location code", @OA\Schema(type="string")),
	 *     @OA\Response(response=200, description="Location exceptions",
	 *         @OA\JsonContent(type="object",
	 *             @OA\Property(property="location_exception", type="array", @OA\Items(type="object"))
	 *         )
	 *     )
	 * )
	 */
	public function getLocationException(Request $request, Response $response): Response
	{
		$locationCode = (string)($request->getQueryParams()['location_code'] ?? '');
		$locationException = $this->bo()->get_location_exception($locationCode);
		foreach ($locationException as &$_locationException)
		{
			$_locationException['category_text'] = preg_replace('!(http|ftp|scp)(s)?:\/\/[a-zA-Z0-9.?%=\-&_/]+!', "<a href=\"\\0\">\\0</a>", $_locationException['category_text']);
			$_locationException['location_descr'] = preg_replace('!(http|ftp|scp)(s)?:\/\/[a-zA-Z0-9.?%=\-&_/]+!', "<a href=\"\\0\">\\0</a>", $_locationException['location_descr']);
		}
		unset($_locationException);
		return $this->jsonResponse($response, array(
			'location_exception' => $locationException
		));
	}

	/**
	 * @OA\Get(
	 *     path="/property/location/responsibility-role/query",
	 *     summary="Query responsibility roles",
	 *     description="Fetch detailed responsibility role data with filtering and sorting",
	 *     tags={"Location"},
	 *     @OA\Parameter(name="user_id", in="query", required=false, description="User ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="role_id", in="query", required=false, description="Role ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="type_id", in="query", required=false, description="Location type", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="lookup_tenant", in="query", required=false, description="Lookup tenant", @OA\Schema(type="boolean")),
	 *     @OA\Response(response=200, description="Query results")
	 * )
	 */
	public function queryRole(Request $request, Response $response): Response
	{
		$queryParams = $request->getQueryParams();
		$bodyParams = $request->getParsedBody();
		$bodyParams = is_array($bodyParams) ? $bodyParams : array();
		$input = array_merge($queryParams, $bodyParams);

		$lookupTenant = (bool)($input['lookup_tenant'] ?? false);
		$userId = (int)($input['user_id'] ?? $this->currentAccountId());
		$roleId = (int)($input['role_id'] ?? 0);
		$search = $input['search'] ?? array();
		$order = (array)($input['order'] ?? array());
		$draw = (int)($input['draw'] ?? 0) + 1;
		$columns = (array)($input['columns'] ?? array());
		$orderColumnIndex = (int)($order[0]['column'] ?? -1);
		$orderField = ($orderColumnIndex >= 0 && isset($columns[$orderColumnIndex]['data']))
			? (string)$columns[$orderColumnIndex]['data']
			: '';
		$orderDir = strtolower((string)($order[0]['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
		$searchValue = is_array($search) ? ($search['value'] ?? '') : (string)$search;

		$params = array(
			'start' => (int)($input['start'] ?? 0),
			'results' => (int)($input['length'] ?? 0),
			'query' => $searchValue,
			'order' => $orderField,
			'sort' => $orderDir,
			'dir' => $orderDir,
			'allrows' => ((int)($input['length'] ?? 0) == -1),
			'lookup_tenant' => $lookupTenant,
			'user_id' => $userId,
			'role_id' => $roleId,
		);

		$values = $this->bo()->get_responsible($params);
		return $this->jsonResponse($response, array(
			'data' => $values,
			'recordsTotal' => $this->bo()->total_records,
			'recordsFiltered' => $this->bo()->total_records,
			'draw' => $draw,
		));
	}

	/**
	 * @OA\Get(
	 *     path="/property/location/summary/query",
	 *     summary="Query location summary data",
	 *     description="Fetch summary report with specified parameters and filtering",
	 *     tags={"Location"},
	 *     @OA\Parameter(name="search", in="query", required=false, description="Search filter", @OA\Schema(type="string")),
	 *     @OA\Parameter(name="export", in="query", required=false, description="Export raw data", @OA\Schema(type="boolean")),
	 *     @OA\Response(response=200, description="Summary query results")
	 * )
	 */
	public function querySummary(Request $request, Response $response): Response
	{
		$values = $this->bo()->read_summary();
		if (!empty($request->getQueryParams()['export']))
		{
			return $this->jsonResponse($response, $values);
		}
		return $this->jsonResponse($response, array(
			'data' => $values,
			'recordsTotal' => count($values),
			'recordsFiltered' => count($values),
			'draw' => (int)($request->getQueryParams()['draw'] ?? 0) + 1,
		));
	}

	/**
	 * @OA\Get(
	 *     path="/property/location/component/controls",
	 *     summary="Get controls at location component",
	 *     description="Returns list of controls associated with a location component",
	 *     tags={"Location\"},
	 *     @OA\Parameter(name="location_id", in="query", required=true, description="Location ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="id", in="query", required=false, description="Component ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="skip_json", in="query", required=false, description="Skip JSON encoding", @OA\Schema(type="boolean")),
	 *     @OA\Response(response=200, description="Controls list")
	 * )
	 */
	public function getControlsAtComponent(Request $request, Response $response): Response
	{
		$locationId = (int)($request->getQueryParams()['location_id'] ?? 0);
		$id = (int)($request->getQueryParams()['id'] ?? 0);
		$skipJson = (bool)($request->getQueryParams()['skip_json'] ?? false);
		return $this->jsonResponse($response, $this->controllerHelper()->get_controls_at_component($locationId, $id, $skipJson));
	}

	/**
	 * @OA\Get(
	 *     path="/property/location/component/cases",
	 *     summary="Get cases at location component",
	 *     description="Returns list of cases for a location component",
	 *     tags={"Location"},
	 *     @OA\Parameter(name="location_id", in="query", required=true, description="Location ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="id", in="query", required=false, description="Component ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="year", in="query", required=false, description="Filter by year", @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="Cases list")
	 * )
	 */
	public function getCases(Request $request, Response $response): Response
	{
		$locationId = (int)($request->getQueryParams()['location_id'] ?? 0);
		$id = (int)($request->getQueryParams()['id'] ?? 0);
		$year = (int)($request->getQueryParams()['year'] ?? 0);
		return $this->jsonResponse($response, $this->controllerHelper()->get_cases($locationId, $id, $year));
	}

	/**
	 * @OA\Get(
	 *     path="/property/location/component/checklists",
	 *     summary="Get checklists at location component",
	 *     description="Returns list of checklists for a location component",
	 *     tags={"Location"},
	 *     @OA\Parameter(name="location_id", in="query", required=true, description="Location ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="id", in="query", required=false, description="Component ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="year", in="query", required=false, description="Filter by year", @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="Checklists list")
	 * )
	 */
	public function getChecklists(Request $request, Response $response): Response
	{
		$locationId = (int)($request->getQueryParams()['location_id'] ?? 0);
		$id = (int)($request->getQueryParams()['id'] ?? 0);
		$year = (int)($request->getQueryParams()['year'] ?? 0);
		return $this->jsonResponse($response, $this->controllerHelper()->get_checklists($locationId, $id, $year));
	}

	/**
	 * @OA\Get(
	 *     path="/property/location/component/cases-for-checklist",
	 *     summary="Get cases for a checklist",
	 *     description="Returns DataTables-formatted cases for a specific checklist",
	 *     tags={"Location"},
	 *     @OA\Parameter(name="check_list_id", in="query", required=false, description="Checklist ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="draw", in="query", required=false, description="DataTables draw counter", @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="Cases for checklist")
	 * )
	 */
	public function getCasesForChecklist(Request $request, Response $response): Response
	{
		$queryParams = $request->getQueryParams();
		$bodyParams = $request->getParsedBody();
		$bodyParams = is_array($bodyParams) ? $bodyParams : array();
		$checkListId = (int)($queryParams['check_list_id'] ?? ($bodyParams['check_list_id'] ?? 0));
		$draw = (int)($queryParams['draw'] ?? 0);

		return $this->jsonResponse(
			$response,
			$this->controllerHelper()->get_cases_for_checklist($checkListId ?: null, true, $draw)
		);
	}

	/**
	 * @OA\Post(
	 *     path="/property/location/edit-field",
	 *     summary="Edit a single location field",
	 *     description="Updates a specific field on a location record via inline editing",
	 *     tags={"Location"},
	 *     @OA\Parameter(name="type_id", in="query", required=false, description="Location type ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="field_name", in="query", required=true, description="Field name to edit", @OA\Schema(type="string")),
	 *     @OA\Response(response=200, description="OK on success, ERROR on failure")
	 * )
	 */
	public function editField(Request $request, Response $response): Response
	{
		$typeId = (int)($request->getQueryParams()['type_id'] ?? 0);
		$bodyParams = $request->getParsedBody();
		$bodyParams = is_array($bodyParams) ? $bodyParams : array();
		$id = (int)($bodyParams['id'] ?? 0);
		$fieldName = (string)($request->getQueryParams()['field_name'] ?? '');
		$value = $bodyParams['value'] ?? ($request->getQueryParams()['value'] ?? null);

		if (!$this->hasAcl(self::ACL_EDIT))
		{
			return $this->jsonResponse($response, 'ERROR');
		}
		if (!$this->hasManageAcl() && $fieldName !== 'contact_phone')
		{
			return $this->jsonResponse($response, 'ERROR');
		}
		if (!$id || !$fieldName)
		{
			return $this->jsonResponse($response, 'ERROR');
		}

		$data = array(
			'type_id' => $typeId,
			'id' => $id,
			'field_name' => $fieldName,
			'value' => $value,
		);

		try
		{
			$ret = $this->bo()->edit_field($data);
		}
		catch (\Exception $e)
		{
			$ret = false;
		}

		return $this->jsonResponse($response, $ret ? 'OK' : 'ERROR');
	}

	/**
	 * @OA\Post(
	 *     path="/property/location",
	 *     summary="Create a new location",
	 *     description="Creates a new location record with provided field values and custom attributes. Legacy alias /property/location/add is still supported for backward compatibility.",
	 *     tags={"Location"},
	 *     @OA\RequestBody(
	 *         required=true,
	 *         @OA\JsonContent(type="object",
	 *             @OA\Property(property="location_code", type="string", example="5000-03", description="Unique location code"),
	 *             @OA\Property(property="loc1", type="string", example="5000", description="Level 1 location code"),
	 *             @OA\Property(property="loc1_name", type="string", example="MØHLENPRIS III", description="Level 1 location name"),
	 *             @OA\Property(property="loc2", type="string", example="03", description="Level 2 location code"),
	 *             @OA\Property(property="loc2_name", type="string", example="Bygg 3", description="Level 2 location name"),
	 *             @OA\Property(property="cat_id", type="string", example="5", description="Category/type ID"),
	 *             @OA\Property(property="values_attribute", type="object", description="Custom attributes keyed by attribute ID (integer)",
	 *                 @OA\AdditionalProperties(type="object",
	 *                     @OA\Property(property="value", type="string", description="Attribute value")
	 *                 )
	 *             )
	 *         )
	 *     ),
	 *     @OA\Response(response=200, description="Location created successfully",
	 *         @OA\JsonContent(type="object",
	 *             @OA\Property(property="status", type="string", enum={"success", "error"}),
	 *             @OA\Property(property="message", type="array", description="Validation messages"),
	 *             @OA\Property(property="location_code", type="string", description="The created location code"),
	 *             @OA\Property(property="id", type="integer", description="Internal location ID")
	 *         )
	 *     ),
	 *     @OA\Response(response=400, description="Validation error"),
	 *     @OA\Response(response=403, description="Forbidden - no add access")
	 * )
	 */
	public function add(Request $request, Response $response): Response
	{
		if (!$this->hasAcl(self::ACL_ADD))
		{
			return $this->forbiddenResponse($response, 'No add access for location');
		}

		$bodyParams = $this->requestBodyAsArray($request);

		// Map input -> validate -> persist -> build response
		$state = $this->formHelper->mapInput($bodyParams, null);
		$state = $this->formHelper->applyLegacyRules($state, [], false);
		$state = $this->formHelper->validate($state);
		$state = $this->formHelper->persistSave($state);
		$responseData = $this->formHelper->buildSaveResponse($state, 'save');

		return $this->jsonResponse($response, $responseData['payload']);
	}

	/**
	 * @OA\Put(
	 *     path="/property/location/{location_code}",
	 *     summary="Update an existing location",
	 *     description="Updates a location record with field changes, custom attribute modifications, and optional rename via location_code_original threading",
	 *     tags={"Location"},
	 *     @OA\Parameter(name="location_code", in="path", required=true, description="Current location code", @OA\Schema(type="string")),
	 *     @OA\RequestBody(
	 *         required=true,
	 *         @OA\JsonContent(type="object",
	 *             @OA\Property(property="location_code", type="string", example="5000-04", description="New location code (for rename) or existing code"),
	 *             @OA\Property(property="loc1", type="string", example="5000", description="Level 1 location code"),
	 *             @OA\Property(property="loc2", type="string", example="04", description="Level 2 location code"),
	 *             @OA\Property(property="cat_id", type="string", example="5", description="Category/type ID"),
	 *             @OA\Property(property="values_attribute", type="object", description="Updated custom attributes keyed by attribute ID",
	 *                 @OA\AdditionalProperties(type="object",
	 *                     @OA\Property(property="value", type="string", description="Updated attribute value")
	 *                 )
	 *             )
	 *         )
	 *     ),
	 *     @OA\Response(response=200, description="Location updated successfully",
	 *         @OA\JsonContent(type="object",
	 *             @OA\Property(property="status", type="string", enum={"success", "error"}),
	 *             @OA\Property(property="message", type="array", description="Validation messages"),
	 *             @OA\Property(property="location_code", type="string", description="The location code (new if renamed)")
	 *         )
	 *     ),
	 *     @OA\Response(response=400, description="Invalid location code or validation error"),
	 *     @OA\Response(response=403, description="Forbidden - no edit access")
	 * )
	 */
	public function save(Request $request, Response $response, array $args): Response
	{
		if (!$this->hasAcl(self::ACL_EDIT))
		{
			return $this->forbiddenResponse($response, 'No edit access for location');
		}

		$locationCode = (string)($args['location_code'] ?? '');
		if ($locationCode === '' || !preg_match('/^[A-Za-z0-9_-]+(?:-[A-Za-z0-9_-]+)*$/', $locationCode))
		{
			return $this->jsonResponse($response, [
				'status' => 'error',
				'message' => 'Invalid location code',
				'location_code' => null,
			], 400);
		}

		$bodyParams = $this->requestBodyAsArray($request);
		$bodyParams['location_code_original'] = $locationCode;
		if (empty($bodyParams['location_code']))
		{
			$bodyParams['location_code'] = $locationCode;
		}

		// Map input -> validate -> persist -> build response
		$state = $this->formHelper->mapInput($bodyParams, 1);
		$state = $this->formHelper->applyLegacyRules($state, [], true);
		$state = $this->formHelper->validate($state);
		$state = $this->formHelper->persistSave($state);
		$responseData = $this->formHelper->buildSaveResponse($state, 'save');

		$statusCode = ($responseData['payload']['status'] === 'error') ? 400 : 200;
		return $this->jsonResponse($response, $responseData['payload'], $statusCode);
	}

	/**
	 * @OA\Post(
	 *     path="/property/location/component/add-control",
	 *     summary="Add a control at a location component",
	 *     description="Adds a control record to a location component",
	 *     tags={"Location"},
	 *     @OA\RequestBody(required=true),
	 *     @OA\Response(response=200, description="Control added")
	 * )
	 */
	public function addControl(Request $request, Response $response): Response
	{
		$queryParams = $request->getQueryParams();
		$bodyParams = $this->requestBodyAsArray($request);
		$payload = array_merge($queryParams, $bodyParams);

		return $this->jsonResponse($response, $this->controllerHelper()->add_control($payload));
	}

	/**
	 * @OA\Post(
	 *     path="/property/location/component/update-control-serie",
	 *     summary="Update control series",
	 *     description="Updates a series of control records",
	 *     tags={"Location"},
	 *     @OA\RequestBody(required=true),
	 *     @OA\Response(response=200, description="Controls updated")
	 * )
	 */
	public function updateControlSerie(Request $request, Response $response): Response
	{
		$queryParams = $request->getQueryParams();
		$bodyParams = $this->requestBodyAsArray($request);
		$payload = array_merge($queryParams, $bodyParams);

		return $this->jsonResponse($response, $this->controllerHelper()->update_control_serie($payload));
	}

	/**
	 * @OA\Delete(
	 *     path="/property/location/{location_code}",
	 *     summary="Delete a location",
	 *     description="Deletes a location record by its code",
	 *     tags={"Location"},
	 *     @OA\Parameter(name="location_code", in="path", required=true, description="Location code to delete", @OA\Schema(type="string")),
	 *     @OA\Response(response=200, description="Location deleted",
	 *         @OA\JsonContent(type="object",
	 *             @OA\Property(property="status", type="string", enum={"success"}),
	 *             @OA\Property(property="message", type="string")
	 *         )
	 *     ),
	 *     @OA\Response(response=400, description="Missing location code"),
	 *     @OA\Response(response=403, description="Forbidden - no delete access")
	 * )
	 */
	public function delete(Request $request, Response $response, array $args): Response
	{
		if (!$this->hasAcl(self::ACL_DELETE))
		{
			return $this->forbiddenResponse($response, 'No delete access for location');
		}

		$locationCode = (string)($args['location_code'] ?? '');
		if ($locationCode === '')
		{
			return $this->jsonResponse($response, array(
				'status' => 'error',
				'message' => 'Missing location code',
			), 400);
		}

		$this->bo()->delete($locationCode);
		return $this->jsonResponse($response, array(
			'status' => 'success',
			'message' => "location_code {$locationCode} " . lang('has been deleted'),
		));
	}

	/**
	 * @OA\Delete(
	 *     path="/property/location/delete",
	 *     summary="Delete a location via query/body parameter",
	 *     description="Deletes a location record using location_code from query or request body",
	 *     tags={"Location"},
	 *     @OA\Parameter(name="location_code", in="query", required=false, description="Location code to delete", @OA\Schema(type="string")),
	 *     @OA\RequestBody(required=false),
	 *     @OA\Response(response=200, description="Location deleted",
	 *         @OA\JsonContent(type="object",
	 *             @OA\Property(property="status", type="string", enum={"success"}),
	 *             @OA\Property(property="message", type="string")
	 *         )
	 *     ),
	 *     @OA\Response(response=400, description="Missing location code"),
	 *     @OA\Response(response=403, description="Forbidden - no delete access")
	 * )
	 */
	public function deleteByLocationCode(Request $request, Response $response): Response
	{
		if (!$this->hasAcl(self::ACL_DELETE))
		{
			return $this->forbiddenResponse($response, 'No delete access for location');
		}

		$locationCode = (string)($request->getQueryParams()['location_code'] ?? '');
		if ($locationCode === '')
		{
			$bodyParams = $this->requestBodyAsArray($request);
			$locationCode = (string)($bodyParams['location_code'] ?? '');
		}
		if ($locationCode === '')
		{
			return $this->jsonResponse($response, array(
				'status' => 'error',
				'message' => 'Missing location code',
			), 400);
		}

		$this->bo()->delete($locationCode);
		return $this->jsonResponse($response, array(
			'status' => 'success',
			'message' => "location_code {$locationCode} " . lang('has been deleted'),
		));
	}

	/**
	 * @OA\Get(
	 *     path="/property/location/download",
	 *     summary="Download location data as spreadsheet",
	 *     description="Exports location data in spreadsheet format (XLS, CSV, etc.)",
	 *     tags={"Location"},
	 *     @OA\Parameter(name="download_type", in="query", required=false, description="Type of download (default/summary/responsibility_role)", @OA\Schema(type="string", enum={"", "summary", "responsibility_role"})),
	 *     @OA\Parameter(name="user_id", in="query", required=false, description="User ID (for responsibility_role)", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="role_id", in="query", required=false, description="Role ID (for responsibility_role)", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="type_id", in="query", required=false, description="Location type (for responsibility_role)", @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="File download initiated",
	 *         @OA\MediaType(mediaType="application/vnd.ms-excel")
	 *     ),
	 *     @OA\Response(response=403, description="Forbidden - no read access")
	 * )
	 */
	public function download(Request $request, Response $response, array $args): Response
	{
		$bo = $this->bo();

		if (!$this->hasReadAccess())
		{
			return $this->forbiddenResponse($response, 'No read access for location');
		}

		$queryParams  = $request->getQueryParams();
		$downloadType = (string)($queryParams['download_type'] ?? '');

		switch ($downloadType)
		{
			case 'summary':
				$list   = $bo->read_summary();
				$uicols = $bo->uicols;
				break;

			case 'responsiblility_role':
				$userId = isset($queryParams['user_id']) ? (int)$queryParams['user_id'] : null;
				$roleId = isset($queryParams['role_id']) ? (int)$queryParams['role_id'] : null;
				$typeId = isset($queryParams['type_id']) ? (int)$queryParams['type_id'] : null;
				$search = $queryParams['search'] ?? '';

				$list = $bo->get_responsible(array(
					'user_id'  => $userId,
					'role_id'  => $roleId,
					'type_id'  => $typeId,
					'query'    => is_array($search) ? ($search['value'] ?? '') : $search,
					'allrows'  => true,
				));

				foreach ($list as &$entry)
				{
					$entry['role_id'] = $roleId;
				}
				unset($entry);

				$uicols = $bo->uicols;

				$uicols['name'][]       = 'role_id';
				$uicols['descr'][]      = 'role_id';
				$uicols['input_type'][] = '';

				$uicols['name'][]       = 'responsible_contact';
				$uicols['descr'][]      = lang('responsible');
				$uicols['input_type'][] = '';

				$uicols['name'][]       = 'contact_id';
				$uicols['descr'][]      = 'contact_id';
				$uicols['input_type'][] = '';
				break;

			default:
				$list   = $bo->read(array('allrows' => true));
				$uicols = $bo->uicols;
				break;
		}

		$bocommon = $this->makeBoCommon();
		$bocommon->download(
			(array)$list,
			$uicols['name'],
			$uicols['descr'],
			$uicols['input_type'] ?? array()
		);

		return $response;
	}
}
