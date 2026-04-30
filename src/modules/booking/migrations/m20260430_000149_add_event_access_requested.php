<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add access_requested column to bb_event';

    public function up(): void
    {
        $this->ensureColumn('bb_event', 'access_requested', [
            'type' => 'int',
            'precision' => 4,
            'nullable' => false,
            'default' => 0,
        ]);
    }
};
