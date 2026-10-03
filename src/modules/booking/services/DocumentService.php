<?php

namespace App\modules\booking\services;

use App\Database\Db;
use App\modules\booking\repositories\DocumentRepository;
use App\modules\booking\models\Document;
use Psr\Http\Message\UploadedFileInterface;
use PDO;
use Exception;
use Throwable;

class DocumentService
{
    /** File types an uploaded document may have (by its last extension). */
    public const UPLOAD_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'xls', 'xlsx', 'doc', 'docx', 'txt', 'pdf', 'odt', 'ods'];
    public const UPLOAD_MAX_BYTES = 20 * 1024 * 1024;
    /** The file is stored as "{id}_{name}", which must fit a 255-byte file name. */
    public const UPLOAD_NAME_MAX_BYTES = 200;

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

    /**
     * The name an uploaded file is stored under, or null when the client's name
     * cannot be used. Invalid UTF-8, control characters and '..' are refused, not
     * repaired. Otherwise only the base name is kept, and any character outside
     * letters, digits, spaces and . _ - ( ) + , & ' becomes '_'.
     */
    public function uploadFileName(UploadedFileInterface $file): ?string
    {
        $name = (string)$file->getClientFilename();
        if (!mb_check_encoding($name, 'UTF-8') || preg_match('/\p{Cc}/u', $name) || str_contains($name, '..')) {
            return null;
        }

        // PHP already strips directories from $_FILES names; this does not rely on it.
        $name = substr(strrchr('/' . str_replace('\\', '/', $name), '/'), 1);
        $name = trim(preg_replace("/[^\\p{L}\\p{M}\\p{N} ._\\-()+,&']/u", '_', $name));

        if ($name === '' || $name === '.' || strlen($name) > self::UPLOAD_NAME_MAX_BYTES) {
            return null;
        }
        return $name;
    }

    /**
     * Why an uploaded file may not be stored, as a translated message, or null
     * when it may.
     */
    public function validateUpload(UploadedFileInterface $file): ?string
    {
        $tooLarge = lang('booking.attachment_too_large', self::UPLOAD_MAX_BYTES / (1024 * 1024));

        switch ($file->getError()) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_NO_FILE:
                return lang('booking.Missing file for document');
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return $tooLarge;
            default:
                return lang('booking.attachment_upload_failed');
        }

        $size = $file->getSize();
        if ($size === null) {
            return lang('booking.attachment_upload_failed');
        }
        if ($size === 0) {
            return lang('booking.attachment_empty');
        }
        if ($size > self::UPLOAD_MAX_BYTES) {
            return $tooLarge;
        }

        $name = $this->uploadFileName($file);
        if ($name === null) {
            return lang('booking.attachment_invalid_name');
        }

        $dot = strrpos($name, '.');
        $extension = $dot === false ? '' : strtolower(substr($name, $dot + 1));
        if (!in_array($extension, self::UPLOAD_EXTENSIONS, true)) {
            return lang('booking.attachment_invalid_type', implode(', ', self::UPLOAD_EXTENSIONS));
        }

        return null;
    }

    /**
     * Store every uploaded file as a document (category 'other') of $ownerId, or
     * none of them: all files are validated before anything is written, and a
     * failed write takes back what this call already stored.
     *
     * @param UploadedFileInterface[] $files
     * @param array $fields description, focal_point_x and focal_point_y; anything else is ignored
     * @return array ['ids' => int[]] when stored, ['errors' => string[]] when refused
     */
    public function storeUploads(int $ownerId, array $files, array $fields = []): array
    {
        $errors = [];
        foreach ($files as $file) {
            $error = $this->validateUpload($file);
            if ($error !== null) {
                $label = $this->uploadLabel($file);
                $errors[] = $label === '' ? $error : $label . ': ' . $error;
            }
        }
        if ($errors) {
            return ['errors' => $errors];
        }

        $ids = [];
        try {
            foreach ($files as $file) {
                $ids[] = $this->storeUpload($ownerId, $file, $fields);
            }
        } catch (Throwable $e) {
            foreach ($ids as $id) {
                $this->discardDocument($id);
            }
            throw $e;
        }

        return ['ids' => $ids];
    }

    private function storeUpload(int $ownerId, UploadedFileInterface $file, array $fields): int
    {
        $name = $this->uploadFileName($file);
        $document = [
            'category' => Document::CATEGORY_OTHER,
            'owner_id' => $ownerId,
            'name' => $name,
            'description' => $fields['description'] ?? $name,
        ];
        if (isset($fields['focal_point_x'], $fields['focal_point_y'])) {
            $document['focal_point_x'] = $fields['focal_point_x'];
            $document['focal_point_y'] = $fields['focal_point_y'];
        }

        $id = $this->createDocument($document);
        try {
            $this->saveDocumentFile($id, $file);
        } catch (Throwable $e) {
            // The row must not outlive a file that never arrived.
            $this->discardDocument($id);
            throw $e;
        }

        return $id;
    }

    /**
     * Remove a document this service just stored. A failure here is logged, so
     * it does not hide the error that made the removal necessary.
     */
    private function discardDocument(int $documentId): void
    {
        try {
            $this->documentRepository->deleteDocument($documentId);
        } catch (Throwable $e) {
            error_log("DocumentService: could not remove document {$documentId}: " . $e->getMessage());
        }
    }

    /**
     * The client's file name, made safe to echo back in a message.
     */
    private function uploadLabel(UploadedFileInterface $file): string
    {
        $name = mb_convert_encoding((string)$file->getClientFilename(), 'UTF-8', 'UTF-8');
        $name = preg_replace('/\p{Cc}/u', '', $name) ?? '';
        return mb_strimwidth($name, 0, 80, '…', 'UTF-8');
    }

    private function saveDocumentFile(int $documentId, UploadedFileInterface $file): void
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

        return $success ? $tempFile : $sourceFile;
    }

}
