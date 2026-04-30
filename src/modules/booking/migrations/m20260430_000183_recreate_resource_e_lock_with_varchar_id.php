<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Recreate bb_resource_e_lock table with e_lock_resource_id as varchar(80) instead of int';

    public function up(): void
    {
        // The original migration drops and recreates the table to change the column type.
        // Since this is a migration system, we handle this by ensuring the table exists
        // with the correct schema. If it already has varchar type, this is a no-op via createTable.
        if ($this->tableExists('bb_resource_e_lock')) {
            $colType = $this->getColumnType('bb_resource_e_lock', 'e_lock_resource_id');
            if ($colType !== null && stripos($colType, 'varchar') === false && stripos($colType, 'character varying') === false) {
                // Column is not varchar, need to alter it
                $this->sql("ALTER TABLE bb_resource_e_lock ALTER COLUMN e_lock_resource_id TYPE varchar(80) USING e_lock_resource_id::varchar");
            }
            return;
        }

        $this->createTable('bb_resource_e_lock', [
            'fd' => [
                'resource_id' => ['type' => 'int', 'precision' => 4, 'nullable' => false],
                'e_lock_system_id' => ['type' => 'int', 'precision' => 4, 'nullable' => false],
                'e_lock_resource_id' => ['type' => 'varchar', 'precision' => 80, 'nullable' => false],
                'e_lock_name' => ['type' => 'varchar', 'precision' => 50, 'nullable' => true],
                'access_code_format' => ['type' => 'varchar', 'precision' => 20, 'nullable' => true],
                'active' => ['type' => 'int', 'nullable' => false, 'precision' => 2, 'default' => 1],
                'modified_on' => ['type' => 'timestamp', 'nullable' => false, 'default' => 'current_timestamp'],
                'modified_by' => ['type' => 'int', 'precision' => 4, 'nullable' => false],
            ],
            'pk' => ['resource_id', 'e_lock_system_id', 'e_lock_resource_id'],
            'fk' => [
                'bb_resource' => ['resource_id' => 'id'],
            ],
            'ix' => [],
            'uc' => [],
        ]);
    }
};
