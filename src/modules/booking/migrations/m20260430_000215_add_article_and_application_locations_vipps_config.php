<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add .article and .application locations with Vipps payment configuration';

    public function up(): void
    {
        $location_obj = new \App\modules\phpgwapi\security\Locations();
        $location_id = $location_obj->get_id('booking', '.article');

        if (!$location_id) {
            $location_obj->add('.article', 'article', 'booking');
            $location_obj->add('.application', 'Application', 'booking');

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
        }
    }
};
