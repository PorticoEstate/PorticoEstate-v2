<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add description column to bb_office';

    public function up(): void
    {
        $this->ensureColumn('bb_office', 'description', [
            'type' => 'text',
            'nullable' => true,
        ]);
    }
};
