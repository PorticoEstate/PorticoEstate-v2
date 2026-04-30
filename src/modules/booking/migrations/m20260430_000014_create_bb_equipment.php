<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Create bb_equipment table';

	public function up(): void
	{
		$this->createTable('bb_equipment', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'resource_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'name' => ['type' => 'varchar', 'precision' => '50', 'nullable' => false],
				'description' => ['type' => 'varchar', 'precision' => '10000', 'nullable' => false],
			],
			'pk' => ['id'],
			'fk' => [
				'bb_resource' => ['resource_id' => 'id'],
			],
			'ix' => [],
			'uc' => [],
		]);
	}
};
