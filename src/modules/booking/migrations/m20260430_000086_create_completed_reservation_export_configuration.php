<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Create bb_completed_reservation_export_configuration table';

	public function up(): void
	{
		$this->createTable('bb_completed_reservation_export_configuration', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'type' => ['type' => 'text', 'nullable' => false],
				'export_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'export_file_id' => ['type' => 'int', 'precision' => '4', 'nullable' => true],
				'account_code_set_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
			],
			'pk' => ['id'],
			'fk' => [
				'bb_account_code_set' => ['account_code_set_id' => 'id'],
				'bb_completed_reservation_export' => ['export_id' => 'id'],
				'bb_completed_reservation_export_file' => ['export_file_id' => 'id'],
			],
			'ix' => [],
			'uc' => [],
		]);
	}
};
