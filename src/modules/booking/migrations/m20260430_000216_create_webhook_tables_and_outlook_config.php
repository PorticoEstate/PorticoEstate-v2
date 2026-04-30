<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Create webhook subscription and delivery log tables, add Outlook webhook configuration';

    public function up(): void
    {
        $this->createTable('bb_webhook_subscriptions', [
            'fd' => [
                'id' => ['type' => 'auto', 'nullable' => false],
                'subscription_id' => ['type' => 'varchar', 'precision' => 255, 'nullable' => false],
                'entity_type' => ['type' => 'varchar', 'precision' => 50, 'nullable' => false],
                'resource_id' => ['type' => 'int', 'precision' => 4, 'nullable' => true],
                'webhook_url' => ['type' => 'text', 'nullable' => false],
                'change_types' => ['type' => 'varchar', 'precision' => 255, 'nullable' => false, 'default' => 'created,updated,deleted'],
                'client_state' => ['type' => 'varchar', 'precision' => 255, 'nullable' => true],
                'secret_key' => ['type' => 'varchar', 'precision' => 255, 'nullable' => true],
                'is_active' => ['type' => 'int', 'precision' => 2, 'nullable' => false, 'default' => 1],
                'expires_at' => ['type' => 'timestamp', 'nullable' => false],
                'created_by' => ['type' => 'int', 'precision' => 4, 'nullable' => false],
                'created_at' => ['type' => 'timestamp', 'nullable' => false, 'default' => 'current_timestamp'],
                'last_notification_at' => ['type' => 'timestamp', 'nullable' => true],
                'notification_count' => ['type' => 'int', 'precision' => 4, 'nullable' => false, 'default' => 0],
                'failure_count' => ['type' => 'int', 'precision' => 4, 'nullable' => false, 'default' => 0],
            ],
            'pk' => ['id'],
            'fk' => [
                'phpgw_accounts' => ['created_by' => 'account_id'],
            ],
            'ix' => [
                ['entity_type', 'resource_id'],
                ['is_active', 'expires_at'],
            ],
            'uc' => ['subscription_id'],
        ]);

        $this->createTable('bb_webhook_delivery_log', [
            'fd' => [
                'id' => ['type' => 'auto', 'nullable' => false],
                'subscription_id' => ['type' => 'varchar', 'precision' => 255, 'nullable' => false],
                'change_type' => ['type' => 'varchar', 'precision' => 50, 'nullable' => false],
                'entity_type' => ['type' => 'varchar', 'precision' => 50, 'nullable' => false],
                'entity_id' => ['type' => 'int', 'precision' => 4, 'nullable' => false],
                'resource_id' => ['type' => 'int', 'precision' => 4, 'nullable' => true],
                'http_status_code' => ['type' => 'int', 'precision' => 4, 'nullable' => true],
                'response_time_ms' => ['type' => 'int', 'precision' => 4, 'nullable' => true],
                'error_message' => ['type' => 'text', 'nullable' => true],
                'created_at' => ['type' => 'timestamp', 'nullable' => false, 'default' => 'current_timestamp'],
            ],
            'pk' => ['id'],
            'fk' => [
                'bb_webhook_subscriptions' => ['subscription_id' => 'subscription_id'],
            ],
            'ix' => [
                ['subscription_id', 'created_at'],
                ['entity_type', 'entity_id'],
            ],
            'uc' => [],
        ]);

        // Add Outlook webhook configuration
        $location_obj = new \App\modules\phpgwapi\security\Locations();
        $run_location_id = $location_obj->get_id('booking', 'run');

        if ($run_location_id) {
            $custom_config = \CreateObject('admin.soconfig', $run_location_id);

            $receipt_Outlook = $custom_config->add_section([
                'name' => 'Outlook',
                'descr' => 'Outlook webhook configuration',
            ]);

            if (!empty($receipt_Outlook['section_id'])) {
                $custom_config->add_attrib([
                    'section_id' => $receipt_Outlook['section_id'],
                    'input_type' => 'text',
                    'name' => 'baseurl',
                    'descr' => 'Base URL',
                    'value' => '',
                ]);

                $custom_config->add_attrib([
                    'section_id' => $receipt_Outlook['section_id'],
                    'input_type' => 'text',
                    'name' => 'tenant_id',
                    'descr' => 'Tenant ID',
                    'value' => '',
                ]);

                $custom_config->add_attrib([
                    'section_id' => $receipt_Outlook['section_id'],
                    'input_type' => 'password',
                    'name' => 'api_key',
                    'descr' => 'API Key',
                    'value' => '',
                ]);

                $custom_config->add_attrib([
                    'section_id' => $receipt_Outlook['section_id'],
                    'input_type' => 'checkbox',
                    'name' => 'webhook_enabled',
                    'descr' => 'Enable Webhooks',
                    'choice' => ['active'],
                    'value' => [],
                ]);
            }
        }
    }
};
