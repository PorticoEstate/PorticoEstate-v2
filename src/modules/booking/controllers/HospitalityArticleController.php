<?php

namespace App\modules\booking\controllers;

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\security\Acl;
use App\modules\booking\repositories\HospitalityRepository;
use App\modules\booking\repositories\HospitalityArticleRepository;
use App\modules\booking\models\HospitalityArticleGroup;
use App\modules\booking\models\HospitalityArticle;
use App\modules\bookingfrontend\helpers\WebSocketHelper;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Exception;

class HospitalityArticleController
{
	protected array $userSettings;
	protected Acl $acl;
	protected HospitalityRepository $hospitalityRepository;
	protected HospitalityArticleRepository $repository;

	public function __construct(ContainerInterface $container)
	{
		$this->userSettings = Settings::getInstance()->get('user');
		$this->acl = Acl::getInstance();
		$this->hospitalityRepository = new HospitalityRepository();
		$this->repository = new HospitalityArticleRepository();
	}

	private function validateHospitality(int $id, Response $response): ?Response
	{
		if (!$this->hospitalityRepository->getById($id)) {
			$response->getBody()->write(json_encode(['error' => 'Hospitality not found']));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
		}
		return null;
	}

	// -- Article Groups --

	/**
	 * @OA\Get(
	 *     path="/booking/hospitality/{id}/article-groups",
	 *     summary="List article groups for a hospitality",
	 *     tags={"Hospitality Articles"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="List of article groups")
	 * )
	 */
	public function indexGroups(Request $request, Response $response, array $args): Response
	{
		try {
			$id = (int)$args['id'];
			if ($err = $this->validateHospitality($id, $response)) return $err;

			$rows = $this->repository->getGroupsByHospitality($id);
			$results = array_map(function ($row) {
				$group = new HospitalityArticleGroup($row);
				$articleRows = $this->repository->getArticlesByGroup((int)$row['id']);
				$group->articles = array_map(function ($a) {
					return (new HospitalityArticle($a))->serialize();
				}, $articleRows);
				return $group->serialize();
			}, $rows);

			$response->getBody()->write(json_encode($results));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
		} catch (Exception $e) {
			$response->getBody()->write(json_encode(['error' => $e->getMessage()]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	/**
	 * @OA\Post(
	 *     path="/booking/hospitality/{id}/article-groups",
	 *     summary="Create an article group",
	 *     tags={"Hospitality Articles"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\RequestBody(required=true, @OA\JsonContent(
	 *         required={"name"},
	 *         @OA\Property(property="name", type="string"),
	 *         @OA\Property(property="sort_order", type="integer"),
	 *         @OA\Property(property="active", type="integer")
	 *     )),
	 *     @OA\Response(response=201, description="Group created"),
	 *     @OA\Response(response=403, description="Permission denied")
	 * )
	 */
	public function storeGroup(Request $request, Response $response, array $args): Response
	{
		if (!$this->acl->check('.application', Acl::ADD, 'booking')) {
			$response->getBody()->write(json_encode(['error' => 'Permission denied']));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
		}

		try {
			$id = (int)$args['id'];
			if ($err = $this->validateHospitality($id, $response)) return $err;

			$body = $request->getParsedBody() ?? json_decode($request->getBody()->getContents(), true) ?? [];

			if (empty($body['name'])) {
				$response->getBody()->write(json_encode(['error' => 'name is required']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
			}

			$body['hospitality_id'] = $id;
			$groupId = $this->repository->createGroup($body);
			$row = $this->repository->getGroupById($groupId);
			$model = new HospitalityArticleGroup($row);

			$accountId = (int)$this->userSettings['account_id'];
			WebSocketHelper::sendEntityNotificationAsync(
				'hospitality', $id, 'Article group created', 'updated',
				['section' => 'articles', 'groupId' => $groupId, 'modifiedBy' => $accountId]
			);

			$response->getBody()->write(json_encode($model->serialize()));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
		} catch (Exception $e) {
			$response->getBody()->write(json_encode(['error' => $e->getMessage()]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	/**
	 * @OA\Put(
	 *     path="/booking/hospitality/{id}/article-groups/{groupId}",
	 *     summary="Update an article group",
	 *     tags={"Hospitality Articles"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="groupId", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="Group updated"),
	 *     @OA\Response(response=403, description="Permission denied"),
	 *     @OA\Response(response=404, description="Not found")
	 * )
	 */
	public function updateGroup(Request $request, Response $response, array $args): Response
	{
		if (!$this->acl->check('.application', Acl::EDIT, 'booking')) {
			$response->getBody()->write(json_encode(['error' => 'Permission denied']));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
		}

		try {
			$id = (int)$args['id'];
			$groupId = (int)$args['groupId'];
			if ($err = $this->validateHospitality($id, $response)) return $err;

			$existing = $this->repository->getGroupById($groupId);
			if (!$existing || (int)$existing['hospitality_id'] !== $id) {
				$response->getBody()->write(json_encode(['error' => 'Article group not found']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
			}

			$body = $request->getParsedBody() ?? json_decode($request->getBody()->getContents(), true) ?? [];
			$this->repository->updateGroup($groupId, $body);

			$row = $this->repository->getGroupById($groupId);
			$model = new HospitalityArticleGroup($row);

			$accountId = (int)$this->userSettings['account_id'];
			WebSocketHelper::sendEntityNotificationAsync(
				'hospitality', $id, 'Article group updated', 'updated',
				['section' => 'articles', 'groupId' => $groupId, 'modifiedBy' => $accountId]
			);

			$response->getBody()->write(json_encode($model->serialize()));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
		} catch (Exception $e) {
			$response->getBody()->write(json_encode(['error' => $e->getMessage()]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	/**
	 * @OA\Delete(
	 *     path="/booking/hospitality/{id}/article-groups/{groupId}",
	 *     summary="Delete an article group (unlinks articles)",
	 *     tags={"Hospitality Articles"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="groupId", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="Group deleted"),
	 *     @OA\Response(response=403, description="Permission denied"),
	 *     @OA\Response(response=404, description="Not found")
	 * )
	 */
	public function destroyGroup(Request $request, Response $response, array $args): Response
	{
		if (!$this->acl->check('.application', Acl::DELETE, 'booking')) {
			$response->getBody()->write(json_encode(['error' => 'Permission denied']));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
		}

		try {
			$id = (int)$args['id'];
			$groupId = (int)$args['groupId'];
			if ($err = $this->validateHospitality($id, $response)) return $err;

			$existing = $this->repository->getGroupById($groupId);
			if (!$existing || (int)$existing['hospitality_id'] !== $id) {
				$response->getBody()->write(json_encode(['error' => 'Article group not found']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
			}

			$this->repository->deleteGroup($groupId);

			$accountId = (int)$this->userSettings['account_id'];
			WebSocketHelper::sendEntityNotificationAsync(
				'hospitality', $id, 'Article group deleted', 'updated',
				['section' => 'articles', 'groupId' => $groupId, 'modifiedBy' => $accountId]
			);

			$response->getBody()->write(json_encode(['message' => 'Article group deleted']));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
		} catch (Exception $e) {
			$response->getBody()->write(json_encode(['error' => $e->getMessage()]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	// -- Articles --

	/**
	 * @OA\Get(
	 *     path="/booking/hospitality/{id}/articles",
	 *     summary="List articles for a hospitality",
	 *     tags={"Hospitality Articles"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="active_only", in="query", @OA\Schema(type="boolean")),
	 *     @OA\Response(response=200, description="List of articles with effective pricing")
	 * )
	 */
	public function indexArticles(Request $request, Response $response, array $args): Response
	{
		try {
			$id = (int)$args['id'];
			if ($err = $this->validateHospitality($id, $response)) return $err;

			$params = $request->getQueryParams();
			$activeOnly = !empty($params['active_only']);

			$rows = $this->repository->getArticlesByHospitality($id, $activeOnly);
			$results = array_map(function ($row) {
				return (new HospitalityArticle($row))->serialize();
			}, $rows);

			$response->getBody()->write(json_encode($results));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
		} catch (Exception $e) {
			$response->getBody()->write(json_encode(['error' => $e->getMessage()]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	/**
	 * @OA\Post(
	 *     path="/booking/hospitality/{id}/articles",
	 *     summary="Add an article to a hospitality",
	 *     tags={"Hospitality Articles"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\RequestBody(required=true, @OA\JsonContent(
	 *         required={"article_mapping_id"},
	 *         @OA\Property(property="article_mapping_id", type="integer"),
	 *         @OA\Property(property="article_group_id", type="integer"),
	 *         @OA\Property(property="description", type="string"),
	 *         @OA\Property(property="sort_order", type="integer"),
	 *         @OA\Property(property="active", type="integer"),
	 *         @OA\Property(property="override_price", type="number"),
	 *         @OA\Property(property="override_tax_code", type="integer")
	 *     )),
	 *     @OA\Response(response=201, description="Article added"),
	 *     @OA\Response(response=403, description="Permission denied"),
	 *     @OA\Response(response=409, description="Duplicate article mapping")
	 * )
	 */
	public function storeArticle(Request $request, Response $response, array $args): Response
	{
		if (!$this->acl->check('.application', Acl::ADD, 'booking')) {
			$response->getBody()->write(json_encode(['error' => 'Permission denied']));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
		}

		try {
			$id = (int)$args['id'];
			if ($err = $this->validateHospitality($id, $response)) return $err;

			$body = $request->getParsedBody() ?? json_decode($request->getBody()->getContents(), true) ?? [];

			if (empty($body['article_mapping_id'])) {
				$response->getBody()->write(json_encode(['error' => 'article_mapping_id is required']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
			}

			// Check for duplicate (hospitality_id, article_mapping_id)
			$existing = $this->repository->getArticlesByHospitality($id);
			foreach ($existing as $article) {
				if ((int)$article['article_mapping_id'] === (int)$body['article_mapping_id']) {
					$response->getBody()->write(json_encode([
						'error' => 'This article mapping already exists for this hospitality',
					]));
					return $response->withHeader('Content-Type', 'application/json')->withStatus(409);
				}
			}

			$body['hospitality_id'] = $id;
			$articleId = $this->repository->createArticle($body);
			$row = $this->repository->getArticleById($articleId);
			$model = new HospitalityArticle($row);

			$accountId = (int)$this->userSettings['account_id'];
			WebSocketHelper::sendEntityNotificationAsync(
				'hospitality', $id, 'Article added', 'updated',
				['section' => 'articles', 'articleId' => $articleId, 'modifiedBy' => $accountId]
			);

			$response->getBody()->write(json_encode($model->serialize()));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
		} catch (Exception $e) {
			$response->getBody()->write(json_encode(['error' => $e->getMessage()]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	/**
	 * @OA\Get(
	 *     path="/booking/hospitality/{id}/articles/{articleId}",
	 *     summary="Get a single article with effective pricing",
	 *     tags={"Hospitality Articles"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="articleId", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="Article details"),
	 *     @OA\Response(response=404, description="Not found")
	 * )
	 */
	public function showArticle(Request $request, Response $response, array $args): Response
	{
		try {
			$id = (int)$args['id'];
			$articleId = (int)$args['articleId'];
			if ($err = $this->validateHospitality($id, $response)) return $err;

			$row = $this->repository->getArticleById($articleId);
			if (!$row || (int)$row['hospitality_id'] !== $id) {
				$response->getBody()->write(json_encode(['error' => 'Article not found']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
			}

			$model = new HospitalityArticle($row);

			$response->getBody()->write(json_encode($model->serialize()));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
		} catch (Exception $e) {
			$response->getBody()->write(json_encode(['error' => $e->getMessage()]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	/**
	 * @OA\Put(
	 *     path="/booking/hospitality/{id}/articles/{articleId}",
	 *     summary="Update a hospitality article",
	 *     tags={"Hospitality Articles"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="articleId", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="Article updated"),
	 *     @OA\Response(response=403, description="Permission denied"),
	 *     @OA\Response(response=404, description="Not found")
	 * )
	 */
	public function updateArticle(Request $request, Response $response, array $args): Response
	{
		if (!$this->acl->check('.application', Acl::EDIT, 'booking')) {
			$response->getBody()->write(json_encode(['error' => 'Permission denied']));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
		}

		try {
			$id = (int)$args['id'];
			$articleId = (int)$args['articleId'];
			if ($err = $this->validateHospitality($id, $response)) return $err;

			$existing = $this->repository->getArticleById($articleId);
			if (!$existing || (int)$existing['hospitality_id'] !== $id) {
				$response->getBody()->write(json_encode(['error' => 'Article not found']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
			}

			$body = $request->getParsedBody() ?? json_decode($request->getBody()->getContents(), true) ?? [];
			$this->repository->updateArticle($articleId, $body);

			$row = $this->repository->getArticleById($articleId);
			$model = new HospitalityArticle($row);

			$accountId = (int)$this->userSettings['account_id'];
			WebSocketHelper::sendEntityNotificationAsync(
				'hospitality', $id, 'Article updated', 'updated',
				['section' => 'articles', 'articleId' => $articleId, 'modifiedBy' => $accountId]
			);

			$response->getBody()->write(json_encode($model->serialize()));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
		} catch (Exception $e) {
			$response->getBody()->write(json_encode(['error' => $e->getMessage()]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	/**
	 * @OA\Delete(
	 *     path="/booking/hospitality/{id}/articles/{articleId}",
	 *     summary="Delete a hospitality article",
	 *     tags={"Hospitality Articles"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Parameter(name="articleId", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\Response(response=200, description="Article deleted"),
	 *     @OA\Response(response=403, description="Permission denied"),
	 *     @OA\Response(response=404, description="Not found")
	 * )
	 */
	public function destroyArticle(Request $request, Response $response, array $args): Response
	{
		if (!$this->acl->check('.application', Acl::DELETE, 'booking')) {
			$response->getBody()->write(json_encode(['error' => 'Permission denied']));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
		}

		try {
			$id = (int)$args['id'];
			$articleId = (int)$args['articleId'];
			if ($err = $this->validateHospitality($id, $response)) return $err;

			$existing = $this->repository->getArticleById($articleId);
			if (!$existing || (int)$existing['hospitality_id'] !== $id) {
				$response->getBody()->write(json_encode(['error' => 'Article not found']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
			}

			$this->repository->deleteArticle($articleId);

			$accountId = (int)$this->userSettings['account_id'];
			WebSocketHelper::sendEntityNotificationAsync(
				'hospitality', $id, 'Article deleted', 'updated',
				['section' => 'articles', 'articleId' => $articleId, 'modifiedBy' => $accountId]
			);

			$response->getBody()->write(json_encode(['message' => 'Article deleted']));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
		} catch (Exception $e) {
			$response->getBody()->write(json_encode(['error' => $e->getMessage()]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}

	/**
	 * @OA\Put(
	 *     path="/booking/hospitality/{id}/articles/reorder",
	 *     summary="Batch reorder articles (sort_order and optional group changes)",
	 *     tags={"Hospitality Articles"},
	 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
	 *     @OA\RequestBody(required=true, @OA\JsonContent(
	 *         required={"items"},
	 *         @OA\Property(property="items", type="array", @OA\Items(
	 *             @OA\Property(property="id", type="integer"),
	 *             @OA\Property(property="sort_order", type="integer"),
	 *             @OA\Property(property="article_group_id", type="integer", nullable=true)
	 *         ))
	 *     )),
	 *     @OA\Response(response=200, description="Articles reordered"),
	 *     @OA\Response(response=403, description="Permission denied")
	 * )
	 */
	public function reorderArticles(Request $request, Response $response, array $args): Response
	{
		if (!$this->acl->check('.application', Acl::EDIT, 'booking')) {
			$response->getBody()->write(json_encode(['error' => 'Permission denied']));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
		}

		try {
			$id = (int)$args['id'];
			if ($err = $this->validateHospitality($id, $response)) return $err;

			$body = $request->getParsedBody() ?? json_decode($request->getBody()->getContents(), true) ?? [];

			if (empty($body['items']) || !is_array($body['items'])) {
				$response->getBody()->write(json_encode(['error' => 'items array is required']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
			}

			$this->repository->reorderArticles($id, $body['items']);

			$accountId = (int)$this->userSettings['account_id'];
			WebSocketHelper::sendEntityNotificationAsync(
				'hospitality', $id, 'Articles reordered', 'updated',
				['section' => 'articles', 'modifiedBy' => $accountId]
			);

			$response->getBody()->write(json_encode(['message' => 'Articles reordered']));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
		} catch (Exception $e) {
			$response->getBody()->write(json_encode(['error' => $e->getMessage()]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}
}
