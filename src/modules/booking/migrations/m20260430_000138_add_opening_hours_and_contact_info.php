<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add opening_hours to bb_building and bb_resource, and contact_info to bb_resource';

    public function up(): void
    {
        $this->ensureColumn('bb_building', 'opening_hours', [
            'type' => 'text',
            'nullable' => true,
        ]);

        $this->ensureColumn('bb_resource', 'opening_hours', [
            'type' => 'text',
            'nullable' => true,
        ]);

        $this->ensureColumn('bb_resource', 'contact_info', [
            'type' => 'text',
            'nullable' => true,
        ]);
    }
};
