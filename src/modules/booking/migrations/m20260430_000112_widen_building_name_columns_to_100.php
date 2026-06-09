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
                // Only narrow/widen if current precision is less than 100 — skip if already >= 100
                $this->db->query(
                    "SELECT character_maximum_length FROM information_schema.columns "
                    . "WHERE table_schema = 'public' AND table_name = '$table' AND column_name = 'building_name'",
                    __LINE__, __FILE__
                );
                $this->db->next_record();
                $currentLen = (int) ($this->db->Record['character_maximum_length'] ?? 0);

                if ($currentLen < 100) {
                    // Truncate any values that would overflow
                    $this->sql("UPDATE $table SET building_name = LEFT(building_name, 100) WHERE LENGTH(building_name) > 100");
                    $this->sql("ALTER TABLE $table ALTER COLUMN building_name TYPE varchar(100)");
                }
                $this->sql("ALTER TABLE $table ALTER COLUMN building_name SET DEFAULT 'changeme'");
                if ($this->isNullable($table, 'building_name')) {
                    $this->sql("ALTER TABLE $table ALTER COLUMN building_name SET NOT NULL");
                }
            }
        }
    }
};
