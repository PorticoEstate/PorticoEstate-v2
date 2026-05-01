<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Create bb_article_group table and add group_id to bb_article_mapping';

    public function up(): void
    {
        $this->createTable('bb_article_group', [
            'fd' => [
                'id' => ['type' => 'int', 'precision' => 4, 'nullable' => false],
                'name' => ['type' => 'varchar', 'precision' => 100, 'nullable' => false],
                'remark' => ['type' => 'text', 'nullable' => true],
            ],
            'pk' => ['id'],
            'fk' => [],
            'ix' => [],
            'uc' => [],
        ]);

        $this->sql("INSERT INTO bb_article_group (id, name, remark) SELECT 1, 'Gruppe 1', 'Gruppering av artikler' WHERE NOT EXISTS (SELECT 1 FROM bb_article_group WHERE id = 1)");

        $this->ensureColumn('bb_article_mapping', 'group_id', [
            'type' => 'int',
            'precision' => 4,
            'nullable' => true,
            'default' => 1,
        ]);
    }
};
