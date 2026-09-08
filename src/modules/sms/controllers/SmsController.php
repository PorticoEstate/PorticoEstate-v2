<?php

namespace App\modules\sms\controllers;

use App\helpers\ResponseHelper;
use App\modules\phpgwapi\security\Acl;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * @OA\Tag(
 *     name="SMS",
 *     description="REST API for the SMS gateway inbox/outbox"
 * )
 *
 * @OA\Schema(
 *     schema="SmsInboxMessage",
 *     type="object",
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="sender", type="string"),
 *     @OA\Property(property="user", type="string"),
 *     @OA\Property(property="message", type="string"),
 *     @OA\Property(property="entry_time", type="string")
 * )
 *
 * @OA\Schema(
 *     schema="SmsOutboxMessage",
 *     type="object",
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="receiver", type="string"),
 *     @OA\Property(property="user", type="string"),
 *     @OA\Property(property="dst_group", type="string"),
 *     @OA\Property(property="entry_time", type="string"),
 *     @OA\Property(property="status", type="string"),
 *     @OA\Property(property="message", type="string")
 * )
 *
 * @OA\Schema(
 *     schema="SmsSendRequest",
 *     type="object",
 *     required={"to", "message"},
 *     @OA\Property(property="to", type="string", description="Comma-separated recipient numbers"),
 *     @OA\Property(property="message", type="string"),
 *     @OA\Property(property="flash", type="boolean"),
 *     @OA\Property(property="unicode", type="boolean")
 * )
 *
 * @OA\Schema(
 *     schema="SmsErrorResponse",
 *     type="object",
 *     @OA\Property(property="error", type="string")
 * )
 */
class SmsController
{
	private function storageObject()
	{
		return \CreateObject('sms.sosms');
	}

	private function businessObject()
	{
		return \CreateObject('sms.bosms', false);
	}

	/**
	 * Parse DataTables server-side params (also accepts plain query params for non-grid callers).
	 */
	private function getListParams(Request $request): array
	{
		$query = $request->getQueryParams();
		$body = (array) ($request->getParsedBody() ?: []);

		$start = max(0, (int) ($body['start'] ?? $query['start'] ?? 0));
		$search = $body['search']['value'] ?? $body['search'] ?? $query['search'] ?? $query['query'] ?? '';
		$order = $body['order'][0] ?? [];
		$columns = (array) ($body['columns'] ?? []);
		$columnIndex = (int) ($order['column'] ?? -1);
		$columnKey = (string) ($columns[$columnIndex]['data'] ?? $query['sort'] ?? '');
		$direction = strtoupper((string) ($order['dir'] ?? $body['dir'] ?? $query['dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
		$draw = (int) ($body['draw'] ?? $query['draw'] ?? 0);

		return [
			'draw' => $draw,
			'start' => $start,
			'search' => (string) $search,
			'columnKey' => $columnKey,
			'dir' => $direction,
		];
	}

	private function formatEntryTime($rawValue): string
	{
		if ($rawValue === '' || $rawValue === null)
		{
			return '';
		}

		$phpgwapiCommon = new \phpgwapi_common();
		return (string) $phpgwapiCommon->show_date(strtotime((string) $rawValue));
	}

	/**
	 * GET|POST /sms/inbox
	 *
	 * @OA\Get(
	 *     path="/sms/inbox",
	 *     summary="List inbox messages (supports DataTables server-side params)",
	 *     tags={"SMS"},
	 *     @OA\Parameter(name="start", in="query", @OA\Schema(type="integer", default=0)),
	 *     @OA\Parameter(name="search", in="query", @OA\Schema(type="string")),
	 *     @OA\Parameter(name="sort", in="query", @OA\Schema(type="string", enum={"date", "sender"})),
	 *     @OA\Parameter(name="dir", in="query", @OA\Schema(type="string", enum={"ASC", "DESC"}, default="DESC")),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Inbox list",
	 *         @OA\JsonContent(
	 *             type="object",
	 *             @OA\Property(property="items", type="array", @OA\Items(ref="#/components/schemas/SmsInboxMessage")),
	 *             @OA\Property(property="total", type="integer")
	 *         )
	 *     ),
	 *     @OA\Response(response=403, description="Access denied", @OA\JsonContent(ref="#/components/schemas/SmsErrorResponse"))
	 * )
	 */
	public function inbox(Request $request, Response $response): Response
	{
		if (!Acl::getInstance()->check('.inbox', Acl::READ, 'sms'))
		{
			return ResponseHelper::sendErrorResponse(['error' => 'Access not permitted'], 403);
		}

		$mapSort = ['date' => 'in_datetime', 'sender' => 'in_sender'];
		$params = $this->getListParams($request);

		$so = $this->storageObject();
		$rows = (array) $so->read_inbox([
			'start' => $params['start'],
			'query' => $params['search'],
			'order' => $mapSort[$params['columnKey']] ?? '',
			'sort' => $params['dir'],
			'allrows' => false,
		]);
		$total = (int) $so->total_records;

		$items = [];
		foreach ($rows as $row)
		{
			$items[] = [
				'id' => (int) ($row['id'] ?? 0),
				'date' => $this->formatEntryTime($row['entry_time'] ?? ''),
				'sender' => (string) \phpgw::strip_html((string) ($row['sender'] ?? '')),
				'user' => (string) ($row['user'] ?? ''),
				'message' => (string) \phpgw::strip_html((string) ($row['message'] ?? '')),
			];
		}

		if ($params['draw'] > 0)
		{
			return ResponseHelper::sendJSONResponse([
				'draw' => $params['draw'],
				'recordsTotal' => $total,
				'recordsFiltered' => $total,
				'data' => $items,
			]);
		}

		return ResponseHelper::sendJSONResponse(['items' => $items, 'total' => $total]);
	}

	/**
	 * DELETE /sms/inbox/{id}
	 *
	 * @OA\Delete(
	 *     path="/sms/inbox/{id}",
	 *     summary="Delete an inbox message",
	 *     tags={"SMS"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="Deleted", @OA\JsonContent(type="object", @OA\Property(property="deleted", type="boolean"))),
	 *     @OA\Response(response=403, description="Access denied", @OA\JsonContent(ref="#/components/schemas/SmsErrorResponse")),
	 *     @OA\Response(response=400, description="Invalid request", @OA\JsonContent(ref="#/components/schemas/SmsErrorResponse"))
	 * )
	 */
	public function destroyInbox(Request $request, Response $response, array $args): Response
	{
		if (!Acl::getInstance()->check('.inbox', Acl::DELETE, 'sms'))
		{
			return ResponseHelper::sendErrorResponse(['error' => 'Access not permitted'], 403);
		}

		$id = (int) ($args['id'] ?? 0);
		if (!$id)
		{
			return ResponseHelper::sendErrorResponse(['error' => 'Missing message ID'], 400);
		}

		$this->businessObject()->delete_in($id);

		return ResponseHelper::sendJSONResponse(['deleted' => true]);
	}

	/**
	 * GET|POST /sms/outbox
	 *
	 * @OA\Get(
	 *     path="/sms/outbox",
	 *     summary="List outbox messages (supports DataTables server-side params)",
	 *     tags={"SMS"},
	 *     @OA\Parameter(name="start", in="query", @OA\Schema(type="integer", default=0)),
	 *     @OA\Parameter(name="search", in="query", @OA\Schema(type="string")),
	 *     @OA\Parameter(name="sort", in="query", @OA\Schema(type="string", enum={"date"})),
	 *     @OA\Parameter(name="dir", in="query", @OA\Schema(type="string", enum={"ASC", "DESC"}, default="DESC")),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Outbox list",
	 *         @OA\JsonContent(
	 *             type="object",
	 *             @OA\Property(property="items", type="array", @OA\Items(ref="#/components/schemas/SmsOutboxMessage")),
	 *             @OA\Property(property="total", type="integer")
	 *         )
	 *     ),
	 *     @OA\Response(response=403, description="Access denied", @OA\JsonContent(ref="#/components/schemas/SmsErrorResponse"))
	 * )
	 */
	public function outbox(Request $request, Response $response): Response
	{
		if (!Acl::getInstance()->check('.outbox', Acl::READ, 'sms'))
		{
			return ResponseHelper::sendErrorResponse(['error' => 'Access not permitted'], 403);
		}

		$mapSort = ['date' => 'p_datetime'];
		$params = $this->getListParams($request);

		$so = $this->storageObject();
		// acl_location enables the legacy grants2-based visibility filter (see sms_sosms::read_outbox).
		$rows = (array) $so->read_outbox([
			'start' => $params['start'],
			'query' => $params['search'],
			'order' => $mapSort[$params['columnKey']] ?? '',
			'sort' => $params['dir'],
			'allrows' => false,
			'acl_location' => '.outbox',
		]);
		$total = (int) $so->total_records;

		$items = [];
		foreach ($rows as $row)
		{
			$items[] = [
				'id' => (int) ($row['id'] ?? 0),
				'date' => $this->formatEntryTime($row['entry_time'] ?? ''),
				'receiver' => (string) \phpgw::strip_html((string) ($row['p_dst'] ?? '')),
				'user' => (string) ($row['user'] ?? ''),
				'dst_group' => (string) ($row['dst_group'] ?? ''),
				'status' => (string) ($row['status'] ?? ''),
				'message' => (string) \phpgw::strip_html((string) ($row['message'] ?? '')),
			];
		}

		if ($params['draw'] > 0)
		{
			return ResponseHelper::sendJSONResponse([
				'draw' => $params['draw'],
				'recordsTotal' => $total,
				'recordsFiltered' => $total,
				'data' => $items,
			]);
		}

		return ResponseHelper::sendJSONResponse(['items' => $items, 'total' => $total]);
	}

	/**
	 * DELETE /sms/outbox/{id}
	 *
	 * @OA\Delete(
	 *     path="/sms/outbox/{id}",
	 *     summary="Delete an outbox message",
	 *     tags={"SMS"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="Deleted", @OA\JsonContent(type="object", @OA\Property(property="deleted", type="boolean"))),
	 *     @OA\Response(response=403, description="Access denied", @OA\JsonContent(ref="#/components/schemas/SmsErrorResponse")),
	 *     @OA\Response(response=400, description="Invalid request", @OA\JsonContent(ref="#/components/schemas/SmsErrorResponse"))
	 * )
	 */
	public function destroyOutbox(Request $request, Response $response, array $args): Response
	{
		if (!Acl::getInstance()->check('.outbox', Acl::DELETE, 'sms'))
		{
			return ResponseHelper::sendErrorResponse(['error' => 'Access not permitted'], 403);
		}

		$id = (int) ($args['id'] ?? 0);
		if (!$id)
		{
			return ResponseHelper::sendErrorResponse(['error' => 'Missing message ID'], 400);
		}

		$this->businessObject()->delete_out($id);

		return ResponseHelper::sendJSONResponse(['deleted' => true]);
	}

	/**
	 * POST /sms/messages
	 *
	 * @OA\Post(
	 *     path="/sms/messages",
	 *     summary="Send an SMS to one or more recipients",
	 *     tags={"SMS"},
	 *     @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/SmsSendRequest")),
	 *     @OA\Response(
	 *         response=201,
	 *         description="Queued",
	 *         @OA\JsonContent(type="object", @OA\Property(property="messages", type="array", @OA\Items(type="string")))
	 *     ),
	 *     @OA\Response(response=400, description="Validation error", @OA\JsonContent(ref="#/components/schemas/SmsErrorResponse")),
	 *     @OA\Response(response=403, description="Access denied", @OA\JsonContent(ref="#/components/schemas/SmsErrorResponse"))
	 * )
	 */
	public function store(Request $request, Response $response): Response
	{
		if (!Acl::getInstance()->check('.outbox', Acl::ADD, 'sms'))
		{
			return ResponseHelper::sendErrorResponse(['error' => 'Access not permitted'], 403);
		}

		$data = $request->getParsedBody();
		if (!is_array($data))
		{
			$decoded = json_decode((string) $request->getBody(), true);
			$data = is_array($decoded) ? $decoded : [];
		}

		$to = trim((string) ($data['to'] ?? ''));
		$message = trim((string) ($data['message'] ?? ''));

		if ($to === '')
		{
			return ResponseHelper::sendErrorResponse(['error' => lang('Please enter a recipient !')], 400);
		}
		if ($message === '')
		{
			return ResponseHelper::sendErrorResponse(['error' => lang('Please enter a message !')], 400);
		}

		$recipients = array_values(array_filter(array_map('trim', explode(',', $to))));

		$values = [
			'p_num_text' => $recipients,
			'message' => $message,
			'msg_flash' => !empty($data['flash']) ? 'on' : '',
			'msg_unicode' => !empty($data['unicode']) ? 'on' : '',
		];

		$receipt = $this->businessObject()->send_sms($values);
		$messages = array_map(static function ($entry)
		{
			return (string) ($entry['msg'] ?? '');
		}, (array) ($receipt['message'] ?? []));

		return ResponseHelper::sendJSONResponse(['messages' => $messages], 201);
	}
}
