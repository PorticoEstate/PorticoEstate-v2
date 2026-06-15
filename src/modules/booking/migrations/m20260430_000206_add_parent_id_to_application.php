<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add parent_id column to bb_application';

    public function up(): void
    {
        $this->ensureColumn('bb_application', 'parent_id', [
            'type' => 'int',
            'precision' => 4,
            'nullable' => true,
        ]);
    }
};
