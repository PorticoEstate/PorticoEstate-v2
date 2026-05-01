<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Make reservation_type and reservation_id nullable on bb_purchase_order';

    public function up(): void
    {
        if ($this->columnExists('bb_purchase_order', 'reservation_type') && !$this->isNullable('bb_purchase_order', 'reservation_type')) {
            $this->sql("ALTER TABLE bb_purchase_order ALTER COLUMN reservation_type DROP NOT NULL");
        }
        if ($this->columnExists('bb_purchase_order', 'reservation_type')) {
            $this->sql("ALTER TABLE bb_purchase_order ALTER COLUMN reservation_type TYPE varchar(70)");
        }

        if ($this->columnExists('bb_purchase_order', 'reservation_id') && !$this->isNullable('bb_purchase_order', 'reservation_id')) {
            $this->sql("ALTER TABLE bb_purchase_order ALTER COLUMN reservation_id DROP NOT NULL");
        }
    }
};
