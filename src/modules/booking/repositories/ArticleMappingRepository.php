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

    public function createService(array $data): int
    {
        $nameJson = $data['name_json'] ?? null;
        if (is_array($nameJson)) {
            $nameJson = json_encode($nameJson);
        }

        $sql = "INSERT INTO bb_service (name, name_json, description, active, owner_id)
                VALUES (:name, :name_json, :description, :active, :owner_id)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':name' => $data['name'],
            ':name_json' => $nameJson,
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

    public function getServiceById(int $id): ?array
    {
        $sql = "SELECT id, name, name_json, description, active FROM bb_service WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function updateService(int $id, array $data): bool
    {
        $updates = [];
        $params = [':id' => $id];
        $allowedFields = ['name', 'name_json'];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $value = $data[$field];
                if ($field === 'name_json' && is_array($value)) {
                    $value = json_encode($value);
                }
                $updates[] = "{$field} = :{$field}";
                $params[":{$field}"] = $value;
            }
        }

        if (empty($updates)) {
            return true;
        }

        $sql = "UPDATE bb_service SET " . implode(', ', $updates) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function updateMapping(int $id, array $data): bool
    {
        $updates = [];
        $params = [':id' => $id];
        $allowedFields = ['article_code', 'unit', 'tax_code'];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updates[] = "{$field} = :{$field}";
                $params[":{$field}"] = $data[$field];
            }
        }

        if (empty($updates)) {
            return true;
        }

        $sql = "UPDATE bb_article_mapping SET " . implode(', ', $updates) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function updateDefaultPrice(int $mappingId, float $price): bool
    {
        // Try to update existing active default price row
        $sql = "UPDATE bb_article_price SET price = :price
                WHERE article_mapping_id = :mapping_id AND active = 1 AND default_ = 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':price' => $price, ':mapping_id' => $mappingId]);

        if ($stmt->rowCount() === 0) {
            // No existing row — create one
            $this->createDefaultPrice($mappingId, $price);
        }

        return true;
    }

    public function serviceNameExists(string $name, ?int $excludeId = null): bool
    {
        $sql = "SELECT 1 FROM bb_service WHERE name = :name";
        $params = [':name' => $name];
        if ($excludeId !== null) {
            $sql .= " AND id != :exclude_id";
            $params[':exclude_id'] = $excludeId;
        }
        $sql .= " LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function articleCodeExists(string $code, int $catId, ?int $excludeMappingId = null): bool
    {
        $sql = "SELECT 1 FROM bb_article_mapping WHERE article_code = :code AND article_cat_id = :cat_id";
        $params = [':code' => $code, ':cat_id' => $catId];
        if ($excludeMappingId !== null) {
            $sql .= " AND id != :exclude_id";
            $params[':exclude_id'] = $excludeMappingId;
        }
        $sql .= " LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getMappingById(int $id): ?array
    {
        $sql = "SELECT am.*,
                       av.name AS article_name,
                       s.name_json AS service_name_json,
                       ac.name AS article_cat_name,
                       ag.name AS article_group,
                       ev.descr AS tax_code_name,
                       ap.price AS default_price
                FROM bb_article_mapping am
                LEFT JOIN bb_service s ON am.article_id = s.id AND am.article_cat_id = 2
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
