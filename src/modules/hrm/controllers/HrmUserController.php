<?php

namespace App\modules\hrm\controllers;

use App\helpers\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class HrmUserController
{
	private function listParams(Request $request): array
	{
		$query = $request->getQueryParams();
		$order = $query['order'][0] ?? [];
		$columns = (array) ($query['columns'] ?? []);
		$columnIndex = (int) ($order['column'] ?? -1);
		$columnKey = (string) ($columns[$columnIndex]['data'] ?? 'last_name');
		$columnsByKey = [
			'first_name' => 'account_firstname',
			'last_name' => 'account_lastname',
		];

		return [
			'draw' => (int) ($query['draw'] ?? 0),
			'start' => max(0, (int) ($query['start'] ?? 0)),
			'query' => (string) ($query['search']['value'] ?? $query['search'] ?? ''),
			'order' => $columnsByKey[$columnKey] ?? 'account_lastname',
			'sort' => strtoupper((string) ($order['dir'] ?? 'asc')) === 'DESC' ? 'DESC' : 'ASC',
		];
	}

	public function index(Request $request, Response $response): Response
	{
		$params = $this->listParams($request);
		$users = \CreateObject('hrm.bouser', false);
		$users->start = $params['start'];
		$users->query = $params['query'];
		$users->order = $params['order'];
		$users->sort = $params['sort'];
		$users->allrows = false;

		$common = \CreateObject('hrm.bocommon');
		$rows = [];
		foreach ((array) $users->read() as $user)
		{
			$userId = (int) ($user['account_id'] ?? 0);
			$rows[] = [
				'id' => $userId,
				'first_name' => (string) ($user['account_firstname'] ?? ''),
				'last_name' => (string) ($user['account_lastname'] ?? ''),
				'can_training' => $common->check_perms2($userId, (array) $users->grants, ACL_READ),
			];
		}

		$total = (int) $users->total_records;
		if ($params['draw'] > 0)
		{
			return ResponseHelper::sendJSONResponse([
				'draw' => $params['draw'],
				'recordsTotal' => $total,
				'recordsFiltered' => $total,
				'data' => $rows,
			]);
		}

		return ResponseHelper::sendJSONResponse(['items' => $rows, 'total' => $total]);
	}

	public function training(Request $request, Response $response, array $args): Response
	{
		$userId = (int) ($args['id'] ?? 0);
		$users = \CreateObject('hrm.bouser', false);
		$common = \CreateObject('hrm.bocommon');
		if (!$userId || !$common->check_perms2($userId, (array) $users->grants, ACL_READ))
		{
			return ResponseHelper::sendErrorResponse(['error' => 'Access not permitted'], 403);
		}

		$query = $request->getQueryParams();
		$order = $query['order'][0] ?? [];
		$columns = (array) ($query['columns'] ?? []);
		$columnIndex = (int) ($order['column'] ?? 4);
		$columnKey = (string) ($columns[$columnIndex]['data'] ?? 'start_date');
		$trainingColumns = [
			'category' => 'phpgw_hrm_training_category.descr',
			'title' => 'phpgw_hrm_training.title',
			'place' => 'phpgw_hrm_training_place.name',
			'credits' => 'phpgw_hrm_training.credits',
			'start_date' => 'phpgw_hrm_training.start_date',
			'end_date' => 'phpgw_hrm_training.end_date',
		];

		$users->start = max(0, (int) ($query['start'] ?? 0));
		$length = (int) ($query['length'] ?? 10);
		$users->length = $length === -1 ? -1 : max(1, $length);
		$users->query = (string) ($query['search']['value'] ?? $query['search'] ?? '');
		$users->order = $trainingColumns[$columnKey] ?? 'phpgw_hrm_training.start_date';
		$users->sort = strtoupper((string) ($order['dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
		$users->allrows = $users->length === -1;
		$rows = (array) $users->read_training($userId);

		$data = array_map(static function (array $row): array {
			return [
				'id' => (int) ($row['training_id'] ?? 0),
				'category' => (string) ($row['category'] ?? ''),
				'title' => (string) ($row['title'] ?? ''),
				'place' => (string) ($row['place'] ?? ''),
				'credits' => (int) ($row['credits'] ?? 0),
				'start_date' => (string) ($row['start_date'] ?? ''),
				'end_date' => (string) ($row['end_date'] ?? ''),
			];
		}, $rows);

		return ResponseHelper::sendJSONResponse([
			'draw' => (int) ($query['draw'] ?? 0),
			'recordsTotal' => count($data),
			'recordsFiltered' => count($data),
			'data' => $data,
		]);
	}
}
