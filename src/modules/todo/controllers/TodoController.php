<?php

namespace App\modules\todo\controllers;

use App\helpers\ResponseHelper;
use App\modules\property\helpers\BoCommon;
use App\modules\phpgwapi\security\Acl;
use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\controllers\Accounts\Accounts;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * @OA\Tag(
 *     name="Todo",
 *     description="REST API for todo items"
 * )
 *
 * @OA\Schema(
 *     schema="TodoItem",
 *     type="object",
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="cat_id", type="integer"),
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="level", type="integer"),
 *     @OA\Property(property="status", type="integer", minimum=0, maximum=100),
 *     @OA\Property(property="pri", type="string"),
 *     @OA\Property(property="sdate", type="string"),
 *     @OA\Property(property="edate", type="string"),
 *     @OA\Property(property="owner", type="string"),
 *     @OA\Property(
 *         property="assigned_entries",
 *         type="array",
 *         @OA\Items(
 *             type="object",
 *             @OA\Property(property="id", type="integer"),
 *             @OA\Property(property="type", type="string", enum={"user", "group"}),
 *             @OA\Property(property="name", type="string")
 *         )
 *     )
 * )
 *
 * @OA\Schema(
 *     schema="TodoDetail",
 *     type="object",
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="descr", type="string"),
 *     @OA\Property(property="category", type="string"),
 *     @OA\Property(property="parent", type="string"),
 *     @OA\Property(property="status", type="integer", minimum=0, maximum=100),
 *     @OA\Property(property="pri", type="string"),
 *     @OA\Property(property="access", type="string"),
 *     @OA\Property(property="owner", type="string"),
 *     @OA\Property(
 *         property="assigned_entries",
 *         type="array",
 *         @OA\Items(
 *             type="object",
 *             @OA\Property(property="id", type="integer"),
 *             @OA\Property(property="type", type="string", enum={"user", "group"}),
 *             @OA\Property(property="name", type="string")
 *         )
 *     ),
 *     @OA\Property(property="sdate", type="string"),
 *     @OA\Property(property="edate", type="string"),
 *     @OA\Property(property="has_subs", type="boolean")
 * )
 *
 * @OA\Schema(
 *     schema="TodoCategory",
 *     type="object",
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="name", type="string")
 * )
 *
 * @OA\Schema(
 *     schema="TodoUpsertRequest",
 *     type="object",
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="descr", type="string"),
 *     @OA\Property(property="cat", type="integer"),
 *     @OA\Property(property="parent", type="integer"),
 *     @OA\Property(property="pri", type="integer"),
 *     @OA\Property(property="status", type="integer", minimum=0, maximum=100),
 *     @OA\Property(property="access", type="boolean"),
 *     @OA\Property(property="assigned", oneOf={@OA\Schema(type="string"), @OA\Schema(type="array", @OA\Items(type="string"))}),
 *     @OA\Property(property="assigned_group", oneOf={@OA\Schema(type="string"), @OA\Schema(type="array", @OA\Items(type="string"))}),
 *     @OA\Property(property="sday", type="integer"),
 *     @OA\Property(property="smonth", type="integer"),
 *     @OA\Property(property="syear", type="integer"),
 *     @OA\Property(property="eday", type="integer"),
 *     @OA\Property(property="emonth", type="integer"),
 *     @OA\Property(property="eyear", type="integer")
 * )
 *
 * @OA\Schema(
 *     schema="TodoErrorResponse",
 *     type="object",
 *     @OA\Property(property="error", type="string"),
 *     @OA\Property(property="reason", type="string"),
 *     @OA\Property(property="todo_id", type="integer"),
 *     @OA\Property(property="parent_id", type="integer")
 * )
 */
class TodoController
{
	private function mapDataTableColumnToSortKey(string $columnKey): string
	{
		$map = [
			'id' => 'id',
			'title' => 'title',
			'status' => 'status',
			'pri' => 'priority',
			'sdate' => 'created',
			'edate' => 'due',
			'owner' => 'owner',
		];

		return $map[$columnKey] ?? 'id';
	}

	private function isCircularParentAssignment($botodo, int $todoId, int $parentId): bool
	{
		if ($parentId <= 0)
		{
			return false;
		}

		if ($parentId === $todoId)
		{
			return true;
		}

		$descendants = (string) $botodo->sotodo->find_subs((string) $todoId);
		if ($descendants === '')
		{
			return false;
		}

		$descendantIds = array_filter(array_map('intval', explode(',', $descendants)));
		return in_array($parentId, $descendantIds, true);
	}

	private function getTodoHistoryData(int $id): array
	{
		$historylog = \CreateObject('phpgwapi.historylog', 'todo', '.todo');
		$userSettings = Settings::getInstance()->get('user');
		$dateFormat = (string) ($userSettings['preferences']['common']['dateformat'] ?? 'Y-m-d');
		$dateTimeFormat = trim($dateFormat . ' H:i');
		$phpgwapiCommon = new \phpgwapi_common();
		$statusLabels = array(
			'A' => lang('Entry added'),
			'C' => lang('Category changed'),
			'S' => lang('Start date changed'),
			'E' => lang('End date changed'),
			'U' => lang('Urgency changed'),
			's' => lang('Status changed'),
			'T' => lang('Title changed'),
			'D' => lang('Description changed'),
			'a' => lang('Access changed'),
			'P' => lang('Parent changed'),
		);

		$rows = (array) $historylog->return_array(array(), array(), '', '', $id);
		$normalized = array();
		foreach ($rows as $row)
		{
			$statusCode = (string) ($row['status'] ?? '');
			$rawDatetime = $row['datetime'] ?? '';
			$formattedDatetime = (string) $rawDatetime;
			if (is_numeric($rawDatetime))
			{
				$timestamp = (int) $rawDatetime;
				if ($timestamp > 9999999999)
				{
					$timestamp = (int) floor($timestamp / 1000);
				}

				if ($timestamp > 0)
				{
					$formattedDatetime = (string) $phpgwapiCommon->show_date($timestamp, $dateTimeFormat);
				}
			}

			$normalized[] = [
				'id' => (int) ($row['id'] ?? 0),
				'record_id' => (int) ($row['record_id'] ?? 0),
				'owner' => (string) ($row['owner'] ?? ''),
				'status' => $statusCode,
				'status_label' => (string) ($statusLabels[$statusCode] ?? $statusCode),
				'new_value' => (string) ($row['new_value'] ?? ''),
				'old_value' => (string) ($row['old_value'] ?? ''),
				'datetime' => $formattedDatetime,
				'publish' => $row['publish'] ?? null,
			];
		}

		return $normalized;
	}

	private function normalizeUrl(string $url): string
	{
		return html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}

	private function getCommonQueryParams(Request $request): array
	{
		$query = $request->getQueryParams();
		$body = (array) ($request->getParsedBody() ?: []);
		$params = array_merge($query, $body);

		$draw = isset($params['draw']) ? (int) $params['draw'] : 0;
		$start = isset($params['start']) ? (int) $params['start'] : 0;
		$limit = isset($params['limit']) ? (int) $params['limit'] : 100;
		if (isset($params['length']))
		{
			$limit = (int) $params['length'];
		}
		if ($limit < 1)
		{
			$limit = 100;
		}
		if ($limit > 2000)
		{
			$limit = 2000;
		}

		$search = '';
		if (isset($params['search']) && is_array($params['search']))
		{
			$search = (string) ($params['search']['value'] ?? '');
		}
		else if (isset($params['search']))
		{
			$search = (string) $params['search'];
		}

		$filter = isset($params['filter']) ? (string) $params['filter'] : 'none';
		$catId = isset($params['cat_id']) ? (int) $params['cat_id'] : 0;

		$sortKey = (string) ($params['sort'] ?? 'id');
		$dir = strtoupper((string) ($params['dir'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';

		if (empty($params['sort']) && isset($params['order'][0]) && is_array($params['order'][0]))
		{
			$dtOrder = $params['order'][0];
			$columnIndex = isset($dtOrder['column']) ? (int) $dtOrder['column'] : -1;
			if ($columnIndex >= 0 && isset($params['columns'][$columnIndex]['data']))
			{
				$sortKey = $this->mapDataTableColumnToSortKey((string) $params['columns'][$columnIndex]['data']);
			}
			$dir = strtoupper((string) ($dtOrder['dir'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
		}

		$sort = $this->mapSortKey($sortKey);

		return [
			'draw' => $draw,
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

		return $title;
	}

	private function normalizeAssignedIds($botodo, $raw): array
	{
		if (is_array($raw))
		{
			$values = $raw;
		}
		else
		{
			$values = $botodo->format_assigned((string) $raw);
		}

		$ids = [];
		foreach ((array) $values as $value)
		{
			$id = (int) $value;
			if ($id > 0)
			{
				$ids[] = $id;
			}
		}

		return array_values(array_unique($ids));
	}

	private function resolveAssignedName($botodo, int $accountId): string
	{
		$cached = $botodo->cached_accounts($accountId);
		if (is_object($cached) && isset($cached->lid, $cached->firstname, $cached->lastname))
		{
			$phpgwapiCommon = new \phpgwapi_common();
			return (string) $phpgwapiCommon->display_fullname(
				(string) $cached->lid,
				(string) $cached->firstname,
				(string) $cached->lastname
			);
		}

		$accountsObj = new Accounts();
		return (string) $accountsObj->id2name($accountId);
	}

	private function mapAssignedEntries($botodo, array $item): array
	{
		$entries = [];
		$seen = [];

		$userIds = $this->normalizeAssignedIds($botodo, $item['assigned'] ?? '');
		foreach ($userIds as $userId)
		{
			$key = 'user:' . $userId;
			if (isset($seen[$key]))
			{
				continue;
			}
			$seen[$key] = true;

			$entries[] = [
				'id' => $userId,
				'type' => 'user',
				'name' => $this->resolveAssignedName($botodo, $userId),
			];
		}

		$groupIds = $this->normalizeAssignedIds($botodo, $item['assigned_group'] ?? '');
		foreach ($groupIds as $groupId)
		{
			$key = 'group:' . $groupId;
			if (isset($seen[$key]))
			{
				continue;
			}
			$seen[$key] = true;

			$entries[] = [
				'id' => $groupId,
				'type' => 'group',
				'name' => $this->resolveAssignedName($botodo, $groupId),
			];
		}

		return $entries;
	}

	private function assignedEntriesToText(array $entries): string
	{
		$lines = array_values(array_filter(array_map(static function ($entry)
		{
			return (string) ($entry['name'] ?? '');
		}, $entries)));

		return implode("\n", $lines);
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

		$assignedEntries = $this->mapAssignedEntries($botodo, (array) $item);
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
			'assigned_entries' => $assignedEntries,
			'sdate' => $startDate,
			'edate' => $endDate,
			'has_subs' => (bool) $botodo->exists((int) ($item['id'] ?? 0)),
		];
	}

	private function mapTodoItems(array $todoList, $botodo, array $grants, int $catId): array
	{
		$userSettings = Settings::getInstance()->get('user');
		$currentAccountId = (int) ($userSettings['account_id'] ?? 0);
		$accountsObj = new Accounts();

		$rows = [];
		foreach ($todoList as $todo)
		{
			$id = (int) ($todo['id'] ?? 0);
			$ownerId = (int) ($todo['owner_id'] ?? 0);
			$canEdit = $botodo->check_perms($ownerId, $grants, ACL_EDIT) || $ownerId === $currentAccountId;
			$canDelete = $botodo->check_perms($ownerId, $grants, ACL_DELETE) || $ownerId === $currentAccountId;

			$assignedEntries = $this->mapAssignedEntries($botodo, (array) $todo);

			$rows[] = [
				'id' => $id,
				'cat_id' => (int) ($todo['cat'] ?? $catId),
				'title' => $this->formatTodoTitle((array) $todo),
				'level' => max(0, (int) ($todo['level'] ?? 0)),
				'status' => (string) ($todo['status'] ?? ''),
				'pri' => $this->formatPriority($todo['pri'] ?? 0),
				'sdate' => (string) ($todo['sdate'] ?? ''),
				'edate' => (string) ($todo['edate'] ?? ''),
				'owner' => (string) ($todo['owner'] ? $accountsObj->id2name((int) $todo['owner']) : ''),
				'assigned_entries' => $assignedEntries,
				'actions' => [
					'view' => $this->normalizeUrl(\phpgw::link('/todo/view/todos/' . $id)),
					'edit' => $canEdit ? $this->normalizeUrl(\phpgw::link('/todo/view/todos/' . $id . '/edit')) : '',
					'delete' => $canDelete ? $this->normalizeUrl(\phpgw::link('/todo/view/todos/' . $id . '/delete')) : '',
					'subadd' => $this->normalizeUrl(\phpgw::link('/todo/view/todos/add', [
						'parent' => $id,
						'cat_id' => (int) ($todo['cat'] ?? $catId),
					])),
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
			'start_date' => 'todo_startdate',
			'due' => 'todo_enddate',
			'end_date' => 'todo_enddate',
			'owner' => 'todo_owner',
		];

		return $map[$key] ?? 'todo_id';
	}

	/**
	 * GET /todo/todos
	 *
	 * @OA\Get(
	 *     path="/todo/todos",
	 *     summary="List todo items",
	 *     tags={"Todo"},
	 *     @OA\Parameter(name="start", in="query", @OA\Schema(type="integer", default=0)),
	 *     @OA\Parameter(name="limit", in="query", @OA\Schema(type="integer", default=100)),
	 *     @OA\Parameter(name="search", in="query", @OA\Schema(type="string")),
	 *     @OA\Parameter(name="filter", in="query", @OA\Schema(type="string", default="none")),
	 *     @OA\Parameter(name="cat_id", in="query", @OA\Schema(type="integer", default=0)),
	 *     @OA\Parameter(name="sort", in="query", @OA\Schema(type="string")),
	 *     @OA\Parameter(name="dir", in="query", @OA\Schema(type="string", enum={"ASC", "DESC"}, default="ASC")),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Todo list",
	 *         @OA\JsonContent(
	 *             type="object",
	 *             @OA\Property(property="total", type="integer"),
	 *             @OA\Property(property="items", type="array", @OA\Items(ref="#/components/schemas/TodoItem"))
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=401,
	 *         description="Unauthorized",
	 *         @OA\JsonContent(ref="#/components/schemas/TodoErrorResponse")
	 *     ),
	 *     @OA\Response(
	 *         response=500,
	 *         description="Internal server error",
	 *         @OA\JsonContent(ref="#/components/schemas/TodoErrorResponse")
	 *     )
	 * )
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

		if ((int) ($params['draw'] ?? 0) > 0)
		{
			return ResponseHelper::sendJSONResponse([
				'draw' => (int) $params['draw'],
				'recordsTotal' => (int) $botodo->total_records,
				'recordsFiltered' => (int) $botodo->total_records,
				'data' => $items,
			]);
		}

		return ResponseHelper::sendJSONResponse([
			'total' => (int) $botodo->total_records,
			'items' => $items,
		]);
	}

	/**
	 * GET /todo/categories
	 *
	 * @OA\Get(
	 *     path="/todo/categories",
	 *     summary="List todo categories",
	 *     tags={"Todo"},
	 *     @OA\Response(
	 *         response=200,
	 *         description="Category list",
	 *         @OA\JsonContent(
	 *             type="object",
	 *             @OA\Property(property="items", type="array", @OA\Items(ref="#/components/schemas/TodoCategory"))
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=401,
	 *         description="Unauthorized",
	 *         @OA\JsonContent(ref="#/components/schemas/TodoErrorResponse")
	 *     ),
	 *     @OA\Response(
	 *         response=500,
	 *         description="Internal server error",
	 *         @OA\JsonContent(ref="#/components/schemas/TodoErrorResponse")
	 *     )
	 * )
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
	 *
	 * @OA\Get(
	 *     path="/todo/todos/export/csv",
	 *     summary="Export todo list as CSV",
	 *     tags={"Todo"},
	 *     @OA\Response(response=200, description="CSV file"),
	 *     @OA\Response(
	 *         response=401,
	 *         description="Unauthorized",
	 *         @OA\JsonContent(ref="#/components/schemas/TodoErrorResponse")
	 *     ),
	 *     @OA\Response(
	 *         response=500,
	 *         description="Internal server error",
	 *         @OA\JsonContent(ref="#/components/schemas/TodoErrorResponse")
	 *     )
	 * )
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

		$list = [];
		foreach ($items as $item)
		{
			$list[] = [
				'id' => $item['id'],
				'title' => $item['title'],
				'status' => $item['status'],
				'pri' => $item['pri'],
				'sdate' => $item['sdate'],
				'edate' => $item['edate'],
				'owner' => $item['owner'],
				'assigned' => $this->assignedEntriesToText((array) ($item['assigned_entries'] ?? [])),
			];
		}

		$boCommon = new BoCommon();
		$boCommon->userSettings['preferences']['common']['export_format'] = 'csv';
		$boCommon->performDownload(
			$list,
			['id', 'title', 'status', 'pri', 'sdate', 'edate', 'owner', 'assigned'],
			['ID', 'Title', 'Status', 'Urgency', 'Start date', 'End date', 'Created by', 'Assigned to'],
			['text', 'text', 'text', 'text', 'text', 'text', 'text', 'text'],
			[],
			'todo-list.csv'
		);

		return $response;
	}

	/**
	 * GET /todo/todos/{id}
	 *
	 * @OA\Get(
	 *     path="/todo/todos/{id}",
	 *     summary="Get single todo",
	 *     tags={"Todo"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Todo item",
	 *         @OA\JsonContent(
	 *             type="object",
	 *             @OA\Property(property="item", type="object"),
	 *             @OA\Property(property="detail", ref="#/components/schemas/TodoDetail"),
	 *             @OA\Property(
	 *                 property="history",
	 *                 type="array",
	 *                 @OA\Items(
	 *                     type="object",
	 *                     @OA\Property(property="id", type="integer"),
	 *                     @OA\Property(property="record_id", type="integer"),
	 *                     @OA\Property(property="owner", type="string"),
	 *                     @OA\Property(property="status", type="string"),
	 *                     @OA\Property(property="status_label", type="string"),
	 *                     @OA\Property(property="new_value", type="string"),
	 *                     @OA\Property(property="old_value", type="string"),
	 *                     @OA\Property(property="datetime", type="string")
	 *                 )
	 *             )
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=404,
	 *         description="Todo not found",
	 *         @OA\JsonContent(ref="#/components/schemas/TodoErrorResponse")
	 *     ),
	 *     @OA\Response(
	 *         response=401,
	 *         description="Unauthorized",
	 *         @OA\JsonContent(ref="#/components/schemas/TodoErrorResponse")
	 *     ),
	 *     @OA\Response(
	 *         response=500,
	 *         description="Internal server error",
	 *         @OA\JsonContent(ref="#/components/schemas/TodoErrorResponse")
	 *     )
	 * )
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
			'history' => $this->getTodoHistoryData($id),
		]);
	}

	/**
	 * POST /todo/todos
	 *
	 * @OA\Post(
	 *     path="/todo/todos",
	 *     summary="Create todo",
	 *     tags={"Todo"},
	 *     @OA\RequestBody(
	 *         required=true,
	 *         @OA\JsonContent(ref="#/components/schemas/TodoUpsertRequest")
	 *     ),
	 *     @OA\Response(
	 *         response=201,
	 *         description="Created",
	 *         @OA\JsonContent(type="object", @OA\Property(property="id", type="integer"))
	 *     ),
	 *     @OA\Response(
	 *         response=400,
	 *         description="Validation error",
	 *         @OA\JsonContent(ref="#/components/schemas/TodoErrorResponse")
	 *     ),
	 *     @OA\Response(
	 *         response=401,
	 *         description="Unauthorized",
	 *         @OA\JsonContent(ref="#/components/schemas/TodoErrorResponse")
	 *     ),
	 *     @OA\Response(
	 *         response=500,
	 *         description="Internal server error",
	 *         @OA\JsonContent(ref="#/components/schemas/TodoErrorResponse")
	 *     )
	 * )
	 */
	public function store(Request $request, Response $response): Response
	{
		$query = $request->getQueryParams();
		$parsedBody = $request->getParsedBody();
		$parsedBody = is_array($parsedBody) ? $parsedBody : [];

		// DataTables server-side requests use POST to this endpoint in datatable2.
		// Route those requests to the list handler instead of create.
		if (
			isset($parsedBody['draw'])
			|| isset($parsedBody['columns'])
			|| isset($parsedBody['order'])
			|| isset($query['draw'])
			|| isset($query['columns'])
			|| isset($query['order'])
		)
		{
			return $this->index($request, $response);
		}

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
	 *
	 * @OA\Put(
	 *     path="/todo/todos/{id}",
	 *     summary="Update todo",
	 *     tags={"Todo"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\RequestBody(
	 *         required=true,
	 *         @OA\JsonContent(ref="#/components/schemas/TodoUpsertRequest")
	 *     ),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Updated",
	 *         @OA\JsonContent(type="object", @OA\Property(property="id", type="integer"))
	 *     ),
	 *     @OA\Response(
	 *         response=400,
	 *         description="Invalid request",
	 *         @OA\JsonContent(ref="#/components/schemas/TodoErrorResponse")
	 *     ),
	 *     @OA\Response(
	 *         response=404,
	 *         description="Todo not found",
	 *         @OA\JsonContent(ref="#/components/schemas/TodoErrorResponse")
	 *     ),
	 *     @OA\Response(
	 *         response=401,
	 *         description="Unauthorized",
	 *         @OA\JsonContent(ref="#/components/schemas/TodoErrorResponse")
	 *     ),
	 *     @OA\Response(
	 *         response=500,
	 *         description="Internal server error",
	 *         @OA\JsonContent(ref="#/components/schemas/TodoErrorResponse")
	 *     )
	 * )
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
		$parentId = (int) ($values['parent'] ?? 0);
		if ($this->isCircularParentAssignment($botodo, $id, $parentId))
		{
			return ResponseHelper::sendErrorResponse(['error' => 'Invalid parent selection: circular references are not allowed'], 400);
		}

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
	 * PATCH /todo/todos/{id}/status
	 *
	 * @OA\Patch(
	 *     path="/todo/todos/{id}/status",
	 *     summary="Update todo completion percentage",
	 *     tags={"Todo"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\RequestBody(
	 *         required=true,
	 *         @OA\JsonContent(
	 *             required={"status"},
	 *             @OA\Property(property="status", type="integer", minimum=0, maximum=100)
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Status updated",
	 *         @OA\JsonContent(
	 *             type="object",
	 *             @OA\Property(property="id", type="integer"),
	 *             @OA\Property(property="status", type="integer", minimum=0, maximum=100)
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=400,
	 *         description="Invalid status value",
	 *         @OA\JsonContent(ref="#/components/schemas/TodoErrorResponse")
	 *     ),
	 *     @OA\Response(
	 *         response=404,
	 *         description="Todo not found",
	 *         @OA\JsonContent(ref="#/components/schemas/TodoErrorResponse")
	 *     ),
	 *     @OA\Response(
	 *         response=401,
	 *         description="Unauthorized",
	 *         @OA\JsonContent(ref="#/components/schemas/TodoErrorResponse")
	 *     ),
	 *     @OA\Response(
	 *         response=500,
	 *         description="Internal server error",
	 *         @OA\JsonContent(ref="#/components/schemas/TodoErrorResponse")
	 *     )
	 * )
	 */
	public function updateStatus(Request $request, Response $response, array $args): Response
	{
		$id = (int) ($args['id'] ?? 0);
		if (!$id)
		{
			return ResponseHelper::sendErrorResponse(['error' => 'Missing todo ID'], 400);
		}

		$payload = $this->readPayload($request);
		if (!isset($payload['status']))
		{
			return ResponseHelper::sendErrorResponse(['error' => 'Missing status value'], 400);
		}

		$status = (int) $payload['status'];
		if ($status < 0 || $status > 100)
		{
			return ResponseHelper::sendErrorResponse(['error' => 'Status must be between 0 and 100'], 400);
		}

		$botodo = \CreateObject('todo.botodo', true);
		$current = $botodo->read($id);
		if (!$current)
		{
			return ResponseHelper::sendErrorResponse(['error' => 'Todo not found'], 404);
		}

		$values = [
			'id' => $id,
			'title' => (string) ($current['title'] ?? ''),
			'descr' => (string) ($current['descr'] ?? ''),
			'cat' => (int) ($current['cat'] ?? 0),
			'parent' => (int) ($current['parent'] ?? 0),
			'pri' => (int) ($current['pri'] ?? 2),
			'status' => $status,
			'access' => ((string) ($current['access'] ?? '') === 'private'),
			'assigned' => (string) ($current['assigned'] ?? ''),
			'assigned_group' => (string) ($current['assigned_group'] ?? ''),
			'sdate' => (int) ($current['sdate'] ?? 0),
			'edate' => (int) ($current['edate'] ?? 0),
		];

		$error = $botodo->check_values($values);
		if (is_array($error) && count($error))
		{
			return ResponseHelper::sendErrorResponse(['error' => implode('; ', $error)], 400);
		}

		$ok = $botodo->save($values, 'edit');
		if (!$ok)
		{
			return ResponseHelper::sendErrorResponse(['error' => 'Failed to update todo status'], 500);
		}

		return ResponseHelper::sendJSONResponse([
			'id' => $id,
			'status' => $status,
		]);
	}

	/**
	 * DELETE /todo/todos/{id}
	 *
	 * @OA\Delete(
	 *     path="/todo/todos/{id}",
	 *     summary="Delete todo",
	 *     tags={"Todo"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="subs", in="query", required=false, @OA\Schema(type="boolean", default=false)),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Delete result",
	 *         @OA\JsonContent(
	 *             type="object",
	 *             @OA\Property(property="deleted", type="boolean"),
	 *             @OA\Property(property="subs", type="boolean")
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=403,
	 *         description="Delete denied",
	 *         @OA\JsonContent(ref="#/components/schemas/TodoErrorResponse")
	 *     ),
	 *     @OA\Response(
	 *         response=404,
	 *         description="Todo not found",
	 *         @OA\JsonContent(ref="#/components/schemas/TodoErrorResponse")
	 *     ),
	 *     @OA\Response(
	 *         response=401,
	 *         description="Unauthorized",
	 *         @OA\JsonContent(ref="#/components/schemas/TodoErrorResponse")
	 *     ),
	 *     @OA\Response(
	 *         response=500,
	 *         description="Internal server error",
	 *         @OA\JsonContent(ref="#/components/schemas/TodoErrorResponse")
	 *     )
	 * )
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
		$deleteResult = $botodo->delete($id, $deleteSubs);

		if (is_array($deleteResult) && empty($deleteResult['ok']))
		{
			$reason = (string) ($deleteResult['reason'] ?? 'delete_denied');
			$message = (string) ($deleteResult['message'] ?? lang('Delete denied'));

			$statusCode = 403;
			if ($reason === 'not_found' || $reason === 'parent_not_found')
			{
				$statusCode = 404;
			}

			return ResponseHelper::sendErrorResponse([
				'error' => $message,
				'reason' => $reason,
				'todo_id' => (int) ($deleteResult['todo_id'] ?? $id),
				'parent_id' => (int) ($deleteResult['parent_id'] ?? 0),
			], $statusCode);
		}

		if ($deleteResult === false)
		{
			return ResponseHelper::sendErrorResponse(['error' => 'Failed to delete todo'], 500);
		}

		return ResponseHelper::sendJSONResponse([
			'deleted' => true,
			'subs' => $deleteSubs,
			'result' => is_array($deleteResult) ? $deleteResult : null,
		]);
	}
}
