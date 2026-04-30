<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add parent_mapping_id to purchase_order_line, drop building_id from article_mapping, insert missing article mappings, recreate article view';

    public function up(): void
    {
        $this->ensureColumn('bb_purchase_order_line', 'parent_mapping_id', [
            'type' => 'int',
            'precision' => 4,
            'nullable' => true,
        ]);

        $this->dropColumn('bb_article_mapping', 'building_id');

        // Insert missing resource article mappings
        $this->sql("
            INSERT INTO bb_article_mapping (article_cat_id, article_id, article_code, unit, tax_code)
            SELECT 1, bb_resource.id, 'resource_' || bb_resource.id, 'hour',
                   (SELECT id FROM fm_ecomva WHERE id > 0 ORDER BY id LIMIT 1)
            FROM bb_resource
            LEFT JOIN bb_article_mapping ON (bb_resource.id = bb_article_mapping.article_id AND bb_article_mapping.article_cat_id = 1)
            WHERE bb_article_mapping.id IS NULL
        ");

        $this->sql("DROP VIEW IF EXISTS public.bb_article_view");

        $this->sql("
            CREATE OR REPLACE VIEW public.bb_article_view AS
            SELECT bb_resource.id,
                bb_building.name || '::' || bb_resource.name as name,
                bb_resource.description,
                bb_resource.active,
                1 AS article_cat_id
            FROM bb_resource
            JOIN bb_building_resource ON bb_building_resource.resource_id = bb_resource.id
            JOIN bb_building ON bb_building_resource.building_id = bb_building.id
            UNION
            SELECT bb_service.id,
                bb_service.name,
                bb_service.description,
                bb_service.active,
                2 AS article_cat_id
            FROM bb_service
        ");
    }
};
