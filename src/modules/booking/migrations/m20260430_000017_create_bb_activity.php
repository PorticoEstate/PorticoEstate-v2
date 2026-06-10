<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Create bb_activity table';

	public function up(): void
	{
		$this->createTable('bb_activity', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'parent_id' => ['type' => 'int', 'precision' => '4', 'nullable' => true],
				'name' => ['type' => 'varchar', 'precision' => '50', 'nullable' => false],
				'description' => ['type' => 'varchar', 'precision' => '10000', 'nullable' => false],
			],
			'pk' => ['id'],
			'fk' => [
				'bb_activity' => ['parent_id' => 'id'],
			],
			'ix' => [],
			'uc' => [],
		]);
	}
};
