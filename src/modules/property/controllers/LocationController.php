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
	private ?\phpgwapi_common $phpgwapiCommon = null;
	private $bo = null;

	public function __construct(ContainerInterface $container)
	{
		if (defined('SRC_ROOT_PATH') && !function_exists('include_class'))
		{
			require_once SRC_ROOT_PATH . '/helpers/LegacyObjectHandler.php';
		}
		// Initialize form helper for write operations
		$this->formHelper = new LocationFormHelper();
	}

	private function bo()
	{
		if ($this->bo === null)
		{
			$this->bo = CreateObject('property.bolocation', true);
		}

		return $this->bo;
	}

	private function phpgwapiCommon(): \phpgwapi_common
	{
		if ($this->phpgwapiCommon === null)
		{
			$this->phpgwapiCommon = new \phpgwapi_common();
		}

		return $this->phpgwapiCommon;
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

		$ui = $this->ui();
		$values = $request->getParsedBody();
		$values = is_array($values) ? $values : array();
		$assignOrig = \Sanitizer::get_var('assign_orig');
		$assign = \Sanitizer::get_var('assign');
		$roleId = \Sanitizer::get_var('role_id', 'int');
		$userId = \Sanitizer::get_var('user_id', 'int', 'request', $ui->account);

		$result = array('message' => array());
		if (($assign || $assignOrig) && $ui->acl_edit)
		{
			$userId = abs($userId);
			$account = $ui->accounts->get($userId);
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
		$this->hydrateRequestGlobals($request);
		$ui = $this->ui();
		$districtId = (int)($request->getQueryParams()['district_id'] ?? 0);
		$bocommon = createObject('property.bocommon');
		$values = $bocommon->select_part_of_town('filter', $ui->part_of_town_id, $districtId);
		array_unshift($values, array('id' => '', 'name' => lang('no part of town')));
		return $this->jsonResponse($response, $values);
	}

	public function getAccounts(Request $request, Response $response): Response
	{
		$this->hydrateRequestGlobals($request);

		$ui = $this->ui();
		$accountType = (string)($request->getQueryParams()['account_type'] ?? '');
		if ($accountType === '')
		{
			$accountType = (string)($request->getParsedBody()['account_type'] ?? '');
		}

		switch ($accountType)
		{
			case 'accounts':
				$accounts = $ui->accounts->get_list('accounts', -1, 'ASC', 'account_lastname', '', -1);
				break;
			case 'groups':
				$accounts = $ui->accounts->get_list('groups', -1, 'ASC', 'account_firstname', '', -1);
				break;
			default:
				$accounts = array_merge(
					$ui->accounts->get_list('groups', -1, 'ASC', 'account_firstname', '', -1),
					$ui->accounts->get_list('accounts', -1, 'ASC', 'account_lastname', '', -1)
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
				'id' => (-1 * $ui->userSettings['account_id']),
				'name' => lang('mine roles')
			));
		}
		array_unshift($values, array('id' => '', 'name' => lang('Select')));

		return $this->jsonResponse($response, $values);
	}

	public function getHistoryData(Request $request, Response $response): Response
	{
		$this->hydrateRequestGlobals($request);
		$ui = $this->ui();
		$locationCode = (string)($request->getQueryParams()['location_code'] ?? '');
		$draw = (int)($request->getQueryParams()['draw'] ?? 0);
		$values = $this->bo()->get_history($locationCode);
		$dateFormat = $ui->userSettings['preferences']['common']['dateformat'];
		foreach ($values as &$entry)
		{
			$entry['entry_date'] = $this->phpgwapiCommon()->show_date($entry['entry_date'], $dateFormat);
		}
		unset($entry);
		return $this->jsonResponse($response, array(
			'results' => $values,
			'total_records' => count($values),
			'draw' => $draw,
		));
	}

	public function getDocuments(Request $request, Response $response): Response
	{
		$this->hydrateRequestGlobals($request);

		$ui = $this->ui();
		$search = $request->getQueryParams()['search'] ?? array();
		$order = (array)($request->getQueryParams()['order'] ?? array());
		$draw = (int)($request->getQueryParams()['draw'] ?? 0);
		$columns = (array)($request->getQueryParams()['columns'] ?? array());
		$docType = (int)($request->getQueryParams()['doc_type'] ?? 0);
		$locationCode = (string)($request->getQueryParams()['location_code'] ?? '');
		$export = !empty($request->getQueryParams()['export']);

		$params = array(
			'start' => (int)($request->getQueryParams()['start'] ?? 0),
			'results' => (int)($request->getQueryParams()['length'] ?? 0),
			'query' => $search['value'] ?? '',
			'order' => $columns[$order[0]['column']]['data'] ?? '',
			'sort' => $order[0]['dir'] ?? 'ASC',
			'dir' => $order[0]['dir'] ?? 'ASC',
			'allrows' => ((int)($request->getQueryParams()['length'] ?? 0) == -1) || $export,
			'doc_type' => $docType,
			'location_code' => $locationCode,
		);

		$dateFormat = $ui->userSettings['preferences']['common']['dateformat'];
		$document = CreateObject('property.sodocument');
		$documents = $document->read_at_location($params);
		$totalRecords = $document->total_records;
		$values = array();
		foreach ($documents as $item)
		{
			if ($item['link'])
			{
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
			$documentName = '<a href="' . \phpgw::link('/index.php',array(
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

		$locations = new \App\modules\phpgwapi\controllers\Locations();
		$locationId = $locations->get_id('property', '.location.' . count(explode('-', $locationCode)));
		$genericDocument = CreateObject('property.sogeneric_document');
		$params['location_id'] = $locationId;
		$params['location_item_id'] = $this->bo()->get_item_id($locationCode);
		$params['order'] = 'name';
		$params['cat_id'] = $docType;
		$documents2 = $genericDocument->read($params);
		$totalRecords += $genericDocument->total_records;
		foreach ($documents2 as $item)
		{
			$title = '';
			if ($item['path'])
			{
				$temp = (array)json_decode($item['path']);
				$title = implode('<br/>', $temp);
			}
			$documentName = '<a href="' . \phpgw::link('/index.php',array(
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
			'results' => $values,
			'total_records' => $totalRecords,
			'draw' => $draw,
		));
	}

	public function getLocationData(Request $request, Response $response): Response
	{
		$this->hydrateRequestGlobals($request);
		$ui = $this->ui();
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
		$this->hydrateRequestGlobals($request);
		$ui = $this->ui();
		$loc1 = (string)($request->getQueryParams()['loc1'] ?? '');
		return $this->jsonResponse($response, array(
			'delivery_address' => $this->bo()->get_delivery_address($loc1)
		));
	}

	public function getLocationException(Request $request, Response $response): Response
	{
		$this->hydrateRequestGlobals($request);
		$ui = $this->ui();
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
		$this->hydrateRequestGlobals($request);
		$ui = $this->ui();
		$lookupTenant = (bool)($request->getQueryParams()['lookup_tenant'] ?? false);
		$userId = (int)($request->getQueryParams()['user_id'] ?? $ui->account);
		$roleId = (int)($request->getQueryParams()['role_id'] ?? 0);
		$search = $request->getQueryParams()['search'] ?? array();
		$order = (array)($request->getQueryParams()['order'] ?? array());
		$draw = (int)($request->getQueryParams()['draw'] ?? 0);
		$columns = (array)($request->getQueryParams()['columns'] ?? array());

		$params = array(
			'start' => (int)($request->getQueryParams()['start'] ?? 0),
			'results' => (int)($request->getQueryParams()['length'] ?? 0),
			'query' => $search['value'] ?? '',
			'order' => $columns[$order[0]['column']]['data'] ?? '',
			'sort' => $order[0]['dir'] ?? 'ASC',
			'dir' => $order[0]['dir'] ?? 'ASC',
			'allrows' => ((int)($request->getQueryParams()['length'] ?? 0) == -1),
			'lookup_tenant' => $lookupTenant,
			'user_id' => $userId,
			'role_id' => $roleId,
		);

		$values = $this->bo()->get_responsible($params);
		return $this->jsonResponse($response, array(
			'results' => $values,
			'total_records' => $this->bo()->total_records,
			'draw' => $draw,
		));
	}

	public function querySummary(Request $request, Response $response): Response
	{
		$this->hydrateRequestGlobals($request);
		$ui = $this->ui();
		$values = $this->bo()->read_summary();
		if (!empty($request->getQueryParams()['export']))
		{
			return $this->jsonResponse($response, $values);
		}
		return $this->jsonResponse($response, array(
			'results' => $values,
			'total_records' => count($values),
			'draw' => (int)($request->getQueryParams()['draw'] ?? 0),
		));
	}

	public function getControlsAtComponent(Request $request, Response $response): Response
	{
		$this->hydrateRequestGlobals($request);
		$ui = $this->ui();
		$locationId = (int)($request->getQueryParams()['location_id'] ?? 0);
		$id = (int)($request->getQueryParams()['id'] ?? 0);
		$skipJson = (bool)($request->getQueryParams()['skip_json'] ?? false);
		return $this->jsonResponse($response, $ui->controller_helper->get_controls_at_component($locationId, $id, $skipJson));
	}

	public function getCases(Request $request, Response $response): Response
	{
		$this->hydrateRequestGlobals($request);
		$ui = $this->ui();
		$locationId = (int)($request->getQueryParams()['location_id'] ?? 0);
		$id = (int)($request->getQueryParams()['id'] ?? 0);
		$year = (int)($request->getQueryParams()['year'] ?? 0);
		return $this->jsonResponse($response, $ui->controller_helper->get_cases($locationId, $id, $year));
	}

	public function getChecklists(Request $request, Response $response): Response
	{
		$this->hydrateRequestGlobals($request);
		$ui = $this->ui();
		$locationId = (int)($request->getQueryParams()['location_id'] ?? 0);
		$id = (int)($request->getQueryParams()['id'] ?? 0);
		$year = (int)($request->getQueryParams()['year'] ?? 0);
		return $this->jsonResponse($response, $ui->controller_helper->get_checklists($locationId, $id, $year));
	}

	public function getCasesForChecklist(Request $request, Response $response): Response
	{
		$this->hydrateRequestGlobals($request);
		return $this->jsonResponse($response, $this->ui()->controller_helper->get_cases_for_checklist());
	}

	public function editField(Request $request, Response $response): Response
	{
		$this->hydrateRequestGlobals($request);
		$ui = $this->ui();
		$typeId = (int)($request->getQueryParams()['type_id'] ?? 0);
		$id = (int)($request->getParsedBody()['id'] ?? 0);
		$fieldName = (string)($request->getQueryParams()['field_name'] ?? '');

		if (!$ui->acl_edit)
		{
			return $this->jsonResponse($response, 'ERROR');
		}
		if (!$ui->acl_manage && $fieldName !== 'contact_phone')
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
			'value' => \Sanitizer::get_var('value'),
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
