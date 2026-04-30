<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Drop description column from building/resource/organization, add article location, configure Vipps payment, recreate article view, make allocation skip_bas nullable';

    public function up(): void
    {
        // Drop description columns
        $tables = ['bb_building', 'bb_resource', 'bb_organization'];
        foreach ($tables as $table) {
            $this->dropColumn($table, 'description');
        }

        // Add .article location if not exists
        $location_obj = new \App\modules\phpgwapi\security\Locations();
        $location_id = $location_obj->get_id('booking', '.article');

        if (!$location_id) {
            $location_obj->add('.article', 'article', 'booking');

            $custom_config = \CreateObject('admin.soconfig', $location_obj->get_id('booking', 'run'));

            $receipt_section_common = $custom_config->add_section([
                'name' => 'payment',
                'descr' => 'payment method config',
            ]);

            $custom_config->add_attrib([
                'section_id' => $receipt_section_common['section_id'],
                'input_type' => 'listbox',
                'name' => 'method',
                'descr' => 'Payment method',
                'choice' => ['Vipps'],
            ]);

            $receipt_section_vipps = $custom_config->add_section([
                'name' => 'Vipps',
                'descr' => 'Vipps config',
            ]);

            foreach ([
                ['text', 'base_url', 'base_url'],
                ['text', 'client_id', 'client_id'],
                ['password', 'client_secret', 'client_secret'],
                ['password', 'subscription_key', 'subscription_key'],
                ['text', 'msn', 'Merchant Serial Number'],
            ] as [$inputType, $name, $descr]) {
                $custom_config->add_attrib([
                    'section_id' => $receipt_section_vipps['section_id'],
                    'input_type' => $inputType,
                    'name' => $name,
                    'descr' => $descr,
                    'value' => '',
                ]);
            }

            $custom_config->add_attrib([
                'section_id' => $receipt_section_vipps['section_id'],
                'input_type' => 'listbox',
                'name' => 'debug',
                'descr' => 'debug',
                'choice' => [1],
            ]);

            $custom_config->add_attrib([
                'section_id' => $receipt_section_vipps['section_id'],
                'input_type' => 'listbox',
                'name' => 'active',
                'descr' => 'Aktiv',
                'choice' => ['active'],
            ]);

            // Insert missing resource article mappings
            $this->sql("
                INSERT INTO bb_article_mapping (article_cat_id, article_id, article_code, unit, tax_code)
                SELECT 1, bb_resource.id, 'resource_' || bb_resource.id, 'hour',
                       (SELECT id FROM fm_ecomva WHERE id > 0 ORDER BY id LIMIT 1)
                FROM bb_resource
                LEFT JOIN bb_article_mapping ON (bb_resource.id = bb_article_mapping.article_id AND bb_article_mapping.article_cat_id = 1)
                WHERE bb_article_mapping.id IS NULL
            ");
        }

        // Recreate article view
        $this->sql("DROP VIEW IF EXISTS public.bb_article_view");
        $this->sql("
            CREATE OR REPLACE VIEW public.bb_article_view AS
            SELECT bb_resource.id,
                bb_building.name || '::' || bb_resource.name as name,
                'Ressurs' || bb_resource.name as description,
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

        // Make allocation skip_bas nullable
        if ($this->columnExists('bb_allocation', 'skip_bas') && !$this->isNullable('bb_allocation', 'skip_bas')) {
            $this->sql("ALTER TABLE bb_allocation ALTER COLUMN skip_bas DROP NOT NULL");
        }
    }
};
