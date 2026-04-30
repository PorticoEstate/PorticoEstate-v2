<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add homepage column to bb_application and bb_event';

    public function up(): void
    {
        $this->ensureColumn('bb_application', 'homepage', [
            'type' => 'varchar',
            'precision' => 255,
            'nullable' => true,
        ]);

        $this->ensureColumn('bb_event', 'homepage', [
            'type' => 'varchar',
            'precision' => 255,
            'nullable' => true,
        ]);
    }
};
