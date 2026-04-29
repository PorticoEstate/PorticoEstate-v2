<?php

namespace App\modules\property\controllers;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;
use Sanitizer;
use OpenApi\Annotations as OA;
use function include_class;

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
		if (defined('SRC_ROOT_PATH') && !function_exists('include_class'))
		{
			require_once SRC_ROOT_PATH . '/helpers/LegacyObjectHandler.php';
		}
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
		$context = $this->resolveAclContext($args);
		$bo = $context['bo'];
		$aclCheckLocation = $context['acl_check_location'];
		$app = $context['app'];
		return new \property_controller_helper([
			'acl_read' => $bo->acl->check($aclCheckLocation, ACL_READ, $app),
			'acl_add' => $bo->acl->check($aclCheckLocation, ACL_ADD, $app),
			'acl_edit' => $bo->acl->check($aclCheckLocation, ACL_EDIT, $app),
			'acl_delete' => $bo->acl->check($aclCheckLocation, ACL_DELETE, $app),
			'type_app' => $bo->type_app,
			'type'     => (string)$args['type'],
		]);
	}

	/**
	 * Resolve boentity and ACL scope equivalent to legacy uientity constructor logic.
	 *
	 * @param array $args Slim route args containing type, entity_id, cat_id.
	 * @return array{bo:\property_boentity, acl_check_location:string, app:string}
	 */
	private function resolveAclContext(array $args): array
	{
		$bo = $this->bo($args);

		$aclCheckLocation = $bo->acl_location;
		$config = CreateObject('phpgwapi.config', 'property')->read();
		if (!empty($config['bypass_acl_at_entity'])
			&& is_array($config['bypass_acl_at_entity'])
			&& in_array($bo->entity_id, $config['bypass_acl_at_entity']))
		{
			$aclCheckLocation = ".{$bo->type}.{$bo->entity_id}";
		}

		$app = $bo->type_app[$bo->type] ?? 'property';

		return [
			'bo' => $bo,
			'acl_check_location' => $aclCheckLocation,
			'app' => $app,
		];
	}

	/**
	 * Enforce entity/category ACL and return the loaded boentity instance.
	 *
	 * @param Request $request
	 * @param array $args
	 * @param int $aclType ACL_READ|ACL_ADD|ACL_EDIT|ACL_DELETE
	 * @param string $message Error message for forbidden access.
	 * @return \property_boentity
	 */
	private function assertEntityAcl(Request $request, array $args, int $aclType, string $message): \property_boentity
	{
		$context = $this->resolveAclContext($args);
		$bo = $context['bo'];

		if (!$bo->acl->check($context['acl_check_location'], $aclType, $context['app']))
		{
			throw new HttpForbiddenException($request, $message);
		}

		return $bo;
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
	 * Enrich a list of entity rows with image thumbnails and view links,
	 * mirroring the post-processing done in uientity::query().
	 *
	 * @param array            $rows         Rows returned by boentity::read().
	 * @param \property_boentity $bo         Loaded boentity instance.
	 * @return array                         Rows with file_name, img_id, img_url,
	 *                                       thumbnail_flag, and link added where applicable.
	 */
	private function enrichRows(array $rows, \property_boentity $bo): array
	{
		$img_types = ['image/jpeg', 'image/png', 'image/gif'];

		// Remote-image config (per-category admin setting)
		include_class('admin', 'soconfig');
		$locations       = new \App\modules\phpgwapi\controllers\Locations();
		$location_id     = $locations->get_id($bo->type_app[$bo->type], $bo->acl_location);
		$custom_config   = new \admin_soconfig($location_id);
		$config          = isset($custom_config->config_data) && $custom_config->config_data
			? $custom_config->config_data
			: [];

		$remote_image_in_table    = false;
		$remote_image_config      = [];
		foreach ($config as $_section_data)
		{
			if (!empty($_section_data['image_in_table']))
			{
				$remote_image_in_table = true;
				$remote_image_config   = $_section_data;
				break;
			}
		}

		if (!$remote_image_in_table)
		{
			$vfs              = new \App\modules\phpgwapi\services\Vfs\Vfs();
			$vfs->override_acl = 1;
		}

		$link_base = [
			'menuaction' => 'property.uientity.view',
			'entity_id'  => $bo->entity_id,
			'cat_id'     => $bo->cat_id,
			'type'       => $bo->type,
		];

		foreach ($rows as &$entry)
		{
			$loc1 = !empty($entry['loc1']) ? $entry['loc1'] : 'dummy';

			if ($remote_image_in_table)
			{
				$key                      = $remote_image_config['img_key_local'] ?? '';
				$entry['file_name']       = $entry[$key] ?? '';
				$entry['img_id']          = $entry[$key] ?? '';
				$entry['img_url']         = ($remote_image_config['url'] ?? '')
					. '&' . ($remote_image_config['img_key_remote'] ?? '')
					. '=' . $entry['img_id'];
				$entry['thumbnail_flag']  = $remote_image_config['thumbnail_flag'] ?? '';
			}
			else
			{
				$_files = $vfs->ls([
					'string'      => "/property/{$bo->category_dir}/{$loc1}/{$entry['id']}",
					'checksubdirs' => false,
					'relatives'    => [RELATIVE_NONE],
				]);

				if (!empty($_files[0]) && in_array($_files[0]['mime_type'], $img_types, true))
				{
					$entry['file_name']      = $_files[0]['name'];
					$entry['img_id']         = $_files[0]['file_id'];
					$entry['directory']      = $_files[0]['directory'];
					$entry['img_url']        = \phpgw::link('/index.php',[
						'menuaction' => 'property.uigallery.view_file',
						'file'       => $entry['directory'] . '/' . $entry['file_name'],
					]);
					$entry['thumbnail_flag'] = 'thumb=1';
				}
			}

			$entry['link'] = \phpgw::link('/index.php',
				array_merge($link_base, ['id' => $entry['id']])
			);
		}
		unset($entry);

		return $rows;
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
		$bo = $this->assertEntityAcl($request, $args, ACL_READ, 'No read access for this entity category');

		$body = (array)($request->getParsedBody() ?? []);

		// DataTables server-side POST protocol
		if (isset($body['draw']))
		{
			$draw    = (int)($body['draw'] ?? 1);
			$search  = is_array($body['search'] ?? null) ? $body['search'] : [];
			$order   = is_array($body['order']  ?? null) ? $body['order']  : [];
			$columns = is_array($body['columns'] ?? null) ? $body['columns'] : [];
			$length  = isset($body['length']) ? (int)$body['length'] : 0;

			$colIdx  = isset($order[0]['column']) ? (int)$order[0]['column'] : 0;
			$sortCol = isset($columns[$colIdx]['data'])
				? Sanitizer::clean_value($columns[$colIdx]['data'], 'string')
				: '';

			$readParams = [
				'start'   => isset($body['start']) ? (int)$body['start'] : 0,
				'results' => $length,
				'query'   => Sanitizer::clean_value($search['value'] ?? '', 'string'),
				'order'   => $sortCol,
				'sort'    => Sanitizer::clean_value($order[0]['dir'] ?? 'asc', 'string'),
				'allrows' => $length === -1,
				'filter'  => Sanitizer::clean_value($body['filter'] ?? '', 'string'),
			];

			$bo->allrows = $readParams['allrows'];
			$items = $bo->read($readParams);
			$items = $this->enrichRows((array)$items, $bo);

			$payload = [
				'draw'            => $draw,
				'recordsTotal'    => (int)$bo->total_records,
				'recordsFiltered' => (int)$bo->total_records,
				'data'            => $items,
			];
			$response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));
			return $response->withHeader('Content-Type', 'application/json');
		}

		// Plain GET: flat query params
		$params = $request->getQueryParams();
		$readParams = [
			'start'   => isset($params['start'])  ? (int)$params['start']  : 0,
			'query'   => Sanitizer::clean_value($params['query']  ?? '', 'string'),
			'filter'  => Sanitizer::clean_value($params['filter'] ?? '', 'string'),
			'order'   => Sanitizer::clean_value($params['sort']   ?? '', 'string'),
			'sort'    => Sanitizer::clean_value($params['dir']    ?? '', 'string'),
			'allrows' => isset($params['allrows']) && $params['allrows'] === 'true',
		];

		$bo->allrows = $readParams['allrows'];
		$items = $bo->read($readParams);
		$items = $this->enrichRows((array)$items, $bo);

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
		$bo = $this->assertEntityAcl($request, $args, ACL_READ, 'No read access for this entity category');

		$id = (int)$args['id'];
		if ($id <= 0)
		{
			throw new HttpBadRequestException($request, 'Invalid id');
		}

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
		$bo = $this->assertEntityAcl($request, $args, ACL_ADD, 'No add access for this entity category');

		$body = (array)($request->getParsedBody() ?? []);
		$values           = (array)($body['values']           ?? []);
		$values_attribute = (array)($body['values_attribute'] ?? []);

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
		$bo = $this->assertEntityAcl($request, $args, ACL_EDIT, 'No edit access for this entity category');

		$id = (int)$args['id'];
		if ($id <= 0)
		{
			throw new HttpBadRequestException($request, 'Invalid id');
		}

		$body = (array)($request->getParsedBody() ?? []);
		$values           = (array)($body['values']           ?? []);
		$values_attribute = (array)($body['values_attribute'] ?? []);
		$values['id']     = $id;

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
		$bo = $this->assertEntityAcl($request, $args, ACL_DELETE, 'No delete access for this entity category');

		$id = (int)$args['id'];
		if ($id <= 0)
		{
			throw new HttpBadRequestException($request, 'Invalid id');
		}

		$bo->delete($id);

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
		$bo = $this->assertEntityAcl($request, $args, ACL_READ, 'No read access for this entity category');

		$qr_code = Sanitizer::clean_value($request->getQueryParams()['qr_code'] ?? '', 'string');
		$items   = $bo->get_items_per_qr($qr_code);
		return $this->jsonResponse($response, $items);
	}

	/**
	 * Return related entity links for a given item.
	 *
	 * GET /property/entity/{type}/{entity_id}/{cat_id}/{id}/related
	 */
	public function getRelated(Request $request, Response $response, array $args): Response
	{
		$bo = $this->assertEntityAcl($request, $args, ACL_READ, 'No read access for this entity category');

		$params  = $request->getQueryParams();
		$id      = (int)$args['id'];
		$draw    = (int)($params['draw'] ?? 1);

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
	 * Return attached files for a given item, with HTML file_link / delete_file cells
	 * and image enrichment (img_id, img_url) for image/* mime types.
	 *
	 * GET /property/entity/{type}/{entity_id}/{cat_id}/{id}/files
	 */
	public function getFiles(Request $request, Response $response, array $args): Response
	{
		$bo = $this->assertEntityAcl($request, $args, ACL_READ, 'No read access for this entity category');

		$params     = $request->getQueryParams();
		$id         = (int)$args['id'];
		$draw       = (int)($params['draw'] ?? 1);
		$entity_id  = (int)$args['entity_id'];
		$cat_id     = (int)$args['cat_id'];
		$type       = (string)$args['type'];

		$item = $bo->read_single([
			'entity_id' => $entity_id,
			'cat_id'    => $cat_id,
			'type'      => $type,
			'id'        => $id,
		]);

		$loc1            = $item['location_data']['loc1'] ?? '';
		$view_file_base  = '/index.php?' . http_build_query([
			'menuaction' => 'property.uientity.view_file',
			'loc1'       => $loc1,
			'id'         => $id,
			'cat_id'     => $cat_id,
			'entity_id'  => $entity_id,
			'type'       => $type,
		]);

		$img_types = ['image/jpeg', 'image/png', 'image/gif'];

		$lang_view   = lang('click to view file');
		$lang_delete = lang('Check to delete file');

		$content_files = [];
		foreach ((array)($item['files'] ?? []) as $_entry)
		{
			$file_url = $view_file_base . '&file_id=' . (int)$_entry['file_id'];

			$row = [
				'file_link'   => "<a href='" . htmlspecialchars($file_url, ENT_QUOTES, 'UTF-8') . "'"
					. " target='_blank' title='" . htmlspecialchars($lang_view, ENT_QUOTES, 'UTF-8') . "'>"
					. htmlspecialchars($_entry['name'] ?? '', ENT_QUOTES, 'UTF-8') . '</a>',
				'delete_file' => "<input type='checkbox' name='values[file_action][]'"
					. " value='" . (int)$_entry['file_id'] . "'"
					. " title='" . htmlspecialchars($lang_delete, ENT_QUOTES, 'UTF-8') . "'>",
			];

			if (in_array($_entry['mime_type'] ?? '', $img_types, true))
			{
				$row['file_name'] = $_entry['name'] ?? '';
				$row['img_id']    = (int)$_entry['file_id'];
				$row['img_url']   = $file_url;
			}

			$content_files[] = $row;
		}

		return $this->jsonResponse($response, [
			'data'            => $content_files,
			'recordsTotal'    => count($content_files),
			'recordsFiltered' => count($content_files),
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
		$bo = $this->assertEntityAcl($request, $args, ACL_READ, 'No read access for this entity category');

		$params      = $request->getQueryParams();
		$id          = (int)$args['id'];
		$draw        = (int)($params['draw'] ?? 1);

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
		$this->assertEntityAcl($request, $args, ACL_READ, 'No read access for this entity category');

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
		$this->assertEntityAcl($request, $args, ACL_READ, 'No read access for this entity category');

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
		$this->assertEntityAcl($request, $args, ACL_READ, 'No read access for this entity category');

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
		$this->assertEntityAcl($request, $args, ACL_READ, 'No read access for this entity category');

		$_GET['phpgw_return_as'] = 'json';
		$result = $this->controllerHelper($args)->get_cases_for_checklist();
		return $this->jsonResponse($response, $result);
	}

	/**
	 * Download the current entity list as a file using property_bocommon::download().
	 *
	 * GET /property/entity/{type}/{entity_id}/{cat_id}/download
	 *
	 * This will output CSV, Excel, or ODS depending on user preference, matching the legacy UI.
	 *
	 * @param Request $request
	 * @param Response $response (unused)
	 * @param array $args
	 * @return void
	 */
	public function download(Request $request, Response $response, array $args): void
	{
		include_class('property', 'bocommon');
		$bo = $this->assertEntityAcl($request, $args, ACL_READ, 'No read access for this entity category');
		$bo->allrows = true;
		$list = $bo->read(['allrows' => true]);
		$list = $this->enrichRows((array)$list, $bo);
		$uicols = $bo->uicols;
		$bocommon = new \property_bocommon();
		$bocommon->download(
			$list,
			$uicols['name'],
			$uicols['descr'],
			$uicols['input_type'] ?? [],
			[],
			''
		);
		exit;
	}
}
