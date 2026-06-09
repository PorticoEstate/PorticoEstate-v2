<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add co_address column to bb_organization';

    public function up(): void
    {
        $this->ensureColumn('bb_organization', 'co_address', [
            'type' => 'varchar',
            'precision' => 150,
            'nullable' => true,
        ]);
    }
};
