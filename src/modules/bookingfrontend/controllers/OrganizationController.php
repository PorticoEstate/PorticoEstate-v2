<?php

namespace App\modules\bookingfrontend\controllers;

use App\modules\bookingfrontend\helpers\ResponseHelper;
use App\modules\bookingfrontend\services\OrganizationService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;


/**
 * @OA\Tag(
 *     name="Organizations",
 *     description="API Endpoints for Organizations"
 * )
 */

class OrganizationController
{
    private $organizationService;

    public function __construct()
    {
        $this->organizationService = new OrganizationService();
    }

    /**
     * @OA\Post(
     *     path="/bookingfrontend/organizations",
     *     summary="Create a new organization",
     *     tags={"Organizations"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             required={"organization_number"},
     *             @OA\Property(property="organization_number", type="string", description="Norwegian organization number"),
     *             @OA\Property(property="name", type="string"),
     *             @OA\Property(property="activity_id", type="integer"),
     *             @OA\Property(property="contacts", type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="name", type="string"),
     *                     @OA\Property(property="email", type="string"),
     *                     @OA\Property(property="phone", type="string")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Organization created successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="id", type="integer"),
     *             @OA\Property(property="message", type="string")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid input"
     *     )
     * )
     */
    public function create(Request $request, Response $response): Response
    {
        try {
            $data = json_decode($request->getBody()->getContents(), true);

            if (empty($data['organization_number'])) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Organization number is required'],
                    400
                );
            }

            $id = $this->organizationService->createOrganization($data);

            // Optionally add current user as delegate if requested
//            if (!empty($data['add_current_user_delegate']) && $this->userHelper->is_logged_in()) {
//                $this->organizationService->addDelegate($id, $this->userHelper->ssn);
//            }

            $response->getBody()->write(json_encode([
                'id' => $id,
                'message' => 'Organization created successfully'
            ]));

            return $response->withStatus(201)
                ->withHeader('Content-Type', 'application/json');

        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => $e->getMessage()],
                500
            );
        }
    }


    /**
     * @OA\Get(
     *     path="/bookingfrontend/organizations/lookup/{number}",
     *     summary="Look up organization details from Brønnøysund Register",
     *     tags={"Organizations"},
     *     @OA\Parameter(
     *         name="number",
     *         in="path",
     *         required=true,
     *         description="Norwegian organization number",
     *         @OA\Schema(type="string", pattern="^\d{9}$")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Organization details found",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="navn", type="string"),
     *             @OA\Property(property="organisasjonsnummer", type="string"),
     *             @OA\Property(property="hjemmeside", type="string", nullable=true),
     *             @OA\Property(
     *                 property="postadresse",
     *                 type="object",
     *                 @OA\Property(property="adresse", type="array", @OA\Items(type="string")),
     *                 @OA\Property(property="postnummer", type="string"),
     *                 @OA\Property(property="poststed", type="string")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Organization not found"
     *     )
     * )
     */
    public function lookup(Request $request, Response $response, array $args): Response
    {
        try {
            $orgNumber = $args['number'];

            // Validate organization number format
            if (!preg_match('/^\d{9}$/', $orgNumber)) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Invalid organization number format'],
                    400
                );
            }

            $orgData = $this->organizationService->lookupOrganization($orgNumber);

            if (!$orgData) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Organization not found'],
                    404
                );
            }

            $response->getBody()->write(json_encode($orgData));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => $e->getMessage()],
                500
            );
        }
    }


    /**
     * @OA\Put(
     *     path="/bookingfrontend/organizations/{id}",
     *     summary="Update an organization's details",
     *     tags={"Organizations"},
     *     security={{ "oidc": {} }},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Organization ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="name", type="string"),
     *             @OA\Property(property="phone", type="string"),
     *             @OA\Property(property="email", type="string"),
     *             @OA\Property(property="homepage", type="string"),
     *             @OA\Property(property="description", type="string")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Organization updated successfully"
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Not authorized to modify this organization"
     *     )
     * )
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        try {
            $id = (int)$args['id'];

            // Check if user has access to modify this organization
            if (!$this->organizationService->hasAccess($id)) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Not authorized to modify this organization'],
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

            $this->organizationService->updateOrganization($id, $data);

            return $response->withStatus(200)
                ->withHeader('Content-Type', 'application/json')
                ->write(json_encode(['message' => 'Organization updated successfully']));

        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => $e->getMessage()],
                500
            );
        }
    }


    /**
     * @OA\Post(
     *     path="/bookingfrontend/organizations/{id}/delegates",
     *     summary="Add a delegate to an organization",
     *     tags={"Organizations"},
     *     security={{ "oidc": {} }},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Organization ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             required={"ssn"},
     *             @OA\Property(property="ssn", type="string", description="Norwegian social security number")
     *         )
     *     ),
     *     @OA\Response(
     *         response=204,
     *         description="Delegate added successfully"
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid input"
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Not authorized to add delegates to this organization"
     *     )
     * )
     */
    public function addDelegate(Request $request, Response $response, array $args): Response
    {
        try {
            $id = (int)$args['id'];

            if (!$this->organizationService->hasAccess($id)) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Not authorized to add delegates to this organization'],
                    403
                );
            }
            $data = json_decode($request->getBody()->getContents(), true);

            if (empty($data['ssn'])) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'SSN is required'],
                    400
                );
            }

            $this->organizationService->addDelegate($id, $data['ssn']);

            return $response->withStatus(204);

        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => $e->getMessage()],
                500
            );
        }
    }


    /**
     * @OA\Get(
     *     path="/bookingfrontend/organizations/my",
     *     summary="Get organizations for authenticated user",
     *     tags={"Organizations"},
     *     security={{ "oidc": {} }},
     *     @OA\Response(
     *         response=200,
     *         description="List of organizations",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="results",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer"),
     *                     @OA\Property(property="name", type="string"),
     *                     @OA\Property(property="organization_number", type="string"),
     *                     @OA\Property(property="is_delegate", type="boolean"),
     *                     @OA\Property(property="active", type="integer")
     *                 )
     *             ),
     *             @OA\Property(property="total_records", type="integer")
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Not authenticated"
     *     )
     * )
     */
    public function getMyOrganizations(Request $request, Response $response): Response
    {
        try {
            $organizations = $this->organizationService->getMyOrganizations();

            $response->getBody()->write(json_encode([
                'results' => $organizations,
                'total_records' => count($organizations)
            ]));

            return $response->withHeader('Content-Type', 'application/json');

        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => $e->getMessage()],
                500
            );
        }
    }

    /**
     * @OA\Get(
     *     path="/bookingfrontend/organizations/list",
     *     summary="Get paginated list of organizations for select2",
     *     tags={"Organizations"},
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         description="Page number",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="query",
     *         in="query",
     *         description="Search query",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="List of organizations",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="results", type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="string"),
     *                     @OA\Property(property="text", type="string"),
     *                     @OA\Property(property="disabled", type="boolean"),
     *                     @OA\Property(property="name", type="string"),
     *                     @OA\Property(property="organization_number", type="string")
     *                 )
     *             ),
     *             @OA\Property(property="pagination", type="object",
     *                 @OA\Property(property="more", type="boolean")
     *             )
     *         )
     *     )
     * )
     */
    public function getList(Request $request, Response $response): Response
    {
        try {
            $page = max(1, (int)$request->getQueryParams()['page'] ?? 1); // Ensure page is at least 1
            $query = $request->getQueryParams()['query'] ?? '';
            $length = 15; // Or get from settings
            $start = max(0, ($page - 1) * $length); // Ensure start is never negative

            $organizations = $this->organizationService->getOrganizationList(
                $start,
                $length,
                $query
            );

            $total = $organizations['total'];
            $results = array_map(function($entry) {
                return [
                    'id' => "{$entry['id']}_{$entry['organization_number']}",
                    'text' => "{$entry['organization_number']} [{$entry['name']}]",
                    'disabled' => !$entry['active'],
                    'name' => $entry['name'],
                    'organization_number' => $entry['organization_number']
                ];
            }, $organizations['results']);

            $morePages = $total > ($start + count($results)); // Fixed pagination calculation

            $responseData = [
                'results' => $results,
                'pagination' => [
                    'more' => $morePages
                ]
            ];

            $response->getBody()->write(json_encode($responseData));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => $e->getMessage()],
                500
            );
        }
    }
}