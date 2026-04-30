<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Create bb_office and bb_office_user tables and register locations';

    public function up(): void
    {
        $this->createTable('bb_office', [
            'fd' => [
                'id' => ['type' => 'auto', 'precision' => 4, 'nullable' => false],
                'name' => ['type' => 'varchar', 'precision' => 200, 'nullable' => false],
                'user_id' => ['type' => 'int', 'precision' => 4, 'nullable' => true],
                'entry_date' => ['type' => 'int', 'precision' => 4, 'nullable' => true],
                'modified_date' => ['type' => 'int', 'precision' => 4, 'nullable' => true],
            ],
            'pk' => ['id'],
            'fk' => [],
            'ix' => [],
            'uc' => [],
        ]);

        $this->createTable('bb_office_user', [
            'fd' => [
                'id' => ['type' => 'auto', 'precision' => 4, 'nullable' => false],
                'office' => ['type' => 'int', 'precision' => 4, 'nullable' => true],
                'user_id' => ['type' => 'int', 'precision' => 4, 'nullable' => true],
                'entry_date' => ['type' => 'int', 'precision' => 4, 'nullable' => true],
                'modified_date' => ['type' => 'int', 'precision' => 4, 'nullable' => true],
            ],
            'pk' => ['id'],
            'fk' => ['bb_office' => ['office' => 'id']],
            'ix' => [],
            'uc' => [],
        ]);

        // Register locations for office module
        $location_obj = new \App\modules\phpgwapi\security\Locations();
        try {
            $location_obj->add('.office', 'office', 'booking');
        } catch (\Exception $e) {
            // Location may already exist
        }
        try {
            $location_obj->add('.office.user', 'office/user relation', 'booking', false, 'bb_office_user');
        } catch (\Exception $e) {
            // Location may already exist
        }
    }
};
