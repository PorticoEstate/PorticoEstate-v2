<?php

namespace App\modules\todo\viewcontrollers;

use App\modules\phpgwapi\services\Settings;
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

	private function formatDateForInput($timestamp, string $dateFormat): string
	{
		$ts = (int) $timestamp;
		if ($ts <= 0)
		{
			return '';
		}

		return date($dateFormat, $ts);
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
			$entries = $botodo->_list(0, 0, '', '', '', '', '', 'mains');

			$o = 0;
			foreach ((array) $entries as $entry)
			{
				$o++;
				$ind = $o % count($colors);

				if ((int) ($entry['sdate_epoch'] ?? 0) <= 0 || (int) ($entry['edate_epoch'] ?? 0) <= 0)
				{
					continue;
				}

				$id = (int) ($entry['id'] ?? 0);
				$title = '<a href="' . \phpgw::link('/todo/view/todos/' . $id) . '">' . \phpgw::strip_html((string) ($entry['title'] ?? '')) . '</a>';
				$startd = date('Ymd', (int) $entry['sdate_epoch']);
				$endd = date('Ymd', (int) $entry['edate_epoch']);
				$matrix->setPeriod($title, $startd, $endd, $colors[$ind]);

				$subentries = $botodo->_list(0, 0, '', '', '', '', '', 'subs', $id);
				foreach ((array) $subentries as $subentry)
				{
					if ((int) ($subentry['sdate_epoch'] ?? 0) <= 0 || (int) ($subentry['edate_epoch'] ?? 0) <= 0)
					{
						continue;
					}

					$subId = (int) ($subentry['id'] ?? 0);
					$subTitle = '<a href="' . \phpgw::link('/todo/view/todos/' . $subId) . '">' . \phpgw::strip_html((string) ($subentry['title'] ?? '')) . '</a>';
					$subStart = date('Ymd', (int) $subentry['sdate_epoch']);
					$subEnd = date('Ymd', (int) $subentry['edate_epoch']);
					$matrix->setPeriod($subTitle, $subStart, $subEnd, $colors[$ind]);
				}
			}

			ob_start();
			$matrix->out(\phpgw::link('/todo/view/todos/matrix'));
			$matrixHtml = (string) ob_get_clean();

			$componentHtml = $this->twig->render('@views/todo/matrix/todo_matrix.twig', [
				'layout' => '@views/_bare.twig',
				'matrix_html' => $matrixHtml,
			]);

			$html = $this->legacyView->render($componentHtml, ['todo']);
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
	 * GET /todo/view/todos
	 */
	public function index(Request $request, Response $response): Response
	{
		Settings::getInstance()->update('flags', ['app_header' => lang('todo') . '::' . lang('list projects')]);

		try {
			$userSettings = Settings::getInstance()->get('user');
			$maxMatches = isset($userSettings['preferences']['common']['maxmatchs']) ? (int) $userSettings['preferences']['common']['maxmatchs'] : 25;
			if ($maxMatches < 1)
			{
				$maxMatches = 25;
			}

			$pageSizeOptions = [];
			foreach ([1, 2, 4] as $multiplier)
			{
				$value = $maxMatches * $multiplier;
				if ($value > 0 && $value <= 2000)
				{
					$pageSizeOptions[] = $value;
				}
			}
			$pageSizeOptions = array_values(array_unique($pageSizeOptions));

			$componentHtml = $this->twig->render('@views/todo/index/todo_index.twig', [
				'layout' => '@views/_bare.twig',
				'categories' => $this->getCategories(),
				'page_size_options' => $pageSizeOptions,
				'default_page_size' => $maxMatches,
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
			]);

			$html = $this->legacyView->render($componentHtml, ['todo']);
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
			]);

			$html = $this->legacyView->render($componentHtml, ['todo']);
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
				'date_format' => $dateFormat,
				'selected_cat_id' => isset($query['cat_id']) ? (int) $query['cat_id'] : 0,
				'selected_parent_id' => isset($query['parent']) ? (int) $query['parent'] : 0,
			]);

			$html = $this->legacyView->render($componentHtml, ['todo']);
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
				'date_format' => $dateFormat,
			]);

			$html = $this->legacyView->render($componentHtml, ['todo']);
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
