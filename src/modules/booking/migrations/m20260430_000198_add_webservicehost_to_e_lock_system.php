<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add webservicehost column to bb_e_lock_system';

    public function up(): void
    {
        $this->ensureColumn('bb_e_lock_system', 'webservicehost', [
            'type' => 'text',
            'nullable' => true,
        ]);
    }
};
