<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Create bb_documentation table';

    public function up(): void
    {
        $this->createTable('bb_documentation', [
            'fd' => [
                'id' => ['type' => 'auto', 'nullable' => false],
                'name' => ['type' => 'varchar', 'precision' => 255, 'nullable' => false],
                'category' => ['type' => 'varchar', 'precision' => 150, 'nullable' => false],
                'description' => ['type' => 'text', 'nullable' => true],
            ],
            'pk' => ['id'],
            'fk' => [],
            'ix' => [],
            'uc' => [],
        ]);
    }
};
