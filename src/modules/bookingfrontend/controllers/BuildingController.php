<?php

namespace App\modules\bookingfrontend\controllers;

use App\modules\bookingfrontend\models\Building;
use App\modules\bookingfrontend\models\Document;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\modules\bookingfrontend\services\DocumentService;
use Exception;
use App\modules\phpgwapi\services\Settings;
use App\Database\Db;
use OpenApi\Annotations as OA;
use Slim\Psr7\Stream;

/**
 * @OA\Tag(
 *     name="Buildings",
 *     description="API Endpoints for Buildings"
 * )
 */
class BuildingController
{
    private $db;
    private $userSettings;
    private $documentService;
    public function __construct(ContainerInterface $container)
    {
        $this->db = Db::getInstance();
        $this->userSettings = Settings::getInstance()->get('user');
        $this->documentService = new DocumentService(Document::OWNER_BUILDING);
    }

    private function getUserRoles()
    {
        return $this->userSettings['groups'] ?? [];
    }

    /**
     * @OA\Get(
     *     path="/bookingfrontend/buildings",
     *     summary="Get a list of all buildings",
     *     tags={"Buildings"},
     *     @OA\Response(
     *         response=200,
     *         description="A list of buildings",
     *         @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/Building"))
     *     )
     * )
     */
    public function index(Request $request, Response $response): Response
    {
        $maxMatches = isset($this->userSettings['preferences']['common']['maxmatchs']) ? (int)$this->userSettings['preferences']['common']['maxmatchs'] : 15;
        $queryParams = $request->getQueryParams();
        $start = isset($queryParams['start']) ? (int)$queryParams['start'] : 0;
        $perPage = isset($queryParams['results']) ? (int)$queryParams['results'] : $maxMatches;

        $sql = "SELECT * FROM bb_building ORDER BY id";
        if ($perPage > 0)
        {
            $sql .= " LIMIT :limit OFFSET :start";
        }

        try
        {
            $stmt = $this->db->prepare($sql);
            if ($perPage > 0)
            {
                $stmt->bindParam(':limit', $perPage, \PDO::PARAM_INT);
                $stmt->bindParam(':start', $start, \PDO::PARAM_INT);
            }
            $stmt->execute();
            $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $buildings = array_map(function ($data)
            {
                $building = new Building($data);
                return $building->serialize($this->getUserRoles());
            }, $results);

            $response->getBody()->write(json_encode($buildings));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (Exception $e)
        {
            $error = "Error fetching buildings: " . $e->getMessage();
            $response->getBody()->write(json_encode(['error' => $error]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }


    /**
     * @OA\Get(
     *     path="/bookingfrontend/buildings/{id}",
     *     summary="Get a specific building by ID",
     *     tags={"Buildings"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID of the building to fetch",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Building details",
     *         @OA\JsonContent(ref="#/components/schemas/Building")
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Building not found"
     *     )
     * )
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $buildingId = $args['id'];

        try
        {
            $sql = "SELECT * FROM bb_building WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->bindParam(':id', $buildingId, \PDO::PARAM_INT);
            $stmt->execute();

            $result = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$result)
            {
                $response->getBody()->write(json_encode(['error' => 'Building not found']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }

            $building = new Building($result);
            $response->getBody()->write(json_encode($building->serialize($this->getUserRoles())));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (Exception $e)
        {
            $error = "Error fetching building: " . $e->getMessage();
            $response->getBody()->write(json_encode(['error' => $error]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }


    /**
     * @OA\Get(
     *     path="/bookingfrontend/buildings/{id}/documents",
     *     summary="Get documents for a specific building",
     *     tags={"Buildings"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID of the building",
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
     *         description="List of building documents",
     *         @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/Document"))
     *     )
     * )
     */
    public function getDocuments(Request $request, Response $response, array $args): Response
    {
        $buildingId = (int)$args['id'];
        $typeParam = $request->getQueryParams()['type'] ?? null;

        try {
            $types = $this->parseDocumentTypes($typeParam);
            $documents = $this->documentService->getDocumentsForBuilding($buildingId, $types);

            $serializedDocuments = array_map(function($document) {
                return $document->serialize();
            }, $documents);

            $response->getBody()->write(json_encode($serializedDocuments));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            $error = "Error fetching building documents: " . $e->getMessage();
            $response->getBody()->write(json_encode(['error' => $error]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    private function parseDocumentTypes(?string $typeParam): ?array
    {
        if ($typeParam === null) {
            return null; // Return all document types
        }

        $types = explode(',', $typeParam);
        $validTypes = [];

        foreach ($types as $type) {
            if ($type === 'images') {
                $validTypes[] = Document::CATEGORY_PICTURE;
                $validTypes[] = Document::CATEGORY_PICTURE_MAIN;
            } elseif (in_array($type, Document::getCategories())) {
                $validTypes[] = $type;
            }
        }

        return !empty($validTypes) ? array_unique($validTypes) : null;
    }



    /**
     * @OA\Get(
     *     path="/bookingfrontend/buildings/documents/{id}/download",
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

            // Assuming the document's physical path is stored in the 'file_path' property
            $filePath = $document->generate_filename();

            if (!file_exists($filePath)) {
                $response->getBody()->write(json_encode(['error' => 'Document file not found']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }

            $fileType = $document->getFileTypeFromExtension();

            $latin1FileName = mb_convert_encoding($document->name, 'ISO-8859-1', 'UTF-8');
            $utf8FileName = rawurlencode($document->name);

            // Determine if the file should be displayed inline
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
}