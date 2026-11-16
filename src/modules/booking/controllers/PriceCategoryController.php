<?php

namespace App\modules\booking\controllers;

use App\modules\booking\repositories\PriceCategoryRepository;
use App\modules\phpgwapi\security\Acl;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Price categories (bb_price_category, ref #1477), added by an administrator
 * from the price tab of an article, where the price rows are linked to them.
 */
class PriceCategoryController
{
	/** JSON that stays inert wherever a page puts it */
	private const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

	private Acl $acl;

	public function __construct(ContainerInterface $container)
	{
		$this->acl = Acl::getInstance();
	}

	/**
	 * @OA\Post(
	 *     path="/booking/price-categories",
	 *     summary="Add a price category, or find the one already named so",
	 *     tags={"Price categories"},
	 *     @OA\RequestBody(required=true, @OA\JsonContent(
	 *         required={"name"},
	 *         @OA\Property(property="name", type="string", maxLength=100)
	 *     )),
	 *     @OA\Response(response=201, description="Category added"),
	 *     @OA\Response(response=400, description="No usable name, or a failed CSRF check"),
	 *     @OA\Response(response=403, description="Permission denied"),
	 *     @OA\Response(response=409, description="A category with this name exists already, ignoring case; it is returned"),
	 *     @OA\Response(response=503, description="Price categories are not installed yet")
	 * )
	 */
	public function store(Request $request, Response $response): Response
	{
		// Whoever may save a price on the article's price tab may add a
		// category there: the check booking.uiarticle_mapping.save makes
		// (phpgwapi_uicommon::save, the '.article' location)
		if (!$this->acl->check('.article', Acl::ADD, 'booking'))
		{
			return $this->json($response, ['error' => 'Permission denied'], 403);
		}

		if (!PriceCategoryRepository::isInstalled())
		{
			return $this->json($response, ['error' => 'Price categories are not installed'], 503);
		}

		$body = $request->getParsedBody();
		if (!is_array($body))
		{
			$body = json_decode((string)$request->getBody(), true);
		}

		$name = PriceCategoryRepository::normalizeName(is_array($body) ? ($body['name'] ?? null) : null);
		if ($name === null)
		{
			return $this->json($response, ['error' => 'invalid_name'], 400);
		}

		$result = (new PriceCategoryRepository())->create($name);

		if ($result['category'] === null)
		{
			return $this->json($response, ['error' => 'invalid_name'], 400);
		}

		if (!$result['created'])
		{
			return $this->json($response, ['error' => 'exists', 'category' => $this->category($result['category'])], 409);
		}

		return $this->json($response, ['category' => $this->category($result['category'])], 201);
	}

	private function category(array $row): array
	{
		return [
			'id' => (int)$row['id'],
			'name' => (string)$row['name'],
			'active' => (int)$row['active'],
			'sort' => (int)$row['sort'],
		];
	}

	private function json(Response $response, array $data, int $status): Response
	{
		$response->getBody()->write(json_encode($data, self::JSON_FLAGS));

		return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
	}
}
