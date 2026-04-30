<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Create bb_event and bb_event_resource tables';

	public function up(): void
	{
		$this->createTable('bb_event', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'active' => ['type' => 'int', 'precision' => '4', 'nullable' => false, 'default' => '1'],
				'activity_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'description' => ['type' => 'varchar', 'precision' => '50', 'nullable' => false, 'default' => ''],
				'from_' => ['type' => 'timestamp', 'nullable' => false],
				'to_' => ['type' => 'timestamp', 'nullable' => false],
				'cost' => ['type' => 'decimal', 'precision' => '10', 'scale' => '2', 'nullable' => false],
				'contact_name' => ['type' => 'varchar', 'precision' => '50', 'nullable' => false, 'default' => ''],
				'contact_email' => ['type' => 'varchar', 'precision' => '50', 'nullable' => false, 'default' => ''],
				'contact_phone' => ['type' => 'varchar', 'precision' => '50', 'nullable' => false, 'default' => ''],
			],
			'pk' => ['id'],
			'fk' => [
				'bb_activity' => ['activity_id' => 'id'],
			],
			'ix' => [],
			'uc' => [],
		]);

		$this->createTable('bb_event_resource', [
			'fd' => [
				'event_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'resource_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
			],
			'pk' => ['event_id', 'resource_id'],
			'fk' => [
				'bb_event' => ['event_id' => 'id'],
				'bb_resource' => ['resource_id' => 'id'],
			],
			'ix' => [],
			'uc' => [],
		]);
	}
};
