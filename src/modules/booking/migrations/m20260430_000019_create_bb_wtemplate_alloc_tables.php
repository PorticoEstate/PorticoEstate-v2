<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Create bb_wtemplate_alloc and bb_wtemplate_alloc_resource tables';

	public function up(): void
	{
		$this->createTable('bb_wtemplate_alloc', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'season_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'organization_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'wday' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'cost' => ['type' => 'decimal', 'precision' => '10', 'scale' => '2', 'nullable' => false],
				'from_' => ['type' => 'time', 'nullable' => false],
				'to_' => ['type' => 'time', 'nullable' => false],
			],
			'pk' => ['id'],
			'fk' => [
				'bb_season' => ['season_id' => 'id'],
				'bb_organization' => ['organization_id' => 'id'],
			],
			'ix' => [],
			'uc' => [['season_id', 'wday', 'from_']],
		]);

		$this->createTable('bb_wtemplate_alloc_resource', [
			'fd' => [
				'allocation_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'resource_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
			],
			'pk' => ['allocation_id', 'resource_id'],
			'fk' => [
				'bb_wtemplate_alloc' => ['allocation_id' => 'id'],
				'bb_resource' => ['resource_id' => 'id'],
			],
			'ix' => [],
			'uc' => [],
		]);
	}
};
