<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add log_filename column to bb_completed_reservation_export_file';

    public function up(): void
    {
        $this->ensureColumn('bb_completed_reservation_export_file', 'log_filename', [
            'type' => 'text',
            'nullable' => true,
        ]);
    }
};
