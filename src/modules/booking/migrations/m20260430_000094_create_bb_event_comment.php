<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Create bb_event_comment table';

	public function up(): void
	{
		$this->createTable('bb_event_comment', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'event_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'time' => ['type' => 'timestamp', 'nullable' => false],
				'author' => ['type' => 'text', 'nullable' => false],
				'comment' => ['type' => 'text', 'nullable' => false],
				'type' => ['type' => 'text', 'nullable' => false, 'default' => 'comment'],
			],
			'pk' => ['id'],
			'fk' => [
				'bb_event' => ['event_id' => 'id'],
			],
			'ix' => [],
			'uc' => [],
		]);
	}
};
