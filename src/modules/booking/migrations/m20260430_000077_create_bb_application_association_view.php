<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Create bb_application_association view linking bookings, allocations, and events to applications';

	public function up(): void
	{
		$this->sql(
			"CREATE OR REPLACE VIEW bb_application_association AS " .
			"SELECT 'booking' AS type, application_id, id, from_, to_ FROM bb_booking WHERE application_id IS NOT NULL " .
			"UNION " .
			"SELECT 'allocation' AS type, application_id, id, from_, to_ FROM bb_allocation WHERE application_id IS NOT NULL " .
			"UNION " .
			"SELECT 'event' AS type, application_id, id, from_, to_ FROM bb_event WHERE application_id IS NOT NULL"
		);
	}
};
