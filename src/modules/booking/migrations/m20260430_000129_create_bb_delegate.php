<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Create bb_delegate table for organization delegates';

    public function up(): void
    {
        $this->createTable('bb_delegate', [
            'fd' => [
                'id' => ['type' => 'auto', 'nullable' => false],
                'active' => ['type' => 'int', 'nullable' => false, 'precision' => 2, 'default' => 1],
                'organization_id' => ['type' => 'int', 'precision' => 4, 'nullable' => false],
                'name' => ['type' => 'varchar', 'precision' => 150, 'nullable' => false],
                'email' => ['type' => 'varchar', 'precision' => 50, 'nullable' => true],
                'ssn' => ['type' => 'varchar', 'precision' => 115, 'nullable' => true],
                'phone' => ['type' => 'varchar', 'precision' => 50, 'nullable' => true],
            ],
            'pk' => ['id'],
            'fk' => ['bb_organization' => ['organization_id' => 'id']],
            'ix' => [],
            'uc' => [['organization_id', 'ssn']],
        ]);
    }
};
