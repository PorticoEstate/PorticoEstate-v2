<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add activate_prepayment column to bb_resource';

    public function up(): void
    {
        $this->ensureColumn('bb_resource', 'activate_prepayment', [
            'type' => 'int',
            'precision' => 2,
            'default' => 0,
            'nullable' => true,
        ]);
    }
};
