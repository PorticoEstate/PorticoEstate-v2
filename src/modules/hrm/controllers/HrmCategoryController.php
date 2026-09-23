<?php

namespace App\modules\hrm\controllers;

use App\helpers\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class HrmCategoryController
{
	private array $allowedTypes = ['training', 'skill_level', 'experience', 'qualification'];

	private function listParams(Request $request): array
	{
		$query = $request->getQueryParams();
		$order = $query['order'][0] ?? [];
		$columns = (array) ($query['columns'] ?? []);
		$columnIndex = (int) ($order['column'] ?? 0);
		$columnKey = (string) ($columns[$columnIndex]['data'] ?? 'id');
		$columnsByKey = [
			'id' => 'id',
			'descr' => 'descr',
		];

		return [
			'draw' => (int) ($query['draw'] ?? 0),
			'start' => max(0, (int) ($query['start'] ?? 0)),
			'length' => (int) ($query['length'] ?? 10),
			'query' => (string) ($query['search']['value'] ?? $query['search'] ?? ''),
			'type_id' => (int) ($query['type_id'] ?? 0),
			'order' => $columnsByKey[$columnKey] ?? 'id',
			'sort' => strtoupper((string) ($order['dir'] ?? 'asc')) === 'DESC' ? 'DESC' : 'ASC',
		];
	}

	public function index(Request $request, Response $response, array $args): Response
	{
		$type = (string) ($args['type'] ?? '');
		if (!in_array($type, $this->allowedTypes, true))
		{
			return ResponseHelper::sendErrorResponse(['error' => 'Unknown category type'], 404);
		}

		$params = $this->listParams($request);
		$categories = \CreateObject('hrm.bocategory', false);
		$categories->start = $params['start'];
		$categories->length = $params['length'] === -1 ? -1 : max(1, $params['length']);
		$categories->query = $params['query'];
		$categories->order = $params['order'];
		$categories->sort = $params['sort'];
		$categories->allrows = $categories->length === -1;

		$rows = [];
		foreach ((array) $categories->read($type, $params['type_id']) as $category)
		{
			$rows[] = [
				'id' => (int) ($category['id'] ?? 0),
				'descr' => (string) ($category['descr'] ?? ''),
			];
		}

		$total = (int) $categories->total_records;
		return ResponseHelper::sendJSONResponse([
			'draw' => $params['draw'],
			'recordsTotal' => $total,
			'recordsFiltered' => $total,
			'data' => $rows,
		]);
	}
}