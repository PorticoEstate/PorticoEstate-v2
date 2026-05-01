<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add short_description jsonb column to bb_building and bb_resource';

    public function up(): void
    {
        $this->ensureColumn('bb_building', 'short_description', [
            'type' => 'jsonb',
            'nullable' => true,
        ]);

        $this->ensureColumn('bb_resource', 'short_description', [
            'type' => 'jsonb',
            'nullable' => true,
        ]);
    }
};
