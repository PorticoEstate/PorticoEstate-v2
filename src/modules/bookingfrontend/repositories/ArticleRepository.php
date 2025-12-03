<?php
namespace App\modules\bookingfrontend\repositories;

use App\Database\Db;
use App\modules\bookingfrontend\models\Article;
use PDO;

class ArticleRepository
{
    private $db;
    private $currentapp = 'bookingfrontend';

    public function __construct()
    {
        $this->db = Db::getInstance();
    }

    /**
     * Get article mapping by ID
     *
     * @param int $mappingId The mapping ID
     * @return array|null The article mapping or null if not found
     */
    public function getArticleMappingById(int $mappingId): ?array
    {
        // Query the mapping and also join price information
        $sql = "SELECT am.*, p.price, am.tax_code
                FROM bb_article_mapping am
                LEFT JOIN bb_article_price p ON p.article_mapping_id = am.id
                WHERE am.id = :id
                ORDER BY p.from_ DESC
                LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $mappingId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Fetch articles for an application in ArticleOrder format
     *
     * @param int $application_id The application ID
     * @return array Array of articles in ArticleOrder format
     */
    public function fetchArticlesForApplication(int $application_id): array
    {
        $sql = "SELECT pol.article_mapping_id as id, pol.quantity, pol.parent_mapping_id as parent_id,
                CASE WHEN r.name IS NULL THEN s.name ELSE r.name END AS name,
                am.unit, am.article_cat_id, am.article_id, pol.unit_price,
                pol.tax_code, e.percent_ AS tax_percent
                FROM bb_purchase_order po
                JOIN bb_purchase_order_line pol ON po.id = pol.order_id
                JOIN bb_article_mapping am ON pol.article_mapping_id = am.id
                LEFT JOIN fm_ecomva e ON pol.tax_code = e.id
                LEFT JOIN bb_service s ON (am.article_id = s.id AND am.article_cat_id = 2)
                LEFT JOIN bb_resource r ON (am.article_id = r.id AND am.article_cat_id = 1)
                WHERE po.cancelled IS NULL AND po.application_id = :application_id
                ORDER BY pol.id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':application_id' => $application_id]);
        $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Convert result rows to ArticleOrder format
        $articles = [];
        foreach ($results as $row) {
            $articles[] = [
                'id' => (int)$row['id'],
                'quantity' => (int)$row['quantity'],
                'parent_id' => !empty($row['parent_id']) ? (int)$row['parent_id'] : null
            ];
        }

        return $articles;
    }

    /**
     * Save articles for an application using the new ArticleOrder format
     *
     * @param int $applicationId The application ID
     * @param array $articles Array of ArticleOrder objects with id, quantity, and parent_id
     */
    public function saveArticlesForApplication(int $applicationId, array $articles): void
    {
        try {
            // First delete existing purchase order lines for this application
            $this->deleteExistingPurchaseOrderLines($applicationId);

            // Create a new purchase order if it doesn't exist
            $purchase_order_id = $this->getOrCreatePurchaseOrder($applicationId);

            // Add each article as a purchase order line
            foreach ($articles as $article) {
                // Get article details from the mapping
                $mapping = $this->getArticleMappingById($article['id']);
                if (!$mapping) {
                    continue; // Skip if mapping not found
                }

                // Create the purchase order line
                $line = [
                    'article_mapping_id' => $article['id'],
                    'quantity' => $article['quantity'],
                    'parent_mapping_id' => $article['parent_id'] ?? null,
                    'ex_tax_price' => $mapping['price'] ?? 0, // Using price from mapping
                    'tax_code' => $mapping['tax_code'] ?? null
                ];

                $this->savePurchaseOrderLine($purchase_order_id, $line);
            }
        } catch (\Exception $e) {
            throw new \Exception("Error saving application articles: " . $e->getMessage());
        }
    }

    /**
     * Delete existing purchase order lines for an application
     *
     * @param int $applicationId The application ID
     */
    private function deleteExistingPurchaseOrderLines(int $applicationId): void
    {
        // First get the purchase order ID
        $sql = "SELECT id FROM bb_purchase_order WHERE application_id = :application_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['application_id' => $applicationId]);
        $purchase_order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$purchase_order) {
            return; // No purchase order exists
        }

        // Delete the lines - using the correct column name 'order_id' instead of 'purchase_order_id'
        $sql = "DELETE FROM bb_purchase_order_line WHERE order_id = :order_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['order_id' => $purchase_order['id']]);
    }

    /**
     * Get existing purchase order or create a new one
     *
     * @param int $applicationId The application ID
     * @return int The purchase order ID
     */
    private function getOrCreatePurchaseOrder(int $applicationId): int
    {
        // Check if purchase order exists
        $sql = "SELECT id FROM bb_purchase_order WHERE application_id = :application_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['application_id' => $applicationId]);
        $purchase_order = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($purchase_order) {
            return (int)$purchase_order['id'];
        }

        // Create a new purchase order
        $sql = "INSERT INTO bb_purchase_order (application_id) VALUES (:application_id)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['application_id' => $applicationId]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Save purchase order line
     */
    private function savePurchaseOrderLine(int $orderId, array $line): void
    {
        $sql = "INSERT INTO bb_purchase_order_line (
            order_id, article_mapping_id, quantity,
            tax_code, unit_price, parent_mapping_id, amount, tax, currency
        ) VALUES (
            :order_id, :article_mapping_id, :quantity,
            :tax_code, :unit_price, :parent_mapping_id, :amount, :tax, :currency
        )";

        // Calculate the amount based on unit price and quantity
        $unitPrice = $line['ex_tax_price'] ?? 0;
        $quantity = $line['quantity'] ?? 0;
        $amount = $unitPrice * $quantity;

        // Get tax rate information (assuming 25% if not specified)
        $taxRate = 0.25; // Default tax rate
        if (!empty($line['tax_code'])) {
            // Could look up actual tax rate here if needed
        }

        // Calculate tax amount
        $tax = $amount * $taxRate;

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':order_id' => $orderId,
            ':article_mapping_id' => $line['article_mapping_id'],
            ':quantity' => $line['quantity'],
            ':tax_code' => $line['tax_code'],
            ':unit_price' => $unitPrice,
            ':parent_mapping_id' => $line['parent_mapping_id'] ?? null,
            ':amount' => $amount,
            ':tax' => $tax,
            ':currency' => 'NOK' // Default currency
        ]);
    }

    /**
     * Get articles by resources without requiring an application
     */
    public function getArticlesByResources(array $resourceIds): array
    {
        // If no resources provided, return empty array
        if (empty($resourceIds)) {
            return [];
        }

        // Convert resource IDs to integers
        $resourceIds = array_map('intval', $resourceIds);
        $resourcePlaceholders = implode(',', array_fill(0, count($resourceIds), '?'));

        $articlesData = [];

        // First, get the primary resource articles
        $sql = "SELECT bb_article_mapping.id AS mapping_id,
            bb_article_mapping.article_cat_id || '_' || bb_article_mapping.article_id AS article_id,
            bb_resource.name as name,
            bb_article_mapping.article_id AS resource_id,
            bb_article_mapping.unit,
            fm_ecomva.percent_ AS tax_percent,
            bb_article_mapping.tax_code,
            bb_article_mapping.group_id,
            bb_article_group.name AS article_group_name,
            bb_article_group.remark AS article_group_remark
            FROM bb_article_mapping
            JOIN bb_resource ON (bb_article_mapping.article_id = bb_resource.id)
            JOIN fm_ecomva ON (bb_article_mapping.tax_code = fm_ecomva.id)
            JOIN bb_article_group ON (bb_article_mapping.group_id = bb_article_group.id)
            WHERE bb_article_mapping.article_cat_id = 1
            AND bb_resource.active = 1
            AND bb_article_mapping.article_id IN ({$resourcePlaceholders})
            ORDER BY bb_resource.name";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($resourceIds);
        $resourceArticles = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Now process each resource and get associated services
        foreach ($resourceArticles as $resourceArticle) {
            // Add the resource article first
            $articleData = [
                'id' => $resourceArticle['mapping_id'],
                'parent_mapping_id' => null,
                'resource_id' => $resourceArticle['resource_id'],
                'article_id' => $resourceArticle['article_id'],
                'name' => $resourceArticle['name'],
                'unit' => $resourceArticle['unit'],
                'tax_code' => $resourceArticle['tax_code'],
                'tax_percent' => (float)($resourceArticle['tax_percent'] ?? 0),
                'group_id' => (int)$resourceArticle['group_id'],
                'article_remark' => '',
                'article_group_name' => $resourceArticle['article_group_name'],
                'article_group_remark' => $resourceArticle['article_group_remark']
            ];

            $articlesData[] = $articleData;

            // Get related service articles
            $resourceId = $resourceArticle['resource_id'];
            $sql = "SELECT bb_article_mapping.id AS mapping_id,
                bb_article_mapping.article_cat_id || '_' || bb_article_mapping.article_id AS article_id,
                bb_service.name as name,
                bb_service.description as article_remark,
                bb_resource_service.resource_id,
                bb_article_mapping.unit,
                fm_ecomva.percent_ AS tax_percent,
                bb_article_mapping.tax_code,
                bb_article_mapping.group_id,
                bb_article_group.name AS article_group_name,
                bb_article_group.remark AS article_group_remark
                FROM bb_article_mapping
                JOIN bb_service ON (bb_article_mapping.article_id = bb_service.id)
                JOIN bb_resource_service ON (bb_service.id = bb_resource_service.service_id)
                JOIN fm_ecomva ON (bb_article_mapping.tax_code = fm_ecomva.id)
                JOIN bb_article_group ON (bb_article_mapping.group_id = bb_article_group.id)
                WHERE bb_article_mapping.article_cat_id = 2
                AND bb_resource_service.resource_id = ?";

            // Add frontend filter if needed
            if ($this->currentapp == 'bookingfrontend') {
                $sql .= ' AND deactivate_in_frontend IS NULL';
            }

            $sql .= " ORDER BY bb_resource_service.resource_id, bb_service.name";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$resourceId]);
            $serviceArticles = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Add service articles for this resource
            foreach ($serviceArticles as $serviceArticle) {
                $articleData = [
                    'id' => $serviceArticle['mapping_id'],
                    'parent_mapping_id' => $resourceArticle['mapping_id'],
                    'article_id' => $serviceArticle['article_id'],
                    'name' => "- " . $serviceArticle['name'],
                    'unit' => $serviceArticle['unit'],
                    'tax_code' => $serviceArticle['tax_code'],
                    'tax_percent' => (float)($serviceArticle['tax_percent'] ?? 0),
                    'group_id' => (int)$serviceArticle['group_id'],
                    'article_remark' => $serviceArticle['article_remark'],
                    'article_group_name' => $serviceArticle['article_group_name'],
                    'article_group_remark' => $serviceArticle['article_group_remark']
                ];

                $articlesData[] = $articleData;
            }
        }

        // Create Article objects and add pricing info
        $articles = [];
        foreach ($articlesData as $articleData) {
            // Get pricing info
            $sql = "SELECT price, remark FROM bb_article_price
                WHERE article_mapping_id = ?
                AND active = 1
                ORDER BY default_ ASC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$articleData['id']]);
            $price = $stmt->fetch(PDO::FETCH_ASSOC);

            // Add price data to article data
            $articleData['ex_tax_price'] = (float)($price['price'] ?? 0);
            $articleData['tax'] = $articleData['ex_tax_price'] * ($articleData['tax_percent'] / 100);
            $articleData['price'] = $articleData['ex_tax_price'] * (1 + ($articleData['tax_percent'] / 100));
            $articleData['price_remark'] = $price['remark'] ?? '';

            // Format for frontend
            $articleData['unit_price'] = (float)$articleData['price'];
            $articleData['selected_quantity'] = 0;
            $articleData['selected_sum'] = 0;

            // Format numeric values
            $articleData['ex_tax_price'] = number_format($articleData['ex_tax_price'], 2, '.', '');
            $articleData['unit_price'] = number_format($articleData['unit_price'], 2, '.', '');
            $articleData['price'] = number_format($articleData['price'], 2, '.', '');
            $articleData['tax'] = number_format($articleData['tax'], 2, '.', '');

            // Set defaults for resource items
            $articleData['mandatory'] = isset($articleData['resource_id']) ? 1 : '';
            $articleData['lang_unit'] = $articleData['unit'];

            if (empty($articleData['selected_quantity'])) {
                $articleData['selected_quantity'] = isset($articleData['resource_id']) ? 1 : '';
            }

            if (empty($articleData['selected_article_quantity'])) {
                $parentId = $articleData['parent_mapping_id'] ?? 'null';
                $articleData['selected_article_quantity'] = isset($articleData['resource_id'])
                    ? "{$articleData['id']}_1_{$articleData['tax_code']}_{$articleData['ex_tax_price']}_{$parentId}"
                    : '';
            }

            if (empty($articleData['selected_sum'])) {
                $articleData['selected_sum'] = isset($articleData['resource_id']) ? $articleData['price'] : '';
            }

            // Create Article object from data
            $article = new Article($articleData);
            $articles[] = $article;
        }

        // Return serialized articles
        return array_map(function($article) {
            return $article->serialize();
        }, $articles);
    }
}