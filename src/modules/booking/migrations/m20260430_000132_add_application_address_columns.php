<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add responsible_street, responsible_zip_code, and responsible_city columns to bb_application';

    public function up(): void
    {
        $this->ensureColumn('bb_application', 'responsible_street', [
            'type' => 'varchar',
            'precision' => 255,
            'nullable' => true,
        ]);

        $this->ensureColumn('bb_application', 'responsible_zip_code', [
            'type' => 'varchar',
            'precision' => 16,
            'nullable' => true,
        ]);

        $this->ensureColumn('bb_application', 'responsible_city', [
            'type' => 'varchar',
            'precision' => 255,
            'nullable' => true,
        ]);
    }
};
