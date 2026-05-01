<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Reset completed flag on allocations that have no matching completed reservation record';

    public function up(): void
    {
        $this->sql("
            UPDATE bb_allocation SET completed = 0 WHERE id IN (
                SELECT bb_allocation.id
                FROM bb_allocation LEFT OUTER JOIN bb_completed_reservation
                ON bb_allocation.id = reservation_id AND reservation_type = 'allocation'
                WHERE reservation_id IS NULL
                AND completed = 1
            )
        ");
    }
};
