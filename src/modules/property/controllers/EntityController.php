<?php

namespace App\modules\property\controllers;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;
use Sanitizer;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="Entity",
 *     description="REST API for EAV-based entity item records"
 * )
 *
 * @OA\Schema(
 *     schema="EntityItem",
 *     type="object",
 *     description="A single entity item (EAV record)",
 *     @OA\Property(property="id", type="integer", description="Item ID"),
 *     @OA\Property(property="entity_id", type="integer", description="Entity definition ID"),
 *     @OA\Property(property="cat_id", type="integer", description="Category ID within the entity"),
 *     @OA\AdditionalProperties(@OA\Schema(type="string"))
 * )
 *
 * @OA\Schema(
 *     schema="EntityReceipt",
 *     type="object",
 *     description="Save operation receipt",
 *     @OA\Property(property="id", type="integer", description="ID of the saved item"),
 *     @OA\Property(property="message", type="array", @OA\Items(type="object"), description="Success messages"),
 *     @OA\Property(property="error", type="array", @OA\Items(type="object"), description="Error messages")
 * )
 */
class EntityController
{
	public function __construct(ContainerInterface $container)
	{
		// Container available for future DI needs (ACL, settings, etc.)
	}

	/**
	 * Resolve and load property_boentity for the given route args.
	 *
	 * @param array $args Slim route args containing type, entity_id, cat_id.
	 * @return \property_boentity
	 */
	protected function bo(array $args): \property_boentity
	{
		include_class('property', 'boentity');
		return \property_boentity::forType(
			(string)$args['type'],
			(int)$args['entity_id'],
			(int)$args['cat_id']
		);
	}

	/**
	 * Instantiate a property_controller_helper for the current entity context.
	 *
	 * @param array $args Slim route args containing type, entity_id, cat_id.
	 * @return \property_controller_helper
	 */
	private function controllerHelper(array $args): \property_controller_helper
	{
		include_class('property', 'controller_helper');
		$bo = $this->bo($args);
		return new \property_controller_helper([
			'acl_read' => true,
			'type_app' => $bo->type_app,
			'type'     => (string)$args['type'],
		]);
	}

	/**
	 * Write a JSON response.
	 *
	 * @param Response $response
	 * @param mixed    $data
	 * @return Response
	 */
	private function jsonResponse(Response $response, mixed $data): Response
	{
		$response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
		return $response->withHeader('Content-Type', 'application/json');
	}

	/**
	 * @OA\Get(
	 *     path="/property/entity/{type}/{entity_id}/{cat_id}",
	 *     summary="List entity items",
	 *     description="Returns a paginated list of entity items for the given type/entity/category combination.",
	 *     tags={"Entity"},
	 *     @OA\Parameter(name="type", in="path", required=true, description="Entity type key", @OA\Schema(type="string")),
	 *     @OA\Parameter(name="entity_id", in="path", required=true, description="Entity definition ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="cat_id", in="path", required=true, description="Category ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="start", in="query", required=false, description="Pagination offset", @OA\Schema(type="integer", default=0)),
	 *     @OA\Parameter(name="query", in="query", required=false, description="Free-text search", @OA\Schema(type="string")),
	 *     @OA\Parameter(name="filter", in="query", required=false, description="Status/category filter", @OA\Schema(type="string")),
	 *     @OA\Parameter(name="sort", in="query", required=false, description="Column to sort by", @OA\Schema(type="string")),
	 *     @OA\Parameter(name="dir", in="query", required=false, description="Sort direction", @OA\Schema(type="string", enum={"ASC", "DESC"}, default="ASC")),
	 *     @OA\Parameter(name="allrows", in="query", required=false, description="Return all rows without pagination", @OA\Schema(type="boolean", default=false)),
	 *     @OA\Response(
	 *         response=200,
	 *         description="List of entity items",
	 *         @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/EntityItem"))
	 *     )
	 * )
	 */
	public function index(Request $request, Response $response, array $args): Response
	{
		$bo = $this->bo($args);

		$params = $request->getQueryParams();
		$bo->start      = isset($params['start'])   ? (int)$params['start']   : 0;
		$bo->query      = Sanitizer::clean_value($params['query']  ?? '', 'string');
		$bo->filter     = Sanitizer::clean_value($params['filter'] ?? '', 'string');
		$bo->sort       = Sanitizer::clean_value($params['sort']   ?? '', 'string');
		$bo->order      = Sanitizer::clean_value($params['dir']    ?? '', 'string');
		$bo->allrows    = isset($params['allrows']) && $params['allrows'] === 'true';

		$items = $bo->read();

		$response->getBody()->write(json_encode($items, JSON_THROW_ON_ERROR));
		return $response->withHeader('Content-Type', 'application/json');
	}

	/**
	 * @OA\Get(
	 *     path="/property/entity/{type}/{entity_id}/{cat_id}/{id}",
	 *     summary="Get a single entity item",
	 *     description="Returns a single entity item with its full set of EAV attributes.",
	 *     tags={"Entity"},
	 *     @OA\Parameter(name="type", in="path", required=true, description="Entity type key", @OA\Schema(type="string")),
	 *     @OA\Parameter(name="entity_id", in="path", required=true, description="Entity definition ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="cat_id", in="path", required=true, description="Category ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="id", in="path", required=true, description="Item ID", @OA\Schema(type="integer")),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Entity item with attributes",
	 *         @OA\JsonContent(ref="#/components/schemas/EntityItem")
	 *     ),
	 *     @OA\Response(response=400, description="Invalid id"),
	 *     @OA\Response(response=404, description="Entity item not found")
	 * )
	 */
	public function show(Request $request, Response $response, array $args): Response
	{
		$id = (int)$args['id'];
		if ($id <= 0)
		{
			throw new HttpBadRequestException($request, 'Invalid id');
		}

		$bo   = $this->bo($args);
		$item = $bo->read_single(['id' => $id]);

		if (empty($item))
		{
			throw new HttpNotFoundException($request, 'Entity item not found');
		}

		$response->getBody()->write(json_encode($item, JSON_THROW_ON_ERROR));
		return $response->withHeader('Content-Type', 'application/json');
	}

	/**
	 * @OA\Post(
	 *     path="/property/entity/{type}/{entity_id}/{cat_id}",
	 *     summary="Create a new entity item",
	 *     description="Creates a new entity item with optional EAV attribute values.",
	 *     tags={"Entity"},
	 *     @OA\Parameter(name="type", in="path", required=true, description="Entity type key", @OA\Schema(type="string")),
	 *     @OA\Parameter(name="entity_id", in="path", required=true, description="Entity definition ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="cat_id", in="path", required=true, description="Category ID", @OA\Schema(type="integer")),
	 *     @OA\RequestBody(
	 *         required=true,
	 *         @OA\JsonContent(
	 *             type="object",
	 *             @OA\Property(property="values", type="object", description="Core field values"),
	 *             @OA\Property(property="values_attribute", type="object", description="EAV attribute values keyed by attribute ID")
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=201,
	 *         description="Item created",
	 *         @OA\JsonContent(ref="#/components/schemas/EntityReceipt")
	 *     )
	 * )
	 */
	public function store(Request $request, Response $response, array $args): Response
	{
		$body = (array)($request->getParsedBody() ?? []);
		$values           = (array)($body['values']           ?? []);
		$values_attribute = (array)($body['values_attribute'] ?? []);

		$bo      = $this->bo($args);
		$receipt = $bo->save($values, $values_attribute, 'add', (int)$args['entity_id'], (int)$args['cat_id']);

		$response->getBody()->write(json_encode($receipt, JSON_THROW_ON_ERROR));
		return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
	}

	/**
	 * @OA\Put(
	 *     path="/property/entity/{type}/{entity_id}/{cat_id}/{id}",
	 *     summary="Update an entity item",
	 *     description="Updates core fields and/or EAV attribute values of an existing entity item.",
	 *     tags={"Entity"},
	 *     @OA\Parameter(name="type", in="path", required=true, description="Entity type key", @OA\Schema(type="string")),
	 *     @OA\Parameter(name="entity_id", in="path", required=true, description="Entity definition ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="cat_id", in="path", required=true, description="Category ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="id", in="path", required=true, description="Item ID to update", @OA\Schema(type="integer")),
	 *     @OA\RequestBody(
	 *         required=true,
	 *         @OA\JsonContent(
	 *             type="object",
	 *             @OA\Property(property="values", type="object", description="Core field values"),
	 *             @OA\Property(property="values_attribute", type="object", description="EAV attribute values keyed by attribute ID")
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Item updated",
	 *         @OA\JsonContent(ref="#/components/schemas/EntityReceipt")
	 *     ),
	 *     @OA\Response(response=400, description="Invalid id")
	 * )
	 */
	public function update(Request $request, Response $response, array $args): Response
	{
		$id = (int)$args['id'];
		if ($id <= 0)
		{
			throw new HttpBadRequestException($request, 'Invalid id');
		}

		$body = (array)($request->getParsedBody() ?? []);
		$values           = (array)($body['values']           ?? []);
		$values_attribute = (array)($body['values_attribute'] ?? []);
		$values['id']     = $id;

		$bo      = $this->bo($args);
		$receipt = $bo->save($values, $values_attribute, 'edit', (int)$args['entity_id'], (int)$args['cat_id']);

		$response->getBody()->write(json_encode($receipt, JSON_THROW_ON_ERROR));
		return $response->withHeader('Content-Type', 'application/json');
	}

	/**
	 * @OA\Delete(
	 *     path="/property/entity/{type}/{entity_id}/{cat_id}/{id}",
	 *     summary="Delete an entity item",
	 *     description="Permanently deletes the specified entity item.",
	 *     tags={"Entity"},
	 *     @OA\Parameter(name="type", in="path", required=true, description="Entity type key", @OA\Schema(type="string")),
	 *     @OA\Parameter(name="entity_id", in="path", required=true, description="Entity definition ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="cat_id", in="path", required=true, description="Category ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="id", in="path", required=true, description="Item ID to delete", @OA\Schema(type="integer")),
	 *     @OA\Response(response=204, description="Deleted successfully"),
	 *     @OA\Response(response=400, description="Invalid id")
	 * )
	 */
	public function destroy(Request $request, Response $response, array $args): Response
	{
		$id = (int)$args['id'];
		if ($id <= 0)
		{
			throw new HttpBadRequestException($request, 'Invalid id');
		}

		$this->bo($args)->delete($id);

		return $response->withStatus(204);
	}

	// ── Subsidiary data endpoints ─────────────────────────────────────────────

	/**
	 * Return entity items matching a QR code.
	 *
	 * GET /property/entity/{type}/{entity_id}/{cat_id}/items-per-qr?qr_code=…
	 */
	public function getItemsPerQr(Request $request, Response $response, array $args): Response
	{
		$qr_code = Sanitizer::clean_value($request->getQueryParams()['qr_code'] ?? '', 'string');
		$items   = $this->bo($args)->get_items_per_qr($qr_code);
		return $this->jsonResponse($response, $items);
	}

	/**
	 * Return related entity links for a given item.
	 *
	 * GET /property/entity/{type}/{entity_id}/{cat_id}/{id}/related
	 */
	public function getRelated(Request $request, Response $response, array $args): Response
	{
		$params  = $request->getQueryParams();
		$id      = (int)$args['id'];
		$draw    = (int)($params['draw'] ?? 0);
		$bo      = $this->bo($args);

		$related = $bo->read_entity_to_link([
			'entity_id' => (int)$args['entity_id'],
			'cat_id'    => (int)$args['cat_id'],
			'id'        => $id,
		]);

		$values = [];
		foreach ($related as $related_data)
		{
			if (is_array($related_data))
			{
				foreach ($related_data as $entry)
				{
					$values[] = [
						'name'        => $entry['name'] ?? '',
						'entity_link' => $entry['entity_link'] ?? '',
					];
				}
			}
		}

		return $this->jsonResponse($response, [
			'data'            => $values,
			'recordsTotal'    => count($values),
			'recordsFiltered' => count($values),
			'draw'            => $draw,
		]);
	}

	/**
	 * Return attached files for a given item.
	 *
	 * GET /property/entity/{type}/{entity_id}/{cat_id}/{id}/files
	 */
	public function getFiles(Request $request, Response $response, array $args): Response
	{
		$params = $request->getQueryParams();
		$id     = (int)$args['id'];
		$draw   = (int)($params['draw'] ?? 0);
		$bo     = $this->bo($args);

		$item  = $bo->read_single([
			'entity_id' => (int)$args['entity_id'],
			'cat_id'    => (int)$args['cat_id'],
			'type'      => (string)$args['type'],
			'id'        => $id,
		]);

		$files = array_values($item['files'] ?? []);

		return $this->jsonResponse($response, [
			'data'            => $files,
			'recordsTotal'    => count($files),
			'recordsFiltered' => count($files),
			'draw'            => $draw,
		]);
	}

	/**
	 * Return inventory records for a given item.
	 *
	 * GET /property/entity/{type}/{entity_id}/{cat_id}/{id}/inventory
	 */
	public function getInventory(Request $request, Response $response, array $args): Response
	{
		$params      = $request->getQueryParams();
		$id          = (int)$args['id'];
		$draw        = (int)($params['draw'] ?? 0);
		$bo          = $this->bo($args);

		// Resolve the system location_id for this entity/category path.
		$type_app    = $bo->type_app[$bo->type] ?? '';
		$location_id = $bo->locations_obj->get_id(
			$type_app,
			".{$args['type']}.{$args['entity_id']}.{$args['cat_id']}"
		);

		$values = $bo->get_inventory(['id' => $id, 'location_id' => (int)$location_id]);

		return $this->jsonResponse($response, [
			'data'            => array_values($values),
			'recordsTotal'    => count($values),
			'recordsFiltered' => count($values),
			'draw'            => $draw,
		]);
	}

	/**
	 * Return controller cases linked to this entity item.
	 *
	 * GET /property/entity/{type}/{entity_id}/{cat_id}/cases?id=…&location_id=…&year=…
	 */
	public function getCases(Request $request, Response $response, array $args): Response
	{
		$_GET['phpgw_return_as'] = 'json';
		$result = $this->controllerHelper($args)->get_cases();
		return $this->jsonResponse($response, $result);
	}

	/**
	 * Return controller checklists linked to this entity item.
	 *
	 * GET /property/entity/{type}/{entity_id}/{cat_id}/checklists?id=…&location_id=…&year=…
	 */
	public function getChecklists(Request $request, Response $response, array $args): Response
	{
		$_GET['phpgw_return_as'] = 'json';
		$result = $this->controllerHelper($args)->get_checklists();
		return $this->jsonResponse($response, $result);
	}

	/**
	 * Return controller controls attached to this entity component.
	 *
	 * GET /property/entity/{type}/{entity_id}/{cat_id}/controls?id=…&location_id=…
	 */
	public function getControlsAtComponent(Request $request, Response $response, array $args): Response
	{
		$_GET['phpgw_return_as'] = 'json';
		$result = $this->controllerHelper($args)->get_controls_at_component();
		return $this->jsonResponse($response, $result);
	}

	/**
	 * Return cases belonging to a specific checklist.
	 *
	 * GET /property/entity/{type}/{entity_id}/{cat_id}/cases-for-checklist?check_list_id=…
	 */
	public function getCasesForChecklist(Request $request, Response $response, array $args): Response
	{
		$_GET['phpgw_return_as'] = 'json';
		$result = $this->controllerHelper($args)->get_cases_for_checklist();
		return $this->jsonResponse($response, $result);
	}
}
