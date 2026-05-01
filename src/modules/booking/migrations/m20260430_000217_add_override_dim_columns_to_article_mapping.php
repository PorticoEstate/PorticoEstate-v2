<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add override_dim_0 through override_dim_7 columns to bb_article_mapping';

    public function up(): void
    {
        for ($i = 0; $i <= 7; $i++) {
            $this->ensureColumn('bb_article_mapping', 'override_dim_' . $i, [
                'type' => 'varchar',
                'precision' => 25,
                'nullable' => true,
            ]);
        }
    }
};
