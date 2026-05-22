<?php

namespace App\modules\property\controllers;

use App\Database\Db;
use App\modules\property\helpers\EntityFormHelper;
use App\modules\phpgwapi\controllers\Accounts\Accounts;
use App\modules\phpgwapi\services\Cache;
use App\modules\phpgwapi\services\Settings;
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
 *
 * @OA\Schema(
 *     schema="RelationInfo",
 *     type="object",
 *     description="Relation metadata used to enrich entity payloads with location and origin context.",
 *     @OA\Property(property="location_code", type="string", example="5436-01-01-001"),
 *     @OA\Property(property="p_num", type="string", example="7"),
 *     @OA\Property(property="p_entity_id", type="integer", example=1),
 *     @OA\Property(property="p_cat_id", type="integer", example=15),
 *     @OA\Property(property="tenant_id", type="integer", example=1000),
 *     @OA\Property(
 *         property="origin",
 *         type="string",
 *         description="Origin context. Preferred format is {application}.{module}[.{submodule}] (for example property.ticket.category). Legacy dot-prefixed values (for example .ticket.category) are accepted and normalized with a property application fallback when resolving location.",
 *         example="property.ticket.category"
 *     ),
 *     @OA\Property(property="origin_id", type="integer", example=34844)
 * )
 *
 * @OA\Schema(
 *     schema="EntitySaveRequest",
 *     type="object",
 *     @OA\Property(property="values", type="object", description="Core field values"),
 *     @OA\Property(property="values_attribute", type="object", description="EAV attribute values keyed by attribute ID"),
 *     @OA\Property(property="values_checklist_stage", type="object", description="Checklist stage values"),
 *     @OA\Property(property="RelationInfo", ref="#/components/schemas/RelationInfo")
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
		if (
			!empty($config['bypass_acl_at_entity'])
			&& is_array($config['bypass_acl_at_entity'])
			&& in_array($bo->entity_id, $config['bypass_acl_at_entity'])
		)
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
	 * Build the shared helper used by legacy and REST save workflows.
	 */
	protected function formHelper(): EntityFormHelper
	{
		return new EntityFormHelper();
	}

	/**
	 * Build the legacy admin entity helper used for category validation.
	 */
	protected function soadminEntity(): object
	{
		include_class('property', 'soadmin_entity');
		return new \property_soadmin_entity();
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
	protected function assertEntityAcl(Request $request, array $args, int $aclType, string $message): \property_boentity
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
	 * Determine if the incoming request includes file actions or uploads.
	 */
	private function hasFileOperations(array $values): bool
	{
		if (!empty($values['file_action']) || !empty($values['file_jasperaction']))
		{
			return true;
		}

		return (!empty($_FILES['file']['name']) || !empty($_FILES['jasperfile']['name']));
	}

	/**
	 * Abort the current transaction.
	 */
	protected function abortTransaction(): void
	{
		Db::getInstance()->transaction_abort();
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
	 * Resolve the current DataTables draw token from query or parsed body.
	 */
	private function currentDraw(Request $request): int
	{
		$queryParams = $request->getQueryParams();
		$parsedBody = $request->getParsedBody();
		$bodyDraw = is_array($parsedBody) ? ($parsedBody['draw'] ?? null) : null;
		$draw = (int)($queryParams['draw'] ?? $bodyDraw ?? 1);
		return $draw > 0 ? $draw : 1;
	}

	/**
	 * Format row data for DataTables-compatible responses used by inline form tables.
	 *
	 * @param array $rows
	 */
	private function datatableResponse(Response $response, Request $request, array $rows): Response
	{
		return $this->jsonResponse($response, [
			'draw' => $this->currentDraw($request),
			'recordsTotal' => count($rows),
			'recordsFiltered' => count($rows),
			'data' => $rows,
		]);
	}

	private function isDataTablesRequest(array $input): bool
	{
		return array_key_exists('draw', $input)
			|| array_key_exists('columns', $input)
			|| array_key_exists('order', $input);
	}

	/**
	 * Return a validation error response compatible with legacy receipts.
	 */
	private function validationErrorResponse(Response $response, array $errors): Response
	{
		return $this->jsonResponse($response, [
			'message' => [],
			'error' => array_values($errors),
		])->withStatus(400);
	}

	/**
	 * Return request body as array, with JSON fallback when parsed body is empty.
	 */
	private function requestBodyArray(Request $request): array
	{
		$parsed = $request->getParsedBody();
		if (is_array($parsed))
		{
			return $parsed;
		}

		$rawBody = (string)$request->getBody();
		if ($rawBody === '')
		{
			return [];
		}

		$decoded = json_decode($rawBody, true);
		if (!is_array($decoded))
		{
			throw new HttpBadRequestException($request, 'Invalid JSON request body');
		}

		return $decoded;
	}

	/**
	 * Split a link into path + query params for client-side navigation URL building.
	 *
	 * @return array{path: string|null, params: array<string, mixed>}
	 */
	private function splitLinkToPathAndParams(string $link): array
	{
		if ($link === '')
		{
			return ['path' => null, 'params' => []];
		}

		$rawPath = parse_url($link, PHP_URL_PATH);
		$path = is_string($rawPath) && $rawPath !== '' ? $rawPath : null;
		$params = [];

		$rawQuery = parse_url($link, PHP_URL_QUERY);
		if (is_string($rawQuery) && $rawQuery !== '')
		{
			parse_str($rawQuery, $params);
			if (!is_array($params))
			{
				$params = [];
			}
		}

		return [
			'path' => $path,
			'params' => $params,
		];
	}

	/**
	 * Recursively sanitize scalar payload values while preserving array structure.
	 */
	private function sanitizePayloadValue(mixed $value): mixed
	{
		if (is_array($value))
		{
			$clean = [];
			foreach ($value as $key => $item)
			{
				$clean[$key] = $this->sanitizePayloadValue($item);
			}
			return $clean;
		}

		if (is_string($value))
		{
			return Sanitizer::clean_value($value, 'string');
		}

		if (is_int($value) || is_float($value) || is_bool($value) || $value === null)
		{
			return $value;
		}

		return Sanitizer::clean_value((string)$value, 'string');
	}

	/**
	 * Validate and sanitize REST save payload shape.
	 *
	 * @return array{values: array, values_attribute: array, values_checklist_stage: mixed}
	 */
	private function normalizedSavePayload(Request $request): array
	{
		$body = $this->requestBodyArray($request);

		if (isset($body['values']) && !is_array($body['values']))
		{
			throw new HttpBadRequestException($request, 'Invalid payload: values must be an object');
		}

		if (isset($body['values_attribute']) && !is_array($body['values_attribute']))
		{
			throw new HttpBadRequestException($request, 'Invalid payload: values_attribute must be an object');
		}

		if (
			isset($body['values_checklist_stage'])
			&& !is_array($body['values_checklist_stage'])
			&& $body['values_checklist_stage'] !== null
		)
		{
			throw new HttpBadRequestException($request, 'Invalid payload: values_checklist_stage must be an object');
		}

		$values = $this->sanitizePayloadValue((array)($body['values'] ?? []));
		$valuesAttribute = $this->sanitizePayloadValue((array)($body['values_attribute'] ?? []));
		$valuesChecklistStage = $this->sanitizePayloadValue($body['values_checklist_stage'] ?? null);

		return [
			'values' => is_array($values) ? $values : [],
			'values_attribute' => is_array($valuesAttribute) ? $valuesAttribute : [],
			'values_checklist_stage' => $valuesChecklistStage,
		];
	}

	/**
	 * Apply RelationInfo-based enrichment for location and relation fields.
	 *
	 * This replaces the legacy collect_locationdata() bridge for REST saves.
	 */
	private function applyRelationInfoPayload(array $values, \property_boentity $bo, Request $request): array
	{
		$body = $this->requestBodyArray($request);

		$relationInfo = [];
		if (isset($body['RelationInfo']) && is_array($body['RelationInfo']))
		{
			$relationInfo = $this->sanitizePayloadValue($body['RelationInfo']);
		}

		$values['extra'] = isset($values['extra']) && is_array($values['extra']) ? $values['extra'] : [];

		if (!empty($relationInfo['location_code']))
		{
			$locationCode = Sanitizer::clean_value((string)$relationInfo['location_code'], 'string');
			$values['location_code'] = $locationCode;

			$parts = array_values(array_filter(explode('-', $locationCode), static function ($part)
			{
				return $part !== '';
			}));
			if (!empty($parts))
			{
				$values['location'] = [];
				for ($i = 0; $i < count($parts); $i++)
				{
					$values['location']['loc' . ($i + 1)] = $parts[$i];
				}

				$locationNameKey = 'loc' . count($parts) . '_name';
				if (!empty($body[$locationNameKey]))
				{
					$values['location_name'] = Sanitizer::clean_value((string)$body[$locationNameKey], 'string');
				}
			}
		}

		if (!empty($body['street_name']))
		{
			$values['street_name'] = Sanitizer::clean_value((string)$body['street_name'], 'string');
		}

		if (!empty($body['street_number']))
		{
			$values['street_number'] = Sanitizer::clean_value((string)$body['street_number'], 'string');
		}

		if (!empty($relationInfo['tenant_id']))
		{
			$values['extra']['tenant_id'] = Sanitizer::clean_value((string)$relationInfo['tenant_id'], 'string');
		}

		$pEntityId = !empty($relationInfo['p_entity_id']) ? (int)$relationInfo['p_entity_id'] : 0;
		$pCatId = !empty($relationInfo['p_cat_id']) ? (int)$relationInfo['p_cat_id'] : 0;
		$pNum = !empty($relationInfo['p_num']) ? Sanitizer::clean_value((string)$relationInfo['p_num'], 'string') : '';

		if ($pEntityId > 0 && $pCatId > 0 && $pNum !== '')
		{
			$values['extra']['type'] = $values['extra']['type'] ?? $bo->type;
			$values['extra']['p_entity_id'] = $pEntityId;
			$values['extra']['p_cat_id'] = $pCatId;

			$convertedPNum = execMethod(
				'property.soentity.convert_num_to_id',
				[
					'type' => $values['extra']['type'],
					'entity_id' => $pEntityId,
					'cat_id' => $pCatId,
					'num' => $pNum,
				]
			);

			$values['extra']['p_num'] = $convertedPNum;
			$values['p'][$pEntityId]['p_entity_id'] = $pEntityId;
			$values['p'][$pEntityId]['p_cat_id'] = $pCatId;
			$values['p'][$pEntityId]['p_num'] = $convertedPNum;

			$pCatNameKey = "entity_cat_name_{$pEntityId}";
			if (!empty($body[$pCatNameKey]))
			{
				$values['p'][$pEntityId]['p_cat_name'] = Sanitizer::clean_value((string)$body[$pCatNameKey], 'string');
			}
		}

		if (!empty($relationInfo['origin']))
		{
			$values['origin'] = Sanitizer::clean_value((string)$relationInfo['origin'], 'string');
		}

		if (!empty($relationInfo['origin_id']))
		{
			$values['origin_id'] = (int)$relationInfo['origin_id'];
		}

		return $values;
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
	protected function enrichRows(array $rows, \property_boentity $bo): array
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
		else
		{
			$vfs = null;
		}

		foreach ($rows as &$entry)
		{
			$loc1 = !empty($entry['loc1']) ? $entry['loc1'] : 'dummy';
			$entry['entity_type'] = (string)$bo->type;
			$entry['entity_id'] = (int)$bo->entity_id;
			$entry['cat_id'] = (int)$bo->cat_id;

			if ($remote_image_in_table)
			{
				$key                      = $remote_image_config['img_key_local'] ?? '';
				$entry['file_name']       = $entry[$key] ?? '';
				$entry['img_id']          = $entry[$key] ?? '';
				$entry['img_url']         = ($remote_image_config['url'] ?? '')
					. '&' . ($remote_image_config['img_key_remote'] ?? '')
					. '=' . $entry['img_id'];
				$entry['thumbnail_flag']  = $remote_image_config['thumbnail_flag'] ?? '';
				$entry['image_source'] = 'remote';
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
					$entry['img_url']        = null;
					$entry['thumbnail_flag'] = 'thumb=1';
					$entry['image_source'] = 'local';
				}
			}
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

		$body = $this->requestBodyArray($request);

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
	 * Canonical collection POST endpoint.
	 *
	 * DataTables clients can still post by including draw/order/columns,
	 * while non-DataTables payloads are treated as create requests.
	 */
	public function postCollection(Request $request, Response $response, array $args): Response
	{
		$input = array_merge($request->getQueryParams(), $this->requestBodyArray($request));
		if ($this->isDataTablesRequest($input))
		{
			return $this->index($request, $response, $args);
		}

		return $this->store($request, $response, $args);
	}

	/**
	 * @OA\Get(
	 *     path="/property/entity/{type}/{entity_id}/{cat_id}/list",
	 *     summary="List entity items (canonical envelope)",
	 *     description="Returns a canonical JSON envelope for entity item collections without DataTables-specific keys.",
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
	 *         description="Canonical list of entity items",
	 *         @OA\JsonContent(type="object",
	 *             @OA\Property(property="status", type="string", example="success"),
	 *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/EntityItem")),
	 *             @OA\Property(property="meta", type="object",
	 *                 @OA\Property(property="start", type="integer"),
	 *                 @OA\Property(property="total", type="integer")
	 *             )
	 *         )
	 *     )
	 * )
	 */
	public function listItems(Request $request, Response $response, array $args): Response
	{
		$bo = $this->assertEntityAcl($request, $args, ACL_READ, 'No read access for this entity category');
		$input = array_merge($request->getQueryParams(), $this->requestBodyArray($request));

		$readParams = [
			'start'   => isset($input['start']) ? (int)$input['start'] : 0,
			'query'   => Sanitizer::clean_value($input['query'] ?? '', 'string'),
			'filter'  => Sanitizer::clean_value($input['filter'] ?? '', 'string'),
			'order'   => Sanitizer::clean_value($input['sort'] ?? '', 'string'),
			'sort'    => Sanitizer::clean_value($input['dir'] ?? '', 'string'),
			'allrows' => isset($input['allrows']) && (string)$input['allrows'] === 'true',
		];

		$bo->allrows = $readParams['allrows'];
		$items = $bo->read($readParams);
		$items = $this->enrichRows((array)$items, $bo);

		return $this->jsonResponse($response, [
			'status' => 'success',
			'data' => $items,
			'meta' => [
				'start' => $readParams['start'],
				'total' => (int)$bo->total_records,
			],
		]);
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
	 *     description="Creates a new entity item with optional EAV attribute values. Accepts JSON or multipart/form-data (required when uploading a file attachment). Legacy alias /create is still supported for backward compatibility.",
	 *     tags={"Entity"},
	 *     @OA\Parameter(name="type", in="path", required=true, description="Entity type key", @OA\Schema(type="string")),
	 *     @OA\Parameter(name="entity_id", in="path", required=true, description="Entity definition ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="cat_id", in="path", required=true, description="Category ID", @OA\Schema(type="integer")),
	 *     @OA\RequestBody(
	 *         required=true,
	 *         @OA\MediaType(
	 *             mediaType="application/json",
	 *             @OA\Schema(ref="#/components/schemas/EntitySaveRequest"),
	 *             @OA\Examples(
	 *                 example="ticketCreateExample",
	 *                 summary="Create with ticket relation context",
	 *                 value={
	 *                     "values": {
	 *                         "name": "Røykvarsler",
	 *                         "descr": "Opprettet fra ticket"
	 *                     },
	 *                     "values_attribute": {
	 *                        "7": { "value": "2024-12-31" },
	 *                         "20": { "value": "1" },
	 *                         "21": { "value": {"0": "1", "1": "3"} }
	 *                     },
	 *                     "RelationInfo": {
	 *                         "location_code": "5436-01-01-001",
	 *                         "p_num": "7",
	 *                         "p_entity_id": 1,
	 *                         "p_cat_id": 15,
	 *                         "tenant_id": 1000,
	 *                         "origin": "property.ticket.category",
	 *                         "origin_id": 34844
	 *                     }
	 *                 }
	 *             )
	 *         ),
	 *         @OA\MediaType(
	 *             mediaType="multipart/form-data",
	 *             @OA\Schema(
	 *                 type="object",
	 *                 @OA\Property(property="values", type="object", description="Core field values"),
	 *                 @OA\Property(property="values_attribute", type="object", description="EAV attribute values keyed by attribute ID"),
	 *                 @OA\Property(property="values_checklist_stage", type="object", description="Checklist stage values"),
	 *                 @OA\Property(property="RelationInfo", ref="#/components/schemas/RelationInfo"),
	 *                 @OA\Property(property="file", type="string", format="binary", description="Optional file attachment"),
	 *                 @OA\Property(property="jasperfile", type="string", format="binary", description="Optional Jasper report attachment")
	 *             )
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
		$helper = $this->formHelper();
		$soadminEntity = $this->soadminEntity();

		$payload = $this->normalizedSavePayload($request);
		$values = $payload['values'];
		$values_attribute = $payload['values_attribute'];
		$valuesChecklistStage = $payload['values_checklist_stage'];
		$values = $this->applyRelationInfoPayload($values, $bo, $request);

		$validation = $helper->validate(
			$values,
			$values_attribute,
			(int) $args['cat_id'],
			(int) $args['entity_id'],
			$soadminEntity,
			$bo
		);
		$values = $validation['values'];
		$values_attribute = $validation['values_attribute'];
		if (!empty($validation['errors']))
		{
			return $this->validationErrorResponse($response, (array) $validation['errors']);
		}

		try
		{
			$persisted = $helper->persistSave(
				$values,
				$values_attribute,
				'add',
				(int)$args['entity_id'],
				(int)$args['cat_id'],
				$bo,
				$valuesChecklistStage
			);

			$receipt = $persisted['receipt'];
			$savedValues = $persisted['values'];
		}
		catch (\Exception $e)
		{
			$this->abortTransaction();
			throw $e;
		}

		if ($this->hasFileOperations($values))
		{
			$errors = (array)($receipt['error'] ?? []);
			$helper->handleFiles(
				$savedValues,
				$bo->category_dir,
				$bo->type_app[$bo->type],
				$errors
			);

			try
			{
				$messages = Cache::message_get(true);
			}
			catch (\Throwable $e)
			{
				$messages = [];
			}
			$receipt['error'] = array_merge($errors, (array)($messages['error'] ?? []));
			$receipt['message'] = array_merge((array)($receipt['message'] ?? []), (array)($messages['message'] ?? []));
		}

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
	 *         @OA\MediaType(
	 *             mediaType="application/json",
	 *             @OA\Schema(ref="#/components/schemas/EntitySaveRequest"),
	 *             @OA\Examples(
	 *                 example="ticketUpdateExample",
	 *                 summary="Update with ticket relation context",
	 *                 value={
	 *                     "values": {
	 *                         "name": "Røykvarsler oppdatert",
	 *                         "descr": "Oppdatert fra ticket"
	 *                     },
	 *                     "values_attribute": {
	 *                         "7": { "value": "2024-12-31" },
	 *                         "20": { "value": "1" },
	 *                         "21": { "value": {"0": "1", "1": "3"} }
	 *                     },
	 *                     "RelationInfo": {
	 *                         "location_code": "5436-01-01-001",
	 *                         "p_num": "7",
	 *                         "p_entity_id": 1,
	 *                         "p_cat_id": 15,
	 *                         "tenant_id": 1000,
	 *                         "origin": "property.ticket.category",
	 *                         "origin_id": 34844
	 *                     }
	 *                 }
	 *             )
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
		$helper = $this->formHelper();
		$soadminEntity = $this->soadminEntity();

		$id = (int)$args['id'];
		if ($id <= 0)
		{
			throw new HttpBadRequestException($request, 'Invalid id');
		}

		$payload = $this->normalizedSavePayload($request);
		$values = $payload['values'];
		$values_attribute = $payload['values_attribute'];
		$valuesChecklistStage = $payload['values_checklist_stage'];
		$values = $this->applyRelationInfoPayload($values, $bo, $request);
		$values['id'] = $id;

		$validation = $helper->validate(
			$values,
			$values_attribute,
			(int) $args['cat_id'],
			(int) $args['entity_id'],
			$soadminEntity,
			$bo
		);
		$values = $validation['values'];
		$values_attribute = $validation['values_attribute'];
		if (!empty($validation['errors']))
		{
			return $this->validationErrorResponse($response, (array) $validation['errors']);
		}

		try
		{
			$persisted = $helper->persistSave(
				$values,
				$values_attribute,
				'edit',
				(int)$args['entity_id'],
				(int)$args['cat_id'],
				$bo,
				$valuesChecklistStage
			);

			$receipt = $persisted['receipt'];
			$savedValues = $persisted['values'];
		}
		catch (\Exception $e)
		{
			$this->abortTransaction();
			throw $e;
		}

		if ($this->hasFileOperations($values))
		{
			$errors = (array)($receipt['error'] ?? []);
			$helper->handleFiles(
				$savedValues,
				$bo->category_dir,
				$bo->type_app[$bo->type],
				$errors
			);

			try
			{
				$messages = Cache::message_get(true);
			}
			catch (\Throwable $e)
			{
				$messages = [];
			}
			$receipt['error'] = array_merge($errors, (array)($messages['error'] ?? []));
			$receipt['message'] = array_merge((array)($receipt['message'] ?? []), (array)($messages['message'] ?? []));
		}

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
	 * @OA\Get(
	 *     path="/property/entity/{type}/{entity_id}/{cat_id}/items-per-qr",
	 *     summary="Get items by QR code",
	 *     description="Returns entity items whose QR code matches the given value.",
	 *     tags={"Entity"},
	 *     @OA\Parameter(name="type", in="path", required=true, description="Entity type key", @OA\Schema(type="string")),
	 *     @OA\Parameter(name="entity_id", in="path", required=true, description="Entity definition ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="cat_id", in="path", required=true, description="Category ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="qr_code", in="query", required=false, description="QR code value to search", @OA\Schema(type="string")),
	 *     @OA\Response(response=200, description="Matching items", @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/EntityItem")))
	 * )
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
	 * @OA\Post(
	 *     path="/property/entity/{type}/{entity_id}/{cat_id}/{id}/related",
	 *     summary="Get related entities",
	 *     description="Returns related entity records for the given item as pure data with navigation context. Called via DataTables POST.",
	 *     tags={"Entity"},
	 *     @OA\Parameter(name="type", in="path", required=true, description="Entity type key", @OA\Schema(type="string")),
	 *     @OA\Parameter(name="entity_id", in="path", required=true, description="Entity definition ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="cat_id", in="path", required=true, description="Category ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="id", in="path", required=true, description="Item ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="draw", in="query", required=false, description="DataTables draw counter", @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="DataTables response with related records")
	 * )
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
					$linkParts = $this->splitLinkToPathAndParams((string)($entry['entity_link'] ?? ''));
					$values[] = [
						'name' => (string)($entry['name'] ?? ''),
						'related_path' => $linkParts['path'],
						'related_params' => $linkParts['params'],
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
	 * Return target/log rows for a given item as pure data fields.
	 *
	 * Rendering concerns (anchors) are handled in client formatters.
	 *
	 * @OA\Post(
	 *     path="/property/entity/{type}/{entity_id}/{cat_id}/{id}/target",
	 *     summary="Get target/log rows",
	 *     description="Returns interlink/workorder target rows for the given item as DataTables-compatible pure data.",
	 *     tags={"Entity"},
	 *     @OA\Parameter(name="type", in="path", required=true, description="Entity type key", @OA\Schema(type="string")),
	 *     @OA\Parameter(name="entity_id", in="path", required=true, description="Entity definition ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="cat_id", in="path", required=true, description="Category ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="id", in="path", required=true, description="Item ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="draw", in="query", required=false, description="DataTables draw counter", @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="DataTables response with target rows")
	 * )
	 */
	public function getTarget(Request $request, Response $response, array $args): Response
	{
		$bo = $this->assertEntityAcl($request, $args, ACL_READ, 'No read access for this entity category');

		$params = $request->getQueryParams();
		$id = (int)$args['id'];
		$draw = (int)($params['draw'] ?? 1);
		$phpgwapiCommon = new \phpgwapi_common();

		$accounts = null;
		$dateFormat = 'Y-m-d';
		try
		{
			$userSettings = Settings::getInstance()->get('user') ?? [];
			$dateFormat = (string)($userSettings['preferences']['common']['dateformat'] ?? 'Y-m-d');
		}
		catch (\Throwable $e)
		{
			// Keep a deterministic date format when settings bootstrap is unavailable.
		}

		$interlink = CreateObject('property.interlink');
		$target = $interlink->get_relation('property', $bo->acl_location, $id, 'target');

		$values = [];
		if (is_array($target))
		{
			foreach ($target as $targetSection)
			{
				foreach ((array)($targetSection['data'] ?? []) as $targetEntry)
				{
					$linkParts = $this->splitLinkToPathAndParams((string)($targetEntry['link'] ?? ''));
					$accountId = (int)($targetEntry['account_id'] ?? 0);
					$userLabel = '';
					if ($accountId > 0)
					{
						if ($accounts === null)
						{
							$accounts = new Accounts();
						}
						$userLabel = $accounts->get($accountId)->__toString();
					}

					$values[] = [
						'target_id' => (string)($targetEntry['id'] ?? ''),
						'target_path' => $linkParts['path'],
						'target_params' => $linkParts['params'],
						'type' => (string)($targetSection['descr'] ?? ''),
						'title' => (string)($targetEntry['title'] ?? ''),
						'status' => (string)($targetEntry['statustext'] ?? ''),
						'user' => $userLabel,
						'entry_date' => !empty($targetEntry['entry_date'])
							? $phpgwapiCommon->show_date($targetEntry['entry_date'], $dateFormat)
							: '',
					];
				}
			}
		}

		$workorders = CreateObject('property.soworkorder')->get_entity_relation((int)$args['entity_id'], (int)$args['cat_id'], $id);
		$langWorkorder = lang('workorder');
		foreach ((array)$workorders as $workorder)
		{
			$link = \phpgw::link('/index.php', [
				'menuaction' => 'property.uiworkorder.view',
				'id' => $workorder['id'],
			]);
			$linkParts = $this->splitLinkToPathAndParams($link);
			$workorderUserId = (int)($workorder['user_id'] ?? 0);
			$userLabel = '';
			if ($workorderUserId > 0)
			{
				if ($accounts === null)
				{
					$accounts = new Accounts();
				}
				$userLabel = $accounts->get($workorderUserId)->__toString();
			}

			$values[] = [
				'target_id' => (string)($workorder['id'] ?? ''),
				'target_path' => $linkParts['path'],
				'target_params' => $linkParts['params'],
				'type' => $langWorkorder,
				'title' => (string)($workorder['title'] ?? ''),
				'status' => (string)($workorder['statustext'] ?? ''),
				'user' => $userLabel,
				'entry_date' => !empty($workorder['entry_date'])
					? $phpgwapiCommon->show_date($workorder['entry_date'], $dateFormat)
					: '',
			];
		}

		return $this->jsonResponse($response, [
			'data' => $values,
			'recordsTotal' => count($values),
			'recordsFiltered' => count($values),
			'draw' => $draw,
		]);
	}

	/**
	 * Return document rows for a given item as pure data fields.
	 *
	 * Rendering concerns (anchors) are handled in client formatters.
	 *
	 * @OA\Post(
	 *     path="/property/entity/{type}/{entity_id}/{cat_id}/{id}/documents",
	 *     summary="Get document rows",
	 *     description="Returns classic and generic document rows for the given item as DataTables-compatible pure data.",
	 *     tags={"Entity"},
	 *     @OA\Parameter(name="type", in="path", required=true, description="Entity type key", @OA\Schema(type="string")),
	 *     @OA\Parameter(name="entity_id", in="path", required=true, description="Entity definition ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="cat_id", in="path", required=true, description="Category ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="id", in="path", required=true, description="Item ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="doc_type", in="query", required=false, description="Document type filter", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="draw", in="query", required=false, description="DataTables draw counter", @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="DataTables response with document rows")
	 * )
	 */
	public function getDocuments(Request $request, Response $response, array $args): Response
	{
		$bo = $this->assertEntityAcl($request, $args, ACL_READ, 'No read access for this entity category');

		$queryParams = $request->getQueryParams();
		$bodyParams = $this->requestBodyArray($request);
		$input = array_merge($queryParams, $bodyParams);

		$search = $input['search'] ?? [];
		$order = (array)($input['order'] ?? []);
		$columns = (array)($input['columns'] ?? []);
		$draw = (int)($input['draw'] ?? 0) + 1;
		$docType = (int)($input['doc_type'] ?? 0);
		$itemId = (int)$args['id'];

		$orderColumnIndex = (int)($order[0]['column'] ?? -1);
		$orderField = ($orderColumnIndex >= 0 && isset($columns[$orderColumnIndex]['data']))
			? (string)$columns[$orderColumnIndex]['data']
			: '';
		$orderDir = strtolower((string)($order[0]['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
		$searchValue = is_array($search) ? ($search['value'] ?? '') : (string)$search;

		$params = [
			'start' => (int)($input['start'] ?? 0),
			'results' => (int)($input['length'] ?? 0),
			'query' => $searchValue,
			'order' => $orderField,
			'sort' => $orderDir,
			'dir' => $orderDir,
			'allrows' => ((int)($input['length'] ?? 0) == -1) || !empty($input['export']),
			'doc_type' => $docType,
			'entity_id' => (int)$args['entity_id'],
			'cat_id' => (int)$args['cat_id'],
			'p_num' => $itemId,
			'location_item_id' => $itemId,
		];

		$document = CreateObject('property.sodocument');
		$documents = $document->read_at_location($params);
		$totalRecords = (int)$document->total_records;

		$rows = [];
		foreach ((array)$documents as $item)
		{
			$rows[] = [
				'document_name' => (string)($item['document_name'] ?? ''),
				'document_source' => 'entity',
				'document_id' => (int)($item['id'] ?? 0),
				'title' => (string)($item['title'] ?? ''),
			];
		}

		$genericDocument = CreateObject('property.sogeneric_document');
		$locationId = (int)($input['location_id'] ?? 0);
		if ($locationId <= 0)
		{
			$locations = new \App\modules\phpgwapi\controllers\Locations();
			$locationId = (int)$locations->get_id($bo->type_app[$bo->type], ".{$bo->type}.{$bo->entity_id}.{$bo->cat_id}");
		}

		$params['location_id'] = $locationId;
		$params['order'] = 'name';
		$params['cat_id'] = $docType;
		$documents2 = $genericDocument->read($params);
		$totalRecords += (int)$genericDocument->total_records;

		foreach ((array)$documents2 as $item)
		{
			$rows[] = [
				'document_name' => (string)($item['name'] ?? ''),
				'document_source' => 'generic',
				'document_id' => (int)($item['id'] ?? 0),
				'title' => (string)($item['title'] ?? ''),
			];
		}

		return $this->jsonResponse($response, [
			'data' => $rows,
			'recordsTotal' => $totalRecords,
			'recordsFiltered' => $totalRecords,
			'draw' => $draw,
		]);
	}

	/**
	 * Return attached files for a given item as pure data fields.
	 *
	 * Rendering concerns (anchors, checkboxes) are handled in client formatters.
	 *
	 * @OA\Post(
	 *     path="/property/entity/{type}/{entity_id}/{cat_id}/{id}/files",
	 *     summary="Get attached files",
	 *     description="Returns file attachments for the given item as a DataTables-compatible response with pure data fields.",
	 *     tags={"Entity"},
	 *     @OA\Parameter(name="type", in="path", required=true, description="Entity type key", @OA\Schema(type="string")),
	 *     @OA\Parameter(name="entity_id", in="path", required=true, description="Entity definition ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="cat_id", in="path", required=true, description="Category ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="id", in="path", required=true, description="Item ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="draw", in="query", required=false, description="DataTables draw counter", @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="DataTables response with file rows")
	 * )
	 */
	public function getFiles(Request $request, Response $response, array $args): Response
	{
		$bo = $this->assertEntityAcl($request, $args, ACL_READ, 'No read access for this entity category');

		$params     = $request->getQueryParams();
		$id         = (int)$args['id'];
		$draw       = (int)($params['draw'] ?? 0) + 1;
		$entity_id  = (int)$args['entity_id'];
		$cat_id     = (int)$args['cat_id'];
		$type       = (string)$args['type'];

		$item = $bo->read_single([
			'entity_id' => $entity_id,
			'cat_id'    => $cat_id,
			'type'      => $type,
			'id'        => $id,
		]);

		$loc1 = (string)($item['location_data']['loc1'] ?? '');

		$img_types = ['image/jpeg', 'image/png', 'image/gif'];

		$content_files = [];
		foreach ((array)($item['files'] ?? []) as $_entry)
		{
			$fileId = (int)($_entry['file_id'] ?? 0);

			$row = [
				'file_id' => $fileId,
				'file_name' => (string)($_entry['name'] ?? ''),
				'file_mime_type' => (string)($_entry['mime_type'] ?? ''),
				'loc1' => $loc1,
				'item_id' => $id,
				'entity_id' => $entity_id,
				'cat_id' => $cat_id,
				'type' => $type,
			];

			if (in_array($_entry['mime_type'] ?? '', $img_types, true))
			{
				$row['img_id']    = $fileId;
				$row['img_url']   = null;
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
	 * @OA\Post(
	 *     path="/property/entity/{type}/{entity_id}/{cat_id}/{id}/inventory",
	 *     summary="Get inventory for item",
	 *     description="Returns inventory records associated with the given entity item.",
	 *     tags={"Entity"},
	 *     @OA\Parameter(name="type", in="path", required=true, description="Entity type key", @OA\Schema(type="string")),
	 *     @OA\Parameter(name="entity_id", in="path", required=true, description="Entity definition ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="cat_id", in="path", required=true, description="Category ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="id", in="path", required=true, description="Item ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="draw", in="query", required=false, description="DataTables draw counter", @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="DataTables response with inventory rows")
	 * )
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
	 * @OA\Get(
	 *     path="/property/entity/{type}/{entity_id}/{cat_id}/{id}/multi-upload",
	 *     summary="Build multi-upload interface",
	 *     description="Renders HTML for the multi-file upload popup interface",
	 *     tags={"Entity"},
	 *     @OA\Parameter(name="type", in="path", required=true, description="Entity type key", @OA\Schema(type="string")),
	 *     @OA\Parameter(name="entity_id", in="path", required=true, description="Entity definition ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="cat_id", in="path", required=true, description="Category ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="id", in="path", required=true, description="Item ID", @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="HTML form for file upload")
	 * )
	 */
	public function buildMultiUploadFile(Request $request, Response $response, array $args): Response
	{
		$this->assertEntityAcl($request, $args, ACL_READ, 'No read access for this entity category');

		$seed = [
			'id'        => (int)$args['id'],
			'entity_id' => (int)$args['entity_id'],
			'cat_id'    => (int)$args['cat_id'],
			'type'      => (string)$args['type'],
			'_entity_id'=> (int)$args['entity_id'],
			'_cat_id'   => (int)$args['cat_id'],
			'_type'     => (string)$args['type'],
		];

		$backupGet = $_GET;
		$backupRequest = $_REQUEST;

		try
		{
			foreach ($seed as $key => $value)
			{
				$_GET[$key] = $value;
				$_REQUEST[$key] = $value;
			}

			include_class('property', 'uientity');
			$ui = new \property_uientity();

			ob_start();
			$ui->build_multi_upload_file();
			$html = (string)ob_get_clean();
		}
		finally
		{
			$_GET = $backupGet;
			$_REQUEST = $backupRequest;
		}

		$response->getBody()->write($html ?? '');
		return $response->withHeader('Content-Type', 'text/html')->withStatus(200);
	}

	/**
	 * @OA\Post(
	 *     path="/property/entity/{type}/{entity_id}/{cat_id}/{id}/multi-upload",
	 *     summary="Handle multi-upload file operations",
	 *     description="Processes file uploads, deletions, and listing for multi-upload interface (GET/POST/PUT/PATCH/DELETE/HEAD/OPTIONS)",
	 *     tags={"Entity"},
	 *     @OA\Parameter(name="type", in="path", required=true, description="Entity type key", @OA\Schema(type="string")),
	 *     @OA\Parameter(name="entity_id", in="path", required=true, description="Entity definition ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="cat_id", in="path", required=true, description="Category ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="id", in="path", required=true, description="Item ID", @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="Upload operation result"),
	 *     @OA\Response(response=403, description="Forbidden - no edit access")
	 * )
	 */
	public function handleMultiUploadFile(Request $request, Response $response, array $args): Response
	{
		$bo = $this->assertEntityAcl($request, $args, ACL_EDIT, 'No edit access for this entity category');

		$id = (int)$args['id'];
		$entityId = (int)$args['entity_id'];
		$catId = (int)$args['cat_id'];
		$type = (string)$args['type'];

		\phpgw::import_class('property.multiuploader');

		$values = $bo->read_single([
			'entity_id' => $entityId,
			'cat_id' => $catId,
			'id' => $id,
		]);

		$loc1 = isset($values['location_data']['loc1']) && $values['location_data']['loc1']
			? $values['location_data']['loc1']
			: 'dummy';

		if (($bo->type_app[$bo->type] ?? '') === 'catch')
		{
			$loc1 = 'dummy';
		}

		$baseDir = "{$bo->category_dir}/{$loc1}/{$id}";
		$serverSettings = Settings::getInstance()->get('server');
		$scriptUrl = \phpgw::link(
			'/property/entity/' . rawurlencode($type)
			. '/' . rawurlencode((string)$entityId)
			. '/' . rawurlencode((string)$catId)
			. '/' . rawurlencode((string)$id)
			. '/multi-upload'
		);

		$options = [
			'base_dir' => $baseDir,
			'upload_dir' => $serverSettings['files_dir'] . '/property/' . $baseDir . '/',
			'script_url' => html_entity_decode($scriptUrl),
		];

		$uploadHandler = new \property_multiuploader($options, false);

		switch (strtoupper($request->getMethod()))
		{
			case 'OPTIONS':
			case 'HEAD':
				$uploadHandler->head();
				break;
			case 'GET':
				$uploadHandler->get();
				break;
			case 'PATCH':
			case 'PUT':
			case 'POST':
				$uploadHandler->add_file();
				break;
			case 'DELETE':
				$uploadHandler->delete_file();
				break;
			default:
				return $response->withStatus(405);
		}

		return $response;
	}

	private function runLegacyEntityPopup(string $method, array $seed): array
	{
		$backupGet = $_GET;
		$backupRequest = $_REQUEST;

		try
		{
			foreach ($seed as $key => $value)
			{
				$_GET[$key] = $value;
				$_REQUEST[$key] = $value;
			}

			include_class('property', 'uientity');
			$ui = new \property_uientity();

			ob_start();
			$result = $ui->{$method}();
			$html = (string)ob_get_clean();
		}
		finally
		{
			$_GET = $backupGet;
			$_REQUEST = $backupRequest;
		}

		return ['result' => $result ?? null, 'html' => $html ?? ''];
	}

	/**
	 * @OA\Get(
	 *     path="/property/entity/{type}/{entity_id}/{cat_id}/{id}/inventory/add",
	 *     summary="Show add inventory popup",
	 *     description="Renders the add inventory form popup for an entity item",
	 *     tags={"Entity"},
	 *     @OA\Parameter(name="type", in="path", required=true, description="Entity type key", @OA\Schema(type="string")),
	 *     @OA\Parameter(name="entity_id", in="path", required=true, description="Entity definition ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="cat_id", in="path", required=true, description="Category ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="id", in="path", required=true, description="Item ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="location_id", in="query", required=true, description="Location ID", @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="HTML form or JSON response")
	 * )
	 */
	public function addInventoryPopup(Request $request, Response $response, array $args): Response
	{
		$params = $request->getQueryParams();
		$locationId = (int)($params['location_id'] ?? 0);
		if (!$locationId)
		{
			throw new HttpBadRequestException($request, 'Missing required query parameter: location_id');
		}

		$popup = $this->runLegacyEntityPopup('add_inventory', [
			'location_id' => $locationId,
			'id' => (int)$args['id'],
		]);

		if (is_array($popup['result']))
		{
			return $this->jsonResponse($response, $popup['result']);
		}

		$response->getBody()->write($popup['html']);
		return $response->withHeader('Content-Type', 'text/html')->withStatus(200);
	}

	/**
	 * @OA\Get(
	 *     path="/property/entity/{type}/{entity_id}/{cat_id}/{id}/inventory/{inventory_id}/edit",
	 *     summary="Show edit inventory popup",
	 *     description="Renders the edit inventory form popup for a specific inventory record",
	 *     tags={"Entity"},
	 *     @OA\Parameter(name="type", in="path", required=true, description="Entity type key", @OA\Schema(type="string")),
	 *     @OA\Parameter(name="entity_id", in="path", required=true, description="Entity definition ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="cat_id", in="path", required=true, description="Category ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="id", in="path", required=true, description="Item ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="inventory_id", in="path", required=true, description="Inventory record ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="location_id", in="query", required=true, description="Location ID", @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="HTML form or JSON response")
	 * )
	 */
	public function editInventoryPopup(Request $request, Response $response, array $args): Response
	{
		$params = $request->getQueryParams();
		$locationId = (int)($params['location_id'] ?? 0);
		if (!$locationId)
		{
			throw new HttpBadRequestException($request, 'Missing required query parameter: location_id');
		}

		$popup = $this->runLegacyEntityPopup('edit_inventory', [
			'location_id' => $locationId,
			'id' => (int)$args['id'],
			'inventory_id' => (int)$args['inventory_id'],
		]);

		if (is_array($popup['result']))
		{
			return $this->jsonResponse($response, $popup['result']);
		}

		$response->getBody()->write($popup['html']);
		return $response->withHeader('Content-Type', 'text/html')->withStatus(200);
	}

	/**
	 * @OA\Get(
	 *     path="/property/entity/{type}/{entity_id}/{cat_id}/{id}/inventory/{inventory_id}/calendar",
	 *     summary="Show inventory calendar popup",
	 *     description="Renders the inventory calendar interface for a specific inventory record",
	 *     tags={"Entity"},
	 *     @OA\Parameter(name="type", in="path", required=true, description="Entity type key", @OA\Schema(type="string")),
	 *     @OA\Parameter(name="entity_id", in="path", required=true, description="Entity definition ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="cat_id", in="path", required=true, description="Category ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="id", in="path", required=true, description="Item ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="inventory_id", in="path", required=true, description="Inventory record ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="location_id", in="query", required=true, description="Location ID", @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="HTML calendar interface")
	 * )
	 */
	public function inventoryCalendarPopup(Request $request, Response $response, array $args): Response
	{
		$params = $request->getQueryParams();
		$locationId = (int)($params['location_id'] ?? 0);
		if (!$locationId)
		{
			throw new HttpBadRequestException($request, 'Missing required query parameter: location_id');
		}

		$popup = $this->runLegacyEntityPopup('inventory_calendar', [
			'location_id' => $locationId,
			'id' => (int)$args['id'],
			'inventory_id' => (int)$args['inventory_id'],
		]);

		if (is_array($popup['result']))
		{
			return $this->jsonResponse($response, $popup['result']);
		}

		$response->getBody()->write($popup['html']);
		return $response->withHeader('Content-Type', 'text/html')->withStatus(200);
	}

	/**
	 * @OA\Get(
	 *     path="/property/entity/{type}/{entity_id}/{cat_id}/assigned-history",
	 *     summary="Show assigned history popup",
	 *     description="Renders the assigned history interface for a control series",
	 *     tags={"Entity"},
	 *     @OA\Parameter(name="type", in="path", required=true, description="Entity type key", @OA\Schema(type="string")),
	 *     @OA\Parameter(name="entity_id", in="path", required=true, description="Entity definition ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="cat_id", in="path", required=true, description="Category ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="serie_id", in="query", required=true, description="Control series ID", @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="HTML history interface")
	 * )
	 */
	public function assignedHistoryPopup(Request $request, Response $response, array $args): Response
	{
		$params = $request->getQueryParams();
		$serieId = (int)($params['serie_id'] ?? 0);
		if (!$serieId)
		{
			throw new HttpBadRequestException($request, 'Missing required query parameter: serie_id');
		}

		$helper = $this->controllerHelper($args);
		$backupGet = $_GET;
		$backupRequest = $_REQUEST;

		try
		{
			$_GET['serie_id'] = $serieId;
			$_REQUEST['serie_id'] = $serieId;

			ob_start();
			$helper->get_assigned_history();
			$html = (string)ob_get_clean();
		}
		finally
		{
			$_GET = $backupGet;
			$_REQUEST = $backupRequest;
		}

		$response->getBody()->write($html ?? '');
		return $response->withHeader('Content-Type', 'text/html')->withStatus(200);
	}

	/**
	 * Return controller cases linked to this entity item.
	 *
	 * @OA\Get(
	 *     path="/property/entity/{type}/{entity_id}/{cat_id}/cases",
	 *     summary="Get controller cases",
	 *     description="Returns controller cases linked to the given entity item.",
	 *     tags={"Entity"},
	 *     @OA\Parameter(name="type", in="path", required=true, description="Entity type key", @OA\Schema(type="string")),
	 *     @OA\Parameter(name="entity_id", in="path", required=true, description="Entity definition ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="cat_id", in="path", required=true, description="Category ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="id", in="query", required=false, description="Item ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="location_id", in="query", required=false, description="Location ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="year", in="query", required=false, description="Year filter", @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="DataTables response with case rows")
	 * )
	 */
	public function getCases(Request $request, Response $response, array $args): Response
	{
		$this->assertEntityAcl($request, $args, ACL_READ, 'No read access for this entity category');
		$params = $request->getQueryParams();
		$rows = $this->controllerHelper($args)->get_cases(
			(int)($params['location_id'] ?? 0),
			(int)($params['id'] ?? 0),
			(int)($params['year'] ?? 0)
		);

		return $this->datatableResponse($response, $request, (array)$rows);
	}

	/**
	 * Return controller checklists linked to this entity item.
	 *
	 * @OA\Get(
	 *     path="/property/entity/{type}/{entity_id}/{cat_id}/checklists",
	 *     summary="Get controller checklists",
	 *     description="Returns controller checklists linked to the given entity item.",
	 *     tags={"Entity"},
	 *     @OA\Parameter(name="type", in="path", required=true, description="Entity type key", @OA\Schema(type="string")),
	 *     @OA\Parameter(name="entity_id", in="path", required=true, description="Entity definition ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="cat_id", in="path", required=true, description="Category ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="id", in="query", required=false, description="Item ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="location_id", in="query", required=false, description="Location ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="year", in="query", required=false, description="Year filter", @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="DataTables response with checklist rows")
	 * )
	 */
	public function getChecklists(Request $request, Response $response, array $args): Response
	{
		$this->assertEntityAcl($request, $args, ACL_READ, 'No read access for this entity category');
		$params = $request->getQueryParams();
		$rows = $this->controllerHelper($args)->get_checklists(
			(int)($params['location_id'] ?? 0),
			(int)($params['id'] ?? 0),
			(int)($params['year'] ?? 0)
		);

		return $this->datatableResponse($response, $request, (array)$rows);
	}

	/**
	 * Return controller controls attached to this entity component.
	 *
	 * @OA\Get(
	 *     path="/property/entity/{type}/{entity_id}/{cat_id}/controls",
	 *     summary="Get controls at component",
	 *     description="Returns controller inspection controls attached to this entity item/component.",
	 *     tags={"Entity"},
	 *     @OA\Parameter(name="type", in="path", required=true, description="Entity type key", @OA\Schema(type="string")),
	 *     @OA\Parameter(name="entity_id", in="path", required=true, description="Entity definition ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="cat_id", in="path", required=true, description="Category ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="id", in="query", required=false, description="Item ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="location_id", in="query", required=false, description="Location ID", @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="DataTables response with control rows")
	 * )
	 */
	public function getControlsAtComponent(Request $request, Response $response, array $args): Response
	{
		$this->assertEntityAcl($request, $args, ACL_READ, 'No read access for this entity category');
		$params = $request->getQueryParams();
		$rows = $this->controllerHelper($args)->get_controls_at_component(
			(int)($params['location_id'] ?? 0),
			(int)($params['id'] ?? 0),
			true
		);

		return $this->datatableResponse($response, $request, (array)$rows);
	}

	/**
	 * Return cases belonging to a specific checklist.
	 *
	 * @OA\Get(
	 *     path="/property/entity/{type}/{entity_id}/{cat_id}/cases-for-checklist",
	 *     summary="Get cases for checklist",
	 *     description="Returns controller cases belonging to a specific checklist linked to this entity.",
	 *     tags={"Entity"},
	 *     @OA\Parameter(name="type", in="path", required=true, description="Entity type key", @OA\Schema(type="string")),
	 *     @OA\Parameter(name="entity_id", in="path", required=true, description="Entity definition ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="cat_id", in="path", required=true, description="Category ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="check_list_id", in="query", required=false, description="Checklist ID", @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="DataTables response with case rows")
	 * )
	 */
	public function getCasesForChecklist(Request $request, Response $response, array $args): Response
	{
		$this->assertEntityAcl($request, $args, ACL_READ, 'No read access for this entity category');
		$checkListId = (int)($request->getQueryParams()['check_list_id'] ?? 0);
		$rows = $this->controllerHelper($args)->get_cases_for_checklist($checkListId > 0 ? $checkListId : null);

		return $this->datatableResponse($response, $request, (array)$rows);
	}

	/**
	 * Download the current entity list as a file using property_bocommon::download().
	 *
	 * @OA\Get(
	 *     path="/property/entity/{type}/{entity_id}/{cat_id}/download",
	 *     summary="Download entity list as spreadsheet",
	 *     description="Exports the entity list in CSV, Excel, or ODS format",
	 *     tags={"Entity"},
	 *     @OA\Parameter(name="type", in="path", required=true, description="Entity type key", @OA\Schema(type="string")),
	 *     @OA\Parameter(name="entity_id", in="path", required=true, description="Entity definition ID", @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="cat_id", in="path", required=true, description="Category ID", @OA\Schema(type="integer")),
	 *     @OA\Response(
	 *         response=200,
	 *         description="File download",
	 *         @OA\MediaType(mediaType="text/csv"),
	 *         @OA\MediaType(mediaType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"),
	 *         @OA\MediaType(mediaType="application/vnd.oasis.opendocument.spreadsheet")
	 *     )
	 * )
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
		$bo = $this->assertEntityAcl($request, $args, ACL_READ, 'No read access for this entity category');
		$bo->allrows = true;
		$list = $bo->read(['allrows' => true]);
		$list = $this->enrichRows((array)$list, $bo);
		$uicols = $bo->uicols;
		$bocommon = new \App\modules\property\helpers\BoCommon();
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
