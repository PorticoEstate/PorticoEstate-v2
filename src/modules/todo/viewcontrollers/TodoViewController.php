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
}
