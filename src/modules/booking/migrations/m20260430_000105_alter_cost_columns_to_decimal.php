<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Alter cost columns to decimal(10,2) on reservation, allocation, booking, and event tables';

    public function up(): void
    {
        $tables = [
            'bb_completed_reservation',
            'bb_wtemplate_alloc',
            'bb_allocation',
            'bb_booking',
            'bb_event',
        ];

        foreach ($tables as $table) {
            if ($this->columnExists($table, 'cost') && $this->getColumnType($table, 'cost') !== 'numeric') {
                $this->sql("ALTER TABLE $table ALTER COLUMN cost TYPE numeric(10,2)");
                $this->sql("ALTER TABLE $table ALTER COLUMN cost SET DEFAULT 0.0");
            }
        }
    }
};
