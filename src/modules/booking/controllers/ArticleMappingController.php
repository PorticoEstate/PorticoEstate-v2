<?php

namespace App\modules\booking\controllers;

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\security\Acl;
use App\modules\booking\repositories\ArticleMappingRepository;
use App\modules\booking\models\ArticleMapping;
use App\Database\Db;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Exception;

class ArticleMappingController
{
	protected array $userSettings;
	protected Acl $acl;
	protected ArticleMappingRepository $repository;

	private const ARTICLE_CAT_SERVICE = 2;
	private const VALID_UNITS = ['each', 'kg', 'm', 'm2', 'minute', 'hour', 'day'];

	public function __construct(ContainerInterface $container)
	{
		$this->userSettings = Settings::getInstance()->get('user');
		$this->acl = Acl::getInstance();
		$this->repository = new ArticleMappingRepository();
	}

	/**
	 * @OA\Post(
	 *     path="/booking/article-mappings",
	 *     summary="Create a new service + article mapping + optional default price",
	 *     tags={"Article Mappings"},
	 *     @OA\RequestBody(required=true, @OA\JsonContent(
	 *         required={"name", "article_code", "unit", "tax_code"},
	 *         @OA\Property(property="name", type="string"),
	 *         @OA\Property(property="article_code", type="string"),
	 *         @OA\Property(property="unit", type="string", enum={"each","kg","m","m2","minute","hour","day"}),
	 *         @OA\Property(property="tax_code", type="integer"),
	 *         @OA\Property(property="price", type="number")
	 *     )),
	 *     @OA\Response(response=201, description="Article mapping created"),
	 *     @OA\Response(response=400, description="Validation error"),
	 *     @OA\Response(response=403, description="Permission denied"),
	 *     @OA\Response(response=409, description="Duplicate name or article code")
	 * )
	 */
	public function store(Request $request, Response $response): Response
	{
		if (!$this->acl->check('.application', Acl::ADD, 'booking')) {
			$response->getBody()->write(json_encode(['error' => 'Permission denied']));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
		}

		try {
			$body = $request->getParsedBody() ?? json_decode($request->getBody()->getContents(), true) ?? [];

			// Validate required fields
			$errors = [];
			if (empty($body['name'])) {
				$errors[] = 'name is required';
			}
			if (empty($body['article_code'])) {
				$errors[] = 'article_code is required';
			}
			if (empty($body['unit']) || !in_array($body['unit'], self::VALID_UNITS, true)) {
				$errors[] = 'unit must be one of: ' . implode(', ', self::VALID_UNITS);
			}
			if (!isset($body['tax_code']) || !is_numeric($body['tax_code'])) {
				$errors[] = 'tax_code is required and must be numeric';
			}

			if (!empty($errors)) {
				$response->getBody()->write(json_encode(['error' => implode('; ', $errors)]));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
			}

			// Uniqueness checks
			if ($this->repository->serviceNameExists(trim($body['name']))) {
				$response->getBody()->write(json_encode(['error' => 'A service with this name already exists']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(409);
			}
			if ($this->repository->articleCodeExists(trim($body['article_code']), self::ARTICLE_CAT_SERVICE)) {
				$response->getBody()->write(json_encode(['error' => 'This article code already exists for services']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(409);
			}

			$db = Db::getInstance();
			$db->beginTransaction();

			// 1. Create bb_service
			$serviceId = $this->repository->createService([
				'name' => trim($body['name']),
				'name_json' => $body['name_json'] ?? null,
				'description' => $body['description'] ?? null,
				'active' => 1,
				'owner_id' => (int)$this->userSettings['account_id'],
			]);

			// 2. Create bb_article_mapping
			$mappingId = $this->repository->createMapping([
				'article_cat_id' => self::ARTICLE_CAT_SERVICE,
				'article_id' => $serviceId,
				'article_code' => trim($body['article_code']),
				'unit' => $body['unit'],
				'tax_code' => (int)$body['tax_code'],
				'group_id' => 1,
				'owner_id' => (int)$this->userSettings['account_id'],
			]);

			// 3. Create bb_article_price (if price provided)
			if (isset($body['price']) && $body['price'] !== '' && is_numeric($body['price'])) {
				$this->repository->createDefaultPrice($mappingId, (float)$body['price']);
			}

			$db->commit();

			// Return the new mapping with all joins
			$row = $this->repository->getMappingById($mappingId);
			$model = new ArticleMapping($row);

			$response->getBody()->write(json_encode($model->serialize()));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
		} catch (Exception $e) {
			$db = Db::getInstance();
			$db->rollBack();

			$response->getBody()->write(json_encode(['error' => $e->getMessage()]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	/**
	 * @OA\Put(
	 *     path="/booking/article-mappings/{id}",
	 *     summary="Update an existing service + article mapping + default price",
	 *     tags={"Article Mappings"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\RequestBody(required=true, @OA\JsonContent(
	 *         @OA\Property(property="name", type="string"),
	 *         @OA\Property(property="name_json", type="object"),
	 *         @OA\Property(property="article_code", type="string"),
	 *         @OA\Property(property="unit", type="string", enum={"each","kg","m","m2","minute","hour","day"}),
	 *         @OA\Property(property="tax_code", type="integer"),
	 *         @OA\Property(property="price", type="number")
	 *     )),
	 *     @OA\Response(response=200, description="Article mapping updated"),
	 *     @OA\Response(response=400, description="Validation error"),
	 *     @OA\Response(response=403, description="Permission denied"),
	 *     @OA\Response(response=404, description="Not found"),
	 *     @OA\Response(response=409, description="Duplicate name or article code")
	 * )
	 */
	public function update(Request $request, Response $response, array $args): Response
	{
		if (!$this->acl->check('.application', Acl::EDIT, 'booking')) {
			$response->getBody()->write(json_encode(['error' => 'Permission denied']));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
		}

		$id = (int) $args['id'];
		$mapping = $this->repository->getMappingById($id);
		if (!$mapping) {
			$response->getBody()->write(json_encode(['error' => 'Article mapping not found']));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
		}

		try {
			$body = $request->getParsedBody() ?? json_decode($request->getBody()->getContents(), true) ?? [];

			// Validate unit if provided
			if (isset($body['unit']) && !in_array($body['unit'], self::VALID_UNITS, true)) {
				$response->getBody()->write(json_encode(['error' => 'unit must be one of: ' . implode(', ', self::VALID_UNITS)]));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
			}

			// Validate tax_code if provided
			if (isset($body['tax_code']) && !is_numeric($body['tax_code'])) {
				$response->getBody()->write(json_encode(['error' => 'tax_code must be numeric']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
			}

			$serviceId = $mapping['article_id'];

			// Uniqueness checks (excluding current records)
			if (isset($body['name']) && !empty(trim($body['name']))) {
				if ($this->repository->serviceNameExists(trim($body['name']), $serviceId)) {
					$response->getBody()->write(json_encode(['error' => 'A service with this name already exists']));
					return $response->withHeader('Content-Type', 'application/json')->withStatus(409);
				}
			}
			if (isset($body['article_code']) && !empty(trim($body['article_code']))) {
				if ($this->repository->articleCodeExists(trim($body['article_code']), self::ARTICLE_CAT_SERVICE, $id)) {
					$response->getBody()->write(json_encode(['error' => 'This article code already exists for services']));
					return $response->withHeader('Content-Type', 'application/json')->withStatus(409);
				}
			}

			$db = Db::getInstance();
			$db->beginTransaction();

			// Update bb_service
			$serviceData = [];
			if (isset($body['name'])) {
				$serviceData['name'] = trim($body['name']);
			}
			if (isset($body['name_json'])) {
				$serviceData['name_json'] = $body['name_json'];
			}
			if (!empty($serviceData)) {
				$this->repository->updateService($serviceId, $serviceData);
			}

			// Update bb_article_mapping
			$mappingData = [];
			if (isset($body['article_code'])) {
				$mappingData['article_code'] = trim($body['article_code']);
			}
			if (isset($body['unit'])) {
				$mappingData['unit'] = $body['unit'];
			}
			if (isset($body['tax_code'])) {
				$mappingData['tax_code'] = (int) $body['tax_code'];
			}
			if (!empty($mappingData)) {
				$this->repository->updateMapping($id, $mappingData);
			}

			// Update bb_article_price
			if (isset($body['price']) && $body['price'] !== '' && is_numeric($body['price'])) {
				$this->repository->updateDefaultPrice($id, (float) $body['price']);
			}

			$db->commit();

			$row = $this->repository->getMappingById($id);
			$model = new ArticleMapping($row);

			$response->getBody()->write(json_encode($model->serialize()));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
		} catch (Exception $e) {
			$db = Db::getInstance();
			$db->rollBack();

			$response->getBody()->write(json_encode(['error' => $e->getMessage()]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}
}
