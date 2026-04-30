<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Drop and recreate bb_completed_reservation_export_file table with new schema';

	public function up(): void
	{
		$this->dropTable('bb_completed_reservation_export_file');

		$this->createTable('bb_completed_reservation_export_file', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'filename' => ['type' => 'text'],
				'type' => ['type' => 'text', 'nullable' => false],
				'total_cost' => [
					'type' => 'decimal',
					'precision' => '10',
					'scale' => '2',
					'nullable' => false,
				],
				'total_items' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'created_on' => ['type' => 'timestamp', 'nullable' => false],
				'created_by' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
			],
			'pk' => ['id'],
			'fk' => [
				'phpgw_accounts' => ['created_by' => 'account_id'],
			],
			'ix' => [],
			'uc' => [],
		]);
	}
};
