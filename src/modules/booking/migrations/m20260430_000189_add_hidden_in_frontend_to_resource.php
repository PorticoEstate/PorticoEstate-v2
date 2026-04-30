<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add hidden_in_frontend column to bb_resource';

    public function up(): void
    {
        $this->ensureColumn('bb_resource', 'hidden_in_frontend', [
            'type' => 'int',
            'precision' => 2,
            'default' => 0,
            'nullable' => true,
        ]);
    }
};
