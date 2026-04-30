<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add building_id to bb_application and populate from bb_building name match';

    public function up(): void
    {
        $this->ensureColumn('bb_application', 'building_id', [
            'type' => 'int',
            'precision' => 4,
            'nullable' => false,
            'default' => 0,
        ]);

        // Update building_id from bb_building where names match
        if ($this->columnExists('bb_application', 'building_name')) {
            $this->sql("UPDATE bb_application SET building_id = bb_building.id FROM bb_building WHERE bb_application.building_name = bb_building.name AND bb_building.active = 1 AND bb_application.building_id = 0");
        }
    }
};
