<?php

namespace App\modules\todo\viewcontrollers;

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

	private function getParentTodos(): array
	{
		$botodo = \CreateObject('todo.botodo', true);
		$todos = $botodo->_list(0, 0, '', '', '', '', 0, 'mains');
		$options = [
			['id' => 0, 'title' => lang('None')],
		];

		foreach ((array) $todos as $todo)
		{
			$title = (string) ($todo['title'] ?? '');
			if (!$title)
			{
				$descr = \phpgw::strip_html((string) ($todo['descr'] ?? ''));
				$title = trim(implode(' ', array_slice(explode(' ', $descr), 0, 4)) . ' ...');
			}

			$options[] = [
				'id' => (int) ($todo['id'] ?? 0),
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
	 * GET /todo/view/todos
	 */
	public function index(Request $request, Response $response): Response
	{
		try {
			$componentHtml = $this->twig->render('@views/todo/index/todo_index.twig', [
				'layout' => '@views/_bare.twig',
				'categories' => $this->getCategories(),
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
}
