<?php

namespace App\modules\messenger\controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\modules\phpgwapi\controllers\Accounts\Accounts;
use App\modules\phpgwapi\security\Acl;
use App\modules\phpgwapi\services\Settings;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="Messenger",
 *     description="REST API for internal messenger messages"
 * )
 *
 * @OA\Schema(
 *     schema="MessengerMessage",
 *     type="object",
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="from", type="string"),
 *     @OA\Property(property="status", type="string", enum={"N", "R", "O", "F"}),
 *     @OA\Property(property="status_text", type="string"),
 *     @OA\Property(property="date", type="string"),
 *     @OA\Property(property="subject", type="string")
 * )
 *
 * @OA\Schema(
 *     schema="MessengerMessageDetail",
 *     type="object",
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="from", type="string"),
 *     @OA\Property(property="from_id", type="integer"),
 *     @OA\Property(property="status", type="string", enum={"N", "R", "O", "F"}),
 *     @OA\Property(property="date", type="string"),
 *     @OA\Property(property="subject", type="string"),
 *     @OA\Property(property="content", type="string")
 * )
 *
 * @OA\Schema(
 *     schema="MessengerUser",
 *     type="object",
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="name", type="string")
 * )
 *
 * @OA\Schema(
 *     schema="MessengerGroup",
 *     type="object",
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="name", type="string")
 * )
 *
 * @OA\Schema(
 *     schema="MessengerSendRequest",
 *     type="object",
 *     required={"to", "subject", "content"},
 *     @OA\Property(property="to", type="integer", description="Recipient account id"),
 *     @OA\Property(property="subject", type="string"),
 *     @OA\Property(property="content", type="string")
 * )
 *
 * @OA\Schema(
 *     schema="MessengerReplyRequest",
 *     type="object",
 *     required={"subject", "content"},
 *     @OA\Property(property="subject", type="string"),
 *     @OA\Property(property="content", type="string")
 * )
 *
 * @OA\Schema(
 *     schema="MessengerForwardRequest",
 *     type="object",
 *     required={"to", "subject", "content"},
 *     @OA\Property(property="to", type="integer", description="Recipient account id"),
 *     @OA\Property(property="subject", type="string"),
 *     @OA\Property(property="content", type="string")
 * )
 *
 * @OA\Schema(
 *     schema="MessengerGroupSendRequest",
 *     type="object",
 *     required={"account_groups", "subject", "content"},
 *     @OA\Property(property="account_groups", type="array", @OA\Items(type="integer")),
 *     @OA\Property(property="subject", type="string"),
 *     @OA\Property(property="content", type="string")
 * )
 *
 * @OA\Schema(
 *     schema="MessengerGlobalSendRequest",
 *     type="object",
 *     required={"subject", "content"},
 *     @OA\Property(property="subject", type="string"),
 *     @OA\Property(property="content", type="string")
 * )
 *
 * @OA\Schema(
 *     schema="MessengerDeleteRequest",
 *     type="object",
 *     required={"ids"},
 *     @OA\Property(property="ids", type="array", @OA\Items(type="integer"))
 * )
 *
 * @OA\Schema(
 *     schema="MessengerErrorResponse",
 *     type="object",
 *     @OA\Property(property="error", type="string"),
 *     @OA\Property(property="errors", type="array", @OA\Items(type="string"))
 * )
 */
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

	/**
	 * GET /messenger/messages
	 *
	 * @OA\Get(
	 *     path="/messenger/messages",
	 *     summary="List inbox messages (supports DataTables server-side params)",
	 *     tags={"Messenger"},
	 *     @OA\Parameter(name="start", in="query", @OA\Schema(type="integer", default=0)),
	 *     @OA\Parameter(name="limit", in="query", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="search", in="query", @OA\Schema(type="string")),
	 *     @OA\Parameter(name="sort", in="query", @OA\Schema(type="string", enum={"id", "from", "subject", "date"})),
	 *     @OA\Parameter(name="dir", in="query", @OA\Schema(type="string", enum={"ASC", "DESC"}, default="DESC")),
	 *     @OA\Parameter(name="status", in="query", @OA\Schema(type="string", enum={"N", "R", "O", "F"})),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Message list",
	 *         @OA\JsonContent(
	 *             type="object",
	 *             @OA\Property(property="items", type="array", @OA\Items(ref="#/components/schemas/MessengerMessage")),
	 *             @OA\Property(property="total", type="integer")
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=401,
	 *         description="Unauthorized",
	 *         @OA\JsonContent(ref="#/components/schemas/MessengerErrorResponse")
	 *     )
	 * )
	 */
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

	/**
	 * GET /messenger/messages/{id}
	 *
	 * @OA\Get(
	 *     path="/messenger/messages/{id}",
	 *     summary="Get a single message, marking it as read",
	 *     tags={"Messenger"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Message detail",
	 *         @OA\JsonContent(ref="#/components/schemas/MessengerMessageDetail")
	 *     ),
	 *     @OA\Response(
	 *         response=404,
	 *         description="Message not found",
	 *         @OA\JsonContent(ref="#/components/schemas/MessengerErrorResponse")
	 *     ),
	 *     @OA\Response(
	 *         response=401,
	 *         description="Unauthorized",
	 *         @OA\JsonContent(ref="#/components/schemas/MessengerErrorResponse")
	 *     )
	 * )
	 */
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

	/**
	 * GET /messenger/messages/users
	 *
	 * @OA\Get(
	 *     path="/messenger/messages/users",
	 *     summary="List users eligible to receive messages",
	 *     tags={"Messenger"},
	 *     @OA\Response(
	 *         response=200,
	 *         description="User list",
	 *         @OA\JsonContent(
	 *             type="object",
	 *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/MessengerUser"))
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=401,
	 *         description="Unauthorized",
	 *         @OA\JsonContent(ref="#/components/schemas/MessengerErrorResponse")
	 *     )
	 * )
	 */
	public function users(Request $request, Response $response): Response
	{
		$users = [];
		foreach ($this->businessObject()->get_available_users() as $id => $name)
		{
			$users[] = ['id' => (int) $id, 'name' => (string) $name];
		}

		return $this->json($response, ['data' => $users]);
	}

	/**
	 * GET /messenger/messages/groups
	 *
	 * @OA\Get(
	 *     path="/messenger/messages/groups",
	 *     summary="List account groups the current user may send a message to",
	 *     tags={"Messenger"},
	 *     @OA\Response(
	 *         response=200,
	 *         description="Group list",
	 *         @OA\JsonContent(
	 *             type="object",
	 *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/MessengerGroup"))
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=403,
	 *         description="Access not permitted",
	 *         @OA\JsonContent(ref="#/components/schemas/MessengerErrorResponse")
	 *     ),
	 *     @OA\Response(
	 *         response=401,
	 *         description="Unauthorized",
	 *         @OA\JsonContent(ref="#/components/schemas/MessengerErrorResponse")
	 *     )
	 * )
	 */
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

	/**
	 * POST /messenger/messages/groups
	 *
	 * @OA\Post(
	 *     path="/messenger/messages/groups",
	 *     summary="Send a message to every member of one or more account groups",
	 *     tags={"Messenger"},
	 *     @OA\RequestBody(
	 *         required=true,
	 *         @OA\JsonContent(ref="#/components/schemas/MessengerGroupSendRequest")
	 *     ),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Sent",
	 *         @OA\JsonContent(
	 *             type="object",
	 *             @OA\Property(property="sent", type="boolean"),
	 *             @OA\Property(property="receipt", type="object")
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=403,
	 *         description="Access not permitted or no permitted groups selected",
	 *         @OA\JsonContent(ref="#/components/schemas/MessengerErrorResponse")
	 *     ),
	 *     @OA\Response(
	 *         response=422,
	 *         description="Validation error",
	 *         @OA\JsonContent(ref="#/components/schemas/MessengerErrorResponse")
	 *     ),
	 *     @OA\Response(
	 *         response=401,
	 *         description="Unauthorized",
	 *         @OA\JsonContent(ref="#/components/schemas/MessengerErrorResponse")
	 *     )
	 * )
	 */
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

	/**
	 * POST /messenger/messages/global
	 *
	 * @OA\Post(
	 *     path="/messenger/messages/global",
	 *     summary="Send a message to every account (admin/compose_global permission required)",
	 *     tags={"Messenger"},
	 *     @OA\RequestBody(
	 *         required=true,
	 *         @OA\JsonContent(ref="#/components/schemas/MessengerGlobalSendRequest")
	 *     ),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Sent",
	 *         @OA\JsonContent(type="object", @OA\Property(property="sent", type="boolean"))
	 *     ),
	 *     @OA\Response(
	 *         response=403,
	 *         description="Access not permitted",
	 *         @OA\JsonContent(ref="#/components/schemas/MessengerErrorResponse")
	 *     ),
	 *     @OA\Response(
	 *         response=422,
	 *         description="Validation error",
	 *         @OA\JsonContent(ref="#/components/schemas/MessengerErrorResponse")
	 *     ),
	 *     @OA\Response(
	 *         response=401,
	 *         description="Unauthorized",
	 *         @OA\JsonContent(ref="#/components/schemas/MessengerErrorResponse")
	 *     )
	 * )
	 */
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

	/**
	 * POST /messenger/messages
	 *
	 * @OA\Post(
	 *     path="/messenger/messages",
	 *     summary="Send a message to a single recipient (or list the inbox for DataTables server-side POST requests)",
	 *     tags={"Messenger"},
	 *     @OA\RequestBody(
	 *         required=true,
	 *         @OA\JsonContent(ref="#/components/schemas/MessengerSendRequest")
	 *     ),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Sent",
	 *         @OA\JsonContent(type="object", @OA\Property(property="sent", type="boolean"))
	 *     ),
	 *     @OA\Response(
	 *         response=422,
	 *         description="Validation error",
	 *         @OA\JsonContent(ref="#/components/schemas/MessengerErrorResponse")
	 *     ),
	 *     @OA\Response(
	 *         response=401,
	 *         description="Unauthorized",
	 *         @OA\JsonContent(ref="#/components/schemas/MessengerErrorResponse")
	 *     )
	 * )
	 */
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

	/**
	 * POST /messenger/messages/{id}/reply
	 *
	 * @OA\Post(
	 *     path="/messenger/messages/{id}/reply",
	 *     summary="Reply to a message and mark the original as replied",
	 *     tags={"Messenger"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\RequestBody(
	 *         required=true,
	 *         @OA\JsonContent(ref="#/components/schemas/MessengerReplyRequest")
	 *     ),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Sent",
	 *         @OA\JsonContent(type="object", @OA\Property(property="sent", type="boolean"))
	 *     ),
	 *     @OA\Response(
	 *         response=422,
	 *         description="Validation error",
	 *         @OA\JsonContent(ref="#/components/schemas/MessengerErrorResponse")
	 *     ),
	 *     @OA\Response(
	 *         response=401,
	 *         description="Unauthorized",
	 *         @OA\JsonContent(ref="#/components/schemas/MessengerErrorResponse")
	 *     )
	 * )
	 */
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

	/**
	 * POST /messenger/messages/{id}/forward
	 *
	 * @OA\Post(
	 *     path="/messenger/messages/{id}/forward",
	 *     summary="Forward a message to another user and mark the original as forwarded",
	 *     tags={"Messenger"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\RequestBody(
	 *         required=true,
	 *         @OA\JsonContent(ref="#/components/schemas/MessengerForwardRequest")
	 *     ),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Sent",
	 *         @OA\JsonContent(type="object", @OA\Property(property="sent", type="boolean"))
	 *     ),
	 *     @OA\Response(
	 *         response=422,
	 *         description="Validation error",
	 *         @OA\JsonContent(ref="#/components/schemas/MessengerErrorResponse")
	 *     ),
	 *     @OA\Response(
	 *         response=401,
	 *         description="Unauthorized",
	 *         @OA\JsonContent(ref="#/components/schemas/MessengerErrorResponse")
	 *     )
	 * )
	 */
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

	/**
	 * DELETE /messenger/messages
	 *
	 * @OA\Delete(
	 *     path="/messenger/messages",
	 *     summary="Delete one or more messages owned by the current user",
	 *     tags={"Messenger"},
	 *     @OA\RequestBody(
	 *         required=true,
	 *         @OA\JsonContent(ref="#/components/schemas/MessengerDeleteRequest")
	 *     ),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Deleted",
	 *         @OA\JsonContent(
	 *             type="object",
	 *             @OA\Property(property="deleted", type="array", @OA\Items(type="integer"))
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=422,
	 *         description="No message ids supplied",
	 *         @OA\JsonContent(ref="#/components/schemas/MessengerErrorResponse")
	 *     ),
	 *     @OA\Response(
	 *         response=401,
	 *         description="Unauthorized",
	 *         @OA\JsonContent(ref="#/components/schemas/MessengerErrorResponse")
	 *     )
	 * )
	 */
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