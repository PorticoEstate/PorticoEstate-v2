<?php

namespace App\modules\bookingfrontend\services;

use App\modules\bookingfrontend\repositories\DocumentRepository;
use App\modules\bookingfrontend\models\Document;
use Psr\Http\Message\UploadedFileInterface;
use Exception;

class DocumentService
{
    private $documentRepository;
    private $ownerType;

    public function __construct(string $owner_type = Document::OWNER_BUILDING)
    {
        $this->ownerType = $owner_type;
        $this->documentRepository = new DocumentRepository($owner_type);
    }

    /**
     * Parse and validate document types from query parameter
     */
    public function parseDocumentTypes(?string $typeParam): ?array
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
     * Get images for a specific owner
     */
    public function getImagesForId(int $ownerId): array
    {
        $imageCategories = [Document::CATEGORY_PICTURE, Document::CATEGORY_PICTURE_MAIN];
        return $this->documentRepository->getDocumentsForOwner($ownerId, $imageCategories);
    }

    /**
     * Get documents for a specific owner
     */
    public function getDocumentsForId(int $ownerId, array|null $categories = null): array
    {
        return $this->documentRepository->getDocumentsForOwner($ownerId, $categories);
    }

    /**
     * Get documents by category for a specific owner
     */
    public function getDocumentsByCategory(int $ownerId, string $category): array
    {
        return $this->documentRepository->getDocumentsForOwner($ownerId, [$category]);
    }

    /**
     * Get a specific document by ID
     */
    public function getDocumentById(int $documentId): ?Document
    {
        return $this->documentRepository->getDocumentById($documentId);
    }

    /**
     * Get the owner type for this service instance
     */
    public function getOwnerType(): string
    {
        return $this->ownerType;
    }

    /**
     * Validate focal point coordinates
     * @throws Exception if validation fails
     */
    public function validateFocalPoint(?float $x, ?float $y): void
    {
        if ($x === null && $y === null) {
            return;
        }

        if ($x === null || $y === null) {
            throw new Exception('Both focal_point_x and focal_point_y must be provided together');
        }

        if ($x < 0 || $x > 100) {
            throw new Exception('focal_point_x must be between 0 and 100');
        }

        if ($y < 0 || $y > 100) {
            throw new Exception('focal_point_y must be between 0 and 100');
        }
    }


    /**
     * Create a new document
     */
    public function createDocument(array $data): int
    {
        // Validate focal point if provided
        $focalX = $data['focal_point_x'] ?? null;
        $focalY = $data['focal_point_y'] ?? null;

        if ($focalX !== null || $focalY !== null) {
            $this->validateFocalPoint($focalX, $focalY);

            $metadata = $data['metadata'] ?? [];
            $metadata['focal_point'] = [
                'x' => (float)$focalX,
                'y' => (float)$focalY
            ];
            $data['metadata'] = $metadata;
        }

        return $this->documentRepository->createDocument($data);
    }



    public function saveDocumentFile(int $documentId, UploadedFileInterface $file): void
    {
        $document = $this->getDocumentById($documentId);
        if (!$document) {
            throw new Exception('Document not found');
        }

        $targetPath = $document->generate_filename();
        
        // Ensure the directory exists
        $directory = dirname($targetPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        
        $file->moveTo($targetPath);
    }

    public function deleteDocument(int $documentId): void
    {
        $this->documentRepository->deleteDocument($documentId);
    }

    /**
     * Update document metadata (description, focal point, etc.)
     */
    public function updateDocument(int $documentId, array $data): bool
    {
        // Handle focal point update
        if (isset($data['focal_point_x']) || isset($data['focal_point_y'])) {
            $focalX = $data['focal_point_x'] ?? null;
            $focalY = $data['focal_point_y'] ?? null;

            $this->validateFocalPoint($focalX, $focalY);

            // Get existing document to preserve other metadata
            $document = $this->getDocumentById($documentId);
            if (!$document) {
                throw new Exception('Document not found');
            }

            $metadata = $document->metadata ?? [];

            if ($focalX !== null && $focalY !== null) {
                $metadata['focal_point'] = [
                    'x' => (float)$focalX,
                    'y' => (float)$focalY
                ];
            } else {
                unset($metadata['focal_point']);
            }

            $data['metadata'] = $metadata;
            unset($data['focal_point_x'], $data['focal_point_y']);
        }

        return $this->documentRepository->updateDocument($documentId, $data);
    }

}