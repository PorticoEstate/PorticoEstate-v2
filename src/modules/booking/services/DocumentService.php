<?php

namespace App\modules\booking\services;

use App\Database\Db;
use App\modules\booking\repositories\DocumentRepository;
use App\modules\booking\models\Document;
use Psr\Http\Message\UploadedFileInterface;
use PDO;
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
     * Get main picture for a specific owner
     * Returns picture_main if exists, otherwise first picture, otherwise null
     */
    public function getMainPicture(int $ownerId): ?Document
    {
        return $this->documentRepository->getMainPicture($ownerId);
    }

    /**
     * Get documents by category for a specific owner
     */
    public function getDocumentsByCategory(int $ownerId, string $category): array
    {
        return $this->documentRepository->getDocumentsForOwner($ownerId, [$category]);
    }

    /**
     * Get all documents with optional sorting and limit
     */
    public function getAllDocuments(string $sort = 'name', string $dir = 'ASC', ?int $limit = null): array
    {
        return $this->documentRepository->getAllDocuments($sort, $dir, $limit);
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

        $id = $this->documentRepository->createDocument($data);

        if (isset($data['owner_id'])) {
            $this->invalidateDocumentCache((int)$data['owner_id']);
        }

        return $id;
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
        $document = $this->getDocumentById($documentId);
        $ownerId = $document?->owner_id;

        $this->documentRepository->deleteDocument($documentId);

        if ($ownerId) {
            $this->invalidateDocumentCache($ownerId);
        }
    }

    /**
     * Update document metadata (description, focal point, rotation, etc.)
     */
    public function updateDocument(int $documentId, array $data): bool
    {
        $document = $this->getDocumentById($documentId);
        if (!$document) {
            throw new Exception('Document not found');
        }

        // Validate owner_id change if requested (only for building documents)
        if (isset($data['owner_id']) && $this->ownerType === Document::OWNER_BUILDING) {
            $newOwnerId = (int)$data['owner_id'];
            if ($newOwnerId !== $document->owner_id) {
                $this->validateBuildingExists($newOwnerId);
            }
        }

        $metadata = $document->metadata ?? [];
        $metadataUpdated = false;

        // Handle focal point update
        if (isset($data['focal_point_x']) || isset($data['focal_point_y'])) {
            $focalX = $data['focal_point_x'] ?? null;
            $focalY = $data['focal_point_y'] ?? null;

            $this->validateFocalPoint($focalX, $focalY);

            if ($focalX !== null && $focalY !== null) {
                $metadata['focal_point'] = [
                    'x' => (float)$focalX,
                    'y' => (float)$focalY
                ];
            } else {
                unset($metadata['focal_point']);
            }

            $metadataUpdated = true;
            unset($data['focal_point_x'], $data['focal_point_y']);
        }

        // Handle persistent rotation
        if (isset($data['rotation']) && $data['rotation'] !== '') {
            $newRotation = (int)$data['rotation'];
            $previousRotation = (int)($metadata['rotation'] ?? 0);
            $rotationToApply = ($newRotation - $previousRotation + 360) % 360;

            if ($rotationToApply !== 0) {
                $filePath = $document->generate_filename();
                if (file_exists($filePath)) {
                    $this->physicallyRotateImage($filePath, $rotationToApply);
                }
            }

            $metadata['rotation'] = $newRotation;
            $metadataUpdated = true;
            unset($data['rotation']);
        }

        if ($metadataUpdated) {
            $data['metadata'] = $metadata;
        }

        $result = $this->documentRepository->updateDocument($documentId, $data);

        $this->invalidateDocumentCache($document->owner_id);

        // If owner changed, also invalidate the new owner's cache
        if (isset($data['owner_id']) && (int)$data['owner_id'] !== $document->owner_id) {
            $this->invalidateDocumentCache((int)$data['owner_id']);
        }

        return $result;
    }

    /**
     * Invalidate Next.js caches for document changes based on owner type.
     */
    private function invalidateDocumentCache(int $ownerId): void
    {
        if (!class_exists('\App\modules\bookingfrontend\services\CacheService')) {
            return;
        }

        $cache = new \App\modules\bookingfrontend\services\CacheService();

        match ($this->ownerType) {
            Document::OWNER_BUILDING => $cache->invalidateBuildingDocuments($ownerId),
            Document::OWNER_RESOURCE => $cache->invalidateResourceDocuments($ownerId),
            default => null,
        };
    }

    private function validateBuildingExists(int $buildingId): void
    {
        $db = Db::getInstance();
        $stmt = $db->prepare("SELECT id FROM bb_building WHERE id = :id AND active = 1");
        $stmt->execute([':id' => $buildingId]);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            throw new Exception('Building not found or inactive');
        }
    }

    /**
     * Physically rotate an image file on disk using GD.
     */
    private function physicallyRotateImage(string $filePath, int $degrees): bool
    {
        if (!in_array($degrees, [90, 180, 270])) {
            return false;
        }

        if (!extension_loaded('gd')) {
            return false;
        }

        $imageInfo = getimagesize($filePath);
        if (!$imageInfo) {
            return false;
        }

        $mime = $imageInfo['mime'];
        $source = match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($filePath),
            'image/png' => imagecreatefrompng($filePath),
            'image/gif' => imagecreatefromgif($filePath),
            'image/webp' => imagecreatefromwebp($filePath),
            default => null,
        };

        if (!$source) {
            return false;
        }

        $rotated = imagerotate($source, -$degrees, 0);
        imagedestroy($source);

        if (!$rotated) {
            return false;
        }

        $success = match ($mime) {
            'image/jpeg' => imagejpeg($rotated, $filePath, 90),
            'image/png' => (function () use ($rotated, $filePath) {
                imagesavealpha($rotated, true);
                return imagepng($rotated, $filePath);
            })(),
            'image/gif' => imagegif($rotated, $filePath),
            'image/webp' => imagewebp($rotated, $filePath, 90),
            default => false,
        };

        imagedestroy($rotated);

        return $success;
    }

    /**
     * Create a temporary rotated copy of an image for preview/download.
     * Returns the temp file path, or the original path if rotation failed.
     */
    public function rotateImageTemp(string $sourceFile, int $degrees): string
    {
        if (!in_array($degrees, [90, 180, 270])) {
            return $sourceFile;
        }

        if (!extension_loaded('gd')) {
            return $sourceFile;
        }

        $imageInfo = getimagesize($sourceFile);
        if (!$imageInfo) {
            return $sourceFile;
        }

        $mime = $imageInfo['mime'];
        $source = match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($sourceFile),
            'image/png' => imagecreatefrompng($sourceFile),
            'image/gif' => imagecreatefromgif($sourceFile),
            'image/webp' => imagecreatefromwebp($sourceFile),
            default => null,
        };

        if (!$source) {
            return $sourceFile;
        }

        $rotated = imagerotate($source, -$degrees, 0);
        imagedestroy($source);

        if (!$rotated) {
            return $sourceFile;
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'rotated_');

        $success = match ($mime) {
            'image/jpeg' => imagejpeg($rotated, $tempFile, 90),
            'image/png' => (function () use ($rotated, $tempFile) {
                imagesavealpha($rotated, true);
                return imagepng($rotated, $tempFile);
            })(),
            'image/gif' => imagegif($rotated, $tempFile),
            'image/webp' => imagewebp($rotated, $tempFile, 90),
            default => false,
        };

        imagedestroy($rotated);

        return $success ? $tempFile : $sourceFile;
    }

}
