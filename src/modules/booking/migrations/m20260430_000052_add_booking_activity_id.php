<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Drop name column and add activity_id to bb_booking';

	public function up(): void
	{
		$this->dropColumn('bb_booking', 'name');

		$this->ensureColumn('bb_booking', 'activity_id', ['type' => 'int', 'precision' => '4', 'nullable' => true]);

		if ($this->columnExists('bb_booking', 'activity_id') && !$this->constraintExists('bb_booking', 'bb_booking_activity_id_fkey')) {
			$this->sql("ALTER TABLE bb_booking ADD CONSTRAINT bb_booking_activity_id_fkey FOREIGN KEY (activity_id) REFERENCES bb_activity(id)");
		}
	}
};
