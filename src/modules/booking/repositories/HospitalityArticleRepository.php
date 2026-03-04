<?php

namespace App\modules\booking\repositories;

use App\Database\Db;
use PDO;

class HospitalityArticleRepository
{
    private Db $db;

    public function __construct()
    {
        $this->db = Db::getInstance();
    }

    // -- Article Groups --

    public function getGroupsByHospitality(int $hospitalityId): array
    {
        $sql = "SELECT * FROM bb_hospitality_article_group
                WHERE hospitality_id = :hospitality_id
                ORDER BY sort_order, name";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':hospitality_id' => $hospitalityId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getGroupById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM bb_hospitality_article_group WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function createGroup(array $data): int
    {
        $sql = "INSERT INTO bb_hospitality_article_group
                (hospitality_id, name, sort_order, active)
                VALUES (:hospitality_id, :name, :sort_order, :active)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':hospitality_id' => $data['hospitality_id'],
            ':name' => $data['name'],
            ':sort_order' => $data['sort_order'] ?? 0,
            ':active' => $data['active'] ?? 1,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function updateGroup(int $id, array $data): bool
    {
        $updates = [];
        $params = [':id' => $id];
        $allowedFields = ['name', 'sort_order', 'active'];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updates[] = "{$field} = :{$field}";
                $params[":{$field}"] = $data[$field];
            }
        }

        if (empty($updates)) {
            return true;
        }

        $sql = "UPDATE bb_hospitality_article_group SET " . implode(', ', $updates) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function deleteGroup(int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE bb_hospitality_article_group SET active = 0 WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    // -- Articles --

    public function getArticlesByHospitality(int $hospitalityId, bool $activeOnly = false): array
    {
        $sql = "SELECT ha.*,
                       av.name AS article_name,
                       am.unit,
                       am.tax_code AS base_tax_code,
                       ap.price AS base_price
                FROM bb_hospitality_article ha
                JOIN bb_article_mapping am ON ha.article_mapping_id = am.id
                LEFT JOIN bb_article_view av ON am.article_id = av.id AND am.article_cat_id = av.article_cat_id
                LEFT JOIN bb_article_price ap ON am.id = ap.article_mapping_id
                    AND ap.active = 1 AND ap.default_ = 1
                WHERE ha.hospitality_id = :hospitality_id";
        if ($activeOnly) {
            $sql .= " AND ha.active = 1";
        }
        $sql .= " ORDER BY ha.sort_order, av.name";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':hospitality_id' => $hospitalityId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getArticlesByGroup(int $groupId): array
    {
        $sql = "SELECT ha.*,
                       av.name AS article_name,
                       am.unit,
                       am.tax_code AS base_tax_code,
                       ap.price AS base_price
                FROM bb_hospitality_article ha
                JOIN bb_article_mapping am ON ha.article_mapping_id = am.id
                LEFT JOIN bb_article_view av ON am.article_id = av.id AND am.article_cat_id = av.article_cat_id
                LEFT JOIN bb_article_price ap ON am.id = ap.article_mapping_id
                    AND ap.active = 1 AND ap.default_ = 1
                WHERE ha.article_group_id = :group_id
                ORDER BY ha.sort_order, av.name";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':group_id' => $groupId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getArticleById(int $id): ?array
    {
        $sql = "SELECT ha.*,
                       av.name AS article_name,
                       am.unit,
                       am.tax_code AS base_tax_code,
                       ap.price AS base_price
                FROM bb_hospitality_article ha
                JOIN bb_article_mapping am ON ha.article_mapping_id = am.id
                LEFT JOIN bb_article_view av ON am.article_id = av.id AND am.article_cat_id = av.article_cat_id
                LEFT JOIN bb_article_price ap ON am.id = ap.article_mapping_id
                    AND ap.active = 1 AND ap.default_ = 1
                WHERE ha.id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function createArticle(array $data): int
    {
        $sql = "INSERT INTO bb_hospitality_article
                (hospitality_id, article_group_id, article_mapping_id,
                 description, sort_order, active, override_price, override_tax_code)
                VALUES (:hospitality_id, :article_group_id, :article_mapping_id,
                        :description, :sort_order, :active, :override_price, :override_tax_code)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':hospitality_id' => $data['hospitality_id'],
            ':article_group_id' => $data['article_group_id'] ?? null,
            ':article_mapping_id' => $data['article_mapping_id'],
            ':description' => $data['description'] ?? null,
            ':sort_order' => $data['sort_order'] ?? 0,
            ':active' => $data['active'] ?? 1,
            ':override_price' => $data['override_price'] ?? null,
            ':override_tax_code' => $data['override_tax_code'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function updateArticle(int $id, array $data): bool
    {
        $updates = [];
        $params = [':id' => $id];
        $allowedFields = [
            'article_group_id', 'description', 'sort_order',
            'active', 'override_price', 'override_tax_code',
        ];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updates[] = "{$field} = :{$field}";
                $params[":{$field}"] = $data[$field];
            }
        }

        if (empty($updates)) {
            return true;
        }

        $sql = "UPDATE bb_hospitality_article SET " . implode(', ', $updates) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function deleteArticle(int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE bb_hospitality_article SET active = 0 WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Resolve the effective price and tax code for a hospitality article.
     * Returns override values if set, otherwise falls back to the base article system.
     */
    public function resolveEffectivePricing(int $articleId): ?array
    {
        $article = $this->getArticleById($articleId);
        if (!$article) {
            return null;
        }

        return [
            'effective_price' => $article['override_price'] ?? $article['base_price'],
            'effective_tax_code' => $article['override_tax_code'] ?? $article['base_tax_code'],
            'unit' => $article['unit'],
            'article_name' => $article['article_name'],
        ];
    }
}
