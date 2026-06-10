<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add organizations_ids column to bb_resource';

    public function up(): void
    {
        $this->ensureColumn('bb_resource', 'organizations_ids', [
            'type' => 'varchar',
            'precision' => 50,
            'nullable' => true,
        ]);
    }
};
