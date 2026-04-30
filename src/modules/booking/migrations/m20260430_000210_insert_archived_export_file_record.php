<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Insert archived (id=-1) record into bb_completed_reservation_export_file';

    public function up(): void
    {
        $this->sql("
            INSERT INTO bb_completed_reservation_export_file (id, filename, total_cost, type, total_items, created_on, created_by)
            SELECT -1, 'Arkivert', 0, 'internal', 0, NOW(),
                   (SELECT min(account_id) FROM phpgw_accounts WHERE account_type = 'u')
            WHERE NOT EXISTS (SELECT 1 FROM bb_completed_reservation_export_file WHERE id = -1)
        ");
    }
};
