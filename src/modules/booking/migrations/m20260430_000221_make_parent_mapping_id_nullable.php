<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Ensure parent_mapping_id on bb_purchase_order_line is nullable for consistency';

    public function up(): void
    {
        if ($this->columnExists('bb_purchase_order_line', 'parent_mapping_id') && !$this->isNullable('bb_purchase_order_line', 'parent_mapping_id')) {
            $this->sql("ALTER TABLE bb_purchase_order_line ALTER COLUMN parent_mapping_id DROP NOT NULL");
        }
    }
};
