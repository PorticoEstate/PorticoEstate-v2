<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Create bb_participant_log table for tracking event participants';

	public function up(): void
	{
		$this->createTable('bb_participant_log', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'reservation_type' => ['type' => 'varchar', 'precision' => '70', 'nullable' => false],
				'reservation_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'from_' => ['type' => 'timestamp', 'nullable' => true],
				'to_' => ['type' => 'timestamp', 'nullable' => true],
				'phone' => ['type' => 'varchar', 'precision' => '50', 'nullable' => true],
				'email' => ['type' => 'varchar', 'precision' => '50', 'nullable' => true],
			],
			'pk' => ['id'],
			'fk' => [],
			'ix' => [],
			'uc' => [],
		]);
	}
};
