<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Drop filename and account_code_set_id from export table and create bb_completed_reservation_export_file';

	public function up(): void
	{
		$this->dropColumn('bb_completed_reservation_export', 'filename');

		if ($this->columnExists('bb_completed_reservation_export', 'account_code_set_id')) {
			if ($this->constraintExists('bb_completed_reservation_export', 'bb_completed_reservation_export_account_code_set_id_fkey')) {
				$this->sql("ALTER TABLE bb_completed_reservation_export DROP CONSTRAINT bb_completed_reservation_export_account_code_set_id_fkey");
			}
			$this->dropColumn('bb_completed_reservation_export', 'account_code_set_id');
		}

		$this->createTable('bb_completed_reservation_export_file', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'filename' => ['type' => 'text'],
				'type' => ['type' => 'text', 'nullable' => false],
				'export_id' => ['type' => 'int', 'precision' => '4'],
				'account_code_set_id' => ['type' => 'int', 'precision' => '4'],
			],
			'pk' => ['id'],
			'fk' => [
				'bb_account_code_set' => ['account_code_set_id' => 'id'],
				'bb_completed_reservation_export' => ['export_id' => 'id'],
			],
			'ix' => [],
			'uc' => [],
		]);
	}
};
