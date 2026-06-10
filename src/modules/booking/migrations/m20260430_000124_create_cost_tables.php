<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Create bb_allocation_cost, bb_event_cost, and bb_booking_cost tables';

    public function up(): void
    {
        $this->createTable('bb_allocation_cost', [
            'fd' => [
                'id' => ['type' => 'auto', 'nullable' => false],
                'allocation_id' => ['type' => 'int', 'precision' => 4, 'nullable' => false],
                'time' => ['type' => 'timestamp', 'nullable' => false, 'default' => 'current_timestamp'],
                'author' => ['type' => 'text', 'nullable' => false],
                'comment' => ['type' => 'text', 'nullable' => false],
                'cost' => ['type' => 'decimal', 'precision' => 10, 'scale' => 2, 'nullable' => true, 'default' => '0.0'],
            ],
            'pk' => ['id'],
            'fk' => ['bb_allocation' => ['allocation_id' => 'id']],
            'ix' => [],
            'uc' => [],
        ]);

        $this->createTable('bb_event_cost', [
            'fd' => [
                'id' => ['type' => 'auto', 'nullable' => false],
                'event_id' => ['type' => 'int', 'precision' => 4, 'nullable' => false],
                'time' => ['type' => 'timestamp', 'nullable' => false, 'default' => 'current_timestamp'],
                'author' => ['type' => 'text', 'nullable' => false],
                'comment' => ['type' => 'text', 'nullable' => false],
                'cost' => ['type' => 'decimal', 'precision' => 10, 'scale' => 2, 'nullable' => true, 'default' => '0.0'],
            ],
            'pk' => ['id'],
            'fk' => ['bb_event' => ['event_id' => 'id']],
            'ix' => [],
            'uc' => [],
        ]);

        $this->createTable('bb_booking_cost', [
            'fd' => [
                'id' => ['type' => 'auto', 'nullable' => false],
                'booking_id' => ['type' => 'int', 'precision' => 4, 'nullable' => false],
                'time' => ['type' => 'timestamp', 'nullable' => false, 'default' => 'current_timestamp'],
                'author' => ['type' => 'text', 'nullable' => false],
                'comment' => ['type' => 'text', 'nullable' => false],
                'cost' => ['type' => 'decimal', 'precision' => 10, 'scale' => 2, 'nullable' => true, 'default' => '0.0'],
            ],
            'pk' => ['id'],
            'fk' => ['bb_booking' => ['booking_id' => 'id']],
            'ix' => [],
            'uc' => [],
        ]);
    }
};
