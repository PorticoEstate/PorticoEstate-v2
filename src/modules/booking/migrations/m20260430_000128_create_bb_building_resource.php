<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Create bb_building_resource junction table, migrate data from bb_resource.building_id, and drop building_id column';

    public function up(): void
    {
        $this->createTable('bb_building_resource', [
            'fd' => [
                'building_id' => ['type' => 'int', 'precision' => 4, 'nullable' => false],
                'resource_id' => ['type' => 'int', 'precision' => 4, 'nullable' => false],
            ],
            'pk' => ['building_id', 'resource_id'],
            'fk' => [
                'bb_building' => ['building_id' => 'id'],
                'bb_resource' => ['resource_id' => 'id'],
            ],
            'ix' => [],
            'uc' => [],
        ]);

        // Migrate existing building_id data from bb_resource if the column still exists
        if ($this->columnExists('bb_resource', 'building_id')) {
            $this->sql("INSERT INTO bb_building_resource (building_id, resource_id) SELECT building_id, id FROM bb_resource WHERE building_id IS NOT NULL ON CONFLICT DO NOTHING");
            $this->dropColumn('bb_resource', 'building_id');
        }
    }
};
