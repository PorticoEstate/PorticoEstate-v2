<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add description_json column to bb_building, bb_resource, bb_organization and migrate existing descriptions';

    public function up(): void
    {
        $tables = ['bb_building', 'bb_resource', 'bb_organization'];

        foreach ($tables as $table) {
            $this->ensureColumn($table, 'description_json', [
                'type' => 'jsonb',
                'nullable' => true,
            ]);

            // Migrate existing description data to JSON format if description column still exists
            if ($this->columnExists($table, 'description')) {
                $this->sql("
                    UPDATE {$table}
                    SET description_json = jsonb_build_object('no', COALESCE(description, ''))
                    WHERE description_json IS NULL AND description IS NOT NULL
                ");
            }
        }
    }
};
