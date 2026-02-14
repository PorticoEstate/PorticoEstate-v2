<?php

namespace App\modules\phpgwapi\controllers;

use App\modules\booking\repositories\PermissionRepository;
use App\modules\phpgwapi\services\AuthorizationService;
use App\helpers\ResponseHelper;
use App\modules\phpgwapi\services\Config;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Exception;

class ConfigController
{
    private const ALLOWED_APPS = ['booking', 'bookingfrontend', 'phpgwapi'];

    private AuthorizationService $authService;

    public function __construct()
    {
        $this->authService = new AuthorizationService(new PermissionRepository());
    }

    public function getConfig(Request $request, Response $response, array $args): Response
    {
        $appname = $args['appname'] ?? '';

        if (!in_array($appname, self::ALLOWED_APPS, true)) {
            return ResponseHelper::sendErrorResponse(
                ['error' => "Invalid app: {$appname}. Allowed: " . implode(', ', self::ALLOWED_APPS)],
                400
            );
        }

        if (!$this->authService->isAdminForApp($appname)) {
            return ResponseHelper::sendErrorResponse(['error' => 'Permission denied'], 403);
        }

        try {
            $config = new Config($appname);
            $data = $config->read();

            $keys = $request->getQueryParams()['keys'] ?? null;
            if ($keys !== null && trim($keys) !== '') {
                $allowed = array_map('trim', explode(',', $keys));
                $data = array_intersect_key($data, array_flip($allowed));
            }

            $response->getBody()->write(json_encode($data));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'Error reading config: ' . $e->getMessage()],
                500
            );
        }
    }

    public function updateConfig(Request $request, Response $response, array $args): Response
    {
        $appname = $args['appname'] ?? '';

        if (!in_array($appname, self::ALLOWED_APPS, true)) {
            return ResponseHelper::sendErrorResponse(
                ['error' => "Invalid app: {$appname}. Allowed: " . implode(', ', self::ALLOWED_APPS)],
                400
            );
        }

        if (!$this->authService->isAdminForApp($appname)) {
            return ResponseHelper::sendErrorResponse(['error' => 'Permission denied'], 403);
        }

        try {
            $config = new Config($appname);
            $existing = $config->read();

            $incoming = $request->getParsedBody()
                ?? json_decode($request->getBody()->getContents(), true);
            if (!is_array($incoming)) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Request body must be a JSON object'],
                    400
                );
            }

            // Merge incoming over existing; null values remove the key
            foreach ($incoming as $key => $value) {
                if ($value === null) {
                    unset($existing[$key]);
                } else {
                    $existing[$key] = $value;
                }
            }

            $config->config_data = $existing;
            $config->save_repository();

            $response->getBody()->write(json_encode($existing));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'Error updating config: ' . $e->getMessage()],
                500
            );
        }
    }
}
