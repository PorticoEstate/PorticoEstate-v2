<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Widen name columns to varchar(150) across multiple tables and building_name to varchar(150)';

    public function up(): void
    {
        // Widen name columns to varchar(150)
        $nameColumns = [
            'bb_activity' => ['nullable' => false],
            'bb_building' => ['nullable' => false],
            'bb_contact_person' => ['nullable' => false],
            'bb_organization' => ['nullable' => false],
            'bb_resource' => ['nullable' => false],
            'bb_group' => ['nullable' => false],
            'bb_season' => ['nullable' => false],
            'bb_organization_contact' => ['nullable' => true],
            'bb_group_contact' => ['nullable' => true],
            'bb_system_message' => ['nullable' => false],
        ];

        foreach ($nameColumns as $table => $opts) {
            if ($this->columnExists($table, 'name')) {
                $this->sql("ALTER TABLE $table ALTER COLUMN name TYPE varchar(150)");
            }
        }

        // Widen building_name columns to varchar(150)
        $buildingNameTables = [
            'bb_application',
            'bb_allocation',
            'bb_booking',
            'bb_event',
            'bb_system_message',
        ];

        foreach ($buildingNameTables as $table) {
            if ($this->columnExists($table, 'building_name')) {
                $this->sql("ALTER TABLE $table ALTER COLUMN building_name TYPE varchar(150)");
            }
        }

        // Widen event-specific columns
        if ($this->columnExists('bb_event', 'contact_name')) {
            $this->sql("ALTER TABLE bb_event ALTER COLUMN contact_name TYPE varchar(150)");
        }
        if ($this->columnExists('bb_event', 'customer_organization_name')) {
            $this->sql("ALTER TABLE bb_event ALTER COLUMN customer_organization_name TYPE varchar(150)");
        }
    }
};
