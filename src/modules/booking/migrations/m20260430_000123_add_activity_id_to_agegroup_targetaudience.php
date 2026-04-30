<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add activity_id to agegroup and targetaudience, duplicate records per activity, and add actual count columns';

    public function up(): void
    {
        // Add activity_id columns (nullable first for data migration)
        $this->ensureColumn('bb_agegroup', 'activity_id', [
            'type' => 'int',
            'precision' => 4,
            'nullable' => true,
        ]);
        $this->ensureColumn('bb_targetaudience', 'activity_id', [
            'type' => 'int',
            'precision' => 4,
            'nullable' => true,
        ]);

        // Alter description columns to text
        if ($this->columnExists('bb_agegroup', 'description')) {
            $colType = $this->getColumnType('bb_agegroup', 'description');
            if ($colType !== 'text') {
                $this->sql("ALTER TABLE bb_agegroup ALTER COLUMN description TYPE text");
            }
        }
        if ($this->columnExists('bb_targetaudience', 'description')) {
            $colType = $this->getColumnType('bb_targetaudience', 'description');
            if ($colType !== 'text') {
                $this->sql("ALTER TABLE bb_targetaudience ALTER COLUMN description TYPE text");
            }
        }

        // Assign first top-level activity to existing records that have no activity_id
        $db = \App\Database\Db::getInstance();
        $db->query("SELECT id FROM bb_activity WHERE parent_id IS NULL OR parent_id = 0 ORDER BY id LIMIT 1");
        if ($db->next_record()) {
            $firstActivityId = (int) $db->f('id');
            $this->sql("UPDATE bb_agegroup SET activity_id = {$firstActivityId} WHERE activity_id IS NULL");
            $this->sql("UPDATE bb_targetaudience SET activity_id = {$firstActivityId} WHERE activity_id IS NULL");
        }

        // Add actual count columns to booking and event agegroup tables
        $this->ensureColumn('bb_booking_agegroup', 'male_actual', [
            'type' => 'int',
            'precision' => 4,
            'nullable' => true,
        ]);
        $this->ensureColumn('bb_booking_agegroup', 'female_actual', [
            'type' => 'int',
            'precision' => 4,
            'nullable' => true,
        ]);
        $this->ensureColumn('bb_event_agegroup', 'male_actual', [
            'type' => 'int',
            'precision' => 4,
            'nullable' => true,
        ]);
        $this->ensureColumn('bb_event_agegroup', 'female_actual', [
            'type' => 'int',
            'precision' => 4,
            'nullable' => true,
        ]);

        // Make activity_id NOT NULL after data migration
        if ($this->columnExists('bb_agegroup', 'activity_id') && $this->isNullable('bb_agegroup', 'activity_id')) {
            $this->sql("ALTER TABLE bb_agegroup ALTER COLUMN activity_id SET NOT NULL");
        }
        if ($this->columnExists('bb_targetaudience', 'activity_id') && $this->isNullable('bb_targetaudience', 'activity_id')) {
            $this->sql("ALTER TABLE bb_targetaudience ALTER COLUMN activity_id SET NOT NULL");
        }
    }
};
