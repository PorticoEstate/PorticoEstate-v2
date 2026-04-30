<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add articles jsonb column to bb_wtemplate_alloc';

    public function up(): void
    {
        $this->ensureColumn('bb_wtemplate_alloc', 'articles', [
            'type' => 'jsonb',
            'nullable' => true,
        ]);
    }
};
