<?php

namespace App\modules\phpgwapi\controllers;

use App\modules\phpgwapi\services\ScimResponse;
use App\modules\phpgwapi\services\ScimFilterParser;
use App\modules\phpgwapi\services\ScimProvisioningRepository;
use App\modules\phpgwapi\services\ScimProvisioningStore;
use App\modules\phpgwapi\services\ScimResourceMapper;
use InvalidArgumentException;
use PDOException;
use Throwable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @OA\SecurityScheme(
 *     securityScheme="scimBearerAuth",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="opaque"
 * )
 * @OA\Schema(
 *     schema="ScimError",
 *     type="object",
 *     required={"schemas", "status", "detail"},
 *     @OA\Property(property="schemas", type="array", @OA\Items(type="string")),
 *     @OA\Property(property="status", type="string"),
 *     @OA\Property(property="scimType", type="string", nullable=true),
 *     @OA\Property(property="detail", type="string")
 * )
 * @OA\Schema(
 *     schema="ScimUser",
 *     type="object",
 *     required={"schemas", "id", "externalId", "userName", "active"},
 *     @OA\Property(property="schemas", type="array", @OA\Items(type="string")),
 *     @OA\Property(property="id", type="string", readOnly=true),
 *     @OA\Property(property="externalId", type="string"),
 *     @OA\Property(property="userName", type="string"),
 *     @OA\Property(property="active", type="boolean"),
 *     @OA\Property(property="name", type="object",
 *         @OA\Property(property="givenName", type="string"),
 *         @OA\Property(property="familyName", type="string")
 *     ),
 *     @OA\Property(property="displayName", type="string"),
 *     @OA\Property(property="emails", type="array", @OA\Items(type="object",
 *         @OA\Property(property="value", type="string"),
 *         @OA\Property(property="type", type="string"),
 *         @OA\Property(property="primary", type="boolean")
 *     )),
 *     @OA\Property(property="meta", type="object",
 *         @OA\Property(property="resourceType", type="string"),
 *         @OA\Property(property="location", type="string", format="uri")
 *     )
 * )
 * @OA\Schema(
 *     schema="ScimGroup",
 *     type="object",
 *     required={"schemas", "id", "externalId", "displayName"},
 *     @OA\Property(property="schemas", type="array", @OA\Items(type="string")),
 *     @OA\Property(property="id", type="string", readOnly=true),
 *     @OA\Property(property="externalId", type="string"),
 *     @OA\Property(property="displayName", type="string"),
 *     @OA\Property(property="members", type="array", @OA\Items(type="object",
 *         @OA\Property(property="value", type="string"),
 *         @OA\Property(property="display", type="string")
 *     )),
 *     @OA\Property(property="meta", type="object",
 *         @OA\Property(property="resourceType", type="string"),
 *         @OA\Property(property="location", type="string", format="uri")
 *     )
 * )
 * @OA\Schema(
 *     schema="ScimListResponse",
 *     type="object",
 *     required={"schemas", "totalResults", "startIndex", "itemsPerPage", "Resources"},
 *     @OA\Property(property="schemas", type="array", @OA\Items(type="string")),
 *     @OA\Property(property="totalResults", type="integer"),
 *     @OA\Property(property="startIndex", type="integer"),
 *     @OA\Property(property="itemsPerPage", type="integer"),
 *     @OA\Property(property="Resources", type="array", @OA\Items(type="object"))
 * )
 */
final class ScimController
{
	private const USER_SCHEMA = 'urn:ietf:params:scim:schemas:core:2.0:User';
	private const GROUP_SCHEMA = 'urn:ietf:params:scim:schemas:core:2.0:Group';
	private ?ScimProvisioningStore $store;
	private ScimFilterParser $filterParser;
	private ScimResourceMapper $mapper;
	private ?string $publicBaseUrl;

	public function __construct(
		?ScimProvisioningStore $store = null,
		?ScimFilterParser $filterParser = null,
		?ScimResourceMapper $mapper = null,
		?string $publicBaseUrl = null
	)
	{
		$this->store = $store;
		$this->filterParser = $filterParser ?? new ScimFilterParser();
		$this->mapper = $mapper ?? new ScimResourceMapper();
		$configuredBaseUrl = $publicBaseUrl ?? getenv('SCIM_PUBLIC_BASE_URL');
		$this->publicBaseUrl = is_string($configuredBaseUrl) && trim($configuredBaseUrl) !== ''
			? rtrim(trim($configuredBaseUrl), '/')
			: null;
	}

	/**
	 * @OA\Get(
	 *     path="/api/scim/v2/ServiceProviderConfig",
	 *     operationId="scimServiceProviderConfig",
	 *     tags={"SCIM 2.0"},
	 *     summary="Read SCIM provider capabilities",
	 *     security={{"scimBearerAuth": {}}},
	 *     @OA\Response(response=200, description="SCIM provider configuration", @OA\JsonContent(type="object")),
	 *     @OA\Response(response=401, description="Missing or invalid bearer token", @OA\JsonContent(ref="#/components/schemas/ScimError")),
	 *     @OA\Response(response=503, description="SCIM is not configured", @OA\JsonContent(ref="#/components/schemas/ScimError"))
	 * )
	 */
	public function serviceProviderConfig(
		ServerRequestInterface $request,
		ResponseInterface $response
	): ResponseInterface
	{
		return ScimResponse::json([
			'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ServiceProviderConfig'],
			'documentationUri' => 'https://datatracker.ietf.org/doc/html/rfc7644',
			'patch' => ['supported' => true],
			'bulk' => ['supported' => false, 'maxOperations' => 0, 'maxPayloadSize' => 0],
			'filter' => ['supported' => true, 'maxResults' => 100],
			'changePassword' => ['supported' => false],
			'sort' => ['supported' => false],
			'etag' => ['supported' => false],
			'authenticationSchemes' => [[
				'type' => 'oauthbearertoken',
				'name' => 'Bearer Token',
				'description' => 'Static bearer token configured for Microsoft Entra provisioning',
				'primary' => true,
			]],
		], 200, $response);
	}

	/**
	 * @OA\Get(
	 *     path="/api/scim/v2/ResourceTypes",
	 *     operationId="scimResourceTypes",
	 *     tags={"SCIM 2.0"},
	 *     summary="List supported SCIM resource types",
	 *     security={{"scimBearerAuth": {}}},
	 *     @OA\Response(response=200, description="Supported User and Group resource types", @OA\JsonContent(ref="#/components/schemas/ScimListResponse")),
	 *     @OA\Response(response=401, description="Missing or invalid bearer token", @OA\JsonContent(ref="#/components/schemas/ScimError"))
	 * )
	 */
	public function resourceTypes(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		return ScimResponse::json([
			'schemas' => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'],
			'totalResults' => 2,
			'itemsPerPage' => 2,
			'startIndex' => 1,
			'Resources' => [
				[
					'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ResourceType'],
					'id' => 'User',
					'name' => 'User',
					'endpoint' => '/Users',
					'schema' => self::USER_SCHEMA,
				],
				[
					'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ResourceType'],
					'id' => 'Group',
					'name' => 'Group',
					'endpoint' => '/Groups',
					'schema' => self::GROUP_SCHEMA,
				],
			],
		], 200, $response);
	}

	/**
	 * @OA\Get(
	 *     path="/api/scim/v2/Schemas",
	 *     operationId="scimSchemas",
	 *     tags={"SCIM 2.0"},
	 *     summary="List supported SCIM schemas",
	 *     security={{"scimBearerAuth": {}}},
	 *     @OA\Response(response=200, description="User and Group schemas", @OA\JsonContent(ref="#/components/schemas/ScimListResponse")),
	 *     @OA\Response(response=401, description="Missing or invalid bearer token", @OA\JsonContent(ref="#/components/schemas/ScimError"))
	 * )
	 */
	public function schemas(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		return ScimResponse::json([
			'schemas' => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'],
			'totalResults' => 2,
			'itemsPerPage' => 2,
			'startIndex' => 1,
			'Resources' => [
				$this->userSchema(),
				$this->groupSchema(),
			],
		], 200, $response);
	}

	/**
	 * @OA\Get(
	 *     path="/api/scim/v2/Users",
	 *     operationId="scimListUsers",
	 *     tags={"SCIM 2.0 Users"},
	 *     summary="List provisioned users",
	 *     security={{"scimBearerAuth": {}}},
	 *     @OA\Parameter(name="filter", in="query", required=false, description="Supports userName eq, externalId eq and displayName eq", @OA\Schema(type="string")),
	 *     @OA\Parameter(name="startIndex", in="query", required=false, description="1-based page index", @OA\Schema(type="integer", minimum=1, default=1)),
	 *     @OA\Parameter(name="count", in="query", required=false, description="Page size, capped at 100", @OA\Schema(type="integer", minimum=0, maximum=100, default=100)),
	 *     @OA\Response(response=200, description="Paginated SCIM User list", @OA\JsonContent(ref="#/components/schemas/ScimListResponse")),
	 *     @OA\Response(response=400, description="Unsupported SCIM filter", @OA\JsonContent(ref="#/components/schemas/ScimError")),
	 *     @OA\Response(response=401, description="Missing or invalid bearer token", @OA\JsonContent(ref="#/components/schemas/ScimError"))
	 * )
	 */
	public function users(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$query = $request->getQueryParams();
		$startIndex = max(1, (int) ($query['startIndex'] ?? 1));
		$count = min(100, max(0, (int) ($query['count'] ?? 100)));

		try
		{
			$filter = $this->filterParser->parse($query['filter'] ?? null);
			$result = $this->store()->list('u', $startIndex - 1, $count, $filter);
		}
		catch (InvalidArgumentException $exception)
		{
			return ScimResponse::error(400, $exception->getMessage(), 'invalidFilter');
		}

		$baseUrl = $this->baseUrl($request);
		$resources = array_map(
			fn(array $row): array => $this->mapper->user($row, $baseUrl),
			$result['resources']
		);

		return ScimResponse::json([
			'schemas' => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'],
			'totalResults' => (int) $result['total'],
			'startIndex' => $startIndex,
			'itemsPerPage' => count($resources),
			'Resources' => $resources,
		], 200, $response);
	}

	/**
	 * @OA\Get(
	 *     path="/api/scim/v2/Users/{id}",
	 *     operationId="scimGetUser",
	 *     tags={"SCIM 2.0 Users"},
	 *     summary="Get a provisioned user",
	 *     security={{"scimBearerAuth": {}}},
	 *     @OA\Parameter(name="id", in="path", required=true, description="Local account ID used as SCIM id", @OA\Schema(type="integer", minimum=1)),
	 *     @OA\Response(response=200, description="SCIM User", @OA\JsonContent(ref="#/components/schemas/ScimUser")),
	 *     @OA\Response(response=401, description="Missing or invalid bearer token", @OA\JsonContent(ref="#/components/schemas/ScimError")),
	 *     @OA\Response(response=404, description="User not found", @OA\JsonContent(ref="#/components/schemas/ScimError"))
	 * )
	 */
	public function user(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$id = filter_var($args['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
		if ($id === false)
		{
			return ScimResponse::error(404, 'User not found');
		}

		$row = $this->store()->findById($id, 'u');
		if ($row === null)
		{
			return ScimResponse::error(404, 'User not found');
		}

		return ScimResponse::json($this->mapper->user($row, $this->baseUrl($request)), 200, $response);
	}

	/**
	 * @OA\Post(
	 *     path="/api/scim/v2/Users",
	 *     operationId="scimCreateUser",
	 *     tags={"SCIM 2.0 Users"},
	 *     summary="Create a provisioned user",
	 *     description="Idempotent by externalId within the configured Entra tenant.",
	 *     security={{"scimBearerAuth": {}}},
	 *     @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/ScimUser")),
	 *     @OA\Response(response=201, description="User created", @OA\JsonContent(ref="#/components/schemas/ScimUser")),
	 *     @OA\Response(response=200, description="User already exists for externalId", @OA\JsonContent(ref="#/components/schemas/ScimUser")),
	 *     @OA\Response(response=400, description="Invalid User resource", @OA\JsonContent(ref="#/components/schemas/ScimError")),
	 *     @OA\Response(response=401, description="Missing or invalid bearer token", @OA\JsonContent(ref="#/components/schemas/ScimError")),
	 *     @OA\Response(response=409, description="User uniqueness conflict", @OA\JsonContent(ref="#/components/schemas/ScimError"))
	 * )
	 */
	public function createUser(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		try
		{
			$data = $this->normalizeUser($this->body($request));
			$existing = $this->store()->findByExternalId($data['externalId'], 'u');
			if ($existing !== null)
			{
				return ScimResponse::json($this->mapper->user($existing, $this->baseUrl($request)), 200, $response);
			}

			$row = $this->store()->createUser($data);
			$resource = $this->mapper->user($row, $this->baseUrl($request));
			return ScimResponse::json($resource, 201, $response)
				->withHeader('Location', $resource['meta']['location']);
		}
		catch (InvalidArgumentException $exception)
		{
			return ScimResponse::error(400, $exception->getMessage(), 'invalidValue');
		}
		catch (PDOException $exception)
		{
			return $this->databaseWriteError($exception, 'User');
		}
		catch (Throwable $exception)
		{
			return ScimResponse::error(500, 'User provisioning failed');
		}
	}

	/**
	 * @OA\Put(
	 *     path="/api/scim/v2/Users/{id}",
	 *     operationId="scimReplaceUser",
	 *     tags={"SCIM 2.0 Users"},
	 *     summary="Replace a provisioned user's SCIM-managed attributes",
	 *     security={{"scimBearerAuth": {}}},
	 *     @OA\Parameter(name="id", in="path", required=true, description="Local account ID used as SCIM id", @OA\Schema(type="integer", minimum=1)),
	 *     @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/ScimUser")),
	 *     @OA\Response(response=200, description="Updated User", @OA\JsonContent(ref="#/components/schemas/ScimUser")),
	 *     @OA\Response(response=400, description="Invalid User resource", @OA\JsonContent(ref="#/components/schemas/ScimError")),
	 *     @OA\Response(response=401, description="Missing or invalid bearer token", @OA\JsonContent(ref="#/components/schemas/ScimError")),
	 *     @OA\Response(response=404, description="User not found", @OA\JsonContent(ref="#/components/schemas/ScimError")),
	 *     @OA\Response(response=409, description="User uniqueness conflict", @OA\JsonContent(ref="#/components/schemas/ScimError"))
	 * )
	 */
	public function replaceUser(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args
	): ResponseInterface
	{
		return $this->updateUserResource($request, $response, $args, false);
	}

	/**
	 * @OA\Patch(
	 *     path="/api/scim/v2/Users/{id}",
	 *     operationId="scimPatchUser",
	 *     tags={"SCIM 2.0 Users"},
	 *     summary="Patch a user's SCIM-managed attributes",
	 *     description="Supports Replace for active, userName, name.givenName, name.familyName, displayName and work email, including Entra path-less Replace values.",
	 *     security={{"scimBearerAuth": {}}},
	 *     @OA\Parameter(name="id", in="path", required=true, description="Local account ID used as SCIM id", @OA\Schema(type="integer", minimum=1)),
	 *     @OA\RequestBody(required=true, @OA\JsonContent(type="object", required={"Operations"}, @OA\Property(property="schemas", type="array", @OA\Items(type="string")), @OA\Property(property="Operations", type="array", @OA\Items(type="object")))),
	 *     @OA\Response(response=200, description="Updated User", @OA\JsonContent(ref="#/components/schemas/ScimUser")),
	 *     @OA\Response(response=400, description="Invalid PatchOp", @OA\JsonContent(ref="#/components/schemas/ScimError")),
	 *     @OA\Response(response=401, description="Missing or invalid bearer token", @OA\JsonContent(ref="#/components/schemas/ScimError")),
	 *     @OA\Response(response=404, description="User not found", @OA\JsonContent(ref="#/components/schemas/ScimError")),
	 *     @OA\Response(response=409, description="User uniqueness conflict", @OA\JsonContent(ref="#/components/schemas/ScimError"))
	 * )
	 */
	public function patchUser(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args
	): ResponseInterface
	{
		return $this->updateUserResource($request, $response, $args, true);
	}

	/**
	 * @OA\Delete(
	 *     path="/api/scim/v2/Users/{id}",
	 *     operationId="scimDeleteUser",
	 *     tags={"SCIM 2.0 Users"},
	 *     summary="Deactivate a provisioned user",
	 *     description="Soft-deactivates the account; it does not physically delete it.",
	 *     security={{"scimBearerAuth": {}}},
	 *     @OA\Parameter(name="id", in="path", required=true, description="Local account ID used as SCIM id", @OA\Schema(type="integer", minimum=1)),
	 *     @OA\Response(response=204, description="User deactivated"),
	 *     @OA\Response(response=401, description="Missing or invalid bearer token", @OA\JsonContent(ref="#/components/schemas/ScimError")),
	 *     @OA\Response(response=404, description="User not found", @OA\JsonContent(ref="#/components/schemas/ScimError"))
	 * )
	 */
	public function deleteUser(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args
	): ResponseInterface
	{
		$id = $this->resourceId($args);
		if ($id === null || !$this->store()->deactivate($id, 'u'))
		{
			return ScimResponse::error(404, 'User not found');
		}

		return $response->withStatus(204);
	}

	/**
	 * @OA\Get(
	 *     path="/api/scim/v2/Groups",
	 *     operationId="scimListGroups",
	 *     tags={"SCIM 2.0 Groups"},
	 *     summary="List groups mapped to the configured Entra tenant",
	 *     security={{"scimBearerAuth": {}}},
	 *     @OA\Parameter(name="filter", in="query", required=false, description="Supports displayName eq and externalId eq", @OA\Schema(type="string")),
	 *     @OA\Parameter(name="startIndex", in="query", required=false, description="1-based page index", @OA\Schema(type="integer", minimum=1, default=1)),
	 *     @OA\Parameter(name="count", in="query", required=false, description="Page size, capped at 100", @OA\Schema(type="integer", minimum=0, maximum=100, default=100)),
	 *     @OA\Response(response=200, description="Paginated SCIM Group list", @OA\JsonContent(ref="#/components/schemas/ScimListResponse")),
	 *     @OA\Response(response=400, description="Unsupported SCIM filter", @OA\JsonContent(ref="#/components/schemas/ScimError")),
	 *     @OA\Response(response=401, description="Missing or invalid bearer token", @OA\JsonContent(ref="#/components/schemas/ScimError"))
	 * )
	 */
	public function groups(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$query = $request->getQueryParams();
		$startIndex = max(1, (int) ($query['startIndex'] ?? 1));
		$count = min(100, max(0, (int) ($query['count'] ?? 100)));
		try
		{
			$filter = $this->filterParser->parse($query['filter'] ?? null);
			$result = $this->store()->list('g', $startIndex - 1, $count, $filter);
		}
		catch (InvalidArgumentException $exception)
		{
			return ScimResponse::error(400, $exception->getMessage(), 'invalidFilter');
		}

		$baseUrl = $this->baseUrl($request);
		$resources = array_map(
			fn(array $row): array => $this->mapper->group($row, $baseUrl),
			$result['resources']
		);

		return ScimResponse::json([
			'schemas' => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'],
			'totalResults' => (int) $result['total'],
			'startIndex' => $startIndex,
			'itemsPerPage' => count($resources),
			'Resources' => $resources,
		], 200, $response);
	}

	/**
	 * @OA\Get(
	 *     path="/api/scim/v2/Groups/{id}",
	 *     operationId="scimGetGroup",
	 *     tags={"SCIM 2.0 Groups"},
	 *     summary="Get a provisioned group and its SCIM-mapped members",
	 *     security={{"scimBearerAuth": {}}},
	 *     @OA\Parameter(name="id", in="path", required=true, description="Local group account ID used as SCIM id", @OA\Schema(type="integer", minimum=1)),
	 *     @OA\Response(response=200, description="SCIM Group", @OA\JsonContent(ref="#/components/schemas/ScimGroup")),
	 *     @OA\Response(response=401, description="Missing or invalid bearer token", @OA\JsonContent(ref="#/components/schemas/ScimError")),
	 *     @OA\Response(response=404, description="Group not found", @OA\JsonContent(ref="#/components/schemas/ScimError"))
	 * )
	 */
	public function group(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$id = $this->resourceId($args);
		$row = $id === null ? null : $this->store()->findById($id, 'g');
		if ($row === null)
		{
			return ScimResponse::error(404, 'Group not found');
		}

		return ScimResponse::json(
			$this->mapper->group($row, $this->baseUrl($request), $this->store()->groupMembers($id)),
			200,
			$response
		);
	}

	/**
	 * @OA\Post(
	 *     path="/api/scim/v2/Groups",
	 *     operationId="scimCreateGroup",
	 *     tags={"SCIM 2.0 Groups"},
	 *     summary="Create a provisioned group",
	 *     description="Idempotent by externalId within the configured Entra tenant.",
	 *     security={{"scimBearerAuth": {}}},
	 *     @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/ScimGroup")),
	 *     @OA\Response(response=201, description="Group created", @OA\JsonContent(ref="#/components/schemas/ScimGroup")),
	 *     @OA\Response(response=200, description="Group already exists for externalId", @OA\JsonContent(ref="#/components/schemas/ScimGroup")),
	 *     @OA\Response(response=400, description="Invalid Group resource", @OA\JsonContent(ref="#/components/schemas/ScimError")),
	 *     @OA\Response(response=401, description="Missing or invalid bearer token", @OA\JsonContent(ref="#/components/schemas/ScimError")),
	 *     @OA\Response(response=409, description="Group uniqueness conflict", @OA\JsonContent(ref="#/components/schemas/ScimError"))
	 * )
	 */
	public function createGroup(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		try
		{
			$data = $this->normalizeGroup($this->body($request));
			$existing = $this->store()->findByExternalId($data['externalId'], 'g');
			if ($existing !== null)
			{
				return ScimResponse::json($this->mapper->group($existing, $this->baseUrl($request)), 200, $response);
			}
			$row = $this->store()->createGroup($data);
			$resource = $this->mapper->group($row, $this->baseUrl($request));

			return ScimResponse::json($resource, 201, $response)
				->withHeader('Location', $resource['meta']['location']);
		}
		catch (InvalidArgumentException $exception)
		{
			return ScimResponse::error(400, $exception->getMessage(), 'invalidValue');
		}
		catch (PDOException $exception)
		{
			return $this->databaseWriteError($exception, 'Group');
		}
		catch (Throwable $exception)
		{
			return ScimResponse::error(500, 'Group provisioning failed');
		}
	}

	/**
	 * @OA\Patch(
	 *     path="/api/scim/v2/Groups/{id}",
	 *     operationId="scimPatchGroup",
	 *     tags={"SCIM 2.0 Groups"},
	 *     summary="Rename a group or add/remove members",
	 *     description="Supports Replace displayName, Add members, and Remove members filtered by a quoted member id.",
	 *     security={{"scimBearerAuth": {}}},
	 *     @OA\Parameter(name="id", in="path", required=true, description="Local group account ID used as SCIM id", @OA\Schema(type="integer", minimum=1)),
	 *     @OA\RequestBody(required=true, @OA\JsonContent(type="object", required={"Operations"}, @OA\Property(property="schemas", type="array", @OA\Items(type="string")), @OA\Property(property="Operations", type="array", @OA\Items(type="object")))),
	 *     @OA\Response(response=200, description="Updated Group and membership", @OA\JsonContent(ref="#/components/schemas/ScimGroup")),
	 *     @OA\Response(response=400, description="Unsupported operation or unknown member", @OA\JsonContent(ref="#/components/schemas/ScimError")),
	 *     @OA\Response(response=401, description="Missing or invalid bearer token", @OA\JsonContent(ref="#/components/schemas/ScimError")),
	 *     @OA\Response(response=404, description="Group not found", @OA\JsonContent(ref="#/components/schemas/ScimError"))
	 * )
	 */
	public function patchGroup(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$id = $this->resourceId($args);
		$current = $id === null ? null : $this->store()->findById($id, 'g');
		if ($current === null)
		{
			return ScimResponse::error(404, 'Group not found');
		}

		try
		{
			$body = $this->body($request);
			if (!isset($body['Operations']) || !is_array($body['Operations']))
			{
				throw new InvalidArgumentException('PatchOp Operations must be an array');
			}

			$displayName = (string) $current['account_lid'];
			$addMembers = [];
			$removeMembers = [];
			foreach ($body['Operations'] as $operation)
			{
				$op = strtolower((string) ($operation['op'] ?? ''));
				$path = (string) ($operation['path'] ?? '');
				$value = $operation['value'] ?? null;
				if ($op === 'replace' && strcasecmp($path, 'displayName') === 0)
				{
					$displayName = trim((string) $value);
					continue;
				}
				if ($op === 'add' && strcasecmp($path, 'members') === 0 && is_array($value))
				{
					foreach ($value as $member)
					{
						$addMembers[] = (int) ($member['value'] ?? 0);
					}
					continue;
				}
				if ($op === 'remove' && preg_match('/^members\[value eq "([0-9]+)"\]$/i', $path, $matches))
				{
					$removeMembers[] = (int) $matches[1];
					continue;
				}
				throw new InvalidArgumentException('Unsupported Group PATCH operation');
			}

			if ($displayName === '')
			{
				throw new InvalidArgumentException('displayName is required');
			}
			$row = $this->store()->patchGroup($id, $displayName, $addMembers, $removeMembers);
			if ($row === null)
			{
				return ScimResponse::error(404, 'Group not found');
			}

			return ScimResponse::json(
				$this->mapper->group($row, $this->baseUrl($request), $this->store()->groupMembers($id)),
				200,
				$response
			);
		}
		catch (InvalidArgumentException $exception)
		{
			return ScimResponse::error(400, $exception->getMessage(), 'invalidValue');
		}
	}

	/**
	 * @OA\Delete(
	 *     path="/api/scim/v2/Groups/{id}",
	 *     operationId="scimDeleteGroup",
	 *     tags={"SCIM 2.0 Groups"},
	 *     summary="Deactivate a provisioned group",
	 *     description="Soft-deactivates the group; it does not physically delete it.",
	 *     security={{"scimBearerAuth": {}}},
	 *     @OA\Parameter(name="id", in="path", required=true, description="Local group account ID used as SCIM id", @OA\Schema(type="integer", minimum=1)),
	 *     @OA\Response(response=204, description="Group deactivated"),
	 *     @OA\Response(response=401, description="Missing or invalid bearer token", @OA\JsonContent(ref="#/components/schemas/ScimError")),
	 *     @OA\Response(response=404, description="Group not found", @OA\JsonContent(ref="#/components/schemas/ScimError"))
	 * )
	 */
	public function deleteGroup(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$id = $this->resourceId($args);
		if ($id === null || !$this->store()->deactivate($id, 'g'))
		{
			return ScimResponse::error(404, 'Group not found');
		}

		return $response->withStatus(204);
	}

	private function updateUserResource(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
		bool $patch
	): ResponseInterface
	{
		$id = $this->resourceId($args);
		$current = $id === null ? null : $this->store()->findById($id, 'u');
		if ($current === null)
		{
			return ScimResponse::error(404, 'User not found');
		}

		try
		{
			$body = $this->body($request);
			$data = $patch
				? $this->applyUserPatch($current, $body)
				: $this->normalizeUser($body, (string) $current['external_id']);
			$updated = $this->store()->updateUser($id, $data);
			if ($updated === null)
			{
				return ScimResponse::error(404, 'User not found');
			}

			return ScimResponse::json($this->mapper->user($updated, $this->baseUrl($request)), 200, $response);
		}
		catch (InvalidArgumentException $exception)
		{
			return ScimResponse::error(400, $exception->getMessage(), 'invalidValue');
		}
		catch (PDOException $exception)
		{
			return $this->databaseWriteError($exception, 'User');
		}
		catch (Throwable $exception)
		{
			return ScimResponse::error(500, 'User provisioning failed');
		}
	}

	private function applyUserPatch(array $current, array $body): array
	{
		if (!isset($body['Operations']) || !is_array($body['Operations']))
		{
			throw new InvalidArgumentException('PatchOp Operations must be an array');
		}

		$data = [
			'externalId' => (string) $current['external_id'],
			'userName' => (string) $current['account_lid'],
			'givenName' => (string) $current['account_firstname'],
			'familyName' => (string) $current['account_lastname'],
			'active' => $current['account_status'] === 'A',
			'email' => (string) ($current['email'] ?? ''),
		];

		foreach ($body['Operations'] as $operation)
		{
			if (strcasecmp((string) ($operation['op'] ?? ''), 'replace') !== 0)
			{
				throw new InvalidArgumentException('Only Replace is supported for User PATCH');
			}
			$path = strtolower((string) ($operation['path'] ?? ''));
			$value = $operation['value'] ?? null;
			if ($path === '' && is_array($value))
			{
				foreach ($value as $attribute => $attributeValue)
				{
					$this->applyUserPatchValue($data, strtolower((string) $attribute), $attributeValue);
				}
				continue;
			}
			$this->applyUserPatchValue($data, $path, $value);
		}

		return $this->normalizeUser($data, $data['externalId']);
	}

	private function applyUserPatchValue(array &$data, string $path, mixed $value): void
	{
			switch ($path)
			{
				case 'active':
					$data['active'] = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
						?? throw new InvalidArgumentException('active must be boolean');
					break;
				case 'username':
					$data['userName'] = (string) $value;
					break;
				case 'name.givenname':
					$data['givenName'] = (string) $value;
					break;
				case 'name.familyname':
					$data['familyName'] = (string) $value;
					break;
				case 'displayname':
					break;
				case 'emails':
				case 'emails[type eq "work"].value':
					$data['email'] = is_array($value)
						? (string) ($value[0]['value'] ?? '')
						: (string) $value;
					break;
				default:
					throw new InvalidArgumentException('Unsupported User PATCH path');
			}
	}

	private function normalizeUser(array $body, ?string $externalId = null): array
	{
		$name = is_array($body['name'] ?? null) ? $body['name'] : [];
		$emails = is_array($body['emails'] ?? null) ? $body['emails'] : [];
		if (array_key_exists('active', $body) && !is_bool($body['active']))
		{
			throw new InvalidArgumentException('active must be boolean');
		}
		$data = [
			'externalId' => trim($externalId ?? (string) ($body['externalId'] ?? '')),
			'userName' => trim((string) ($body['userName'] ?? '')),
			'givenName' => trim((string) ($body['givenName'] ?? $name['givenName'] ?? '')),
			'familyName' => trim((string) ($body['familyName'] ?? $name['familyName'] ?? '')),
			'active' => array_key_exists('active', $body) ? (bool) $body['active'] : true,
			'email' => trim((string) ($body['email'] ?? $emails[0]['value'] ?? '')),
		];

		foreach (['externalId', 'userName', 'givenName', 'familyName'] as $required)
		{
			if ($data[$required] === '')
			{
				throw new InvalidArgumentException($required . ' is required');
			}
		}
		if (strlen($data['externalId']) > 100 || strlen($data['userName']) > 100)
		{
			throw new InvalidArgumentException('externalId and userName must not exceed 100 characters');
		}

		return $data;
	}

	private function databaseWriteError(PDOException $exception, string $resource): ResponseInterface
	{
		if ((string) $exception->getCode() === '23505')
		{
			return ScimResponse::error(409, $resource . ' conflicts with an existing resource', 'uniqueness');
		}

		return ScimResponse::error(500, $resource . ' provisioning failed');
	}

	private function normalizeGroup(array $body): array
	{
		$data = [
			'externalId' => trim((string) ($body['externalId'] ?? '')),
			'displayName' => trim((string) ($body['displayName'] ?? '')),
		];
		if ($data['externalId'] === '' || $data['displayName'] === '')
		{
			throw new InvalidArgumentException('externalId and displayName are required');
		}
		if (strlen($data['externalId']) > 100 || strlen($data['displayName']) > 100)
		{
			throw new InvalidArgumentException('externalId and displayName must not exceed 100 characters');
		}

		return $data;
	}

	private function body(ServerRequestInterface $request): array
	{
		$body = $request->getParsedBody();
		if (is_array($body))
		{
			return $body;
		}

		$decoded = json_decode((string) $request->getBody(), true);
		if (!is_array($decoded))
		{
			throw new InvalidArgumentException('Request body must be a JSON object');
		}

		return $decoded;
	}

	private function resourceId(array $args): ?int
	{
		$id = filter_var($args['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

		return $id === false ? null : $id;
	}

	private function store(): ScimProvisioningStore
	{
		if ($this->store === null)
		{
			$this->store = new ScimProvisioningRepository();
		}

		return $this->store;
	}

	private function baseUrl(ServerRequestInterface $request): string
	{
		if ($this->publicBaseUrl !== null)
		{
			return $this->publicBaseUrl;
		}

		$uri = $request->getUri();
		return $uri->getScheme() . '://' . $uri->getAuthority() . '/api/scim/v2';
	}

	private function userSchema(): array
	{
		return [
			'id' => self::USER_SCHEMA,
			'name' => 'User',
			'description' => 'PorticoEstate user account',
			'attributes' => [
				['name' => 'userName', 'type' => 'string', 'multiValued' => false, 'required' => true, 'uniqueness' => 'server'],
				['name' => 'externalId', 'type' => 'string', 'multiValued' => false, 'required' => false, 'uniqueness' => 'server'],
				['name' => 'displayName', 'type' => 'string', 'multiValued' => false, 'required' => false],
				['name' => 'active', 'type' => 'boolean', 'multiValued' => false, 'required' => false],
			],
		];
	}

	private function groupSchema(): array
	{
		return [
			'id' => self::GROUP_SCHEMA,
			'name' => 'Group',
			'description' => 'PorticoEstate group account',
			'attributes' => [
				['name' => 'displayName', 'type' => 'string', 'multiValued' => false, 'required' => true, 'uniqueness' => 'server'],
				['name' => 'externalId', 'type' => 'string', 'multiValued' => false, 'required' => false, 'uniqueness' => 'server'],
				['name' => 'members', 'type' => 'complex', 'multiValued' => true, 'required' => false],
			],
		];
	}
}