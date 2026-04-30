<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add additional_invoice_information column to bb_allocation and bb_event';

    public function up(): void
    {
        $this->ensureColumn('bb_allocation', 'additional_invoice_information', [
            'type' => 'text',
            'nullable' => true,
        ]);

        $this->ensureColumn('bb_event', 'additional_invoice_information', [
            'type' => 'text',
            'nullable' => true,
        ]);
    }
};
