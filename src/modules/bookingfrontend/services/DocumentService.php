<?php

namespace App\modules\bookingfrontend\services;

use App\modules\bookingfrontend\repositories\DocumentRepository;
use App\modules\bookingfrontend\models\Document;
class DocumentService
{
    private $documentRepository;

    public function __construct(string $owner_type = Document::OWNER_BUILDING)
    {
        $this->documentRepository = new DocumentRepository($owner_type);
    }

    /**
     * @return Document[]
     */
    public function getImagesForBuilding(int $buildingId): array
    {
        $imageCategories = [Document::CATEGORY_PICTURE, Document::CATEGORY_PICTURE_MAIN];
        return $this->documentRepository->getDocumentsForBuilding($buildingId, $imageCategories);
    }

    /**
     * @return Document[]
     */
    public function getDocumentsForBuilding(int $buildingId, ?array $categories = null): array
    {
        return $this->documentRepository->getDocumentsForBuilding($buildingId, $categories);
    }

    /**
     * @return Document[]
     */
    public function getDocumentsByCategory(int $buildingId, string $category): array
    {
        return $this->documentRepository->getDocumentsForBuilding($buildingId, [$category]);
    }


    /**
     * @return ?Document
     */
    public function getDocumentById(int $documentId): ?Document
    {
        return $this->documentRepository->getDocumentById($documentId);
    }
}
