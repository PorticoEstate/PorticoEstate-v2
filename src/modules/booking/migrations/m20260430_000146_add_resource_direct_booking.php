<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add direct_booking column to bb_resource';

    public function up(): void
    {
        $this->ensureColumn('bb_resource', 'direct_booking', [
            'type' => 'int',
            'precision' => 8,
            'nullable' => true,
        ]);
    }
};
