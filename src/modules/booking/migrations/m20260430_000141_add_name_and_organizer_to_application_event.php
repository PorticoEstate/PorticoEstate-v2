<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add name and organizer columns to bb_application and bb_event';

    public function up(): void
    {
        $this->ensureColumn('bb_application', 'name', [
            'type' => 'varchar',
            'precision' => 255,
            'nullable' => true,
        ]);

        $this->ensureColumn('bb_application', 'organizer', [
            'type' => 'varchar',
            'precision' => 255,
            'nullable' => true,
        ]);

        $this->ensureColumn('bb_event', 'name', [
            'type' => 'varchar',
            'precision' => 255,
            'nullable' => true,
        ]);

        $this->ensureColumn('bb_event', 'organizer', [
            'type' => 'varchar',
            'precision' => 255,
            'nullable' => true,
        ]);
    }
};
