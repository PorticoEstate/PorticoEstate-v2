<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add calendar_text column to bb_building';

    public function up(): void
    {
        $this->ensureColumn('bb_building', 'calendar_text', [
            'type' => 'varchar',
            'precision' => 50,
            'nullable' => true,
        ]);
    }
};
