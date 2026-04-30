<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add building_id column with foreign key to bb_completed_reservation';

	public function up(): void
	{
		$this->ensureColumn('bb_completed_reservation', 'building_id', ['type' => 'int', 'precision' => '4', 'nullable' => true]);

		if ($this->columnExists('bb_completed_reservation', 'building_id') && !$this->constraintExists('bb_completed_reservation', 'bb_completed_reservation_building_id_fkey')) {
			$this->sql("ALTER TABLE bb_completed_reservation ADD CONSTRAINT bb_completed_reservation_building_id_fkey FOREIGN KEY (building_id) REFERENCES bb_building(id)");
		}
	}
};
