<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Recreate bb_application_association view with cost/active columns and drop booking_dow_default_end';

	public function up(): void
	{
		$this->sql("DROP VIEW IF EXISTS bb_application_association");

		$this->sql(
			"CREATE OR REPLACE VIEW bb_application_association AS "
			. "SELECT 'booking' AS type, application_id, id, from_, to_, cost, active FROM bb_booking WHERE application_id IS NOT NULL "
			. "UNION "
			. "SELECT 'allocation' AS type, application_id, id, from_, to_, cost, active FROM bb_allocation WHERE application_id IS NOT NULL "
			. "UNION "
			. "SELECT 'event' AS type, application_id, id, from_, to_, cost, active FROM bb_event WHERE application_id IS NOT NULL"
		);

		$this->dropColumn('bb_resource', 'booking_dow_default_end');
	}
};
