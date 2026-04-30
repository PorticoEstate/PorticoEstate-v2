<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Create bb_resource_facility junction table';

    public function up(): void
    {
        $this->createTable('bb_resource_facility', [
            'fd' => [
                'resource_id' => ['type' => 'int', 'precision' => 4, 'nullable' => false],
                'facility_id' => ['type' => 'int', 'precision' => 4, 'nullable' => false],
            ],
            'pk' => ['resource_id', 'facility_id'],
            'fk' => [
                'bb_resource' => ['resource_id' => 'id'],
                'bb_facility' => ['facility_id' => 'id'],
            ],
            'ix' => [],
            'uc' => [],
        ]);
    }
};
