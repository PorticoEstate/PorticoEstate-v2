<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add recurring_info jsonb column to bb_application';

    public function up(): void
    {
        $this->ensureColumn('bb_application', 'recurring_info', [
            'type' => 'jsonb',
            'nullable' => true,
        ]);
    }
};
