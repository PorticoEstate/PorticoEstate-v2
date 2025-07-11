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
     * Create a new document
     */
    public function createDocument(array $data): int
    {
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

}