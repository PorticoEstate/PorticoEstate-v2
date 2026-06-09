<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add deactivate_in_frontend to article_mapping, active and default_ to article_price';

    public function up(): void
    {
        $this->ensureColumn('bb_article_mapping', 'deactivate_in_frontend', [
            'type' => 'int',
            'precision' => 2,
            'nullable' => true,
        ]);

        $this->ensureColumn('bb_article_price', 'active', [
            'type' => 'int',
            'precision' => 2,
            'default' => 1,
            'nullable' => true,
        ]);

        $this->ensureColumn('bb_article_price', 'default_', [
            'type' => 'int',
            'precision' => 2,
            'nullable' => true,
        ]);
    }
};
