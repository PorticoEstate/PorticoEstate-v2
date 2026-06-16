<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Create bb_account_code_set table and add account_code_set_id to bb_completed_reservation_export';

	public function up(): void
	{
		$this->createTable('bb_account_code_set', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'name' => ['type' => 'text', 'nullable' => false],
				'object_number' => ['type' => 'varchar', 'precision' => '8', 'nullable' => false],
				'responsible_code' => ['type' => 'varchar', 'precision' => '6', 'nullable' => false],
				'article' => ['type' => 'varchar', 'precision' => '15', 'nullable' => false],
				'service' => ['type' => 'varchar', 'precision' => '8', 'nullable' => false],
				'project_number' => ['type' => 'varchar', 'precision' => '12', 'nullable' => false],
				'unit_number' => ['type' => 'varchar', 'precision' => '12', 'nullable' => false],
				'unit_prefix' => ['type' => 'varchar', 'precision' => '1', 'nullable' => false],
				'invoice_instruction' => ['type' => 'varchar', 'precision' => '120'],
				'active' => ['type' => 'int', 'nullable' => false, 'precision' => '4', 'default' => 1],
			],
			'pk' => ['id'],
			'fk' => [],
			'ix' => [],
			'uc' => [],
		]);

		$this->ensureColumn('bb_completed_reservation_export', 'account_code_set_id', ['type' => 'int', 'precision' => '4', 'nullable' => true]);

		if ($this->columnExists('bb_completed_reservation_export', 'account_code_set_id') && !$this->constraintExists('bb_completed_reservation_export', 'bb_completed_reservation_export_account_code_set_id_fkey')) {
			$this->sql("ALTER TABLE bb_completed_reservation_export ADD CONSTRAINT bb_completed_reservation_export_account_code_set_id_fkey FOREIGN KEY (account_code_set_id) REFERENCES bb_account_code_set(id)");
		}
	}
};
