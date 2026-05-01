<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Create bb_completed_reservation_export table';

	public function up(): void
	{
		$this->createTable('bb_completed_reservation_export', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'season_id' => ['type' => 'int', 'precision' => '4'],
				'building_id' => ['type' => 'int', 'precision' => '4'],
				'from_' => ['type' => 'timestamp', 'nullable' => true],
				'to_' => ['type' => 'timestamp', 'nullable' => true],
				'created_on' => ['type' => 'timestamp', 'nullable' => false],
				'filename' => ['type' => 'text', 'nullable' => false],
			],
			'pk' => ['id'],
			'fk' => [
				'bb_building' => ['building_id' => 'id'],
				'bb_season' => ['season_id' => 'id'],
			],
			'ix' => [],
			'uc' => [],
		]);
	}
};
