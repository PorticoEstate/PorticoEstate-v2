<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Create bb_user table for frontend users';

    public function up(): void
    {
        $this->createTable('bb_user', [
            'fd' => [
                'id' => ['type' => 'auto', 'nullable' => false],
                'active' => ['type' => 'int', 'nullable' => false, 'precision' => 4, 'default' => 1],
                'name' => ['type' => 'varchar', 'precision' => 150, 'nullable' => false],
                'homepage' => ['type' => 'text', 'nullable' => true],
                'phone' => ['type' => 'varchar', 'precision' => 50, 'nullable' => true],
                'email' => ['type' => 'varchar', 'precision' => 50, 'nullable' => true],
                'street' => ['type' => 'varchar', 'precision' => 255, 'nullable' => true],
                'zip_code' => ['type' => 'varchar', 'precision' => 255, 'nullable' => true],
                'city' => ['type' => 'varchar', 'precision' => 255, 'nullable' => true],
                'customer_number' => ['type' => 'text', 'nullable' => true],
                'customer_ssn' => ['type' => 'varchar', 'precision' => 12, 'nullable' => true],
            ],
            'pk' => ['id'],
            'fk' => [],
            'ix' => [],
            'uc' => [],
        ]);
    }
};
