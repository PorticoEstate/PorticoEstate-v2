<?php

namespace App\modules\hrm\controllers;

use App\helpers\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class HrmPlaceController
{
	private function listParams(Request $request): array
	{
		$query = $request->getQueryParams();
		$order = $query['order'][0] ?? [];
		$columns = (array) ($query['columns'] ?? []);
		$columnIndex = (int) ($order['column'] ?? 0);
		$columnKey = (string) ($columns[$columnIndex]['data'] ?? 'name');
		$columnsByKey = [
			'name' => 'name',
		];

		return [
			'draw' => (int) ($query['draw'] ?? 0),
			'start' => max(0, (int) ($query['start'] ?? 0)),
			'length' => (int) ($query['length'] ?? 10),
			'query' => (string) ($query['search']['value'] ?? $query['search'] ?? ''),
			'order' => $columnsByKey[$columnKey] ?? 'name',
			'sort' => strtoupper((string) ($order['dir'] ?? 'asc')) === 'DESC' ? 'DESC' : 'ASC',
		];
	}

	public function index(Request $request, Response $response): Response
	{
		$params = $this->listParams($request);
		$places = \CreateObject('hrm.boplace', false);
		$places->start = $params['start'];
		$places->length = $params['length'] === -1 ? -1 : max(1, $params['length']);
		$places->query = $params['query'];
		$places->order = $params['order'];
		$places->sort = $params['sort'];
		$places->allrows = $places->length === -1;

		$rows = [];
		foreach ((array) $places->read() as $place)
		{
			$rows[] = [
				'id' => (int) ($place['id'] ?? 0),
				'name' => (string) ($place['name'] ?? ''),
			];
		}

		$total = (int) $places->total_records;
		return ResponseHelper::sendJSONResponse([
			'draw' => $params['draw'],
			'recordsTotal' => $total,
			'recordsFiltered' => $total,
			'data' => $rows,
		]);
	}
}