<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add deactivate_calendar and deactivate_application columns to bb_building';

    public function up(): void
    {
        $this->ensureColumn('bb_building', 'deactivate_calendar', [
            'type' => 'int',
            'precision' => 4,
            'nullable' => false,
            'default' => 0,
        ]);

        $this->ensureColumn('bb_building', 'deactivate_application', [
            'type' => 'int',
            'precision' => 4,
            'nullable' => false,
            'default' => 0,
        ]);
    }
};
