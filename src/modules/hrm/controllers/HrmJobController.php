<?php

namespace App\modules\hrm\controllers;

use App\helpers\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class HrmJobController
{
	private function hasJobAccess(int $acl): bool
	{
		return (bool) \CreateObject('phpgwapi.acl')->check('.job', $acl, 'hrm');
	}

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
		if (!$this->hasJobAccess(ACL_READ))
		{
			return ResponseHelper::sendErrorResponse(['error' => 'Access not permitted'], 403);
		}

		$params = $this->listParams($request);
		$jobs = \CreateObject('hrm.bojob', false);
		$jobs->start = $params['start'];
		$jobs->length = $params['length'] === -1 ? -1 : max(1, $params['length']);
		$jobs->query = $params['query'];
		$jobs->order = $params['order'];
		$jobs->sort = $params['sort'];
		$jobs->allrows = $jobs->length === -1;

		$rows = [];
		foreach ((array) $jobs->read() as $job)
		{
			$level = (int) ($job['level'] ?? 0);
			$rows[] = [
				'id' => (int) ($job['id'] ?? 0),
				'name' => str_repeat('--', max(0, $level)) . (string) ($job['name'] ?? ''),
				'descr' => (string) ($job['descr'] ?? ''),
				'task_count' => (int) ($job['task_count'] ?? 0),
				'quali_count' => (int) ($job['quali_count'] ?? 0),
				'level' => $level,
			];
		}

		$total = (int) $jobs->total_records;
		return ResponseHelper::sendJSONResponse([
			'draw' => $params['draw'],
			'recordsTotal' => $total,
			'recordsFiltered' => $total,
			'data' => $rows,
		]);
	}
}