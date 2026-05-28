<?php

namespace App\modules\property\controllers;

use App\Database\Db;
use JsonException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\modules\phpgwapi\security\Acl;
use Slim\Exception\HttpForbiddenException;

class WorkorderController
{
	private $bo = null;

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

	protected function hasReadAccess(): bool
	{
		return (bool)Acl::getInstance()->check('.project', ACL_READ, 'property');
	}

	protected function hasEditAccess(): bool
	{
		return (bool)Acl::getInstance()->check('.project', ACL_EDIT, 'property');
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
						$tags[] = Db::getInstance()->stripslashes((string)$tag);
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
