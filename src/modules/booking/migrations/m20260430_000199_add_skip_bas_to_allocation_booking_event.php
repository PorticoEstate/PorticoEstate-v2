<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add skip_bas (Building Automation System) column to bb_allocation, bb_booking, and bb_event';

    public function up(): void
    {
        $this->ensureColumn('bb_allocation', 'skip_bas', [
            'type' => 'int',
            'nullable' => false,
            'precision' => 2,
            'default' => 0,
        ]);

        $this->ensureColumn('bb_booking', 'skip_bas', [
            'type' => 'int',
            'nullable' => false,
            'precision' => 2,
            'default' => 0,
        ]);

        $this->ensureColumn('bb_event', 'skip_bas', [
            'type' => 'int',
            'nullable' => false,
            'precision' => 2,
            'default' => 0,
        ]);
    }
};
