<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Create bb_rescategory and bb_rescategory_activity tables';

    public function up(): void
    {
        $this->createTable('bb_rescategory', [
            'fd' => [
                'id' => ['type' => 'auto', 'nullable' => false],
                'name' => ['type' => 'varchar', 'precision' => 100, 'nullable' => false],
                'active' => ['type' => 'int', 'nullable' => false, 'precision' => 4, 'default' => 1],
            ],
            'pk' => ['id'],
            'fk' => [],
            'ix' => [],
            'uc' => [],
        ]);

        $this->createTable('bb_rescategory_activity', [
            'fd' => [
                'rescategory_id' => ['type' => 'int', 'precision' => 4, 'nullable' => false],
                'activity_id' => ['type' => 'int', 'precision' => 4, 'nullable' => false],
            ],
            'pk' => ['rescategory_id', 'activity_id'],
            'fk' => [
                'bb_rescategory' => ['rescategory_id' => 'id'],
                'bb_activity' => ['activity_id' => 'id'],
            ],
            'ix' => [],
            'uc' => [],
        ]);
    }
};
