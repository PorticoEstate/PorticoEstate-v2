<?php

namespace App\modules\bookingfrontend\controllers;

use App\modules\bookingfrontend\helpers\ResponseHelper;
use App\modules\phpgwapi\security\Sessions;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Sanitizer;

/**
 * @OA\Tag(
 *     name="Version",
 *     description="API Endpoints for version settings"
 * )
 */
class VersionController
{
    private $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * @OA\Get(
     *     path="/bookingfrontend/version",
     *     summary="Get current version preference",
     *     description="Gets the user's current version preference (original or new)",
     *     tags={"Version"},
     *     @OA\Response(
     *         response=200,
     *         description="Current version settings",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean"),
     *             @OA\Property(property="version", type="string"),
     *             @OA\Property(property="template_set", type="string"),
     *         )
     *     )
     * )
     */
    public function getVersion(Request $request, Response $response): Response
    {
        // Get the current settings from cookies
        $template_set = Sanitizer::get_var('template_set', 'string', 'COOKIE', 'bookingfrontend');

        // Determine version based on cookie values
        $version = ($template_set === 'bookingfrontend_2') ? 'new' : 'original';

        return ResponseHelper::sendErrorResponse(
            [
                'success' => true,
                'version' => $version,
                'template_set' => $template_set,
            ],
            200,
            $response
        );
    }

    /**
     * @OA\Post(
     *     path="/bookingfrontend/version",
     *     summary="Set version preference",
     *     description="Sets the user's preferred version (original or new)",
     *     tags={"Version"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"version"},
     *             @OA\Property(
     *                 property="version",
     *                 type="string",
     *                 description="The version to set (original or new)",
     *                 example="new"
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Version preference set successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean"),
     *             @OA\Property(property="version", type="string")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid version specified",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="error", type="string")
     *         )
     *     )
     * )
     */
    public function setVersion(Request $request, Response $response): Response
    {
        // Get the request body as a string and decode it
        $body = $request->getBody()->getContents();
        $data = json_decode($body, true) ?? [];

        // Add debugging
        error_log('Version request body: "' . $body . '"');
        error_log('JSON decode result: ' . json_last_error_msg());
        error_log('Parsed data: ' . print_r($data, true));

        $version = $data['version'] ?? null;

        if (!$version || !in_array($version, ['original', 'new'])) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'Invalid version specified. Must be one of: original, new'],
                400
            );
        }

        // Get the sessions object for setting cookies
        $sessions = Sessions::getInstance();

        // Set up template_set based on version
        $templateSet = ($version === 'original') ? 'bookingfrontend' : 'bookingfrontend_2';

        // Log before setting
        error_log('Version value: "' . $version . '"');

        // Set expiration time (1 year from now)
        $expirationTime = time() + (60 * 60 * 24 * 365);

        // Use only the Sessions class to set cookies, for consistency with the rest of the application
        $sessions->phpgw_setcookie('template_set', $templateSet, $expirationTime);

        // Log the success
        error_log('Setting version to: ' . $version);
        error_log('Template set: ' . $templateSet);

        return ResponseHelper::sendErrorResponse(
            [
                'success' => true,
                'version' => $version,
                'template_set' => $templateSet,
            ],
            200,
            $response
        );
    }
}