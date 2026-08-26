<?php

namespace App\modules\messenger\controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\modules\phpgwapi\controllers\Accounts\Accounts;
use App\modules\phpgwapi\security\Acl;
use App\modules\phpgwapi\services\Settings;

class MessengerController
{
	private function json(Response $response, $data, int $status = 200): Response
	{
		$response->getBody()->write((string) json_encode($data));
		return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
	}

	private function businessObject()
	{
		return \CreateObject('messenger.bomessenger', true);
	}

	private function payload(Request $request): array
	{
		$payload = $request->getParsedBody();
		if (!is_array($payload))
		{
			$payload = json_decode((string) $request->getBody(), true);
		}

		return is_array($payload) ? $payload : [];
	}

	private function sendMessage(Response $response, array $message, bool $global = false): Response
	{
		$bo = $this->businessObject();
		if (!$global)
		{
			$errors = $bo->check_for_missing_fields($message);
			if ($errors)
			{
				return $this->json($response, ['error' => 'Message validation failed', 'errors' => $errors], 422);
			}
		}

		$bo->so->send_message($message, $global);
		return $this->json($response, ['sent' => true]);
	}

	public function index(Request $request, Response $response): Response
	{
		$query = $request->getQueryParams();
		$body = (array) ($request->getParsedBody() ?: []);
		$start = max(0, (int) ($body['start'] ?? $query['start'] ?? 0));
		$limit = (int) ($body['length'] ?? $body['limit'] ?? $query['limit'] ?? 0);
		$search = $body['search']['value'] ?? $body['search'] ?? $query['search'] ?? $query['query'] ?? '';
		$order = $body['order'][0] ?? [];
		$columns = (array) ($body['columns'] ?? []);
		$columnIndex = (int) ($order['column'] ?? -1);
		$columnKey = (string) ($columns[$columnIndex]['data'] ?? $query['sort'] ?? 'date');
		$direction = strtoupper((string) ($order['dir'] ?? $body['dir'] ?? $query['dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
		$status = strtoupper((string) ($body['status'] ?? $query['status'] ?? ''));
		$status = in_array($status, ['N', 'R', 'O', 'F'], true) ? $status : '';
		$sortKeys = [
			'id' => 'message_id',
			'from' => 'message_from',
			'subject' => 'message_subject',
			'date' => 'message_date',
		];
		$sortKey = $columnKey;
		$params = [
			'start' => $start,
			'results' => $limit,
			'query' => (string) $search,
			'order' => $sortKeys[$sortKey] ?? 'message_date',
			'sort' => $direction,
			'status' => $status,
		];

		$bo = $this->businessObject();
		$messages = [];
		foreach ((array) $bo->read_inbox($params) as $message)
		{
			$messages[] = [
				'id' => (int) ($message['id'] ?? 0),
				'from' => \phpgw::strip_html((string) ($message['from'] ?? '')),
				'status' => (string) ($message['status'] ?? ''),
				'status_text' => (string) ($message['status_text'] ?? ''),
				'date' => \phpgw::strip_html((string) ($message['date'] ?? '')),
				'subject' => \phpgw::strip_html((string) ($message['subject'] ?? '')),
			];
		}

		$total = (int) $bo->total_messages();
		if (isset($body['draw']) || isset($query['draw']))
		{
			return $this->json($response, [
				'draw' => (int) ($body['draw'] ?? $query['draw'] ?? 0),
				'recordsTotal' => $total,
				'recordsFiltered' => $total,
				'data' => $messages,
			]);
		}

		return $this->json($response, ['items' => $messages, 'total' => $total]);
	}

	public function show(Request $request, Response $response, array $args): Response
	{
		$id = (int) ($args['id'] ?? 0);
		$bo = $this->businessObject();
		$rawMessage = $bo->so->read_message($id);
		$message = $bo->read_message($id);
		if (!is_array($message) || empty($message))
		{
			return $this->json($response, ['error' => 'Message not found'], 404);
		}
		$message['from_id'] = (int) ($rawMessage['from'] ?? 0);

		return $this->json($response, $message);
	}

	public function users(Request $request, Response $response): Response
	{
		$users = [];
		foreach ($this->businessObject()->get_available_users() as $id => $name)
		{
			$users[] = ['id' => (int) $id, 'name' => (string) $name];
		}

		return $this->json($response, ['data' => $users]);
	}

	public function groups(Request $request, Response $response): Response
	{
		if (!Acl::getInstance()->check('.compose_groups', Acl::ADD, 'messenger'))
		{
			return $this->json($response, ['error' => 'Access not permitted'], 403);
		}

		return $this->json($response, ['data' => $this->availableGroups()]);
	}

	private function availableGroups(): array
	{
		$accounts = new Accounts();
		$allGroups = $accounts->get_list('groups');
		$validGroups = array_keys($allGroups);
		$bo = $this->businessObject();
		if (!Acl::getInstance()->check('run', Acl::READ, 'admin'))
		{
			$validGroups = [];
			$userSettings = Settings::getInstance()->get('user');
			foreach ((array) ($userSettings['apps'] ?? []) as $app => $unused)
			{
				if (Acl::getInstance()->check('admin', Acl::ADD, $app))
				{
					$validGroups = array_merge($validGroups, Acl::getInstance()->get_ids_for_location('run', Acl::READ, $app));
				}
			}
			$validGroups = array_unique(array_map('intval', $validGroups));
		}

		$groups = [];
		foreach ($allGroups as $group)
		{
			if (in_array((int) $group->id, $validGroups, true))
			{
				$groups[] = ['id' => (int) $group->id, 'name' => (string) $group];
			}
		}
		return $groups;
	}

	public function storeGroups(Request $request, Response $response): Response
	{
		if (!Acl::getInstance()->check('.compose_groups', Acl::ADD, 'messenger'))
		{
			return $this->json($response, ['error' => 'Access not permitted'], 403);
		}

		$payload = $this->payload($request);
		$groups = array_values(array_filter(array_map('intval', (array) ($payload['account_groups'] ?? [])), static fn (int $id): bool => $id > 0));
		$subject = trim((string) ($payload['subject'] ?? ''));
		$content = trim((string) ($payload['content'] ?? ''));
		if (!$groups || $subject === '' || $content === '')
		{
			return $this->json($response, ['error' => 'Group message validation failed', 'errors' => array_values(array_filter([
				!$groups ? lang('Missing groups') : '',
				$subject === '' ? lang('Missing subject') : '',
				$content === '' ? lang('Missing content') : '',
			]))], 422);
		}

		$allowedIds = array_column($this->availableGroups(), 'id');
		$groups = array_values(array_intersect($groups, $allowedIds));
		if (!$groups)
		{
			return $this->json($response, ['error' => 'No permitted groups selected'], 403);
		}

		$receipt = $this->businessObject()->send_to_groups([
			'account_groups' => $groups,
			'subject' => $subject,
			'content' => $content,
		]);
		return $this->json($response, ['sent' => true, 'receipt' => $receipt]);
	}

	public function storeGlobal(Request $request, Response $response): Response
	{
		$acl = Acl::getInstance();
		if (!$acl->check('.compose_global', Acl::ADD, 'messenger') && !$acl->check('run', Acl::ADD, 'admin'))
		{
			return $this->json($response, ['error' => 'Access not permitted'], 403);
		}

		$payload = $this->payload($request);
		$subject = trim((string) ($payload['subject'] ?? ''));
		$content = trim((string) ($payload['content'] ?? ''));
		if ($subject === '' || $content === '')
		{
			return $this->json($response, ['error' => 'Global message validation failed', 'errors' => array_values(array_filter([
				$subject === '' ? lang('Missing subject') : '',
				$content === '' ? lang('Missing content') : '',
			]))], 422);
		}

		$bo = $this->businessObject();
		$accounts = new Accounts();
		$bo->so->transaction_begin();
		foreach ((array) $accounts->get_list('accounts') as $account)
		{
			$bo->so->send_message(['to' => (int) $account->id, 'subject' => $subject, 'content' => $content], true);
		}
		$bo->so->transaction_commit();

		return $this->json($response, ['sent' => true]);
	}

	public function store(Request $request, Response $response): Response
	{
		$parsedBody = $request->getParsedBody();
		$parsedBody = is_array($parsedBody) ? $parsedBody : [];
		$query = $request->getQueryParams();
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

		$payload = $this->payload($request);
		$message = [
			'to' => (int) ($payload['to'] ?? 0),
			'subject' => trim((string) ($payload['subject'] ?? '')),
			'content' => trim((string) ($payload['content'] ?? '')),
		];

		return $this->sendMessage($response, $message);
	}

	public function reply(Request $request, Response $response, array $args): Response
	{
		$payload = $this->payload($request);
		$bo = $this->businessObject();
		$original = $bo->so->read_message((int) ($args['id'] ?? 0));
		$message = [
			'to' => (int) ($original['from'] ?? 0),
			'subject' => trim((string) ($payload['subject'] ?? '')),
			'content' => trim((string) ($payload['content'] ?? '')),
		];
		$result = $this->sendMessage($response, $message);
		if ($result->getStatusCode() < 300)
		{
			$bo->so->update_message_status('R', (int) ($args['id'] ?? 0));
		}
		return $result;
	}

	public function forward(Request $request, Response $response, array $args): Response
	{
		$payload = $this->payload($request);
		$message = [
			'to' => (int) ($payload['to'] ?? 0),
			'subject' => trim((string) ($payload['subject'] ?? '')),
			'content' => trim((string) ($payload['content'] ?? '')),
		];
		$result = $this->sendMessage($response, $message);
		if ($result->getStatusCode() < 300)
		{
			$this->businessObject()->so->update_message_status('F', (int) ($args['id'] ?? 0));
		}
		return $result;
	}

	public function destroy(Request $request, Response $response): Response
	{
		$body = (array) ($request->getParsedBody() ?: []);
		$ids = $body['ids'] ?? [];
		$ids = is_array($ids) ? $ids : [$ids];
		$ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
		if (!$ids)
		{
			return $this->json($response, ['error' => 'At least one message id is required'], 422);
		}

		$bo = $this->businessObject();
		$bo->so->transaction_begin();
		foreach ($ids as $id)
		{
			$bo->so->delete_message($id);
		}
		$bo->so->transaction_commit();
		return $this->json($response, ['deleted' => $ids]);
	}
}