<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add rescategory_id column with foreign key to bb_resource';

    public function up(): void
    {
        $this->ensureColumn('bb_resource', 'rescategory_id', [
            'type' => 'int',
            'precision' => 4,
            'nullable' => true,
        ]);

        if ($this->columnExists('bb_resource', 'rescategory_id') && !$this->constraintExists('bb_resource', 'bb_resource_rescategory_id_fkey')) {
            $this->sql("ALTER TABLE bb_resource ADD CONSTRAINT bb_resource_rescategory_id_fkey FOREIGN KEY (rescategory_id) REFERENCES bb_rescategory(id)");
        }
    }
};
