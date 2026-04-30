<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add show_in_portal column to bb_organization and bb_group';

    public function up(): void
    {
        $this->ensureColumn('bb_organization', 'show_in_portal', [
            'type' => 'int',
            'precision' => 4,
            'nullable' => false,
            'default' => 0,
        ]);

        $this->ensureColumn('bb_group', 'show_in_portal', [
            'type' => 'int',
            'precision' => 4,
            'nullable' => false,
            'default' => 0,
        ]);
    }
};
