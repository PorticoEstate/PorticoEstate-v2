<?php

namespace App\modules\booking\controllers;

use App\modules\booking\authorization\DocumentBuildingAuthConfig;
use App\modules\booking\authorization\DocumentOrganizationAuthConfig;
use App\modules\booking\authorization\DocumentResourceAuthConfig;
use App\modules\booking\authorization\EntityAuthConfig;
use App\modules\booking\models\Document;
use App\modules\booking\repositories\PermissionRepository;
use App\modules\booking\services\AuthorizationService;
use App\modules\booking\services\DocumentService;
use App\modules\bookingfrontend\helpers\ResponseHelper;
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

    public function categories(Request $request, Response $response): Response
    {
        $categories = array_map(function (string $cat) {
            return [
                'value' => $cat,
                'label' => lang(str_replace('_', ' ', $cat)),
            ];
        }, Document::getCategories());

        $response->getBody()->write(json_encode($categories));
        return $response->withHeader('Content-Type', 'application/json');
    }

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

            $response->getBody()->write(json_encode($serializedDocuments));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'Error fetching documents: ' . $e->getMessage()],
                500
            );
        }
    }

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

            $response->getBody()->write(json_encode($document->serialize()));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'Error fetching document: ' . $e->getMessage()],
                500
            );
        }
    }

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
            $response->getBody()->write(json_encode($updatedDocument->serialize()));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => $e->getMessage()],
                400
            );
        }
    }

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
