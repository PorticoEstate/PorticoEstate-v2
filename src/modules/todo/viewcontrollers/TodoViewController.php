<?php

namespace App\modules\todo\viewcontrollers;

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\security\Acl;
use App\modules\phpgwapi\helpers\LegacyViewHelper;
use App\modules\phpgwapi\helpers\TwigHelper;
use App\helpers\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Exception;

class TodoViewController
{
	protected TwigHelper $twig;
	protected LegacyViewHelper $legacyView;

	private function getDatatableI18n(): array
	{
		$user = Settings::getInstance()->get('user');
		$rowsPerPage = isset($user['preferences']['common']['maxmatchs']) && (int) $user['preferences']['common']['maxmatchs'] > 0
			? (int) $user['preferences']['common']['maxmatchs']
			: 10;

		$lengthmenu = [[], []];
		for ($i = 1; $i < 4; $i++)
		{
			$lengthmenu[0][] = $i * $rowsPerPage;
			$lengthmenu[1][] = $i * $rowsPerPage;
		}

		return [
			'datatable' => [
				'emptyTable' => json_encode(lang('No data available in table')),
				'info' => json_encode(lang('Showing _START_ to _END_ of _TOTAL_ entries')),
				'infoEmpty' => json_encode(lang('Showing 0 to 0 of 0 entries')),
				'infoFiltered' => json_encode(lang('(filtered from _MAX_ total entries)')),
				'infoPostFix' => json_encode(''),
				'thousands' => json_encode(','),
				'lengthMenu' => json_encode(lang('Show _MENU_ entries')),
				'loadingRecords' => json_encode(lang('Loading...')),
				'processing' => json_encode(lang('Processing...')),
				'search' => json_encode(lang('search')),
				'zeroRecords' => json_encode(lang('No matching records found')),
				'paginate' => json_encode([
					'first' => lang('first'),
					'last' => lang('last'),
					'next' => lang('next'),
					'previous' => lang('prev'),
				]),
				'aria' => json_encode([
					'sortAscending' => lang(': activate to sort column ascending'),
					'sortDescending' => lang(': activate to sort column descending'),
				]),
				'select' => json_encode([
					'rows' => [
						'0' => '',
						'_' => '%d ' . lang('rows selected'),
					],
				]),
			],
			'lengthmenu' => ['_' => json_encode($lengthmenu)],
			'lengthmenu_allrows' => ['_' => json_encode([-1, lang('all')])],
			'csv_download' => ['_' => json_encode([
				'show_button' => !empty($user['preferences']['common']['csv_download']),
				'title' => lang('download visible data'),
			])],
		];
	}

	private function formatDateForInput($timestamp, string $dateFormat): string
	{
		$ts = (int) $timestamp;
		if ($ts <= 0)
		{
			return '';
		}

		return date($dateFormat, $ts);
	}

	private function buildMatrixHierarchyPrefix(int $level): string
	{
		$level = max(0, $level);
		if ($level === 0)
		{
			return '<span class="todo-matrix__node-prefix todo-matrix__node-prefix--root"></span>';
		}

		$spacer = str_repeat('&nbsp;&nbsp;&nbsp;', $level);
		return '<span class="todo-matrix__node-prefix" aria-hidden="true">' . $spacer . '&boxur;&nbsp;</span>';
	}

	private function buildMatrixTitle(array $entry, string $bandClass = ''): string
	{
		$id = (int) ($entry['id'] ?? 0);
		$level = max(0, (int) ($entry['level'] ?? 0));
		$status = (int) ($entry['status'] ?? 0);
		$status = max(0, min(100, $status));
		$title = 	\phpgw::strip_html((string) ($entry['title'] ?? ''));
		if ($title === '')
		{
			$title = lang('Untitled');
		}

		$prefix = $this->buildMatrixHierarchyPrefix($level);
		$link = '<a href="' . \phpgw::link('/todo/view/todos/' . $id) . '">' . $title . '</a>';
		$statusHtml = '<button type="button" class="todo-matrix__status" data-todo-id="' . $id . '" data-status="' . $status . '" title="' . lang('Completed') . ': ' . $status . '% - ' . lang('click to edit') . '">'
			. '<span class="todo-matrix__status-value">' . $status . '%</span>'
			. '<span class="todo-matrix__status-bar" aria-hidden="true"><span class="todo-matrix__status-fill" style="width:' . $status . '%"></span></span>'
			. '</button>';
		$bandClass = trim($bandClass);
		$bandClassPart = $bandClass ? ' ' . $bandClass : '';

		return '<span class="todo-matrix__node todo-matrix__node--level-' . $level . $bandClassPart . '">' . $prefix . $link . $statusHtml . '</span>';
	}

	private function getPeopleList(string $type): array
	{
		$botodo = \CreateObject('todo.botodo', true);
		$employees = $botodo->employee_list($type);
		$options = [];

		$accounts = new \App\modules\phpgwapi\controllers\Accounts\Accounts();
		foreach ((array) $employees as $employee)
		{
			$options[] = [
				'id' => (int) ($employee->id ?? 0),
				'name' => (string) $accounts->id2name((int) ($employee->id ?? 0)),
			];
		}

		return $options;
	}

	private function getParentTodos(int $excludeId = 0): array
	{
		$botodo = \CreateObject('todo.botodo', true);
		$todos = $botodo->_list(0, 0, '', '', '', '', 0, 'all');
		$excludedIds = [];
		if ($excludeId > 0)
		{
			$excludedIds[] = $excludeId;
			$descendants = (string) $botodo->sotodo->find_subs((string) $excludeId);
			if ($descendants !== '')
			{
				$excludedIds = array_merge($excludedIds, array_filter(array_map('intval', explode(',', $descendants))));
			}
			$excludedIds = array_values(array_unique($excludedIds));
		}

		$options = [
			['id' => 0, 'title' => lang('None')],
		];

		foreach ((array) $todos as $todo)
		{
			$id = (int) ($todo['id'] ?? 0);
			if ($excludeId > 0 && in_array($id, $excludedIds, true))
			{
				continue;
			}

			$title = (string) ($todo['title'] ?? '');
			if (!$title)
			{
				$descr = \phpgw::strip_html((string) ($todo['descr'] ?? ''));
				$title = trim(implode(' ', array_slice(explode(' ', $descr), 0, 4)) . ' ...');
			}

			$level = (int) ($todo['level'] ?? 0);
			if ($level > 0)
			{
				$title = str_repeat('-- ', $level) . $title;
			}

			$options[] = [
				'id' => $id,
				'title' => $title,
			];
		}

		return $options;
	}

	private function getCategories(): array
	{
		$cats = \CreateObject('phpgwapi.categories', -1, 'todo', '.task');
		$categories = $cats->return_sorted_array(0, false, '', '', '', true, 0, false);

		$options = [
			[
				'id' => 0,
				'name' => lang('All')
			]
		];

		foreach ((array) $categories as $category)
		{
			$options[] = [
				'id' => (int) ($category['id'] ?? 0),
				'name' => (string) ($category['name'] ?? '')
			];
		}

		return $options;
	}

	private function getCsrfData(Request $request): array
	{
		$nameKey = 'csrf_name';
		$valueKey = 'csrf_value';

		return [
			'name_key' => $nameKey,
			'value_key' => $valueKey,
			'name' => (string) ($request->getAttribute($nameKey) ?? ''),
			'value' => (string) ($request->getAttribute($valueKey) ?? ''),
		];
	}

	public function __construct()
	{
		$this->legacyView = new LegacyViewHelper();
		$this->twig = new TwigHelper('todo');
	}

	/**
	 * GET|POST /todo/view/todos/matrix
	 */
	public function matrix(Request $request, Response $response): Response
	{
		try {
			$body = (array) ($request->getParsedBody() ?: []);
			$query = $request->getQueryParams();

			$month = isset($query['month']) ? (int) $query['month'] : (isset($body['month']) ? (int) $body['month'] : (int) date('n'));
			$year = isset($query['year']) ? (int) $query['year'] : (isset($body['year']) ? (int) $body['year'] : (int) date('Y'));

			if ($month < 1 || $month > 12)
			{
				$month = (int) date('n');
			}
			if ($year < 1970 || $year > 2100)
			{
				$year = (int) date('Y');
			}

			$colors = [
				'#cc0033',
				'#006600',
				'#00ccff',
				'#ff6600',
				'#0000ff',
			];

			$botodo = \CreateObject('todo.botodo', true);
			$matrix = \CreateObject('phpgwapi.matrixview', $month, $year);
			$entries = $botodo->_list(0, 0, '', '', '', '', '', 'all');

			$groupColors = [];
			$groupBands = [];
			$colorIndex = 0;
			foreach ((array) $entries as $entry)
			{
				if ((int) ($entry['sdate_epoch'] ?? 0) <= 0 || (int) ($entry['edate_epoch'] ?? 0) <= 0)
				{
					continue;
				}

				$groupId = (int) ($entry['main'] ?? 0);
				if ($groupId <= 0)
				{
					$groupId = (int) ($entry['id'] ?? 0);
				}

				if (!isset($groupColors[$groupId]))
				{
					$groupColors[$groupId] = $colors[$colorIndex % count($colors)];
					$groupBands[$groupId] = ($colorIndex % 2 === 0) ? 'todo-matrix__node--band-a' : 'todo-matrix__node--band-b';
					$colorIndex++;
				}

				$title = $this->buildMatrixTitle((array) $entry, (string) ($groupBands[$groupId] ?? ''));
				$startd = date('Ymd', (int) $entry['sdate_epoch']);
				$endd = date('Ymd', (int) $entry['edate_epoch']);
				$matrix->setPeriod($title, $startd, $endd, $groupColors[$groupId]);
			}

			ob_start();
			$matrix->out(\phpgw::link('/todo/view/todos/matrix'));
			$matrixHtml = (string) ob_get_clean();

			$componentHtml = $this->twig->render('@views/todo/matrix/todo_matrix.twig', [
				'layout' => '@views/_bare.twig',
				'matrix_html' => $matrixHtml,
				'csrf' => $this->getCsrfData($request),
			]);

			$html = $this->legacyView->render($componentHtml, ['todo', 'matrix']);
			$response->getBody()->write($html);
			return $response->withHeader('Content-Type', 'text/html');
		} catch (Exception $e) {
			if (ob_get_level() > 0)
			{
				ob_end_clean();
			}

			return ResponseHelper::sendErrorResponse(
				['error' => 'Error loading todo matrix page: ' . $e->getMessage()],
				500
			);
		}
	}
	/**
	 *
	 * @param string $app
	 * @param string $pkg will always look within template set, then fallback to $pkg
	 * @param string $name name of the javascript file to include
	 * @param bool $end_of_page
	 * @param array $config
	 * @return bool
	 */

	public static function add_javascript($app, $pkg, $name, $end_of_page = false, $config = array())
	{

		return \phpgwapi_js::getInstance()->validate_file($pkg, str_replace('.js', '', $name), $app, $end_of_page, $config);
	}

	/**
	 * GET /todo/view/todos
	 */
	public function index(Request $request, Response $response): Response
	{

		try {
			\phpgw::import_class('phpgwapi.jquery');
			\phpgw::import_class('phpgwapi.css');
			\phpgw::import_class('phpgwapi.js');
			\phpgwapi_jquery::load_widget('core');
			\phpgwapi_jquery::load_widget('contextMenu');
			self::add_javascript('phpgwapi', "jquery", 'common.js', false, array('combine' => true));
			self::add_javascript('phpgwapi', 'DataTables2', 'datatables.min.js', false, array('combine' => true));
			self::add_javascript('phpgwapi', 'DataTables2', 'plugins/dataTables.inputPaging.js', false, array('combine' => true));
			self::add_javascript('phpgwapi', 'jquery', 'editable/jquery.jeditable.min.js', false, array('combine' => true));
			self::add_javascript('phpgwapi', 'jquery', 'editable/jquery.dataTables.editable.min.js', false, array('combine' => true));
			\phpgwapi_css::getInstance()->add_external_file('phpgwapi/js/DataTables2/datatables.min.css');
			\phpgwapi_css::getInstance()->add_external_file('phpgwapi/js/DataTables2/plugins/dataTables.inputPaging.min.css');



			$query = $request->getQueryParams();
			$selectedCat = isset($query['cat_id']) ? (int) $query['cat_id'] : 0;
			$selectedFilter = isset($query['filter']) ? (string) $query['filter'] : 'none';
			$search = isset($query['search']) ? (string) $query['search'] : '';

			$categories = array_map(static function (array $category) use ($selectedCat): array
			{
				$category['selected'] = ((int) ($category['id'] ?? 0) === $selectedCat) ? '1' : '0';
				return $category;
			}, $this->getCategories());

			$filters = [
				[
					'id' => 'none',
					'name' => lang('All'),
					'selected' => $selectedFilter === 'none' ? '1' : '0',
				],
				[
					'id' => 'private',
					'name' => lang('Private'),
					'selected' => $selectedFilter === 'private' ? '1' : '0',
				],
			];

			$componentHtml = $this->twig->render('@views/todo/index/todo_datatable.twig', [
				'layout' => '@views/_bare.twig',
				'categories' => $categories,
				'filters' => $filters,
				'acl_delete' => (bool) Acl::getInstance()->check('.todo', ACL_DELETE, 'todo'),
				'search_query' => $search,
				'jquery_phpgw_i18n' => $this->getDatatableI18n(),
				'matrix_url' => \phpgw::link('/todo/view/todos/matrix', [
					'month' => date('m'),
					'year' => date('Y'),
				]),
			]);

			$html = $this->legacyView->render(
				$componentHtml,
				['todo']
			);

			$response->getBody()->write($html);
			return $response->withHeader('Content-Type', 'text/html');
		} catch (Exception $e) {
			return ResponseHelper::sendErrorResponse(
				['error' => 'Error loading todo page: ' . $e->getMessage()],
				500
			);
		}
	}

	/**
	 * GET /todo/view/todos/{id}
	 */
	public function view(Request $request, Response $response, array $args): Response
	{
		try {
			$id = (int) ($args['id'] ?? 0);
			if ($id <= 0)
			{
				return ResponseHelper::sendErrorResponse(['error' => 'Missing todo ID'], 400);
			}

			$componentHtml = $this->twig->render('@views/todo/view/todo_view.twig', [
				'layout' => '@views/_bare.twig',
				'todo_id' => $id,
				'csrf' => $this->getCsrfData($request),
			]);

			$html = $this->legacyView->render($componentHtml, ['todo', 'view'], 'todo');
			$response->getBody()->write($html);
			return $response->withHeader('Content-Type', 'text/html');
		} catch (Exception $e) {
			return ResponseHelper::sendErrorResponse(
				['error' => 'Error loading todo view page: ' . $e->getMessage()],
				500
			);
		}
	}

	/**
	 * GET /todo/view/todos/{id}/delete
	 */
	public function delete(Request $request, Response $response, array $args): Response
	{
		try {
			$id = (int) ($args['id'] ?? 0);
			if ($id <= 0)
			{
				return ResponseHelper::sendErrorResponse(['error' => 'Missing todo ID'], 400);
			}

			$componentHtml = $this->twig->render('@views/todo/delete/todo_delete.twig', [
				'layout' => '@views/_bare.twig',
				'todo_id' => $id,
				'csrf' => $this->getCsrfData($request),
			]);

			$html = $this->legacyView->render($componentHtml, ['todo', 'delete'], 'todo');
			$response->getBody()->write($html);
			return $response->withHeader('Content-Type', 'text/html');
		} catch (Exception $e) {
			return ResponseHelper::sendErrorResponse(
				['error' => 'Error loading todo delete page: ' . $e->getMessage()],
				500
			);
		}
	}

	/**
	 * GET /todo/view/todos/add
	 */
	public function add(Request $request, Response $response): Response
	{
		try {
			\phpgw::import_class('phpgwapi.jquery');
			\phpgwapi_jquery::load_widget('select2');
			\phpgwapi_jquery::load_widget('datepicker');

			$query = $request->getQueryParams();
			$userSettings = \App\modules\phpgwapi\services\Settings::getInstance()->get('user');
			$dateFormat = (string) ($userSettings['preferences']['common']['dateformat'] ?? 'Y-m-d');
			$componentHtml = $this->twig->render('@views/todo/add/todo_add.twig', [
				'layout' => '@views/_bare.twig',
				'categories' => $this->getCategories(),
				'parentTodos' => $this->getParentTodos(),
				'users' => $this->getPeopleList('accounts'),
				'groups' => $this->getPeopleList('groups'),
				'csrf' => $this->getCsrfData($request),
				'date_format' => $dateFormat,
				'selected_cat_id' => isset($query['cat_id']) ? (int) $query['cat_id'] : 0,
				'selected_parent_id' => isset($query['parent']) ? (int) $query['parent'] : 0,
			]);

			$html = $this->legacyView->render($componentHtml, ['todo', 'add'], 'todo');
			$response->getBody()->write($html);
			return $response->withHeader('Content-Type', 'text/html');
		} catch (Exception $e) {
			return ResponseHelper::sendErrorResponse(
				['error' => 'Error loading todo add page: ' . $e->getMessage()],
				500
			);
		}
	}

	/**
	 * GET /todo/view/todos/{id}/edit
	 */
	public function edit(Request $request, Response $response, array $args): Response
	{
		try {
			$id = (int) ($args['id'] ?? 0);
			if ($id <= 0)
			{
				return ResponseHelper::sendErrorResponse(['error' => 'Missing todo ID'], 400);
			}

			$botodo = \CreateObject('todo.botodo', true);
			$item = $botodo->read($id);
			if (!is_array($item) || !count($item))
			{
				return ResponseHelper::sendErrorResponse(['error' => 'Todo not found'], 404);
			}

			\phpgw::import_class('phpgwapi.jquery');
			\phpgwapi_jquery::load_widget('select2');
			\phpgwapi_jquery::load_widget('datepicker');

			$userSettings = \App\modules\phpgwapi\services\Settings::getInstance()->get('user');
			$dateFormat = (string) ($userSettings['preferences']['common']['dateformat'] ?? 'Y-m-d');

			$assignedIds = $botodo->format_assigned((string) ($item['assigned'] ?? ''));
			$assignedGroupIds = $botodo->format_assigned((string) ($item['assigned_group'] ?? ''));

			$todo = [
				'id' => $id,
				'title' => (string) ($item['title'] ?? ''),
				'descr' => (string) ($item['descr'] ?? ''),
				'cat' => (int) ($item['cat'] ?? 0),
				'parent' => (int) ($item['parent'] ?? 0),
				'pri' => (int) ($item['pri'] ?? 2),
				'status' => (int) ($item['status'] ?? 0),
				'sdate' => $this->formatDateForInput($item['sdate'] ?? 0, $dateFormat),
				'edate' => $this->formatDateForInput($item['edate'] ?? 0, $dateFormat),
				'access_private' => (string) ($item['access'] ?? '') === 'private',
				'assigned_ids' => is_array($assignedIds) ? $assignedIds : [],
				'assigned_group_ids' => is_array($assignedGroupIds) ? $assignedGroupIds : [],
			];

			$componentHtml = $this->twig->render('@views/todo/edit/todo_edit.twig', [
				'layout' => '@views/_bare.twig',
				'todo' => $todo,
				'categories' => $this->getCategories(),
				'parentTodos' => $this->getParentTodos($id),
				'users' => $this->getPeopleList('accounts'),
				'groups' => $this->getPeopleList('groups'),
				'csrf' => $this->getCsrfData($request),
				'date_format' => $dateFormat,
			]);

			$html = $this->legacyView->render($componentHtml, ['todo', 'edit'], 'todo');
			$response->getBody()->write($html);
			return $response->withHeader('Content-Type', 'text/html');
		} catch (Exception $e) {
			return ResponseHelper::sendErrorResponse(
				['error' => 'Error loading todo edit page: ' . $e->getMessage()],
				500
			);
		}
	}
}
