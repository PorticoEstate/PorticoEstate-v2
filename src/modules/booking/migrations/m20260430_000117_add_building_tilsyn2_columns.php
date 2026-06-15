<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add second tilsyn contact columns to bb_building';

    public function up(): void
    {
        $this->ensureColumn('bb_building', 'tilsyn_name2', [
            'type' => 'varchar',
            'precision' => 50,
            'nullable' => true,
        ]);

        $this->ensureColumn('bb_building', 'tilsyn_email2', [
            'type' => 'varchar',
            'precision' => 50,
            'nullable' => true,
        ]);

        $this->ensureColumn('bb_building', 'tilsyn_phone2', [
            'type' => 'varchar',
            'precision' => 50,
            'nullable' => true,
        ]);
    }
};
