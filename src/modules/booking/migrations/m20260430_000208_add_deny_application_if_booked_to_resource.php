<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add deny_application_if_booked column to bb_resource';

    public function up(): void
    {
        $this->ensureColumn('bb_resource', 'deny_application_if_booked', [
            'type' => 'int',
            'precision' => 2,
            'nullable' => false,
            'default' => 0,
        ]);
    }
};
