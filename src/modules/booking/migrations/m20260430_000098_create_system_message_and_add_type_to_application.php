<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Create bb_system_message table and add type column to bb_application';

	public function up(): void
	{
		$this->createTable('bb_system_message', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'title' => ['type' => 'text', 'nullable' => false],
				'created' => ['type' => 'timestamp', 'nullable' => false, 'default' => 'current_timestamp'],
				'display_in_dashboard' => [
					'type' => 'int',
					'nullable' => false,
					'precision' => '4',
					'default' => 1,
				],
				'building_id' => ['type' => 'int', 'precision' => '4'],
				'name' => ['type' => 'varchar', 'precision' => '50', 'nullable' => false],
				'phone' => ['type' => 'varchar', 'precision' => '50', 'nullable' => true],
				'email' => ['type' => 'varchar', 'precision' => '50', 'nullable' => true],
				'message' => ['type' => 'text', 'nullable' => false],
				'type' => ['type' => 'text', 'nullable' => false, 'default' => 'message'],
				'status' => ['type' => 'text', 'nullable' => false, 'default' => 'NEW'],
			],
			'pk' => ['id'],
			'fk' => [],
			'ix' => [],
			'uc' => [],
		]);

		$this->ensureColumn('bb_application', 'type', [
			'type' => 'varchar',
			'precision' => '11',
			'nullable' => false,
			'default' => 'application',
		]);

		// Set type for existing rows
		$this->sql("UPDATE bb_application SET type = 'application' WHERE type IS NULL OR type = ''");

		// Update status CONFIRMED -> ACCEPTED
		$this->sql("UPDATE bb_application SET status = 'ACCEPTED' WHERE status = 'CONFIRMED'");
	}
};
