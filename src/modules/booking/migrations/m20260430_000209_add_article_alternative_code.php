<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add article_alternative_code column to bb_article_mapping';

    public function up(): void
    {
        $this->ensureColumn('bb_article_mapping', 'article_alternative_code', [
            'type' => 'varchar',
            'precision' => 100,
            'nullable' => true,
        ]);
    }
};
