<?php
namespace App\modules\booking\repositories;

use App\Database\Db;
use App\modules\booking\models\Document;
use PDO;
use Exception;

class DocumentRepository
{
    private $db;
    private $owner_type;

    public function __construct(string $owner_type = Document::OWNER_BUILDING)
    {
        $this->db = Db::getInstance();
        $this->owner_type = $owner_type;
    }

    private function getDBTable(): string
    {
        return match ($this->owner_type) {
            Document::OWNER_BUILDING => 'bb_document_building',
            Document::OWNER_APPLICATION => 'bb_document_application',
            Document::OWNER_RESOURCE => 'bb_document_resource',
            Document::OWNER_ORGANIZATION => 'bb_document_organization',
            default => 'bb_document_building',
        };
    }

    public function getDocumentsForOwner(int $ownerId, array|null $categories = null): array
    {
        $table = $this->getDBTable();
        $sql = "SELECT * FROM {$table} WHERE owner_id = :buildingId";
        $params = [':buildingId' => $ownerId];

        if ($categories !== null && !empty($categories)) {
            $placeholders = [];
            foreach ($categories as $index => $category) {
                $key = ":category{$index}";
                $placeholders[] = $key;
                $params[$key] = $category;
            }
            $sql .= " AND category IN (" . implode(', ', $placeholders) . ")";
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function($row) {
            return new Document($row, $this->owner_type);
        }, $results);
    }

    public function getMainPicture(int $ownerId): ?Document
    {
        $table = $this->getDBTable();
        $sql = "SELECT * FROM {$table}
                WHERE owner_id = :ownerId
                AND category IN ('picture_main', 'picture')
                AND (name ~* '\.(jpg|jpeg|png|gif|bmp|webp)$')
                ORDER BY CASE WHEN category = 'picture_main' THEN 0 ELSE 1 END, id ASC
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':ownerId' => $ownerId]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result) {
            return null;
        }

        return new Document($result, $this->owner_type);
    }

    public function getDocumentById(int $documentId): ?Document
    {
        $table = $this->getDBTable();
        $sql = "SELECT * FROM {$table} WHERE id = :documentId";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':documentId' => $documentId]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result) {
            return null;
        }

        return new Document($result, $this->owner_type);
    }

    public function createDocument(array $data): int
    {
        $metadata = null;
        if (isset($data['metadata'])) {
            $metadata = is_string($data['metadata'])
                ? $data['metadata']
                : json_encode($data['metadata']);
        }

        $sql = "INSERT INTO bb_document_{$this->owner_type}
            (name, description, category, owner_id, metadata)
            VALUES (:name, :description, :category, :owner_id, :metadata)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':name' => $data['name'],
            ':description' => $data['description'],
            ':category' => $data['category'],
            ':owner_id' => $data['owner_id'],
            ':metadata' => $metadata
        ]);

        return $this->db->lastInsertId();
    }

    public function deleteDocument(int $documentId): void
    {
        $document = $this->getDocumentById($documentId);
        if (!$document) {
            throw new Exception('Document not found');
        }

        // Delete physical file
        $filepath = $document->generate_filename();
        if (file_exists($filepath)) {
            unlink($filepath);
        }

        // Delete database record
        $sql = "DELETE FROM bb_document_{$this->owner_type} WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $documentId]);
    }

    public function updateDocument(int $documentId, array $data): bool
    {
        $document = $this->getDocumentById($documentId);
        if (!$document) {
            throw new Exception('Document not found');
        }

        $updates = [];
        $params = [':id' => $documentId];

        if (isset($data['description'])) {
            $updates[] = 'description = :description';
            $params[':description'] = $data['description'];
        }

        if (isset($data['owner_id'])) {
            $updates[] = 'owner_id = :owner_id';
            $params[':owner_id'] = (int)$data['owner_id'];
        }

        if (isset($data['metadata'])) {
            $metadata = is_string($data['metadata'])
                ? $data['metadata']
                : json_encode($data['metadata']);
            $updates[] = 'metadata = :metadata';
            $params[':metadata'] = $metadata;
        }

        if (empty($updates)) {
            return true;
        }

        $sql = "UPDATE bb_document_{$this->owner_type}
                SET " . implode(', ', $updates) . "
                WHERE id = :id";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Get all documents with optional sorting and limit.
     * Joins to the owner table to include owner_name.
     */
    public function getAllDocuments(string $sort = 'name', string $dir = 'ASC', ?int $limit = null): array
    {
        $table = $this->getDBTable();
        $ownerTable = 'bb_' . $this->owner_type;

        $allowedSortColumns = ['id', 'name', 'owner_id', 'category', 'description', 'owner_name'];
        if (!in_array($sort, $allowedSortColumns, true)) {
            $sort = 'name';
        }
        $dir = strtoupper($dir) === 'DESC' ? 'DESC' : 'ASC';

        $sortColumn = $sort === 'owner_name'
            ? "o.name"
            : "d.{$sort}";

        $sql = "SELECT d.*, o.name AS owner_name
                FROM {$table} d
                LEFT JOIN {$ownerTable} o ON d.owner_id = o.id
                ORDER BY {$sortColumn} {$dir}";

        if ($limit !== null && $limit > 0) {
            $sql .= " LIMIT :limit";
        }

        $stmt = $this->db->prepare($sql);

        if ($limit !== null && $limit > 0) {
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        }

        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($row) {
            return new Document($row, $this->owner_type);
        }, $results);
    }

    public static function getDocumentByIdAnyOwner(int $documentId): ?Document
    {
        $db = Db::getInstance();
        $ownerTypes = [
            Document::OWNER_BUILDING,
            Document::OWNER_RESOURCE,
            Document::OWNER_APPLICATION,
            Document::OWNER_ORGANIZATION
        ];

        foreach ($ownerTypes as $ownerType) {
            $table = "bb_document_{$ownerType}";
            $sql = "SELECT * FROM {$table} WHERE id = :documentId";
            $stmt = $db->prepare($sql);
            $stmt->execute([':documentId' => $documentId]);

            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result) {
                return new Document($result, $ownerType);
            }
        }

        return null;
    }

}
