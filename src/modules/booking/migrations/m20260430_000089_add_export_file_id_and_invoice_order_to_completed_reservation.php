<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add export_file_id and invoice_file_order_id columns to bb_completed_reservation';

	public function up(): void
	{
		$table = 'bb_completed_reservation';

		$this->ensureColumn($table, 'export_file_id', [
			'type' => 'int',
			'precision' => '4',
			'nullable' => true,
		]);
		$this->ensureColumn($table, 'invoice_file_order_id', [
			'type' => 'varchar',
			'precision' => '255',
			'nullable' => true,
		]);

		if (!$this->constraintExists($table, "{$table}_export_file_id_fkey") && $this->tableExists('bb_completed_reservation_export_file')) {
			// Clear orphaned references before adding FK constraint
			$this->sql("UPDATE {$table} SET export_file_id = NULL WHERE export_file_id IS NOT NULL AND export_file_id NOT IN (SELECT id FROM bb_completed_reservation_export_file)");
			$this->sql("ALTER TABLE {$table} ADD CONSTRAINT {$table}_export_file_id_fkey FOREIGN KEY (export_file_id) REFERENCES bb_completed_reservation_export_file(id)");
		}
	}
};
