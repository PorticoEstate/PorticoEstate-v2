<?php

namespace App\modules\bookingfrontend\controllers;

use App\modules\bookingfrontend\helpers\ResponseHelper;
use App\modules\bookingfrontend\services\OrganizationService;
use App\modules\bookingfrontend\models\Document;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Exception;


/**
 * @OA\Tag(
 *     name="Organizations",
 *     description="API Endpoints for Organizations"
 * )
 */

class OrganizationController extends DocumentController
{
    private $organizationService;

    public function __construct()
    {
        parent::__construct(Document::OWNER_ORGANIZATION);
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

            return ResponseHelper::sendJSONResponse([
                'id' => $id,
                'message' => 'Organization created successfully'
            ], 201);

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

            return ResponseHelper::sendJSONResponse($orgData);

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
     *             @OA\Property(property="shortname", type="string"),
     *             @OA\Property(property="phone", type="string"),
     *             @OA\Property(property="email", type="string"),
     *             @OA\Property(property="homepage", type="string"),
     *             @OA\Property(property="activity_id", type="integer"),
     *             @OA\Property(property="show_in_portal", type="boolean"),
     *             @OA\Property(property="street", type="string"),
     *             @OA\Property(property="zip_code", type="string"),
     *             @OA\Property(property="city", type="string"),
     *             @OA\Property(property="description_json", type="string")
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

            return ResponseHelper::sendJSONResponse(['message' => 'Organization updated successfully']);

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
     *             @OA\Property(property="ssn", type="string", description="Norwegian social security number"),
     *             @OA\Property(property="name", type="string", description="Delegate name"),
     *             @OA\Property(property="email", type="string", description="Delegate email address"),
     *             @OA\Property(property="phone", type="string", description="Delegate phone number"),
     *             @OA\Property(property="active", type="boolean", description="Whether delegate is active", default=true)
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

            $this->organizationService->addDelegate($id, $data);

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

            return ResponseHelper::sendJSONResponse([
                'results' => $organizations,
                'total_records' => count($organizations)
            ]);

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

            return ResponseHelper::sendJSONResponse($responseData);

        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => $e->getMessage()],
                500
            );
        }
    }

    /**
     * @OA\Get(
     *     path="/bookingfrontend/organizations/{id}",
     *     summary="Get a specific organization by ID",
     *     tags={"Organizations"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Organization ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Organization details",
     *         @OA\JsonContent(ref="#/components/schemas/Organization")
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Organization not found"
     *     )
     * )
     */
    public function getById(Request $request, Response $response, array $args): Response
    {
        try {
            $id = (int)$args['id'];

            $organization = $this->organizationService->getOrganization($id);

            if (!$organization) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Organization not found'],
                    404
                );
            }

            // Evaluate user access to this organization
            $userHasAccess = $this->organizationService->hasAccess($id);

            // Use the model's serialize function with access context
            return ResponseHelper::sendJSONResponse($organization->serialize(['user_has_access' => $userHasAccess]));
//            return ResponseHelper::sendJSONResponse(['user_has_access' => $userHasAccess , 'data' => $organization->serialize(['user_has_access' => $userHasAccess])]);

        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => $e->getMessage()],
                500
            );
        }
    }

    /**
     * @OA\Get(
     *     path="/bookingfrontend/organizations/{id}/groups",
     *     summary="Get groups associated with an organization",
     *     tags={"Organizations"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Organization ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="List of groups in short format",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(ref="#/components/schemas/Group")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Organization not found"
     *     )
     * )
     */
    public function getGroups(Request $request, Response $response, array $args): Response
    {
        try {
            $id = (int)$args['id'];

            // Verify organization exists
            $organization = $this->organizationService->getOrganization($id);
            if (!$organization) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Organization not found'],
                    404
                );
            }

            $groups = $this->organizationService->getOrganizationGroups($id);

            return ResponseHelper::sendJSONResponse($groups);

        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => $e->getMessage()],
                500
            );
        }
    }

    /**
     * @OA\Get(
     *     path="/bookingfrontend/organizations/{id}/groups/{group_id}",
     *     summary="Get a specific group by ID from an organization",
     *     tags={"Organizations"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Organization ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="group_id",
     *         in="path",
     *         required=true,
     *         description="Group ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Group details",
     *         @OA\JsonContent(ref="#/components/schemas/Group")
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Organization or group not found"
     *     )
     * )
     */
    public function getGroup(Request $request, Response $response, array $args): Response
    {
        try {
            $id = (int)$args['id'];
            $groupId = (int)$args['group_id'];

            // Verify organization exists
            $organization = $this->organizationService->getOrganization($id);
            if (!$organization) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Organization not found'],
                    404
                );
            }

            // Verify group belongs to organization and get the group
            $group = $this->organizationService->getOrganizationGroup($id, $groupId);
            if (!$group) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Group not found in this organization'],
                    404
                );
            }

            return ResponseHelper::sendJSONResponse($group);

        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => $e->getMessage()],
                500
            );
        }
    }

    /**
     * @OA\Post(
     *     path="/bookingfrontend/organizations/{id}/groups",
     *     summary="Create a new group for an organization",
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
     *             required={"name"},
     *             @OA\Property(property="name", type="string", description="Group name"),
     *             @OA\Property(property="shortname", type="string", description="Group short name (max 11 chars)"),
     *             @OA\Property(property="description", type="string", description="Group description"),
     *             @OA\Property(property="parent_id", type="integer", description="Parent group ID"),
     *             @OA\Property(property="activity_id", type="integer", description="Activity ID"),
     *             @OA\Property(property="show_in_portal", type="boolean", description="Show in public portal"),
     *             @OA\Property(
     *                 property="contacts",
     *                 type="array",
     *                 description="Group contacts (max 2)",
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
     *         description="Group created successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="id", type="integer"),
     *             @OA\Property(property="message", type="string")
     *         )
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Not authorized to create groups for this organization"
     *     )
     * )
     */
    public function createGroup(Request $request, Response $response, array $args): Response
    {
        try {
            $organizationId = (int)$args['id'];

            // Check if user has access to modify this organization
            if (!$this->organizationService->hasAccess($organizationId)) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Not authorized to create groups for this organization'],
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

            if (empty($data['name'])) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Group name is required'],
                    400
                );
            }

            $data['organization_id'] = $organizationId;
            $groupId = $this->organizationService->createGroup($data);

            return ResponseHelper::sendJSONResponse([
                'id' => $groupId,
                'message' => 'Group created successfully'
            ], 201);

        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => $e->getMessage()],
                500
            );
        }
    }

    /**
     * @OA\Put(
     *     path="/bookingfrontend/organizations/{id}/groups/{group_id}",
     *     summary="Update a group for an organization",
     *     tags={"Organizations"},
     *     security={{ "oidc": {} }},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Organization ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="group_id",
     *         in="path",
     *         required=true,
     *         description="Group ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="name", type="string", description="Group name"),
     *             @OA\Property(property="shortname", type="string", description="Group short name (max 11 chars)"),
     *             @OA\Property(property="description", type="string", description="Group description"),
     *             @OA\Property(property="parent_id", type="integer", description="Parent group ID"),
     *             @OA\Property(property="activity_id", type="integer", description="Activity ID"),
     *             @OA\Property(property="show_in_portal", type="boolean", description="Show in public portal"),
     *             @OA\Property(property="active", type="boolean", description="Group active status (can be toggled to deactivate instead of deleting)"),
     *             @OA\Property(
     *                 property="contacts",
     *                 type="array",
     *                 description="Group contacts (max 2)",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", description="Contact ID (for updates)"),
     *                     @OA\Property(property="name", type="string"),
     *                     @OA\Property(property="email", type="string"),
     *                     @OA\Property(property="phone", type="string")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Group updated successfully"
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Not authorized to update groups for this organization"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Group not found"
     *     )
     * )
     */
    public function updateGroup(Request $request, Response $response, array $args): Response
    {
        try {
            $organizationId = (int)$args['id'];
            $groupId = (int)$args['group_id'];

            // Check if user has access to modify this organization
            if (!$this->organizationService->hasAccess($organizationId)) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Not authorized to update groups for this organization'],
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

            // Verify group belongs to organization
            if (!$this->organizationService->groupBelongsToOrganization($groupId, $organizationId)) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Group not found in this organization'],
                    404
                );
            }

            $this->organizationService->updateGroup($groupId, $data);

            return ResponseHelper::sendJSONResponse(['message' => 'Group updated successfully']);

        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => $e->getMessage()],
                500
            );
        }
    }


    /**
     * @OA\Get(
     *     path="/bookingfrontend/organizations/{id}/buildings",
     *     summary="Get buildings used by an organization",
     *     tags={"Organizations"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Organization ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="List of buildings in short format",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(ref="#/components/schemas/Building")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Organization not found"
     *     )
     * )
     */
    public function getBuildings(Request $request, Response $response, array $args): Response
    {
        try {
            $id = (int)$args['id'];

            // Verify organization exists
            $organization = $this->organizationService->getOrganization($id);
            if (!$organization) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Organization not found'],
                    404
                );
            }

            $buildings = $this->organizationService->getOrganizationBuildings($id);

            return ResponseHelper::sendJSONResponse($buildings);

        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => $e->getMessage()],
                500
            );
        }
    }

    /**
     * @OA\Get(
     *     path="/bookingfrontend/organizations/{id}/delegates",
     *     summary="Get delegates for an organization (requires authentication)",
     *     tags={"Organizations"},
     *     security={{ "oidc": {} }},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Organization ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="List of delegates in short format",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(ref="#/components/schemas/OrganizationDelegate")
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Not authenticated"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Organization not found"
     *     )
     * )
     */
    public function getDelegates(Request $request, Response $response, array $args): Response
    {
        try {
            $id = (int)$args['id'];

            // Check if user is logged in for delegate information
            if (!$this->organizationService->hasAccess($id)) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Authentication required to view delegates'],
                    401
                );
            }

            // Verify organization exists
            $organization = $this->organizationService->getOrganization($id);
            if (!$organization) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Organization not found'],
                    404
                );
            }

            $userHasAccess = $this->organizationService->hasAccess($id);
            $delegates = $this->organizationService->getOrganizationDelegates($id, $userHasAccess);

            return ResponseHelper::sendJSONResponse($delegates);

        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => $e->getMessage()],
                500
            );
        }
    }

    /**
     * @OA\Put(
     *     path="/bookingfrontend/organizations/{id}/delegates/{delegate_id}",
     *     summary="Update a delegate for an organization",
     *     tags={"Organizations"},
     *     security={{ "oidc": {} }},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Organization ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="delegate_id",
     *         in="path",
     *         required=true,
     *         description="Delegate ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="name", type="string", description="Delegate name"),
     *             @OA\Property(property="email", type="string", description="Delegate email address"),
     *             @OA\Property(property="phone", type="string", description="Delegate phone number"),
     *             @OA\Property(property="active", type="boolean", description="Whether delegate is active")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Delegate updated successfully"
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Not authorized to update delegates for this organization"
     *     )
     * )
     */
    public function updateDelegate(Request $request, Response $response, array $args): Response
    {
        try {
            $organizationId = (int)$args['id'];
            $delegateId = (int)$args['delegate_id'];

            if (!$this->organizationService->hasAccess($organizationId)) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Not authorized to update delegates for this organization'],
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

            $this->organizationService->updateDelegate($delegateId, $data);

            return ResponseHelper::sendJSONResponse(['message' => 'Delegate updated successfully']);

        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => $e->getMessage()],
                500
            );
        }
    }

    /**
     * @OA\Delete(
     *     path="/bookingfrontend/organizations/{id}/delegates/{delegate_id}",
     *     summary="Deactivate a delegate from an organization (soft delete)",
     *     tags={"Organizations"},
     *     security={{ "oidc": {} }},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Organization ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="delegate_id",
     *         in="path",
     *         required=true,
     *         description="Delegate ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=204,
     *         description="Delegate deactivated successfully"
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Not authorized to remove delegates from this organization"
     *     )
     * )
     */
    public function removeDelegate(Request $request, Response $response, array $args): Response
    {
        try {
            $organizationId = (int)$args['id'];
            $delegateId = (int)$args['delegate_id'];

            if (!$this->organizationService->hasAccess($organizationId)) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Not authorized to remove delegates from this organization'],
                    403
                );
            }

            $this->organizationService->deleteDelegate($delegateId);

            return $response->withStatus(204);

        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => $e->getMessage()],
                500
            );
        }
    }
}