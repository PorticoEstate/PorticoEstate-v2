<?php

namespace App\modules\bookingfrontend\controllers;

use App\modules\bookingfrontend\models\Document;
use App\modules\bookingfrontend\services\DocumentService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;
use Exception;

/**
 * Base controller for handling document-related operations
 */
class DocumentController
{
    protected DocumentService $documentService;

    public function __construct(string $ownerType = Document::OWNER_BUILDING)
    {
        $this->documentService = new DocumentService($ownerType);
    }

    /**
     * @OA\Get(
     *     path="/bookingfrontend/{ownertype="buildings"|"resources"}/{id}/documents",
     *     summary="Get documents for a specific owner",
     *     tags={"Buildings", "Resources"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID of the owner",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="type",
     *         in="query",
     *         description="Type of documents to retrieve. Can be 'images' for all image types, or specific document categories. Multiple types can be comma-separated.",
     *         required=false,
     *         @OA\Schema(
     *             type="string",
     *             example="images,regulation,price_list"
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="List of documents",
     *         @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/Document"))
     *     )
     * )
     */
    public function getDocuments(Request $request, Response $response, array $args): Response
    {
        $ownerId = (int)$args['id'];
        $typeParam = $request->getQueryParams()['type'] ?? null;

        try {
            $types = $this->documentService->parseDocumentTypes($typeParam);
            $documents = $this->documentService->getDocumentsForId($ownerId, $types);

            $serializedDocuments = array_map(function($document) {
                return $document->serialize();
            }, $documents);

            $response->getBody()->write(json_encode($serializedDocuments));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            $error = "Error fetching documents: " . $e->getMessage();
            $response->getBody()->write(json_encode(['error' => $error]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * @OA\Get(
     *     path="/bookingfrontend/documents/{id}/download",
     *     summary="Download a specific document",
     *     tags={"Buildings"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID of the document to download",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Document file",
     *         @OA\Header(
     *             header="Content-Type",
     *             description="MIME type of the document",
     *             @OA\Schema(type="string")
     *         ),
     *         @OA\Header(
     *             header="Content-Disposition",
     *             description="Attachment with filename",
     *             @OA\Schema(type="string")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Document not found"
     *     )
     * )
     */
    public function downloadDocument(Request $request, Response $response, array $args): Response
    {
        $documentId = (int)$args['id'];

        try {
            $document = $this->documentService->getDocumentById($documentId);

            if (!$document) {
                $response->getBody()->write(json_encode(['error' => 'Document not found']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }

            $filePath = $document->generate_filename();

            if (!file_exists($filePath)) {
                $response->getBody()->write(json_encode(['error' => 'Document file not found']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }

            $fileType = $document->getFileTypeFromExtension();

            $latin1FileName = mb_convert_encoding($document->name, 'ISO-8859-1', 'UTF-8');
            $utf8FileName = rawurlencode($document->name);

            $isDisplayable = Document::isDisplayableFileType($fileType);
            $disposition = $isDisplayable ? 'inline' : 'attachment';

            $response = $response
                ->withHeader('Content-Type', $fileType)
                ->withHeader('Content-Disposition', "{$disposition}; filename={$latin1FileName}")
                ->withHeader('Cache-Control', 'must-revalidate, post-check=0, pre-check=0')
                ->withHeader('Pragma', 'cache');

            $stream = fopen($filePath, 'r');
            return $response->withBody(new Stream($stream));

        } catch (Exception $e) {
            $error = "Error downloading document: " . $e->getMessage();
            $response->getBody()->write(json_encode(['error' => $error]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

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

}
