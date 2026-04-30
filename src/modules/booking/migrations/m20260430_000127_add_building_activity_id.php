<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add activity_id column to bb_building and populate from resource activity links';

    public function up(): void
    {
        $this->ensureColumn('bb_building', 'activity_id', [
            'type' => 'int',
            'precision' => 4,
            'nullable' => true,
        ]);

        // Populate activity_id based on resource activities
        if ($this->columnExists('bb_building', 'activity_id')) {
            $db = \App\Database\Db::getInstance();

            // Get top-level activities
            $db->query("SELECT id FROM bb_activity WHERE parent_id = 0 OR parent_id IS NULL ORDER BY id");
            $topLevels = [];
            while ($db->next_record()) {
                $topLevels[] = (int) $db->f('id');
            }

            if (!empty($topLevels)) {
                // Set first top-level activity as default for all buildings without activity_id
                $firstId = $topLevels[0];
                $this->sql("UPDATE bb_building SET activity_id = {$firstId} WHERE activity_id IS NULL");
            }

            // Make NOT NULL after populating
            if (!empty($topLevels) && $this->isNullable('bb_building', 'activity_id')) {
                $this->sql("ALTER TABLE bb_building ALTER COLUMN activity_id SET NOT NULL");
            }
        }
    }
};
