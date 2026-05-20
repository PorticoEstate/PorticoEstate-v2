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

	public function index(Request $request, Response $response): Response
	{
		$queryParams = $request->getQueryParams();

		$search	 = $queryParams['search'] ?? \Sanitizer::get_var('search');
		$order	 = $queryParams['order'] ?? \Sanitizer::get_var('order');
		$draw	 = (int)($queryParams['draw'] ?? \Sanitizer::get_var('draw', 'int'));
		$columns = (array)($queryParams['columns'] ?? \Sanitizer::get_var('columns'));
		$start = (int)($queryParams['start'] ?? \Sanitizer::get_var('start', 'int', 'REQUEST', 0));
		$export = !empty($queryParams['export']) || \Sanitizer::get_var('export', 'bool', 'REQUEST', false);
		$lookupTenant = (bool)($queryParams['lookup_tenant'] ?? \Sanitizer::get_var('lookup_tenant', 'bool', 'REQUEST', false));
		$allrows = $export || ((int)($queryParams['length'] ?? \Sanitizer::get_var('length', 'int', 'REQUEST', 0)) === -1);

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
			'results' => (int)($queryParams['length'] ?? 0),
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

	public function summary(Request $request, Response $response): Response
	{
		return $this->querySummary($request, $response);
	}

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

	public function getPartOfTown(Request $request, Response $response): Response
	{
		$districtId = (int)($request->getQueryParams()['district_id'] ?? 0);
		$partOfTownId = (int)($request->getQueryParams()['part_of_town_id'] ?? 0);
		$bocommon = createObject('property.bocommon');
		$values = $bocommon->select_part_of_town('filter', $partOfTownId, $districtId);
		array_unshift($values, array('id' => '', 'name' => lang('no part of town')));
		return $this->jsonResponse($response, $values);
	}

	public function getAccounts(Request $request, Response $response): Response
	{
		$accountType = (string)($request->getQueryParams()['account_type'] ?? '');
		if ($accountType === '')
		{
			$accountType = (string)($request->getParsedBody()['account_type'] ?? '');
		}
		$accountsObj = $this->accounts();

		switch ($accountType)
		{
			case 'accounts':
				$accounts = $accountsObj->get_list('accounts', -1, 'ASC', 'account_lastname', '', -1);
				break;
			case 'groups':
				$accounts = $accountsObj->get_list('groups', -1, 'ASC', 'account_firstname', '', -1);
				break;
			default:
				$accounts = array_merge(
					$accountsObj->get_list('groups', -1, 'ASC', 'account_firstname', '', -1),
					$accountsObj->get_list('accounts', -1, 'ASC', 'account_lastname', '', -1)
				);
				break;
		}

		$values = array();
		foreach ($accounts as $account)
		{
			$values[] = array(
				'id' => $account->id,
				'name' => $account->__toString(),
			);
		}
		if ($accountType === 'accounts')
		{
			array_unshift($values, array(
				'id' => (-1 * $this->currentAccountId()),
				'name' => lang('mine roles')
			));
		}
		array_unshift($values, array('id' => '', 'name' => lang('Select')));

		return $this->jsonResponse($response, $values);
	}

	public function getHistoryData(Request $request, Response $response): Response
	{
		$locationCode = (string)($request->getQueryParams()['location_code'] ?? '');
		$draw = (int)($request->getQueryParams()['draw'] ?? 0) + 1;
		$values = $this->bo()->get_history($locationCode);
		$dateFormat = $this->dateFormat();
		foreach ($values as &$entry)
		{
			$entry['entry_date'] = $this->phpgwapiCommon()->show_date($entry['entry_date'], $dateFormat);
		}
		unset($entry);
		return $this->jsonResponse($response, array(
			'data' => $values,
			'recordsTotal' => count($values),
			'recordsFiltered' => count($values),
			'draw' => $draw,
		));
	}

	public function getDocuments(Request $request, Response $response): Response
	{
		$queryParams = $request->getQueryParams();
		$bodyParams = $request->getParsedBody();
		$bodyParams = is_array($bodyParams) ? $bodyParams : array();
		$input = array_merge($queryParams, $bodyParams);

		$search = $input['search'] ?? array();
		$order = (array)($input['order'] ?? array());
		$draw = (int)($input['draw'] ?? 0) + 1;
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

		return $this->jsonResponse($response, array(
			'data' => $values,
			'recordsTotal' => $recordsTotal,
			'recordsFiltered' => $recordsTotal,
			'draw' => $draw,
		));
	}

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

	public function getDeliveryAddress(Request $request, Response $response): Response
	{
		$loc1 = (string)($request->getQueryParams()['loc1'] ?? '');
		return $this->jsonResponse($response, array(
			'delivery_address' => $this->bo()->get_delivery_address($loc1)
		));
	}

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

	public function getControlsAtComponent(Request $request, Response $response): Response
	{
		$locationId = (int)($request->getQueryParams()['location_id'] ?? 0);
		$id = (int)($request->getQueryParams()['id'] ?? 0);
		$skipJson = (bool)($request->getQueryParams()['skip_json'] ?? false);
		return $this->jsonResponse($response, $this->controllerHelper()->get_controls_at_component($locationId, $id, $skipJson));
	}

	public function getCases(Request $request, Response $response): Response
	{
		$locationId = (int)($request->getQueryParams()['location_id'] ?? 0);
		$id = (int)($request->getQueryParams()['id'] ?? 0);
		$year = (int)($request->getQueryParams()['year'] ?? 0);
		return $this->jsonResponse($response, $this->controllerHelper()->get_cases($locationId, $id, $year));
	}

	public function getChecklists(Request $request, Response $response): Response
	{
		$locationId = (int)($request->getQueryParams()['location_id'] ?? 0);
		$id = (int)($request->getQueryParams()['id'] ?? 0);
		$year = (int)($request->getQueryParams()['year'] ?? 0);
		return $this->jsonResponse($response, $this->controllerHelper()->get_checklists($locationId, $id, $year));
	}

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
		if ($locationId <= 0)
		{
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
		$queryParams = $request->getQueryParams();
		$bodyParams = $request->getParsedBody();
		$bodyParams = is_array($bodyParams) ? $bodyParams : array();
		$payload = array_merge($queryParams, $bodyParams);

		return $this->jsonResponse($response, $this->controllerHelper()->add_control($payload));
	}

	public function updateControlSerie(Request $request, Response $response): Response
	{
		$queryParams = $request->getQueryParams();
		$bodyParams = $request->getParsedBody();
		$bodyParams = is_array($bodyParams) ? $bodyParams : array();
		$payload = array_merge($queryParams, $bodyParams);

		return $this->jsonResponse($response, $this->controllerHelper()->update_control_serie($payload));
	}

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
	 * Export location data as a spreadsheet download.
	 *
	 * Replaces uilocation::download(). Calls BO methods directly (not query())
	 * so that bocommon->download() receives the expected flat row array.
	 *
	 * @param Request $request
	 * @param Response $response
	 * @param array $args
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
