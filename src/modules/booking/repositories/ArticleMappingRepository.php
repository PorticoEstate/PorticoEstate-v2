<?php

namespace App\modules\booking\repositories;

use App\Database\Db;
use PDO;

class ArticleMappingRepository
{
    private Db $db;

    public function __construct()
    {
        $this->db = Db::getInstance();
    }

    public function serviceNameExists(string $name): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM bb_service WHERE name = :name LIMIT 1");
        $stmt->execute([':name' => $name]);
        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function articleCodeExists(string $code, int $catId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM bb_article_mapping WHERE article_code = :code AND article_cat_id = :cat_id LIMIT 1"
        );
        $stmt->execute([':code' => $code, ':cat_id' => $catId]);
        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function createService(array $data): int
    {
        $sql = "INSERT INTO bb_service (name, description, active, owner_id)
                VALUES (:name, :description, :active, :owner_id)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':name' => $data['name'],
            ':description' => $data['description'] ?? null,
            ':active' => $data['active'] ?? 1,
            ':owner_id' => $data['owner_id'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function createMapping(array $data): int
    {
        $sql = "INSERT INTO bb_article_mapping
                (article_cat_id, article_id, article_code, unit, tax_code, group_id, owner_id)
                VALUES (:article_cat_id, :article_id, :article_code, :unit, :tax_code, :group_id, :owner_id)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':article_cat_id' => $data['article_cat_id'],
            ':article_id' => $data['article_id'],
            ':article_code' => $data['article_code'],
            ':unit' => $data['unit'],
            ':tax_code' => $data['tax_code'],
            ':group_id' => $data['group_id'] ?? 1,
            ':owner_id' => $data['owner_id'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function createDefaultPrice(int $mappingId, float $price): int
    {
        $sql = "INSERT INTO bb_article_price (article_mapping_id, price, from_, active, default_)
                VALUES (:mapping_id, :price, NOW(), 1, 1)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':mapping_id' => $mappingId,
            ':price' => $price,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function getMappingById(int $id): ?array
    {
        $sql = "SELECT am.*,
                       av.name AS article_name,
                       ac.name AS article_cat_name,
                       ag.name AS article_group,
                       ev.descr AS tax_code_name,
                       ap.price AS default_price
                FROM bb_article_mapping am
                LEFT JOIN bb_article_view av ON am.article_id = av.id AND am.article_cat_id = av.article_cat_id
                LEFT JOIN bb_article_category ac ON am.article_cat_id = ac.id
                LEFT JOIN bb_article_group ag ON am.group_id = ag.id
                LEFT JOIN fm_ecomva ev ON am.tax_code = ev.id
                LEFT JOIN bb_article_price ap ON am.id = ap.article_mapping_id
                    AND ap.active = 1 AND ap.default_ = 1
                WHERE am.id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
