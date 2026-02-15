<?php

namespace App\modules\booking\controllers;

use App\Database\Db;
use App\modules\booking\authorization\DocumentBuildingAuthConfig;
use App\modules\booking\models\Building;
use App\modules\booking\repositories\PermissionRepository;
use App\modules\phpgwapi\services\AuthorizationService;
use App\helpers\ResponseHelper;
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

            $sql = "SELECT b.* FROM bb_building b WHERE b.active = 1";
            $params = [];

            if ($search !== null && trim($search) !== '') {
                $sql .= " AND b.name ILIKE :search";
                $params[':search'] = '%' . trim($search) . '%';
            }

            $sql .= " ORDER BY b.name";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $buildings = array_map(function ($row) {
                $building = new Building($row);
                return $building->serialize([], true);
            }, $results);

            return ResponseHelper::sendJSONResponse($buildings, 200, $response);
        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'Error fetching buildings: ' . $e->getMessage()],
                500
            );
        }
    }
}
