<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add equipment column to bb_event';

    public function up(): void
    {
        $this->ensureColumn('bb_event', 'equipment', [
            'type' => 'text',
            'nullable' => true,
        ]);
    }
};
