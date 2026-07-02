<?php

namespace App\modules\todo\controllers;

use App\helpers\ResponseHelper;
use App\modules\phpgwapi\security\Acl;
use App\modules\phpgwapi\services\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class TodoController
{
	private function getCommonQueryParams(Request $request): array
	{
		$query = $request->getQueryParams();

		$start = isset($query['start']) ? (int) $query['start'] : 0;
		$limit = isset($query['limit']) ? (int) $query['limit'] : 100;
		if ($limit < 1)
		{
			$limit = 100;
		}
		if ($limit > 2000)
		{
			$limit = 2000;
		}

		$search = isset($query['search']) ? (string) $query['search'] : '';
		$filter = isset($query['filter']) ? (string) $query['filter'] : 'none';
		$catId = isset($query['cat_id']) ? (int) $query['cat_id'] : 0;
		$sort = $this->mapSortKey((string) ($query['sort'] ?? 'id'));
		$dir = strtoupper((string) ($query['dir'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';

		return [
			'start' => $start,
			'limit' => $limit,
			'search' => $search,
			'filter' => $filter,
			'cat_id' => $catId,
			'sort' => $sort,
			'dir' => $dir,
		];
	}

	private function formatPriority($priority): string
	{
		switch ((int) $priority)
		{
			case 1:
				return lang('Low');
			case 2:
				return lang('normal');
			case 3:
				return lang('high');
			default:
				return '';
		}
	}

	private function formatTodoTitle(array $todo): string
	{
		$title = \phpgw::strip_html((string) ($todo['title'] ?? ''));
		if (!$title)
		{
			$words = explode(' ', \phpgw::strip_html((string) ($todo['descr'] ?? '')));
			$title = trim(implode(' ', array_slice($words, 0, 4)) . ' ...');
		}

		if ((int) ($todo['level'] ?? 0) <= 0)
		{
			return $title;
		}

		return str_repeat('  ', (int) $todo['level']) . $title;
	}

	private function mapTodoDetail(array $item, $botodo): array
	{
		$userSettings = Settings::getInstance()->get('user');
		$dateFormat = (string) ($userSettings['preferences']['common']['dateformat'] ?? 'Y-m-d');

		$ownerId = (int) ($item['owner'] ?? 0);
		$ownerName = (string) ($item['owner'] ?? '');
		$cached = $botodo->cached_accounts($ownerId);
		if (is_object($cached) && isset($cached->lid, $cached->firstname, $cached->lastname))
		{
			$phpgwapiCommon = new \phpgwapi_common();
			$ownerName = (string) $phpgwapiCommon->display_fullname(
				(string) $cached->lid,
				(string) $cached->firstname,
				(string) $cached->lastname
			);
		}

		$assigned = $botodo->list_assigned($botodo->format_assigned((string) ($item['assigned'] ?? '')));
		$assigned .= $botodo->list_assigned($botodo->format_assigned((string) ($item['assigned_group'] ?? '')));
		$phpgwapiCommon = new \phpgwapi_common();

		$categoryName = '';
		if (!empty($item['cat']))
		{
			$cats = \CreateObject('phpgwapi.categories');
			$categoryName = (string) $cats->id2name((int) $item['cat']);
		}

		$parentTitle = '';
		$parentId = (int) ($item['parent'] ?? 0);
		if ($parentId > 0)
		{
			$parent = $botodo->read($parentId);
			if (is_array($parent))
			{
				$parentTitle = (string) \phpgw::strip_html((string) ($parent['title'] ?? ''));
			}
		}

		$tzOffset = (int) ($botodo->datetime->tz_offset ?? 0);
		$sdateTs = (int) ($item['sdate'] ?? 0);
		$edateTs = (int) ($item['edate'] ?? 0);

		$startDate = '';
		if ($sdateTs > 0)
		{
			$startDate = (string) $phpgwapiCommon->show_date($sdateTs - $tzOffset, $dateFormat);
		}

		$endDate = '';
		if ($edateTs > 0)
		{
			$endDate = (string) $phpgwapiCommon->show_date($edateTs - $tzOffset, $dateFormat);
		}

		$priority = $this->formatPriority($item['pri'] ?? 0);

		return [
			'id' => (int) ($item['id'] ?? 0),
			'title' => (string) \phpgw::strip_html((string) ($item['title'] ?? '')),
			'descr' => (string) \phpgw::strip_html((string) ($item['descr'] ?? '')),
			'category' => $categoryName,
			'parent' => $parentTitle,
			'status' => (int) ($item['status'] ?? 0),
			'pri' => $priority,
			'access' => (string) ($item['access'] ?? ''),
			'owner' => $ownerName,
			'assigned' => (string) $assigned,
			'sdate' => $startDate,
			'edate' => $endDate,
			'has_subs' => (bool) $botodo->exists((int) ($item['id'] ?? 0)),
		];
	}

	private function mapTodoItems(array $todoList, $botodo, array $grants, int $catId): array
	{
		$userSettings = Settings::getInstance()->get('user');
		$currentAccountId = (int) ($userSettings['account_id'] ?? 0);

		$rows = [];
		foreach ($todoList as $todo)
		{
			$id = (int) ($todo['id'] ?? 0);
			$ownerId = (int) ($todo['owner_id'] ?? 0);
			$canEdit = $botodo->check_perms($ownerId, $grants, ACL_EDIT) || $ownerId === $currentAccountId;
			$canDelete = $botodo->check_perms($ownerId, $grants, ACL_DELETE) || $ownerId === $currentAccountId;
			$canAdd = $botodo->check_perms($ownerId, $grants, ACL_ADD);

			$assigned = $botodo->list_assigned($todo['assigned'] ?? '');
			$assigned .= $botodo->list_assigned($todo['assigned_group'] ?? '');

			$rows[] = [
				'id' => $id,
				'title' => $this->formatTodoTitle((array) $todo),
				'status' => (string) ($todo['status'] ?? ''),
				'pri' => $this->formatPriority($todo['pri'] ?? 0),
				'sdate' => (string) ($todo['sdate'] ?? ''),
				'edate' => (string) ($todo['edate'] ?? ''),
				'owner' => (string) ($todo['owner'] ?? ''),
				'assigned' => (string) $assigned,
				'actions' => [
					'view' => \phpgw::link('/todo/view/todos/' . $id),
						'edit' => $canEdit ? \phpgw::link('/todo/view/todos/' . $id . '/edit') : '',
					'delete' => $canDelete ? \phpgw::link('/todo/view/todos/' . $id . '/delete') : '',
					'subadd' => $canAdd ? \phpgw::link('/todo/view/todos/add', ['parent' => $id, 'cat_id' => $catId]) : '',
				],
			];
		}

		return $rows;
	}

	private function getTodoGrants(): array
	{
		try
		{
			$grants = Acl::getInstance()->get_grants('todo', '.');
			return is_array($grants) ? $grants : [];
		}
		catch (\Throwable $e)
		{
			return [];
		}
	}

	private function readPayload(Request $request): array
	{
		$data = $request->getParsedBody();
		if (!is_array($data))
		{
			$raw = (string) $request->getBody();
			if ($raw !== '')
			{
				$decoded = json_decode($raw, true);
				if (is_array($decoded))
				{
					$data = $decoded;
				}
			}
		}

		$data = is_array($data) ? $data : [];

		if (isset($data['assigned']) && is_array($data['assigned']))
		{
			$data['assigned'] = implode(',', array_filter($data['assigned'], static function ($value)
			{
				return $value !== '' && $value !== null;
			}));
		}

		if (isset($data['assigned_group']) && is_array($data['assigned_group']))
		{
			$data['assigned_group'] = implode(',', array_filter($data['assigned_group'], static function ($value)
			{
				return $value !== '' && $value !== null;
			}));
		}

		return $data;
	}

	private function mapSortKey(string $key): string
	{
		$map = [
			'id' => 'todo_id',
			'title' => 'todo_title',
			'status' => 'todo_status',
			'priority' => 'todo_pri',
			'created' => 'todo_startdate',
			'due' => 'todo_enddate',
			'owner' => 'todo_owner',
		];

		return $map[$key] ?? 'todo_id';
	}

	/**
	 * GET /todo/todos
	 */
	public function index(Request $request, Response $response): Response
	{
		$params = $this->getCommonQueryParams($request);

		$botodo = \CreateObject('todo.botodo', true);
		$grants = $this->getTodoGrants();
		$todoList = $botodo->_list(
			$params['start'],
			$params['limit'],
			$params['search'],
			$params['filter'],
			$params['sort'],
			$params['dir'],
			$params['cat_id'],
			'all'
		);
		$items = $this->mapTodoItems(is_array($todoList) ? $todoList : [], $botodo, $grants, (int) $params['cat_id']);

		return ResponseHelper::sendJSONResponse([
			'total' => (int) $botodo->total_records,
			'items' => $items,
		]);
	}

	/**
	 * GET /todo/categories
	 */
	public function categories(Request $request, Response $response): Response
	{
		$cats = \CreateObject('phpgwapi.categories', -1, 'todo', '.task');
		$categories = $cats->return_sorted_array(0, false, '', '', '', true, 0, false);

		$list = [
			['id' => 0, 'name' => lang('All')],
		];

		foreach ((array) $categories as $category)
		{
			$list[] = [
				'id' => (int) ($category['id'] ?? 0),
				'name' => (string) ($category['name'] ?? ''),
			];
		}

		return ResponseHelper::sendJSONResponse(['items' => $list]);
	}

	/**
	 * GET /todo/todos/export/csv
	 */
	public function exportCsv(Request $request, Response $response): Response
	{
		$params = $this->getCommonQueryParams($request);
		$params['start'] = 0;
		$params['limit'] = 2000;

		$botodo = \CreateObject('todo.botodo', true);
		$grants = $this->getTodoGrants();
		$todoList = $botodo->_list(
			$params['start'],
			$params['limit'],
			$params['search'],
			$params['filter'],
			$params['sort'],
			$params['dir'],
			$params['cat_id'],
			'all'
		);
		$items = $this->mapTodoItems(is_array($todoList) ? $todoList : [], $botodo, $grants, (int) $params['cat_id']);

		$fp = fopen('php://temp', 'r+');
		fputcsv($fp, ['ID', 'Title', 'Status', 'Urgency', 'Start date', 'End date', 'Created by', 'Assigned to']);
		foreach ($items as $item)
		{
			fputcsv($fp, [
				$item['id'],
				$item['title'],
				$item['status'],
				$item['pri'],
				$item['sdate'],
				$item['edate'],
				$item['owner'],
				$item['assigned'],
			]);
		}
		rewind($fp);
		$csv = (string) stream_get_contents($fp);
		fclose($fp);

		$response->getBody()->write($csv);
		return $response
			->withHeader('Content-Type', 'text/csv; charset=utf-8')
			->withHeader('Content-Disposition', 'attachment; filename="todo-list.csv"');
	}

	/**
	 * GET /todo/todos/{id}
	 */
	public function show(Request $request, Response $response, array $args): Response
	{
		$id = (int) ($args['id'] ?? 0);
		if (!$id) {
			return ResponseHelper::sendErrorResponse(['error' => 'Missing todo ID'], 400);
		}

		$botodo = \CreateObject('todo.botodo', true);
		$item = $botodo->read($id);

		if (!$item) {
			return ResponseHelper::sendErrorResponse(['error' => 'Todo not found'], 404);
		}

		return ResponseHelper::sendJSONResponse([
			'item' => $item,
			'detail' => $this->mapTodoDetail((array) $item, $botodo),
		]);
	}

	/**
	 * POST /todo/todos
	 */
	public function store(Request $request, Response $response): Response
	{
		$values = $this->readPayload($request);
		$botodo = \CreateObject('todo.botodo', true);

		$error = $botodo->check_values($values);
		if (is_array($error) && count($error)) {
			return ResponseHelper::sendErrorResponse(['error' => implode('; ', $error)], 400);
		}

		$newId = $botodo->save($values);
		if (!$newId) {
			return ResponseHelper::sendErrorResponse(['error' => 'Failed to create todo'], 500);
		}

		return ResponseHelper::sendJSONResponse(['id' => (int) $newId], 201);
	}

	/**
	 * PUT /todo/todos/{id}
	 */
	public function update(Request $request, Response $response, array $args): Response
	{
		$id = (int) ($args['id'] ?? 0);
		if (!$id) {
			return ResponseHelper::sendErrorResponse(['error' => 'Missing todo ID'], 400);
		}

		$values = $this->readPayload($request);
		$values['id'] = $id;

		$botodo = \CreateObject('todo.botodo', true);
		$error = $botodo->check_values($values);
		if (is_array($error) && count($error)) {
			return ResponseHelper::sendErrorResponse(['error' => implode('; ', $error)], 400);
		}

		$ok = $botodo->save($values, 'edit');
		if (!$ok) {
			return ResponseHelper::sendErrorResponse(['error' => 'Failed to update todo'], 500);
		}

		return ResponseHelper::sendJSONResponse(['id' => $id]);
	}

	/**
	 * DELETE /todo/todos/{id}
	 */
	public function destroy(Request $request, Response $response, array $args): Response
	{
		$id = (int) ($args['id'] ?? 0);
		if (!$id) {
			return ResponseHelper::sendErrorResponse(['error' => 'Missing todo ID'], 400);
		}

		$query = $request->getQueryParams();
		$subsRaw = $query['subs'] ?? '0';
		$deleteSubs = in_array((string) $subsRaw, ['1', 'true', 'on', 'yes'], true);

		$botodo = \CreateObject('todo.botodo', true);
		$botodo->delete($id, $deleteSubs);

		return ResponseHelper::sendJSONResponse(['deleted' => true, 'subs' => $deleteSubs]);
	}
}
