<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Make bb_application.from_ nullable for partial applications';

    public function up(): void
    {
        $this->assertTableExists('bb_application');
        $this->assertColumnExists('bb_application', 'from_');

        if (!$this->isNullable('bb_application', 'from_')) {
            $this->sql("ALTER TABLE bb_application ALTER COLUMN from_ DROP NOT NULL");
        }
    }
};
