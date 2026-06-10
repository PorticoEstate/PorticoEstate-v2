<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add access_instruction text column to bb_resource_e_lock';

    public function up(): void
    {
        $this->ensureColumn('bb_resource_e_lock', 'access_instruction', [
            'type' => 'text',
            'nullable' => true,
        ]);
    }
};
