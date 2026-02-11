<?php

namespace App\modules\booking\controllers;

use App\Database\Db;
use App\modules\booking\authorization\DocumentBuildingAuthConfig;
use App\modules\booking\repositories\PermissionRepository;
use App\modules\booking\services\AuthorizationService;
use App\modules\bookingfrontend\helpers\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use PDO;
use Exception;

class BuildingController
{
    protected AuthorizationService $authService;

    public function __construct()
    {
        $this->authService = new AuthorizationService(new PermissionRepository());
    }

    public function index(Request $request, Response $response): Response
    {
        $authConfig = new DocumentBuildingAuthConfig();
        if ($this->authService->authorize($authConfig, 'read') === false) {
            return ResponseHelper::sendErrorResponse(['error' => 'Permission denied'], 403);
        }

        try {
            $db = Db::getInstance();
            $search = $request->getQueryParams()['search'] ?? null;

            $sql = "SELECT id, name FROM bb_building WHERE active = 1";
            $params = [];

            if ($search !== null && trim($search) !== '') {
                $sql .= " AND name ILIKE :search";
                $params[':search'] = '%' . trim($search) . '%';
            }

            $sql .= " ORDER BY name";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $buildings = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Cast id to int
            $buildings = array_map(function ($b) {
                $b['id'] = (int)$b['id'];
                return $b;
            }, $buildings);

            $response->getBody()->write(json_encode($buildings));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'Error fetching buildings: ' . $e->getMessage()],
                500
            );
        }
    }
}
