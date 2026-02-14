<?php

namespace App\modules\booking\controllers;

use App\modules\booking\authorization\DocumentBuildingAuthConfig;
use App\modules\booking\authorization\DocumentOrganizationAuthConfig;
use App\modules\booking\authorization\DocumentResourceAuthConfig;
use App\modules\booking\authorization\EntityAuthConfig;
use App\modules\booking\models\Document;
use App\modules\booking\repositories\PermissionRepository;
use App\modules\phpgwapi\services\AuthorizationService;
use App\modules\booking\services\DocumentService;
use App\helpers\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;
use Exception;

class DocumentController
{
    protected DocumentService $documentService;
    protected AuthorizationService $authService;
    protected EntityAuthConfig $authConfig;

    public function __construct(string $ownerType = Document::OWNER_BUILDING)
    {
        $this->documentService = new DocumentService($ownerType);
        $this->authService = new AuthorizationService(new PermissionRepository());
        $this->authConfig = match ($ownerType) {
            Document::OWNER_RESOURCE => new DocumentResourceAuthConfig(),
            Document::OWNER_ORGANIZATION => new DocumentOrganizationAuthConfig(),
            default => new DocumentBuildingAuthConfig(),
        };
    }

    /**
     * @OA\Get(
     *     path="/booking/buildings/documents/categories",
     *     summary="Get available document categories",
     *     tags={"Building Documents"},
     *     @OA\Response(
     *         response=200,
     *         description="List of document categories",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(
     *                 @OA\Property(property="value", type="string", description="Category identifier"),
     *                 @OA\Property(property="label", type="string", description="Translated category label")
     *             )
     *         )
     *     )
     * )
     */
    public function categories(Request $request, Response $response): Response
    {
        $categories = array_map(function (string $cat) {
            return [
                'value' => $cat,
                'label' => lang(str_replace('_', ' ', $cat)),
            ];
        }, Document::getCategories());

        return ResponseHelper::sendJSONResponse($categories, 200, $response);
    }

    /**
     * @OA\Get(
     *     path="/booking/buildings/documents",
     *     summary="List all building documents",
     *     tags={"Building Documents"},
     *     @OA\Parameter(
     *         name="sort",
     *         in="query",
     *         description="Sort column (id, name, owner_id, category, description, owner_name)",
     *         @OA\Schema(type="string", default="name")
     *     ),
     *     @OA\Parameter(
     *         name="dir",
     *         in="query",
     *         description="Sort direction",
     *         @OA\Schema(type="string", enum={"ASC", "DESC"}, default="ASC")
     *     ),
     *     @OA\Parameter(
     *         name="length",
     *         in="query",
     *         description="Maximum number of results to return. Omit or use -1 for all.",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="List of documents",
     *         @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/Document"))
     *     ),
     *     @OA\Response(response=403, description="Permission denied")
     * )
     */
    public function listAll(Request $request, Response $response): Response
    {
        if ($this->authService->authorize($this->authConfig, 'read') === false) {
            return ResponseHelper::sendErrorResponse(['error' => 'Permission denied'], 403);
        }

        $params = $request->getQueryParams();
        $sort = $params['sort'] ?? 'name';
        $dir = $params['dir'] ?? 'ASC';
        $length = isset($params['length']) ? (int)$params['length'] : null;

        if ($length === -1) {
            $length = null;
        }

        try {
            $documents = $this->documentService->getAllDocuments($sort, $dir, $length);
            $serialized = array_map(fn($doc) => $doc->serialize(), $documents);

            return ResponseHelper::sendJSONResponse($serialized, 200, $response);
        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'Error fetching documents: ' . $e->getMessage()],
                500
            );
        }
    }

    /**
     * @OA\Get(
     *     path="/booking/buildings/{ownerId}/documents",
     *     summary="List documents for a specific building",
     *     tags={"Building Documents"},
     *     @OA\Parameter(
     *         name="ownerId",
     *         in="path",
     *         required=true,
     *         description="Building ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="type",
     *         in="query",
     *         description="Filter by category type (comma-separated). Use 'images' for picture + picture_main.",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="List of documents for the building",
     *         @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/Document"))
     *     ),
     *     @OA\Response(response=403, description="Permission denied")
     * )
     */
    public function index(Request $request, Response $response, array $args): Response
    {
        if ($this->authService->authorize($this->authConfig, 'read') === false) {
            return ResponseHelper::sendErrorResponse(['error' => 'Permission denied'], 403);
        }

        $ownerId = (int)$args['ownerId'];
        $typeParam = $request->getQueryParams()['type'] ?? null;

        try {
            $types = $this->documentService->parseDocumentTypes($typeParam);
            $documents = $this->documentService->getDocumentsForId($ownerId, $types);

            $serializedDocuments = array_map(fn($doc) => $doc->serialize(), $documents);

            return ResponseHelper::sendJSONResponse($serializedDocuments, 200, $response);
        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'Error fetching documents: ' . $e->getMessage()],
                500
            );
        }
    }

    /**
     * @OA\Get(
     *     path="/booking/buildings/{ownerId}/documents/{id}",
     *     summary="Get a single document",
     *     tags={"Building Documents"},
     *     @OA\Parameter(name="ownerId", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="Document details",
     *         @OA\JsonContent(ref="#/components/schemas/Document")
     *     ),
     *     @OA\Response(response=403, description="Permission denied"),
     *     @OA\Response(response=404, description="Document not found")
     * )
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        if ($this->authService->authorize($this->authConfig, 'read') === false) {
            return ResponseHelper::sendErrorResponse(['error' => 'Permission denied'], 403);
        }

        $ownerId = (int)$args['ownerId'];
        $documentId = (int)$args['id'];

        try {
            $document = $this->documentService->getDocumentById($documentId);

            if (!$document || $document->owner_id !== $ownerId) {
                return ResponseHelper::sendErrorResponse(['error' => 'Document not found'], 404);
            }

            return ResponseHelper::sendJSONResponse($document->serialize(), 200, $response);
        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'Error fetching document: ' . $e->getMessage()],
                500
            );
        }
    }

    /**
     * @OA\Patch(
     *     path="/booking/buildings/{ownerId}/documents/{id}",
     *     summary="Update a document",
     *     tags={"Building Documents"},
     *     @OA\Parameter(name="ownerId", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="description", type="string"),
     *             @OA\Property(property="category", type="string"),
     *             @OA\Property(property="focal_point_x", type="number"),
     *             @OA\Property(property="focal_point_y", type="number"),
     *             @OA\Property(property="rotation", type="integer", enum={0, 90, 180, 270})
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Updated document",
     *         @OA\JsonContent(ref="#/components/schemas/Document")
     *     ),
     *     @OA\Response(response=400, description="Validation error"),
     *     @OA\Response(response=403, description="Permission denied"),
     *     @OA\Response(response=404, description="Document not found")
     * )
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $ownerId = (int)$args['ownerId'];
        $documentId = (int)$args['id'];

        try {
            $document = $this->documentService->getDocumentById($documentId);

            if (!$document || $document->owner_id !== $ownerId) {
                return ResponseHelper::sendErrorResponse(['error' => 'Document not found'], 404);
            }

            $grant = $this->authService->authorize($this->authConfig, 'write', [
                'id' => $documentId,
                'owner_id' => $ownerId,
            ]);

            if ($grant === false) {
                return ResponseHelper::sendErrorResponse(['error' => 'Permission denied'], 403);
            }

            $parsedBody = $request->getParsedBody()
                ?? json_decode($request->getBody()->getContents(), true)
                ?? [];

            // Filter update data through granted fields
            if (is_array($grant)) {
                $updateData = array_intersect_key($parsedBody, $grant);
            } else {
                // Full access — still apply the general field whitelist
                $allowedFields = $this->authConfig->getAllFields();
                $updateData = array_intersect_key($parsedBody, array_flip($allowedFields));
            }

            if (empty($updateData)) {
                return ResponseHelper::sendErrorResponse(['error' => 'No valid fields to update'], 400);
            }

            $this->documentService->updateDocument($documentId, $updateData);

            $updatedDocument = $this->documentService->getDocumentById($documentId);
            return ResponseHelper::sendJSONResponse($updatedDocument->serialize(), 200, $response);
        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => $e->getMessage()],
                400
            );
        }
    }

    /**
     * @OA\Delete(
     *     path="/booking/buildings/{ownerId}/documents/{id}",
     *     summary="Delete a document",
     *     tags={"Building Documents"},
     *     @OA\Parameter(name="ownerId", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=204, description="Document deleted"),
     *     @OA\Response(response=403, description="Permission denied"),
     *     @OA\Response(response=404, description="Document not found")
     * )
     */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $ownerId = (int)$args['ownerId'];
        $documentId = (int)$args['id'];

        try {
            $document = $this->documentService->getDocumentById($documentId);

            if (!$document || $document->owner_id !== $ownerId) {
                return ResponseHelper::sendErrorResponse(['error' => 'Document not found'], 404);
            }

            if ($this->authService->authorize($this->authConfig, 'delete', [
                'id' => $documentId,
                'owner_id' => $ownerId,
            ]) === false) {
                return ResponseHelper::sendErrorResponse(['error' => 'Permission denied'], 403);
            }

            $this->documentService->deleteDocument($documentId);

            return $response->withStatus(204);
        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'Error deleting document: ' . $e->getMessage()],
                500
            );
        }
    }

    /**
     * @OA\Get(
     *     path="/booking/buildings/documents/{id}/download",
     *     summary="Download a document file",
     *     tags={"Building Documents"},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(
     *         name="rotation",
     *         in="query",
     *         description="Rotation to apply for image preview (0, 90, 180, 270)",
     *         @OA\Schema(type="integer", enum={0, 90, 180, 270})
     *     ),
     *     @OA\Response(response=200, description="File content"),
     *     @OA\Response(response=403, description="Permission denied"),
     *     @OA\Response(response=404, description="Document or file not found")
     * )
     */
    public function downloadDocument(Request $request, Response $response, array $args): Response
    {
        if ($this->authService->authorize($this->authConfig, 'read') === false) {
            return ResponseHelper::sendErrorResponse(['error' => 'Permission denied'], 403);
        }

        $documentId = (int)$args['id'];
        $rotation = (int)($request->getQueryParams()['rotation'] ?? 0);

        try {
            $document = $this->documentService->getDocumentById($documentId);

            if (!$document) {
                return ResponseHelper::sendErrorResponse(['error' => 'Document not found'], 404);
            }

            $filePath = $document->generate_filename();

            if (!file_exists($filePath)) {
                return ResponseHelper::sendErrorResponse(['error' => 'Document file not found'], 404);
            }

            $isRotated = false;
            if ($rotation !== 0) {
                $fileType = $document->getFileTypeFromExtension();
                if (str_starts_with($fileType, 'image/')) {
                    $rotatedPath = $this->documentService->rotateImageTemp($filePath, $rotation);
                    if ($rotatedPath !== $filePath) {
                        $filePath = $rotatedPath;
                        $isRotated = true;
                    }
                }
            }

            $fileType = $document->getFileTypeFromExtension();
            $latin1FileName = mb_convert_encoding($document->name, 'ISO-8859-1', 'UTF-8');
            $isDisplayable = Document::isDisplayableFileType($fileType);
            $disposition = $isDisplayable ? 'inline' : 'attachment';

            $response = $response
                ->withHeader('Content-Type', $fileType)
                ->withHeader('Content-Disposition', "{$disposition}; filename={$latin1FileName}")
                ->withHeader('Cache-Control', 'must-revalidate, post-check=0, pre-check=0')
                ->withHeader('Pragma', 'cache');

            $stream = fopen($filePath, 'r');
            $response = $response->withBody(new Stream($stream));

            if ($isRotated && file_exists($filePath)) {
                @unlink($filePath);
            }

            return $response;
        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'Error downloading document: ' . $e->getMessage()],
                500
            );
        }
    }
}
