<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Create bb_resource_activity_entityform table';

	public function up(): void
	{
		$this->createTable('bb_resource_activity_entityform', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'name' => ['type' => 'varchar', 'precision' => 150, 'nullable' => false],
				'active' => ['type' => 'int', 'precision' => 2, 'nullable' => true, 'default' => 1],
				'building_id' => ['type' => 'int', 'precision' => 4, 'nullable' => false],
				'resources' => ['type' => 'jsonb', 'nullable' => false],
				'activities' => ['type' => 'jsonb', 'nullable' => true],
				'location_id' => ['type' => 'int', 'precision' => 4, 'nullable' => false],
			],
			'pk' => ['id'],
			'fk' => [
				'phpgw_locations' => ['location_id' => 'location_id'],
				'bb_building' => ['building_id' => 'id'],
			],
			'ix' => [],
			'uc' => [],
		]);
	}
};
