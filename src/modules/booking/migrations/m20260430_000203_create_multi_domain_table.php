<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Create bb_multi_domain table for multi-domain support';

    public function up(): void
    {
        $this->createTable('bb_multi_domain', [
            'fd' => [
                'id' => ['type' => 'auto', 'nullable' => false],
                'name' => ['type' => 'varchar', 'precision' => 200, 'nullable' => false],
                'webservicehost' => ['type' => 'text', 'nullable' => true],
                'user_id' => ['type' => 'int', 'precision' => 8, 'nullable' => true],
                'entry_date' => ['type' => 'int', 'precision' => 8, 'nullable' => true],
                'modified_date' => ['type' => 'int', 'precision' => 8, 'nullable' => true],
            ],
            'pk' => ['id'],
            'fk' => [],
            'ix' => [],
            'uc' => [],
        ]);
    }
};
