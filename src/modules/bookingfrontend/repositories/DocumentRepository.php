<?php
namespace App\modules\bookingfrontend\repositories;

use App\Database\Db;
use App\modules\bookingfrontend\models\Document;
use PDO;

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
        $sql = "INSERT INTO bb_document_{$this->owner_type}
            (name, description, category, owner_id)
            VALUES (:name, :description, :category, :owner_id)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':name' => $data['name'],
            ':description' => $data['description'],
            ':category' => $data['category'],
            ':owner_id' => $data['owner_id']
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

}
