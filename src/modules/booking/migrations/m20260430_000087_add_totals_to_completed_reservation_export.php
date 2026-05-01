<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add total_cost and total_items columns to bb_completed_reservation_export';

	public function up(): void
	{
		$table = 'bb_completed_reservation_export';

		$this->ensureColumn($table, 'total_cost', [
			'type' => 'decimal',
			'precision' => '10',
			'scale' => '2',
			'nullable' => true,
		]);
		$this->ensureColumn($table, 'total_items', [
			'type' => 'int',
			'precision' => '4',
			'nullable' => true,
		]);

		// Set defaults for existing rows
		$this->sql("UPDATE {$table} SET total_cost = 0.0 WHERE total_cost IS NULL");
		$this->sql("UPDATE {$table} SET total_items = 0 WHERE total_items IS NULL");

		// Set NOT NULL constraints
		if ($this->columnExists($table, 'total_items') && $this->isNullable($table, 'total_items')) {
			$this->sql("ALTER TABLE {$table} ALTER COLUMN total_items SET NOT NULL");
		}
		if ($this->columnExists($table, 'total_cost') && $this->isNullable($table, 'total_cost')) {
			$this->sql("ALTER TABLE {$table} ALTER COLUMN total_cost SET NOT NULL");
		}
	}
};
