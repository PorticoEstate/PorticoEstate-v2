<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Make skip_bas nullable on bb_booking and bb_allocation';

    public function up(): void
    {
        if ($this->columnExists('bb_booking', 'skip_bas') && !$this->isNullable('bb_booking', 'skip_bas')) {
            $this->sql("ALTER TABLE bb_booking ALTER COLUMN skip_bas DROP NOT NULL");
        }

        if ($this->columnExists('bb_allocation', 'skip_bas') && !$this->isNullable('bb_allocation', 'skip_bas')) {
            $this->sql("ALTER TABLE bb_allocation ALTER COLUMN skip_bas DROP NOT NULL");
        }
    }
};
