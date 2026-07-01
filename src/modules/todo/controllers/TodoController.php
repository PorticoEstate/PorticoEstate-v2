<?php

namespace App\modules\todo\controllers;

use App\helpers\ResponseHelper;
use App\modules\phpgwapi\security\Acl;
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

	private function mapTodoItems(array $todoList, $botodo, array $grants, int $catId): array
	{
		$rows = [];
		foreach ($todoList as $todo)
		{
			$id = (int) ($todo['id'] ?? 0);
			$ownerId = (int) ($todo['owner_id'] ?? 0);
			$canEdit = $botodo->check_perms($ownerId, $grants, ACL_EDIT);
			$canDelete = $botodo->check_perms($ownerId, $grants, ACL_DELETE);
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
					'view' => \phpgw::link('/index.php', ['menuaction' => 'todo.uitodo.view', 'todo_id' => $id]),
					'edit' => $canEdit ? \phpgw::link('/index.php', ['menuaction' => 'todo.uitodo.edit', 'todo_id' => $id]) : '',
					'delete' => $canDelete ? \phpgw::link('/index.php', ['menuaction' => 'todo.uitodo.delete', 'todo_id' => $id]) : '',
					'subadd' => $canAdd ? \phpgw::link('/index.php', ['menuaction' => 'todo.uitodo.add', 'parent' => $id, 'cat_id' => $catId]) : '',
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
		return is_array($data) ? $data : [];
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

		return ResponseHelper::sendJSONResponse(['item' => $item]);
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

		$botodo = \CreateObject('todo.botodo', true);
		$botodo->delete($id);

		return ResponseHelper::sendJSONResponse(['deleted' => true]);
	}
}
