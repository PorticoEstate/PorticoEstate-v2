<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add e_lock_system_id and e_lock_resource_id columns to bb_resource';

    public function up(): void
    {
        $this->ensureColumn('bb_resource', 'e_lock_system_id', [
            'type' => 'int',
            'precision' => 4,
            'nullable' => true,
        ]);

        $this->ensureColumn('bb_resource', 'e_lock_resource_id', [
            'type' => 'int',
            'precision' => 4,
            'nullable' => true,
        ]);
    }
};
