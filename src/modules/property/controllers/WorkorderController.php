<?php

namespace App\modules\property\controllers;

use App\Database\Db;
use App\modules\property\helpers\WorkorderFormHelper;
use App\modules\phpgwapi\services\Settings;
use JsonException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\modules\phpgwapi\security\Acl;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpForbiddenException;

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

	public function getEcoService(Request $request, Response $response): Response
	{
		if (!$this->hasReadAccess())
		{
			throw new HttpForbiddenException($request, 'No read access to eco service lookup');
		}

		return $this->jsonResponse($response, (array)$this->bocommon()->get_eco_service());
	}

	public function getUnspscCode(Request $request, Response $response): Response
	{
		if (!$this->hasReadAccess())
		{
			throw new HttpForbiddenException($request, 'No read access to UNSPSC lookup');
		}

		return $this->jsonResponse($response, (array)$this->bocommon()->get_unspsc_code());
	}

	public function getEcodimb(Request $request, Response $response): Response
	{
		if (!$this->hasReadAccess())
		{
			throw new HttpForbiddenException($request, 'No read access to ecodimb lookup');
		}

		return $this->jsonResponse($response, (array)$this->bocommon()->get_ecodimb());
	}

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
		return $this->datatableResponse($response, $input, $rows);
	}

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

		$linkViewFile = \phpgw::link('/index.php', array(
			'menuaction' => 'property.uiworkorder.view_file',
		));

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
				$contentFiles[$lastIndex]['img_url'] = \phpgw::link('/index.php', array(
					'menuaction' => 'property.uiworkorder.view_image',
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

		$values = $this->bo()->read_single($id);
		$fileAttachments = isset($values['file_attachments']) && is_array($values['file_attachments']) ? $values['file_attachments'] : array();
		$contentAttachments = array();
		$imgTypes = array('image/jpeg', 'image/png', 'image/gif');
		$sortArray = array();

		$linkWorkorderFile = \phpgw::link('/index.php', array('menuaction' => 'property.uiworkorder.view_file'));
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
				$contentAttachments[$z]['img_url'] = \phpgw::link('/index.php', array(
					'menuaction' => 'property.uiworkorder.view_image',
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
				$contentAttachments[$z]['img_url'] = \phpgw::link('/index.php', array(
					'menuaction' => 'property.uiworkorder.view_image',
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
}
