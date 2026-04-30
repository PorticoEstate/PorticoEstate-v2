<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add include_in_list column to bb_event';

    public function up(): void
    {
        $this->ensureColumn('bb_event', 'include_in_list', [
            'type' => 'int',
            'precision' => 4,
            'nullable' => false,
            'default' => 0,
        ]);
    }
};
