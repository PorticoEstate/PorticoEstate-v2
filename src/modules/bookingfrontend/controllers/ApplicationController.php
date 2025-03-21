<?php

namespace App\modules\bookingfrontend\controllers;

use App\modules\bookingfrontend\helpers\ResponseHelper;
use App\modules\bookingfrontend\helpers\UserHelper;
use App\modules\bookingfrontend\models\Document;
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
class ApplicationController extends DocumentController
{
    private $bouser;
    private $applicationService;
    private $userSettings;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct(Document::OWNER_APPLICATION);
        $this->bouser = new UserHelper();
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
            if (!$this->canModifyApplication($application)) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Unauthorized to delete this application'],
                    403
                );
            }


            $deleted = $this->applicationService->deletePartial($id);
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

            // Get the application to check permissions
            $application = $this->applicationService->getApplicationById($id);
            if (!$application) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Application not found'],
                    404
                );
            }

            // Verify permissions
            if (!$this->canModifyApplication($application)) {
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
            if (!$this->canModifyApplication($application)) {
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
     * @OA\Post(
     *     path="/bookingfrontend/applications/partials/checkout",
     *     summary="Update and finalize all partial applications with contact and organization info",
     *     tags={"Applications"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             required={"customerType", "contactName", "contactEmail", "contactPhone"},
     *             @OA\Property(property="customerType", type="string", enum={"ssn", "organization_number"}),
     *             @OA\Property(property="organizationNumber", type="string"),
     *             @OA\Property(property="organizationName", type="string"),
     *             @OA\Property(property="contactName", type="string"),
     *             @OA\Property(property="contactEmail", type="string"),
     *             @OA\Property(property="contactPhone", type="string"),
     *             @OA\Property(property="street", type="string"),
     *             @OA\Property(property="zipCode", type="string"),
     *             @OA\Property(property="city", type="string"),
     *             @OA\Property(property="eventTitle", type="string"),
     *             @OA\Property(property="organizerName", type="string")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Applications updated successfully"
     *     )
     * )
     */
    public function checkoutPartials(Request $request, Response $response): Response
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

            try {
				// Update all partial applications
				$result = $this->applicationService->checkoutPartials($session_id, $data);

				// Add timestamp and session info for debugging
				$result['debug_timestamp'] = date('Y-m-d H:i:s');
				$result['debug_session_id'] = $session_id;

				$response->getBody()->write(json_encode([
					'message' => 'Applications processed successfully',
					'applications' => $result['updated'],
					'skipped' => $result['skipped'],
					'debug_info' => [
						'collisions' => $result['debug_collisions'],
						'timestamp' => $result['debug_timestamp'],
						'session_id' => $result['debug_session_id']
					]
				]));
				return $response->withHeader('Content-Type', 'application/json');

            } catch (Exception $e) {
                // Check if the error message contains validation errors
                if (strpos($e->getMessage(), ',') !== false) {
                    // This is likely a validation error with multiple messages
                    return ResponseHelper::sendErrorResponse(
                        ['errors' => explode(', ', $e->getMessage())],
                        400
                    );
                }
                throw $e;
            }

        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => $e->getMessage()],
                500
            );
        }
    }


	/**
	 * @OA\Post(
	 *     path="/bookingfrontend/applications/validate-checkout",
	 *     summary="Validate if checkout of partial applications would succeed",
	 *     tags={"Applications"},
	 *     @OA\RequestBody(
	 *         required=true,
	 *         @OA\JsonContent(
	 *             type="object",
	 *             required={"customerType", "contactName", "contactEmail", "contactPhone"},
	 *             @OA\Property(property="customerType", type="string", enum={"ssn", "organization_number"}),
	 *             @OA\Property(property="organizationNumber", type="string"),
	 *             @OA\Property(property="organizationName", type="string"),
	 *             @OA\Property(property="contactName", type="string"),
	 *             @OA\Property(property="contactEmail", type="string"),
	 *             @OA\Property(property="contactPhone", type="string"),
	 *             @OA\Property(property="street", type="string"),
	 *             @OA\Property(property="zipCode", type="string"),
	 *             @OA\Property(property="city", type="string"),
	 *             @OA\Property(property="eventTitle", type="string"),
	 *             @OA\Property(property="organizerName", type="string")
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Validation results",
	 *         @OA\JsonContent(
	 *             type="object",
	 *             @OA\Property(property="valid", type="boolean"),
	 *             @OA\Property(
	 *                 property="applications",
	 *                 type="array",
	 *                 @OA\Items(
	 *                     type="object",
	 *                     @OA\Property(property="id", type="integer"),
	 *                     @OA\Property(property="valid", type="boolean"),
	 *                     @OA\Property(property="would_be_direct_booking", type="boolean"),
	 *                     @OA\Property(
	 *                         property="issues",
	 *                         type="array",
	 *                         @OA\Items(
	 *                             type="object",
	 *                             @OA\Property(property="type", type="string"),
	 *                             @OA\Property(property="message", type="string")
	 *                         )
	 *                     )
	 *                 )
	 *             )
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=400,
	 *         description="Invalid data or no active session"
	 *     )
	 * )
	 */
	public function validateCheckout(Request $request, Response $response): Response
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

			// Validate checkout
			$validationResults = $this->applicationService->validateCheckout($session_id, $data);

			$validationResults['debug_timestamp'] = date('Y-m-d H:i:s');
			$validationResults['debug_session_id'] = $session_id;

			$response->getBody()->write(json_encode($validationResults));
			return $response->withHeader('Content-Type', 'application/json');

		} catch (Exception $e) {
			return ResponseHelper::sendErrorResponse(
				['error' => $e->getMessage()],
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
            if (!$this->canModifyApplication($application)) {
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
            if (!$this->canModifyApplication($application)) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Unauthorized to delete this document'],
                    403
                );
            }

            // Delete the document and its file
            $this->documentService->deleteDocument($documentId);

            return $response->withStatus(204);

        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => "Error deleting document: " . $e->getMessage()],
                500
            );
        }
    }

    /**
     * Check if the current user/session can modify the given application
     *
     * Verifies either:
     * - Application belongs to current session (for in-progress applications)
     * OR if user is logged in:
     * - Is the direct owner (via SSN)
     * - Belongs to the owning organization
     * - Is a delegate for the owning organization
     *
     * @param array $application The application data to check
     * @return bool True if user can modify the application, false otherwise
     */
    private function canModifyApplication(array $application): bool
    {
        $session = Sessions::getInstance();
        $session_id = $session->get_session_id();

        // Check if application belongs to current session
        if ($application['status'] === 'NEWPARTIAL1' && $application['session_id'] === $session_id) {
            return true;
        }

        // Additional checks if user is logged in
        if ($this->bouser->is_logged_in()) {
            $ssn = $this->bouser->ssn;
            $orgnr = $this->bouser->orgnr;

            if ($application['customer_ssn'] === $ssn) {
                return true;
            }

            if ($application['customer_identifier_type'] === 'organization_number'
                && $application['customer_organization_number'] === $orgnr) {
                return true;
            }

            if ($application['customer_identifier_type'] === 'organization_number'
                && $this->bouser->organizations) {
                foreach ($this->bouser->organizations as $org) {
                    if ($org['orgnr'] === $application['customer_organization_number']) {
                        return true;
                    }
                }
            }
        }

        return false;
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


			// FIXED CONVERSION: Properly convert milliseconds to date strings
			// Debug before conversion
			error_log("Timestamps received: from={$data['from']}, to={$data['to']}");
			$osloTz = new \DateTimeZone('Europe/Oslo');
			// Convert from milliseconds to seconds if needed
			$fromTimestamp = is_numeric($data['from']) ? (int)$data['from'] : 0;
			$toTimestamp = is_numeric($data['to']) ? (int)$data['to'] : 0;

			// If these look like milliseconds (13 digits), convert to seconds
			if ($fromTimestamp > 10000000000) {
				$fromTimestamp = (int)($fromTimestamp / 1000);
			}
			if ($toTimestamp > 10000000000) {
				$toTimestamp = (int)($toTimestamp / 1000);
			}

			// Create DateTime objects with Oslo timezone
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

			// Get articles by resources
			$articles = $this->applicationService->getArticlesByResources($resources);

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
}