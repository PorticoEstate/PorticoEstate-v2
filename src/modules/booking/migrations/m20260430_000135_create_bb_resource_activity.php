<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Create bb_resource_activity junction table';

    public function up(): void
    {
        $this->createTable('bb_resource_activity', [
            'fd' => [
                'resource_id' => ['type' => 'int', 'precision' => 4, 'nullable' => false],
                'activity_id' => ['type' => 'int', 'precision' => 4, 'nullable' => false],
            ],
            'pk' => ['resource_id', 'activity_id'],
            'fk' => [
                'bb_resource' => ['resource_id' => 'id'],
                'bb_activity' => ['activity_id' => 'id'],
            ],
            'ix' => [],
            'uc' => [],
        ]);
    }
};
