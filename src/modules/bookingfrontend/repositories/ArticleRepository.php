<?php
namespace App\modules\bookingfrontend\repositories;

use App\Database\Db;
use App\modules\booking\repositories\PriceCategoryRepository;
use App\modules\bookingfrontend\helpers\PriceChoiceException;
use App\modules\bookingfrontend\models\Article;
use App\modules\bookingfrontend\models\BookingfrontendConfig;
use App\modules\phpgwapi\services\Config;
use PDO;

class ArticleRepository
{
    private $db;
    private $currentapp = 'bookingfrontend';
    private ?bool $hidesPriceChoice = null;

    public function __construct()
    {
        $this->db = Db::getInstance();
    }

    /**
     * Whether the citizen chooses no price because the price category is hidden
     * (bookingfrontend config hide_price_category, ref #1477). A resource is then
     * priced as on origin/master, by getArticleMappingById like every other line, and
     * a price_id the client sends is not read.
     *
     * For a resource with several prices, no default and the newest from_ shared, that
     * query, as on master, orders the rows no further, so which of them is billed
     * depends on the database's row order (operator's choice, forum/pe-general/42463).
     *
     * Read as the client reads it: through BookingfrontendConfig (@ParseBool), and
     * hidden only when that gives true. The timeslot path reads the same value
     * (WebSocket/node/src/modules/booking/resource-price.ts hidesPriceChoice).
     * Read once per repository, so that one request prices all its lines alike.
     */
    public function hidesPriceChoice(): bool
    {
        if ($this->hidesPriceChoice === null) {
            $config = new BookingfrontendConfig((new Config('bookingfrontend'))->read());
            $this->hidesPriceChoice = ($config->serialize()['hide_price_category'] ?? null) === true;
        }

        return $this->hidesPriceChoice;
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
     * Each row carries its price category (ref #1477), null without one or
     * before the migration that adds categories has run.
     *
     * @return array Rows of id, price (ex. tax), remark, default_, category_id and category_name
     */
    public function getEligibleResourcePrices(int $mappingId): array
    {
        $category = PriceCategoryRepository::isInstalled()
            ? "p.category_id, c.name AS category_name
                FROM bb_article_price p
                LEFT JOIN bb_price_category c ON c.id = p.category_id"
            : "NULL AS category_id, NULL AS category_name
                FROM bb_article_price p";

        $sql = "SELECT p.id, p.price, p.remark, p.default_, {$category}
                WHERE p.article_mapping_id = :mapping_id
                AND p.active = 1
                AND p.from_ < (now() AT TIME ZONE 'Europe/Oslo')::date + 1
                ORDER BY p.price, p.id";
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
     * What a price is called where the citizen sees it: its category's name
     * when it has one, otherwise the remark as the admin wrote it (ref #1477).
     */
    public static function optionLabel(array $option): string
    {
        $categoryName = trim((string)($option['category_name'] ?? ''));

        return $categoryName !== '' ? $categoryName : trim((string)($option['remark'] ?? ''));
    }

    /**
     * The label stored with the order line, so the case officer sees what the
     * citizen picked. Only a real choice gets one: the option's label (its
     * category, or else its remark), or the amount when that is empty. A single
     * price was never chosen and gets none.
     */
    public static function priceLabel(array $option, int $optionCount): ?string
    {
        if ($optionCount < 2) {
            return null;
        }

        $label = self::optionLabel($option);

        return $label !== '' ? $label : number_format((float)$option['price'], 2, '.', '');
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
     * Before checkout, the resource lines still waiting for the citizen's price.
     * While the price category is shown they are refused (assertPriceChoicesMade).
     * While it is hidden there is nothing to choose: each such line, booked through
     * a time slot while it was shown, gets the price a line booked while hidden gets
     * (ref #1477). Only those lines: a price the citizen chose is kept.
     *
     * @param int[] $applicationIds
     * @return int The number of lines given a price other than 0 kr; always 0 while the category is shown
     * @throws PriceChoiceException
     */
    public function settlePendingPriceChoices(array $applicationIds): int
    {
        if (!$this->hidesPriceChoice()) {
            $this->assertPriceChoicesMade($applicationIds);
            return 0;
        }

        return count($this->priceLinesPendingWhileHidden($applicationIds));
    }

    /**
     * With the price category hidden, prices the resource lines still waiting for a
     * price, as settlePendingPriceChoices does. While it is shown nothing is read or
     * written; the assert refuses those lines at submit.
     *
     * A line master's query prices at 0 kr is written too but not returned: what will
     * be billed did not change, so there is nothing new for the applicant to see.
     *
     * @param int[] $applicationIds
     * @return array<int, int> The application id of each line given a price other than 0 kr, by line id
     */
    public function priceLinesPendingWhileHidden(array $applicationIds): array
    {
        if (!$this->hidesPriceChoice()) {
            return [];
        }

        $applicationIds = array_map('intval', $applicationIds);
        if (empty($applicationIds)) {
            return [];
        }

        // The lines assertPriceChoicesMade refuses; keep the two selections the same
        $placeholders = implode(',', array_fill(0, count($applicationIds), '?'));
        $sql = "SELECT pol.id, pol.article_mapping_id, pol.quantity, po.application_id
                FROM bb_purchase_order po
                JOIN bb_purchase_order_line pol ON po.id = pol.order_id
                JOIN bb_article_mapping am ON pol.article_mapping_id = am.id
                WHERE po.cancelled IS NULL
                AND po.application_id IN ({$placeholders})
                AND am.article_cat_id = 1
                AND pol.article_price_id IS NULL
                AND pol.unit_price = 0
                ORDER BY pol.id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($applicationIds);

        $update = $this->db->prepare(
            "UPDATE bb_purchase_order_line
             SET tax_code = :tax_code, unit_price = :unit_price, amount = :amount, tax = :tax
             WHERE id = :id"
        );

        $priced = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $pending) {
            $mappingId = (int)$pending['article_mapping_id'];
            if (empty($this->getEligibleResourcePrices($mappingId))) {
                continue; // without a price the line was never waiting for one
            }

            // As saveArticlesForApplication and savePurchaseOrderLine price a line while hidden
            $mapping = $this->getArticleMappingById($mappingId);
            $unitPrice = $mapping['price'] ?? 0;
            $amount = $unitPrice * $pending['quantity'];
            $tax = $amount * ((float)($mapping['tax_percent'] ?? 0) / 100);

            $update->execute([
                ':tax_code' => $mapping['tax_code'] ?? null,
                ':unit_price' => $unitPrice,
                ':amount' => $amount,
                ':tax' => $tax,
                ':id' => (int)$pending['id']
            ]);
            if ((float)$amount != 0.0) {
                $priced[(int)$pending['id']] = (int)$pending['application_id'];
            }
        }

        return $priced;
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
            // The resources the application is booked on right now. Callers save an
            // application's resources BEFORE its articles, in the same transaction
            $resourceIds = $this->applicationResourceIds($applicationId);
            $serviceIds = $this->servicesOfApplicationResources($applicationId);
            $hidden = $this->hidesPriceChoice();

            // Price every line before anything is deleted, so a refused price
            // choice leaves the existing order as it was
            $lines = [];
            foreach ($articles as $article) {
                // Get article details from the mapping
                $mapping = $this->getArticleMappingById($article['id']);
                if (!$mapping) {
                    continue; // Skip if mapping not found
                }

                // A line of a resource the application is not booked on is one the
                // applicant cannot see or pay for in the form: it is dropped, not stored
                $parentId = isset($article['parent_id']) ? (int)$article['parent_id'] : null;
                if (self::isForeignLine($mapping, $parentId, $resourceIds, $serviceIds)) {
                    $this->logForeignLine($applicationId, $mapping, $parentId, null, 'dropped on save');
                    continue;
                }

                // While the price category is hidden there is no choice, and what the client sends is not read
                $priceId = null;
                if (isset($article['price_id']) && !$hidden) {
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
                    'price_label' => null,
                    'category_id' => null
                ];

                // With the price category hidden the resource keeps the mapping's price above,
                // which is what origin/master bills, and as nothing was chosen no price row,
                // label or category is recorded
                if ((int)$mapping['article_cat_id'] === 1 && !$hidden) {
                    // The resource itself: billed at the price row the citizen chose.
                    // Its category is the row's, never one the client sends
                    $price = $this->resolveResourcePrice((int)$article['id'], $priceId);
                    $line['ex_tax_price'] = $price['price'] ?? 0;
                    $line['article_price_id'] = $price ? (int)$price['id'] : null;
                    $line['price_label'] = $price ? self::priceLabel($price, $price['option_count']) : null;
                    $line['category_id'] = isset($price['category_id']) ? (int)$price['category_id'] : null;
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
     * The ids of the resources an application is booked on, as stored right now.
     *
     * @return int[]
     */
    private function applicationResourceIds(int $applicationId): array
    {
        $stmt = $this->db->prepare(
            "SELECT resource_id FROM bb_application_resource WHERE application_id = :application_id"
        );
        $stmt->execute([':application_id' => $applicationId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * The services (bb_service ids) that at least one of the application's resources offers
     * as an add-on, the same link getArticlesByResources() offers them by.
     *
     * @return int[]
     */
    private function servicesOfApplicationResources(int $applicationId): array
    {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT rs.service_id
             FROM bb_resource_service rs
             JOIN bb_application_resource ar ON ar.resource_id = rs.resource_id
             WHERE ar.application_id = :application_id"
        );
        $stmt->execute([':application_id' => $applicationId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Whether an order line belongs to a resource the application is not booked on.
     *
     * - A resource article (category 1) is foreign when its resource is not one of the
     *   application's resources.
     * - A service (category 2) is foreign only when it claims a parent (it is presented
     *   as an add-on of a resource) and none of the application's resources offers it.
     *   A service without a parent is not tied to a resource and is left as it is.
     * - Any other category is never foreign here.
     *
     * @param array $mapping The line's bb_article_mapping row (article_cat_id, article_id)
     * @param int|null $parentId The parent mapping id the line claims
     * @param int[] $resourceIds The application's resources
     * @param int[] $serviceIds The services the application's resources offer
     */
    public static function isForeignLine(array $mapping, ?int $parentId, array $resourceIds, array $serviceIds): bool
    {
        $category = (int)($mapping['article_cat_id'] ?? 0);
        $articleId = (int)($mapping['article_id'] ?? 0);

        if ($category === 1) {
            return !in_array($articleId, $resourceIds, true);
        }
        if ($category === 2) {
            return ($parentId ?? 0) > 0 && !in_array($articleId, $serviceIds, true);
        }

        return false;
    }

    /**
     * Log a line that was dropped or pruned for belonging to a resource the application
     * is not booked on: the application, the mapping and the resource. error_log() reaches
     * the PHP/Apache log whatever log_errors is set to.
     *
     * @param array $mapping The line's bb_article_mapping row
     * @param int|null $parentId The parent mapping id the line claims
     * @param int|null $lineId The stored line, when it was stored
     */
    private function logForeignLine(int $applicationId, array $mapping, ?int $parentId, ?int $lineId, string $action): void
    {
        $resourceId = null;
        if ((int)$mapping['article_cat_id'] === 1) {
            $resourceId = (int)$mapping['article_id'];
        } elseif ($parentId) {
            // An add-on: the resource is the one its parent mapping stands for
            $parent = $this->getArticleMappingById($parentId);
            if ($parent && (int)$parent['article_cat_id'] === 1) {
                $resourceId = (int)$parent['article_id'];
            }
        }

        error_log(sprintf(
            '[order-line-guard] %s: application=%d mapping=%d resource=%s category=%d parent_mapping=%s line=%s',
            $action,
            $applicationId,
            (int)$mapping['id'],
            $resourceId ?? 'unknown',
            (int)$mapping['article_cat_id'],
            $parentId ?? 'none',
            $lineId ?? 'not stored'
        ));
    }

    /**
     * Remove the stored order lines of an application that belong to resources it is no
     * longer booked on. For a request that changes an application's resources without
     * sending its articles: the lines of the removed resources, and the add-ons only they
     * offered, would otherwise stay in the order and be billed.
     *
     * Only this application's own order (not a reservation's, not a cancelled one), and
     * only in the caller's transaction, after the resources were saved.
     *
     * @return int The number of lines removed
     */
    public function pruneLinesOfRemovedResources(int $applicationId): int
    {
        $stmt = $this->db->prepare(
            "SELECT l.id AS line_id, l.parent_mapping_id, m.*
             FROM bb_purchase_order po
             JOIN bb_purchase_order_line l ON l.order_id = po.id
             JOIN bb_article_mapping m ON m.id = l.article_mapping_id
             WHERE po.application_id = :application_id
             AND po.reservation_type IS NULL
             AND po.cancelled IS NULL
             ORDER BY l.id"
        );
        $stmt->execute([':application_id' => $applicationId]);
        $stored = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $resourceIds = $this->applicationResourceIds($applicationId);
        $serviceIds = $this->servicesOfApplicationResources($applicationId);

        $foreignLineIds = [];
        foreach ($stored as $row) {
            $parentId = !empty($row['parent_mapping_id']) ? (int)$row['parent_mapping_id'] : null;
            if (self::isForeignLine($row, $parentId, $resourceIds, $serviceIds)) {
                $foreignLineIds[] = (int)$row['line_id'];
                $this->logForeignLine($applicationId, $row, $parentId, (int)$row['line_id'], 'pruned, its resource left the application');
            }
        }

        if (empty($foreignLineIds)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($foreignLineIds), '?'));
        $delete = $this->db->prepare("DELETE FROM bb_purchase_order_line WHERE id IN ({$placeholders})");
        $delete->execute($foreignLineIds);

        return count($foreignLineIds);
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
        // The category the line was priced in (ref #1477), once the column exists
        $withCategory = PriceCategoryRepository::isInstalled();
        $categoryColumn = $withCategory ? ', category_id' : '';
        $categoryValue = $withCategory ? ', :category_id' : '';

        $sql = "INSERT INTO bb_purchase_order_line (
            order_id, article_mapping_id, quantity,
            tax_code, unit_price, parent_mapping_id, amount, tax, currency,
            article_price_id, price_label{$categoryColumn}
        ) VALUES (
            :order_id, :article_mapping_id, :quantity,
            :tax_code, :unit_price, :parent_mapping_id, :amount, :tax, :currency,
            :article_price_id, :price_label{$categoryValue}
        )";

        // Calculate the amount based on unit price and quantity
        $unitPrice = $line['ex_tax_price'] ?? 0;
        $quantity = $line['quantity'] ?? 0;
        $amount = $unitPrice * $quantity;

        // Calculate tax using the article's actual tax percent
        $taxPercent = $line['tax_percent'] ?? 0;
        $tax = $amount * ($taxPercent / 100);

        $params = [
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
        ];
        if ($withCategory) {
            $params[':category_id'] = $line['category_id'] ?? null;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
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
            // With the price category hidden the resource has no prices to choose between:
            // it is priced below like any other article, as on origin/master
            if (isset($articleData['resource_id']) && !$this->hidesPriceChoice()) {
                // The resource itself: the citizen chooses between today's prices.
                // The article's own price is the preselected option, and 0 while
                // a choice is required.
                $options = $this->getEligibleResourcePrices((int)$articleData['id']);
                $preselected = self::preselectedPrice($options);
                $price = $preselected ? ['price' => $preselected['price'], 'remark' => self::optionLabel($preselected)] : false;

                $taxPercent = (float)$articleData['tax_percent'];
                $articleData['price_options'] = array_map(function ($option) use ($taxPercent) {
                    $exTaxPrice = (float)$option['price'];
                    return [
                        'price_id' => (int)$option['id'],
                        'remark' => $option['remark'] ?? '',
                        'category_id' => isset($option['category_id']) ? (int)$option['category_id'] : null,
                        'category_name' => $option['category_name'] ?? null,
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