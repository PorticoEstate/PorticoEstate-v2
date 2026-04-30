<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Create bb_booking table';

	public function up(): void
	{
		$this->createTable('bb_booking', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'name' => ['type' => 'varchar', 'precision' => '50', 'nullable' => false],
				'category' => ['type' => 'varchar', 'precision' => '50', 'nullable' => false],
				'resources' => ['type' => 'varchar', 'precision' => '50', 'nullable' => false],
				'group_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'from_' => ['type' => 'varchar', 'precision' => '5', 'nullable' => false],
				'to_' => ['type' => 'varchar', 'precision' => '5', 'nullable' => false],
				'date' => ['type' => 'date', 'precision' => '50', 'nullable' => false],
				'season_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
			],
			'pk' => ['id'],
			'fk' => [
				'bb_group' => ['group_id' => 'id'],
				'bb_season' => ['season_id' => 'id'],
			],
			'ix' => [],
			'uc' => [],
		]);
	}
};
