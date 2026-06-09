<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Create bb_season_wday table and add status, from_, to_ columns to bb_season';

	public function up(): void
	{
		$this->createTable('bb_season_wday', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'season_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'wday' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'from_' => ['type' => 'varchar', 'precision' => '5', 'nullable' => false],
				'to_' => ['type' => 'varchar', 'precision' => '5', 'nullable' => false],
			],
			'pk' => ['id'],
			'fk' => [
				'bb_season' => ['season_id' => 'id'],
			],
			'ix' => [],
			'uc' => [['season_id', 'wday']],
		]);

		$this->ensureColumn('bb_season', 'status', [
			'type' => 'varchar',
			'precision' => 10,
			'nullable' => false,
		]);

		$this->ensureColumn('bb_season', 'from_', [
			'type' => 'date',
			'nullable' => false,
		]);

		$this->ensureColumn('bb_season', 'to_', [
			'type' => 'date',
			'nullable' => false,
		]);
	}
};
