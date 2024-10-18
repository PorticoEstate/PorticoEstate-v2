<?php

namespace App\modules\bookingfrontend\controllers;

use App\modules\bookingfrontend\models\Application;
use App\modules\bookingfrontend\models\helper\Date;
use App\modules\bookingfrontend\models\Resource;
use App\modules\bookingfrontend\models\Order;
use App\modules\bookingfrontend\models\OrderLine;
use App\modules\bookingfrontend\helpers\UserHelper;
use App\modules\bookingfrontend\services\ApplicationService;
use App\modules\phpgwapi\security\Sessions;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Exception;
use App\Database\Db;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="Applications",
 *     description="API Endpoints for Applications"
 * )
 */
class ApplicationController
{
    private $applicationService;

    public function __construct(ContainerInterface $container)
    {
        $this->applicationService = new ApplicationService();
    }

    /**
     * @OA\Get(
     *     path="/bookingfrontend/applications/partials",
     *     summary="Get partial applications for the current session",
     *     tags={"Applications"},
     *     @OA\Response(
     *         response=200,
     *         description="List of partial applications",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="list", type="array", @OA\Items(ref="#/components/schemas/Application")),
     *             @OA\Property(property="total_sum", type="number")
     *         )
     *     )
     * )
     */
    public function getPartials(Request $request, Response $response): Response
    {
        try {
            $session = Sessions::getInstance();
            $session_id = $session->get_session_id();

            if (empty($session_id)) {
                return $response->withStatus(400)->withJson(['error' => 'No active session']);
            }
            $applications = $this->applicationService->getPartialApplications($session_id);
            $total_sum = $this->applicationService->calculateTotalSum($applications);

            $responseData = [
                'list' => $applications,
                'total_sum' => $total_sum
            ];

            $response->getBody()->write(json_encode($responseData));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            $error = "Error fetching partial applications: " . $e->getMessage();
            return $response->withStatus(500)->withJson(['error' => $error]);
        }
    }


    /**
     * @OA\Delete(
     *     path="/bookingfrontend/applications/{id}",
     *     summary="Delete a partial application",
     *     tags={"Applications"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the application to delete",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Application successfully deleted",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="deleted", type="boolean")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid input"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Application not found"
     *     )
     * )
     */
    public function deletePartial(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $session = Sessions::getInstance();
        $session_id = $session->get_session_id();

        if (empty($session_id)) {
            $response->getBody()->write(json_encode(['error' => 'No active session']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        try {
            $deleted = $this->applicationService->deletePartial($id, $session_id);
            $response->getBody()->write(json_encode(['deleted' => $deleted]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            $status = ($e->getMessage() === "Application not found or not owned by the current session") ? 404 : 500;
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
        }
    }

}