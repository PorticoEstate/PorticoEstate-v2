<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Create bb_resource table';

	public function up(): void
	{
		$this->createTable('bb_resource', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'building_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'name' => ['type' => 'varchar', 'precision' => '50', 'nullable' => false],
			],
			'pk' => ['id'],
			'fk' => [
				'bb_building' => ['building_id' => 'id'],
			],
			'ix' => [],
			'uc' => [],
		]);
	}
};
