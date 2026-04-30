<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add booking_buffer_deadline column to bb_resource';

    public function up(): void
    {
        $this->ensureColumn('bb_resource', 'booking_buffer_deadline', [
            'type' => 'int',
            'precision' => 4,
            'nullable' => true,
            'default' => 0,
        ]);
    }
};
