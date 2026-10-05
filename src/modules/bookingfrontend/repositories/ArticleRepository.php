<?php
namespace App\modules\bookingfrontend\repositories;

use App\Database\Db;
use App\modules\bookingfrontend\helpers\PriceChoiceException;
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
        // Query the mapping and also join price information and tax percent
        // Priority: default price first, then most recent by date; only prices valid today or earlier
        $sql = "SELECT am.*, p.price, am.tax_code, e.percent_ AS tax_percent
                FROM bb_article_mapping am
                LEFT JOIN bb_article_price p ON p.article_mapping_id = am.id AND p.active = 1 AND p.from_ <= CURRENT_DATE
                LEFT JOIN fm_ecomva e ON am.tax_code = e.id
                WHERE am.id = :id
                ORDER BY p.default_ ASC, p.from_ DESC
                LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $mappingId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * The prices a citizen chooses between for a resource (article_cat 1): every
     * active row of the mapping whose from_ has been reached today. A future
     * from_ is not offered yet; superseded prices are deleted by the admin, so
     * the rows are alternatives, not a history.
     *
     * from_ is a naive local timestamp, so "today" is the Oslo date: the session
     * runs in UTC, where CURRENT_DATE is still yesterday until 02:00, and a row
     * dated later today would not count until tomorrow.
     *
     * The timeslot path runs the same query in its node port
     * (WebSocket/node/src/modules/booking/resource-price.ts); keep them identical.
     *
     * @return array Rows of id, price (ex. tax), remark and default_
     */
    public function getEligibleResourcePrices(int $mappingId): array
    {
        $sql = "SELECT id, price, remark, default_
                FROM bb_article_price
                WHERE article_mapping_id = :mapping_id
                AND active = 1
                AND from_ < (now() AT TIME ZONE 'Europe/Oslo')::date + 1
                ORDER BY price, id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':mapping_id' => $mappingId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The option a resource's price starts out as: the only one, or else the
     * default. Two or more default rows are no default at all. Null means the
     * citizen has to choose, or that there is nothing to choose between.
     */
    public static function preselectedPrice(array $options): ?array
    {
        if (count($options) === 1) {
            return $options[0];
        }

        $defaults = array_values(array_filter($options, fn($option) => (int)$option['default_'] === 1));

        return count($defaults) === 1 ? $defaults[0] : null;
    }

    /**
     * The label stored with the order line, so the case officer sees what the
     * citizen picked. Only a real choice gets one: the remark, or the amount when
     * the remark is empty. A single price was never chosen and gets none.
     */
    public static function priceLabel(array $option, int $optionCount): ?string
    {
        if ($optionCount < 2) {
            return null;
        }

        $remark = trim((string)($option['remark'] ?? ''));

        return $remark !== '' ? $remark : number_format((float)$option['price'], 2, '.', '');
    }

    /**
     * The price a resource line is billed at, given the citizen's choice
     * ($priceId null = none sent). A choice must be one of today's options; the
     * client's price is never used. Without a choice the preselected option
     * applies, and where there is none the choice is required.
     *
     * @return array|null The chosen price row plus option_count, or null when
     *                    the resource has no price (the line stays free, as before)
     * @throws PriceChoiceException
     */
    public function resolveResourcePrice(int $mappingId, ?int $priceId): ?array
    {
        $options = $this->getEligibleResourcePrices($mappingId);

        if ($priceId !== null) {
            foreach ($options as $option) {
                if ((int)$option['id'] === $priceId) {
                    return $option + ['option_count' => count($options)];
                }
            }
            throw new PriceChoiceException(PriceChoiceException::INVALID, $mappingId);
        }

        if (empty($options)) {
            return null;
        }

        $preselected = self::preselectedPrice($options);
        if ($preselected === null) {
            throw new PriceChoiceException(PriceChoiceException::REQUIRED, $mappingId);
        }

        return $preselected + ['option_count' => count($options)];
    }

    /**
     * Refuse checkout while a resource line still waits for the citizen's price.
     *
     * The timeslot path books before anyone can choose, so when the resource has
     * several prices and no default it leaves the resource line unpriced: no
     * price row and a unit price of 0. Such a line is only resolved by a choice,
     * also when the resource has gained a default since; it must not go through
     * as free.
     *
     * @param int[] $applicationIds
     * @throws PriceChoiceException
     */
    public function assertPriceChoicesMade(array $applicationIds): void
    {
        $applicationIds = array_map('intval', $applicationIds);
        if (empty($applicationIds)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($applicationIds), '?'));
        $sql = "SELECT DISTINCT pol.article_mapping_id
                FROM bb_purchase_order po
                JOIN bb_purchase_order_line pol ON po.id = pol.order_id
                JOIN bb_article_mapping am ON pol.article_mapping_id = am.id
                WHERE po.cancelled IS NULL
                AND po.application_id IN ({$placeholders})
                AND am.article_cat_id = 1
                AND pol.article_price_id IS NULL
                AND pol.unit_price = 0
                ORDER BY pol.article_mapping_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($applicationIds);

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $mappingId) {
            if (!empty($this->getEligibleResourcePrices((int)$mappingId))) {
                throw new PriceChoiceException(PriceChoiceException::REQUIRED, (int)$mappingId);
            }
        }
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
     * @param array $articles Array of ArticleOrder objects with id, quantity, parent_id
     *                        and, for the resource itself, the chosen price_id
     * @throws PriceChoiceException When a resource's price choice is missing or not offered
     */
    public function saveArticlesForApplication(int $applicationId, array $articles): void
    {
        try {
            // Price every line before anything is deleted, so a refused price
            // choice leaves the existing order as it was
            $lines = [];
            foreach ($articles as $article) {
                // Get article details from the mapping
                $mapping = $this->getArticleMappingById($article['id']);
                if (!$mapping) {
                    continue; // Skip if mapping not found
                }

                $priceId = null;
                if (isset($article['price_id'])) {
                    $priceId = filter_var($article['price_id'], FILTER_VALIDATE_INT);
                    if ($priceId === false) {
                        throw new PriceChoiceException(PriceChoiceException::INVALID, (int)$article['id']);
                    }
                }

                // Create the purchase order line
                $line = [
                    'article_mapping_id' => $article['id'],
                    'quantity' => $article['quantity'],
                    'parent_mapping_id' => $article['parent_id'] ?? null,
                    'ex_tax_price' => $mapping['price'] ?? 0, // Using price from mapping
                    'tax_code' => $mapping['tax_code'] ?? null,
                    'tax_percent' => (float)($mapping['tax_percent'] ?? 0),
                    'article_price_id' => null,
                    'price_label' => null
                ];

                if ((int)$mapping['article_cat_id'] === 1) {
                    // The resource itself: billed at the price row the citizen chose
                    $price = $this->resolveResourcePrice((int)$article['id'], $priceId);
                    $line['ex_tax_price'] = $price['price'] ?? 0;
                    $line['article_price_id'] = $price ? (int)$price['id'] : null;
                    $line['price_label'] = $price ? self::priceLabel($price, $price['option_count']) : null;
                } elseif ($priceId !== null) {
                    // Only the resource has a price choice; services keep their own price
                    throw new PriceChoiceException(PriceChoiceException::INVALID, (int)$article['id']);
                }

                $lines[] = $line;
            }

            // First delete existing purchase order lines for this application
            $this->deleteExistingPurchaseOrderLines($applicationId);

            // Create a new purchase order if it doesn't exist
            $purchase_order_id = $this->getOrCreatePurchaseOrder($applicationId);

            // Add each article as a purchase order line
            foreach ($lines as $line) {
                $this->savePurchaseOrderLine($purchase_order_id, $line);
            }
        } catch (PriceChoiceException $e) {
            throw $e;
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
            tax_code, unit_price, parent_mapping_id, amount, tax, currency,
            article_price_id, price_label
        ) VALUES (
            :order_id, :article_mapping_id, :quantity,
            :tax_code, :unit_price, :parent_mapping_id, :amount, :tax, :currency,
            :article_price_id, :price_label
        )";

        // Calculate the amount based on unit price and quantity
        $unitPrice = $line['ex_tax_price'] ?? 0;
        $quantity = $line['quantity'] ?? 0;
        $amount = $unitPrice * $quantity;

        // Calculate tax using the article's actual tax percent
        $taxPercent = $line['tax_percent'] ?? 0;
        $tax = $amount * ($taxPercent / 100);

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
            ':currency' => 'NOK', // Default currency
            ':article_price_id' => $line['article_price_id'] ?? null,
            ':price_label' => $line['price_label'] ?? null
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
            if (isset($articleData['resource_id'])) {
                // The resource itself: the citizen chooses between today's prices.
                // The article's own price is the preselected option, and 0 while
                // a choice is required.
                $options = $this->getEligibleResourcePrices((int)$articleData['id']);
                $preselected = self::preselectedPrice($options);
                $price = $preselected ? ['price' => $preselected['price'], 'remark' => $preselected['remark']] : false;

                $taxPercent = (float)$articleData['tax_percent'];
                $articleData['price_options'] = array_map(function ($option) use ($taxPercent) {
                    $exTaxPrice = (float)$option['price'];
                    return [
                        'price_id' => (int)$option['id'],
                        'remark' => $option['remark'] ?? '',
                        'ex_tax_price' => number_format($exTaxPrice, 2, '.', ''),
                        'tax' => number_format($exTaxPrice * ($taxPercent / 100), 2, '.', ''),
                        'price' => number_format($exTaxPrice * (1 + ($taxPercent / 100)), 2, '.', ''),
                        'is_default' => (int)$option['default_'] === 1
                    ];
                }, $options);
                $articleData['default_price_id'] = $preselected ? (int)$preselected['id'] : null;
                $articleData['price_choice_required'] = count($options) > 1 && $preselected === null;
            } else {
                // Get pricing info
                $sql = "SELECT price, remark FROM bb_article_price
                    WHERE article_mapping_id = ?
                    AND active = 1
                    AND from_ <= CURRENT_DATE
                    ORDER BY default_ ASC, from_ DESC";

                $stmt = $this->db->prepare($sql);
                $stmt->execute([$articleData['id']]);
                $price = $stmt->fetch(PDO::FETCH_ASSOC);
            }

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