<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Create bb_facility table';

    public function up(): void
    {
        $this->createTable('bb_facility', [
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
    }
};
