<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add building_id column to bb_event and populate from resource links';

    public function up(): void
    {
        $this->ensureColumn('bb_event', 'building_id', [
            'type' => 'int',
            'precision' => 4,
            'nullable' => true,
        ]);

        if ($this->columnExists('bb_event', 'building_id')) {
            $this->sql("UPDATE bb_event SET building_id = br2.building_id FROM bb_resource br2 WHERE EXISTS (SELECT 1 FROM bb_event be, bb_event_resource ber, bb_resource br WHERE be.id = ber.event_id AND ber.resource_id = br.id AND br2.id = br.id AND bb_event.id = be.id) AND bb_event.building_id IS NULL");
        }
    }
};
