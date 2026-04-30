<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Add from_ datetime column to bb_application and populate from earliest application_date';

    public function up(): void
    {
        $this->ensureColumn('bb_application', 'from_', [
            'type' => 'datetime',
            'nullable' => true,
        ]);

        // Populate existing rows with the earliest date from bb_application_date
        if ($this->tableExists('bb_application_date')) {
            $this->sql("
                UPDATE bb_application a
                SET from_ = d.min_from
                FROM (
                    SELECT application_id, MIN(from_) AS min_from
                    FROM bb_application_date
                    GROUP BY application_id
                ) d
                WHERE a.id = d.application_id
                AND a.from_ IS NULL
            ");
        }
    }
};
