<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add building_name column to bb_booking and bb_allocation';

	public function up(): void
	{
		$this->ensureColumn('bb_booking', 'building_name', [
			'type' => 'varchar',
			'precision' => '50',
			'nullable' => false,
			'default' => 'changeme',
		]);

		// Populate building_name from related building via season
		$this->sql("UPDATE bb_booking SET building_name = b2.name FROM bb_building b2 WHERE EXISTS (SELECT 1 FROM bb_booking bo, bb_season s, bb_building b WHERE bo.season_id = s.id AND s.building_id = b.id AND b2.id = b.id AND bb_booking.id = bo.id) AND bb_booking.building_name = 'changeme'");

		$this->ensureColumn('bb_allocation', 'building_name', [
			'type' => 'varchar',
			'precision' => '50',
			'nullable' => false,
			'default' => 'changeme',
		]);

		// Populate building_name from related building via season
		$this->sql("UPDATE bb_allocation SET building_name = b2.name FROM bb_building b2 WHERE EXISTS (SELECT 1 FROM bb_allocation a, bb_season s, bb_building b WHERE s.id = a.season_id AND s.building_id = b.id AND b2.id = b.id AND bb_allocation.id = a.id) AND bb_allocation.building_name = 'changeme'");
	}
};
