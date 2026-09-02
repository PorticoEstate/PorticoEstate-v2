<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add allocation_group_id and price_locked to bb_allocation (ref #982). allocation_group_id groups the allocations minted by one recurring-wizard run so a price edit can cascade across the series; NULL means ungrouped, which is every pre-existing row. price_locked marks an allocation whose price an officer set by hand, so a bulk price edit can skip it. smallint rather than boolean because the ORM valid_field_types has no bool.';

    public function up(): void
    {
        $this->ensureColumn('bb_allocation', 'allocation_group_id', [
            'type' => 'int',
            'precision' => 4,
            'nullable' => true,
        ]);

        $this->ensureColumn('bb_allocation', 'price_locked', [
            'type' => 'int',
            'precision' => 2,
            'nullable' => false,
            'default' => 0,
        ]);
    }
};
