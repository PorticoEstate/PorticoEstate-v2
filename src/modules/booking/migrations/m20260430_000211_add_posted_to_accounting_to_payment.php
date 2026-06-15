<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add posted_to_accounting column to bb_payment';

    public function up(): void
    {
        $this->ensureColumn('bb_payment', 'posted_to_accounting', [
            'type' => 'int',
            'precision' => 8,
            'nullable' => true,
        ]);
    }
};
