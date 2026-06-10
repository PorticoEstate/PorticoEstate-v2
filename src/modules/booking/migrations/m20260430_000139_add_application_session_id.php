<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add session_id column to bb_application';

    public function up(): void
    {
        $this->ensureColumn('bb_application', 'session_id', [
            'type' => 'varchar',
            'precision' => 64,
            'nullable' => true,
        ]);
    }
};
