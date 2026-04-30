<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Create bb_allocation and bb_allocation_resource tables';

	public function up(): void
	{
		$this->createTable('bb_allocation', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'organization_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'from_' => ['type' => 'timestamp', 'nullable' => false],
				'to_' => ['type' => 'timestamp', 'nullable' => false],
				'season_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
			],
			'pk' => ['id'],
			'fk' => [
				'bb_organization' => ['organization_id' => 'id'],
				'bb_season' => ['season_id' => 'id'],
			],
			'ix' => [],
			'uc' => [],
		]);

		$this->createTable('bb_allocation_resource', [
			'fd' => [
				'allocation_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'resource_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
			],
			'pk' => ['allocation_id', 'resource_id'],
			'fk' => [
				'bb_allocation' => ['allocation_id' => 'id'],
				'bb_resource' => ['resource_id' => 'id'],
			],
			'ix' => [],
			'uc' => [],
		]);
	}
};
