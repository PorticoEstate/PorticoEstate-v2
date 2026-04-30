<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add created_by column with foreign key to bb_completed_reservation_export';

	public function up(): void
	{
		$this->ensureColumn('bb_completed_reservation_export', 'created_by', ['type' => 'int', 'precision' => '4', 'nullable' => true]);

		if ($this->columnExists('bb_completed_reservation_export', 'created_by') && !$this->constraintExists('bb_completed_reservation_export', 'bb_completed_reservation_export_created_by_fkey')) {
			$this->sql("ALTER TABLE bb_completed_reservation_export ADD CONSTRAINT bb_completed_reservation_export_created_by_fkey FOREIGN KEY (created_by) REFERENCES phpgw_accounts(account_id)");
		}
	}
};
