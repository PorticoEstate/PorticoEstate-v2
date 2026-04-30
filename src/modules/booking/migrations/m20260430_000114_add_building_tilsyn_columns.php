<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add tilsyn_name, tilsyn_email, and tilsyn_phone columns to bb_building';

    public function up(): void
    {
        $this->ensureColumn('bb_building', 'tilsyn_name', [
            'type' => 'varchar',
            'precision' => 50,
            'nullable' => true,
        ]);

        $this->ensureColumn('bb_building', 'tilsyn_email', [
            'type' => 'varchar',
            'precision' => 50,
            'nullable' => true,
        ]);

        $this->ensureColumn('bb_building', 'tilsyn_phone', [
            'type' => 'varchar',
            'precision' => 50,
            'nullable' => true,
        ]);
    }
};
