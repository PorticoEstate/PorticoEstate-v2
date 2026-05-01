<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Remove duplicate completed reservations and add unique constraint on reservation_type and reservation_id';

    public function up(): void
    {
        // Delete duplicate completed reservation resources first
        $this->sql("
            DELETE FROM bb_completed_reservation_resource
            WHERE completed_reservation_id IN (
                SELECT id FROM (
                    WITH DuplicateRows AS (
                        SELECT *,
                            ROW_NUMBER() OVER (PARTITION BY reservation_type, reservation_id ORDER BY cost DESC) AS rn
                        FROM bb_completed_reservation
                    )
                    SELECT id FROM DuplicateRows WHERE rn > 1
                ) AS dupes
            )
        ");

        // Delete the duplicate completed reservations
        $this->sql("
            DELETE FROM bb_completed_reservation
            WHERE id IN (
                SELECT id FROM (
                    WITH DuplicateRows AS (
                        SELECT *,
                            ROW_NUMBER() OVER (PARTITION BY reservation_type, reservation_id ORDER BY cost DESC) AS rn
                        FROM bb_completed_reservation
                    )
                    SELECT id FROM DuplicateRows WHERE rn > 1
                ) AS dupes
            )
        ");

        // Add unique constraint
        if (!$this->constraintExists('bb_completed_reservation', 'bb_completed_reservation_reservation_type_reservation_id_key')) {
            $this->sql("ALTER TABLE bb_completed_reservation ADD CONSTRAINT bb_completed_reservation_reservation_type_reservation_id_key UNIQUE (reservation_type, reservation_id)");
        }
    }
};
