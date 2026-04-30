<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Create bb_block table for temporary resource reservations';

	public function up(): void
	{
		$this->createTable('bb_block', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'active' => ['type' => 'int', 'precision' => 2, 'nullable' => false, 'default' => 1],
				'from_' => ['type' => 'timestamp', 'nullable' => false],
				'to_' => ['type' => 'timestamp', 'nullable' => false],
				'entry_time' => ['type' => 'timestamp', 'nullable' => false, 'default' => 'current_timestamp'],
				'session_id' => ['type' => 'varchar', 'precision' => 64, 'nullable' => true],
				'resource_id' => ['type' => 'int', 'precision' => 4, 'nullable' => false],
			],
			'pk' => ['id'],
			'fk' => [],
			'ix' => [],
			'uc' => [],
		]);
	}
};
