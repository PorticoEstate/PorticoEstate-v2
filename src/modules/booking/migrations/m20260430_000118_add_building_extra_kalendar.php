<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add extra_kalendar column to bb_building';

    public function up(): void
    {
        $this->ensureColumn('bb_building', 'extra_kalendar', [
            'type' => 'int',
            'precision' => 4,
            'nullable' => false,
            'default' => 0,
        ]);
    }
};
