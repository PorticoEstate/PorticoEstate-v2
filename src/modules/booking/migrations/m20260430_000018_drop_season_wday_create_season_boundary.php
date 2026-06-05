<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Drop bb_season_wday and create bb_season_boundary table';

	public function up(): void
	{
		$this->dropTable('bb_season_wday');

		$this->createTable('bb_season_boundary', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'season_id' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'wday' => ['type' => 'int', 'precision' => '4', 'nullable' => false],
				'from_' => ['type' => 'time', 'nullable' => false],
				'to_' => ['type' => 'time', 'nullable' => false],
			],
			'pk' => ['id'],
			'fk' => [
				'bb_season' => ['season_id' => 'id'],
			],
			'ix' => [],
			'uc' => [],
		]);
	}
};
