<?php

namespace App\modules\bookingfrontend\controllers;

use App\modules\bookingfrontend\helpers\ResponseHelper;
use App\modules\bookingfrontend\helpers\UserHelper;
use App\modules\bookingfrontend\services\ApplicationService;
use App\modules\phpgwapi\security\Sessions;
use App\modules\phpgwapi\services\Settings;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Exception;
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
    private $userSettings;

    public function __construct(ContainerInterface $container)
    {
        $this->applicationService = new ApplicationService();
        $this->userSettings = Settings::getInstance()->get('user');
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
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'No active session'],
                    400
                );
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
            return ResponseHelper::sendErrorResponse(
                ['error' => $error],
                500
            );
        }
    }

    public function getApplications(Request $request, Response $response): Response
    {
        try {
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

            $applications = $this->applicationService->getApplicationsBySsn($ssn);
            $total_sum = $this->applicationService->calculateTotalSum($applications);

            $responseData = [
                'list' => $applications,
                'total_sum' => $total_sum
            ];

            $response->getBody()->write(json_encode($responseData));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            $error = "Error fetching applications: " . $e->getMessage();
            return ResponseHelper::sendErrorResponse(
                ['error' => $error],
                500
            );
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


    /**
     * @OA\Post(
     *     path="/bookingfrontend/applications/partials",
     *     summary="Create a new partial application",
     *     tags={"Applications"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(ref="#/components/schemas/Application")
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Partial application created",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="id", type="integer"),
     *             @OA\Property(property="message", type="string")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid input or missing session"
     *     )
     * )
     */
    public function createPartial(Request $request, Response $response): Response
    {
        try {
            $session = Sessions::getInstance();
            $session_id = $session->get_session_id();

            if (empty($session_id)) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'No active session'],
                    400
                );
            }

            $data = json_decode($request->getBody()->getContents(), true);
            if (!$data) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Invalid JSON data'],
                    400
                );
            }

            // Add required application data
            $data['owner_id'] = $this->userSettings['account_id'];
            $data['session_id'] = $session_id;
            $data['status'] = 'NEWPARTIAL1';
            $data['active'] = '1';
            $data['created'] = 'now';



            // Add dummy data for required fields
            $this->populateDummyData($data);

            $id = $this->applicationService->savePartialApplication($data);

            $responseData = [
                'id' => $id,
                'message' => 'Partial application created successfully'
            ];

            $response->getBody()->write(json_encode($responseData));
            return $response->withStatus(201)
                ->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => "Error creating partial application: " . $e->getMessage()],
                500
            );
        }
    }

    /**
     * @OA\Put(
     *     path="/bookingfrontend/applications/partials/{id}",
     *     summary="Replace an existing partial application",
     *     tags={"Applications"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(ref="#/components/schemas/Application")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Application updated successfully"
     *     )
     * )
     */
    public function updatePartial(Request $request, Response $response, array $args): Response
    {
        try {
            $id = (int)$args['id'];
            $session = Sessions::getInstance();
            $session_id = $session->get_session_id();

            if (empty($session_id)) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'No active session'],
                    400
                );
            }

            $data = json_decode($request->getBody()->getContents(), true);
            if (!$data) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Invalid JSON data'],
                    400
                );
            }

            // Verify ownership
            $existing = $this->applicationService->getPartialApplicationById($id);
            if (!$existing || $existing['session_id'] !== $session_id) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Application not found or not owned by current session'],
                    404
                );
            }

//            $data['id'] = $id;
            $data['session_id'] = $session_id;
//            $data['status'] = 'NEWPARTIAL1';
//            $this->populateDummyData($data);

            $this->applicationService->savePartialApplication($data);

            $response->getBody()->write(json_encode([
                'message' => 'Application updated successfully'
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => "Error updating partial application: " . $e->getMessage()],
                500
            );
        }
    }


    /**
     * @OA\Patch(
     *     path="/bookingfrontend/applications/partials/{id}",
     *     summary="Partially update an application",
     *     tags={"Applications"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the application to update",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="status", type="string"),
     *             @OA\Property(property="name", type="string"),
     *             @OA\Property(property="contact_name", type="string"),
     *             @OA\Property(property="contact_email", type="string"),
     *             @OA\Property(property="contact_phone", type="string"),
     *             @OA\Property(
     *                 property="resources",
     *                 type="array",
     *                 description="Complete replacement of resources",
     *                 @OA\Items(type="integer")
     *             ),
     *             @OA\Property(
     *                 property="dates",
     *                 type="array",
     *                 description="Update existing dates (with id) or create new ones (without id)",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer"),
     *                     @OA\Property(property="from_", type="string", format="date-time"),
     *                     @OA\Property(property="to_", type="string", format="date-time")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Application updated successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string")
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
    public function patchApplication(Request $request, Response $response, array $args): Response
    {
        try {
            $id = (int)$args['id'];
            $session = Sessions::getInstance();
            $session_id = $session->get_session_id();

            if (empty($session_id)) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'No active session'],
                    400
                );
            }

            $data = json_decode($request->getBody()->getContents(), true);
            if (!$data) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Invalid JSON data'],
                    400
                );
            }

            // Verify ownership
            $existing = $this->applicationService->getPartialApplicationById($id);
            if (!$existing || $existing['session_id'] !== $session_id) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Application not found or not owned by current session'],
                    404
                );
            }


            $data['id'] = $id;
            $this->applicationService->patchApplication($data);

            $response->getBody()->write(json_encode([
                'message' => 'Application updated successfully'
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => "Error updating application: " . $e->getMessage()],
                500
            );
        }
    }


    /**
     * Helper function to populate required dummy data
     */
    private function populateDummyData(array &$data): void
    {
        $dummyFields = [
            'contact_name' => 'dummy',
            'contact_phone' => 'dummy',
            'responsible_city' => 'dummy',
            'responsible_street' => 'dummy',
            'contact_email' => 'dummy@example.com',
            'contact_email2' => 'dummy@example.com',
            'responsible_zip_code' => '0000',
            'customer_identifier_type' => 'organization_number',
            'customer_organization_number' => ''
        ];

        foreach ($dummyFields as $field => $value) {
            if (!isset($data[$field])) {
                $data[$field] = $value;
            }
        }
    }

}