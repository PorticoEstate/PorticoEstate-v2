<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Widen building_name columns to varchar(100) on application, allocation, booking, event, and system_message';

    public function up(): void
    {
        $tables = [
            'bb_application',
            'bb_allocation',
            'bb_booking',
            'bb_event',
            'bb_system_message',
        ];

        foreach ($tables as $table) {
            if ($this->columnExists($table, 'building_name')) {
                $this->sql("ALTER TABLE $table ALTER COLUMN building_name TYPE varchar(100)");
                $this->sql("ALTER TABLE $table ALTER COLUMN building_name SET DEFAULT 'changeme'");
                if ($this->isNullable($table, 'building_name')) {
                    $this->sql("ALTER TABLE $table ALTER COLUMN building_name SET NOT NULL");
                }
            }
        }
    }
};
