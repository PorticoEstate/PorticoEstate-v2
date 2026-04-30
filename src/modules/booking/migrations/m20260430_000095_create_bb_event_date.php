<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Create bb_event_date table';

	public function up(): void
	{
		$this->createTable('bb_event_date', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'event_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'from_' => ['type' => 'timestamp', 'nullable' => false],
				'to_' => ['type' => 'timestamp', 'nullable' => false],
			],
			'pk' => ['id'],
			'fk' => [
				'bb_event' => ['event_id' => 'id'],
			],
			'ix' => [],
			'uc' => [['event_id', 'from_', 'to_']],
		]);
	}
};
