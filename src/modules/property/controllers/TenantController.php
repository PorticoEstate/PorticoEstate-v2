<?php

namespace App\modules\property\controllers;

use App\modules\property\models\Tenant;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Exception;
use App\modules\phpgwapi\services\Settings;
use App\Database\Db;
use OpenApi\Annotations as OA;
use Slim\Psr7\Stream;
use App\traits\SerializableTrait;
use Sanitizer;

/**
 * @OA\Tag(
 *     name="Buildings",
 *     description="API Endpoints for Buildings"
 * )
 */
class TenantController
{
    private $db;
    private $userSettings;
    public function __construct(ContainerInterface $container)
    {

        $this->db = Db::getInstance();
        $this->userSettings = Settings::getInstance()->get('user');
    }

    private function getUserRoles()
    {
        return $this->userSettings['groups'] ?? [];
    }

    /**
     * @OA\Get(
     *     path="/bookingfrontend/buildings",
     *     summary="Get a list of all buildings",
     *     tags={"Buildings"},
     *     @OA\Response(
     *         response=200,
     *         description="A list of buildings",
     *         @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/Building"))
     *     )
     * )
     */
    public function index(Request $request, Response $response): Response
    {
        $maxMatches = isset($this->userSettings['preferences']['common']['maxmatchs']) ? (int)$this->userSettings['preferences']['common']['maxmatchs'] : 15;
        $queryParams = $request->getQueryParams();
        $start = isset($queryParams['start']) ? (int)$queryParams['start'] : 0;
        $perPage = isset($queryParams['results']) ? (int)$queryParams['results'] : $maxMatches;

        $sql = "SELECT * FROM fm_tenant ORDER BY id";
        if ($perPage > 0)
        {
            $sql .= " LIMIT :limit OFFSET :start";
        }

        try
        {
            $stmt = $this->db->prepare($sql);
            if ($perPage > 0)
            {
                $stmt->bindParam(':limit', $perPage, \PDO::PARAM_INT);
                $stmt->bindParam(':start', $start, \PDO::PARAM_INT);
            }
            $stmt->execute();
            $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

			$tenants = array_map(function ($data)
            {
				$tenant = new Tenant($data);
                return $tenant->serialize($this->getUserRoles());
            }, $results);

            $response->getBody()->write(json_encode($tenants));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (Exception $e)
        {
            $error = "Error fetching tenants: " . $e->getMessage();
            $response->getBody()->write(json_encode(['error' => $error]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }


	/**
	 * @OA\Get(
	 *     path="/property/tenant/{ssn}",
	 *     summary="Get a specific building by ID",
	 *     tags={"Buildings"},
	 *     @OA\Parameter(
	 *         name="id",
	 *         in="path",
	 *         description="ID of the building to fetch",
	 *         required=true,
	 *         @OA\Schema(type="integer")
	 *     ),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Building details",
	 *         @OA\JsonContent(ref="#/components/schemas/Building")
	 *     ),
	 *     @OA\Response(
	 *         response=404,
	 *         description="Building not found"
	 *     )
	 * )
	 */
    public function show(Request $request, Response $response, array $args): Response
    {
        $ssn = Sanitizer::get_var('ssn', 'string', 'GET');

        try
        {
            $sql = "SELECT fm_tenant.* , location_code, fm_streetaddress.descr as street,"
			. " fm_location4.street_number, fm_location4.etasje"
			. " FROM fm_tenant"
			. " JOIN fm_location4 ON fm_tenant.id = fm_location4.tenant_id"
			. " JOIN fm_streetaddress ON fm_location4.street_id = fm_streetaddress.id"
			. " WHERE ssn = :ssn";
            $stmt = $this->db->prepare($sql);
            $stmt->bindParam(':ssn', $ssn, \PDO::PARAM_STR);
            $stmt->execute();

            $result = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$result)
            {
                $response->getBody()->write(json_encode(['error' => 'Tenant not found']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }

			$tenant = new Tenant($result);
            $response->getBody()->write(json_encode($tenant->serialize($this->getUserRoles())));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (Exception $e)
        {
            $error = "Error fetching building: " . $e->getMessage();
            $response->getBody()->write(json_encode(['error' => $error]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }
}