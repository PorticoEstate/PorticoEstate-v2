<?php

namespace App\modules\sms\controllers;

use App\helpers\ResponseHelper;
use App\modules\phpgwapi\security\Acl;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SmsCommandController
{
	private function businessObject()
	{
		return \CreateObject('sms.bocommand', true);
	}

	private function payload(Request $request): array
	{
		$data = $request->getParsedBody();
		if (!is_array($data))
		{
			$decoded = json_decode((string) $request->getBody(), true);
			$data = is_array($decoded) ? $decoded : [];
		}
		return $data;
	}

	private function allowed(): bool
	{
		return Acl::getInstance()->check('.command', Acl::READ, 'sms');
	}

	public function index(Request $request, Response $response): Response
	{
		if (!$this->allowed()) return ResponseHelper::sendErrorResponse(['error' => 'Access not permitted'], 403);
		$query = $request->getQueryParams();
		$body = (array) ($request->getParsedBody() ?: []);
		$start = max(0, (int) ($body['start'] ?? $query['start'] ?? 0));
		$search = (string) ($body['search']['value'] ?? $body['search'] ?? $query['search'] ?? '');
		$draw = (int) ($body['draw'] ?? $query['draw'] ?? 0);
		$bo = $this->businessObject();
		$rows = (array) $bo->read(['start' => $start, 'query' => $search, 'allrows' => false]);
		$data = array_map(static function (array $row): array {
			return ['id' => (int) ($row['id'] ?? 0), 'code' => (string) ($row['code'] ?? ''), 'exec' => (string) ($row['exec'] ?? ''), 'uid' => (int) ($row['uid'] ?? 0)];
		}, $rows);
		$total = (int) $bo->total_records;
		return ResponseHelper::sendJSONResponse($draw > 0 ? ['draw' => $draw, 'recordsTotal' => $total, 'recordsFiltered' => $total, 'data' => $data] : ['items' => $data, 'total' => $total]);
	}

	public function show(Request $request, Response $response, array $args): Response
	{
		if (!$this->allowed()) return ResponseHelper::sendErrorResponse(['error' => 'Access not permitted'], 403);
		return ResponseHelper::sendJSONResponse(['item' => $this->businessObject()->read_single_command((int) ($args['id'] ?? 0))]);
	}

	public function store(Request $request, Response $response): Response
	{
		if (!Acl::getInstance()->check('.command', Acl::ADD, 'sms')) return ResponseHelper::sendErrorResponse(['error' => 'Access not permitted'], 403);
		$data = $this->payload($request);
		foreach (['code', 'type', 'exec'] as $field) if (trim((string) ($data[$field] ?? '')) === '') return ResponseHelper::sendErrorResponse(['error' => 'Missing command field: ' . $field], 400);
		$result = $this->businessObject()->save_command($data);
		return ResponseHelper::sendJSONResponse($result, 201);
	}

	public function update(Request $request, Response $response, array $args): Response
	{
		if (!Acl::getInstance()->check('.command', Acl::EDIT, 'sms')) return ResponseHelper::sendErrorResponse(['error' => 'Access not permitted'], 403);
		$data = $this->payload($request);
		$data['command_id'] = (int) ($args['id'] ?? 0);
		$result = $this->businessObject()->save_command($data, 'edit');
		return ResponseHelper::sendJSONResponse($result);
	}

	public function destroy(Request $request, Response $response, array $args): Response
	{
		if (!Acl::getInstance()->check('.command', Acl::DELETE, 'sms')) return ResponseHelper::sendErrorResponse(['error' => 'Access not permitted'], 403);
		$id = (int) ($args['id'] ?? 0);
		$db = new \App\Database\Db2();
		$db->query('DELETE FROM phpgw_sms_featcommand WHERE command_id=' . $id, __LINE__, __FILE__);
		return ResponseHelper::sendJSONResponse(['deleted' => true]);
	}

	public function log(Request $request, Response $response): Response
	{
		if (!$this->allowed()) return ResponseHelper::sendErrorResponse(['error' => 'Access not permitted'], 403);
		$query = $request->getQueryParams();
		$body = (array) ($request->getParsedBody() ?: []);
		$draw = (int) ($body['draw'] ?? $query['draw'] ?? 0);
		$bo = $this->businessObject();
		$rows = (array) $bo->read_log(['start' => (int) ($body['start'] ?? 0), 'query' => (string) ($body['search']['value'] ?? ''), 'allrows' => false]);
		$total = (int) $bo->total_records;
		return ResponseHelper::sendJSONResponse($draw > 0 ? ['draw' => $draw, 'recordsTotal' => $total, 'recordsFiltered' => $total, 'data' => $rows] : ['items' => $rows, 'total' => $total]);
	}
}
