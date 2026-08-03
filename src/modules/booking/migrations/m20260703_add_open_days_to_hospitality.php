<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add open_days (business-days bitmask) to bb_hospitality: which weekdays the catering is open, used by the order-deadline calc to skip closed days. Bit (ISO weekday - 1) set = open (bit0=Mon..bit6=Sun). Default 127 = all days open.';

    public function up(): void
    {
        $this->ensureColumn('bb_hospitality', 'open_days', [
            'type' => 'int',
            'precision' => 2,
            'default' => 127,
            'nullable' => true,
        ]);
    }
};
