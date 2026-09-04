<?php

namespace App\modules\notes\controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\helpers\ResponseHelper;
use App\modules\phpgwapi\controllers\Accounts\Accounts;
use App\modules\phpgwapi\security\Acl;
use App\modules\phpgwapi\services\Settings;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="Notes",
 *     description="REST API for user notes"
 * )
 *
 * @OA\Schema(
 *     schema="NoteSummary",
 *     type="object",
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="first", type="string"),
 *     @OA\Property(property="content", type="string"),
 *     @OA\Property(property="date", type="string"),
 *     @OA\Property(property="owner", type="string"),
 *     @OA\Property(property="owner_id", type="integer"),
 *     @OA\Property(property="access", type="string"),
 *     @OA\Property(property="cat_id", type="integer"),
 *     @OA\Property(property="cat_name", type="string")
 * )
 *
 * @OA\Schema(
 *     schema="NoteDetail",
 *     type="object",
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="content", type="string"),
 *     @OA\Property(property="access", type="string"),
 *     @OA\Property(property="date", type="string"),
 *     @OA\Property(property="date_formatted", type="string"),
 *     @OA\Property(property="cat_id", type="integer"),
 *     @OA\Property(property="cat_name", type="string"),
 *     @OA\Property(property="owner_id", type="integer"),
 *     @OA\Property(property="owner_name", type="string")
 * )
 *
 * @OA\Schema(
 *     schema="NoteStoreRequest",
 *     type="object",
 *     required={"content"},
 *     @OA\Property(property="content", type="string"),
 *     @OA\Property(property="cat_id", type="integer"),
 *     @OA\Property(property="access", type="string")
 * )
 *
 * @OA\Schema(
 *     schema="NoteCategory",
 *     type="object",
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="name", type="string")
 * )
 *
 * @OA\Schema(
 *     schema="NoteErrorResponse",
 *     type="object",
 *     @OA\Property(property="error", type="string"),
 *     @OA\Property(property="errors", type="array", @OA\Items(type="string"))
 * )
 */
class NotesController
{
	private function businessObject()
	{
		return \CreateObject('notes.bonotes', true);
	}

	private function payload(Request $request): array
	{
		$payload = $request->getParsedBody();
		if (!is_array($payload))
		{
			$raw = (string) $request->getBody();
			if ($raw !== '')
			{
				$decoded = json_decode($raw, true);
				if (is_array($decoded))
				{
					$payload = $decoded;
				}
			}
		}

		return is_array($payload) ? $payload : [];
	}

	/**
	 * GET /notes/notes
	 *
	 * @OA\Get(
	 *     path="/notes/notes",
	 *     summary="List notes (supports DataTables server-side pagination/sorting)",
	 *     tags={"Notes"},
	 *     @OA\Parameter(name="start", in="query", @OA\Schema(type="integer", default=0)),
	 *     @OA\Parameter(name="limit", in="query", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="search", in="query", @OA\Schema(type="string")),
	 *     @OA\Parameter(name="sort", in="query", @OA\Schema(type="string", enum={"note_id", "note_date", "note_content"})),
	 *     @OA\Parameter(name="dir", in="query", @OA\Schema(type="string", enum={"ASC", "DESC"}, default="DESC")),
	 *     @OA\Parameter(name="filter", in="query", @OA\Schema(type="string", enum={"none", "yours", "private"})),
	 *     @OA\Parameter(name="cat_id", in="query", @OA\Schema(type="integer")),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Notes list",
	 *         @OA\JsonContent(
	 *             type="object",
	 *             @OA\Property(property="items", type="array", @OA\Items(ref="#/components/schemas/NoteSummary")),
	 *             @OA\Property(property="total", type="integer")
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=401,
	 *         description="Unauthorized",
	 *         @OA\JsonContent(ref="#/components/schemas/NoteErrorResponse")
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
		$columnKey = (string) ($columns[$columnIndex]['data'] ?? $query['sort'] ?? 'note_date');
		$direction = strtoupper((string) ($order['dir'] ?? $body['dir'] ?? $query['dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

		$filter = (string) ($body['filter'] ?? $query['filter'] ?? 'none');
		$catId = (int) ($body['cat_id'] ?? $query['cat_id'] ?? 0);

		$sortKeys = [
			'id' => 'note_id',
			'note_id' => 'note_id',
			'date' => 'note_date',
			'note_date' => 'note_date',
			'first' => 'note_content',
			'content' => 'note_content',
		];
		$sortKey = $sortKeys[$columnKey] ?? 'note_date';

		$bo = $this->businessObject();
		$bo->start = $start;
		$bo->limit = $limit > 0 ? $limit : true;
		$bo->query = (string) $search;
		$bo->sort = $direction;
		$bo->order = $sortKey;
		$bo->filter = $filter;
		$bo->cat_id = $catId;

		$notesList = (array) $bo->read();
		$catsObj = \CreateObject('phpgwapi.categories', -1, 'notes');

		$items = [];
		foreach ($notesList as $note)
		{
			$content = (string) ($note['content'] ?? '');
			$words = explode(' ', \phpgw::strip_html($content), 10);
			$first = implode(' ', $words);
			if (count($words) > 10)
			{
				$first .= ' ...';
			}

			$cat_id = (int) ($note['cat_id'] ?? 0);
			$cat_name = $cat_id ? (string) $catsObj->id2name($cat_id) : lang('unfiled');

			$items[] = [
				'id' => (int) ($note['note_id'] ?? 0),
				'first' => $first,
				'content' => $content,
				'date' => (string) ($note['date'] ?? ''),
				'owner' => (string) ($note['owner'] ?? ''),
				'owner_id' => (int) ($note['owner_id'] ?? $note['owner'] ?? 0),
				'access' => (string) ($note['access'] ?? 'public'),
				'cat_id' => $cat_id,
				'cat_name' => $cat_name,
			];
		}

		$total = (int) $bo->total_records;

		if (isset($body['draw']) || isset($query['draw']))
		{
			return ResponseHelper::sendJSONResponse([
				'draw' => (int) ($body['draw'] ?? $query['draw'] ?? 0),
				'recordsTotal' => $total,
				'recordsFiltered' => $total,
				'data' => $items,
			], 200, $response);
		}

		return ResponseHelper::sendJSONResponse(['items' => $items, 'total' => $total], 200, $response);
	}

	/**
	 * GET /notes/notes/{id}
	 *
	 * @OA\Get(
	 *     path="/notes/notes/{id}",
	 *     summary="Get details of a single note",
	 *     tags={"Notes"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Note details",
	 *         @OA\JsonContent(ref="#/components/schemas/NoteDetail")
	 *     ),
	 *     @OA\Response(
	 *         response=404,
	 *         description="Note not found",
	 *         @OA\JsonContent(ref="#/components/schemas/NoteErrorResponse")
	 *     )
	 * )
	 */
	public function show(Request $request, Response $response, array $args): Response
	{
		$id = (int) ($args['id'] ?? 0);
		$bo = $this->businessObject();
		$note = $bo->read_single($id);

		if (!is_array($note) || empty($note))
		{
			return ResponseHelper::sendJSONResponse(['error' => 'Note not found'], 404, $response);
		}

		$catsObj = \CreateObject('phpgwapi.categories', -1, 'notes');
		$accountsObj = new Accounts();

		$catId = (int) ($note['cat_id'] ?? 0);
		$ownerId = (int) ($note['owner'] ?? 0);
		$phpgwapiCommon = new \phpgwapi_common();

		$data = [
			'id' => (int) ($note['id'] ?? $id),
			'content' => (string) ($note['content'] ?? ''),
			'access' => (string) ($note['access'] ?? 'public'),
			'date' => (string) ($note['date'] ?? ''),
			'date_formatted' => $phpgwapiCommon->show_date($note['date'] ?? 0),
			'cat_id' => $catId,
			'cat_name' => $catId ? (string) $catsObj->id2name($catId) : lang('unfiled'),
			'owner_id' => $ownerId,
			'owner_name' => $ownerId ? (string) $accountsObj->id2name($ownerId) : '',
		];

		return ResponseHelper::sendJSONResponse($data, 200, $response);
	}

	/**
	 * POST /notes/notes
	 *
	 * @OA\Post(
	 *     path="/notes/notes",
	 *     summary="Create a new note",
	 *     tags={"Notes"},
	 *     @OA\RequestBody(
	 *         required=true,
	 *         @OA\JsonContent(ref="#/components/schemas/NoteStoreRequest")
	 *     ),
	 *     @OA\Response(
	 *         response=201,
	 *         description="Note created",
	 *         @OA\JsonContent(
	 *             type="object",
	 *             @OA\Property(property="id", type="integer"),
	 *             @OA\Property(property="created", type="boolean")
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=422,
	 *         description="Validation error",
	 *         @OA\JsonContent(ref="#/components/schemas/NoteErrorResponse")
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
		$content = trim((string) ($payload['content'] ?? $payload['note_content'] ?? ''));
		if ($content === '')
		{
			return ResponseHelper::sendJSONResponse(['error' => 'Content is required'], 422, $response);
		}

		$catId = (int) ($payload['cat_id'] ?? 0);
		$access = !empty($payload['access']) ? 'private' : 'public';

		$bo = $this->businessObject();
		$noteId = $bo->save([
			'content' => $content,
			'cat_id' => $catId,
			'access' => $access,
		]);

		return ResponseHelper::sendJSONResponse(['id' => (int) $noteId, 'created' => true], 201, $response);
	}

	/**
	 * PUT /notes/notes/{id}
	 *
	 * @OA\Put(
	 *     path="/notes/notes/{id}",
	 *     summary="Update an existing note",
	 *     tags={"Notes"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\RequestBody(
	 *         required=true,
	 *         @OA\JsonContent(ref="#/components/schemas/NoteStoreRequest")
	 *     ),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Note updated",
	 *         @OA\JsonContent(
	 *             type="object",
	 *             @OA\Property(property="id", type="integer"),
	 *             @OA\Property(property="updated", type="boolean")
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=404,
	 *         description="Note not found",
	 *         @OA\JsonContent(ref="#/components/schemas/NoteErrorResponse")
	 *     ),
	 *     @OA\Response(
	 *         response=422,
	 *         description="Validation error",
	 *         @OA\JsonContent(ref="#/components/schemas/NoteErrorResponse")
	 *     )
	 * )
	 */
	public function update(Request $request, Response $response, array $args): Response
	{
		$id = (int) ($args['id'] ?? 0);
		$bo = $this->businessObject();
		$existing = $bo->read_single($id);

		if (!is_array($existing) || empty($existing))
		{
			return ResponseHelper::sendJSONResponse(['error' => 'Note not found'], 404, $response);
		}

		$payload = $this->payload($request);
		$content = trim((string) ($payload['content'] ?? $payload['note_content'] ?? ''));
		if ($content === '')
		{
			return ResponseHelper::sendJSONResponse(['error' => 'Content is required'], 422, $response);
		}

		$catId = (int) ($payload['cat_id'] ?? $existing['cat_id'] ?? 0);
		$access = isset($payload['access']) ? (($payload['access'] === 'private' || !empty($payload['access'])) && $payload['access'] !== 'public' ? 'private' : 'public') : ($existing['access'] ?? 'public');

		$bo->save([
			'note_id' => $id,
			'content' => $content,
			'cat_id' => $catId,
			'access' => $access,
		]);

		return ResponseHelper::sendJSONResponse(['id' => $id, 'updated' => true], 200, $response);
	}

	/**
	 * DELETE /notes/notes/{id}
	 *
	 * @OA\Delete(
	 *     path="/notes/notes/{id}",
	 *     summary="Delete a note",
	 *     tags={"Notes"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Note deleted",
	 *         @OA\JsonContent(
	 *             type="object",
	 *             @OA\Property(property="deleted", type="boolean")
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=404,
	 *         description="Note not found",
	 *         @OA\JsonContent(ref="#/components/schemas/NoteErrorResponse")
	 *     )
	 * )
	 */
	public function destroy(Request $request, Response $response, array $args): Response
	{
		$id = (int) ($args['id'] ?? 0);
		$bo = $this->businessObject();
		$existing = $bo->read_single($id);

		if (!is_array($existing) || empty($existing))
		{
			return ResponseHelper::sendJSONResponse(['error' => 'Note not found'], 404, $response);
		}

		$bo->delete($id);

		return ResponseHelper::sendJSONResponse(['deleted' => true], 200, $response);
	}

	/**
	 * GET /notes/categories
	 *
	 * @OA\Get(
	 *     path="/notes/categories",
	 *     summary="Get available note categories",
	 *     tags={"Notes"},
	 *     @OA\Response(
	 *         response=200,
	 *         description="Category list",
	 *         @OA\JsonContent(
	 *             type="object",
	 *             @OA\Property(property="items", type="array", @OA\Items(ref="#/components/schemas/NoteCategory"))
	 *         )
	 *     )
	 * )
	 */
	public function categories(Request $request, Response $response): Response
	{
		$cats = \CreateObject('phpgwapi.categories', -1, 'notes');
		$categories = (array) $cats->return_sorted_array(0, false, '', '', '', true, 0, false);

		$list = [
			['id' => 0, 'name' => lang('All')],
		];

		foreach ($categories as $category)
		{
			$list[] = [
				'id' => (int) ($category['id'] ?? 0),
				'name' => (string) ($category['name'] ?? ''),
			];
		}

		return ResponseHelper::sendJSONResponse(['items' => $list], 200, $response);
	}
}
