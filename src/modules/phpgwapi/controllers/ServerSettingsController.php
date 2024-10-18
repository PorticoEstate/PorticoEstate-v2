<?php

namespace App\modules\phpgwapi\controllers;

use App\modules\phpgwapi\models\ServerSettings;
use App\modules\phpgwapi\services\Settings;
use Exception;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

require_once SRC_ROOT_PATH . '/helpers/LegacyObjectHandler.php';

class ServerSettingsController
{
    private $settings;

    public function __construct(ContainerInterface $container)
    {
        $this->settings = Settings::getInstance();
    }

    /**
     * @OA\Get(
     *     path="/api/server-settings",
     *     summary="Get server settings",
     *     description="Retrieves the server settings, optionally including booking and bookingfrontend configurations.",
     *     tags={"Server Settings"},
     *     @OA\Parameter(
     *         name="include_configs",
     *         in="query",
     *         description="Whether to include booking and bookingfrontend configurations",
     *         required=false,
     *         @OA\Schema(type="boolean")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful response with server settings",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="bakcground_image", type="string", nullable=true),
     *             @OA\Property(property="logo_url", type="string"),
     *             @OA\Property(property="logo_title", type="string"),
     *             @OA\Property(property="site_title", type="string"),
     *             @OA\Property(property="support_address", type="string"),
     *             @OA\Property(property="webserver_url", type="string"),
     *             @OA\Property(
     *                 property="bookingfrontend_config",
     *                 type="object",
     *                 nullable=true,
     *                 ref="#/components/schemas/BookingfrontendConfig"
     *             ),
     *             @OA\Property(
     *                 property="booking_config",
     *                 type="object",
     *                 nullable=true,
     *                 ref="#/components/schemas/BookingConfig"
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="error", type="string")
     *         )
     *     )
     * )
     */
    public function index(Request $request, Response $response): Response
    {
        try
        {
            $queryParams = $request->getQueryParams();
            $includeConfigs = isset($queryParams['include_configs']) && $queryParams['include_configs'] === 'true';

            $model = ServerSettings::getInstance($includeConfigs);
            $serialized = $model->serialize();

            $response->getBody()->write(json_encode($serialized));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (Exception $e)
        {
            $error = "Error fetching server settings: " . $e->getMessage();
            $response->getBody()->write(json_encode(['error' => $error]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }
}