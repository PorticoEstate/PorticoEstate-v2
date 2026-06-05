<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Create bb_document_organization table';

    public function up(): void
    {
        $this->createTable('bb_document_organization', [
            'fd' => [
                'id' => ['type' => 'auto', 'nullable' => false],
                'name' => ['type' => 'varchar', 'precision' => 255, 'nullable' => false],
                'owner_id' => ['type' => 'int', 'precision' => 4, 'nullable' => false],
                'category' => ['type' => 'varchar', 'precision' => 150, 'nullable' => false],
                'description' => ['type' => 'text', 'nullable' => true],
            ],
            'pk' => ['id'],
            'fk' => ['bb_organization' => ['owner_id' => 'id']],
            'ix' => [],
            'uc' => [],
        ]);
    }
};
