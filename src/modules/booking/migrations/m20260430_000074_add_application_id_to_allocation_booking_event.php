<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add application_id column and foreign keys to bb_allocation, bb_booking, and bb_event';

	public function up(): void
	{
		$this->ensureColumn('bb_allocation', 'application_id', [
			'type' => 'int',
			'precision' => '4',
			'nullable' => true,
		]);
		$this->ensureColumn('bb_booking', 'application_id', [
			'type' => 'int',
			'precision' => '4',
			'nullable' => true,
		]);
		$this->ensureColumn('bb_event', 'application_id', [
			'type' => 'int',
			'precision' => '4',
			'nullable' => true,
		]);

		if (!$this->constraintExists('bb_allocation', 'bb_allocation_application_id_fkey')) {
			$this->sql("ALTER TABLE bb_allocation ADD CONSTRAINT bb_allocation_application_id_fkey FOREIGN KEY (application_id) REFERENCES bb_application(id)");
		}
		if (!$this->constraintExists('bb_booking', 'bb_booking_application_id_fkey')) {
			$this->sql("ALTER TABLE bb_booking ADD CONSTRAINT bb_booking_application_id_fkey FOREIGN KEY (application_id) REFERENCES bb_application(id)");
		}
		if (!$this->constraintExists('bb_event', 'bb_event_application_id_fkey')) {
			$this->sql("ALTER TABLE bb_event ADD CONSTRAINT bb_event_application_id_fkey FOREIGN KEY (application_id) REFERENCES bb_application(id)");
		}
	}
};
