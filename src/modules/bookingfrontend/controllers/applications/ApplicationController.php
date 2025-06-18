<?php

namespace App\modules\bookingfrontend\controllers\applications;

use App\modules\bookingfrontend\controllers\DocumentController;
use App\modules\bookingfrontend\helpers\ApplicationHelper;
use App\modules\bookingfrontend\helpers\ResponseHelper;
use App\modules\bookingfrontend\helpers\UserHelper;
use App\modules\bookingfrontend\helpers\WebSocketHelper;
use App\modules\bookingfrontend\models\Document;
use App\modules\bookingfrontend\repositories\ApplicationRepository;
use App\modules\bookingfrontend\repositories\ArticleRepository;
use App\modules\bookingfrontend\services\applications\ApplicationService;
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
class ApplicationController extends DocumentController
{
    private $bouser;
    private $applicationService;
    private $applicationRepository;
    private $articleRepository;
    private $userSettings;
    private ApplicationHelper $applicationHelper;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct(Document::OWNER_APPLICATION);
        $this->bouser = new UserHelper();
        $this->applicationService = new ApplicationService();
        $this->applicationRepository = new ApplicationRepository();
        $this->articleRepository = new ArticleRepository();
        $this->userSettings = Settings::getInstance()->get('user');
        $this->applicationHelper = new ApplicationHelper();
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

    /**
     * @OA\Get(
     *     path="/bookingfrontend/applications",
     *     summary="Get all applications for the current user",
     *     tags={"Applications"},
     *     @OA\Parameter(
     *         name="include_organizations",
     *         in="query",
     *         required=false,
     *         description="Include applications from organizations the user belongs to",
     *         @OA\Schema(type="boolean", default=false)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="List of applications",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="list", type="array", @OA\Items(ref="#/components/schemas/Application")),
     *             @OA\Property(property="total_sum", type="number")
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="User not authenticated"
     *     )
     * )
     */
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

            // Get query parameter for including organization applications
            $queryParams = $request->getQueryParams();
            $includeOrganizations = isset($queryParams['include_organizations']) && 
                                   filter_var($queryParams['include_organizations'], FILTER_VALIDATE_BOOLEAN);

            $applications = $this->applicationService->getApplicationsBySsn($ssn, $includeOrganizations);
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
     * @OA\Get(
     *     path="/bookingfrontend/applications/{id}",
     *     summary="Get a specific application by ID",
     *     description="Returns a specific application if the user has access to it, or if the correct secret is provided.",
     *     tags={"Applications"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the application to retrieve",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="secret",
     *         in="query",
     *         required=false,
     *         description="Secret key that can be used to access the application without authentication",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Application details",
     *         @OA\JsonContent(ref="#/components/schemas/Application")
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Unauthorized to view this application"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Application not found"
     *     )
     * )
     */
    public function getApplicationById(Request $request, Response $response, array $args): Response
    {
        try {
            $id = (int)$args['id'];

            // Get the application with basic data first
            $application = $this->applicationService->getApplicationById($id);
            if (!$application) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Application not found'],
                    404
                );
            }

            // Check if user can access this application (supports secret parameter and user access)
            if (!$this->applicationHelper->canViewApplication($application, $request)) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Unauthorized to view this application'],
                    403
                );
            }

            // User has access - get full application data
            $fullApplication = $this->applicationService->getFullApplication($id);
            if (!$fullApplication) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Application details not found'],
                    404
                );
            }

            // Return the complete application data
			return ResponseHelper::sendJSONResponse($fullApplication->serialize());

        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => "Error retrieving application: " . $e->getMessage()],
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
        try {
            $id = (int)$args['id'];

            // Get the application to check permissions
            $application = $this->applicationService->getApplicationById($id);
            if (!$application) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Application not found'],
                    404
                );
            }

            // Verify permissions
            if (!$this->applicationHelper->canModifyApplication($application, $request)) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Unauthorized to delete this application'],
                    403
                );
            }


            // Fetch detailed application information for notifications and block clearing
            $dates = $this->applicationService->applicationRepository->fetchDates($id);
            $resources = $this->applicationService->applicationRepository->fetchResources($id);

            // Store building and resource data for notifications before deletion
            $buildingId = $application['building_id'] ?? null;
            $resourceIds = array_column($resources, 'id');
            $resourceId = !empty($resourceIds) ? $resourceIds[0] : null;
            $from = !empty($dates) ? $dates[0]['from_'] : null;
            $to = !empty($dates) ? $dates[0]['to_'] : null;

            $deleted = $this->applicationService->deletePartial($id);

            // Clear blocks for this application
            if ($deleted && $application && !empty($application['session_id'])) {
                try {
                    $sessionId = $application['session_id'];

                    // Clear blocks for each resource and date
                    foreach ($resources as $resource) {
                        foreach ($dates as $date) {
                            $this->applicationService->clearBlocksAndLocks(
                                (int)$resource['id'],
                                $date['from_'],
                                $date['to_'],
                                $sessionId
                            );
                        }
                    }

                    error_log("Blocks cleared for deleted application {$id}");
                } catch (\Exception $e) {
                    // Log but continue, as the application is already deleted
                    error_log("Error clearing blocks for deleted application {$id}: " . $e->getMessage());
                }
            }

            // Send WebSocket notifications about the freed timeslot
            if ($deleted) {
                try {
                    // Offload all WebSocket notifications to a forked process
                    WebSocketHelper::forkNotification(function() use ($buildingId, $resourceId, $from, $to, $id, $resourceIds) {
                        try {
                            // Notify about timeslot changes if we have the necessary data
                            if ($buildingId && $resourceId && $from && $to) {
                                // Notify the relevant entity rooms that overlap status has changed
                                $this->notifyTimeslotChanged(
                                    (int)$buildingId,
                                    (int)$resourceId,
                                    $from,
                                    $to,
                                    $id
                                );
                            }

                            // Get timeslots affected by the application deletion
                            $startDate = new \DateTime($from, new \DateTimeZone('Europe/Oslo'));
                            $endDate = new \DateTime($to, new \DateTimeZone('Europe/Oslo'));

                            // Dates for fetching overlapped timeslots - use a wider range to catch all affected
                            $queryStartDate = clone $startDate;
                            $queryStartDate->modify('-1 day')->setTime(0, 0, 0); // Start one day before

                            $queryEndDate = clone $endDate;
                            $queryEndDate->modify('+1 day')->setTime(23, 59, 59); // End one day after

                            // Fetch the affected timeslots with fresh data after deletion
                            $affectedTimeslots = [];
                            if (is_array($resourceIds) && !empty($resourceIds)) {
                                foreach ($resourceIds as $resId) {
                                    $timeslots = $this->getAffectedTimeslots($buildingId, (int)$resId, $queryStartDate, $queryEndDate);
                                    if (!empty($timeslots)) {
                                        $affectedTimeslots[$resId] = $timeslots;
                                    }
                                }
                            }

                            // Send dedicated notifications to rooms about application deletion
                            if ($buildingId) {
                                WebSocketHelper::sendEntityNotification(
                                    'building',
                                    (int)$buildingId,
                                    'Application deleted',
                                    'deleted',
                                    [
                                        'application_id' => $id,
                                        'from' => $from,
                                        'to' => $to,
                                        'affected_timeslots' => $affectedTimeslots,
                                        'change_type' => 'deletion'
                                    ]
                                );
                            }

                            // Notify each resource's room about the application deletion
                            if (is_array($resourceIds) && !empty($resourceIds)) {
                                foreach ($resourceIds as $resId) {
                                    WebSocketHelper::sendEntityNotification(
                                        'resource',
                                        (int)$resId,
                                        'Application deleted',
                                        'deleted',
                                        [
                                            'application_id' => $id,
                                            'from' => $from,
                                            'to' => $to,
                                            'affected_timeslots' => isset($affectedTimeslots[$resId]) ? $affectedTimeslots[$resId] : [],
                                            'resource_id' => (int)$resId,
                                            'change_type' => 'deletion'
                                        ]
                                    );
                                }
                            }
                        } catch (Exception $innerException) {
                            // Log errors from the forked process
                            error_log("Error in forked WebSocket notification process: " . $innerException->getMessage());
                        }
                    });

                    // Log that notifications were forked
                    error_log("WebSocket notifications for application deletion #{$id} offloaded to forked process");

                } catch (Exception $wsException) {
                    // Log but don't interrupt the response flow
                    error_log("WebSocket notification error in deletePartial: " . $wsException->getMessage());
                }
            }
            WebSocketHelper::triggerPartialApplicationsUpdate();

            $response->getBody()->write(json_encode(['deleted' => $deleted]));
            return $response->withStatus(200)
                ->withHeader('Content-Type', 'application/json');

        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => "Error deleting application: " . $e->getMessage()],
                500
            );
        }
    }


    /**
     * @OA\Post(
     *     path="/bookingfrontend/applications/partials",
     *     summary="Create a new partial application",
     *     tags={"Applications"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             ref="#/components/schemas/Application",
     *             @OA\Property(
     *                 property="articles",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", description="Article mapping ID"),
     *                     @OA\Property(property="quantity", type="integer", description="Quantity ordered"),
     *                     @OA\Property(property="parent_id", type="integer", nullable=true, description="Optional parent mapping ID for sub-items")
     *                 )
     *             )
     *         )
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

            // WebSocket notification disabled for partial applications
            // try {
            //     $resourceId = isset($data['resources']) && !empty($data['resources']) ? $data['resources'][0] : null;
            //     // Use async notification with forking for better performance
            //     WebSocketHelper::forkNotification(function() use ($id, $resourceId) {
            //         WebSocketHelper::notifyPartialApplicationCreated($id, $resourceId);
            //     });
            //     error_log("WebSocket notification for application creation #{$id} offloaded to forked process");
            // } catch (Exception $e) {
            //     // Log but don't interrupt flow
            //     error_log("WebSocket notification error: " . $e->getMessage());
            // }
            WebSocketHelper::triggerPartialApplicationsUpdate($session_id);

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
     *         @OA\JsonContent(
     *             ref="#/components/schemas/Application",
     *             @OA\Property(
     *                 property="articles",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", description="Article mapping ID"),
     *                     @OA\Property(property="quantity", type="integer", description="Quantity ordered"),
     *                     @OA\Property(property="parent_id", type="integer", nullable=true, description="Optional parent mapping ID for sub-items")
     *                 )
     *             )
     *         )
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

            // Get the application to check permissions
            $application = $this->applicationService->getApplicationById($id);
            if (!$application) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Application not found'],
                    404
                );
            }

            // Verify permissions
            if (!$this->applicationHelper->canModifyApplication($application, $request)) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Unauthorized to modify this application'],
                    403
                );
            }

            $data = json_decode($request->getBody()->getContents(), true);
            if (!$data) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Invalid JSON data'],
                    400
                );
            }

            $data['id'] = $id;
            $this->applicationService->savePartialApplication($data);
            WebSocketHelper::triggerPartialApplicationsUpdate();

            $response->getBody()->write(json_encode([
                'message' => 'Application updated successfully'
            ]));
            return $response->withStatus(200)
                ->withHeader('Content-Type', 'application/json');


        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => "Error updating application: " . $e->getMessage()],
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
     *             ),
     *             @OA\Property(
     *                 property="articles",
     *                 type="array",
     *                 description="Complete replacement of articles",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", description="Article mapping ID"),
     *                     @OA\Property(property="quantity", type="integer", description="Quantity ordered"),
     *                     @OA\Property(property="parent_id", type="integer", nullable=true, description="Optional parent mapping ID for sub-items")
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

            // Get the application to check permissions
            $application = $this->applicationService->getApplicationById($id);
            if (!$application) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Application not found'],
                    404
                );
            }

            // Verify permissions
            if (!$this->applicationHelper->canModifyApplication($application, $request)) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Unauthorized to modify this application'],
                    403
                );
            }

            $data = json_decode($request->getBody()->getContents(), true);
            if (!$data) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Invalid JSON data'],
                    400
                );
            }

            $data['id'] = $id;
            $this->applicationService->patchApplication($data);
            WebSocketHelper::triggerPartialApplicationsUpdate();

            $response->getBody()->write(json_encode([
                'message' => 'Application updated successfully'
            ]));
            return $response->withStatus(200)
                ->withHeader('Content-Type', 'application/json');

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


    /**
     * @OA\Post(
     *     path="/bookingfrontend/applications/{id}/documents",
     *     summary="Upload documents for an application",
     *     description="Upload one or more documents to an application. Only the application owner or delegates can upload documents.",
     *     tags={"Applications"},
     *     security={{ "oidc": {} }},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID of the application",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 @OA\Property(
     *                     property="files[]",
     *                     type="array",
     *                     @OA\Items(type="string", format="binary"),
     *                     description="The files to upload"
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Documents successfully uploaded",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(
     *                 property="document_ids",
     *                 type="array",
     *                 @OA\Items(type="integer")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="No files provided"
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Unauthorized to upload documents to this application"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Application not found"
     *     )
     * )
     */
    public function uploadDocument(Request $request, Response $response, array $args): Response
    {
        try {
            $applicationId = (int)$args['id'];

            // Get application using ApplicationService
            $application = $this->applicationService->getApplicationById($applicationId);
            if (!$application) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Application not found'],
                    404
                );
            }

            // Verify ownership
            if (!$this->applicationHelper->canModifyApplication($application, $request)) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Unauthorized to upload documents to this application'],
                    403
                );
            }

            $uploadedFiles = $request->getUploadedFiles();

            if (empty($uploadedFiles['files'])) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'No files uploaded'],
                    400
                );
            }
            $files = $uploadedFiles['files'];
            if (!is_array($files)) {
                $files = [$files];
            }

            $documents = [];
            $parseBody = $request->getParsedBody();
            $description = $parseBody['description'] ?? null;

            foreach ($files as $file) {
                // Basic validation
                $filename = $file->getClientFilename();
//                $fileType = $this->documentService->getFileTypeFromFilename($filename);
//
//                if (!$this->documentService->isAllowedFileType($fileType)) {
//                    return ResponseHelper::sendErrorResponse(
//                        ['error' => "File type not allowed for: {$filename}"],
//                        400
//                    );
//                }

                // Create document record
                $document = [
                    'category' => Document::CATEGORY_OTHER,
                    'owner_id' => $applicationId,
                    'name' => $filename,
                    'description' => $description ?? $filename
                ];

                $docId = $this->documentService->createDocument($document);

                // Move uploaded file to correct location
                $this->documentService->saveDocumentFile($docId, $file);
                $documents[] = $docId;
            }

            WebSocketHelper::triggerPartialApplicationsUpdate();


            $response->getBody()->write(json_encode([
                'message' => 'Files uploaded successfully',
                'document_ids' => $documents
            ]));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => "Error uploading files: " . $e->getMessage()],
                500
            );
        }
    }


    /**
     * @OA\Delete(
     *     path="/bookingfrontend/applications/document/{id}",
     *     summary="Delete a document from an application",
     *     description="Delete a specific document. Only the application owner or delegates can delete documents.",
     *     tags={"Applications"},
     *     security={{ "oidc": {} }},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID of the document to delete",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=204,
     *         description="Document successfully deleted"
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Unauthorized to delete this document"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Document or associated application not found"
     *     )
     * )
     */
    public function deleteDocument(Request $request, Response $response, array $args): Response
    {
        try {
            $documentId = (int)$args['id'];

            $document = $this->documentService->getDocumentById($documentId);
            if (!$document) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Document not found'],
                    404
                );
            }

            // Get the application using ApplicationService
            $application = $this->applicationService->getApplicationById($document->owner_id);
            if (!$application) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Associated application not found'],
                    404
                );
            }

            // Verify ownership
            if (!$this->applicationHelper->canModifyApplication($application, $request)) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Unauthorized to delete this document'],
                    403
                );
            }

            // Delete the document and its file
            $this->documentService->deleteDocument($documentId);
            WebSocketHelper::triggerPartialApplicationsUpdate();

            return $response->withStatus(204);

        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => "Error deleting document: " . $e->getMessage()],
                500
            );
        }
    }



    /**
     * @OA\Post(
     *     path="/bookingfrontend/applications/simple",
     *     summary="Create a simple application booking for a specific timeslot",
     *     tags={"Applications"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             required={"resource_id", "building_id", "from", "to"},
     *             @OA\Property(property="resource_id", type="integer"),
     *             @OA\Property(property="building_id", type="integer"),
     *             @OA\Property(property="from", type="integer", description="Start timestamp in seconds"),
     *             @OA\Property(property="to", type="integer", description="End timestamp in seconds")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Simple application created",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="id", type="integer"),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="status", type="string")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid input or timeslot unavailable"
     *     )
     * )
     */
    public function createSimpleApplication(Request $request, Response $response): Response
    {
        try {
            $startTime = microtime(true);
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

            // Validate required fields
            $requiredFields = ['resource_id', 'building_id', 'from', 'to'];
            foreach ($requiredFields as $field) {
                if (!isset($data[$field])) {
                    return ResponseHelper::sendErrorResponse(
                        ['error' => "Missing required field: {$field}"],
                        400
                    );
                }
            }

            // Optimized timestamp conversion - reduce object creation and string operations
            $fromTimestamp = is_numeric($data['from']) ? (int)$data['from'] : 0;
            $toTimestamp = is_numeric($data['to']) ? (int)$data['to'] : 0;

            // If these look like milliseconds (13 digits), convert to seconds
            if ($fromTimestamp > 10000000000) {
                $fromTimestamp = (int)($fromTimestamp / 1000);
            }
            if ($toTimestamp > 10000000000) {
                $toTimestamp = (int)($toTimestamp / 1000);
            }

            // Create DateTime objects with Oslo timezone once
            $osloTz = new \DateTimeZone('Europe/Oslo');
            $fromDate = new \DateTime('@' . $fromTimestamp); // Create with UTC
            $fromDate->setTimezone($osloTz);                 // Convert to Oslo

            $toDate = new \DateTime('@' . $toTimestamp);     // Create with UTC
            $toDate->setTimezone($osloTz);                   // Convert to Oslo

            // Format for database with Oslo timezone
            $from = $fromDate->format('Y-m-d H:i:s');
            $to = $toDate->format('Y-m-d H:i:s');


            // Check if resource supports simple booking and is available
            $result = $this->applicationService->createSimpleBooking(
                (int)$data['resource_id'],
                (int)$data['building_id'],
                $from,
                $to,
                $session_id
            );


            $responseData = [
                'id' => $result['id'],
                'message' => 'Simple application created successfully',
                'status' => $result['status']
            ];

            // Offload WebSocket notifications to a separate process
            WebSocketHelper::forkNotification(function() use ($data, $from, $to, $result) {
                try {
                    // Notify about the timeslot change to update overlap status
                    $this->notifyTimeslotChanged(
                        (int)$data['building_id'],
                        (int)$data['resource_id'],
                        $from,
                        $to,
                        $result['id']
                    );
                    error_log("WebSocket notifications for application creation #{$result['id']} completed in forked process");
                } catch (Exception $innerException) {
                    error_log("Error in forked WebSocket notification process: " . $innerException->getMessage());
                }
            });

            WebSocketHelper::triggerPartialApplicationsUpdate($session_id);

            // Performance logging (in production, this should be conditional based on debug flag)
            $endTime = microtime(true);
            $executionTime = round(($endTime - $startTime) * 1000, 2); // convert to ms
            error_log("Simple application creation for resource {$data['resource_id']} took {$executionTime}ms (API processing time, excludes forked operations)");

            $response->getBody()->write(json_encode($responseData));
            return $response->withStatus(201)
                ->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => "Error creating simple application: " . $e->getMessage()],
                500
            );
        }
    }




    /**
     * @OA\Get(
     *     path="/bookingfrontend/applications/articles",
     *     summary="Get available articles for resources",
     *     tags={"Applications"},
     *     @OA\Parameter(
     *         name="resources[]",
     *         in="query",
     *         required=true,
     *         @OA\Schema(type="array", @OA\Items(type="integer"))
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="List of articles",
     *         @OA\JsonContent(
     *             type="array",
     *              @OA\Items(type="object")
     *         )
     *     )
     * )
     */
    public function getArticlesByResources(Request $request, Response $response): Response
    {
        try
        {
            $resources = $request->getQueryParams()['resources'] ?? [];

            if (empty($resources))
            {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Resources parameter is required'],
                    400
                );
            }

            // Get articles by resources directly from the repository
            $articles = $this->articleRepository->getArticlesByResources($resources);

            $response->getBody()->write(json_encode($articles));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e)
        {
            return ResponseHelper::sendErrorResponse(
                ['error' => "Error fetching articles: " . $e->getMessage()],
                500
            );
        }
    }

    /**
     * Broadcasts a notification when a partial application is created
     *
     * @param int $id The ID of the created partial application
     * @param array $data The application data
     * @return void
     */
    protected function broadcastPartialApplicationCreated(int $id, array $data): void
    {
        try {
            // Extract relevant information to include in the notification
            $notificationData = [
                'id' => $id,
                'type' => 'partial_application_created',
                'resource_id' => $data['resources'][0] ?? null,
                'timestamp' => date('c')
            ];

            // Send the notification through WebSocket
            WebSocketHelper::sendNotification(
                'New partial application created',
                $notificationData
            );
        } catch (Exception $e) {
            // Log error but don't interrupt the main request flow
            error_log("WebSocket notification error: " . $e->getMessage());
        }
    }

    /**
     * Gets the affected timeslots for a resource in the same format as the get_freetime endpoint
     *
     * @param int $buildingId The building ID
     * @param int $resourceId The resource ID
     * @param \DateTime $startDate The start date for query
     * @param \DateTime $endDate The end date for query
     * @return array Timeslots in the same format as the get_freetime endpoint
     */
    protected function getAffectedTimeslots(int $buildingId, int $resourceId, \DateTime $startDate, \DateTime $endDate): array
    {
        // Create a booking business object to access the get_free_events method
        $bo = \CreateObject('booking.bobooking');

        // Format dates for the get_free_events method
        $formattedStartDate = $startDate->format('d/m-Y');
        $formattedEndDate = $endDate->format('d/m-Y');

        // Save current request parameters
        $originalRequest = $_REQUEST;

        // Set up the request parameters for get_freetime
        $_REQUEST['building_id'] = $buildingId;
        $_REQUEST['resource_id'] = $resourceId;
        $_REQUEST['start_date'] = $formattedStartDate;
        $_REQUEST['end_date'] = $formattedEndDate;
        $_REQUEST['detailed_overlap'] = true;
        $_REQUEST['stop_on_end_date'] = true;

        // Use the existing logic to get timeslots
        $uibooking = \CreateObject('bookingfrontend.uibooking');
        $timeslots = $uibooking->get_freetime();

        // Restore original request parameters
        $_REQUEST = $originalRequest;

        // Return the timeslots for this resource
        return isset($timeslots[$resourceId]) ? $timeslots[$resourceId] : [];
    }

    /**
     * Sends WebSocket notifications to entity rooms when timeslot reservation changes
     * Notifies both the building and resource rooms about the changed overlap status
     *
     * @param int $buildingId The building ID
     * @param int $resourceId The resource ID
     * @param string $from Start datetime
     * @param string $to End datetime
     * @param int $applicationId Application ID that caused the change
     * @return void
     */
    protected function notifyTimeslotChanged(int $buildingId, int $resourceId, string $from, string $to, int $applicationId): void
    {
        try {
            // Always use Oslo timezone
            $tzObject = new \DateTimeZone('Europe/Oslo');

            // Create DateTime objects with the Oslo timezone
            $startDateTime = new \DateTime($from, $tzObject);
            $endDateTime = new \DateTime($to, $tzObject);

            // Dates for fetching overlapped timeslots
            $queryStartDate = clone $startDateTime;
            $queryStartDate->modify('-1 day')->setTime(0, 0, 0); // Start one day before

            $queryEndDate = clone $endDateTime;
            $queryEndDate->modify('+1 day')->setTime(23, 59, 59); // End one day after

            // Get the affected timeslots from the booking engine after the change
            // This uses the same logic as the get_freetime endpoint
            $affectedTimeslots = [];
            $resourceTimeslots = $this->getAffectedTimeslots($buildingId, $resourceId, $queryStartDate, $queryEndDate);
            if (!empty($resourceTimeslots)) {
                $affectedTimeslots[$resourceId] = $resourceTimeslots;
            }

            // Prepare detailed data for both notifications
            $eventData = [
                'application_id' => $applicationId,
                'from' => $from,
                'to' => $to,
                'change_type' => 'overlap_status',
                'affected_timeslots' => $affectedTimeslots
            ];

            // Notify the building room - this will only go to clients subscribed to this entity
            WebSocketHelper::sendEntityNotification(
                'building',
                $buildingId,
                'Timeslot reservation changed',
                'updated',
                $eventData
            );

            // Prepare resource-specific data (include resource ID)
            $resourceData = $eventData;
            $resourceData['resource_id'] = $resourceId;

            // For resource-specific notifications, use a simplified format
            if (isset($affectedTimeslots[$resourceId])) {
                $resourceData['affected_timeslots'] = $affectedTimeslots[$resourceId];
            } else {
                $resourceData['affected_timeslots'] = [];
            }

            // Notify the resource room - this will only go to clients subscribed to this entity
            WebSocketHelper::sendEntityNotification(
                'resource',
                $resourceId,
                'Timeslot reservation changed',
                'updated',
                $resourceData
            );

            error_log("WebSocket notifications sent for timeslot reservation: building_id={$buildingId}, resource_id={$resourceId}, from={$from}, to={$to}");
        } catch (Exception $e) {
            // Log error but don't interrupt the main request flow
            error_log("WebSocket notification error: " . $e->getMessage());
        }
    }

}
