<?php

namespace App\modules\bookingfrontend\controllers;

use App\modules\bookingfrontend\helpers\ResponseHelper;
use App\modules\bookingfrontend\models\User;
use App\modules\bookingfrontend\services\CompletedReservationService;
use App\modules\bookingfrontend\helpers\UserHelper;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Exception;

/**
 * @OA\Tag(
 *     name="CompletedReservations",
 *     description="API Endpoints for Completed Reservations"
 * )
 */
class CompletedReservationController
{
    private $completedReservationService;
    private $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->completedReservationService = new CompletedReservationService();
    }

    /**
     * @OA\Get(
     *     path="/bookingfrontend/completedreservations",
     *     summary="Get completed reservations for the current user",
     *     tags={"CompletedReservations"},
     *     @OA\Response(
     *         response=200,
     *         description="List of completed reservations",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(ref="#/components/schemas/CompletedReservation")
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="User not authenticated"
     *     )
     * )
     */
    public function getReservations(Request $request, Response $response): Response
    {
        try
        {
            $bouser = new UserHelper();
            if (!$bouser->is_logged_in()) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'User not authenticated'],
                    401
                );
            }

            $ssn = $bouser->ssn;
            if (empty($ssn)) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'No SSN found for user'],
                    400
                );
            }
            $userModel = new User($bouser);


            // Get delegate organizations if any
//            $delegateOrgs = $bouser->get_delegate($ssn);
            $delegateOrgIds = array_map(function ($org)
            {
                return $org['org_id'];
            }, $userModel->delegates ?? []);

            $reservations = $this->completedReservationService->getReservationsBySsn($ssn, $delegateOrgIds);

            $response->getBody()->write(json_encode($reservations));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e)
        {
            $error = "Error fetching completed reservations: " . $e->getMessage();
            $response->getBody()->write(json_encode(['error' => $error]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);

        }
    }
}